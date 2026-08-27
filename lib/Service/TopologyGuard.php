<?php

declare(strict_types=1);

namespace OCA\LogCheck\Service;

/**
 * Detects unsupported multi-server topologies (Zeus Q-Z1 / SF-Z03).
 * Shared DB + per-node local log files ⇒ miss/dup alerts — product stance: Unsupported.
 */
final class TopologyGuard
{
	/**
	 * Stable id for this PHP host (not shown in UI).
	 */
	public function currentNodeId(): string
	{
		$host = gethostname();
		if (!is_string($host) || $host === '') {
			$host = 'unknown-host';
		}
		return hash('sha256', 'lck-node|' . $host);
	}

	/**
	 * @param array<string, mixed> $runtime
	 */
	public function isMismatch(array $runtime): bool
	{
		$prev = $runtime['watcher_node'] ?? null;
		if (!is_string($prev) || $prev === '') {
			return false;
		}
		return !hash_equals($prev, $this->currentNodeId());
	}
}
