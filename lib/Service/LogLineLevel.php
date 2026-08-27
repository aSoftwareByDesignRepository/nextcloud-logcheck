<?php

declare(strict_types=1);

namespace OCA\LogCheck\Service;

/**
 * Nextcloud admin log JSON level helpers for viewer-only severity filtering (C-58).
 *
 * NC numeric levels: 0=Debug, 1=Info, 2=Warning, 3=Error, 4=Fatal.
 * Viewer chips: 0=All, 3=Warnings+, 4=Errors+ (optional 2=Info+, 5=Fatal only).
 */
final class LogLineLevel
{
	public const VIEWER_ALL = 0;
	public const VIEWER_INFO = 2;
	public const VIEWER_WARN = 3;
	public const VIEWER_ERROR = 4;
	public const VIEWER_FATAL = 5;

	/** @return int 0–4 NC level, -1 unknown / non-JSON */
	public static function ncLevelFromLine(string $line): int
	{
		$line = trim($line);
		if ($line === '' || !str_starts_with($line, '{')) {
			return -1;
		}
		try {
			/** @var mixed $data */
			$data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return -1;
		}
		if (!is_array($data)) {
			return -1;
		}
		if (isset($data['level']) && is_numeric($data['level'])) {
			return max(0, min(4, (int)$data['level']));
		}
		if (isset($data['level']) && is_string($data['level'])) {
			return self::ncLevelFromName($data['level']);
		}
		return -1;
	}

	public static function ncLevelFromName(string $name): int
	{
		return match (strtolower(trim($name))) {
			'debug' => 0,
			'info' => 1,
			'warning', 'warn' => 2,
			'error' => 3,
			'fatal' => 4,
			default => -1,
		};
	}

	/** Minimum NC level for viewer chip, null = no filter (All). */
	public static function minNcLevelForViewer(int $viewerMinLevel): ?int
	{
		return match ($viewerMinLevel) {
			self::VIEWER_INFO => 1,
			self::VIEWER_WARN => 2,
			self::VIEWER_ERROR => 3,
			self::VIEWER_FATAL => 4,
			default => null,
		};
	}

	public static function lineMatchesViewer(string $line, int $viewerMinLevel): bool
	{
		$min = self::minNcLevelForViewer($viewerMinLevel);
		if ($min === null) {
			return true;
		}
		$level = self::ncLevelFromLine($line);
		if ($level < 0) {
			return false;
		}
		return $level >= $min;
	}

	public static function clampViewerMinLevel(int $viewerMinLevel): int
	{
		if ($viewerMinLevel < self::VIEWER_ALL) {
			return self::VIEWER_ALL;
		}
		if ($viewerMinLevel > self::VIEWER_FATAL) {
			return self::VIEWER_FATAL;
		}
		return $viewerMinLevel;
	}
}
