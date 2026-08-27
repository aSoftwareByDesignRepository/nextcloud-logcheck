<?php

declare(strict_types=1);

namespace OCA\LogCheck\Service;

use OCA\LogCheck\Exception\ForbiddenException;
use OCA\LogCheck\Exception\UnsupportedBackendException;
use OCA\LogCheck\Exception\ValidationException;
use OCP\IConfig;

/**
 * Secure in-app log browser for the systemconfig logfile and its allowlisted siblings (NN-17).
 *
 * Red-team posture:
 * - Never accepts a client-supplied filesystem path (basename id only).
 * - Related files = live basename + `.N` (1–50) + `.lck-rotated-YYYYMMDD-HHMMSS` in the same directory.
 * - realpath jail: siblings must resolve under dirname(live); symlink escape → forbidden.
 * - Literal search only (no regex → no ReDoS).
 * - Hard caps on bytes/lines/matches (no full-file load).
 * - Rotate/delete live require NC system admin + typed confirm + watch lease.
 * - Delete older copies: NC admin + DELETE_COPY; never touches watch cursor.
 * - Reading archives never writes CursorStore (alerts stay on the live stream).
 */
final class LogFileService
{
	public const TAIL_MAX_BYTES = 262144; // 256 KiB
	public const TAIL_MAX_LINES = 500;
	public const SEARCH_MAX_SCAN_BYTES = 2097152; // 2 MiB
	public const SEARCH_MAX_MATCHES = 100;
	public const NEEDLE_MAX_LEN = 200;
	public const LINE_CLIP_CHARS = 8192;
	public const FILE_ID_MAX_LEN = 200;
	public const ROTATE_INDEX_MAX = 50;
	public const CONFIRM_START_FRESH = 'START_FRESH';
	public const CONFIRM_DELETE = 'DELETE';
	public const CONFIRM_DELETE_COPY = 'DELETE_COPY';
	/** Max bytes accumulated in the browser viewer (chunked load-older). */
	public const VIEW_ACCUM_MAX_BYTES = 2097152; // 2 MiB
	/** Soft cap for streamed downloads (still streamed; oversize rejected). */
	public const DOWNLOAD_MAX_BYTES = 104857600; // 100 MiB

	public function __construct(
		private readonly IConfig $config,
		private readonly LogBackendService $logBackend,
		private readonly CursorStore $cursorStore,
		private readonly LeaseService $leaseService,
		private readonly AccessService $accessService,
		private readonly AuditService $auditService,
	) {
	}

	/**
	 * @return array{
	 *   available: bool,
	 *   backend_supported: bool,
	 *   exists: bool,
	 *   readable: bool,
	 *   writable: bool,
	 *   name: string,
	 *   path: string|null,
	 *   size: int,
	 *   mtime: int|null,
	 *   can_mutate: bool,
	 *   related_count: int
	 * }
	 */
	public function meta(bool $revealPath): array
	{
		try {
			$this->logBackend->assertFileBackend();
		} catch (UnsupportedBackendException) {
			return [
				'available' => false,
				'backend_supported' => false,
				'exists' => false,
				'readable' => false,
				'writable' => false,
				'name' => '',
				'path' => null,
				'size' => 0,
				'mtime' => null,
				'can_mutate' => false,
				'related_count' => 0,
			];
		}

		$path = $this->logBackend->resolveLogPath();
		$name = basename($path);
		$exists = is_file($path);
		$readable = $exists && is_readable($path);
		$writable = $exists ? is_writable($path) : is_writable(dirname($path));
		$size = 0;
		$mtime = null;
		if ($exists) {
			$stat = @stat($path);
			if ($stat !== false) {
				$size = (int)$stat['size'];
				$mtime = (int)($stat['mtime'] ?? 0);
			}
		}

		$relatedCount = 0;
		try {
			$relatedCount = count($this->listFiles()['files']);
		} catch (\Throwable) {
			$relatedCount = $exists ? 1 : 0;
		}

		return [
			'available' => true,
			'backend_supported' => true,
			'exists' => $exists,
			'readable' => $readable,
			'writable' => $writable,
			'name' => $name,
			'path' => $revealPath ? $path : null,
			'size' => $size,
			'mtime' => $mtime,
			'can_mutate' => $writable,
			'related_count' => $relatedCount,
		];
	}

