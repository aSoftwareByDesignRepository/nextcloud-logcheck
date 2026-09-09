<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Command;

use OCA\LogCheck\Command\ResetCursorCommand;
use OCA\LogCheck\Command\RunCommand;
use OCA\LogCheck\Command\StatusCommand;
use OCA\LogCheck\Command\TestChannelCommand;
use OCA\LogCheck\Command\UpgradeBackupCommand;
use OCA\LogCheck\Service\ChannelDispatcher;
use OCA\LogCheck\Service\ChannelStateStore;
use OCA\LogCheck\Service\CursorStore;
use OCA\LogCheck\Service\LogBackendService;
use OCA\LogCheck\Service\PayloadBuilder;
use OCA\LogCheck\Service\SettingsService;
use OCA\LogCheck\Service\StatusService;
use OCA\LogCheck\Service\UpgradeBackupService;
use OCA\LogCheck\Service\WatchRunner;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Critic MF cov-commands: invoke each OCC Command::execute (not suite-level / source-scan theater).
 */
final class CommandExecuteInvokeTest extends TestCase
{
	private function invokeExecute(object $command, InputInterface $input, OutputInterface $output): int
	{
		$ref = new ReflectionMethod($command, 'execute');
		$ref->setAccessible(true);
		return (int)$ref->invoke($command, $input, $output);
	}

	public function testStatusCommandExecuteWritesJson(): void
	{
		$status = $this->createMock(StatusService::class);
		$status->expects(self::once())->method('getStatus')->willReturn(['state' => 'watching', 'label' => 'Watching']);
		$input = $this->createMock(InputInterface::class);
		$output = $this->createMock(OutputInterface::class);
		$output->expects(self::once())->method('writeln')->with(self::callback(static function (string $line): bool {
			$decoded = json_decode($line, true);
			return is_array($decoded) && ($decoded['label'] ?? '') === 'Watching';
		}));

		$code = $this->invokeExecute(new StatusCommand($status), $input, $output);
		self::assertSame(Command::SUCCESS, $code);
	}

	public function testRunCommandExecuteInvokesWatchRunner(): void
	{
		$runner = $this->createMock(WatchRunner::class);
		$runner->expects(self::once())->method('run')->willReturn(['ok' => true]);
		$input = $this->createMock(InputInterface::class);
		$output = $this->createMock(OutputInterface::class);
		$output->expects(self::once())->method('writeln');

		$code = $this->invokeExecute(new RunCommand($runner), $input, $output);
		self::assertSame(Command::SUCCESS, $code);
	}

	public function testResetCursorCommandExecuteInitializesEof(): void
	{
		$backend = $this->createMock(LogBackendService::class);
		$backend->expects(self::once())->method('resolveLogPath')->willReturn('/tmp/nextcloud.log');
		$cursor = $this->createMock(CursorStore::class);
		$cursor->expects(self::once())->method('initializeAtEof')->with('/tmp/nextcloud.log');
		$input = $this->createMock(InputInterface::class);
		$output = $this->createMock(OutputInterface::class);
		$output->expects(self::once())->method('writeln')->with('OK');

		$code = $this->invokeExecute(new ResetCursorCommand($cursor, $backend), $input, $output);
		self::assertSame(Command::SUCCESS, $code);
	}

	public function testTestChannelCommandExecuteSendsAndRecordsSuccess(): void
	{
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRawSettings')->willReturn(['channels' => ['notification' => ['enabled' => true]]]);
		$payload = $this->createMock(PayloadBuilder::class);
		$payload->expects(self::once())->method('buildTestPayload')->with('notification', self::anything())->willReturn(['t' => 1]);
		$dispatcher = $this->createMock(ChannelDispatcher::class);
		$dispatcher->expects(self::once())->method('send')->with('notification', ['t' => 1], self::anything());
		$state = $this->createMock(ChannelStateStore::class);
		$state->expects(self::once())->method('recordSuccess')->with('notification');

		$input = $this->createMock(InputInterface::class);
		$input->method('getArgument')->with('channel')->willReturn('notification');
		$output = $this->createMock(OutputInterface::class);
		$output->expects(self::once())->method('writeln')->with('OK');

		$code = $this->invokeExecute(
			new TestChannelCommand($settings, $payload, $dispatcher, $state),
			$input,
			$output,
		);
		self::assertSame(Command::SUCCESS, $code);
	}

	public function testUpgradeBackupCommandExecuteListPath(): void
	{
		$backup = $this->createMock(UpgradeBackupService::class);
		$backup->expects(self::once())->method('listSnapshots')->willReturn([]);

		$input = $this->createMock(InputInterface::class);
		$input->method('getArgument')->with('action')->willReturn('list');
		$input->method('getOption')->willReturn(null);
		$output = $this->createMock(OutputInterface::class);
		// SymfonyStyle writes via output; allow any writeln/write.
		$output->method('writeln');
		$output->method('write');
		$output->method('isDecorated')->willReturn(false);
		$output->method('getVerbosity')->willReturn(OutputInterface::VERBOSITY_NORMAL);

		$code = $this->invokeExecute(new UpgradeBackupCommand($backup), $input, $output);
		self::assertSame(Command::SUCCESS, $code);
	}
}
