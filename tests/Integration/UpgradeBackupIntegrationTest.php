<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Integration;

use OCA\LogCheck\Exception\UpgradeBackupException;
use OCA\LogCheck\Service\UpgradeBackupService;
use OCA\LogCheck\Tests\Support\UpgradeBackupStateGuard;
use OCP\IDBConnection;
use Test\TestCase;

final class UpgradeBackupIntegrationTest extends TestCase
{
	private UpgradeBackupService $backupService;
	private IDBConnection $db;
	private UpgradeBackupStateGuard $backupStateGuard;

	protected function setUp(): void
	{
		parent::setUp();
		// createSnapshot() writes a real snapshot under appdata and updates the
		// upgrade_backup_last_snapshot_id registry — record the baseline first so
		// the guard can sweep them in tearDown.
		$this->backupStateGuard = new UpgradeBackupStateGuard();
		$this->backupStateGuard->setUp();
		$this->backupService = \OCP\Server::get(UpgradeBackupService::class);
		$this->db = \OCP\Server::get(IDBConnection::class);
	}

	protected function tearDown(): void
	{
		$this->backupStateGuard->tearDown();
		parent::tearDown();
	}

	public function testCreateListAndRestoreRoundTrip(): void
	{
		if (!$this->db->tableExists('lck_accumulator')) {
			self::markTestSkipped('HealthCheck tables not present in this instance.');
		}

		$before = $this->countRows('lck_accumulator');

		$result = $this->backupService->createSnapshot('integration-test');
		$snapshotId = $result['id'];
		self::assertNotSame('', $snapshotId);
		self::assertTrue($result['manifest']['complete'] ?? false);
		self::assertNotEmpty($result['manifest']['tables'] ?? [], 'Snapshot must include table metadata when tables exist.');

		$snapshots = $this->backupService->listSnapshots();
		$ids = array_map(static fn (array $snapshot): string => (string)($snapshot['id'] ?? ''), $snapshots);
		self::assertContains($snapshotId, $ids, 'listSnapshots must find the snapshot just created');

		$this->db->getQueryBuilder()
			->delete('lck_accumulator')
			->executeStatement();
		self::assertSame(0, $this->countRows('lck_accumulator'));

		$this->backupService->restoreSnapshot($snapshotId, false);
		self::assertSame($before, $this->countRows('lck_accumulator'));
	}

	public function testRestoreRejectsInvalidSnapshotId(): void
	{
		$this->expectException(UpgradeBackupException::class);
		$this->backupService->restoreSnapshot('../evil', false);
	}

	private function countRows(string $table): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($table);
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}
}
