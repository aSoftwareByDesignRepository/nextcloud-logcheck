<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Repair;

use OCA\LogCheck\Repair\EnsureLogCheckSchema;
use OCA\LogCheck\Repair\SchemaTableCatalog;
use OCA\LogCheck\Repair\UninstallDropTables;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Critic MF: invoke EnsureLogCheckSchema::getName/run (not DI string registration theater).
 */
final class EnsureLogCheckSchemaInvokeTest extends TestCase
{
	public function testGetName(): void
	{
		$step = new EnsureLogCheckSchema(
			$this->createMock(IDBConnection::class),
			$this->createMock(IConfig::class),
		);
		self::assertSame('Ensure logcheck core tables exist', $step->getName());
	}

	public function testSchemaCatalogIsTheSingleSourceOfTruth(): void
	{
		// SSoT drift guard: the generated catalog's expected set is the
		// uninstall drop list minus intentionally dropped legacy tables, and
		// the schema-repair step verifies that same set.
		self::assertSame(
			SchemaTableCatalog::EXPECTED_TABLES,
			array_values(array_diff(UninstallDropTables::TABLES, SchemaTableCatalog::DROPPED_LEGACY_TABLES)),
		);
		self::assertSame(SchemaTableCatalog::EXPECTED_TABLES, EnsureLogCheckSchema::TABLES);
	}

	public function testRunReportsOkWhenAllTablesExist(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$config = $this->createMock(IConfig::class);
		$config->expects(self::once())
			->method('deleteAppValue')
			->with(EnsureLogCheckSchema::APP_ID, UninstallDropTables::REPAIR_PASS_KEY);
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with(self::stringContains('tables are present'));
		$output->expects(self::never())->method('warning');

		(new EnsureLogCheckSchema($db, $config))->run($output);
	}

	public function testMissingTablesEscalateToMigrationThenFailLoud(): void
	{
		// A missing table no longer warns-and-continues: the step announces
		// migrate("latest") and resolves the migration machinery through the
		// live container, then the post-pass fails loudly. Host unit runs
		// have no container (Server::get throws Error); in-container the
		// recorded-migration no-op leaves the mock-missing table and the
		// post-pass throws RuntimeException. Either way: escalate, never warn.
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturnCallback(static fn (string $t): bool => $t !== 'lck_locks');
		$config = $this->createMock(IConfig::class);
		$config->method('deleteAppValue');
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')
			->with(self::logicalAnd(
				self::stringContains('missing'),
				self::stringContains('lck_locks'),
			));
		$output->expects(self::never())->method('warning');

		$this->expectException(\Throwable::class);
		(new EnsureLogCheckSchema($db, $config))->run($output);
	}
}
