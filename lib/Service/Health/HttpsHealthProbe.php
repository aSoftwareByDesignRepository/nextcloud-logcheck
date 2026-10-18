<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\LogCheck\Service\Health;

use OCA\LogCheck\Exception\HttpTransportException;
use OCA\LogCheck\Exception\ValidationException;
use OCA\LogCheck\Service\SafeHttpClient;
use OCA\LogCheck\Service\SettingsService;
use OCA\LogCheck\AppInfo\Application;
use OCP\IConfig;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * HTTPS self-check against the instance's own address only (Argus R-H02 / NN-H09).
 * Never accepts a request-supplied URL. The checked base comes from, in order:
 * the admin-pinned address (settings → overwrite.cli.url when it is HTTPS →
 * the request-derived base). An optional pinned connect-IP dials a chosen leg
 * (e.g. the public ingress under split-horizon DNS) while the URL's host name
 * is kept for TLS/SNI/Host.
 */
final class HttpsHealthProbe implements HealthProbeInterface
{
	/** curl errnos (int — CURLE_* constants need ext-curl loaded) by failure kind. */
	private const CURL_DNS_ERRNOS = [5, 6]; // RESOLVE_PROXY, RESOLVE_HOST
	private const CURL_TLS_ERRNOS = [35, 51, 58, 59, 60, 64, 66, 77, 83, 90, 91, 92, 95];
	private const CURL_CONNECT_ERRNOS = [7, 52]; // COULDNT_CONNECT, GOT_NOTHING
	private const CURL_TIMEOUT_ERRNOS = [28];

	public function __construct(
		private readonly IURLGenerator $urlGenerator,
		private readonly SafeHttpClient $http,
		private readonly IFactory $l10nFactory,
		private readonly LoggerInterface $logger,
		private readonly IConfig $config,
		private readonly SettingsService $settingsService,
	) {
	}

	/** @return list<array{id: string, label: string, href: string|null}> */
	private function securityAction(): array
	{
		$l10n = $this->l10nFactory->get(Application::APP_ID);
		return AdminRecoveryAction::adminSection(
			$this->urlGenerator,
			$l10n,
			'admin-security',
			'security',
			$l10n->t('Open security settings'),
		);
	}

	public function id(): string
	{
		return 'https';
	}

	public function probe(): HealthCard
	{
		[$base, $forceIp] = $this->probeTarget();
		if ($base === null) {
			return new HealthCard(
				'https',
				'HTTPS',
				HealthCardState::UNKNOWN,
				$this->l10nFactory->get(Application::APP_ID)->t('Unknown'),
				$this->l10nFactory->get(Application::APP_ID)->t('Instance URL is not available.'),
			);
		}

		$parts = parse_url($base);
		if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
			return new HealthCard(
				'https',
				'HTTPS',
				HealthCardState::UNKNOWN,
				$this->l10nFactory->get(Application::APP_ID)->t('Unknown'),
				$this->l10nFactory->get(Application::APP_ID)->t('Instance URL could not be parsed.'),
			);
		}

