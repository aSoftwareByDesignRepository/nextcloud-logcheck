<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Migration;

use Doctrine\DBAL\Schema\Table;
use OCA\LogCheck\Migration\Version1000Date20260826150000;
use OCA\LogCheck\Migration\Version1303Date20260826224500;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Critic MF: invoke migration changeSchema (not absolute-no-gos / table-exists theater).
 */
final class MigrationChangeSchemaInvokeTest extends TestCase
{
	public function testVersion1000CreatesCoreTablesWhenAbsent(): void
	{
		$schema = $this->createMock(ISchemaWrapper::class);
		$created = [];
		$schema->method('hasTable')->willReturn(false);
		$schema->method('createTable')->willReturnCallback(function (string $name) use (&$created): Table {
			$created[] = $name;
			$table = $this->getMockBuilder(Table::class)
				->disableOriginalConstructor()
				->onlyMethods(['addColumn', 'setPrimaryKey', 'addIndex'])
				->getMock();
			$table->method('addColumn')->willReturnSelf();
			$table->method('setPrimaryKey')->willReturnSelf();
			$table->method('addIndex')->willReturnSelf();
			return $table;
		});

		$output = $this->createMock(IOutput::class);
		$result = (new Version1000Date20260826150000())->changeSchema(
			$output,
			static fn () => $schema,
			[],
		);

		self::assertSame($schema, $result);
		self::assertContains('lck_settings', $created);
		self::assertContains('lck_cursor', $created);
		self::assertContains('lck_pending', $created);
		self::assertContains('lck_locks', $created);
	}

	public function testVersion1303AddsClaimGenWhenMissing(): void
	{
		$table = $this->getMockBuilder(Table::class)
			->disableOriginalConstructor()
			->onlyMethods(['hasColumn', 'addColumn'])
			->getMock();
		$table->method('hasColumn')->with('claim_gen')->willReturn(false);
		$table->expects(self::once())->method('addColumn')->with(
			'claim_gen',
			'integer',
			self::callback(static fn (array $opts): bool => ($opts['notnull'] ?? false) === true),
		);

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('lck_pending')->willReturn(true);
		$schema->method('getTable')->with('lck_pending')->willReturn($table);

		$output = $this->createMock(IOutput::class);
		$result = (new Version1303Date20260826224500())->changeSchema(
			$output,
			static fn () => $schema,
			[],
		);
		self::assertSame($schema, $result);
	}
}
