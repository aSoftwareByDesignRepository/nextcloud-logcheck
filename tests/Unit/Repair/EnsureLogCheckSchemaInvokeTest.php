<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Repair;

use OCA\LogCheck\Repair\EnsureLogCheckSchema;
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

	public function testRunReportsOkWhenAllTablesExist(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$config = $this->createMock(IConfig::class);
		$config->expects(self::once())
			->method('deleteAppValue')
			->with(EnsureLogCheckSchema::APP_ID, UninstallDropTables::REPAIR_PASS_KEY);
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with('logcheck schema OK');
		$output->expects(self::never())->method('warning');

		(new EnsureLogCheckSchema($db, $config))->run($output);
	}

	public function testRunWarnsWhenTablesMissing(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturnCallback(static fn (string $t): bool => $t !== 'lck_locks');
		$config = $this->createMock(IConfig::class);
		$config->method('deleteAppValue');
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('warning')->with(self::stringContains('lck_locks'));
		$output->expects(self::never())->method('info');

		(new EnsureLogCheckSchema($db, $config))->run($output);
	}
}
