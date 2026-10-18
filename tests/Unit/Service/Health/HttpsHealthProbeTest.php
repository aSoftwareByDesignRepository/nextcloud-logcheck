<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Service\Health;

use OCA\LogCheck\Exception\HttpTransportException;
use OCA\LogCheck\Exception\ValidationException;
use OCA\LogCheck\Service\Health\HealthCard;
use OCA\LogCheck\Service\Health\HealthCardState;
use OCA\LogCheck\Service\Health\HttpsHealthProbe;
use OCA\LogCheck\Service\SafeHttpClient;
use OCA\LogCheck\Service\SettingsService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class HttpsHealthProbeTest extends TestCase
{
	private function factory(): IFactory
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static function (string $m, array $a = []) {
			return $a === [] ? $m : vsprintf($m, $a);
		});
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		return $factory;
	}

	private function probe(
		IURLGenerator $url,
		SafeHttpClient $http,
		array $settings = [],
		string $cliUrl = '',
	): HttpsHealthProbe {
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getRawSettings')->willReturn($settings);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(
			static fn (string $key, mixed $default = '') => $key === 'overwrite.cli.url' ? $cliUrl : $default
		);
		return new HttpsHealthProbe($url, $http, $this->factory(), $this->createMock(LoggerInterface::class), $config, $settingsService);
	}

	private function probeFor(\Throwable $throwable): HealthCard
	{
		$url = $this->createMock(IURLGenerator::class);
		$url->method('getBaseUrl')->willReturn('https://cloud.home.local');
		$url->method('linkToRouteAbsolute')->willReturn('/settings/admin/security');
		$http = $this->createMock(SafeHttpClient::class);
		$http->method('getInstanceStatus')->willThrowException($throwable);
		return $this->probe($url, $http)->probe();
	}

	public function testHttpInstanceIsDegradedWithoutFetch(): void
	{
		$url = $this->createMock(IURLGenerator::class);
		$url->method('getBaseUrl')->willReturn('http://localhost:8081');
		$url->method('linkToRouteAbsolute')->willReturn('/settings/admin/security');
		$http = $this->createMock(SafeHttpClient::class);
		$http->expects(self::never())->method('getInstanceStatus');

		$card = $this->probe($url, $http)->probe();
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
			->with('https://cloud.example/status.php', 'cloud.example', null)
			->willReturn(['status' => 200, 'body' => '{"installed":true}']);

		$card = $this->probe($url, $http)->probe();
		self::assertSame(HealthCardState::OK, $card->state);
	}

	public function testWebrootStatusPathIsPassedThrough(): void
	{
		$url = $this->createMock(IURLGenerator::class);
		$url->method('getBaseUrl')->willReturn('https://cloud.example/nextcloud');
		$http = $this->createMock(SafeHttpClient::class);
		$http->expects(self::once())
			->method('getInstanceStatus')
			->with('https://cloud.example/nextcloud/status.php', 'cloud.example', null)
			->willReturn(['status' => 200, 'body' => '{"installed":true,"version":"32.0.0"}']);

		$card = $this->probe($url, $http)->probe();
		self::assertSame(HealthCardState::OK, $card->state);
	}

	public function testDnsFailureIsNamedAsResolutionFailure(): void
	{
		$card = $this->probeFor(new HttpTransportException('HTTPS request failed (HTTP 0).', 6));
		self::assertSame(HealthCardState::CRITICAL, $card->state);
		self::assertSame('Unreachable', $card->label);
		self::assertSame('The instance hostname could not be resolved on this server. Checked https://cloud.home.local.', $card->detail);
	}

	public function testTlsFailurePointsAtPrivateCaTrust(): void
	{
		$card = $this->probeFor(new HttpTransportException('HTTPS request failed (HTTP 0).', 60));
		self::assertSame(HealthCardState::CRITICAL, $card->state);
		self::assertSame('Unreachable', $card->label);
		self::assertStringContainsString('certificate', $card->detail);
		self::assertStringContainsString('Security', $card->detail);
	}

	public function testConnectRefusedIsNamedAsNoAnswer(): void
	{
		$card = $this->probeFor(new HttpTransportException('HTTPS request failed (HTTP 0).', 7));
		self::assertSame(HealthCardState::CRITICAL, $card->state);
		self::assertSame('The instance did not answer over HTTPS. Checked https://cloud.home.local.', $card->detail);
	}

	public function testTimeoutIsNamedAsTimedOut(): void
	{
		$card = $this->probeFor(new HttpTransportException('HTTPS request failed (HTTP 0).', 28));
		self::assertSame(HealthCardState::CRITICAL, $card->state);
		self::assertSame('The instance did not answer in time over HTTPS. Checked https://cloud.home.local.', $card->detail);
	}

	public function testSsrfGuardRejectionIsNamedAsResolutionFailure(): void
	{
		// pin() throws LCK_HOST_UNRESOLVED when the host resolves to nothing usable.
		$card = $this->probeFor(new ValidationException('Webhook URL is not allowed.', [], 'LCK_HOST_UNRESOLVED'));
		self::assertSame(HealthCardState::CRITICAL, $card->state);
		self::assertSame('The instance hostname could not be resolved on this server. Checked https://cloud.home.local.', $card->detail);
	}

	public function testSsrfGuardAddressRejectionKeepsGenericDetail(): void
	{
		// Other pin() rejections (e.g. link-local/metadata address) must not claim DNS.
		$card = $this->probeFor(new ValidationException('Webhook URL is not allowed.', [], 'LCK_INVALID_URL'));
		self::assertSame(HealthCardState::CRITICAL, $card->state);
		self::assertSame('Could not reach the instance over HTTPS. Checked https://cloud.home.local.', $card->detail);
	}

	public function testGenericTransportFailureKeepsGenericDetail(): void
	{
		$card = $this->probeFor(new \RuntimeException('HTTPS request failed (HTTP 0).'));
		self::assertSame(HealthCardState::CRITICAL, $card->state);
		self::assertSame('Could not reach the instance over HTTPS. Checked https://cloud.home.local.', $card->detail);
	}

	public function testSettingsOverrideUrlWinsOverDerivedAndCliUrl(): void
	{
		$url = $this->createMock(IURLGenerator::class);
		$url->method('getBaseUrl')->willReturn('https://derived.example');
		$http = $this->createMock(SafeHttpClient::class);
		$http->expects(self::once())
			->method('getInstanceStatus')
			->with('https://internal.example/status.php', 'internal.example', null)
			->willReturn(['status' => 200, 'body' => '{"installed":true}']);

		$card = $this->probe($url, $http, ['health_https_probe_url' => 'https://internal.example'], 'https://cli.example')->probe();
		self::assertSame(HealthCardState::OK, $card->state);
		self::assertStringContainsString('https://internal.example', $card->detail);
	}

	public function testHttpsCliUrlIsUsedWhenNoOverride(): void
	{
		$url = $this->createMock(IURLGenerator::class);
		$url->method('getBaseUrl')->willReturn('https://derived.example');
		$http = $this->createMock(SafeHttpClient::class);
		$http->expects(self::once())
			->method('getInstanceStatus')
			->with('https://cli.example/status.php', 'cli.example', null)
			->willReturn(['status' => 200, 'body' => '{"installed":true}']);

		$card = $this->probe($url, $http, [], 'https://cli.example')->probe();
		self::assertSame(HealthCardState::OK, $card->state);
	}

	/**
	 * TLS-terminated-proxy convention: an HTTP overwrite.cli.url is the local
	 * dial path for cron/CLI, not an HTTPS target. It must not downgrade the
	 * card — the request-derived base keeps deciding.
	 */
	public function testHttpCliUrlIsIgnoredInFavorOfDerivedBase(): void
	{
		$url = $this->createMock(IURLGenerator::class);
		$url->method('getBaseUrl')->willReturn('https://proxy.example');
		$http = $this->createMock(SafeHttpClient::class);
		$http->expects(self::once())
			->method('getInstanceStatus')
			->with('https://proxy.example/status.php', 'proxy.example', null)
			->willReturn(['status' => 200, 'body' => '{"installed":true}']);

		$card = $this->probe($url, $http, [], 'http://localhost:8081')->probe();
		self::assertSame(HealthCardState::OK, $card->state);
	}

	public function testPinnedConnectIpIsForwardedToClient(): void
	{
		$url = $this->createMock(IURLGenerator::class);
		$url->method('getBaseUrl')->willReturn('https://cloud.example');
		$http = $this->createMock(SafeHttpClient::class);
		$http->expects(self::once())
			->method('getInstanceStatus')
			->with('https://cloud.example/status.php', 'cloud.example', '203.0.113.10')
			->willReturn(['status' => 200, 'body' => '{"installed":true}']);

		$card = $this->probe($url, $http, ['health_https_probe_ip' => '203.0.113.10'])->probe();
		self::assertSame(HealthCardState::OK, $card->state);
		self::assertStringContainsString('203.0.113.10', $card->detail);
	}

	/** Hand-edited/corrupt settings must degrade to name resolution, never raw dial. */
	public function testInvalidStoredPinIsIgnored(): void
	{
		$url = $this->createMock(IURLGenerator::class);
		$url->method('getBaseUrl')->willReturn('https://cloud.example');
		$http = $this->createMock(SafeHttpClient::class);
		$http->expects(self::once())
			->method('getInstanceStatus')
			->with('https://cloud.example/status.php', 'cloud.example', null)
			->willReturn(['status' => 200, 'body' => '{"installed":true}']);

		$this->probe($url, $http, ['health_https_probe_ip' => 'not-an-ip'])->probe();
	}

	public function testCardDetailNamesTheCheckedTargetOnFailure(): void
	{
		$url = $this->createMock(IURLGenerator::class);
		$url->method('getBaseUrl')->willReturn('https://cloud.example');
		$url->method('linkToRouteAbsolute')->willReturn('/settings/admin/security');
		$http = $this->createMock(SafeHttpClient::class);
		$http->method('getInstanceStatus')->willThrowException(new HttpTransportException('HTTPS request failed (HTTP 0).', 7));

		$card = $this->probe($url, $http)->probe();
		self::assertSame(HealthCardState::CRITICAL, $card->state);
		self::assertStringContainsString('Checked https://cloud.example', $card->detail);
	}

	public function testUnreadableSettingsFallBackToDerivedBase(): void
	{
		$url = $this->createMock(IURLGenerator::class);
		$url->method('getBaseUrl')->willReturn('https://cloud.example');
		$http = $this->createMock(SafeHttpClient::class);
		$http->expects(self::once())
			->method('getInstanceStatus')
			->with('https://cloud.example/status.php', 'cloud.example', null)
			->willReturn(['status' => 200, 'body' => '{"installed":true}']);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getRawSettings')->willThrowException(new \RuntimeException('db down'));
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturn('');
		$probe = new HttpsHealthProbe($url, $http, $this->factory(), $this->createMock(LoggerInterface::class), $config, $settingsService);

		self::assertSame(HealthCardState::OK, $probe->probe()->state);
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
