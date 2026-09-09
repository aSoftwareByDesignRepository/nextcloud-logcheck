<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Service\Health;

use OCA\LogCheck\Service\Health\DbHealthProbe;
use OCA\LogCheck\Service\Health\HealthCardState;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

/**
 * Critic SF: invoke DbHealthProbe::id/probe (not Health/* blanket).
 */
final class DbHealthProbeTest extends TestCase
{
	private function l10nFactory(): IFactory
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $m, array $a = []) => $m);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		return $factory;
	}

	public function testIdAndProbeOk(): void
	{
		$result = $this->createMock(IResult::class);
		$result->expects(self::once())->method('closeCursor');
		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::once())->method('executeQuery')->with('SELECT 1')->willReturn($result);
		$url = $this->createMock(IURLGenerator::class);

		$probe = new DbHealthProbe($db, $this->l10nFactory(), $url);
		self::assertSame('db', $probe->id());
		$card = $probe->probe();
		self::assertSame(HealthCardState::OK, $card->state);
		self::assertSame('db', $card->id);
	}

	public function testProbeCriticalWhenDbThrows(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willThrowException(new \RuntimeException('down'));
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('/settings/admin/overview');

		$card = (new DbHealthProbe($db, $this->l10nFactory(), $url))->probe();
		self::assertSame(HealthCardState::CRITICAL, $card->state);
		self::assertNotSame([], $card->actions);
	}
}
