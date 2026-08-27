<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Integration;

use OCA\LogCheck\Service\LeaseService;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Docker: lease steal prevents renew (NN-20 / SF-Z02 gate used inside WatchRunner TXN).
 */
class LeaseStealIntegrationTest extends TestCase
{
	private ?IDBConnection $db = null;

	protected function setUp(): void
	{
		parent::setUp();
		if (!class_exists(\OC::class) && !file_exists('/var/www/html/lib/base.php')) {
			self::markTestSkipped('Requires Nextcloud runtime (Docker).');
		}
		if (!class_exists(\OC::class)) {
			require_once '/var/www/html/lib/base.php';
		}
		$this->db = Server::get(IDBConnection::class);
		if (!$this->db->tableExists('lck_locks')) {
			self::markTestSkipped('lck_locks missing — enable logcheck first.');
		}
	}

	public function testStolenLeaseCannotRenew(): void
	{
		$lease = new LeaseService($this->db);
		$ownerA = 'zeus-a-' . bin2hex(random_bytes(4));
		$ownerB = 'zeus-b-' . bin2hex(random_bytes(4));

		self::assertTrue($lease->acquire($ownerA));
		self::assertTrue($lease->stillHolds($ownerA));

		$qb = $this->db->getQueryBuilder();
		$qb->update('lck_locks')
			->set('lease_until', $qb->createNamedParameter(time() - 10))
			->where($qb->expr()->eq('lock_name', $qb->createNamedParameter(LeaseService::LOCK_NAME)))
			->executeStatement();

		self::assertTrue($lease->acquire($ownerB));
		self::assertFalse($lease->renew($ownerA), 'Stolen owner must not renew');
		self::assertTrue($lease->renew($ownerB));
		$lease->release($ownerB);
	}

	public function testStolenLeaseCannotRenewInTransaction(): void
	{
		$lease = new LeaseService($this->db);
		$ownerA = 'zeus-a2-' . bin2hex(random_bytes(4));
		$ownerB = 'zeus-b2-' . bin2hex(random_bytes(4));

		self::assertTrue($lease->acquire($ownerA));

		$qb = $this->db->getQueryBuilder();
		$qb->update('lck_locks')
			->set('lease_until', $qb->createNamedParameter(time() - 10))
			->where($qb->expr()->eq('lock_name', $qb->createNamedParameter(LeaseService::LOCK_NAME)))
			->executeStatement();

		self::assertTrue($lease->acquire($ownerB));

		$this->db->beginTransaction();
		try {
			self::assertFalse($lease->renewInTransaction($ownerA));
			self::assertTrue($lease->renewInTransaction($ownerB));
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
		$lease->release($ownerB);
	}
}
