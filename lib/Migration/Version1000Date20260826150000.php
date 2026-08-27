<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\LogCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Initial LogCheck schema (lck_* tables).
 */
class Version1000Date20260826150000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('lck_settings')) {
			$t = $schema->createTable('lck_settings');
			$t->addColumn('id', 'integer', [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$t->addColumn('version', 'integer', [
				'notnull' => true,
				'default' => 1,
			]);
			$t->addColumn('payload', 'text', [
				'notnull' => true,
			]);
			$t->addColumn('updated_at', 'bigint', [
				'notnull' => true,
			]);
			$t->setPrimaryKey(['id'], 'lck_settings_pk');
		}

		if (!$schema->hasTable('lck_cursor')) {
			$t = $schema->createTable('lck_cursor');
			$t->addColumn('id', 'integer', [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$t->addColumn('path', 'string', [
				'notnull' => true,
				'length' => 1024,
			]);
			$t->addColumn('offset', 'bigint', [
				'notnull' => true,
				'default' => 0,
			]);
			$t->addColumn('size', 'bigint', [
				'notnull' => true,
				'default' => 0,
			]);
			$t->addColumn('inode', 'string', [
				'notnull' => false,
				'length' => 64,
				'default' => '',
			]);
			$t->addColumn('fingerprint', 'string', [
				'notnull' => false,
				'length' => 128,
				'default' => '',
			]);
			$t->addColumn('updated_at', 'bigint', [
				'notnull' => true,
			]);
			$t->setPrimaryKey(['id'], 'lck_cursor_pk');
		}

		if (!$schema->hasTable('lck_accumulator')) {
			$t = $schema->createTable('lck_accumulator');
			$t->addColumn('id', 'integer', [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$t->addColumn('payload', 'text', [
				'notnull' => true,
			]);
			$t->addColumn('updated_at', 'bigint', [
				'notnull' => true,
			]);
			$t->setPrimaryKey(['id'], 'lck_accum_pk');
		}

		if (!$schema->hasTable('lck_pending')) {
			$t = $schema->createTable('lck_pending');
			$t->addColumn('event_id', 'string', [
				'notnull' => true,
				'length' => 64,
			]);
			$t->addColumn('channel', 'string', [
				'notnull' => true,
				'length' => 32,
			]);
			$t->addColumn('status', 'string', [
				'notnull' => true,
				'length' => 16,
				'default' => 'pending',
			]);
			$t->addColumn('attempts', 'integer', [
				'notnull' => true,
				'default' => 0,
			]);
			$t->addColumn('payload', 'text', [
				'notnull' => true,
			]);
			$t->addColumn('created_at', 'bigint', [
				'notnull' => true,
			]);
			$t->addColumn('updated_at', 'bigint', [
				'notnull' => true,
			]);
			$t->addColumn('claim_gen', 'integer', [
				'notnull' => true,
				'default' => 0,
			]);
			$t->setPrimaryKey(['event_id', 'channel'], 'lck_pending_pk');
			$t->addIndex(['status', 'created_at'], 'lck_pend_st_idx');
		}

		if (!$schema->hasTable('lck_chan_state')) {
			$t = $schema->createTable('lck_chan_state');
			$t->addColumn('channel', 'string', [
				'notnull' => true,
				'length' => 32,
			]);
			$t->addColumn('fail_count', 'integer', [
				'notnull' => true,
				'default' => 0,
			]);
			$t->addColumn('last_error', 'text', [
				'notnull' => false,
			]);
			$t->addColumn('disabled_at', 'bigint', [
				'notnull' => false,
			]);
			$t->addColumn('verified_at', 'bigint', [
				'notnull' => false,
			]);
			$t->setPrimaryKey(['channel'], 'lck_chan_pk');
		}

		if (!$schema->hasTable('lck_delivery')) {
			$t = $schema->createTable('lck_delivery');
			$t->addColumn('id', 'bigint', [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$t->addColumn('event_id', 'string', [
				'notnull' => true,
				'length' => 64,
			]);
			$t->addColumn('channel', 'string', [
				'notnull' => true,
				'length' => 32,
			]);
			$t->addColumn('status', 'string', [
				'notnull' => true,
				'length' => 16,
			]);
			$t->addColumn('created_at', 'bigint', [
				'notnull' => true,
			]);
			$t->setPrimaryKey(['id'], 'lck_deliv_pk');
			$t->addIndex(['created_at'], 'lck_deliv_ca_idx');
		}

		if (!$schema->hasTable('lck_locks')) {
			$t = $schema->createTable('lck_locks');
			$t->addColumn('lock_name', 'string', [
				'notnull' => true,
				'length' => 64,
			]);
			$t->addColumn('owner', 'string', [
				'notnull' => false,
				'length' => 64,
			]);
			$t->addColumn('lease_until', 'bigint', [
				'notnull' => true,
				'default' => 0,
			]);
			$t->setPrimaryKey(['lock_name'], 'lck_locks_pk');
		}

		return $schema;
	}
}
