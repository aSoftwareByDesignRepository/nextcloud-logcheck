<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Controller;

use OCA\LogCheck\Controller\ApiController;
use OCA\LogCheck\Service\AccessService;
use OCA\LogCheck\Service\AuditService;
use OCA\LogCheck\Service\ChannelDispatcher;
use OCA\LogCheck\Service\ChannelStateStore;
use OCA\LogCheck\Service\ChannelTestProof;
use OCA\LogCheck\Service\CursorStore;
use OCA\LogCheck\Service\LeaseService;
use OCA\LogCheck\Service\LogBackendService;
use OCA\LogCheck\Service\LogFileService;
use OCA\LogCheck\Service\PayloadBuilder;
use OCA\LogCheck\Service\SettingsService;
use OCA\LogCheck\Service\StatusService;
use OCA\LogCheck\Service\WatchRunner;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ApiControllerLocalizationTest extends TestCase
{
	public function testLocalizedStatusTranslatesLabelAndChannelErrors(): void
	{
		$status = $this->createMock(StatusService::class);
		$status->method('getStatus')->willReturn([
			'state' => 'watching',
			'label' => 'Watching',
			'error' => 'Stored channel secrets cannot be read. Re-enter webhook URLs.',
			'channels' => [
				'email' => [
					'enabled' => true,
					'disabled' => true,
					'last_error' => ChannelStateStore::ERR_MAIL,
				],
			],
			'secrets_readable' => false,
		]);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static function (string $text): string {
			return 'TR:' . $text;
		});

		$ctrl = new ApiController(
			$this->createMock(IRequest::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IUserManager::class),
			$this->createMock(AccessService::class),
			$status,
			$this->createMock(SettingsService::class),
			$this->createMock(ChannelDispatcher::class),
			$this->createMock(ChannelStateStore::class),
			$this->createMock(PayloadBuilder::class),
			$this->createMock(LogBackendService::class),
			$this->createMock(CursorStore::class),
			$this->createMock(LeaseService::class),
			$this->createMock(WatchRunner::class),
			$this->createMock(ICacheFactory::class),
			$this->createMock(ChannelTestProof::class),
			$l10n,
			$this->createMock(LogFileService::class),
			$this->createMock(AuditService::class),
		);

		$method = new ReflectionMethod(ApiController::class, 'localizedStatus');
		$method->setAccessible(true);
		/** @var array<string, mixed> $out */
		$out = $method->invoke($ctrl);

		self::assertSame('TR:Watching', $out['label']);
		self::assertSame('TR:Stored channel secrets cannot be read. Re-enter webhook URLs.', $out['error']);
		self::assertSame('TR:' . ChannelStateStore::ERR_MAIL, $out['channels']['email']['last_error']);
	}
}
