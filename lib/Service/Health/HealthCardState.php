<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\LogCheck\Service\Health;

/**
 * Card state vocabulary (HCK-CORE-1 §9). Never invent green — prefer unknown.
 */
final class HealthCardState
{
	public const OK = 'ok';
	public const DEGRADED = 'degraded';
	public const CRITICAL = 'critical';
	public const UNKNOWN = 'unknown';

	/** @return list<string> */
	public static function all(): array
	{
		return [self::OK, self::DEGRADED, self::CRITICAL, self::UNKNOWN];
	}

	public static function isValid(string $state): bool
	{
		return in_array($state, self::all(), true);
	}
}
