<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Templates;

use PHPUnit\Framework\TestCase;

/**
 * Render contract for the sidebar chrome (templates/common/navigation.php):
 * every SettingsSectionCatalog section must be reachable from the SETTINGS
 * group — lck intake 20261124T235946Z: 'transfer' page existed (route +
 * section catalog + urls) but the sidebar hardcoded four items, so the page
 * was only reachable via the in-page chip bar or a direct URL.
 */
final class NavigationRenderTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		require_once dirname(__DIR__) . '/Support/template_stubs.php';
	}

	public function testSettingsGroupListsAllSectionsInCatalogOrder(): void
	{
		$html = $this->render('transfer');

		// 2 watch + 5 settings items.
		self::assertSame(7, substr_count($html, 'lck-nav__item'));

		foreach (['Alerts', 'Rules', 'People', 'Transfer', 'Support us'] as $label) {
			self::assertStringContainsString('>' . $label . '<', $html);
		}

		// Catalog order: transfer sits between people and support.
		$people = strpos($html, '>People<');
		$transfer = strpos($html, '>Transfer<');
		$support = strpos($html, '>Support us<');
		self::assertNotFalse($people);
		self::assertNotFalse($transfer);
		self::assertNotFalse($support);
		self::assertTrue($people < $transfer && $transfer < $support);
	}

	public function testTransferItemLinksToTransferUrlWithHintAndIcon(): void
	{
		$html = $this->render('transfer', ['transfer' => '/apps/logcheck/settings/transfer']);

		$li = $this->itemHtml($html, 'Transfer');
		self::assertStringContainsString('href="/apps/logcheck/settings/transfer"', $li);
		self::assertStringContainsString('Test ↔ production copy', $li);
		self::assertStringContainsString('<svg', $li);
	}

	public function testTransferActiveStateIsExclusive(): void
	{
		$html = $this->render('transfer');

		$li = $this->itemHtml($html, 'Transfer');
		self::assertStringContainsString('lck-nav__link is-active', $li);
		self::assertStringContainsString('aria-current="page"', $li);
		self::assertSame(1, substr_count($html, 'aria-current="page"'));

		foreach (['Alerts', 'Rules', 'People', 'Support us'] as $other) {
			self::assertStringNotContainsString('aria-current', $this->itemHtml($html, $other));
		}
	}

	public function testTransferInactiveWhenOtherSectionActive(): void
	{
		$html = $this->render('alerts');

		$li = $this->itemHtml($html, 'Transfer');
		self::assertStringNotContainsString('is-active', $li);
		self::assertStringNotContainsString('aria-current', $li);

		self::assertStringContainsString('aria-current="page"', $this->itemHtml($html, 'Alerts'));
		self::assertSame(1, substr_count($html, 'aria-current="page"'));
	}

	public function testTransferFallsBackToHashWhenUrlMissing(): void
	{
		$html = $this->render('transfer', ['transfer' => null]);
		self::assertStringContainsString('href="#"', $this->itemHtml($html, 'Transfer'));
	}

	/**
	 * @param array<string, string|null> $urlOverrides
	 */
	private function render(string $settingsSection, array $urlOverrides = []): string
	{
		$l = new class {
			public function getLanguageCode(): string
			{
				return 'en';
			}

			/** @param array<int, mixed> $parameters */
			public function t(string $text, array $parameters = []): string
			{
				return $text;
			}
		};

		$urls = array_merge([
			'home' => '/apps/logcheck',
			'logs' => '/apps/logcheck/logs',
			'alerts' => '/apps/logcheck/settings/alerts',
			'rules' => '/apps/logcheck/settings/rules',
			'people' => '/apps/logcheck/settings/people',
			'transfer' => '/apps/logcheck/settings/transfer',
			'support' => '/apps/logcheck/settings/support',
		], $urlOverrides);

		$_ = [
			'urls' => $urls,
			'pageId' => 'settings',
			'settingsSection' => $settingsSection,
			'roleLabel' => 'Admin',
		];
		ob_start();
		include dirname(__DIR__, 3) . '/templates/common/navigation.php';
		return (string)ob_get_clean();
	}

	/**
	 * Extracts the <li> block carrying the given nav label.
	 */
	private function itemHtml(string $html, string $label): string
	{
		$pos = strpos($html, '>' . $label . '<');
		self::assertNotFalse($pos, "nav label {$label} not rendered");
		$start = strrpos(substr($html, 0, $pos), '<li class="lck-nav__item');
		$end = strpos($html, '</li>', $pos);
		self::assertNotFalse($start);
		self::assertNotFalse($end);
		return substr($html, $start, $end - $start);
	}
}
