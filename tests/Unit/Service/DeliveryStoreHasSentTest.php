<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\LogCheck\Tests\Unit\Service;

use OCA\LogCheck\Service\DeliveryStore;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Momos / Zeus SF-D1: hasSent must be true only for status=sent rows.
 */
class DeliveryStoreHasSentTest extends TestCase
{
	public function testHasSentTrueWhenRowExists(): void
	{
		$store = $this->storeWithFetch(['event_id' => 'e1']);
		self::assertTrue($store->hasSent('e1', 'email'));
	}

	public function testHasSentFalseWhenEmpty(): void
	{
		$store = $this->storeWithFetch(false);
		self::assertFalse($store->hasSent('e1', 'email'));
	}

	/** @param array<string, mixed>|false $fetch */
	private function storeWithFetch(array|false $fetch): DeliveryStore
	{
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn($fetch);
		$result->method('closeCursor')->willReturn(true);

		$expr = new class {
			public function eq(...$a): string
			{
				return 'eq';
			}
		};
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($v) => $v);
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		return new DeliveryStore($db);
	}
}
