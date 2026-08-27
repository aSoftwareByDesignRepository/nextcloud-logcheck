<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Service;

use OCA\LogCheck\Service\LeaseService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * stillHolds semantics via carefully mocked query chain (no live DB).
 */
class LeaseServiceTest extends TestCase
{
	public function testStillHoldsTrueWhenOwnerAndNotExpired(): void
	{
		$svc = $this->serviceReturningRow([
			'owner' => 'owner-a',
			'lease_until' => time() + 60,
		]);
		self::assertTrue($svc->stillHolds('owner-a'));
	}

	public function testStillHoldsFalseWhenDifferentOwner(): void
	{
		$svc = $this->serviceReturningRow([
			'owner' => 'other',
			'lease_until' => time() + 60,
		]);
		self::assertFalse($svc->stillHolds('owner-a'));
	}

	public function testStillHoldsFalseWhenExpired(): void
	{
		$svc = $this->serviceReturningRow([
			'owner' => 'owner-a',
			'lease_until' => time() - 1,
		]);
		self::assertFalse($svc->stillHolds('owner-a'));
	}

	public function testStillHoldsFalseWhenNoRow(): void
	{
		$svc = $this->serviceReturningRow(null);
		self::assertFalse($svc->stillHolds('owner-a'));
	}

	public function testRenewReturnsFalseWhenExecuteAffectsZero(): void
	{
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('update')->willReturnSelf();
		$qb->method('set')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('expr')->willReturn($this->exprMock());
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('executeStatement')->willReturn(0);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$svc = new LeaseService($db);
		self::assertFalse($svc->renew('owner-a'));
	}

	public function testRenewReturnsTrueWhenOwnerMatches(): void
	{
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('update')->willReturnSelf();
		$qb->method('set')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('expr')->willReturn($this->exprMock());
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('executeStatement')->willReturn(1);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$svc = new LeaseService($db);
		self::assertTrue($svc->renew('owner-a'));
	}

	/** @param array{owner: string, lease_until: int}|null $row */
	private function serviceReturningRow(?array $row): LeaseService
	{
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn($row);
		$result->method('closeCursor')->willReturn(true);

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('expr')->willReturn($this->exprMock());
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return new LeaseService($db);
	}

	private function exprMock(): object
	{
		$expr = new class {
			public function eq(...$args): string
			{
				return 'eq';
			}
			public function lt(...$args): string
			{
				return 'lt';
			}
			public function isNull(...$args): string
			{
				return 'isNull';
			}
			public function orX(...$args): string
			{
				return 'orX';
			}
		};
		return $expr;
	}
}
