<?php

declare(strict_types=1);

namespace OCA\LogCheck\Service;

use OCA\LogCheck\BackgroundJob\LogWatchJob;
use OCA\LogCheck\Exception\UnsupportedBackendException;
use OCP\BackgroundJob\IJobList;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Normative §9.7 watch algorithm with lease-gated writes.
 */
final class WatchRunner
{
	public const JOB_INTERVAL = 300;
	public const MAX_CATCHUP_PER_HOUR = 60;

	public function __construct(
		private readonly IDBConnection $db,
		private readonly LeaseService $leaseService,
		private readonly LogBackendService $logBackendService,
		private readonly FileTailer $fileTailer,
		private readonly FilterService $filterService,
		private readonly CursorStore $cursorStore,
		private readonly AccumulatorStore $accumulatorStore,
		private readonly PendingStore $pendingStore,
		private readonly DeliveryStore $deliveryStore,
		private readonly PayloadBuilder $payloadBuilder,
		private readonly ChannelDispatcher $channelDispatcher,
		private readonly SettingsService $settingsService,
		private readonly IJobList $jobList,
		private readonly LoggerInterface $logger,
		private readonly TopologyGuard $topologyGuard,
	) {
	}

	/**
	 * @return array{ok: bool, error?: string, continuation?: bool}
	 */
	public function run(): array
	{
		$owner = bin2hex(random_bytes(16));
		if (!$this->leaseService->acquire($owner)) {
			return ['ok' => true];
		}

		try {
			$settings = $this->settingsService->getRawSettings();
			if (empty($settings['watch_enabled'])) {
				$this->settingsService->patchRuntime([
					'last_run_at' => time(),
					'last_run_ok' => true,
					'last_error' => null,
				]);
				return ['ok' => true];
			}

			$runtime = is_array($settings['runtime'] ?? null) ? $settings['runtime'] : [];
			// Can't watch must mean no processing — UI/Health already surface topology mismatch.
			if ($this->topologyGuard->isMismatch($runtime)) {
				$topoMsg = 'HealthCheck noticed a different server. Multi-server setups need one shared log file.';
				$this->settingsService->patchRuntime([
					'last_run_at' => time(),
					'last_run_ok' => false,
					'last_error' => $topoMsg,
				]);
				return ['ok' => false, 'error' => $topoMsg];
			}

			try {
				$path = $this->logBackendService->resolveLogPath();
			} catch (UnsupportedBackendException) {
				$this->settingsService->patchRuntime([
					'last_run_at' => time(),
					'last_run_ok' => false,
					'last_error' => 'Something went wrong while checking the log.',
				]);
				return ['ok' => false, 'error' => 'Something went wrong while checking the log.'];
			}

			$cursor = $this->cursorStore->get();
			if ($cursor === null) {
				$this->cursorStore->initializeAtEof($path);
				$cursor = $this->cursorStore->get();
			}
			if ($cursor === null) {
				$msg = 'Cannot read the log file. Check permissions.';
				$this->settingsService->patchRuntime([
					'last_run_at' => time(),
					'last_run_ok' => false,
					'last_error' => $msg,
				]);
				return ['ok' => false, 'error' => $msg];
			}

			try {
				$chunk = $this->fileTailer->readChunk($path, $cursor);
			} catch (\Throwable $e) {
				$this->settingsService->patchRuntime([
					'last_run_at' => time(),
					'last_run_ok' => false,
					'last_error' => 'Cannot read the log file. Check permissions.',
				]);
				return ['ok' => false, 'error' => 'Cannot read the log file. Check permissions.'];
			}

			if (!$this->leaseService->renew($owner)) {
				return ['ok' => false, 'error' => 'Lost job lock; will retry.'];
			}

			$acc = $this->accumulatorStore->get();

			foreach ($chunk['lines'] as $line) {
				if (strlen($line) > 1048576) {
					continue;
				}
				$decoded = json_decode($line, true);
				if (!is_array($decoded)) {
					continue;
				}
				$result = $this->filterService->evaluate($decoded, $settings);
				if (!empty($result['muted'])) {
					$acc['total_muted'] = (int)$acc['total_muted'] + 1;
					continue;
				}
				if (empty($result['matched'])) {
					continue;
				}
				$acc = $this->accumulatorStore->mergeHit($acc, [
					'fingerprint' => (string)$result['fingerprint'],
					'level' => (int)$result['level'],
					'app' => (string)$result['app'],
					'message' => (string)$result['message'],
				], $settings);
			}

			if (!$this->leaseService->stillHolds($owner)) {
				return ['ok' => false, 'error' => 'Lost job lock; will retry.'];
			}

			$newCursor = [
				'path' => $path,
				'offset' => $chunk['new_offset'],
				'size' => $chunk['size'],
				'inode' => $chunk['inode'],
				'fingerprint' => $chunk['fingerprint'],
			];

			$coalesce = (int)($settings['coalesce_seconds'] ?? 300);
			$runtime = is_array($settings['runtime'] ?? null) ? $settings['runtime'] : [];
			$lastDigest = isset($runtime['last_digest_sent_at']) ? (int)$runtime['last_digest_sent_at'] : 0;
			$coalesceElapsed = ($lastDigest === 0) || ((time() - $lastDigest) >= $coalesce);
			$enabledChannels = $this->channelDispatcher->enabledChannels($settings);

			$flushed = false;
			if ($coalesceElapsed && !$this->accumulatorStore->isEmpty($acc) && $enabledChannels !== []) {
				$eventId = $this->newEventId();
				$payload = $this->payloadBuilder->build($eventId, $acc, $settings, $coalesce);
				if (!$this->leaseService->stillHolds($owner)) {
					return ['ok' => false, 'error' => 'Lost job lock; will retry.'];
				}
				if (!$this->atomicFlush($eventId, $enabledChannels, $payload, $newCursor, $owner)) {
					return ['ok' => false, 'error' => 'Lost job lock; will retry.'];
				}
				$flushed = true;
				$this->settingsService->patchRuntime(['last_digest_sent_at' => time()]);
				$this->channelDispatcher->dispatchPending($this->settingsService->getRawSettings(), $owner);
			} else {
				if (!$this->atomicPersistProgress($acc, $newCursor, $owner)) {
					return ['ok' => false, 'error' => 'Lost job lock; will retry.'];
				}
			}

			if (!$flushed) {
				$this->channelDispatcher->dispatchPending($settings, $owner);
			}

			$this->deliveryStore->purgeOld();
			$this->pendingStore->purgeExpired();

			$continuation = false;
			if (!empty($chunk['unread_remain'])) {
				$continuation = $this->maybeScheduleContinuation($runtime);
			}

			$this->settingsService->patchRuntime([
				'last_run_at' => time(),
				'last_run_ok' => true,
				'last_error' => null,
				'watcher_node' => $this->topologyGuard->currentNodeId(),
			]);

			return ['ok' => true, 'continuation' => $continuation];
		} catch (\Throwable $e) {
			$this->logger->error('HealthCheck watch run failed', ['app' => 'logcheck', 'exception' => $e]);
			$this->settingsService->patchRuntime([
				'last_run_at' => time(),
				'last_run_ok' => false,
				'last_error' => 'Something went wrong while checking the log.',
			]);
			return ['ok' => false, 'error' => 'Something went wrong while checking the log.'];
		} finally {
			$this->leaseService->release($owner);
		}
	}

