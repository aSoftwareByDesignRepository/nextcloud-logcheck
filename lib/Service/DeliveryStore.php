<?php

declare(strict_types=1);

namespace OCA\LogCheck\Service;

use OCP\IDBConnection;

final class DeliveryStore
{
	public const RETENTION_SECONDS = 604800; // 7d

	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	public function record(string $eventId, string $channel, string $status): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->insert('lck_delivery')->values([
			'event_id' => $qb->createNamedParameter($eventId),
			'channel' => $qb->createNamedParameter($channel),
			'status' => $qb->createNamedParameter($status),
			'created_at' => $qb->createNamedParameter(time()),
		])->executeStatement();
	}

	/**
	 * True if this event/channel already recorded a successful send.
	 * Used to skip outbound HTTP after a stale reclaim when the first worker already finished.
	 */
	public function hasSent(string $eventId, string $channel): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('event_id')
			->from('lck_delivery')
			->where($qb->expr()->eq('event_id', $qb->createNamedParameter($eventId)))
			->andWhere($qb->expr()->eq('channel', $qb->createNamedParameter($channel)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('sent')))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return is_array($row);
	}

	public function purgeOld(): int
	{
		$cutoff = time() - self::RETENTION_SECONDS;
		$qb = $this->db->getQueryBuilder();
		$qb->delete('lck_delivery')
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($cutoff)));
		return $qb->executeStatement();
	}
}
