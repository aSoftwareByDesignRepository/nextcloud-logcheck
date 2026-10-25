<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\LogCheck\Repair;

use OC\DB\Connection;
use OC\DB\MigrationService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCP\Server;

/**
 * Idempotent schema presence check after install / post-migration.
 * Prefer migrate('latest') — same pattern as SbdLicenseOps Ensure*Schema.
 */
final class EnsureLogCheckSchema implements IRepairStep
{
	public const APP_ID = 'logcheck';

	// The table set this step verifies delegates to the generated
	// {@see SchemaTableCatalog} — a dedicated new class, because upgrade-path
	// code must never reference a member added to a pre-existing class
	// mid-release (the old version may already be loaded → `Undefined
	// constant` stalls `occ upgrade`; see UpgradePathConstantsSafety).
	/** @var list<string> */
	public const TABLES = SchemaTableCatalog::EXPECTED_TABLES;

	public function __construct(
		private readonly IDBConnection $connection,
		private readonly IConfig $config,
	) {
	}

	public function getName(): string
	{
		return 'Ensure logcheck core tables exist';
	}

	public function run(IOutput $output): void
	{
		$this->config->deleteAppValue(self::APP_ID, UninstallDropTables::REPAIR_PASS_KEY);

		$missingBefore = $this->missingTables();
		if ($missingBefore === []) {
			$output->info('LogCheck: all ' . count(SchemaTableCatalog::EXPECTED_TABLES) . ' tables are present.');
			return;
		}

		$output->info(sprintf(
			'LogCheck: %d table(s) missing (%s); running migrate("latest").',
			count($missingBefore),
			implode(', ', $missingBefore),
		));

		$migrationService = new MigrationService(
			self::APP_ID,
			Server::get(Connection::class),
		);
		$migrationService->migrate('latest', false);

		$missingAfter = $this->missingTables();
		if ($missingAfter === []) {
			$output->info('LogCheck: schema repair completed; all tables are now present.');
			return;
		}

		throw new \RuntimeException(sprintf(
			'LogCheck schema is still incomplete after migrate("latest"). Missing: %s. '
			. 'Run `php occ upgrade` or re-enable the app and check nextcloud.log. '
			. 'Deploy the full app bundle — partial file copies break repair steps.',
			implode(', ', $missingAfter),
		));
	}

	/**
	 * @return list<string>
	 */
	private function missingTables(): array
	{
		$missing = [];
		foreach (SchemaTableCatalog::EXPECTED_TABLES as $table) {
			if (!$this->connection->tableExists($table)) {
				$missing[] = $table;
			}
		}
		return $missing;
	}
}
