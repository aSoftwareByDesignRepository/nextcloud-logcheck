<?php

declare(strict_types=1);

namespace OCA\LogCheck\Service;

use OCP\IDBConnection;

final class CursorStore
{
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	/** @return array{path: string, offset: int, size: int, inode: string, fingerprint: string, updated_at: int}|null */
	public function get(): ?array
	{
		if (!$this->db->tableExists('lck_cursor')) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('lck_cursor')->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if (!is_array($row)) {
			return null;
		}
		return [
			'path' => (string)$row['path'],
			'offset' => (int)$row['offset'],
			'size' => (int)$row['size'],
			'inode' => (string)($row['inode'] ?? ''),
			'fingerprint' => (string)($row['fingerprint'] ?? ''),
			'updated_at' => (int)$row['updated_at'],
		];
	}

	/**
	 * @param array{path: string, offset: int, size: int, inode: string, fingerprint: string} $cursor
	 */
	public function upsert(array $cursor): void
	{
		$now = time();
		$existing = $this->get();
		if ($existing === null) {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('lck_cursor')->values([
				'path' => $qb->createNamedParameter($cursor['path']),
				'offset' => $qb->createNamedParameter($cursor['offset']),
				'size' => $qb->createNamedParameter($cursor['size']),
				'inode' => $qb->createNamedParameter($cursor['inode']),
				'fingerprint' => $qb->createNamedParameter($cursor['fingerprint']),
				'updated_at' => $qb->createNamedParameter($now),
			])->executeStatement();
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('lck_cursor')
			->set('path', $qb->createNamedParameter($cursor['path']))
			->set('offset', $qb->createNamedParameter($cursor['offset']))
			->set('size', $qb->createNamedParameter($cursor['size']))
			->set('inode', $qb->createNamedParameter($cursor['inode']))
			->set('fingerprint', $qb->createNamedParameter($cursor['fingerprint']))
			->set('updated_at', $qb->createNamedParameter($now));
		$qb->executeStatement();
	}

	public function initializeAtEof(string $path): void
	{
		$size = 0;
		$inode = '';
		if (is_readable($path)) {
			$stat = @stat($path);
			if ($stat !== false) {
				$size = (int)$stat['size'];
				$inode = (string)($stat['ino'] ?? '');
			}
		}
		$this->upsert([
			'path' => $path,
			'offset' => $size,
			'size' => $size,
			'inode' => $inode,
			'fingerprint' => hash('sha256', $path . '|' . $inode . '|' . $size),
		]);
	}

	public function reset(): void
	{
		if (!$this->db->tableExists('lck_cursor')) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete('lck_cursor')->executeStatement();
	}
}
