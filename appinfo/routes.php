<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCA\LogCheck\Service\SettingsSectionCatalog;

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'page#home', 'url' => '/home', 'verb' => 'GET'],
		['name' => 'page#logs', 'url' => '/logs', 'verb' => 'GET'],
		['name' => 'page#settingsIndex', 'url' => '/settings', 'verb' => 'GET'],
		// Legacy aliases before {section} so they never collide with the catalog regex.
		['name' => 'page#legacyStatus', 'url' => '/settings/status', 'verb' => 'GET'],
		['name' => 'page#legacyChannels', 'url' => '/settings/channels', 'verb' => 'GET'],
		['name' => 'page#legacyWatch', 'url' => '/settings/watch', 'verb' => 'GET'],
		['name' => 'page#legacyAccess', 'url' => '/settings/access', 'verb' => 'GET'],
		['name' => 'page#legacyPrivacy', 'url' => '/settings/privacy', 'verb' => 'GET'],
		[
			'name' => 'page#settings',
			'url' => '/settings/{section}',
			'verb' => 'GET',
			'requirements' => ['section' => SettingsSectionCatalog::routeRequirement()],
		],

		['name' => 'api#getStatus', 'url' => '/api/status', 'verb' => 'GET'],
		['name' => 'api#getSettings', 'url' => '/api/settings', 'verb' => 'GET'],
		['name' => 'api#saveSettings', 'url' => '/api/settings', 'verb' => 'PUT'],
		['name' => 'api#turnOnAlerts', 'url' => '/api/turn-on-alerts', 'verb' => 'POST'],
		['name' => 'api#testChannel', 'url' => '/api/channels/{channel}/test', 'verb' => 'POST'],
		['name' => 'api#reenableChannel', 'url' => '/api/channels/{channel}/reenable', 'verb' => 'POST'],
		['name' => 'api#runNow', 'url' => '/api/run', 'verb' => 'POST'],
		['name' => 'api#searchDirectory', 'url' => '/api/directory/search', 'verb' => 'GET'],
		['name' => 'api#getLogMeta', 'url' => '/api/logs/meta', 'verb' => 'GET'],
		['name' => 'api#listLogFiles', 'url' => '/api/logs/files', 'verb' => 'GET'],
		['name' => 'api#getLogTail', 'url' => '/api/logs/tail', 'verb' => 'GET'],
		['name' => 'api#getLogBefore', 'url' => '/api/logs/before', 'verb' => 'GET'],
		['name' => 'api#downloadLog', 'url' => '/api/logs/download', 'verb' => 'POST'],
		['name' => 'api#searchLog', 'url' => '/api/logs/search', 'verb' => 'GET'],
		['name' => 'api#startFreshLog', 'url' => '/api/logs/start-fresh', 'verb' => 'POST'],
		['name' => 'api#deleteLog', 'url' => '/api/logs/delete', 'verb' => 'POST'],
		['name' => 'api#deleteLogCopy', 'url' => '/api/logs/delete-copy', 'verb' => 'POST'],
	],
];
