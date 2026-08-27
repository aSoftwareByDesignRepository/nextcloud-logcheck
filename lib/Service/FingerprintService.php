<?php

declare(strict_types=1);

namespace OCA\LogCheck\Service;

/**
 * Zeus MF-06 fingerprint normalization.
 */
final class FingerprintService
{
	public function fingerprint(int $level, string $app, string|array|object $message): string
	{
		$normalized = $this->normalize($message);
		return hash('sha256', $level . "\0" . $app . "\0" . $normalized);
	}

	public function normalize(string|array|object $message): string
	{
		if (is_array($message) || is_object($message)) {
			$encoded = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			$text = is_string($encoded) ? $encoded : '';
		} else {
			$text = $message;
		}

		$text = preg_replace(
			'/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
			'<UUID>',
			$text
		) ?? $text;
		$text = preg_replace(
			'/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?/',
			'<TS>',
			$text
		) ?? $text;
		$text = preg_replace('/\b[0-9a-f]{8,}\b/i', '<HEX>', $text) ?? $text;
		$text = preg_replace('/\d{6,}/', '<NUM>', $text) ?? $text;
		$text = preg_replace('/\s+/u', ' ', $text) ?? $text;
		return trim($text);
	}
}