	/**
	 * List the live logfile and allowlisted siblings in its directory (NN-17 safe).
	 *
	 * @return array{
	 *   live: string,
	 *   files: list<array{
	 *     id: string,
	 *     name: string,
	 *     role: 'live'|'rotated'|'archive',
	 *     is_live: bool,
	 *     size: int,
	 *     mtime: int|null,
	 *     readable: bool,
	 *     exists: bool
	 *   }>
	 * }
	 */
	public function listFiles(): array
	{
		$this->logBackend->assertFileBackend();
		$livePath = $this->logBackend->resolveLogPath();
		$this->assertConfiguredPath($livePath);
		$base = basename($livePath);
		$dir = $this->resolveLogDirectory($livePath);

		$files = [];
		$files[] = $this->describeFile($livePath, $base, 'live', true);

		$entries = @scandir($dir);
		if (!is_array($entries)) {
			return ['live' => $base, 'files' => $files];
		}

		$dirPrefix = rtrim($dir, '/') . '/';
		foreach ($entries as $name) {
			if ($name === '.' || $name === '..' || $name === $base) {
				continue;
			}
			if (!$this->isAllowlistedBasename($base, $name)) {
				continue;
			}
			$candidate = $dirPrefix . $name;
			if (!is_file($candidate)) {
				continue;
			}
			$real = realpath($candidate);
			if ($real === false || !is_file($real) || !str_starts_with($real, $dirPrefix)) {
				continue;
			}
			if (basename($real) !== $name) {
				continue;
			}
			$role = str_contains($name, '.lck-rotated-') ? 'archive' : 'rotated';
			$files[] = $this->describeFile($real, $name, $role, false);
		}

		usort($files, static function (array $a, array $b): int {
			if ($a['is_live'] !== $b['is_live']) {
				return $a['is_live'] ? -1 : 1;
			}
			$am = $a['mtime'] ?? 0;
			$bm = $b['mtime'] ?? 0;
			if ($am !== $bm) {
				return $bm <=> $am;
			}
			return strcmp($a['id'], $b['id']);
		});

		return ['live' => $base, 'files' => $files];
	}

