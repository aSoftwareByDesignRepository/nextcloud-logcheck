<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Service\Health;

use OCA\LogCheck\Service\Health\HealthCardState;
use OCA\LogCheck\Service\Health\LogHealthProbe;
use PHPUnit\Framework\TestCase;

class LogHealthProbeTest extends TestCase
{
	public function testUnsupportedIsNeverOk(): void
	{
		$card = LogHealthProbe::mapStatus([
			'backend_supported' => false,
			'topology_ok' => true,
			'watch_enabled' => true,
			'stale' => false,
			'label' => 'Can\'t watch',
		]);
		self::assertSame(HealthCardState::CRITICAL, $card->state);
		self::assertNotSame(HealthCardState::OK, $card->state);
	}

	public function testTopologyMismatchIsNeverOk(): void
	{
		$card = LogHealthProbe::mapStatus([
			'backend_supported' => true,
			'topology_ok' => false,
			'watch_enabled' => true,
			'stale' => false,
		]);
		self::assertSame(HealthCardState::CRITICAL, $card->state);
	}

	public function testOffIsDegradedNotOk(): void
	{
		$card = LogHealthProbe::mapStatus([
			'backend_supported' => true,
			'topology_ok' => true,
			'watch_enabled' => false,
			'stale' => false,
		], null, '/apps/logcheck/settings/alerts');
		self::assertSame(HealthCardState::DEGRADED, $card->state);
		self::assertSame('Off', $card->label);
		self::assertNotSame([], $card->actions);
		self::assertSame('/apps/logcheck/settings/alerts', $card->actions[0]['href'] ?? null);
	}

	public function testStaleIsDegraded(): void
	{
		$card = LogHealthProbe::mapStatus([
			'backend_supported' => true,
			'topology_ok' => true,
			'watch_enabled' => true,
			'stale' => true,
		], null, null, '/admin/server');
		self::assertSame(HealthCardState::DEGRADED, $card->state);
		self::assertNotSame([], $card->actions);
		self::assertSame('admin-jobs', $card->actions[0]['id']);
	}

	public function testFailedRunLinksToLogsWhenPermissionError(): void
	{
		$card = LogHealthProbe::mapStatus([
			'backend_supported' => true,
			'topology_ok' => true,
			'watch_enabled' => true,
			'stale' => false,
			'state' => 'degraded',
			'last_check_at' => time(),
			'error' => 'Cannot read the log file. Check permissions.',
		], null, '/alerts', null, '/logs');
		self::assertSame('view-logs', $card->actions[0]['id'] ?? null);
	}

	public function testWatchingIsOk(): void
	{
		$card = LogHealthProbe::mapStatus([
			'backend_supported' => true,
			'topology_ok' => true,
			'watch_enabled' => true,
			'stale' => false,
			'state' => 'watching',
			'last_check_at' => time(),
		]);
		self::assertSame(HealthCardState::OK, $card->state);
		self::assertSame('Watching', $card->label);
	}

	public function testFailedRunIsNeverOk(): void
	{
		$card = LogHealthProbe::mapStatus([
			'backend_supported' => true,
			'topology_ok' => true,
			'watch_enabled' => true,
			'stale' => false,
			'state' => 'degraded',
			'last_check_at' => time(),
			'error' => 'Cannot read the log file. Check permissions.',
		]);
		self::assertSame(HealthCardState::DEGRADED, $card->state);
		self::assertNotSame(HealthCardState::OK, $card->state);
		self::assertSame('Needs attention', $card->label);
		self::assertStringContainsString('permissions', $card->detail);
	}

	public function testWatchingWithoutLastCheckIsNotOk(): void
	{
		$card = LogHealthProbe::mapStatus([
			'backend_supported' => true,
			'topology_ok' => true,
			'watch_enabled' => true,
			'stale' => false,
			'last_check_at' => null,
		]);
		self::assertSame(HealthCardState::DEGRADED, $card->state);
		self::assertNotSame(HealthCardState::OK, $card->state);
		self::assertSame('Not checked yet', $card->label);
	}
}
