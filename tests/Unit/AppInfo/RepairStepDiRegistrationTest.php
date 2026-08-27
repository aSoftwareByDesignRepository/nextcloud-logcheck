<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;

class RepairStepDiRegistrationTest extends TestCase
{
	public function testInfoXmlRepairStepsAreRegistered(): void
	{
		$info = file_get_contents(__DIR__ . '/../../../appinfo/info.xml');
		self::assertNotFalse($info);
		preg_match_all('#<step>([^<]+)</step>#', $info, $m);
		$steps = $m[1];
		self::assertNotEmpty($steps);
		$app = file_get_contents(__DIR__ . '/../../../lib/AppInfo/Application.php');
		self::assertNotFalse($app);
		foreach ($steps as $fqcn) {
			$short = substr($fqcn, strrpos($fqcn, '\\') + 1);
			self::assertStringContainsString($short . '::class', $app, "Missing DI for $fqcn");
		}
	}
}