	/**
	 * @return array{
	 *   lines: list<array{text: string, clipped: bool}>,
	 *   size: int,
	 *   truncated: bool,
	 *   from_offset: int,
	 *   file: string,
	 *   role: string,
	 *   is_live: bool
	 * }
	 */
	public function readTail(
		int $maxBytes = self::TAIL_MAX_BYTES,
		int $maxLines = self::TAIL_MAX_LINES,
		?string $fileId = null,
		int $viewerMinLevel = LogLineLevel::VIEWER_ALL,
	): array {
		$resolved = $this->resolveReadableFile($fileId);
		$path = $resolved['path'];
		$maxBytes = max(1024, min(self::TAIL_MAX_BYTES, $maxBytes));
		$maxLines = max(1, min(self::TAIL_MAX_LINES, $maxLines));

		$stat = @stat($path);
		if ($stat === false) {
			throw new ValidationException('Cannot read the log file. Check permissions.');
		}
		$size = (int)$stat['size'];
		if ($size === 0) {
			return array_merge([
				'lines' => [],
				'size' => 0,
				'truncated' => false,
				'from_offset' => 0,
				'file' => $resolved['id'],
				'role' => $resolved['role'],
				'is_live' => $resolved['is_live'],
			], $this->viewerFilterMeta([], $viewerMinLevel));
		}

		$from = max(0, $size - $maxBytes);
		$fh = fopen($path, 'rb');
		if ($fh === false) {
			throw new ValidationException('Cannot read the log file. Check permissions.');
		}
		try {
			if ($from > 0) {
				fseek($fh, $from);
			}
			$raw = stream_get_contents($fh);
			if ($raw === false) {
				throw new ValidationException('Cannot read the log file. Check permissions.');
			}
		} finally {
			fclose($fh);
		}

		$truncated = $from > 0;
		if ($truncated) {
			$nl = strpos($raw, "\n");
			if ($nl !== false) {
				$raw = substr($raw, $nl + 1);
				$from += $nl + 1;
			}
		}

		$parts = $raw === '' ? [] : explode("\n", $raw);
		if ($parts !== [] && end($parts) === '') {
			array_pop($parts);
		}
		$lineCapExceeded = count($parts) > $maxLines;
		$lines = [];
		foreach ($parts as $line) {
			$lines[] = $this->clipLine($line);
		}

		$filtered = $this->applyViewerFilter($lines, $viewerMinLevel, $maxLines);
		if ($lineCapExceeded || ($filtered['filter_active'] && count($lines) > count($filtered['lines']))) {
			$truncated = true;
		}

		return array_merge([
			'size' => $size,
			'truncated' => $truncated,
			'from_offset' => $from,
			'file' => $resolved['id'],
			'role' => $resolved['role'],
			'is_live' => $resolved['is_live'],
		], $filtered);
	}

	/**
	 * Read a capped window ending at $beforeOffset (exclusive) — “load older” chunks.
	 *
	 * @return array{
	 *   lines: list<array{text: string, clipped: bool}>,
	 *   size: int,
	 *   truncated: bool,
	 *   from_offset: int,
	 *   to_offset: int,
	 *   file: string,
	 *   role: string,
	 *   is_live: bool
	 * }
	 */
	public function readBefore(
		int $beforeOffset,
		int $maxBytes = self::TAIL_MAX_BYTES,
		int $maxLines = self::TAIL_MAX_LINES,
		?string $fileId = null,
		int $viewerMinLevel = LogLineLevel::VIEWER_ALL,
	): array {
		$resolved = $this->resolveReadableFile($fileId);
		$path = $resolved['path'];
		$maxBytes = max(1024, min(self::TAIL_MAX_BYTES, $maxBytes));
		$maxLines = max(1, min(self::TAIL_MAX_LINES, $maxLines));

		$stat = @stat($path);
		if ($stat === false) {
			throw new ValidationException('Cannot read the log file. Check permissions.');
		}
		$size = (int)$stat['size'];
		if ($size === 0 || $beforeOffset <= 0) {
			return array_merge([
				'lines' => [],
				'size' => $size,
				'truncated' => false,
				'from_offset' => 0,
				'to_offset' => 0,
				'file' => $resolved['id'],
				'role' => $resolved['role'],
				'is_live' => $resolved['is_live'],
			], $this->viewerFilterMeta([], $viewerMinLevel));
		}

		$end = min($beforeOffset, $size);
		$from = max(0, $end - $maxBytes);
		$fh = fopen($path, 'rb');
		if ($fh === false) {
			throw new ValidationException('Cannot read the log file. Check permissions.');
		}
		try {
			if ($from > 0) {
				fseek($fh, $from);
			}
			$raw = stream_get_contents($fh, $end - $from);
			if ($raw === false) {
				throw new ValidationException('Cannot read the log file. Check permissions.');
			}
		} finally {
			fclose($fh);
		}

		$truncated = $from > 0;
		if ($from > 0) {
			$nl = strpos($raw, "\n");
			if ($nl !== false) {
				$raw = substr($raw, $nl + 1);
				$from += $nl + 1;
			}
		}

		$parts = $raw === '' ? [] : explode("\n", $raw);
		if ($parts !== [] && end($parts) === '') {
			array_pop($parts);
		}
		$lineCapExceeded = count($parts) > $maxLines;
		$lines = [];
		foreach ($parts as $line) {
			$lines[] = $this->clipLine($line);
		}

		$filtered = $this->applyViewerFilter($lines, $viewerMinLevel, $maxLines);
		if ($lineCapExceeded || ($filtered['filter_active'] && count($lines) > count($filtered['lines']))) {
			$truncated = true;
		}

		return array_merge([
			'size' => $size,
			'truncated' => $truncated,
			'from_offset' => $from,
			'to_offset' => $end,
			'file' => $resolved['id'],
			'role' => $resolved['role'],
			'is_live' => $resolved['is_live'],
		], $filtered);
	}

