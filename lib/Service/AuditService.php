<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\LogCheck\Service;

use OCA\LogCheck\AppInfo\Application;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use Psr\Log\LoggerInterface;

/**
 * Emits CriticalActionPerformedEvent with obfuscated details.
 *
 * Also writes the same line through the app logger: the audit event is a
 * no-op on instances without admin_audit enabled, so the event alone left
 * policy mutations (access grants, watch toggles, log deletion) with zero
 * durable record. The app-log line keeps actor + what-changed forever.
 */
final class AuditService
{
	public function __construct(
		private readonly IEventDispatcher $dispatcher,
		private readonly LoggerInterface $logger,
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
		$message = sprintf('HealthCheck %s by %s %s', $action, $actorUid, json_encode($safe, JSON_UNESCAPED_UNICODE));
		// OCP signature: (string $logMessage, array $parameters = [], bool $obfuscateParameters = false).
		// Never pass a bool as the 2nd argument — that TypeErrors and aborts the caller (settings save).
		$this->dispatcher->dispatchTyped(new CriticalActionPerformedEvent($message, []));
		// Durable record independent of admin_audit being installed/enabled.
		$this->logger->warning($message, ['app' => Application::APP_ID]);
	}
}
