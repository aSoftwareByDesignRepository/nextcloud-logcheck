<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Service;

use OCA\LogCheck\Exception\UnsupportedBackendException;
use OCA\LogCheck\Service\AccumulatorStore;
use OCA\LogCheck\Service\ChannelDispatcher;
use OCA\LogCheck\Service\CursorStore;
use OCA\LogCheck\Service\DeliveryStore;
use OCA\LogCheck\Service\FileTailer;
use OCA\LogCheck\Service\FilterService;
use OCA\LogCheck\Service\LeaseService;
use OCA\LogCheck\Service\LogBackendService;
use OCA\LogCheck\Service\PayloadBuilder;
use OCA\LogCheck\Service\PendingStore;
use OCA\LogCheck\Service\SettingsService;
use OCA\LogCheck\Service\TopologyGuard;
use OCA\LogCheck\Service\WatchRunner;
use OCP\BackgroundJob\IJobList;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WatchRunnerTest extends TestCase
{
	/** @var LeaseService&MockObject */
	private LeaseService $lease;
	/** @var LogBackendService&MockObject */
	private LogBackendService $backend;
	/** @var FileTailer&MockObject */
	private FileTailer $tailer;
	/** @var CursorStore&MockObject */
	private CursorStore $cursor;
	/** @var AccumulatorStore&MockObject */
	private AccumulatorStore $accumulator;
	/** @var SettingsService&MockObject */
	private SettingsService $settings;
	/** @var ChannelDispatcher&MockObject */
	private ChannelDispatcher $dispatcher;
	/** @var TopologyGuard&MockObject */
	private TopologyGuard $topology;
	private WatchRunner $runner;

	protected function setUp(): void
	{
		$this->lease = $this->createMock(LeaseService::class);
		$this->backend = $this->createMock(LogBackendService::class);
		$this->tailer = $this->createMock(FileTailer::class);
		$this->cursor = $this->createMock(CursorStore::class);
		$this->accumulator = $this->createMock(AccumulatorStore::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->dispatcher = $this->createMock(ChannelDispatcher::class);
		$this->topology = $this->createMock(TopologyGuard::class);
		$this->topology->method('currentNodeId')->willReturn('node-test');
		$this->topology->method('isMismatch')->willReturn(false);

		$filter = $this->createMock(FilterService::class);
		$pending = $this->createMock(PendingStore::class);
		$delivery = $this->createMock(DeliveryStore::class);
		$payload = $this->createMock(PayloadBuilder::class);
		$db = $this->createMock(IDBConnection::class);
		$jobs = $this->createMock(IJobList::class);
		$logger = $this->createMock(LoggerInterface::class);

		$this->runner = new WatchRunner(
			$db,
			$this->lease,
			$this->backend,
			$this->tailer,
			$filter,
			$this->cursor,
			$this->accumulator,
			$pending,
			$delivery,
			$payload,
			$this->dispatcher,
			$this->settings,
			$jobs,
			$logger,
			$this->topology,
		);
	}

	public function testRenewFalseSkipsCursorUpsert(): void
	{
		$this->lease->expects(self::once())->method('acquire')->willReturn(true);
		$this->lease->expects(self::once())->method('renew')->willReturn(false);
		$this->lease->expects(self::once())->method('release');

		$this->settings->method('getRawSettings')->willReturn([
			'watch_enabled' => true,
			'coalesce_seconds' => 300,
			'runtime' => [],
		]);
		$this->backend->method('resolveLogPath')->willReturn('/tmp/nc.log');
		$this->cursor->method('get')->willReturn([
			'path' => '/tmp/nc.log',
			'offset' => 0,
			'size' => 10,
			'inode' => '1',
			'fingerprint' => 'fp',
		]);
		$this->tailer->method('readChunk')->willReturn([
			'lines' => ['{"level":3,"app":"files","message":"x"}'],
			'new_offset' => 40,
			'size' => 40,
			'inode' => '1',
			'fingerprint' => 'fp2',
			'rotated' => false,
			'unread_remain' => false,
		]);

		$this->cursor->expects(self::never())->method('upsert');
		$this->accumulator->expects(self::never())->method('save');
		$this->accumulator->expects(self::never())->method('mergeHit');

		$result = $this->runner->run();
		self::assertFalse($result['ok']);
		self::assertSame('Lost job lock; will retry.', $result['error']);
	}

	public function testEmptyWatchReturnsOkWithoutTailing(): void
	{
		$this->lease->expects(self::once())->method('acquire')->willReturn(true);
		$this->lease->expects(self::once())->method('release');
		$this->lease->expects(self::never())->method('renew');

		$this->settings->method('getRawSettings')->willReturn([
			'watch_enabled' => false,
			'runtime' => [],
		]);
		$this->settings->expects(self::once())->method('patchRuntime')->with(self::callback(
			static fn(array $p): bool => !empty($p['last_run_ok']) && array_key_exists('last_run_at', $p)
		));
		$this->backend->expects(self::never())->method('resolveLogPath');
		$this->cursor->expects(self::never())->method('upsert');

		$result = $this->runner->run();
		self::assertTrue($result['ok']);
	}

	public function testUnsupportedBackendReturnsError(): void
	{
		$this->lease->expects(self::once())->method('acquire')->willReturn(true);
		$this->lease->expects(self::once())->method('release');

		$this->settings->method('getRawSettings')->willReturn([
			'watch_enabled' => true,
			'runtime' => [],
		]);
		$this->backend->method('resolveLogPath')->willThrowException(
			new UnsupportedBackendException('syslog')
		);
		$this->settings->expects(self::once())->method('patchRuntime')->with(self::callback(
			static fn(array $p): bool => ($p['last_run_ok'] ?? null) === false
				&& ($p['last_error'] ?? null) === 'Something went wrong while checking the log.'
		));
		$this->cursor->expects(self::never())->method('upsert');

		$result = $this->runner->run();
		self::assertFalse($result['ok']);
		self::assertSame('Something went wrong while checking the log.', (string)$result['error']);
	}

	public function testAcquireFalseReturnsOkQuietly(): void
	{
		$this->lease->method('acquire')->willReturn(false);
		$this->lease->expects(self::never())->method('release');
		$this->settings->expects(self::never())->method('getRawSettings');

		$result = $this->runner->run();
		self::assertTrue($result['ok']);
	}

	/** Zeus MF: Can't watch (topology) must not tail or dispatch. */
	public function testTopologyMismatchSkipsTailAndDispatch(): void
	{
		$this->topology = $this->createMock(TopologyGuard::class);
		$this->topology->method('currentNodeId')->willReturn('node-b');
		$this->topology->method('isMismatch')->willReturn(true);

		$filter = $this->createMock(FilterService::class);
		$pending = $this->createMock(PendingStore::class);
		$delivery = $this->createMock(DeliveryStore::class);
		$payload = $this->createMock(PayloadBuilder::class);
		$db = $this->createMock(IDBConnection::class);
		$jobs = $this->createMock(IJobList::class);
		$logger = $this->createMock(LoggerInterface::class);

		$this->runner = new WatchRunner(
			$db,
			$this->lease,
			$this->backend,
			$this->tailer,
			$filter,
			$this->cursor,
			$this->accumulator,
			$pending,
			$delivery,
			$payload,
			$this->dispatcher,
			$this->settings,
			$jobs,
			$logger,
			$this->topology,
		);

		$this->lease->expects(self::once())->method('acquire')->willReturn(true);
		$this->lease->expects(self::once())->method('release');
		$this->settings->method('getRawSettings')->willReturn([
			'watch_enabled' => true,
			'runtime' => ['watcher_node' => 'other-node'],
		]);
		$this->settings->expects(self::once())->method('patchRuntime')->with(self::callback(
			static fn(array $p): bool => ($p['last_run_ok'] ?? null) === false
				&& is_string($p['last_error'] ?? null)
				&& str_contains((string)$p['last_error'], 'different server')
		));
		$this->backend->expects(self::never())->method('resolveLogPath');
		$this->tailer->expects(self::never())->method('readChunk');
		$this->dispatcher->expects(self::never())->method('dispatchPending');
		$this->cursor->expects(self::never())->method('upsert');

		$result = $this->runner->run();
		self::assertFalse($result['ok']);
		self::assertStringContainsString('different server', (string)$result['error']);
	}
}
