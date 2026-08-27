<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Service\Health;

use OCA\LogCheck\Service\Health\HealthCardState;
use OCA\LogCheck\Service\Health\HealthDashboardService;
use OCA\LogCheck\Service\Health\HealthProbeInterface;
use OCA\LogCheck\Service\Health\HealthCard;
use PHPUnit\Framework\TestCase;

class HealthDashboardServiceTest extends TestCase
{
	public function testSummarizePicksWorstState(): void
	{
		self::assertSame(HealthCardState::CRITICAL, HealthDashboardService::summarize([
			['state' => 'ok'],
			['state' => 'critical'],
			['state' => 'degraded'],
		]));
		self::assertSame(HealthCardState::DEGRADED, HealthDashboardService::summarize([
			['state' => 'ok'],
			['state' => 'unknown'],
			['state' => 'degraded'],
		]));
		self::assertSame(HealthCardState::OK, HealthDashboardService::summarize([
			['state' => 'ok'],
			['state' => 'ok'],
		]));
		self::assertSame(HealthCardState::UNKNOWN, HealthDashboardService::summarize([]));
	}
}
