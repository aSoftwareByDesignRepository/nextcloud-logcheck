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
 * Add claim_gen so markSent/markFailed pin a claim even when updated_at collides in the same second.
 */
class Version1303Date20260826224500 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('lck_pending')) {
			$t = $schema->getTable('lck_pending');
			if (!$t->hasColumn('claim_gen')) {
				$t->addColumn('claim_gen', 'integer', [
					'notnull' => true,
					'default' => 0,
				]);
			}
		}
		return $schema;
	}
}
