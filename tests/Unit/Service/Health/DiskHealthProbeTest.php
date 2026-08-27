<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Service\Health;

use OCA\LogCheck\Service\Health\DiskHealthProbe;
use OCA\LogCheck\Service\Health\HealthCardState;
use OCP\IConfig;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

class DiskHealthProbeTest extends TestCase
{
	public function testStateForRatioThresholds(): void
	{
		self::assertSame(HealthCardState::OK, DiskHealthProbe::stateForRatio(0.20));
		self::assertSame(HealthCardState::DEGRADED, DiskHealthProbe::stateForRatio(0.10));
		self::assertSame(HealthCardState::CRITICAL, DiskHealthProbe::stateForRatio(0.04));
		self::assertSame(HealthCardState::UNKNOWN, DiskHealthProbe::stateForRatio(-1.0));
	}

	public function testEmptyDatadirIsUnknown(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->with('datadirectory', '')->willReturn('');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $m, array $a = []) => $m);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$url = $this->createMock(\OCP\IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('/settings/admin/overview');

		$card = (new DiskHealthProbe($config, $factory, $url))->probe();
		self::assertSame(HealthCardState::UNKNOWN, $card->state);
		self::assertNotSame(HealthCardState::OK, $card->state);
	}

	public function testProbeUsesOnlyConfiguredDatadir(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 4) . '/lib/Service/Health/DiskHealthProbe.php');
		self::assertStringContainsString("getSystemValue('datadirectory'", $src);
		self::assertDoesNotMatchRegularExpression('/getParam\s*\(|\$_GET|\$_POST/i', $src);
	}
}
