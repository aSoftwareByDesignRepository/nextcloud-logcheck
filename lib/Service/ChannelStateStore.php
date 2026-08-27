<?php

declare(strict_types=1);

namespace OCA\LogCheck\Service;

use OCP\IDBConnection;

final class ChannelStateStore
{
	public const FAIL_DISABLE_THRESHOLD = 5;

	public const ERR_HTTP = 'Webhook failed. Check the URL and try again.';
	public const ERR_MAIL = 'Email could not be sent. Check mail settings.';
	public const ERR_SECRETS = 'Stored channel secrets cannot be read. Re-enter webhook URLs.';
	public const ERR_GENERIC = 'Something went wrong. Try again.';

	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	/** @return array{channel: string, fail_count: int, last_error: ?string, disabled_at: ?int, verified_at: ?int}|null */
	public function get(string $channel): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('lck_chan_state')
			->where($qb->expr()->eq('channel', $qb->createNamedParameter($channel)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if (!is_array($row)) {
			return null;
		}
		return [
			'channel' => (string)$row['channel'],
			'fail_count' => (int)$row['fail_count'],
			'last_error' => $row['last_error'] !== null ? (string)$row['last_error'] : null,
			'disabled_at' => $row['disabled_at'] !== null ? (int)$row['disabled_at'] : null,
			'verified_at' => $row['verified_at'] !== null ? (int)$row['verified_at'] : null,
		];
	}

	public function recordSuccess(string $channel): void
	{
		$this->upsert($channel, 0, null, null, time());
	}

	/**
	 * @param string $error Raw exception text — classified to a fixed safe UI string before storage.
	 */
	public function recordFailure(string $channel, string $error): bool
	{
		$state = $this->get($channel);
		$fail = ($state['fail_count'] ?? 0) + 1;
		$disabledAt = $fail >= self::FAIL_DISABLE_THRESHOLD ? time() : ($state['disabled_at'] ?? null);
		$this->upsert($channel, $fail, self::safeError($error), $disabledAt, $state['verified_at'] ?? null);
		return $disabledAt !== null && $fail >= self::FAIL_DISABLE_THRESHOLD;
	}

	public function reenable(string $channel): void
	{
		$this->upsert($channel, 0, null, null, null);
	}

	public function isDisabled(string $channel): bool
	{
		$state = $this->get($channel);
		return $state !== null && $state['disabled_at'] !== null;
	}

	public function isVerified(string $channel): bool
	{
		$state = $this->get($channel);
		return $state !== null
			&& $state['verified_at'] !== null
			&& $state['disabled_at'] === null;
	}

	/**
	 * Drop durable test proof when the outbound URL cipher changes.
	 * Prevents re-enable of a new URL via a stale verified_at (H1).
	 */
	public function clearVerification(string $channel): void
	{
		$state = $this->get($channel);
		if ($state === null) {
			return;
		}
		if ($state['verified_at'] === null) {
			return;
		}
		$this->upsert(
			$channel,
			$state['fail_count'],
			$state['last_error'],
			$state['disabled_at'],
			null
		);
	}

	/**
	 * Map any diagnostic to one of four operator-safe strings (never raw exceptions / hosts).
	 */
	public static function safeError(string $error): string
	{
		$msg = strtolower($error);
		if (str_contains($msg, 'secret') || str_contains($msg, 're-enter') || str_contains($msg, 'decrypt')) {
			return self::ERR_SECRETS;
		}
		if (str_contains($msg, 'mail') || str_contains($msg, 'email') || str_contains($msg, 'smtp')) {
			return self::ERR_MAIL;
		}
		if (str_contains($msg, 'webhook') || str_contains($msg, 'http') || str_contains($msg, 'curl')
			|| str_contains($msg, 'ssl') || str_contains($msg, 'tls')) {
			return self::ERR_HTTP;
		}
		// Curated watch-run copy (and close variants) — never invent Watching after a failed check.
		if (str_contains($msg, 'cannot read the log') || str_contains($msg, 'check permissions')) {
			return 'Cannot read the log file. Check permissions.';
		}
		if (str_contains($msg, 'checking the log') || str_contains($msg, 'unsupported')
			|| str_contains($msg, 'file-based') || str_contains($msg, 'syslog')) {
			return 'Something went wrong while checking the log.';
		}
		return self::ERR_GENERIC;
	}

	private function upsert(string $channel, int $failCount, ?string $lastError, ?int $disabledAt, ?int $verifiedAt): void
	{
		$existing = $this->get($channel);
		if ($existing === null) {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('lck_chan_state')->values([
				'channel' => $qb->createNamedParameter($channel),
				'fail_count' => $qb->createNamedParameter($failCount),
				'last_error' => $qb->createNamedParameter($lastError),
				'disabled_at' => $qb->createNamedParameter($disabledAt),
				'verified_at' => $qb->createNamedParameter($verifiedAt),
			])->executeStatement();
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('lck_chan_state')
			->set('fail_count', $qb->createNamedParameter($failCount))
			->set('last_error', $qb->createNamedParameter($lastError))
			->set('disabled_at', $qb->createNamedParameter($disabledAt))
			->set('verified_at', $qb->createNamedParameter($verifiedAt))
			->where($qb->expr()->eq('channel', $qb->createNamedParameter($channel)));
		$qb->executeStatement();
	}
}
