<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\LogCheck\Repair;

use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Idempotent schema presence check after install / post-migration.
 */
final class EnsureLogCheckSchema implements IRepairStep
{
	public const APP_ID = 'logcheck';

	/** @var list<string> */
	public const TABLES = [
		'lck_accumulator',
		'lck_chan_state',
		'lck_cursor',
		'lck_delivery',
		'lck_locks',
		'lck_pending',
		'lck_settings',
	];

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
		$missing = [];
		foreach (self::TABLES as $table) {
			if (!$this->connection->tableExists($table)) {
				$missing[] = $table;
			}
		}
		if ($missing !== []) {
			$output->warning('logcheck missing tables: ' . implode(', ', $missing) . ' — run migrations');
		} else {
			$output->info('logcheck schema OK');
		}
	}
}
