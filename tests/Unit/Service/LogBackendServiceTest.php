<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Service;

use OCA\LogCheck\Exception\UnsupportedBackendException;
use OCA\LogCheck\Service\LogBackendService;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class LogBackendServiceTest extends TestCase
{
	/** @var IConfig&MockObject */
	private IConfig $config;

	protected function setUp(): void
	{
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
	}

	public function testAbsoluteLogfilePassedThrough(): void
	{
		$this->config->method('getSystemValue')->willReturnCallback(static fn (string $k, $d = '') => match ($k) {
			'log_type' => 'file',
			'logfile' => '/var/log/nextcloud.log',
			'datadirectory' => '/var/www/html/data',
			default => $d,
		});
		$svc = new LogBackendService($this->config);
		self::assertSame('/var/log/nextcloud.log', $svc->resolveLogPath());
	}

	public function testRelativeLogfileAnchoredUnderDataDirectory(): void
	{
		$this->config->method('getSystemValue')->willReturnCallback(static fn (string $k, $d = '') => match ($k) {
			'log_type' => 'file',
			'logfile' => 'nextcloud.log',
			'datadirectory' => '/var/www/html/data',
			default => $d,
		});
		$svc = new LogBackendService($this->config);
		self::assertSame('/var/www/html/data/nextcloud.log', $svc->resolveLogPath());
	}

	public function testRelativeLogfileRejectsMissingDataDirectory(): void
	{
		$this->config->method('getSystemValue')->willReturnCallback(static fn (string $k, $d = '') => match ($k) {
			'log_type' => 'file',
			'logfile' => 'nextcloud.log',
			'datadirectory' => '',
			default => $d,
		});
		$svc = new LogBackendService($this->config);
		$this->expectException(UnsupportedBackendException::class);
		$svc->resolveLogPath();
	}

	public function testDefaultPathUsesDataDirectory(): void
	{
		$this->config->method('getSystemValue')->willReturnCallback(static fn (string $k, $d = '') => match ($k) {
			'log_type' => 'file',
			'logfile' => '',
			'datadirectory' => '/data',
			default => $d,
		});
		$svc = new LogBackendService($this->config);
		self::assertSame('/data/nextcloud.log', $svc->resolveLogPath());
	}
}
