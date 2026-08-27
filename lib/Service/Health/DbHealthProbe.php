<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\LogCheck\Service\Health;

use OCA\LogCheck\AppInfo\Application;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;

/**
 * Database connectivity via existing OC connection (SELECT 1).
 */
final class DbHealthProbe implements HealthProbeInterface
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly IFactory $l10nFactory,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	public function id(): string
	{
		return 'db';
	}

	public function probe(): HealthCard
	{
		try {
			$this->db->executeQuery('SELECT 1')->closeCursor();
			return new HealthCard(
				'db',
				'Database',
				HealthCardState::OK,
				$this->l10nFactory->get(Application::APP_ID)->t('Connected'),
				$this->l10nFactory->get(Application::APP_ID)->t('Database responds to a simple check.'),
			);
		} catch (\Throwable) {
			$l10n = $this->l10nFactory->get(Application::APP_ID);
			return new HealthCard(
				'db',
				'Database',
				HealthCardState::CRITICAL,
				$l10n->t('Unreachable'),
				$l10n->t('Could not reach the database.'),
				AdminRecoveryAction::adminSection($this->urlGenerator, $l10n, 'admin-overview', 'overview', $l10n->t('Open admin overview')),
			);
		}
	}
}
