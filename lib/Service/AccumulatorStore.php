<?php

declare(strict_types=1);

namespace OCA\LogCheck\Service;

use OCP\IDBConnection;

final class AccumulatorStore
{
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	/** @return array<string, mixed> */
	public function get(): array
	{
		$empty = [
			'total_matched' => 0,
			'total_muted' => 0,
			'truncated' => false,
			'by_level' => [],
			'by_app' => [],
			'fingerprints' => [],
			'first_match_at' => null,
		];
		if (!$this->db->tableExists('lck_accumulator')) {
			return $empty;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('lck_accumulator')->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if (!is_array($row)) {
			return $empty;
		}
		$decoded = json_decode((string)$row['payload'], true);
		return is_array($decoded) ? array_replace_recursive($empty, $decoded) : $empty;
	}

	/** @param array<string, mixed> $payload */
	public function save(array $payload): void
	{
		$json = json_encode($payload, JSON_THROW_ON_ERROR);
		$now = time();
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('lck_accumulator')->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if (!is_array($row)) {
			$ins = $this->db->getQueryBuilder();
			$ins->insert('lck_accumulator')->values([
				'payload' => $ins->createNamedParameter($json),
				'updated_at' => $ins->createNamedParameter($now),
			])->executeStatement();
			return;
		}
		$up = $this->db->getQueryBuilder();
		$up->update('lck_accumulator')
			->set('payload', $up->createNamedParameter($json))
			->set('updated_at', $up->createNamedParameter($now));
		$up->executeStatement();
	}

	public function clear(): void
	{
		$this->save([
			'total_matched' => 0,
			'total_muted' => 0,
			'truncated' => false,
			'by_level' => [],
			'by_app' => [],
			'fingerprints' => [],
			'first_match_at' => null,
		]);
	}

	public function isEmpty(array $payload): bool
	{
		return (int)($payload['total_matched'] ?? 0) <= 0;
	}

	/**
	 * @param array<string, mixed> $acc
	 * @param array{fingerprint: string, level: int, app: string, message: string} $hit
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	public function mergeHit(array $acc, array $hit, array $settings): array
	{
		$maxLines = (int)($settings['max_lines_per_digest'] ?? 10000);
		$maxFp = (int)($settings['max_fingerprints_in_payload'] ?? 20);
		$include = !empty($settings['include_message_excerpts']);

		if ((int)$acc['total_matched'] >= $maxLines) {
			$acc['truncated'] = true;
			return $acc;
		}

		$acc['total_matched'] = (int)$acc['total_matched'] + 1;
		if ($acc['first_match_at'] === null) {
			$acc['first_match_at'] = time();
		}
		$levelKey = (string)$hit['level'];
		$acc['by_level'][$levelKey] = (int)($acc['by_level'][$levelKey] ?? 0) + 1;
		$app = $hit['app'] !== '' ? $hit['app'] : '_unknown';
		$maxApps = 40;
		if (!isset($acc['by_app'][$app]) && count($acc['by_app']) >= $maxApps) {
			$acc['by_app']['_other'] = (int)($acc['by_app']['_other'] ?? 0) + 1;
		} else {
			$acc['by_app'][$app] = (int)($acc['by_app'][$app] ?? 0) + 1;
		}

		$fp = $hit['fingerprint'];
		if (!isset($acc['fingerprints'][$fp])) {
			if (count($acc['fingerprints']) >= $maxFp) {
				$acc['truncated'] = true;
			} else {
				$acc['fingerprints'][$fp] = [
					'count' => 1,
					'level' => $hit['level'],
					'app' => $app,
					'sample_message' => $include ? $hit['message'] : null,
				];
			}
		} else {
			$acc['fingerprints'][$fp]['count'] = (int)$acc['fingerprints'][$fp]['count'] + 1;
		}
		return $acc;
	}
}
