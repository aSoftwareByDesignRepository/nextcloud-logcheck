<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Service;

use OCA\LogCheck\Service\AccumulatorStore;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class AccumulatorStoreTest extends TestCase
{
	private AccumulatorStore $store;

	protected function setUp(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$this->store = new AccumulatorStore($db);
	}

	public function testByAppCappedAtFortyPlusOther(): void
	{
		$acc = [
			'total_matched' => 0,
			'total_muted' => 0,
			'truncated' => false,
			'by_level' => [],
			'by_app' => [],
			'fingerprints' => [],
			'first_match_at' => null,
		];
		$settings = [
			'max_lines_per_digest' => 10000,
			'max_fingerprints_in_payload' => 100,
			'include_message_excerpts' => false,
		];

		for ($i = 0; $i < 40; $i++) {
			$acc = $this->store->mergeHit($acc, [
				'fingerprint' => 'fp-' . $i,
				'level' => 3,
				'app' => 'app' . $i,
				'message' => 'm' . $i,
			], $settings);
		}
		self::assertCount(40, $acc['by_app']);
		self::assertArrayNotHasKey('_other', $acc['by_app']);

		$acc = $this->store->mergeHit($acc, [
			'fingerprint' => 'fp-other-1',
			'level' => 3,
			'app' => 'app-overflow-1',
			'message' => 'x',
		], $settings);
		self::assertArrayHasKey('_other', $acc['by_app']);
		self::assertSame(1, $acc['by_app']['_other']);
		self::assertCount(41, $acc['by_app']); // 40 named + _other
		self::assertArrayNotHasKey('app-overflow-1', $acc['by_app']);

		$acc = $this->store->mergeHit($acc, [
			'fingerprint' => 'fp-other-2',
			'level' => 3,
			'app' => 'app-overflow-2',
			'message' => 'y',
		], $settings);
		self::assertSame(2, $acc['by_app']['_other']);

		// Existing app still increments its own bucket
		$acc = $this->store->mergeHit($acc, [
			'fingerprint' => 'fp-app0-again',
			'level' => 3,
			'app' => 'app0',
			'message' => 'z',
		], $settings);
		self::assertSame(2, $acc['by_app']['app0']);
		self::assertSame(2, $acc['by_app']['_other']);
	}
}
