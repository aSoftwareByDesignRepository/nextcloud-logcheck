<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Zeus NG-Z05 / NN-20 / SF-Z02: durable writes renew lease under FOR UPDATE inside the TXN.
 */
class LeaseGatedWritesTest extends TestCase
{
	public function testAtomicHelpersRenewLeaseInsideTransaction(): void
	{
		$src = (string)file_get_contents(__DIR__ . '/../../../lib/Service/WatchRunner.php');
		$flush = $this->methodBody($src, 'atomicFlush');
		$persist = $this->methodBody($src, 'atomicPersistProgress');

		self::assertStringContainsString('beginTransaction()', $flush);
		self::assertStringContainsString('renewInTransaction($owner)', $flush);
		self::assertTrue(
			strpos($flush, 'beginTransaction()') < strpos($flush, 'renewInTransaction($owner)'),
			'atomicFlush must renewInTransaction after beginTransaction'
		);
		self::assertTrue(
			strpos($flush, 'renewInTransaction($owner)') < strpos($flush, 'insertForChannels'),
			'atomicFlush must renew before pending insert'
		);

		self::assertStringContainsString('beginTransaction()', $persist);
		self::assertStringContainsString('renewInTransaction($owner)', $persist);
		self::assertTrue(
			strpos($persist, 'beginTransaction()') < strpos($persist, 'renewInTransaction($owner)')
		);
		self::assertTrue(
			strpos($persist, 'renewInTransaction($owner)') < strpos($persist, 'accumulatorStore->save')
		);

		$lease = (string)file_get_contents(__DIR__ . '/../../../lib/Service/LeaseService.php');
		self::assertStringContainsString("getSQL() . ' FOR UPDATE'", $lease);
		self::assertStringContainsString('function renewInTransaction', $lease);

		$api = (string)file_get_contents(__DIR__ . '/../../../lib/Controller/ApiController.php');
		self::assertStringContainsString('initializeCursorAtEofUnderLease', $api);
		self::assertStringContainsString('wasWatching', $api);
	}

	private function methodBody(string $src, string $name): string
	{
		$pattern = '/private function ' . preg_quote($name, '/') . '\([^)]*\):[^{]*\{/';
		self::assertSame(1, preg_match($pattern, $src, $m, PREG_OFFSET_CAPTURE));
		$start = (int)$m[0][1];
		$rest = substr($src, $start);
		$depth = 0;
		$end = 0;
		$len = strlen($rest);
		for ($i = 0; $i < $len; $i++) {
			$ch = $rest[$i];
			if ($ch === '{') {
				$depth++;
			} elseif ($ch === '}') {
				$depth--;
				if ($depth === 0) {
					$end = $i + 1;
					break;
				}
			}
		}
		self::assertGreaterThan(0, $end, 'Could not find end of ' . $name);
		return substr($rest, 0, $end);
	}
}
