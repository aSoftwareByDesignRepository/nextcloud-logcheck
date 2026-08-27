<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\LogCheck\Service\Health;

use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * One honest recovery link for non-ok health cards (MH-B05 / F-08).
 */
final class AdminRecoveryAction
{
	/**
	 * @return list<array{id: string, label: string, href: string|null}>
	 */
	public static function adminSection(IURLGenerator $url, IL10N $l10n, string $id, string $section, string $label): array
	{
		return [[
			'id' => $id,
			'label' => $label,
			'href' => $url->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => $section]),
		]];
	}
}