	/**
	 * Resolve an allowlisted log for streamed download (never load full file into PHP memory).
	 *
	 * @return array{path: string, name: string, size: int}
	 */
	public function resolveDownload(?string $fileId, string $actorUid): array {
		// Full-file download is NC system admin only (App Admins keep in-app viewer/search).
		$this->assertNcAdmin($actorUid);
		$resolved = $this->resolveReadableFile($fileId);
		$path = $resolved['path'];
		$stat = @stat($path);
		if ($stat === false) {
			throw new ValidationException('Cannot read the log file. Check permissions.');
		}
		$size = (int)$stat['size'];
		if ($size > self::DOWNLOAD_MAX_BYTES) {
			throw new ValidationException(
				'This log file is too large to download here. Use SSH or your host panel, or start a fresh log.',
			);
		}
		$this->auditService->log($actorUid, 'log_downloaded', [
			'name' => $resolved['id'],
			'role' => $resolved['role'],
			'size' => $size,
		]);
		return [
			'path' => $path,
			'name' => $resolved['id'],
			'size' => $size,
		];
	}

	public function search(
		string $needle,
		bool $caseSensitive = false,
		int $maxMatches = self::SEARCH_MAX_MATCHES,
		int $scanBytes = self::SEARCH_MAX_SCAN_BYTES,
		?string $fileId = null,
		?int $beforeOffset = null,
		int $viewerMinLevel = LogLineLevel::VIEWER_ALL,
	): array {
		$needle = trim($needle);
		if ($needle === '') {
			throw new ValidationException(
				'Enter something to search for.',
				['q' => 'Enter something to search for.'],
			);
		}
		if (strlen($needle) > self::NEEDLE_MAX_LEN) {
			throw new ValidationException(
				'Search is too long.',
				['q' => 'Search is too long.'],
			);
		}

		$resolved = $this->resolveReadableFile($fileId);
		$path = $resolved['path'];
		$maxMatches = max(1, min(self::SEARCH_MAX_MATCHES, $maxMatches));
		$scanBytes = max(1024, min(self::SEARCH_MAX_SCAN_BYTES, $scanBytes));

		$stat = @stat($path);
		if ($stat === false) {
			throw new ValidationException('Cannot read the log file. Check permissions.');
		}
		$size = (int)$stat['size'];
		if ($size === 0) {
			return array_merge([
				'matches' => [],
				'scanned_bytes' => 0,
				'size' => 0,
				'truncated' => false,
				'needle' => $needle,
				'file' => $resolved['id'],
				'role' => $resolved['role'],
				'is_live' => $resolved['is_live'],
			], $this->viewerFilterMeta([], $viewerMinLevel));
		}

		$end = $size;
		if ($beforeOffset !== null) {
			if ($beforeOffset < 0) {
				throw new ValidationException('Invalid search window.');
			}
			$end = min($beforeOffset, $size);
		}
		$from = max(0, $end - $scanBytes);
		$fh = fopen($path, 'rb');
		if ($fh === false) {
			throw new ValidationException('Cannot read the log file. Check permissions.');
		}
		try {
			if ($from > 0) {
				fseek($fh, $from);
			}
			$raw = stream_get_contents($fh, $end - $from);
			if ($raw === false) {
				throw new ValidationException('Cannot read the log file. Check permissions.');
			}
		} finally {
			fclose($fh);
		}

		$windowTruncated = $from > 0;
		if ($windowTruncated) {
			$nl = strpos($raw, "\n");
			if ($nl !== false) {
				$raw = substr($raw, $nl + 1);
			}
		}

		$parts = $raw === '' ? [] : explode("\n", $raw);
		if ($parts !== [] && end($parts) === '') {
			array_pop($parts);
		}

		$hayNeedle = $caseSensitive ? $needle : mb_strtolower($needle, 'UTF-8');
		$matches = [];
		foreach ($parts as $line) {
			$hay = $caseSensitive ? $line : mb_strtolower($line, 'UTF-8');
			if (!str_contains($hay, $hayNeedle)) {
				continue;
			}
			$matches[] = $this->clipLine($line);
			if (count($matches) >= $maxMatches) {
				break;
			}
		}

		$filterMeta = $this->viewerFilterMeta($matches, $viewerMinLevel);
		if ($filterMeta['filter_active']) {
			$filteredMatches = [];
			foreach ($matches as $line) {
				if (LogLineLevel::lineMatchesViewer($line['text'], $viewerMinLevel)) {
					$filteredMatches[] = $line;
				}
			}
			$matches = $filteredMatches;
			$filterMeta['lines_matched'] = count($matches);
		}

		return array_merge([
			'matches' => $matches,
			'scanned_bytes' => strlen($raw),
			'size' => $size,
			'truncated' => $windowTruncated || count($matches) >= $maxMatches,
			'needle' => $needle,
			'file' => $resolved['id'],
			'role' => $resolved['role'],
			'is_live' => $resolved['is_live'],
		], $filterMeta);
	}

