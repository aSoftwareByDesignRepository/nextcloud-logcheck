<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Migration;

use OCA\LogCheck\Migration\Version1000Date20260826150000;
use OCA\LogCheck\Migration\Version1303Date20260826224500;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Schema\IColumn;
use OCP\DB\Schema\ITable;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Critic MF: invoke migration changeSchema (not absolute-no-gos / table-exists theater).
 *
 * Table doubles are environment-agnostic:
 * - NC >= 35 containers: ISchemaWrapper declares ITable/IColumn return types,
 *   so the double must implement OCP\DB\Schema\ITable (mock).
 * - NC <= 34 containers: no ITable; Doctrine\DBAL\Schema\Table mock satisfies
 *   the untyped createTable/getTable.
 * - Host runs (vendored nextcloud/ocp stubs only): neither exists; a recording
 *   stub keeps `composer test` green without a Nextcloud tree.
 */
final class MigrationChangeSchemaInvokeTest extends TestCase
{
	/**
	 * @param array<int, array{name:string, type:mixed, options:array}> $addedCols
	 */
	private function newTableDouble(array &$addedCols): object
	{
		if (interface_exists(ITable::class)) {
			$table = $this->createMock(ITable::class);
			$column = $this->createMock(IColumn::class);
			$table->method('addColumn')->willReturnCallback(
				static function (string $name, $type = null, array $options = []) use (&$addedCols, $column): IColumn {
					$addedCols[] = ['name' => $name, 'type' => $type, 'options' => $options];
					return $column;
				}
			);
			$table->method('setPrimaryKey')->willReturnSelf();
			$table->method('addIndex')->willReturnSelf();
			return $table;
		}

		if (class_exists(\Doctrine\DBAL\Schema\Table::class)) {
			$table = $this->getMockBuilder(\Doctrine\DBAL\Schema\Table::class)
				->disableOriginalConstructor()
				->onlyMethods(['addColumn', 'setPrimaryKey', 'addIndex', 'hasColumn'])
				->getMock();
			$table->method('addColumn')->willReturnCallback(
				static function (string $name, $type = null, array $options = []) use (&$addedCols, $table) {
					$addedCols[] = ['name' => $name, 'type' => $type, 'options' => $options];
					return $table;
				}
			);
			$table->method('setPrimaryKey')->willReturnSelf();
			$table->method('addIndex')->willReturnSelf();
			return $table;
		}

		return new class($addedCols) {
			/** @var array<int, array{name:string, type:mixed, options:array}> */
			private array $addedCols;

			/** @param array<int, array{name:string, type:mixed, options:array}> $addedCols */
			public function __construct(array &$addedCols)
			{
				$this->addedCols = &$addedCols;
			}

			public function addColumn(string $name, $type = null, array $options = []): self
			{
				$this->addedCols[] = ['name' => $name, 'type' => $type, 'options' => $options];
				return $this;
			}

			public function setPrimaryKey(array $columns, $indexName = null): self
			{
				return $this;
			}

			public function addIndex(array $columns, $indexName = null, array $flags = [], array $options = []): self
			{
				return $this;
			}

			public function hasColumn(string $name): bool
			{
				foreach ($this->addedCols as $col) {
					if ($col['name'] === $name) {
						return true;
					}
				}
				return false;
			}
		};
	}

	public function testVersion1000CreatesCoreTablesWhenAbsent(): void
	{
		$schema = $this->createMock(ISchemaWrapper::class);
		$created = [];
		$schema->method('hasTable')->willReturn(false);
		$schema->method('createTable')->willReturnCallback(function (string $name) use (&$created): object {
			$created[] = $name;
			$addedCols = &$this->addedCols;
			return $this->newTableDouble($addedCols);
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
		$addedCols = [];
		$table = $this->newTableDouble($addedCols);
		if ($table instanceof \PHPUnit\Framework\MockObject\MockObject) {
			$table->method('hasColumn')->with('claim_gen')->willReturn(false);
		}

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
		$claimGen = null;
		foreach ($addedCols as $col) {
			if ($col['name'] === 'claim_gen') {
				$claimGen = $col;
			}
		}
		self::assertNotNull($claimGen, 'migration must add lck_pending.claim_gen');
		self::assertSame('integer', $claimGen['type']);
		self::assertTrue((bool)($claimGen['options']['notnull'] ?? false), 'claim_gen must be notnull');
	}

	/** @var array<int, array{name:string, type:mixed, options:array}> */
	private array $addedCols = [];
}
