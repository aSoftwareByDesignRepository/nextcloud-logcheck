<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Service;

use OCA\LogCheck\Service\TopologyGuard;
use PHPUnit\Framework\TestCase;

class TopologyGuardTest extends TestCase
{
	public function testNoMismatchWhenUnset(): void
	{
		$g = new TopologyGuard();
		self::assertFalse($g->isMismatch([]));
		self::assertFalse($g->isMismatch(['watcher_node' => null]));
	}

	public function testMismatchWhenDifferentNodeStored(): void
	{
		$g = new TopologyGuard();
		self::assertTrue($g->isMismatch(['watcher_node' => 'not-this-host-' . bin2hex(random_bytes(8))]));
	}

	public function testNoMismatchWhenSameNode(): void
	{
		$g = new TopologyGuard();
		$id = $g->currentNodeId();
		self::assertFalse($g->isMismatch(['watcher_node' => $id]));
	}
}
