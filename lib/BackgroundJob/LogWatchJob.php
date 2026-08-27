<?php

declare(strict_types=1);

namespace OCA\LogCheck\BackgroundJob;

use OCA\LogCheck\Service\WatchRunner;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

final class LogWatchJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly WatchRunner $watchRunner,
	) {
		parent::__construct($time);
		$this->setInterval(WatchRunner::JOB_INTERVAL);
	}

	protected function run($argument): void
	{
		$this->watchRunner->run();
	}
}
