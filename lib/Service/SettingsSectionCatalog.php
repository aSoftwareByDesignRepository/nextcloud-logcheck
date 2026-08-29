<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\LogCheck\Service;

use OCP\IL10N;

/**
 * Single source of truth for HealthCheck settings sections (Bachus IA).
 */
final class SettingsSectionCatalog
{
	public const DEFAULT_SECTION = 'alerts';

	/** @var list<string> */
	public const SECTIONS = [
		'alerts',
		'rules',
		'people',
		'support',
	];

	/**
	 * Legacy settings slugs → current section (or home for status).
	 *
	 * @var array<string, string>
	 */
	public const LEGACY_ALIASES = [
		'status' => 'home',
		'channels' => 'alerts',
		'watch' => 'rules',
		'access' => 'people',
		'privacy' => 'alerts',
	];

	public function isSection(string $section): bool
	{
		return in_array($section, self::SECTIONS, true);
	}

	public static function routeRequirement(): string
	{
		return implode('|', self::SECTIONS);
	}

	public function label(IL10N $l, string $section): string
	{
		return match ($section) {
			'alerts' => $l->t('Alerts'),
			'rules' => $l->t('Rules'),
			'people' => $l->t('People'),
			'support' => $l->t('Support us'),
			default => $l->t('Settings'),
		};
	}

	public function navLabel(IL10N $l, string $section): string
	{
		return match ($section) {
			'alerts' => $l->t('Alerts'),
			'rules' => $l->t('Rules'),
			'people' => $l->t('People'),
			'support' => $l->t('Support us'),
			default => $l->t('Settings'),
		};
	}

	public function help(IL10N $l, string $section): string
	{
		return match ($section) {
			'alerts' => $l->t('Choose how HealthCheck notifies you when new errors appear.'),
			'rules' => $l->t('Pick how serious an issue must be and how often alerts arrive.'),
			'people' => $l->t('Who can open HealthCheck — Nextcloud admins always can; add app admins below.'),
			'support' => $l->t('Help keep HealthCheck free — donations and enterprise contact.'),
			default => '',
		};
	}
}
