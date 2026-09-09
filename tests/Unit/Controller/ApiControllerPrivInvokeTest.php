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
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\IMemcache;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Critic MF: private ApiController helpers must be invoked via shipping entrypoints
 * (not OpenAPI/schema theater). classifyOutboundFailure via Throwable catch only.
 */
class ApiControllerPrivInvokeTest extends TestCase
{
	/** @var IRequest&MockObject */
	private IRequest $request;
	/** @var StatusService&MockObject */
	private StatusService $status;
	/** @var SettingsService&MockObject */
	private SettingsService $settings;
	/** @var ChannelDispatcher&MockObject */
	private ChannelDispatcher $dispatcher;
	/** @var ChannelStateStore&MockObject */
	private ChannelStateStore $channelState;
	/** @var PayloadBuilder&MockObject */
	private PayloadBuilder $payloadBuilder;
	/** @var LogBackendService&MockObject */
	private LogBackendService $logBackend;
	/** @var CursorStore&MockObject */
	private CursorStore $cursorStore;
	/** @var LeaseService&MockObject */
	private LeaseService $lease;
	/** @var WatchRunner&MockObject */
	private WatchRunner $watchRunner;
	/** @var ChannelTestProof&MockObject */
	private ChannelTestProof $testProof;
	/** @var LogFileService&MockObject */
	private LogFileService $logFiles;
	/** @var AuditService&MockObject */
	private AuditService $audit;
	/** @var AccessService&MockObject */
	private AccessService $access;
	/** @var IUserManager&MockObject */
	private IUserManager $users;
	/** @var IMemcache&MockObject */
	private IMemcache $cache;
	/** @var array<string, string> */
	private array $cacheStore = [];

	protected function setUp(): void
	{
		parent::setUp();
		$this->status = $this->createMock(StatusService::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->dispatcher = $this->createMock(ChannelDispatcher::class);
		$this->channelState = $this->createMock(ChannelStateStore::class);
		$this->payloadBuilder = $this->createMock(PayloadBuilder::class);
		$this->logBackend = $this->createMock(LogBackendService::class);
		$this->cursorStore = $this->createMock(CursorStore::class);
		$this->lease = $this->createMock(LeaseService::class);
		$this->watchRunner = $this->createMock(WatchRunner::class);
		$this->testProof = $this->createMock(ChannelTestProof::class);
		$this->logFiles = $this->createMock(LogFileService::class);
		$this->audit = $this->createMock(AuditService::class);
		$this->access = $this->createMock(AccessService::class);
		$this->users = $this->createMock(IUserManager::class);
		$this->cacheStore = [];
		$this->cache = $this->createMock(IMemcache::class);
		$this->cache->method('add')->willReturnCallback(function (string $key, $value, $ttl = 0) {
			unset($ttl, $value);
			if (array_key_exists($key, $this->cacheStore)) {
				return false;
			}
			$this->cacheStore[$key] = '1';
			return true;
		});
	}

	private function sessionUser(string $uid = 'admin'): IUserSession
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return $session;
	}

