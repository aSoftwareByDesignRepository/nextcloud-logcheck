<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\LogCheck\Service\Health;

use OCA\LogCheck\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;

/**
 * Read-only PHP/runtime card (informational).
 */
final class PhpHealthProbe implements HealthProbeInterface
{
	public function __construct(
		private readonly IFactory $l10nFactory,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	public function id(): string
	{
		return 'php';
	}

	public function probe(): HealthCard
	{
		$version = PHP_VERSION;
		$memory = (string)ini_get('memory_limit');
		$detail = $this->l10nFactory->get(Application::APP_ID)->t('memory_limit=%s', [$memory !== '' ? $memory : 'unknown']);

		if (version_compare($version, '8.2.0', '<')) {
			$l10n = $this->l10nFactory->get(Application::APP_ID);
			return new HealthCard(
				'php',
				'PHP',
				HealthCardState::DEGRADED,
				$l10n->t('PHP %s', [$version]),
				$l10n->t('Nextcloud needs PHP 8.2 or newer. %s', [$detail]),
				AdminRecoveryAction::adminSection($this->urlGenerator, $l10n, 'admin-overview', 'overview', $l10n->t('Open admin overview')),
			);
		}

		return new HealthCard(
			'php',
			'PHP',
			HealthCardState::OK,
			$this->l10nFactory->get(Application::APP_ID)->t('PHP %s', [$version]),
			$detail,
		);
	}
}