	/**
	 * Rename the live log aside and create an empty replacement (preferred “start fresh”).
	 *
	 * @return array{ok: true, archive_name: string, name: string, size: int}
	 */
	public function startFresh(string $uid, string $confirm): array
	{
		$this->assertNcAdmin($uid);
		if ($confirm !== self::CONFIRM_START_FRESH) {
			throw new ValidationException(
				'Type START_FRESH to confirm.',
				['confirm' => 'Type START_FRESH to confirm.'],
			);
		}

		$this->logBackend->assertFileBackend();
		$path = $this->logBackend->resolveLogPath();
		$this->assertConfiguredPath($path);

		$owner = $this->leaseOwner('rotate', $uid);
		if (!$this->leaseService->acquire($owner)) {
			throw new ValidationException(
				'LogCheck is busy checking the log. Try again in a moment.',
				[],
				'LCK_BUSY',
			);
		}

		try {
			$archiveName = '';
			if (is_file($path)) {
				if (!is_writable($path) || !is_writable(dirname($path))) {
					throw new ValidationException('Cannot change the log file. Check permissions.');
				}
				$dir = dirname($path);
				$base = basename($path);
				$archiveName = $base . '.lck-rotated-' . gmdate('Ymd-His');
				$archivePath = $dir . '/' . $archiveName;
				if (!preg_match('/^[A-Za-z0-9._-]+\.lck-rotated-\d{8}-\d{6}$/', $archiveName)) {
					throw new ValidationException('Cannot change the log file. Check permissions.');
				}
				if (file_exists($archivePath)) {
					throw new ValidationException('A rotated copy with that name already exists. Try again.');
				}
				if (!@rename($path, $archivePath)) {
					throw new ValidationException('Could not rename the log file. Check permissions.');
				}
			}

			if (!@touch($path)) {
				$fh = @fopen($path, 'cb');
				if ($fh === false) {
					throw new ValidationException('Could not create a new empty log file. Check permissions.');
				}
				fclose($fh);
			}
			@chmod($path, 0640);

			$this->cursorStore->initializeAtEof($path);
			$stat = @stat($path);
			$size = is_array($stat) ? (int)$stat['size'] : 0;

			$this->auditService->log($uid, 'log_start_fresh', [
				'archive' => $archiveName !== '' ? $archiveName : null,
				'live' => basename($path),
			]);

			return [
				'ok' => true,
				'archive_name' => $archiveName,
				'name' => basename($path),
				'size' => $size,
			];
		} finally {
			$this->leaseService->release($owner);
		}
	}

