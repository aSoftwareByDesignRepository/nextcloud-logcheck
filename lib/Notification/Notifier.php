<?php

declare(strict_types=1);

namespace OCA\LogCheck\Notification;

use OCA\LogCheck\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

class Notifier implements INotifier
{
	public function __construct(
		private readonly IFactory $l10nFactory,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string
	{
		return Application::APP_ID;
	}

	public function getName(): string
	{
		return $this->l10nFactory->get(Application::APP_ID)->t('LogCheck');
	}

	public function prepare(INotification $notification, string $languageCode): INotification
	{
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$params = $notification->getSubjectParameters();
		$subject = $notification->getSubject();

		if ($subject === 'channel_disabled') {
			$channel = (string)($params['channel'] ?? 'channel');
			$label = match ($channel) {
				'email' => $l->t('Email'),
				'slack' => $l->t('Slack'),
				'webhook' => $l->t('Webhook'),
				'notification' => $l->t('Notifications'),
				default => $channel,
			};
			$notification->setParsedSubject(
				$l->t('LogCheck paused %s alerts after repeated failures. Re-enable & test in Alerts.', [$label])
			);
		} elseif ($subject === 'alert') {
			$count = (int)($params['count'] ?? 0);
			$notification->setParsedSubject(
				$l->n('LogCheck found %n new error', 'LogCheck found %n new errors', $count)
			);
		} else {
			throw new UnknownNotificationException();
		}

		$notification->setIcon($this->urlGenerator->imagePath(Application::APP_ID, 'app.svg'));
		$notification->setLink($this->urlGenerator->linkToRouteAbsolute('logcheck.page.settings', ['section' => 'alerts']));
		return $notification;
	}
}
