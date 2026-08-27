<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Integration;

use OCA\LogCheck\Exception\UpgradeBackupException;
use OCA\LogCheck\Service\UpgradeBackupService;
use OCP\IDBConnection;
use Test\TestCase;

final class UpgradeBackupIntegrationTest extends TestCase
{
	private UpgradeBackupService $backupService;
	private IDBConnection $db;

	protected function setUp(): void
	{
		parent::setUp();
		$this->backupService = \OC::$server->get(UpgradeBackupService::class);
		$this->db = \OC::$server->get(IDBConnection::class);
	}

	public function testCreateListAndRestoreRoundTrip(): void
	{
		if (!$this->db->tableExists('lck_accumulator')) {
			self::markTestSkipped('LogCheck tables not present in this instance.');
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
