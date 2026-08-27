<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\LogCheck\Service\Health;

use OCA\LogCheck\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;

/**
 * Background jobs / cron freshness (Argus R-H04).
 */
final class JobsHealthProbe implements HealthProbeInterface
{
	/** Seconds after which cron is considered stuck when mode=cron. */
	public const STALE_SECONDS = 600;

	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IConfig $config,
		private readonly IFactory $l10nFactory,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	public function id(): string
	{
		return 'jobs';
	}

	public function probe(): HealthCard
	{
		$l10n = $this->l10nFactory->get(Application::APP_ID);
		$adminHref = $this->urlGenerator->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => 'server']);
		$fixAction = [[
			'id' => 'admin-jobs',
			'label' => $l10n->t('Open admin settings'),
			'href' => $adminHref,
		]];

		$mode = $this->appConfig->getValueString('core', 'backgroundjobs_mode', 'ajax');
		$last = $this->appConfig->getValueInt('core', 'lastcron', 0);
		$errorsRaw = $this->config->getAppValue('core', 'cronErrors', '');
		$hasErrors = $errorsRaw !== '' && $errorsRaw !== '[]' && $errorsRaw !== 'null';

		if ($hasErrors) {
			return new HealthCard(
				'jobs',
				'Background jobs',
				HealthCardState::CRITICAL,
				$l10n->t('Cron errors'),
				$l10n->t('Nextcloud reported errors while running background jobs.'),
				$fixAction,
			);
		}

		if ($mode === 'ajax' || $mode === 'webcron') {
			return new HealthCard(
				'jobs',
				'Background jobs',
				HealthCardState::DEGRADED,
				$mode === 'ajax' ? $l10n->t('Using AJAX') : $l10n->t('Using webcron'),
				$l10n->t('System cron is more reliable for alerts and health checks.'),
				$fixAction,
			);
		}

		if ($last <= 0) {
			return new HealthCard(
				'jobs',
				'Background jobs',
				HealthCardState::DEGRADED,
				$l10n->t('Never ran'),
				$l10n->t('No successful cron run recorded yet.'),
				$fixAction,
			);
		}

		$age = time() - $last;
		if ($age > self::STALE_SECONDS) {
			return new HealthCard(
				'jobs',
				'Background jobs',
				HealthCardState::DEGRADED,
				$l10n->t('Looks stuck'),
				$l10n->t('Last cron run was more than 10 minutes ago.'),
				$fixAction,
			);
		}

		return new HealthCard(
			'jobs',
			'Background jobs',
			HealthCardState::OK,
			$l10n->t('Running'),
			$l10n->t('Last cron: %s', [date('Y-m-d H:i', $last)]),
		);
	}
}
