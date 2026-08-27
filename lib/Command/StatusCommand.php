<?php

declare(strict_types=1);

namespace OCA\LogCheck\Command;

use OCA\LogCheck\Service\StatusService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class StatusCommand extends Command
{
	public function __construct(
		private readonly StatusService $statusService,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->setName('logcheck:status')
			->setDescription('Show LogCheck watch status');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$status = $this->statusService->getStatus();
		$output->writeln(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
		return Command::SUCCESS;
	}
}