	/**
	 * Delete the live log file. Prefer startFresh() when evidence should be kept.
	 *
	 * @return array{ok: true, name: string}
	 */
	public function deleteLog(string $uid, string $confirm): array
	{
		$this->assertNcAdmin($uid);
		if ($confirm !== self::CONFIRM_DELETE) {
			throw new ValidationException(
				'Type DELETE to confirm.',
				['confirm' => 'Type DELETE to confirm.'],
			);
		}

		$this->logBackend->assertFileBackend();
		$path = $this->logBackend->resolveLogPath();
		$this->assertConfiguredPath($path);

		$owner = $this->leaseOwner('delete', $uid);
		if (!$this->leaseService->acquire($owner)) {
			throw new ValidationException(
				'LogCheck is busy checking the log. Try again in a moment.',
				[],
				'LCK_BUSY',
			);
		}

		try {
			$name = basename($path);
			if (is_file($path)) {
				if (!is_writable($path)) {
					throw new ValidationException('Cannot delete the log file. Check permissions.');
				}
				if (!@unlink($path)) {
					throw new ValidationException('Could not delete the log file. Check permissions.');
				}
			}
			$this->cursorStore->upsert([
				'path' => $path,
				'offset' => 0,
				'size' => 0,
				'inode' => '',
				'fingerprint' => hash('sha256', $path . '||0'),
			]);

			$this->auditService->log($uid, 'log_deleted', [
				'name' => $name,
			]);

			return ['ok' => true, 'name' => $name];
		} finally {
			$this->leaseService->release($owner);
		}
	}

	/**
	 * Delete an older allowlisted copy (rotated / start-fresh archive). Never the live file.
	 * Does not touch the watch cursor.
	 *
	 * @return array{ok: true, name: string}
	 */
	public function deleteCopy(string $uid, string $confirm, string $fileId): array
	{
		$this->assertNcAdmin($uid);
		if ($confirm !== self::CONFIRM_DELETE_COPY) {
			throw new ValidationException(
				'Type DELETE_COPY to confirm.',
				['confirm' => 'Type DELETE_COPY to confirm.'],
			);
		}

		$resolved = $this->resolveAllowlistedFile($fileId);
		if ($resolved['is_live']) {
			throw new ValidationException(
				'Use “Delete log file” for the current log.',
				['file' => 'Use “Delete log file” for the current log.'],
			);
		}

		$path = $resolved['path'];
		if (!is_file($path)) {
			throw new ValidationException('This log copy is no longer available.');
		}
		if (!is_writable($path)) {
			throw new ValidationException('Cannot delete this log copy. Check permissions.');
		}
		if (!@unlink($path)) {
			throw new ValidationException('Could not delete this log copy.');
		}

		$this->auditService->log($uid, 'log_copy_deleted', [
			'name' => $resolved['id'],
			'role' => $resolved['role'],
		]);

		return ['ok' => true, 'name' => $resolved['id']];
	}

	private function assertNcAdmin(string $uid): void
	{
		if ($uid === '' || !$this->accessService->isNcAdmin($uid)) {
			throw new ForbiddenException('Not authorized.');
		}
	}

	/**
	 * @return array{path: string, id: string, role: string, is_live: bool}
	 */
	private function resolveReadableFile(?string $fileId): array
	{
		$resolved = $this->resolveAllowlistedFile($fileId);
		$path = $resolved['path'];
		if (!is_file($path) || !is_readable($path)) {
			throw new ValidationException('Cannot read the log file. Check permissions.');
		}
		return $resolved;
	}

