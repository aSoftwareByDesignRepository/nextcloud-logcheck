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
use Psr\Log\LoggerInterface;

/**
 * Momos C-AUD1: CriticalActionPerformedEvent signature is
 * (string $logMessage, array $parameters = [], bool $obfuscateParameters = false).
 * Passing `false` as the 2nd arg TypeErrors and aborts settings saves that emit audits.
 *
 * The event alone evaporates when admin_audit is disabled (policy_mutation_no_audit
 * class) — AuditService must also write the same redacted line via LoggerInterface.
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

		$svc = new AuditService($dispatcher, $this->createMock(LoggerInterface::class));
		$svc->log('admin', 'app_admins_changed', ['count' => 1, 'webhook_url' => 'https://evil.example/hook']);

		self::assertInstanceOf(CriticalActionPerformedEvent::class, $captured);
		self::assertIsArray($captured->getParameters());
		self::assertFalse($captured->getObfuscateParameters());
		self::assertStringContainsString('app_admins_changed', $captured->getLogMessage());
		self::assertStringContainsString('[redacted]', $captured->getLogMessage());
		self::assertStringNotContainsString('evil.example', $captured->getLogMessage());
	}

	public function testLogWritesDurableAppLogLine(): void
	{
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$logged = null;
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('warning')
			->willReturnCallback(static function (string $message, array $context = []) use (&$logged): void {
				$logged = [$message, $context];
			});

		$svc = new AuditService($dispatcher, $logger);
		$svc->log('admin', 'watch_toggled', ['enabled' => 1, 'url' => 'https://evil.example/hook']);

		self::assertIsArray($logged);
		self::assertStringContainsString('watch_toggled', $logged[0]);
		self::assertStringContainsString('admin', $logged[0]);
		self::assertStringContainsString('[redacted]', $logged[0]);
		self::assertStringNotContainsString('evil.example', $logged[0]);
		self::assertSame('logcheck', $logged[1]['app'] ?? null);
	}
}
