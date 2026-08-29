<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\LogCheck\Tests\Unit\Notification;

use OCA\LogCheck\Notification\Notifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class NotifierTest extends TestCase
{
	/** @var IFactory&MockObject */
	private IFactory $l10nFactory;
	/** @var IURLGenerator&MockObject */
	private IURLGenerator $urlGenerator;
	private Notifier $notifier;
	/** @var IL10N&MockObject */
	private IL10N $l10n;

	protected function setUp(): void
	{
		parent::setUp();
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(
			static function (string $text, array $args = []): string {
				return $args === [] ? $text : vsprintf(str_replace('%s', '%s', $text), $args);
			}
		);
		$this->l10n->method('n')->willReturnCallback(
			static function (string $singular, string $plural, int $count, array $args = []): string {
				$text = $count === 1 ? $singular : $plural;
				return str_replace('%n', (string)$count, $text);
			}
		);

		$this->l10nFactory = $this->createMock(IFactory::class);
		$this->l10nFactory->method('get')->willReturn($this->l10n);

		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->urlGenerator->method('imagePath')->willReturn('/custom_apps/logcheck/img/app-dark.svg');
		$this->urlGenerator->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => 'https://cloud.example' . $path
		);
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			static function (string $route, array $params = []): string {
				if ($route === 'logcheck.page.logs') {
					return 'https://cloud.example/apps/logcheck/logs';
				}
				if ($route === 'logcheck.page.settings') {
					return 'https://cloud.example/apps/logcheck/settings/' . ($params['section'] ?? '');
				}
				return 'https://cloud.example/' . $route;
			}
		);

		$this->notifier = new Notifier($this->l10nFactory, $this->urlGenerator);
	}

	public function testIdAndName(): void
	{
		self::assertSame('logcheck', $this->notifier->getID());
		self::assertSame('HealthCheck', $this->notifier->getName());
	}

	public function testWrongAppThrowsUnknownNotificationException(): void
	{
		$n = $this->createMock(INotification::class);
		$n->method('getApp')->willReturn('files');
		$this->expectException(UnknownNotificationException::class);
		$this->notifier->prepare($n, 'en');
	}

	public function testUnknownSubjectThrowsUnknownNotificationException(): void
	{
		$n = $this->createMock(INotification::class);
		$n->method('getApp')->willReturn('logcheck');
		$n->method('getSubject')->willReturn('not-a-real-subject');
		$n->method('getSubjectParameters')->willReturn([]);
		$this->expectException(UnknownNotificationException::class);
		$this->notifier->prepare($n, 'en');
	}

	public function testPreparesAlertWithAbsoluteIconAndLogsLink(): void
	{
		$n = $this->createMock(INotification::class);
		$n->method('getApp')->willReturn('logcheck');
		$n->method('getSubject')->willReturn('alert');
		$n->method('getSubjectParameters')->willReturn(['count' => '3']);
		$n->expects(self::once())->method('setParsedSubject')
			->with('HealthCheck found 3 new errors')
			->willReturnSelf();
		$n->expects(self::once())->method('setParsedMessage')
			->with('Open Logs to review the newest errors.')
			->willReturnSelf();
		$n->expects(self::once())->method('setIcon')
			->with('https://cloud.example/custom_apps/logcheck/img/app-dark.svg')
			->willReturnSelf();
		$n->expects(self::once())->method('setLink')
			->with('https://cloud.example/apps/logcheck/logs')
			->willReturnSelf();

		self::assertSame($n, $this->notifier->prepare($n, 'en'));
	}

	public function testPreparesChannelDisabledWithAlertsLink(): void
	{
		$n = $this->createMock(INotification::class);
		$n->method('getApp')->willReturn('logcheck');
		$n->method('getSubject')->willReturn('channel_disabled');
		$n->method('getSubjectParameters')->willReturn(['channel' => 'email']);
		$n->expects(self::once())->method('setParsedSubject')
			->with('HealthCheck paused Email alerts after repeated failures. Re-enable & test in Alerts.')
			->willReturnSelf();
		$n->expects(self::once())->method('setParsedMessage')
			->with('Open Alerts, fix the channel, then use Re-enable & test.')
			->willReturnSelf();
		$n->expects(self::once())->method('setIcon')
			->with('https://cloud.example/custom_apps/logcheck/img/app-dark.svg')
			->willReturnSelf();
		$n->expects(self::once())->method('setLink')
			->with('https://cloud.example/apps/logcheck/settings/alerts')
			->willReturnSelf();

		self::assertSame($n, $this->notifier->prepare($n, 'de'));
	}

	public function testIconUsesAbsoluteUrlNeverRelativeImagePath(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Notification/Notifier.php');
		self::assertStringContainsString('getAbsoluteURL', $src);
		self::assertStringContainsString('imagePath', $src);
		self::assertDoesNotMatchRegularExpression(
			'/setIcon\(\s*\$this->urlGenerator->imagePath\(/',
			$src,
			'NC 34 rejects relative icon URLs with InvalidValueException'
		);
	}
}
