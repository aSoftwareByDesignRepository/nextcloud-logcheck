<?php

declare(strict_types=1);

namespace OCA\LogCheck\Service\Channel;

use OCA\LogCheck\AppInfo\Application;
use OCA\LogCheck\Service\AccessService;
use OCP\Notification\IManager;

final class NotificationChannel
{
	public function __construct(
		private readonly IManager $notificationManager,
		private readonly AccessService $accessService,
	) {
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param list<string> $recipientUids
	 */
	public function send(array $payload, array $recipientUids): void
	{
		$targets = $this->resolveTargets($recipientUids);
		$total = (int)($payload['total_matched'] ?? 0);
		foreach ($targets as $uid) {
			$n = $this->notificationManager->createNotification();
			$n->setApp(Application::APP_ID)
				->setUser($uid)
				->setDateTime(new \DateTime())
				->setObject('digest', (string)($payload['event_id'] ?? 'unknown'))
				->setSubject('alert', ['count' => (string)$total]);
			$this->notificationManager->notify($n);
		}
	}

	/**
	 * AC-8: tell entitled operators a channel auto-disabled after repeated failures.
	 *
	 * @param array<string, mixed> $settings
	 */
	public function notifyChannelDisabled(string $channel, array $settings): void
	{
		$configured = $settings['channels']['notification']['recipient_uids'] ?? [];
		$uids = is_array($configured) ? array_values(array_filter($configured, 'is_string')) : [];
		$targets = $this->resolveTargets($uids);
		foreach ($targets as $uid) {
			$n = $this->notificationManager->createNotification();
			$n->setApp(Application::APP_ID)
				->setUser($uid)
				->setDateTime(new \DateTime())
				->setObject('channel', $channel)
				->setSubject('channel_disabled', ['channel' => $channel]);
			$this->notificationManager->notify($n);
		}
	}

	/**
	 * @param list<string> $recipientUids
	 * @return list<string>
	 */
	private function resolveTargets(array $recipientUids): array
	{
		$entitled = $this->accessService->entitledUids();
		if ($recipientUids === []) {
			return $entitled;
		}
		return array_values(array_intersect($recipientUids, $entitled));
	}
}