	/**
	 * @param array<string, mixed>|null $jsonBody
	 */
	private function controller(?array $jsonBody = null): ApiController
	{
		$this->request = $this->createMock(IRequest::class);
		$body = $jsonBody ?? [];
		$this->request->method('getHeader')->willReturnCallback(static function (string $name) use ($jsonBody) {
			if ($jsonBody !== null && strcasecmp($name, 'Content-Type') === 0) {
				return 'application/json';
			}
			return '';
		});
		$this->request->method('getParams')->willReturn($body);

		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($this->cache);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $t): string => 'L10N:' . $t);

		$this->status->method('getStatus')->willReturn([
			'state' => 'watching',
			'label' => 'Watching',
			'error' => null,
			'channels' => [],
		]);

		return new ApiController(
			$this->request,
			$this->sessionUser(),
			$this->users,
			$this->access,
			$this->status,
			$this->settings,
			$this->dispatcher,
			$this->channelState,
			$this->payloadBuilder,
			$this->logBackend,
			$this->cursorStore,
			$this->lease,
			$this->watchRunner,
			$factory,
			$this->testProof,
			$l10n,
			$this->logFiles,
			$this->audit,
		);
	}

	/** localizedStatus via getStatus public entrypoint */
	public function testLocalizedStatusViaGetStatus(): void
	{
		$res = $this->controller()->getStatus();
		self::assertInstanceOf(JSONResponse::class, $res);
		self::assertSame('L10N:Watching', $res->getData()['label']);
	}

	/** tSafe via unknown-channel message on testChannel */
	public function testTSafeViaUnknownChannelMessage(): void
	{
		$res = $this->controller([])->testChannel('not-a-channel');
		self::assertSame(Http::STATUS_BAD_REQUEST, $res->getStatus());
		self::assertSame('LCK_VALIDATION', $res->getData()['error']);
		self::assertSame('L10N:Unknown channel', $res->getData()['message']);
	}

	/** initializeCursorAtEofUnderLease via turnOnAlerts first-enable path */
	public function testInitializeCursorAtEofUnderLeaseViaTurnOn(): void
	{
		$this->logBackend->expects(self::once())->method('assertFileBackend');
		$this->settings->method('getRawSettings')->willReturn([
			'watch_enabled' => false,
			'allow_private_webhooks' => false,
			'channels' => [
				'email' => ['enabled' => false, 'recipients' => []],
				'notification' => ['enabled' => false, 'recipient_uids' => []],
			],
		]);
		$this->payloadBuilder->method('buildTestPayload')->willReturn(['ok' => true]);
		$this->dispatcher->expects(self::once())->method('send');
		$this->settings->expects(self::once())->method('save')->willReturn(['version' => 2]);
		$this->access->method('isNcAdmin')->willReturn(true);
		$this->lease->expects(self::once())->method('acquire')->willReturn(true);
		$this->logBackend->method('resolveLogPath')->willReturn('/tmp/nextcloud.log');
		$this->cursorStore->expects(self::once())->method('initializeAtEof')->with('/tmp/nextcloud.log');
		$this->lease->expects(self::once())->method('release');
		$this->channelState->method('recordSuccess');

		$res = $this->controller([
			'expected_version' => 1,
			'email' => 'ops@example.com',
		])->turnOnAlerts();

		self::assertTrue($res->getData()['ok']);
	}

	/** consumeTestRate via second testChannel (public) */
	public function testConsumeTestRateViaSecondTestChannel(): void
	{
		$this->settings->method('getRawSettings')->willReturn([
			'channels' => ['notification' => ['enabled' => true, 'recipient_uids' => ['admin']]],
		]);
		$this->payloadBuilder->method('buildTestPayload')->willReturn(['t' => 1]);
		$this->dispatcher->method('send');
		$this->channelState->method('recordSuccess');
		$this->audit->method('log');

		$first = $this->controller([])->testChannel('notification');
		self::assertTrue($first->getData()['ok']);

		// Same cache store — second call must hit consumeTestRate → LCK_RATE_LIMIT
		$second = $this->controller([])->testChannel('notification');
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $second->getStatus());
		self::assertSame('LCK_RATE_LIMIT', $second->getData()['error']);
	}

	/** consumeRate via second runNow (public) */
	public function testConsumeRateViaSecondRunNow(): void
	{
		$this->watchRunner->method('run')->willReturn(['ok' => true]);
		$this->audit->method('log');

		$first = $this->controller()->runNow();
		self::assertTrue($first->getData()['ok']);

		$second = $this->controller()->runNow();
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $second->getStatus());
		self::assertSame('LCK_RATE_LIMIT', $second->getData()['error']);
	}

	/** assertOutboundUrlLength via testChannel ephemeral slack URL */
	public function testAssertOutboundUrlLengthViaLongSlackUrl(): void
	{
		$this->settings->method('getRawSettings')->willReturn([
			'allow_private_webhooks' => false,
			'channels' => ['slack' => ['enabled' => true]],
		]);
		$this->payloadBuilder->method('buildTestPayload')->willReturn(['t' => 1]);
		$this->dispatcher->expects(self::never())->method('sendPlainUrl');

		$long = 'https://hooks.example.com/' . str_repeat('x', 2100);
		$res = $this->controller(['url' => $long])->testChannel('slack');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $res->getStatus());
		self::assertSame('LCK_INVALID_URL', $res->getData()['error']);
	}

	/**
	 * classifyOutboundFailure + tSafe outbound branch: Throwable catch on testChannel
	 * (not ValidationException / AuthZ paths).
	 */
	public function testClassifyOutboundFailureHttpViaTestChannelThrowable(): void
	{
		$this->settings->method('getRawSettings')->willReturn([
			'channels' => ['notification' => ['enabled' => true, 'recipient_uids' => ['admin']]],
		]);
		$this->payloadBuilder->method('buildTestPayload')->willReturn(['t' => 1]);
		$this->dispatcher->expects(self::once())->method('send')
			->willThrowException(new \RuntimeException('curl http ssl handshake failed'));
		$this->channelState->expects(self::once())->method('recordFailure')
			->with('notification', 'curl http ssl handshake failed');

		$res = $this->controller([])->testChannel('notification');

		self::assertSame(Http::STATUS_BAD_REQUEST, $res->getStatus());
		self::assertSame('LCK_HTTP_FAILED', $res->getData()['error']);
		self::assertSame(
			'L10N:Webhook failed. Check the URL and try again.',
			$res->getData()['message']
		);
	}

	public function testClassifyOutboundFailureMailViaTestChannelThrowable(): void
	{
		$this->settings->method('getRawSettings')->willReturn([
			'channels' => ['email' => ['enabled' => true, 'recipients' => ['a@b.c']]],
		]);
		$this->payloadBuilder->method('buildTestPayload')->willReturn(['t' => 1]);
		$this->dispatcher->expects(self::once())->method('send')
			->willThrowException(new \RuntimeException('SMTP relay refused'));
		$this->channelState->expects(self::once())->method('recordFailure');

		$res = $this->controller([])->testChannel('email');

		self::assertSame(Http::STATUS_BAD_REQUEST, $res->getStatus());
		self::assertSame('LCK_MAIL_FAILED', $res->getData()['error']);
		self::assertSame(
			'L10N:Email could not be sent. Check mail settings.',
			$res->getData()['message']
		);
	}

	/** requestBody via testChannel ephemeral recipients (reads JSON/params body) */
	public function testRequestBodyViaEphemeralEmailRecipients(): void
	{
		$this->settings->method('getRawSettings')->willReturn([
			'channels' => ['email' => ['enabled' => true, 'recipients' => []]],
		]);
		$this->payloadBuilder->method('buildTestPayload')->willReturn(['t' => 1]);
		$this->dispatcher->expects(self::once())->method('send');
		$this->channelState->expects(self::once())->method('recordSuccess')->with('email');
		$this->testProof->expects(self::once())->method('markUrl');
		$this->audit->expects(self::once())->method('log');

		$res = $this->controller([
			'recipients' => ['ops@example.com'],
		])->testChannel('email');

		self::assertTrue($res->getData()['ok']);
		self::assertTrue($res->getData()['recipients_tested']);
	}
}