		$scheme = strtolower((string)$parts['scheme']);
		$host = (string)$parts['host'];
		if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
			$host = substr($host, 1, -1);
		}
		$l10n = $this->l10nFactory->get(Application::APP_ID);
		$checked = $this->checkedDetail($l10n, $base, $forceIp);

		if ($scheme !== 'https') {
			return new HealthCard(
				'https',
				'HTTPS',
				HealthCardState::DEGRADED,
				$l10n->t('Not using HTTPS'),
				$l10n->t('This instance URL uses HTTP. Prefer HTTPS for production.') . $checked,
				$this->securityAction(),
			);
		}

		$statusUrl = $base . '/status.php';
		try {
			$result = $this->http->getInstanceStatus($statusUrl, $host, $forceIp);
			$code = (int)($result['status'] ?? 0);
			$body = (string)($result['body'] ?? '');
			if (self::isHealthyStatusResponse($code, $body)) {
				return new HealthCard(
					'https',
					'HTTPS',
					HealthCardState::OK,
					$l10n->t('Reachable'),
					$l10n->t('Instance HTTPS endpoint answered.') . $checked,
				);
			}
			return new HealthCard(
				'https',
				'HTTPS',
				HealthCardState::DEGRADED,
				$l10n->t('Check failed'),
				$l10n->t('Instance HTTPS endpoint returned HTTP %s.', [(string)$code]) . $checked,
				$this->securityAction(),
			);
		} catch (HttpTransportException $e) {
			$this->logger->info('HTTPS self-check transport failure', [
				'app' => Application::APP_ID,
				'curl_errno' => $e->curlErrno(),
				'curl_error' => $e->curlError(),
			]);
			return new HealthCard(
				'https',
				'HTTPS',
				HealthCardState::CRITICAL,
				$l10n->t('Unreachable'),
				$this->transportDetail($e->curlErrno(), $l10n) . $checked,
				$this->securityAction(),
			);
		} catch (ValidationException $e) {
			// SsrfGuard::pin rejected the self URL — LCK_HOST_UNRESOLVED means the
			// name yielded no address (DNS/mDNS/hosts miss); other codes mean the
			// resolved address itself was rejected (e.g. link-local/metadata).
			$this->logger->info('HTTPS self-check address validation failed', [
				'app' => Application::APP_ID,
				'error' => $e->getMessage(),
				'error_code' => $e->getErrorCode(),
			]);
			return new HealthCard(
				'https',
				'HTTPS',
				HealthCardState::CRITICAL,
				$l10n->t('Unreachable'),
				($e->getErrorCode() === 'LCK_HOST_UNRESOLVED'
					? $l10n->t('The instance hostname could not be resolved on this server.')
					: $l10n->t('Could not reach the instance over HTTPS.')) . $checked,
				$this->securityAction(),
			);
		} catch (\Throwable) {
			return new HealthCard(
				'https',
				'HTTPS',
				HealthCardState::CRITICAL,
				$l10n->t('Unreachable'),
				$l10n->t('Could not reach the instance over HTTPS.') . $checked,
				$this->securityAction(),
			);
		}
	}

	/**
	 * Which base URL the card verifies. Order (first parseable hit wins):
	 *
	 *  1. health_https_probe_url — explicit admin pin. For deployments where
	 *     neither overwrite.cli.url nor the request-derived base is the right
	 *     address (multi-vhost, internal name vs public ingress).
	 *  2. overwrite.cli.url — but only when it is HTTPS. An HTTP cli.url is
	 *     the TLS-terminated-proxy convention (cron/CLI dial the local plain
	 *     path); honoring it here would wrongly report "Not using HTTPS" for
	 *     instances that genuinely terminate TLS at a proxy.
	 *  3. Request-derived base (previous behavior).
	 *
	 * @return array{0: string|null, 1: string|null} base URL, pinned connect IP
	 */
	private function probeTarget(): array
	{
		$forceIp = null;
		$overrideUrl = '';
		try {
			$settings = $this->settingsService->getRawSettings();
			$overrideUrl = trim((string)($settings['health_https_probe_url'] ?? ''));
			$rawIp = trim((string)($settings['health_https_probe_ip'] ?? ''));
			if ($rawIp !== '') {
				if (filter_var($rawIp, FILTER_VALIDATE_IP) !== false) {
					$forceIp = $rawIp;
				} else {
					// Settings validation normally blocks this — a hand-edited
					// row must degrade to name resolution, never to a raw dial.
					$this->logger->warning('HTTPS self-check ignoring invalid pinned connect IP', [
						'app' => Application::APP_ID,
					]);
				}
			}
		} catch (\Throwable $e) {
			// Mid-migration/upgrade: unreadable settings must not blank the card.
			$this->logger->info('HTTPS self-check could not read probe target settings', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
		}

		foreach ([$overrideUrl, trim((string)$this->config->getSystemValue('overwrite.cli.url', ''))] as $i => $candidate) {
			if ($candidate === '') {
				continue;
			}
			$parts = parse_url($candidate);
			if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
				$this->logger->warning('HTTPS self-check ignoring unparseable candidate URL', [
					'app' => Application::APP_ID,
					'source' => $i === 0 ? 'settings' : 'overwrite.cli.url',
				]);
				continue;
			}
			if ($i === 1 && strtolower((string)$parts['scheme']) !== 'https') {
				continue;
			}
			return [rtrim($candidate, '/'), $forceIp];
		}

		$base = rtrim($this->urlGenerator->getBaseUrl(), '/');
		if ($base === '') {
			$base = rtrim($this->urlGenerator->getAbsoluteURL('/'), '/');
		}
		return [$base === '' ? null : $base, $forceIp];
	}

	/**
	 * Which address was actually verified — shown on every card so an admin
	 * pinning an override sees the knob take effect, and a failing card names
	 * the exact target instead of leaving "Unreachable" ambiguous.
	 */
	private function checkedDetail(IL10N $l10n, string $base, ?string $forceIp): string
	{
		if ($forceIp === null) {
			return ' ' . $l10n->t('Checked %s.', [$base]);
		}
		return ' ' . $l10n->t('Checked %1$s at %2$s.', [$base, $forceIp]);
	}

	/**
	 * Honest per-kind detail for admins — a bare "Unreachable" sends people
	 * guessing (vhost/SNI vs DNS vs CA trust). Raw curl text stays in the log.
	 */
	private function transportDetail(int $errno, IL10N $l10n): string
	{
		if (in_array($errno, self::CURL_DNS_ERRNOS, true)) {
			return $l10n->t('The instance hostname could not be resolved on this server.');
		}
		if (in_array($errno, self::CURL_TLS_ERRNOS, true)) {
			return $l10n->t('The HTTPS certificate could not be verified. If this server uses a private certificate authority, trust it under Administration settings → Security.');
		}
		if (in_array($errno, self::CURL_CONNECT_ERRNOS, true)) {
			return $l10n->t('The instance did not answer over HTTPS.');
		}
		if (in_array($errno, self::CURL_TIMEOUT_ERRNOS, true)) {
			return $l10n->t('The instance did not answer in time over HTTPS.');
		}
		return $l10n->t('Could not reach the instance over HTTPS.');
	}

	/**
	 * Require 2xx + JSON with "installed" key — never invent green from empty/HTML bodies.
	 */
	public static function isHealthyStatusResponse(int $code, string $body): bool
	{
		if ($code < 200 || $code >= 300) {
			return false;
		}
		$data = json_decode($body, true);
		return is_array($data) && array_key_exists('installed', $data);
	}
}
