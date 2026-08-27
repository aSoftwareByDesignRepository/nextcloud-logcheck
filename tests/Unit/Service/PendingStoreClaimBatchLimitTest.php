<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Service;

use OCA\LogCheck\Service\PendingStore;
use PHPUnit\Framework\TestCase;

/**
 * Momos MH-MEM-01: claim scan must never SELECT * pending without a hard cap.
 */
class PendingStoreClaimBatchLimitTest extends TestCase
{
	public function testClaimBatchLimitIsPositiveAndBounded(): void
	{
		self::assertGreaterThan(0, PendingStore::CLAIM_BATCH_LIMIT);
		self::assertLessThanOrEqual(200, PendingStore::CLAIM_BATCH_LIMIT);
	}

	public function testListByStatusSourceEnforcesOrderAndCap(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/PendingStore.php');
		self::assertMatchesRegularExpression('/setMaxResults\s*\(\s*self::CLAIM_BATCH_LIMIT\s*\)/', $src);
		self::assertMatchesRegularExpression('/orderBy\s*\(\s*[\'"]created_at[\'"]\s*,\s*[\'"]ASC[\'"]\s*\)/', $src);
		// Guard against regressing to unbounded select *
		$listFn = preg_match('/function listByStatus.*?\{(.*?)return \$rows;/s', $src, $m) ? $m[1] : '';
		self::assertNotSame('', $listFn);
		self::assertStringContainsString('setMaxResults', $listFn);
		self::assertStringNotContainsString('// unbounded', $listFn);
	}
}
