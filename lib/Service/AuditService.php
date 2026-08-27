<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\LogCheck\Service;

use OCP\EventDispatcher\IEventDispatcher;
use OCP\Log\Audit\CriticalActionPerformedEvent;

/**
 * Emits CriticalActionPerformedEvent with obfuscated details.
 */
final class AuditService
{
	public function __construct(
		private readonly IEventDispatcher $dispatcher,
	) {
	}

	/**
	 * @param array<string, scalar|null> $details
	 */
	public function log(string $actorUid, string $action, array $details = []): void
	{
		$safe = [];
		foreach ($details as $k => $v) {
			if (is_string($v) && (str_contains(strtolower($k), 'url') || str_contains(strtolower($k), 'secret'))) {
				$safe[$k] = '[redacted]';
			} else {
				$safe[$k] = $v;
			}
		}
		$message = sprintf('LogCheck %s by %s %s', $action, $actorUid, json_encode($safe, JSON_UNESCAPED_UNICODE));
		// OCP signature: (string $logMessage, array $parameters = [], bool $obfuscateParameters = false).
		// Never pass a bool as the 2nd argument — that TypeErrors and aborts the caller (settings save).
		$this->dispatcher->dispatchTyped(new CriticalActionPerformedEvent($message, []));
	}
}
