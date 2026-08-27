<?php

declare(strict_types=1);

namespace OCA\LogCheck\Command;

use OCA\LogCheck\Service\CursorStore;
use OCA\LogCheck\Service\LogBackendService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ResetCursorCommand extends Command
{
	public function __construct(
		private readonly CursorStore $cursorStore,
		private readonly LogBackendService $logBackendService,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->setName('logcheck:reset-cursor')
			->setDescription('Reset HealthCheck log position to end of file');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		try {
			$path = $this->logBackendService->resolveLogPath();
			$this->cursorStore->initializeAtEof($path);
			$output->writeln('OK');
			return Command::SUCCESS;
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return Command::FAILURE;
		}
	}
}
