<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Service\Health;

use OCA\LogCheck\Service\Health\HealthCardState;
use OCA\LogCheck\Service\Health\JobsHealthProbe;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

class JobsHealthProbeTest extends TestCase
{
	private function probe(IAppConfig $app, IConfig $config): JobsHealthProbe
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $m, array $a = []) => $m);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$url = $this->createMock(\OCP\IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('https://example.test/settings/admin/server');
		return new JobsHealthProbe($app, $config, $factory, $url);
	}

	public function testCronErrorsAreCritical(): void
	{
		$app = $this->createMock(IAppConfig::class);
		$app->method('getValueString')->willReturn('cron');
		$app->method('getValueInt')->willReturn(time() - 30);
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->with('core', 'cronErrors', '')->willReturn('[{"error":"x"}]');

		$card = $this->probe($app, $config)->probe();
		self::assertSame(HealthCardState::CRITICAL, $card->state);
		self::assertNotSame(HealthCardState::OK, $card->state);
		self::assertNotEmpty($card->actions);
		self::assertSame('admin-jobs', $card->actions[0]['id']);
	}

	public function testAjaxIsDegraded(): void
	{
		$app = $this->createMock(IAppConfig::class);
		$app->method('getValueString')->willReturn('ajax');
		$app->method('getValueInt')->willReturn(time());
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('');

		$card = $this->probe($app, $config)->probe();
		self::assertSame(HealthCardState::DEGRADED, $card->state);
	}

	public function testFreshCronIsOk(): void
	{
		$app = $this->createMock(IAppConfig::class);
		$app->method('getValueString')->willReturn('cron');
		$app->method('getValueInt')->willReturn(time() - 30);
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('');

		$card = $this->probe($app, $config)->probe();
		self::assertSame(HealthCardState::OK, $card->state);
	}
}