	/**
	 * Resolve optional basename id to an allowlisted file under the live log directory.
	 *
	 * @return array{path: string, id: string, role: string, is_live: bool}
	 */
	private function resolveAllowlistedFile(?string $fileId): array
	{
		$this->logBackend->assertFileBackend();
		$livePath = $this->logBackend->resolveLogPath();
		$this->assertConfiguredPath($livePath);
		$base = basename($livePath);

		$id = ($fileId === null || $fileId === '') ? $base : $fileId;
		$this->assertSafeFileId($id);
		if (!$this->isAllowlistedBasename($base, $id)) {
			throw new ForbiddenException('Not authorized.');
		}

		if ($id === $base) {
			return [
				'path' => $livePath,
				'id' => $base,
				'role' => 'live',
				'is_live' => true,
			];
		}

		$dir = $this->resolveLogDirectory($livePath);
		$dirPrefix = rtrim($dir, '/') . '/';
		$candidate = $dirPrefix . $id;
		if (!is_file($candidate)) {
			throw new ValidationException('This log copy is no longer available.');
		}
		$real = realpath($candidate);
		if ($real === false || !is_file($real) || !str_starts_with($real, $dirPrefix)) {
			throw new ForbiddenException('Not authorized.');
		}
		if (basename($real) !== $id) {
			throw new ForbiddenException('Not authorized.');
		}

		return [
			'path' => $real,
			'id' => $id,
			'role' => str_contains($id, '.lck-rotated-') ? 'archive' : 'rotated',
			'is_live' => false,
		];
	}

	private function assertSafeFileId(string $id): void
	{
		if ($id === '' || strlen($id) > self::FILE_ID_MAX_LEN) {
			throw new ForbiddenException('Not authorized.');
		}
		if (str_contains($id, "\0") || str_contains($id, '/') || str_contains($id, '\\')) {
			throw new ForbiddenException('Not authorized.');
		}
		if ($id !== basename($id) || $id === '.' || $id === '..') {
			throw new ForbiddenException('Not authorized.');
		}
	}

	public function isAllowlistedBasename(string $liveBase, string $candidate): bool
	{
		if ($candidate === $liveBase) {
			return true;
		}
		$quoted = preg_quote($liveBase, '/');
		$max = self::ROTATE_INDEX_MAX;
		if (preg_match('/^' . $quoted . '\.([1-9]|[1-4][0-9]|' . $max . ')$/', $candidate) === 1) {
			return true;
		}
		return preg_match('/^' . $quoted . '\.lck-rotated-\d{8}-\d{6}$/', $candidate) === 1;
	}

	private function resolveLogDirectory(string $livePath): string
	{
		if (is_file($livePath)) {
			$real = realpath($livePath);
			if ($real === false) {
				throw new ValidationException('Cannot read the log file. Check permissions.');
			}
			return dirname($real);
		}
		$dir = dirname($livePath);
		if (!is_dir($dir)) {
			throw new ValidationException('Cannot read the log file. Check permissions.');
		}
		$realDir = realpath($dir);
		if ($realDir === false) {
			throw new ValidationException('Cannot read the log file. Check permissions.');
		}
		return $realDir;
	}

	/**
	 * @param 'live'|'rotated'|'archive' $role
	 * @return array{
	 *   id: string,
	 *   name: string,
	 *   role: string,
	 *   is_live: bool,
	 *   size: int,
	 *   mtime: int|null,
	 *   readable: bool,
	 *   exists: bool
	 * }
	 */
	private function describeFile(string $path, string $id, string $role, bool $isLive): array
	{
		$exists = is_file($path);
		$size = 0;
		$mtime = null;
		$readable = false;
		if ($exists) {
			$stat = @stat($path);
			if ($stat !== false) {
				$size = (int)$stat['size'];
				$mtime = (int)($stat['mtime'] ?? 0);
			}
			$readable = is_readable($path);
		}

		return [
			'id' => $id,
			'name' => $id,
			'role' => $role,
			'is_live' => $isLive,
			'size' => $size,
			'mtime' => $mtime,
			'readable' => $readable,
			'exists' => $exists,
		];
	}

