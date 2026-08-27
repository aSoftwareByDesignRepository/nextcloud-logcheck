<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\LogCheck\Service\Health;

use OCA\LogCheck\Service\SafeHttpClient;
use OCA\LogCheck\AppInfo\Application;
use OCP\L10N\IFactory;
use OCP\IURLGenerator;

/**
 * HTTPS self-check against instance base URL only (Argus R-H02 / NN-H09).
 * Never accepts a user-supplied URL.
 */
final class HttpsHealthProbe implements HealthProbeInterface
{
	public function __construct(
		private readonly IURLGenerator $urlGenerator,
		private readonly SafeHttpClient $http,
		private readonly IFactory $l10nFactory,
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
		$base = rtrim($this->urlGenerator->getBaseUrl(), '/');
		if ($base === '') {
			$base = rtrim($this->urlGenerator->getAbsoluteURL('/'), '/');
		}
		if ($base === '') {
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

		if ($scheme !== 'https') {
			return new HealthCard(
				'https',
				'HTTPS',
				HealthCardState::DEGRADED,
				$this->l10nFactory->get(Application::APP_ID)->t('Not using HTTPS'),
				$this->l10nFactory->get(Application::APP_ID)->t('This instance URL uses HTTP. Prefer HTTPS for production.'),
				$this->securityAction(),
			);
		}

		$statusUrl = $base . '/status.php';
		try {
			$result = $this->http->getInstanceStatus($statusUrl, $host);
			$code = (int)($result['status'] ?? 0);
			$body = (string)($result['body'] ?? '');
			if (self::isHealthyStatusResponse($code, $body)) {
				return new HealthCard(
					'https',
					'HTTPS',
					HealthCardState::OK,
					$this->l10nFactory->get(Application::APP_ID)->t('Reachable'),
					$this->l10nFactory->get(Application::APP_ID)->t('Instance HTTPS endpoint answered.'),
				);
			}
			return new HealthCard(
				'https',
				'HTTPS',
				HealthCardState::DEGRADED,
				$this->l10nFactory->get(Application::APP_ID)->t('Check failed'),
				$this->l10nFactory->get(Application::APP_ID)->t('Instance HTTPS endpoint returned HTTP %s.', [(string)$code]),
				$this->securityAction(),
			);
		} catch (\Throwable) {
			return new HealthCard(
				'https',
				'HTTPS',
				HealthCardState::CRITICAL,
				$this->l10nFactory->get(Application::APP_ID)->t('Unreachable'),
				$this->l10nFactory->get(Application::APP_ID)->t('Could not reach the instance over HTTPS.'),
				$this->securityAction(),
			);
		}
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
