<?php

declare(strict_types=1);

namespace OCA\LogCheck\Service;

use OCP\IDBConnection;

/**
 * Job lease lock with TTL, renew, steal-on-expiry, and FOR UPDATE renew-in-TXN (Zeus SF-Z02).
 */
final class LeaseService
{
	public const LOCK_NAME = 'watch_job';
	public const TTL_SECONDS = 120;

	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	public function acquire(string $owner): bool
	{
		$this->ensureRow();
		$now = time();
		$until = $now + self::TTL_SECONDS;

		$qb = $this->db->getQueryBuilder();
		$qb->update('lck_locks')
			->set('owner', $qb->createNamedParameter($owner))
			->set('lease_until', $qb->createNamedParameter($until))
			->where($qb->expr()->eq('lock_name', $qb->createNamedParameter(self::LOCK_NAME)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->lt('lease_until', $qb->createNamedParameter($now)),
				$qb->expr()->isNull('owner'),
				$qb->expr()->eq('owner', $qb->createNamedParameter(''))
			));
		$affected = $qb->executeStatement();
		return $affected === 1;
	}

	public function renew(string $owner): bool
	{
		$until = time() + self::TTL_SECONDS;
		$qb = $this->db->getQueryBuilder();
		$qb->update('lck_locks')
			->set('lease_until', $qb->createNamedParameter($until))
			->where($qb->expr()->eq('lock_name', $qb->createNamedParameter(self::LOCK_NAME)))
			->andWhere($qb->expr()->eq('owner', $qb->createNamedParameter($owner)));
		return $qb->executeStatement() === 1;
	}

	/**
	 * Must run inside an open DB transaction. Locks the lease row (SELECT … FOR UPDATE),
	 * verifies ownership, then extends TTL — closes steal TOCTOU vs concurrent acquire.
	 */
	public function renewInTransaction(string $owner): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('owner', 'lease_until')
			->from('lck_locks')
			->where($qb->expr()->eq('lock_name', $qb->createNamedParameter(self::LOCK_NAME)))
			->setMaxResults(1);
		$sql = $qb->getSQL() . ' FOR UPDATE';
		$result = $this->db->executeQuery($sql, $qb->getParameters(), $qb->getParameterTypes());
		try {
			$row = $result->fetch();
		} finally {
			$result->closeCursor();
		}
		if (!is_array($row)) {
			return false;
		}
		if ((string)($row['owner'] ?? '') !== $owner) {
			return false;
		}
		if ((int)($row['lease_until'] ?? 0) <= time()) {
			return false;
		}
		return $this->renew($owner);
	}

	/** True iff we still own a non-expired lease. */
	public function stillHolds(string $owner): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('owner', 'lease_until')
			->from('lck_locks')
			->where($qb->expr()->eq('lock_name', $qb->createNamedParameter(self::LOCK_NAME)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if (!is_array($row)) {
			return false;
		}
		return (string)($row['owner'] ?? '') === $owner
			&& (int)($row['lease_until'] ?? 0) > time();
	}

	public function release(string $owner): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('lck_locks')
			->set('owner', $qb->createNamedParameter(''))
			->set('lease_until', $qb->createNamedParameter(0))
			->where($qb->expr()->eq('lock_name', $qb->createNamedParameter(self::LOCK_NAME)))
			->andWhere($qb->expr()->eq('owner', $qb->createNamedParameter($owner)));
		$qb->executeStatement();
	}

	private function ensureRow(): void
	{
		if (!$this->db->tableExists('lck_locks')) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('lock_name')->from('lck_locks')
			->where($qb->expr()->eq('lock_name', $qb->createNamedParameter(self::LOCK_NAME)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if (is_array($row)) {
			return;
		}
		try {
			$ins = $this->db->getQueryBuilder();
			$ins->insert('lck_locks')->values([
				'lock_name' => $ins->createNamedParameter(self::LOCK_NAME),
				'owner' => $ins->createNamedParameter(''),
				'lease_until' => $ins->createNamedParameter(0),
			])->executeStatement();
		} catch (\Throwable) {
			// Concurrent ensureRow — row now exists.
		}
	}
}
