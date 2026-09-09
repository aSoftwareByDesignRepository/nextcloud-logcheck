<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\BackgroundJob;

use OCA\LogCheck\BackgroundJob\LogWatchJob;
use OCA\LogCheck\Service\WatchRunner;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Critic MF: invoke LogWatchJob::run (not WatchRunnerTest alone).
 */
final class LogWatchJobInvokeTest extends TestCase
{
	public function testRunDelegatesToWatchRunner(): void
	{
		$time = $this->createMock(ITimeFactory::class);
		$runner = $this->createMock(WatchRunner::class);
		$runner->expects(self::once())->method('run')->willReturn(['ok' => true]);

		$job = new LogWatchJob($time, $runner);
		$ref = new ReflectionMethod(LogWatchJob::class, 'run');
		$ref->setAccessible(true);
		$ref->invoke($job, null);
	}
}
