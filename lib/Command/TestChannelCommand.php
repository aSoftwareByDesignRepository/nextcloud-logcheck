<?php

declare(strict_types=1);

namespace OCA\LogCheck\Command;

use OCA\LogCheck\Service\ChannelDispatcher;
use OCA\LogCheck\Service\ChannelStateStore;
use OCA\LogCheck\Service\PayloadBuilder;
use OCA\LogCheck\Service\SettingsService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TestChannelCommand extends Command
{
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly PayloadBuilder $payloadBuilder,
		private readonly ChannelDispatcher $channelDispatcher,
		private readonly ChannelStateStore $channelStateStore,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->setName('logcheck:test-channel')
			->setDescription('Send a test alert on a channel (requires occ/server admin access)')
			->addArgument('channel', InputArgument::REQUIRED, 'email|slack|webhook|notification');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$channel = (string)$input->getArgument('channel');
		$settings = $this->settingsService->getRawSettings();
		$payload = $this->payloadBuilder->buildTestPayload($channel, $settings);
		try {
			$this->channelDispatcher->send($channel, $payload, $settings);
			$this->channelStateStore->recordSuccess($channel);
			$output->writeln('OK');
			return Command::SUCCESS;
		} catch (\Throwable $e) {
			// Same operator-safe strings as the UI — never dump webhook hosts / SMTP internals to the console.
			$output->writeln('<error>' . ChannelStateStore::safeError($e->getMessage()) . '</error>');
			return Command::FAILURE;
		}
	}
}
