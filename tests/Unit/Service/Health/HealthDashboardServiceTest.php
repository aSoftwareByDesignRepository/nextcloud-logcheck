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
use OCA\LogCheck\Service\SettingsService;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class HealthDashboardServiceTest extends TestCase
{
	private function settings(array $disabled = []): SettingsService
	{
		$svc = $this->createMock(SettingsService::class);
		$svc->method('getRawSettings')->willReturn(['health_disabled_probes' => $disabled]);
		return $svc;
	}

	private function factory(): IFactory
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $m) => $m);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		return $factory;
	}

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

		$svc = new HealthDashboardService(
			$makeProbe(LogHealthProbe::class, 'log'),
			$makeProbe(JobsHealthProbe::class, 'jobs'),
			$makeProbe(PhpHealthProbe::class, 'php'),
			$makeProbe(DbHealthProbe::class, 'db'),
			$makeProbe(DiskHealthProbe::class, 'disk'),
			$makeProbe(HttpsHealthProbe::class, 'https'),
			$makeProbe(UpdatesHealthProbe::class, 'updates'),
			$this->settings(),
			$this->createMock(LoggerInterface::class),
			$this->factory(),
		);

		$cards = $svc->cards();
		self::assertCount(7, $cards);
		self::assertSame($probeIds, array_column($cards, 'id'));

		$dash = $svc->dashboard();
		self::assertSame(HealthCardState::OK, $dash['summary_state']);
		self::assertCount(7, $dash['cards']);
		self::assertSame('Everything looks fine', $dash['summary_label']);
	}

	/** GitHub #1: admins must be able to turn off checks that cannot run locally. */
	public function testDisabledProbeIsSkipped(): void
	{
		$makeProbe = function (string $class, string $id, bool $expectRun) {
			$probe = $this->createMock($class);
			$probe->method('id')->willReturn($id);
			$probe->expects($expectRun ? self::atLeastOnce() : self::never())
				->method('probe')
				->willReturn(new HealthCard($id, ucfirst($id), HealthCardState::OK, 'ok'));
			return $probe;
		};

		$svc = new HealthDashboardService(
			$makeProbe(LogHealthProbe::class, 'log', true),
			$makeProbe(JobsHealthProbe::class, 'jobs', true),
			$makeProbe(PhpHealthProbe::class, 'php', true),
			$makeProbe(DbHealthProbe::class, 'db', true),
			$makeProbe(DiskHealthProbe::class, 'disk', true),
			$makeProbe(HttpsHealthProbe::class, 'https', false),
			$makeProbe(UpdatesHealthProbe::class, 'updates', true),
			$this->settings(['https']),
			$this->createMock(LoggerInterface::class),
			$this->factory(),
		);

		$cards = $svc->cards();
		self::assertCount(6, $cards);
		self::assertNotContains('https', array_column($cards, 'id'));
	}

	/** A broken settings read must not blank the dashboard — probes still run. */
	public function testBrokenSettingsReadStillRunsProbes(): void
	{
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRawSettings')->willThrowException(new \RuntimeException('db gone'));
		$probe = $this->createMock(LogHealthProbe::class);
		$probe->method('id')->willReturn('log');
		$probe->expects(self::once())->method('probe')->willReturn(
			new HealthCard('log', 'Log', HealthCardState::OK, 'ok'),
		);
		$idle = function (string $class, string $id) {
			$p = $this->createMock($class);
			$p->method('id')->willReturn($id);
			$p->method('probe')->willReturn(new HealthCard($id, ucfirst($id), HealthCardState::OK, 'ok'));
			return $p;
		};

		$svc = new HealthDashboardService(
			$probe,
			$idle(JobsHealthProbe::class, 'jobs'),
			$idle(PhpHealthProbe::class, 'php'),
			$idle(DbHealthProbe::class, 'db'),
			$idle(DiskHealthProbe::class, 'disk'),
			$idle(HttpsHealthProbe::class, 'https'),
			$idle(UpdatesHealthProbe::class, 'updates'),
			$settings,
			$this->createMock(LoggerInterface::class),
			$this->factory(),
		);

		self::assertCount(7, $svc->cards());
	}
}
