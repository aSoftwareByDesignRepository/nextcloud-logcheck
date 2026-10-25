<?php

declare(strict_types=1);

/**
 * Contract proof for the upgrade-backup coordination contract:
 * UpgradeBackupService::restore() holds LOCK_KEY exclusively — every
 * background job that scans/writes app tables must take the SHARED lock,
 * skip on LockedException without doing work, and always release.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\LogCheck\Tests\Unit\BackgroundJob;

use OCA\LogCheck\BackgroundJob\LogWatchJob;
use OCA\LogCheck\Service\UpgradeBackupService;
use OCA\LogCheck\Service\WatchRunner;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class UpgradeBackupSharedLockTest extends TestCase
{
	/** Every app background job — the whole family is wired into the contract. */
	private const JOB_CLASSES = [
		LogWatchJob::class,
	];

	/**
	 * Static contract: every job class (a) declares an ILockingProvider
	 * constructor dependency and (b) its source acquires the shared lock on
	 * UpgradeBackupService::LOCK_KEY and releases it in a finally.
	 */
	public function testEveryJobIsWiredIntoSharedLockContract(): void
	{
		foreach (self::JOB_CLASSES as $class) {
			$ref = new \ReflectionClass($class);
			$ctor = $ref->getConstructor();
			$this->assertNotNull($ctor, $class);
			$hasLockDep = false;
			foreach ($ctor->getParameters() as $p) {
				$t = $p->getType();
				if ($t instanceof \ReflectionNamedType && $t->getName() === ILockingProvider::class) {
					$hasLockDep = true;
				}
			}
			$this->assertTrue($hasLockDep, "$class constructor lacks an ILockingProvider dependency");

			$file = $ref->getFileName();
			$this->assertNotFalse($file, $class);
			$src = (string)file_get_contents($file);
			$this->assertStringContainsString('UpgradeBackupService::LOCK_KEY', $src, $class);
			$this->assertStringContainsString('ILockingProvider::LOCK_SHARED', $src, $class);
			$this->assertStringContainsString('releaseLock(UpgradeBackupService::LOCK_KEY', $src, $class);
			$this->assertStringContainsString('} finally {', $src, $class);
		}

		// Sanity: the contract really does cover every job dir — no silently
		// missed class when a second job lands (jobs live under
		// lib/BackgroundJob here; lib/Cron is globbed too so a future move
		// stays covered).
		$onDisk = [];
		foreach (['Cron', 'BackgroundJob'] as $ns) {
			$dir = dirname(__DIR__, 3) . '/lib/' . $ns;
			foreach (glob($dir . '/*Job.php') ?: [] as $f) {
				$onDisk[] = 'OCA\\LogCheck\\' . $ns . '\\' . basename($f, '.php');
			}
		}
		sort($onDisk);
		$declared = self::JOB_CLASSES;
		sort($declared);
		$this->assertSame($onDisk, $declared, 'JOB_CLASSES must enumerate every *Job.php in lib/Cron + lib/BackgroundJob');
	}

	private function invokeRun(object $job, $argument = null): void
	{
		$m = new \ReflectionMethod($job, 'run');
		$m->setAccessible(true);
		$m->invoke($job, $argument);
	}

	private function lockedProvider(): ILockingProvider
	{
		$locking = $this->createMock(ILockingProvider::class);
		$locking->method('acquireLock')
			->with(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED)
			->willThrowException(new LockedException('restore in progress'));
		return $locking;
	}

	public function testLockedOutJobSkipsWithoutTouchingAppTables(): void
	{
		$runner = $this->createMock(WatchRunner::class);
		$runner->expects($this->never())->method('run');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with($this->stringContains('skipping'), $this->arrayHasKey('app'));

		$job = new LogWatchJob(
			$this->createMock(ITimeFactory::class),
			$runner,
			$logger,
			$this->lockedProvider(),
		);
		$this->invokeRun($job);
	}

	public function testSharedLockIsReleasedWhenWorkThrows(): void
	{
		// acquire+release must bracket the work even when it blows up —
		// a leaked shared lock would starve the exclusive restore.
		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->once())->method('acquireLock')
			->with(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED, $this->isType('string'));
		$locking->expects($this->once())->method('releaseLock')
			->with(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED);

		$runner = $this->createMock(WatchRunner::class);
		$runner->method('run')
			->willThrowException(new \RuntimeException('db gone'));

		$job = new LogWatchJob(
			$this->createMock(ITimeFactory::class),
			$runner,
			$this->createMock(LoggerInterface::class),
			$locking,
		);
		try {
			$this->invokeRun($job);
		} catch (\RuntimeException) {
			// work errors propagate to the Nextcloud job runner — the contract
			// under test is that the shared lock was still released.
		}
	}

	public function testHappyPathAcquiresAndReleasesSharedLock(): void
	{
		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->once())->method('acquireLock')
			->with(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED, $this->isType('string'));
		$locking->expects($this->once())->method('releaseLock')
			->with(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED);

		$runner = $this->createMock(WatchRunner::class);
		$runner->expects($this->once())->method('run')
			->willReturn(['ok' => true]);

		$job = new LogWatchJob(
			$this->createMock(ITimeFactory::class),
			$runner,
			$this->createMock(LoggerInterface::class),
			$locking,
		);
		$this->invokeRun($job);
	}
}
