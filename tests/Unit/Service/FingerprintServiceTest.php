<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Service;

use OCA\LogCheck\Service\FingerprintService;
use PHPUnit\Framework\TestCase;

class FingerprintServiceTest extends TestCase
{
	public function testUuidAndTimestampNormalizeToSameFingerprint(): void
	{
		$svc = new FingerprintService();
		$a = $svc->fingerprint(3, 'dav', 'Error for 550e8400-e29b-41d4-a716-446655440000 at 2026-08-26T12:00:00+00:00');
		$b = $svc->fingerprint(3, 'dav', 'Error for 11111111-1111-4111-8111-111111111111 at 2026-01-01T00:00:00Z');
		self::assertSame($a, $b);
	}

	public function testDifferentAppsDiffer(): void
	{
		$svc = new FingerprintService();
		$a = $svc->fingerprint(3, 'files', 'boom');
		$b = $svc->fingerprint(3, 'dav', 'boom');
		self::assertNotSame($a, $b);
	}
}
