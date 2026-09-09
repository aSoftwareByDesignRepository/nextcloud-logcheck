<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Service\Health;

use OCA\LogCheck\Service\Health\HealthCardState;
use OCA\LogCheck\Service\Health\PhpHealthProbe;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

/**
 * Critic SF: invoke PhpHealthProbe::id/probe.
 */
final class PhpHealthProbeTest extends TestCase
{
	public function testIdAndProbeReflectsRuntime(): void
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static function (string $m, array $a = []) {
			return $a === [] ? $m : sprintf(str_replace('%s', '%s', $m), ...$a);
		});
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$url = $this->createMock(IURLGenerator::class);

		$probe = new PhpHealthProbe($factory, $url);
		self::assertSame('php', $probe->id());
		$card = $probe->probe();
		self::assertSame('php', $card->id);
		self::assertContains($card->state, [HealthCardState::OK, HealthCardState::DEGRADED]);
		self::assertStringContainsString(PHP_VERSION, $card->label);
	}
}
