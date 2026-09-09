<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Controller;

use OCA\LogCheck\Controller\PageController;
use OCA\LogCheck\Service\AccessService;
use OCA\LogCheck\Service\Health\HealthDashboardService;
use OCA\LogCheck\Service\LogFileService;
use OCA\LogCheck\Service\SettingsSectionCatalog;
use OCA\LogCheck\Service\SettingsService;
use OCA\LogCheck\Service\StatusService;
use OCA\LogCheck\Support\SupportUsLinks;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Critic MF inv-false-legacy-pages: invoke settingsIndex + legacy* redirects (not source-scan).
 */
final class PageControllerLegacyRedirectTest extends TestCase
{
	private function controller(IURLGenerator $urls): PageController
	{
		return new PageController(
			$this->createMock(IRequest::class),
			$urls,
			$this->createMock(IUserSession::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IL10N::class),
			$this->createMock(AccessService::class),
			$this->createMock(StatusService::class),
			$this->createMock(HealthDashboardService::class),
			$this->createMock(SettingsService::class),
			new SettingsSectionCatalog(),
			$this->createMock(SupportUsLinks::class),
			$this->createMock(LogFileService::class),
		);
	}

	public function testSettingsIndexRedirectsToDefaultSection(): void
	{
		$urls = $this->createMock(IURLGenerator::class);
		$urls->expects(self::once())
			->method('linkToRoute')
			->with('logcheck.page.settings', ['section' => SettingsSectionCatalog::DEFAULT_SECTION])
			->willReturn('/apps/logcheck/settings/alerts');

		$res = $this->controller($urls)->settingsIndex();
		self::assertInstanceOf(RedirectResponse::class, $res);
		self::assertSame('/apps/logcheck/settings/alerts', $res->getRedirectURL());
	}

	/** @return list<array{0: string, 1: string, 2: array{section: string}|array{}, 3: string}> */
	public static function legacyRedirectCases(): array
	{
		return [
			['legacyStatus', 'logcheck.page.home', [], '/apps/logcheck/home'],
			['legacyChannels', 'logcheck.page.settings', ['section' => 'alerts'], '/apps/logcheck/settings/alerts'],
			['legacyWatch', 'logcheck.page.settings', ['section' => 'rules'], '/apps/logcheck/settings/rules'],
			['legacyAccess', 'logcheck.page.settings', ['section' => 'people'], '/apps/logcheck/settings/people'],
			['legacyPrivacy', 'logcheck.page.settings', ['section' => 'alerts'], '/apps/logcheck/settings/alerts'],
		];
	}

	/** @dataProvider legacyRedirectCases */
	public function testLegacyAliasInvokesRedirect(string $method, string $route, array $params, string $target): void
	{
		$urls = $this->createMock(IURLGenerator::class);
		$urls->expects(self::once())
			->method('linkToRoute')
			->with($route, $params)
			->willReturn($target);

		$res = $this->controller($urls)->{$method}();
		self::assertInstanceOf(RedirectResponse::class, $res);
		$expected = $method === 'legacyPrivacy' ? $target . '#lck-more-options' : $target;
		self::assertSame($expected, $res->getRedirectURL());
	}
}
