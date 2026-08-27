<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Source contract: markSent/markFailed must pin status=sending + claim updated_at.
 * Kills mutants that drop the claim-generation predicate (late sender after reclaim).
 */
class PendingStoreClaimGenerationTest extends TestCase
{
	public function testMarkSentRequiresSendingAndUpdatedAt(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/PendingStore.php');
		$markSentPos = strpos($src, 'function markSent(');
		$markFailedPos = strpos($src, 'function markFailed(');
		self::assertNotFalse($markSentPos);
		self::assertNotFalse($markFailedPos);
		self::assertGreaterThan($markSentPos, $markFailedPos);

		$markSentBody = substr($src, $markSentPos, $markFailedPos - $markSentPos);
		self::assertStringContainsString("eq('status'", $markSentBody);
		self::assertStringContainsString("'sending'", $markSentBody);
		self::assertStringContainsString("eq('claim_gen'", $markSentBody);
		self::assertStringContainsString('$claimGen', $markSentBody);
	}
}
