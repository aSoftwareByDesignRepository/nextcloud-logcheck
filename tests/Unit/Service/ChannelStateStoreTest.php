<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Service;

use OCA\LogCheck\Service\ChannelStateStore;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * recordFailure returns true exactly when fail_count reaches FAIL_DISABLE_THRESHOLD (5).
 */
class ChannelStateStoreTest extends TestCase
{
	public function testRecordFailureReturnsTrueAtThresholdFive(): void
	{
		$store = $this->countingFailureStore();
		self::assertFalse($store->recordFailure('slack', '1'));
		self::assertFalse($store->recordFailure('slack', '2'));
		self::assertFalse($store->recordFailure('slack', '3'));
		self::assertFalse($store->recordFailure('slack', '4'));
		self::assertTrue($store->recordFailure('slack', '5'));
		self::assertSame(5, ChannelStateStore::FAIL_DISABLE_THRESHOLD);
	}

	public function testSafeErrorNeverReturnsRawDiagnostics(): void
	{
		self::assertSame(
			ChannelStateStore::ERR_HTTP,
			ChannelStateStore::safeError('cURL error 7: Failed to connect to 10.0.0.5 port 443')
		);
		self::assertSame(
			ChannelStateStore::ERR_MAIL,
			ChannelStateStore::safeError('SMTP connect() failed to mail.internal.corp')
		);
		self::assertSame(
			ChannelStateStore::ERR_SECRETS,
			ChannelStateStore::safeError('Could not decrypt secret blob xyz')
		);
		self::assertSame(
			ChannelStateStore::ERR_GENERIC,
			ChannelStateStore::safeError('PDOException: SQLSTATE[HY000] at /var/www/html/...')
		);
		self::assertSame(
			'Cannot read the log file. Check permissions.',
			ChannelStateStore::safeError('Cannot read the log file. Check permissions.')
		);
		foreach ([
			ChannelStateStore::safeError('cURL error 7: Failed to connect to 10.0.0.5 port 443'),
			ChannelStateStore::safeError('PDOException: SQLSTATE[HY000] at /var/www/html/...'),
		] as $safe) {
			self::assertStringNotContainsString('10.0.0.5', $safe);
			self::assertStringNotContainsString('/var/www', $safe);
			self::assertStringNotContainsString('PDOException', $safe);
		}
	}

	public function testRecordFailureStoresOnlySafeError(): void
	{
		$store = $this->countingFailureStore();
		$store->recordFailure('slack', 'cURL error 7: Failed to connect to 10.1.2.3');
		$state = $store->get('slack');
		self::assertNotNull($state);
		self::assertSame(ChannelStateStore::ERR_HTTP, $state['last_error']);
		self::assertStringNotContainsString('10.1.2.3', (string)$state['last_error']);
	}

	public function testClearVerificationDropsVerifiedAt(): void
	{
		$store = $this->countingFailureStore();
		$store->recordSuccess('slack');
		self::assertTrue($store->isVerified('slack'));
		$store->clearVerification('slack');
		self::assertFalse($store->isVerified('slack'));
		$state = $store->get('slack');
		self::assertNotNull($state);
		self::assertNull($state['verified_at']);
	}

	/**
	 * Momos H-RE1 hazard characterization: clearing disable before a failed test
	 * leaves the channel live after one more failure (fail_count resets to 0).
	 * ApiController::reenableChannel must NOT call reenable() before testChannel.
	 */
	public function testPrematureReenableThenOneFailureLeavesChannelLive(): void
	{
		$store = $this->countingFailureStore();
		for ($i = 0; $i < ChannelStateStore::FAIL_DISABLE_THRESHOLD; $i++) {
			$store->recordFailure('slack', 'fail ' . $i);
		}
		self::assertTrue($store->isDisabled('slack'));
		$store->reenable('slack');
		self::assertFalse($store->isDisabled('slack'));
		$store->recordFailure('slack', 'still broken');
		self::assertFalse(
			$store->isDisabled('slack'),
			'Premature reenable + one failure must leave channel live — API must not do this before a successful test'
		);
	}

	/** Successful test path: recordSuccess alone clears auto-disable (no separate reenable needed). */
	public function testRecordSuccessClearsAutoDisableAndFailCount(): void
	{
		$store = $this->countingFailureStore();
		for ($i = 0; $i < ChannelStateStore::FAIL_DISABLE_THRESHOLD; $i++) {
			$store->recordFailure('webhook', 'fail ' . $i);
		}
		self::assertTrue($store->isDisabled('webhook'));
		$store->recordSuccess('webhook');
		self::assertFalse($store->isDisabled('webhook'));
		$state = $store->get('webhook');
		self::assertNotNull($state);
		self::assertSame(0, $state['fail_count']);
		self::assertNull($state['disabled_at']);
		self::assertNotNull($state['verified_at']);
	}

	/** Failed test while still disabled must keep the channel disabled. */
	public function testFailedTestWhileDisabledKeepsChannelDisabled(): void
	{
		$store = $this->countingFailureStore();
		for ($i = 0; $i < ChannelStateStore::FAIL_DISABLE_THRESHOLD; $i++) {
			$store->recordFailure('email', 'fail ' . $i);
		}
		self::assertTrue($store->isDisabled('email'));
		$store->recordFailure('email', 're-test still broken');
		self::assertTrue($store->isDisabled('email'));
		$state = $store->get('email');
		self::assertNotNull($state);
		self::assertGreaterThanOrEqual(ChannelStateStore::FAIL_DISABLE_THRESHOLD, $state['fail_count']);
	}

	private function countingFailureStore(): ChannelStateStore
	{
		$state = [
			'channel' => 'slack',
			'fail_count' => 0,
			'last_error' => null,
			'disabled_at' => null,
			'verified_at' => null,
		];
		$exists = false;
		$setBag = [];

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnCallback(static function () use (&$exists, &$state) {
			if (!$exists) {
				return false;
			}
			return [
				'channel' => $state['channel'],
				'fail_count' => $state['fail_count'],
				'last_error' => $state['last_error'],
				'disabled_at' => $state['disabled_at'],
				'verified_at' => $state['verified_at'],
			];
		});
		$result->method('closeCursor')->willReturn(true);

		$expr = new class {
			public function eq(...$a): string
			{
				return 'eq';
			}
		};

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('insert')->willReturnSelf();
		$qb->method('update')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(static fn($v) => $v);
		$qb->method('executeQuery')->willReturn($result);
		$qb->method('set')->willReturnCallback(static function ($col, $val) use ($qb, &$setBag) {
			$setBag[$col] = $val;
			return $qb;
		});
		$qb->method('values')->willReturnCallback(static function (array $vals) use ($qb, &$state, &$exists) {
			$state['fail_count'] = (int)$vals['fail_count'];
			$state['last_error'] = $vals['last_error'];
			$state['disabled_at'] = $vals['disabled_at'];
			$state['verified_at'] = $vals['verified_at'];
			$exists = true;
			return $qb;
		});
		$qb->method('executeStatement')->willReturnCallback(static function () use (&$exists, &$state, &$setBag): int {
			if ($setBag !== []) {
				$state['fail_count'] = (int)$setBag['fail_count'];
				$state['last_error'] = $setBag['last_error'];
				$state['disabled_at'] = $setBag['disabled_at'];
				$state['verified_at'] = $setBag['verified_at'];
				$setBag = [];
			}
			$exists = true;
			return 1;
		});

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		return new ChannelStateStore($db);
	}
}
