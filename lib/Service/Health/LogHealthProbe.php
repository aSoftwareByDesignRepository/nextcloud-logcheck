<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\LogCheck\Service\Health;

use OCA\LogCheck\Service\ChannelStateStore;
use OCA\LogCheck\Service\StatusService;
use OCA\LogCheck\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;

/**
 * Maps LogCheck StatusService → Health card. Never green when can't watch / topology bad /
 * never ran a check yet.
 */
final class LogHealthProbe implements HealthProbeInterface
{
	public function __construct(
		private readonly StatusService $statusService,
		private readonly IFactory $l10nFactory,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	public function id(): string
	{
		return 'log';
	}

	public function probe(): HealthCard
	{
		$status = $this->statusService->getStatus();
		$alertsHref = $this->urlGenerator->linkToRoute('logcheck.page.settings', ['section' => 'alerts']);
		$adminJobsHref = $this->urlGenerator->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => 'server']);
		$logsHref = $this->urlGenerator->linkToRoute('logcheck.page.logs', []);
		return self::mapStatus($status, $this->l10nFactory->get(Application::APP_ID), $alertsHref, $adminJobsHref, $logsHref);
	}

	/**
	 * Pure mapper — unit-testable (NN-H01).
	 *
	 * @param array<string, mixed> $status
	 */
	public static function mapStatus(array $status, ?IL10N $l10n = null, ?string $alertsHref = null, ?string $adminJobsHref = null, ?string $logsHref = null): HealthCard
	{
		$t = static function (string $msg, array $args = []) use ($l10n): string {
			if ($l10n !== null) {
				return $args === [] ? $l10n->t($msg) : $l10n->t($msg, $args);
			}
			if ($args === []) {
				return $msg;
			}
			return vsprintf(str_replace('%s', '%s', $msg), $args);
		};

		$supported = !empty($status['backend_supported']);
		$topologyOk = ($status['topology_ok'] ?? true) !== false;
		$watch = !empty($status['watch_enabled']);
		$stale = !empty($status['stale']);
		$lastCheck = isset($status['last_check_at']) ? (int)$status['last_check_at'] : 0;
		$rawError = isset($status['error']) && is_string($status['error']) ? $status['error'] : '';
		$error = $rawError !== '' ? ChannelStateStore::safeError($rawError) : '';
		$turnOnHref = ($alertsHref !== null && $alertsHref !== '') ? $alertsHref : null;
		$setupAlertsAction = ($turnOnHref !== null) ? [[
			'id' => 'setup-alerts',
			'label' => $t('Set up alerts'),
			'href' => $turnOnHref,
		]] : [];
		$adminJobsAction = ($adminJobsHref !== null && $adminJobsHref !== '') ? [[
			'id' => 'admin-jobs',
			'label' => $t('Open admin settings'),
			'href' => $adminJobsHref,
		]] : [];
		$viewLogsAction = ($logsHref !== null && $logsHref !== '') ? [[
			'id' => 'view-logs',
			'label' => $t('View logs'),
			'href' => $logsHref,
		]] : [];

		if (!$supported || !$topologyOk) {
			return new HealthCard(
				'log',
				'Log alerts',
				HealthCardState::CRITICAL,
				$t('Can\'t watch'),
				$error !== '' ? $error : $t('Log watching is not available on this server.'),
			);
		}

		if (!$watch) {
			return new HealthCard(
				'log',
				'Log alerts',
				HealthCardState::DEGRADED,
				$t('Off'),
				$t('Set up alerts to get notified about new errors.'),
				$setupAlertsAction,
			);
		}

		if ($lastCheck <= 0) {
			return new HealthCard(
				'log',
				'Log alerts',
				HealthCardState::DEGRADED,
				$t('Not checked yet'),
				$t('Watching is on, but no background check has finished yet.'),
				$adminJobsAction,
			);
		}

		if ($stale) {
			return new HealthCard(
				'log',
				'Log alerts',
				HealthCardState::DEGRADED,
				$t('Needs a check'),
				$error !== '' ? $error : $t('Background checks look stuck.'),
				$adminJobsAction,
			);
		}

		$statusState = isset($status['state']) && is_string($status['state']) ? $status['state'] : '';
		if ($error !== '' || $statusState === 'degraded') {
			$attentionAction = $setupAlertsAction;
			if ($error !== '' && (stripos($error, 'log') !== false || stripos($error, 'read') !== false || stripos($error, 'permission') !== false)) {
				$attentionAction = $viewLogsAction !== [] ? $viewLogsAction : $setupAlertsAction;
			}
			return new HealthCard(
				'log',
				'Log alerts',
				HealthCardState::DEGRADED,
				$t('Needs attention'),
				$error !== '' ? $error : $t('The last background check did not finish cleanly.'),
				$attentionAction,
			);
		}

		return new HealthCard(
			'log',
			'Log alerts',
			HealthCardState::OK,
			$t('Watching'),
			$t('Last check: %s', [date('Y-m-d H:i', $lastCheck)]),
		);
	}
}
