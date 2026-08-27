<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Service\Health;

use OCA\LogCheck\Service\Health\HealthCardState;
use OCA\LogCheck\Service\Health\HttpsHealthProbe;
use OCA\LogCheck\Service\SafeHttpClient;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

class HttpsHealthProbeTest extends TestCase
{
	public function testHttpInstanceIsDegradedWithoutFetch(): void
	{
		$url = $this->createMock(IURLGenerator::class);
		$url->method('getBaseUrl')->willReturn('http://localhost:8081');
		$url->method('linkToRouteAbsolute')->willReturn('/settings/admin/security');
		$http = $this->createMock(SafeHttpClient::class);
		$http->expects(self::never())->method('getInstanceStatus');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $m, array $a = []) => $m);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);

		$card = (new HttpsHealthProbe($url, $http, $factory))->probe();
		self::assertSame(HealthCardState::DEGRADED, $card->state);
		self::assertSame('Not using HTTPS', $card->label);
		self::assertNotSame([], $card->actions);
	}

	public function testHttpsCallsInstanceStatusWithHostAllowlist(): void
	{
		$url = $this->createMock(IURLGenerator::class);
		$url->method('getBaseUrl')->willReturn('https://cloud.example');
		$http = $this->createMock(SafeHttpClient::class);
		$http->expects(self::once())
			->method('getInstanceStatus')
			->with('https://cloud.example/status.php', 'cloud.example')
			->willReturn(['status' => 200, 'body' => '{"installed":true}']);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $m, array $a = []) => $m);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);

		$card = (new HttpsHealthProbe($url, $http, $factory))->probe();
		self::assertSame(HealthCardState::OK, $card->state);
	}

	public function testWebrootStatusPathIsPassedThrough(): void
	{
		$url = $this->createMock(IURLGenerator::class);
		$url->method('getBaseUrl')->willReturn('https://cloud.example/nextcloud');
		$http = $this->createMock(SafeHttpClient::class);
		$http->expects(self::once())
			->method('getInstanceStatus')
			->with('https://cloud.example/nextcloud/status.php', 'cloud.example')
			->willReturn(['status' => 200, 'body' => '{"installed":true,"version":"32.0.0"}']);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $m, array $a = []) => $m);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);

		$card = (new HttpsHealthProbe($url, $http, $factory))->probe();
		self::assertSame(HealthCardState::OK, $card->state);
	}

	public function testHealthyStatusResponseRequiresInstalledJson(): void
	{
		self::assertTrue(HttpsHealthProbe::isHealthyStatusResponse(200, '{"installed":true}'));
		self::assertFalse(HttpsHealthProbe::isHealthyStatusResponse(200, ''));
		self::assertFalse(HttpsHealthProbe::isHealthyStatusResponse(200, '<html>{</html>'));
		self::assertFalse(HttpsHealthProbe::isHealthyStatusResponse(404, '{"installed":true}'));
		self::assertFalse(HttpsHealthProbe::isHealthyStatusResponse(200, '{"ok":true}'));
	}
}
