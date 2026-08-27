<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

class AbsoluteNoGosTest extends TestCase
{
	public function testArchitectureScriptPasses(): void
	{
		$script = __DIR__ . '/../../Architecture/absolute-no-gos.php';
		self::assertFileExists($script);
		passthru('php ' . escapeshellarg($script), $code);
		self::assertSame(0, $code);
	}
}
