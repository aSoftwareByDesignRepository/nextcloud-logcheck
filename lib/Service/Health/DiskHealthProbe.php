<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\LogCheck\Service\Health;

use OCA\LogCheck\AppInfo\Application;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;

/**
 * Disk free on datadirectory only (Argus R-H01 / NN-H08).
 * Accepts zero path arguments — IConfig datadirectory exclusively.
 */
final class DiskHealthProbe implements HealthProbeInterface
{
	public const FREE_OK = 0.15;
	public const FREE_DEGRADED = 0.05;

	public function __construct(
		private readonly IConfig $config,
		private readonly IFactory $l10nFactory,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	/** @return list<array{id: string, label: string, href: string|null}> */
	private function overviewAction(): array
	{
		$l10n = $this->l10nFactory->get(Application::APP_ID);
		return AdminRecoveryAction::adminSection(
			$this->urlGenerator,
			$l10n,
			'admin-overview',
			'overview',
			$l10n->t('Open admin overview'),
		);
	}

	public function id(): string
	{
		return 'disk';
	}

	public function probe(): HealthCard
	{
		$datadir = trim((string)$this->config->getSystemValue('datadirectory', ''));
		if ($datadir === '') {
			return new HealthCard(
				'disk',
				'Data disk',
				HealthCardState::UNKNOWN,
				$this->l10nFactory->get(Application::APP_ID)->t('Unknown'),
				$this->l10nFactory->get(Application::APP_ID)->t('Data directory is not configured.'),
			);
		}

		$real = realpath($datadir);
		if ($real === false || $real === '') {
			return new HealthCard(
				'disk',
				'Data disk',
				HealthCardState::UNKNOWN,
				$this->l10nFactory->get(Application::APP_ID)->t('Unknown'),
				$this->l10nFactory->get(Application::APP_ID)->t('Data directory is not readable.'),
			);
		}

		// Jail: only measure the resolved datadirectory itself (never a caller path).
		$free = @disk_free_space($real);
		$total = @disk_total_space($real);
		if ($free === false || $total === false || $total <= 0.0) {
			return new HealthCard(
				'disk',
				'Data disk',
				HealthCardState::UNKNOWN,
				$this->l10nFactory->get(Application::APP_ID)->t('Unknown'),
				$this->l10nFactory->get(Application::APP_ID)->t('Could not read disk space for the data directory.'),
			);
		}

		$ratio = $free / $total;
		$pct = (int)floor($ratio * 100);
		$label = $this->l10nFactory->get(Application::APP_ID)->t('%s%% free', [(string)$pct]);
		$state = self::stateForRatio($ratio);

		if ($state === HealthCardState::CRITICAL) {
			return new HealthCard(
				'disk',
				'Data disk',
				HealthCardState::CRITICAL,
				$label,
				$this->l10nFactory->get(Application::APP_ID)->t('Data directory is almost full.'),
				$this->overviewAction(),
			);
		}
		if ($state === HealthCardState::DEGRADED) {
			return new HealthCard(
				'disk',
				'Data disk',
				HealthCardState::DEGRADED,
				$label,
				$this->l10nFactory->get(Application::APP_ID)->t('Data directory free space is getting low.'),
				$this->overviewAction(),
			);
		}
		if ($state === HealthCardState::UNKNOWN) {
			return new HealthCard(
				'disk',
				'Data disk',
				HealthCardState::UNKNOWN,
				$this->l10nFactory->get(Application::APP_ID)->t('Unknown'),
				$this->l10nFactory->get(Application::APP_ID)->t('Could not read disk space for the data directory.'),
			);
		}

		return new HealthCard(
			'disk',
			'Data disk',
			HealthCardState::OK,
			$label,
			$this->l10nFactory->get(Application::APP_ID)->t('Data directory has enough free space.'),
		);
	}

	/**
	 * Test helper: classify a free/total ratio without filesystem I/O.
	 */
	public static function stateForRatio(float $ratio): string
	{
		if ($ratio < 0.0 || !is_finite($ratio)) {
			return HealthCardState::UNKNOWN;
		}
		if ($ratio < self::FREE_DEGRADED) {
			return HealthCardState::CRITICAL;
		}
		if ($ratio < self::FREE_OK) {
			return HealthCardState::DEGRADED;
		}
		return HealthCardState::OK;
	}
}
