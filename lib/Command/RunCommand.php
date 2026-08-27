<?php

declare(strict_types=1);

namespace OCA\LogCheck\Command;

use OCA\LogCheck\Service\WatchRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RunCommand extends Command
{
	public function __construct(
		private readonly WatchRunner $watchRunner,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->setName('logcheck:run')
			->setDescription('Run one LogCheck watch iteration');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$result = $this->watchRunner->run();
		$output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
		return !empty($result['ok']) ? Command::SUCCESS : Command::FAILURE;
	}
}
