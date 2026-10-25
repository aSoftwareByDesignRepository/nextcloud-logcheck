<?php

declare(strict_types=1);

namespace OCA\LogCheck\BackgroundJob;

use OCA\LogCheck\Service\UpgradeBackupService;
use OCA\LogCheck\Service\WatchRunner;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

final class LogWatchJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly WatchRunner $watchRunner,
		private readonly LoggerInterface $logger,
		private readonly ILockingProvider $locking,
	) {
		parent::__construct($time);
		$this->setInterval(WatchRunner::JOB_INTERVAL);
	}

	protected function run($argument): void
	{
		// Shared lock on the upgrade-backup key: a restore holds it
		// exclusively (UpgradeBackupService::runExclusive) — app-table
		// scans/writes must not interleave with a mid-restore
		// truncate+reinsert. A skip is not a failure: TimedJobs re-run
		// on their interval.
		try {
			$this->locking->acquireLock(
				UpgradeBackupService::LOCK_KEY,
				ILockingProvider::LOCK_SHARED,
				'log watch',
			);
		} catch (LockedException) {
			$this->logger->warning(
				'LogCheck: skipping log watch — upgrade backup/restore in progress.',
				['app' => 'logcheck'],
			);
			return;
		}
		try {
			$this->watchRunner->run();
		} finally {
			$this->locking->releaseLock(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED);
		}
	}
}
