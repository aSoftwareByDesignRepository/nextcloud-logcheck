<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Service;

use OCA\LogCheck\Service\LogBackendService;
use OCA\LogCheck\Service\TopologyGuard;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class TopologyGuardTest extends TestCase
{
	private function guard(string $logPath = '/var/www/html/data/nextcloud.log', string $datadir = '/var/www/html/data'): TopologyGuard
	{
		$backend = $this->createMock(LogBackendService::class);
		$backend->method('resolveLogPath')->willReturn($logPath);
		$backend->method('getLogType')->willReturn('file');
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(
			static fn(string $key, mixed $default = '') => $key === 'datadirectory' ? $datadir : $default
		);
		return new TopologyGuard($backend, $config);
	}

	public function testNoMismatchWhenUnset(): void
	{
		$g = $this->guard();
		self::assertFalse($g->isMismatch([]));
		self::assertFalse($g->isMismatch(['watcher_node' => null]));
	}

	public function testMismatchWhenDifferentNodeStored(): void
	{
		$g = $this->guard();
		self::assertTrue($g->isMismatch(['watcher_node' => 'not-this-host-' . bin2hex(random_bytes(8))]));
	}

	public function testNoMismatchWhenSameNode(): void
	{
		$g = $this->guard();
		$id = $g->currentNodeId();
		self::assertFalse($g->isMismatch(['watcher_node' => $id]));
	}

	public function testSameHostnameDifferentLogPathIsMismatch(): void
	{
		// The node id keys on the resolved log path + datadir, not gethostname():
		// a cron sidecar reading the SAME datadir log must not false-positive,
		// but a second server with its OWN local log must be caught.
		$a = $this->guard('/data/nextcloud.log');
		$b = $this->guard('/other-host/data/nextcloud.log');
		self::assertTrue($a->isMismatch(['watcher_node' => $b->currentNodeId()]));
	}

	public function testUnresolvedPathStillYieldsStableId(): void
	{
		$backend = $this->createMock(LogBackendService::class);
		$backend->method('resolveLogPath')->willThrowException(new \RuntimeException('no log'));
		$backend->method('getLogType')->willReturn('file');
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturn('/data');
		$g = new TopologyGuard($backend, $config);
		$id = $g->currentNodeId();
		self::assertNotSame('', $id);
		self::assertFalse($g->isMismatch(['watcher_node' => $id]));
	}
}