	/**
	 * @param list<string> $channels
	 * @param array<string, mixed> $payload
	 * @param array{path: string, offset: int, size: int, inode: string, fingerprint: string} $cursor
	 * @return bool false if lease lost (no durable write)
	 */
	private function atomicFlush(string $eventId, array $channels, array $payload, array $cursor, string $owner): bool
	{
		$this->db->beginTransaction();
		try {
			// NN-20 / SF-Z02: FOR UPDATE + renew inside the same TXN as cursor/pending writes.
			if (!$this->leaseService->renewInTransaction($owner)) {
				$this->db->rollBack();
				return false;
			}
			$this->pendingStore->insertForChannels($eventId, $channels, $payload);
			$this->cursorStore->upsert($cursor);
			$this->accumulatorStore->clear();
			$this->db->commit();
			return true;
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
	}

	/**
	 * Persist accumulator + cursor together (AC-40 — no crash window between).
	 *
	 * @param array<string, mixed> $acc
	 * @param array{path: string, offset: int, size: int, inode: string, fingerprint: string} $cursor
	 * @return bool false if lease lost (no durable write)
	 */
	private function atomicPersistProgress(array $acc, array $cursor, string $owner): bool
	{
		$this->db->beginTransaction();
		try {
			if (!$this->leaseService->renewInTransaction($owner)) {
				$this->db->rollBack();
				return false;
			}
			$this->accumulatorStore->save($acc);
			$this->cursorStore->upsert($cursor);
			$this->db->commit();
			return true;
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
	}

	/** @param array<string, mixed> $runtime */
	private function maybeScheduleContinuation(array $runtime): bool
	{
		$hour = (int)floor(time() / 3600);
		$bucket = isset($runtime['catchup_hour_bucket']) ? (int)$runtime['catchup_hour_bucket'] : null;
		$count = (int)($runtime['catchup_requeues_hour'] ?? 0);
		if ($bucket !== $hour) {
			$bucket = $hour;
			$count = 0;
		}
		if ($count >= self::MAX_CATCHUP_PER_HOUR) {
			$this->settingsService->patchRuntime([
				'catchup_hour_bucket' => $bucket,
				'catchup_requeues_hour' => $count,
			]);
			return false;
		}
		$count++;
		$this->settingsService->patchRuntime([
			'catchup_hour_bucket' => $bucket,
			'catchup_requeues_hour' => $count,
		]);
		try {
			$this->jobList->scheduleAfter(LogWatchJob::class, time());
		} catch (\Throwable) {
		}
		return true;
	}

	private function newEventId(): string
	{
		return sprintf('%s-%s', bin2hex(random_bytes(8)), dechex(time()));
	}
}
