<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Integration;

use OCA\LogCheck\Service\PendingStore;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Docker integration: pending claim exclusivity (AC-41).
 * Skips when not running inside Nextcloud (host unit suite).
 */
class PendingClaimIntegrationTest extends TestCase
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
		if (!$this->db->tableExists('lck_pending')) {
			self::markTestSkipped('lck_pending missing — enable logcheck first.');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete('lck_pending')->executeStatement();
	}

	public function testSecondClaimCannotTakeSameRow(): void
	{
		$store = new PendingStore($this->db);
		$store->insertForChannels('evt-claim-1', ['email'], [
			'schema' => 'lck.alert.v1',
			'total_matched' => 1,
		]);

		$first = $store->claimOne();
		self::assertNotNull($first);
		self::assertSame('email', $first['channel']);

		$second = $store->claimOne();
		self::assertNull($second, 'Same pending row must not be claimed twice');

		$store->markSent($first['event_id'], $first['channel'], (int)$first['claim_gen']);
	}

	public function testMarkFailedReturnsRowToPendingForRetry(): void
	{
		$store = new PendingStore($this->db);
		$store->insertForChannels('evt-retry-1', ['slack'], [
			'schema' => 'lck.alert.v1',
			'total_matched' => 2,
		]);
		$row = $store->claimOne();
		self::assertNotNull($row);
		$store->markFailed($row['event_id'], $row['channel'], 1, (int)$row['claim_gen']);

		$again = $store->claimOne();
		self::assertNotNull($again);
		self::assertSame('evt-retry-1', $again['event_id']);
		self::assertSame(1, $again['attempts']);
		$store->markSent($again['event_id'], $again['channel'], (int)$again['claim_gen']);
	}

	/**
	 * AC-40 / SF-04: crash after claim leaves row in "sending"; reclaim returns it
	 * for exclusive retry (no silent loss; no concurrent second claim while sending).
	 */
	public function testCrashAfterClaimReclaimAllowsRetryNotDoubleClaim(): void
	{
		$store = new PendingStore($this->db);
		$store->insertForChannels('evt-crash-1', ['webhook'], [
			'schema' => 'lck.alert.v1',
			'total_matched' => 3,
		]);
		$row = $store->claimOne();
		self::assertNotNull($row);
		self::assertSame('sending', $row['status']);
		$staleClaimGen = (int)$row['claim_gen'];
		self::assertGreaterThan(0, $staleClaimGen);

		// Concurrent worker must not steal while fresh "sending".
		self::assertNull($store->claimOne());

		// Simulate process death: age the row past SENDING_STALE_SECONDS.
		$qb = $this->db->getQueryBuilder();
		$qb->update('lck_pending')
			->set('updated_at', $qb->createNamedParameter(time() - PendingStore::SENDING_STALE_SECONDS - 5))
			->where($qb->expr()->eq('event_id', $qb->createNamedParameter('evt-crash-1')))
			->executeStatement();

		$reclaimed = $store->claimOne();
		self::assertNotNull($reclaimed);
		self::assertSame('evt-crash-1', $reclaimed['event_id']);
		self::assertSame('webhook', $reclaimed['channel']);
		self::assertNotSame($staleClaimGen, (int)$reclaimed['claim_gen']);

		// Late first sender must not complete the new claim generation.
		self::assertFalse($store->markSent($row['event_id'], $row['channel'], $staleClaimGen));

		$store->markSent($reclaimed['event_id'], $reclaimed['channel'], (int)$reclaimed['claim_gen']);
	}
}
