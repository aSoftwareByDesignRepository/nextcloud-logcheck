<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\LogCheck\Service;

use OCA\LogCheck\Exception\ValidationException;

/**
 * Parse client scalars without PHP's empty() / (bool) traps.
 *
 * (bool)"false" and !empty("false") are both TRUE in PHP — a form-encoded or
 * JSON string "false" must never silently enable a flag (proven live: the
 * SSRF-scope flag allow_private_webhooks flipped on for input "false").
 */
final class InputCoercion
{
	/**
	 * Strict boolean coercion: real bools, 0/1, and the common string forms
	 * only — every other input is a typed validation error, never truthy.
	 */
	public static function asBool(mixed $value, string $field): bool
	{
		if (is_bool($value)) {
			return $value;
		}
		if (is_int($value) || is_float($value)) {
			if ($value === 1 || $value === 1.0) {
				return true;
			}
			if ($value === 0 || $value === 0.0) {
				return false;
			}
			throw self::invalidBool($field);
		}
		if (is_string($value)) {
			$normalized = strtolower(trim($value));
			if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
				return true;
			}
			if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
				return false;
			}
		}
		throw self::invalidBool($field);
	}

	/**
	 * Strict integer coercion: ints and digit strings only — 'banana' must
	 * never silently become 0 via (int) casting.
	 */
	public static function asInt(mixed $value, string $field): int
	{
		if (is_int($value)) {
			return $value;
		}
		if (is_float($value)) {
			if ($value === (float)(int)$value) {
				return (int)$value;
			}
			throw self::invalidInt($field);
		}
		if (is_string($value)) {
			$trimmed = trim($value);
			if ($trimmed !== '' && preg_match('/^[+-]?\d+$/', $trimmed) === 1) {
				return (int)$trimmed;
			}
		}
		throw self::invalidInt($field);
	}

	/**
	 * Strict text coercion: arrays/objects must never collapse into the
	 * literal string "Array" / "Object" and persist as garbage values.
	 */
	public static function asString(mixed $value, string $field): string
	{
		if (is_string($value)) {
			return $value;
		}
		if (is_int($value) || is_float($value) || is_bool($value)) {
			return (string)$value;
		}
		throw new ValidationException(
			'Invalid value.',
			[$field => 'Must be text.'],
			'LCK_VALIDATION',
		);
	}

	private static function invalidBool(string $field): ValidationException
	{
		return new ValidationException(
			'Invalid value.',
			[$field => 'Must be true or false.'],
			'LCK_VALIDATION',
		);
	}

	private static function invalidInt(string $field): ValidationException
	{
		return new ValidationException(
			'Invalid value.',
			[$field => 'Must be a whole number.'],
			'LCK_VALIDATION',
		);
	}
}
