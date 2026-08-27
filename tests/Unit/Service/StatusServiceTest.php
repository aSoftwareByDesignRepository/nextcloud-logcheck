<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Service;

use OCA\LogCheck\Service\ChannelStateStore;
use OCA\LogCheck\Service\LogBackendService;
use OCA\LogCheck\Service\SettingsService;
use OCA\LogCheck\Service\StatusService;
use OCA\LogCheck\Service\TopologyGuard;
use OCA\LogCheck\Service\WatchRunner;
use PHPUnit\Framework\TestCase;

class StatusServiceTest extends TestCase
{
	/**
	 * @param array<string, mixed> $runtime
	 * @param array<string, mixed> $extraSettings
	 */
	private function mockSettings(array $runtime, array $extraSettings = []): SettingsService
	{
		$base = array_merge([
			'watch_enabled' => true,
			'coalesce_seconds' => 300,
			'channels' => [
				'notification' => ['enabled' => true],
				'email' => ['enabled' => false],
				'slack' => ['enabled' => false],
				'webhook' => ['enabled' => false],
			],
			'secrets_readable' => true,
		], $extraSettings);
		// UI DTO never includes watcher_node (redacted in toUiDto).
		$uiRuntime = $runtime;
		unset($uiRuntime['watcher_node']);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('toUiDto')->willReturn([
			'version' => 1,
			'settings' => array_merge($base, ['runtime' => $uiRuntime]),
		]);
		$settings->method('getRawSettings')->willReturn(array_merge($base, ['runtime' => $runtime]));
		return $settings;
	}

	private function fileBackend(): LogBackendService
	{
		$backend = $this->createMock(LogBackendService::class);
		$backend->method('getLogType')->willReturn('file');
		$backend->method('isFileBackend')->willReturn(true);
		return $backend;
	}

	private function emptyChannelState(): ChannelStateStore
	{
		$channelState = $this->createMock(ChannelStateStore::class);
		$channelState->method('get')->willReturn(null);
		return $channelState;
	}

	public function testStaleLabelIsNeedsACheckNotWatching(): void
	{
		$settings = $this->mockSettings([
			'last_run_at' => time() - (3 * WatchRunner::JOB_INTERVAL),
			'last_error' => null,
		]);
		$status = (new StatusService($settings, $this->fileBackend(), $this->emptyChannelState(), new TopologyGuard()))->getStatus();
		self::assertTrue($status['stale']);
		self::assertSame('stale', $status['state']);
		self::assertSame('Needs a check', $status['label']);
		self::assertNotSame('Watching', $status['label']);
	}

	public function testFreshWatchLabelIsWatching(): void
	{
		$settings = $this->mockSettings([
			'last_run_at' => time() - 60,
			'last_run_ok' => true,
			'last_error' => null,
		]);
		$status = (new StatusService($settings, $this->fileBackend(), $this->emptyChannelState(), new TopologyGuard()))->getStatus();
		self::assertFalse($status['stale']);
		self::assertSame('watching', $status['state']);
		self::assertSame('Watching', $status['label']);
		self::assertNull($status['error']);
	}

	public function testFailedRunIsNeverWatching(): void
	{
		$settings = $this->mockSettings([
			'last_run_at' => time() - 30,
			'last_run_ok' => false,
			'last_error' => 'Cannot read the log file. Check permissions.',
		]);
		$status = (new StatusService($settings, $this->fileBackend(), $this->emptyChannelState(), new TopologyGuard()))->getStatus();
		self::assertSame('degraded', $status['state']);
		self::assertSame('Needs attention', $status['label']);
		self::assertNotSame('Watching', $status['label']);
		self::assertNotNull($status['error']);
		self::assertStringContainsString('permissions', (string)$status['error']);
	}

	public function testUnreadableSecretsIsNeverWatching(): void
	{
		$settings = $this->mockSettings([
			'last_run_at' => time() - 30,
			'last_run_ok' => true,
			'last_error' => null,
		], ['secrets_readable' => false]);
		$status = (new StatusService($settings, $this->fileBackend(), $this->emptyChannelState(), new TopologyGuard()))->getStatus();
		self::assertSame('degraded', $status['state']);
		self::assertSame('Needs attention', $status['label']);
		self::assertStringContainsString('secrets', (string)$status['error']);
	}

	public function testTopologyMismatchShowsCantWatch(): void
	{
		$guard = $this->createMock(TopologyGuard::class);
		$guard->method('isMismatch')->willReturn(true);

		$settings = $this->mockSettings([
			'last_run_at' => time() - 60,
			'watcher_node' => 'other-node',
		]);
		$status = (new StatusService($settings, $this->fileBackend(), $this->emptyChannelState(), $guard))->getStatus();
		self::assertFalse($status['topology_ok']);
		self::assertSame('Can\'t watch', $status['label']);
		self::assertStringContainsString('different server', (string)$status['error']);
	}
}
