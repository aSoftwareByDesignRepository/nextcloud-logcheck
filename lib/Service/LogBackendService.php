<?php

declare(strict_types=1);

namespace OCA\LogCheck\Service;

use OCA\LogCheck\Exception\UnsupportedBackendException;
use OCP\IConfig;

/**
 * Resolves log_type + logfile from system config only (NN-17).
 *
 * Relative `logfile` values are anchored under datadirectory so sibling
 * discovery never inherits PHP-FPM CWD (web root) as the log directory.
 */
final class LogBackendService
{
	public function __construct(
		private readonly IConfig $config,
	) {
	}

	public function getLogType(): string
	{
		return (string)$this->config->getSystemValue('log_type', 'file');
	}

	public function isFileBackend(): bool
	{
		return $this->getLogType() === 'file';
	}

	public function assertFileBackend(): void
	{
		$type = $this->getLogType();
		if ($type !== 'file') {
			throw new UnsupportedBackendException($type);
		}
	}

	/**
	 * Path from systemconfig ONLY — never accept a user-supplied path.
	 * Always returns an absolute path when datadirectory is configured.
	 */
	public function resolveLogPath(): string
	{
		$this->assertFileBackend();
		$configured = (string)$this->config->getSystemValue('logfile', '');
		$dataDir = (string)$this->config->getSystemValue('datadirectory', '');
		if ($configured !== '') {
			return $this->absolutizeConfiguredPath($configured, $dataDir);
		}
		if ($dataDir === '') {
			throw new UnsupportedBackendException('file', 'Cannot read the log file. Check permissions.');
		}
		return rtrim($dataDir, '/') . '/nextcloud.log';
	}

	/**
	 * Absolute paths and Windows drive paths pass through; relative paths
	 * are resolved under datadirectory (never under process CWD).
	 */
	private function absolutizeConfiguredPath(string $configured, string $dataDir): string
	{
		$configured = str_replace(["\0"], '', $configured);
		if ($configured === '') {
			throw new UnsupportedBackendException('file', 'Cannot read the log file. Check permissions.');
		}
		if ($this->isAbsolutePath($configured)) {
			return $configured;
		}
		if ($dataDir === '') {
			throw new UnsupportedBackendException('file', 'Cannot read the log file. Check permissions.');
		}
		return rtrim($dataDir, '/') . '/' . ltrim(str_replace('\\', '/', $configured), '/');
	}

	private function isAbsolutePath(string $path): bool
	{
		if (str_starts_with($path, '/')) {
			return true;
		}
		// Windows-style absolute path (rare on NC hosts; keep for completeness).
		return (bool)preg_match('/^[A-Za-z]:[\\\\\\/]/', $path);
	}
}
