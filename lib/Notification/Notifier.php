<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\LogCheck\Notification;

use OCA\LogCheck\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Renders HealthCheck in-app notifications for the bell / clients.
 *
 * Nextcloud 34+ requires absolute http(s) URLs for icon and link — relative
 * imagePath() values throw InvalidValueException, which the notification
 * manager mis-logs as a deprecated InvalidArgumentException.
 */
final class Notifier implements INotifier
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
		return $this->l10nFactory->get(Application::APP_ID)->t('HealthCheck');
	}

	public function prepare(INotification $notification, string $languageCode): INotification
	{
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException('Notification not from HealthCheck');
		}

		$l = $this->l10nFactory->get(
			Application::APP_ID,
			$languageCode !== '' ? $languageCode : null
		);
		$params = $notification->getSubjectParameters();
		$subject = $notification->getSubject();

		$linkRoute = match ($subject) {
			'channel_disabled' => $this->prepareChannelDisabled($notification, $l, $params),
			'alert' => $this->prepareAlert($notification, $l, $params),
			default => throw new UnknownNotificationException('Unknown HealthCheck notification subject'),
		};

		$notification->setIcon($this->absoluteAppIconUrl());
		$notification->setLink($this->urlGenerator->linkToRouteAbsolute($linkRoute['route'], $linkRoute['params']));

		return $notification;
	}

	/**
	 * @param array<string, mixed> $params
	 * @return array{route: string, params: array<string, string>}
	 */
	private function prepareChannelDisabled(INotification $notification, IL10N $l, array $params): array
	{
		$channel = (string)($params['channel'] ?? 'channel');
		$label = match ($channel) {
			'email' => $l->t('Email'),
			'slack' => $l->t('Slack'),
			'webhook' => $l->t('Webhook'),
			'notification' => $l->t('Notifications'),
			default => $channel,
		};
		$notification->setParsedSubject(
			$l->t('HealthCheck paused %s alerts after repeated failures. Re-enable & test in Alerts.', [$label])
		);
		$notification->setParsedMessage(
			$l->t('Open Alerts, fix the channel, then use Re-enable & test.')
		);

		return [
			'route' => 'logcheck.page.settings',
			'params' => ['section' => 'alerts'],
		];
	}

	/**
	 * @param array<string, mixed> $params
	 * @return array{route: string, params: array<string, string>}
	 */
	private function prepareAlert(INotification $notification, IL10N $l, array $params): array
	{
		$count = (int)($params['count'] ?? 0);
		$notification->setParsedSubject(
			$l->n('HealthCheck found %n new error', 'HealthCheck found %n new errors', $count)
		);
		$notification->setParsedMessage(
			$l->t('Open Logs to review the newest errors.')
		);

		return [
			'route' => 'logcheck.page.logs',
			'params' => [],
		];
	}

	private function absoluteAppIconUrl(): string
	{
		// app-dark.svg reads clearly on the notification panel (light and dark themes).
		return $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg')
		);
	}
}
