<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\LogCheck\Tests\Unit\Service;

use OCA\LogCheck\Service\AuditService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use PHPUnit\Framework\TestCase;

/**
 * Momos C-AUD1: CriticalActionPerformedEvent signature is
 * (string $logMessage, array $parameters = [], bool $obfuscateParameters = false).
 * Passing `false` as the 2nd arg TypeErrors and aborts settings saves that emit audits.
 */
class AuditServiceTest extends TestCase
{
	public function testLogDispatchesEventWithArrayParametersNotBool(): void
	{
		$captured = null;
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects(self::once())
			->method('dispatchTyped')
			->willReturnCallback(static function (object $event) use (&$captured): void {
				$captured = $event;
			});

		$svc = new AuditService($dispatcher);
		$svc->log('admin', 'app_admins_changed', ['count' => 1, 'webhook_url' => 'https://evil.example/hook']);

		self::assertInstanceOf(CriticalActionPerformedEvent::class, $captured);
		self::assertIsArray($captured->getParameters());
		self::assertFalse($captured->getObfuscateParameters());
		self::assertStringContainsString('app_admins_changed', $captured->getLogMessage());
		self::assertStringContainsString('[redacted]', $captured->getLogMessage());
		self::assertStringNotContainsString('evil.example', $captured->getLogMessage());
	}
}
