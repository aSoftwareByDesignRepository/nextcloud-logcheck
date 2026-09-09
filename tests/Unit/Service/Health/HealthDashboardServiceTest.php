<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Service\Health;

use OCA\LogCheck\Service\Health\DbHealthProbe;
use OCA\LogCheck\Service\Health\DiskHealthProbe;
use OCA\LogCheck\Service\Health\HealthCard;
use OCA\LogCheck\Service\Health\HealthCardState;
use OCA\LogCheck\Service\Health\HealthDashboardService;
use OCA\LogCheck\Service\Health\HttpsHealthProbe;
use OCA\LogCheck\Service\Health\JobsHealthProbe;
use OCA\LogCheck\Service\Health\LogHealthProbe;
use OCA\LogCheck\Service\Health\PhpHealthProbe;
use OCA\LogCheck\Service\Health\UpdatesHealthProbe;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

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

	public function testDashboardAndCardsInvokeProbes(): void
	{
		$probeIds = ['log', 'jobs', 'php', 'db', 'disk', 'https', 'updates'];
		$makeProbe = function (string $class, string $id) {
			$probe = $this->createMock($class);
			$probe->method('id')->willReturn($id);
			$probe->expects(self::atLeastOnce())->method('probe')->willReturn(
				new HealthCard($id, ucfirst($id), HealthCardState::OK, 'ok'),
			);
			return $probe;
		};

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $m) => $m);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$logger = $this->createMock(LoggerInterface::class);

		$svc = new HealthDashboardService(
			$makeProbe(LogHealthProbe::class, 'log'),
			$makeProbe(JobsHealthProbe::class, 'jobs'),
			$makeProbe(PhpHealthProbe::class, 'php'),
			$makeProbe(DbHealthProbe::class, 'db'),
			$makeProbe(DiskHealthProbe::class, 'disk'),
			$makeProbe(HttpsHealthProbe::class, 'https'),
			$makeProbe(UpdatesHealthProbe::class, 'updates'),
			$logger,
			$factory,
		);

		$cards = $svc->cards();
		self::assertCount(7, $cards);
		self::assertSame($probeIds, array_column($cards, 'id'));

		$dash = $svc->dashboard();
		self::assertSame(HealthCardState::OK, $dash['summary_state']);
		self::assertCount(7, $dash['cards']);
		self::assertSame('Everything looks fine', $dash['summary_label']);
	}
}
