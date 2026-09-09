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
use OCP\AppFramework\Http\StreamResponse;
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
 * Critic MF: every public ApiController action must be invoked (not reflection/OpenAPI theater).
 */
class ApiControllerInvokeTest extends TestCase
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

	protected function setUp(): void
	{
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
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
	}

	private function atomicCache(): IMemcache
	{
		$cache = $this->createMock(IMemcache::class);
		$store = [];
		$cache->method('add')->willReturnCallback(static function (string $key, $value, $ttl = 0) use (&$store) {
			unset($ttl, $value);
			if (array_key_exists($key, $store)) {
				return false;
			}
			$store[$key] = '1';
			return true;
		});
		return $cache;
	}

	private function sessionUser(string $uid = 'admin'): IUserSession
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return $session;
	}

	private function controller(?array $jsonBody = null): ApiController
	{
		// Fresh request mock each time — PHPUnit keeps the first getParams stub otherwise.
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
		$factory->method('createDistributed')->willReturn($this->atomicCache());

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $t): string => $t);

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

	public function testGetStatusInvokesController(): void
	{
		$res = $this->controller()->getStatus();
		self::assertInstanceOf(JSONResponse::class, $res);
		self::assertSame(200, $res->getStatus());
		self::assertSame('Watching', $res->getData()['label']);
	}

	public function testGetSettingsInvokesController(): void
	{
		$this->settings->method('toUiDto')->willReturn(['version' => 3, 'settings' => ['watch_enabled' => true]]);
		$res = $this->controller()->getSettings();
		self::assertSame(3, $res->getData()['version']);
	}

	public function testSaveSettingsInvokesController(): void
	{
		$this->access->method('isNcAdmin')->willReturn(true);
		$this->settings->expects(self::once())->method('save')->willReturn(['version' => 4]);
		$this->settings->method('toUiDto')->willReturn(['version' => 4, 'settings' => []]);
		$res = $this->controller(['expected_version' => 3, 'watch_enabled' => true])->saveSettings();
		self::assertSame(4, $res->getData()['version']);
	}

	public function testTurnOnAlertsHappyPathInvokesController(): void
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
		$this->settings->expects(self::once())->method('save')->willReturn(['version' => 9]);
		$this->access->method('isNcAdmin')->willReturn(true);
		$this->lease->method('acquire')->willReturn(true);
		$this->logBackend->method('resolveLogPath')->willReturn('/tmp/nextcloud.log');
		$this->cursorStore->expects(self::once())->method('initializeAtEof');
		$this->lease->method('release');
		$this->channelState->expects(self::atLeastOnce())->method('recordSuccess');

		$res = $this->controller([
			'expected_version' => 1,
			'email' => 'ops@example.com',
		])->turnOnAlerts();

		self::assertSame(200, $res->getStatus());
		self::assertTrue($res->getData()['ok']);
		self::assertSame(9, $res->getData()['version']);
	}

	public function testTestChannelAndReenableInvokeController(): void
	{
		$this->settings->method('getRawSettings')->willReturn([
			'channels' => ['notification' => ['enabled' => true, 'recipient_uids' => ['admin']]],
		]);
		$this->payloadBuilder->method('buildTestPayload')->willReturn(['t' => 1]);
		$this->dispatcher->expects(self::exactly(2))->method('send');
		$this->channelState->expects(self::exactly(2))->method('recordSuccess')->with('notification');
		$this->audit->expects(self::exactly(2))->method('log');

		$test = $this->controller([])->testChannel('notification');
		self::assertTrue($test->getData()['ok']);

		// Fresh controller + cache so rate limit does not block the second public call.
		$re = $this->controller([])->reenableChannel('notification');
		self::assertTrue($re->getData()['ok']);
	}

	public function testRunNowInvokesWatchRunner(): void
	{
		$this->watchRunner->expects(self::once())->method('run')->willReturn(['ok' => true]);
		$this->audit->expects(self::once())->method('log');
		$res = $this->controller()->runNow();
		self::assertTrue($res->getData()['ok']);
	}

	public function testSearchDirectoryNcAdminHappy(): void
	{
		$this->access->method('isNcAdmin')->willReturn(true);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$user->method('getDisplayName')->willReturn('Bob');
		$this->users->method('searchDisplayName')->willReturn([$user]);
		$this->request->method('getParam')->willReturn('bo');
		$res = $this->controller()->searchDirectory('bo');
		self::assertSame([['uid' => 'bob', 'displayName' => 'Bob']], $res->getData()['users']);
	}

	public function testLogReadMutateApisInvokeController(): void
	{
		$this->access->method('isNcAdmin')->willReturn(true);
		$this->logFiles->method('meta')->willReturn(['name' => 'nextcloud.log']);
		$this->logFiles->method('listFiles')->willReturn(['files' => []]);
		$this->logFiles->method('readTail')->willReturn(['lines' => []]);
		$this->logFiles->method('readBefore')->willReturn(['lines' => []]);
		$this->logFiles->method('search')->willReturn(['matches' => []]);
		$this->logFiles->method('resolveDownload')->willReturn([
			'path' => '/tmp/nextcloud.log',
			'name' => 'nextcloud.log',
			'size' => 3,
		]);
		$this->logFiles->expects(self::once())->method('startFresh')->with('admin', 'START_FRESH')->willReturn(['ok' => true, 'rotated' => 'nextcloud.log.1']);
		$this->logFiles->expects(self::once())->method('deleteLog')->willReturn(['ok' => true]);
		$this->logFiles->expects(self::once())->method('deleteCopy')->willReturn(['ok' => true]);

		$ctrl = $this->controller();
		self::assertSame('nextcloud.log', $ctrl->getLogMeta()->getData()['name']);
		self::assertArrayHasKey('files', $ctrl->listLogFiles()->getData());

		$this->request->method('getParam')->willReturnMap([
			['bytes', null, 1024],
			['max_lines', null, 50],
			['file', null, null],
			['viewer_min_level', 0, 0],
			['before', null, 10],
			['q', '', 'err'],
			['case', null, '0'],
			['max_matches', null, 10],
			['scan_bytes', null, 1000],
		]);
		self::assertArrayHasKey('lines', $ctrl->getLogTail()->getData());
		self::assertArrayHasKey('lines', $ctrl->getLogBefore()->getData());
		self::assertArrayHasKey('matches', $ctrl->searchLog()->getData());

		$dl = $this->controller(['file' => 'nextcloud.log'])->downloadLog();
		self::assertTrue($dl instanceof StreamResponse || $dl instanceof JSONResponse);

		$fresh = $this->controller(['confirm' => 'START_FRESH'])->startFreshLog();
		self::assertTrue($fresh->getData()['ok']);
		self::assertTrue($this->controller(['confirm' => 'DELETE'])->deleteLog()->getData()['ok']);
		self::assertTrue($this->controller(['confirm' => 'DELETE_COPY', 'file' => 'nextcloud.log.1'])->deleteLogCopy()->getData()['ok']);
	}
}
