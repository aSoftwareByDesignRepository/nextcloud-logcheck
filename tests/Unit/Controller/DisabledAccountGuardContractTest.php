<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Family rule: people pickers never offer deactivated Nextcloud accounts.
 */
final class DisabledAccountGuardContractTest extends TestCase
{
	public function testUserSearchSkipsDeactivatedInBothLoops(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/ApiController.php');
		$guardCount = preg_match_all('/if \(!\$user->isEnabled\(\)\)/', $src);
		$this->assertSame(
			2,
			$guardCount,
			'ApiController user search must skip deactivated accounts in the display-name loop AND the id fallback loop'
		);
	}
}
