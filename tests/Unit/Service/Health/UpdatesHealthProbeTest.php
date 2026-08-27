<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Service\Health;

use OCA\LogCheck\Service\Health\HealthCardState;
use OCA\LogCheck\Service\Health\UpdatesHealthProbe;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

class UpdatesHealthProbeTest extends TestCase
{
	private function probe(IConfig $config, IAppConfig $appConfig): UpdatesHealthProbe
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $m, array $a = []) => $a === [] ? $m : vsprintf(str_replace('%s', '%s', $m), $a));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$url = $this->createMock(\OCP\IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('/settings/admin/overview#version');
		return new UpdatesHealthProbe($config, $appConfig, $factory, $url);
	}

	public function testEmptyCacheIsUnknownNotOk(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturn(true);
		$config->method('getAppValue')->with('core', 'lastupdateResult', '')->willReturn('');
		$app = $this->createMock(IAppConfig::class);
		$app->method('getValueInt')->with('core', 'lastupdatedat', 0)->willReturn(0);

		$card = $this->probe($config, $app)->probe();
		self::assertSame(HealthCardState::UNKNOWN, $card->state);
		self::assertNotSame(HealthCardState::OK, $card->state);
	}

	public function testVersionInCacheIsDegraded(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturn(true);
		$config->method('getAppValue')->willReturn(json_encode([
			'version' => '99.0.0',
			'versionstring' => 'Nextcloud 99.0.0',
		]));
		$app = $this->createMock(IAppConfig::class);
		$app->method('getValueInt')->willReturn(time() - 60);

		$card = $this->probe($config, $app)->probe();
		self::assertSame(HealthCardState::DEGRADED, $card->state);
		self::assertNotSame([], $card->actions);
	}

	public function testEmptyVersionAfterCheckIsOk(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturn(true);
		$config->method('getAppValue')->willReturn(json_encode(['version' => '', 'versionstring' => '']));
		$app = $this->createMock(IAppConfig::class);
		$app->method('getValueInt')->willReturn(time() - 60);

		$card = $this->probe($config, $app)->probe();
		self::assertSame(HealthCardState::OK, $card->state);
	}

	public function testEmptyObjectCacheIsUnknownNotOk(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturn(true);
		$config->method('getAppValue')->willReturn('{}');
		$app = $this->createMock(IAppConfig::class);
		$app->method('getValueInt')->willReturn(time() - 60);

		$card = $this->probe($config, $app)->probe();
		self::assertSame(HealthCardState::UNKNOWN, $card->state);
		self::assertNotSame(HealthCardState::OK, $card->state);
	}

	public function testSourceNeverTriggersUpdaterNetwork(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 4) . '/lib/Service/Health/UpdatesHealthProbe.php');
		self::assertStringNotContainsString('IClientService', $src);
		self::assertStringNotContainsString('updater.server.url', $src);
		self::assertStringNotContainsString('newClient', $src);
		self::assertStringContainsString('lastupdateResult', $src);
	}
}
