<?php

declare(strict_types=1);

namespace OCA\LogCheck\Service;

use OCP\IConfig;

/**
 * Detects unsupported multi-server topologies (Zeus Q-Z1 / SF-Z03).
 * Shared DB + per-node local log files ⇒ miss/dup alerts — product stance: Unsupported.
 */
final class TopologyGuard
{
	public function __construct(
		private readonly LogBackendService $logBackend,
		private readonly IConfig $config,
	) {
	}

	/**
	 * Stable id for this watcher node (not shown in UI).
	 *
	 * The guard exists to detect a second app server with its OWN local
	 * log file (shared DB + per-node logs ⇒ miss/dup alerts). What
	 * distinguishes that is the log file each watcher would read, not
	 * the PHP hostname: a cron sidecar in the stock docker-compose setup
	 * is a separate container (different hostname) reading the SAME
	 * datadir log — same logical node. Hashing the resolved log path +
	 * datadir keeps the protection while killing that false positive,
	 * and survives log rotation (path is stable where an inode is not).
	 */
	public function currentNodeId(): string
	{
		try {
			$path = $this->logBackend->resolveLogPath();
		} catch (\Throwable) {
			$path = 'unresolved:' . $this->logBackend->getLogType();
		}
		$datadir = (string)$this->config->getSystemValue('datadirectory', '');
		return hash('sha256', 'lck-node|' . $datadir . '|' . $path);
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
