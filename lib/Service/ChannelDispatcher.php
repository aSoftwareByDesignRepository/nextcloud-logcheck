<?php

declare(strict_types=1);

namespace OCA\LogCheck\Service;

use OCA\LogCheck\Service\Channel\EmailChannel;
use OCA\LogCheck\Service\Channel\NotificationChannel;
use OCA\LogCheck\Service\Channel\SlackChannel;
use OCA\LogCheck\Service\Channel\WebhookChannel;
use Psr\Log\LoggerInterface;

final class ChannelDispatcher
{
	public function __construct(
		private readonly EmailChannel $emailChannel,
		private readonly SlackChannel $slackChannel,
		private readonly WebhookChannel $webhookChannel,
		private readonly NotificationChannel $notificationChannel,
		private readonly SecretStore $secretStore,
		private readonly ChannelStateStore $channelStateStore,
		private readonly PendingStore $pendingStore,
		private readonly DeliveryStore $deliveryStore,
		private readonly LeaseService $leaseService,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @param array<string, mixed> $settings
	 * @param string|null $leaseOwner When set, renew before each send; abort if lease lost
	 */
	public function dispatchPending(array $settings, ?string $leaseOwner = null): void
	{
		while (($row = $this->pendingStore->claimOne()) !== null) {
			$claimGen = (int)($row['claim_gen'] ?? 0);
			if ($leaseOwner !== null && !$this->leaseService->renew($leaseOwner)) {
				// Return this claimed row to pending; unclaimed remain pending.
				$this->pendingStore->markFailed($row['event_id'], $row['channel'], max(0, $row['attempts']), $claimGen);
				return;
			}
			$channel = $row['channel'];
			if ($this->channelStateStore->isDisabled($channel)) {
				$this->pendingStore->markAbandoned($row['event_id'], $channel, 'channel_disabled');
				$this->deliveryStore->record($row['event_id'], $channel, 'abandoned');
				continue;
			}
			// Zeus SF-D1 / Momos residual: if a prior claim already delivered, never HTTP again.
			if ($this->deliveryStore->hasSent($row['event_id'], $channel)) {
				if (!$this->pendingStore->markSent($row['event_id'], $channel, $claimGen)) {
					$this->logger->warning('HealthCheck markSent lost claim generation (already delivered)', [
						'app' => 'logcheck',
						'channel' => $channel,
						'event_id' => $row['event_id'],
					]);
				}
				continue;
			}
			try {
				$this->send($channel, $row['payload'], $settings);
				// Always record outbound success first so a reclaimer cannot HTTP again
				// even if this claim_gen lost the race for pending markSent (LCK-01).
				$this->deliveryStore->record($row['event_id'], $channel, 'sent');
				if (!$this->pendingStore->markSent($row['event_id'], $channel, $claimGen)) {
					$this->logger->warning('HealthCheck markSent lost claim generation (delivery already recorded)', [
						'app' => 'logcheck',
						'channel' => $channel,
						'event_id' => $row['event_id'],
					]);
				}
				$this->channelStateStore->recordSuccess($channel);
			} catch (\Throwable $e) {
				$attempts = $row['attempts'] + 1;
				$this->pendingStore->markFailed($row['event_id'], $channel, $attempts, $claimGen);
				$this->deliveryStore->record($row['event_id'], $channel, 'failed');
				$justDisabled = $this->channelStateStore->recordFailure($channel, $e->getMessage());
				$this->logger->warning('HealthCheck channel delivery failed', [
					'app' => 'logcheck',
					'channel' => $channel,
					'disabled' => $justDisabled,
					// Never log raw transport messages (may contain webhook URL fragments).
					'error' => ChannelStateStore::safeError($e->getMessage()),
				]);
				if ($justDisabled) {
					$this->notificationChannel->notifyChannelDisabled($channel, $settings);
				}
			}
		}
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param array<string, mixed> $settings
	 */
	public function send(string $channel, array $payload, array $settings): void
	{
		$channels = $settings['channels'] ?? [];
		$allowPrivate = !empty($settings['allow_private_webhooks']);
		match ($channel) {
			'email' => $this->emailChannel->send(
				$payload,
				array_values(array_filter($channels['email']['recipients'] ?? [], 'is_string'))
			),
			'slack' => $this->sendSlack($payload, $channels['slack'] ?? [], $allowPrivate),
			'webhook' => $this->sendWebhook($payload, $channels['webhook'] ?? [], $allowPrivate),
			'notification' => $this->notificationChannel->send(
				$payload,
				array_values(array_filter($channels['notification']['recipient_uids'] ?? [], 'is_string'))
			),
			default => throw new \InvalidArgumentException('Unknown channel'),
		};
	}

	/**
	 * Test send with plaintext URL before secrets are persisted (turn-on / channel test).
	 *
	 * @param array<string, mixed> $payload
	 */
	public function sendPlainUrl(string $channel, array $payload, string $url, bool $allowPrivate): void
	{
		match ($channel) {
			'slack' => $this->slackChannel->send($payload, $url, $allowPrivate),
			'webhook' => $this->webhookChannel->send($payload, $url, $allowPrivate),
			default => throw new \InvalidArgumentException('Unknown channel'),
		};
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param array<string, mixed> $slack
	 */
	private function sendSlack(array $payload, array $slack, bool $allowPrivate): void
	{
		$cipher = $slack['webhook_url_cipher'] ?? null;
		if (!is_string($cipher) || $cipher === '') {
			throw new \RuntimeException('Stored channel secrets cannot be read. Re-enter webhook URLs.');
		}
		$url = $this->secretStore->tryDecrypt($cipher);
		if ($url === null) {
			throw new \RuntimeException('Stored channel secrets cannot be read. Re-enter webhook URLs.');
		}
		$this->slackChannel->send($payload, $url, $allowPrivate);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param array<string, mixed> $webhook
	 */
	private function sendWebhook(array $payload, array $webhook, bool $allowPrivate): void
	{
		$cipher = $webhook['url_cipher'] ?? null;
		if (!is_string($cipher) || $cipher === '') {
			throw new \RuntimeException('Stored channel secrets cannot be read. Re-enter webhook URLs.');
		}
		$url = $this->secretStore->tryDecrypt($cipher);
		if ($url === null) {
			throw new \RuntimeException('Stored channel secrets cannot be read. Re-enter webhook URLs.');
		}
		$this->webhookChannel->send($payload, $url, $allowPrivate);
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return list<string>
	 */
	public function enabledChannels(array $settings): array
	{
		$out = [];
		$channels = $settings['channels'] ?? [];
		foreach (['notification', 'email', 'slack', 'webhook'] as $name) {
			if (!empty($channels[$name]['enabled']) && !$this->channelStateStore->isDisabled($name)) {
				$out[] = $name;
			}
		}
		return $out;
	}
}
