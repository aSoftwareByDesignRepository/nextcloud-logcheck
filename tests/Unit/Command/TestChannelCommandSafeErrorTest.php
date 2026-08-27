<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\LogCheck\Tests\Unit\Command;

use OCA\LogCheck\Service\ChannelStateStore;
use PHPUnit\Framework\TestCase;

/**
 * Momos NG-M-OCC1: occ must never echo raw transport diagnostics (webhook hosts, etc.).
 */
class TestChannelCommandSafeErrorTest extends TestCase
{
	public function testFailurePathUsesSafeErrorNotRawExceptionMessage(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Command/TestChannelCommand.php');
		self::assertStringContainsString('ChannelStateStore::safeError($e->getMessage())', $src);
		self::assertStringNotContainsString(
			"writeln('<error>' . \$e->getMessage()",
			$src
		);
		self::assertSame(
			ChannelStateStore::ERR_HTTP,
			ChannelStateStore::safeError('cURL error 7: Failed to connect to 10.66.66.66 port 443')
		);
		self::assertStringNotContainsString(
			'10.66.66.66',
			ChannelStateStore::safeError('cURL error 7: Failed to connect to 10.66.66.66 port 443')
		);
	}
}