	/**
	 * Ensure we only ever touch the systemconfig logfile (or its non-existent path string).
	 * Rejects path tricks: null bytes, relative segments after resolve.
	 */
	private function assertConfiguredPath(string $path): void
	{
		if ($path === '' || str_contains($path, "\0")) {
			throw new ValidationException('Cannot read the log file. Check permissions.');
		}
		$expected = $this->logBackend->resolveLogPath();
		if ($path !== $expected) {
			throw new ForbiddenException('Not authorized.');
		}
		if (is_file($path)) {
			$real = realpath($path);
			if ($real === false || !is_file($real)) {
				throw new ValidationException('Cannot read the log file. Check permissions.');
			}
			$dataDir = (string)$this->config->getSystemValue('datadirectory', '');
			$dataReal = $dataDir !== '' ? realpath($dataDir) : false;
			if (is_string($dataReal) && $dataReal !== '') {
				$prefix = rtrim($dataReal, '/') . '/';
				if (!str_starts_with($real, $prefix) && $real !== $dataReal) {
					$expectedReal = realpath($expected);
					if ($expectedReal === false || $real !== $expectedReal) {
						throw new ForbiddenException('Not authorized.');
					}
				}
			}
		}
	}

	/**
	 * @param list<array{text: string, clipped: bool}> $lines
	 * @return array{
	 *   lines: list<array{text: string, clipped: bool}>,
	 *   viewer_min_level: int,
	 *   filter_active: bool,
	 *   lines_matched: int
	 * }
	 */
	private function applyViewerFilter(array $lines, int $viewerMinLevel, int $maxLines): array
	{
		$viewerMinLevel = LogLineLevel::clampViewerMinLevel($viewerMinLevel);
		$minNc = LogLineLevel::minNcLevelForViewer($viewerMinLevel);
		if ($minNc === null) {
			if (count($lines) > $maxLines) {
				$lines = array_slice($lines, -$maxLines);
			}
			return array_merge(
				['lines' => $lines],
				$this->viewerFilterMeta($lines, $viewerMinLevel),
			);
		}

		$filtered = [];
		foreach ($lines as $line) {
			if (LogLineLevel::lineMatchesViewer($line['text'], $viewerMinLevel)) {
				$filtered[] = $line;
			}
		}
		if (count($filtered) > $maxLines) {
			$filtered = array_slice($filtered, -$maxLines);
		}

		return [
			'lines' => $filtered,
			'viewer_min_level' => $viewerMinLevel,
			'filter_active' => true,
			'lines_matched' => count($filtered),
		];
	}

	/**
	 * @param list<array{text: string, clipped: bool}> $lines
	 * @return array{viewer_min_level: int, filter_active: bool, lines_matched: int}
	 */
	private function viewerFilterMeta(array $lines, int $viewerMinLevel): array
	{
		$viewerMinLevel = LogLineLevel::clampViewerMinLevel($viewerMinLevel);
		return [
			'viewer_min_level' => $viewerMinLevel,
			'filter_active' => LogLineLevel::minNcLevelForViewer($viewerMinLevel) !== null,
			'lines_matched' => count($lines),
		];
	}

	/** @return array{text: string, clipped: bool} */
	private function clipLine(string $line): array
	{
		if (strlen($line) <= self::LINE_CLIP_CHARS) {
			return ['text' => $line, 'clipped' => false];
		}
		return [
			'text' => substr($line, 0, self::LINE_CLIP_CHARS) . '…',
			'clipped' => true,
		];
	}

	private function leaseOwner(string $op, string $uid): string
	{
		return 'ui-' . $op . '-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $uid) . '-' . bin2hex(random_bytes(4));
	}
}
