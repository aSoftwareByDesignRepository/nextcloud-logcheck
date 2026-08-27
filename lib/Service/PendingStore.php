<?php

declare(strict_types=1);

namespace OCA\LogCheck\Service;

use OCP\IDBConnection;

/**
 * Per-channel pending digests with claim semantics (AC-41: no double-send).
 */
final class PendingStore
{
	public const TTL_SECONDS = 86400;
	public const MAX_ATTEMPTS = 3;
	/** Stuck "sending" rows older than this are returned to pending. */
	public const SENDING_STALE_SECONDS = 600;
	/**
	 * Max pending rows loaded per claim scan (Momos MH-MEM-01).
	 * claimPending() loops claimOne() so a large backlog still drains across ticks.
	 */
	public const CLAIM_BATCH_LIMIT = 50;

	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	/**
	 * @param list<string> $channels
	 * @param array<string, mixed> $payload
	 */
	public function insertForChannels(string $eventId, array $channels, array $payload): void
	{
		$now = time();
		$json = json_encode($payload, JSON_THROW_ON_ERROR);
		foreach ($channels as $channel) {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('lck_pending')->values([
				'event_id' => $qb->createNamedParameter($eventId),
				'channel' => $qb->createNamedParameter($channel),
				'status' => $qb->createNamedParameter('pending'),
				'attempts' => $qb->createNamedParameter(0),
				'claim_gen' => $qb->createNamedParameter(0),
				'payload' => $qb->createNamedParameter($json),
				'created_at' => $qb->createNamedParameter($now),
				'updated_at' => $qb->createNamedParameter($now),
			])->executeStatement();
		}
	}

	/**
	 * Atomically claim the next pending row for exclusive delivery.
	 *
	 * @return array{event_id: string, channel: string, status: string, attempts: int, payload: array<string, mixed>, created_at: int, updated_at: int, claim_gen: int}|null
	 */
	public function claimOne(): ?array
	{
		$this->reclaimStaleSending();
		$candidates = $this->listByStatus('pending');
		foreach ($candidates as $row) {
			$claimGen = $this->tryClaim($row['event_id'], $row['channel']);
			if ($claimGen !== null) {
				$row['status'] = 'sending';
				$row['claim_gen'] = $claimGen;
				$row['updated_at'] = time();
				return $row;
			}
		}
		return null;
	}

	/**
	 * @return list<array{event_id: string, channel: string, status: string, attempts: int, payload: array<string, mixed>, created_at: int, updated_at: int, claim_gen: int}>
	 */
	public function claimPending(): array
	{
		$claimed = [];
		while (($row = $this->claimOne()) !== null) {
			$claimed[] = $row;
		}
		return $claimed;
	}

	/** @return int|null New claim_gen on success */
	private function tryClaim(string $eventId, string $channel): ?int
	{
		$now = time();
		$claimGen = random_int(1, 0x7fffffff);
		$qb = $this->db->getQueryBuilder();
		$qb->update('lck_pending')
			->set('status', $qb->createNamedParameter('sending'))
			->set('updated_at', $qb->createNamedParameter($now))
			->set('claim_gen', $qb->createNamedParameter($claimGen))
			->where($qb->expr()->eq('event_id', $qb->createNamedParameter($eventId)))
			->andWhere($qb->expr()->eq('channel', $qb->createNamedParameter($channel)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('pending')));
		return $qb->executeStatement() === 1 ? $claimGen : null;
	}

