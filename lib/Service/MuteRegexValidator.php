<?php

declare(strict_types=1);

namespace OCA\LogCheck\Service;

use OCA\LogCheck\Exception\ValidationException;

/**
 * Argus MF-04 / SF-02: length cap, structural ReDoS denylist, recursion bans, compile check.
 */
final class MuteRegexValidator
{
	public const MAX_LENGTH = 200;

	public function assertSafe(string $pattern): void
	{
		if ($pattern === '') {
			throw new ValidationException('Invalid mute pattern.', ['value' => 'Invalid mute pattern.'], 'LCK_INVALID_REGEX');
		}
		if (strlen($pattern) > self::MAX_LENGTH) {
			throw new ValidationException('Invalid mute pattern.', ['value' => 'Mute pattern is too long.'], 'LCK_INVALID_REGEX');
		}

		// Recursion / subroutine / comment-callouts — not needed for mute matching.
		if (str_contains($pattern, '(?R)')
			|| str_contains($pattern, '(?0)')
			|| preg_match('/\(\?[+-]?\d+\)/', $pattern) === 1
			|| preg_match('/\(\?\([^)]*\)/', $pattern) === 1) {
			throw new ValidationException('Invalid mute pattern.', ['value' => 'Mute pattern is not allowed.'], 'LCK_INVALID_REGEX');
		}

		// Nested quantifiers: (…+)+, (…*)*, (?:…+){2,}, etc.
		if (preg_match('/\([^)]*[+*][^)]*\)[+*{]/', $pattern) === 1) {
			throw new ValidationException('Invalid mute pattern.', ['value' => 'Mute pattern is not allowed.'], 'LCK_INVALID_REGEX');
		}
		// Quantified character class / dot that is itself quantified again: [a-z]++, .++
		if (preg_match('/(?:\[[^\]]*\]|\.)[+*][+*{]/', $pattern) === 1) {
			throw new ValidationException('Invalid mute pattern.', ['value' => 'Mute pattern is not allowed.'], 'LCK_INVALID_REGEX');
		}
		// Overlapping alternation inside a group then quantified: (a|a)+, (a|ab)*
		if (preg_match('/\((?:[^()]*\|)+[^()]*\)[+*{]/', $pattern) === 1) {
			throw new ValidationException('Invalid mute pattern.', ['value' => 'Mute pattern is not allowed.'], 'LCK_INVALID_REGEX');
		}

		$escaped = str_replace('/', '\\/', $pattern);
		$compiled = @preg_match('/' . $escaped . '/u', '');
		if ($compiled === false) {
			throw new ValidationException('Invalid mute pattern.', ['value' => 'Invalid mute pattern.'], 'LCK_INVALID_REGEX');
		}
	}
}