	/** @return list<array{event_id: string, channel: string, status: string, attempts: int, payload: array<string, mixed>, created_at: int, updated_at: int, claim_gen: int}> */
	private function listByStatus(string $status): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('lck_pending')
			->where($qb->expr()->eq('status', $qb->createNamedParameter($status)))
			->orderBy('created_at', 'ASC')
			->setMaxResults(self::CLAIM_BATCH_LIMIT);
		$result = $qb->executeQuery();
		$rows = [];
		while ($row = $result->fetch()) {
			$decoded = json_decode((string)$row['payload'], true);
			$rows[] = [
				'event_id' => (string)$row['event_id'],
				'channel' => (string)$row['channel'],
				'status' => (string)$row['status'],
				'attempts' => (int)$row['attempts'],
				'payload' => is_array($decoded) ? $decoded : [],
				'created_at' => (int)$row['created_at'],
				'updated_at' => (int)$row['updated_at'],
				'claim_gen' => (int)($row['claim_gen'] ?? 0),
			];
		}
		$result->closeCursor();
		return $rows;
	}

	/** @deprecated Prefer claimPending for delivery */
	public function listPending(): array
	{
		return $this->listByStatus('pending');
	}

	/**
	 * Complete a claim only while still in this claim generation (status=sending + claim_gen).
	 * Prevents a late first sender from completing after stale reclaim + second claim.
	 */
	public function markSent(string $eventId, string $channel, int $claimGen): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('lck_pending')
			->set('status', $qb->createNamedParameter('sent'))
			->set('updated_at', $qb->createNamedParameter(time()))
			->where($qb->expr()->eq('event_id', $qb->createNamedParameter($eventId)))
			->andWhere($qb->expr()->eq('channel', $qb->createNamedParameter($channel)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('sending')))
			->andWhere($qb->expr()->eq('claim_gen', $qb->createNamedParameter($claimGen)));
		return $qb->executeStatement() === 1;
	}

	/**
	 * Fail/retry a claim only for this claim generation.
	 */
	public function markFailed(string $eventId, string $channel, int $attempts, int $claimGen): bool
	{
		$status = $attempts >= self::MAX_ATTEMPTS ? 'abandoned' : 'pending';
		$qb = $this->db->getQueryBuilder();
		$qb->update('lck_pending')
			->set('status', $qb->createNamedParameter($status))
			->set('attempts', $qb->createNamedParameter($attempts))
			->set('updated_at', $qb->createNamedParameter(time()))
			->where($qb->expr()->eq('event_id', $qb->createNamedParameter($eventId)))
			->andWhere($qb->expr()->eq('channel', $qb->createNamedParameter($channel)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('sending')))
			->andWhere($qb->expr()->eq('claim_gen', $qb->createNamedParameter($claimGen)));
		return $qb->executeStatement() === 1;
	}

	public function markAbandoned(string $eventId, string $channel, string $reason = ''): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('lck_pending')
			->set('status', $qb->createNamedParameter('abandoned'))
			->set('updated_at', $qb->createNamedParameter(time()))
			->where($qb->expr()->eq('event_id', $qb->createNamedParameter($eventId)))
			->andWhere($qb->expr()->eq('channel', $qb->createNamedParameter($channel)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(
				['pending', 'sending'],
				\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY
			)));
		$qb->executeStatement();
		// Reason recorded by DeliveryStore caller (channel_disabled / etc.).
		unset($reason);
	}

	public function reclaimStaleSending(): int
	{
		$cutoff = time() - self::SENDING_STALE_SECONDS;
		$qb = $this->db->getQueryBuilder();
		$qb->update('lck_pending')
			->set('status', $qb->createNamedParameter('pending'))
			->set('updated_at', $qb->createNamedParameter(time()))
			->where($qb->expr()->eq('status', $qb->createNamedParameter('sending')))
			->andWhere($qb->expr()->lt('updated_at', $qb->createNamedParameter($cutoff)));
		return $qb->executeStatement();
	}

	public function purgeExpired(): int
	{
		$this->reclaimStaleSending();
		$cutoff = time() - self::TTL_SECONDS;
		$qb = $this->db->getQueryBuilder();
		$qb->delete('lck_pending')
			->where($qb->expr()->orX(
				$qb->expr()->andX(
					$qb->expr()->eq('status', $qb->createNamedParameter('abandoned')),
					$qb->expr()->lt('created_at', $qb->createNamedParameter($cutoff))
				),
				$qb->expr()->andX(
					$qb->expr()->eq('status', $qb->createNamedParameter('pending')),
					$qb->expr()->lt('created_at', $qb->createNamedParameter($cutoff))
				),
				// Sent rows are durable in lck_delivery — do not grow pending forever.
				$qb->expr()->andX(
					$qb->expr()->eq('status', $qb->createNamedParameter('sent')),
					$qb->expr()->lt('updated_at', $qb->createNamedParameter($cutoff))
				)
			));
		return $qb->executeStatement();
	}
}
