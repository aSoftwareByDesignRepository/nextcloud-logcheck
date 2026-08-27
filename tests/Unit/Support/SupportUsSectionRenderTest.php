<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Support;

use OCA\LogCheck\Support\SupportUsLinks;
use PHPUnit\Framework\TestCase;

/**
 * Render contract for Support us settings partial (no NC kernel).
 */
final class SupportUsSectionRenderTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		require_once __DIR__ . '/template_stubs.php';
	}

	public function testRenderShowsSponsorsAndEnterpriseWithoutPaymentPsps(): void
	{
		$html = $this->render('en');
		self::assertStringContainsString('id="lck-support-title"', $html);
		self::assertStringContainsString('id="lck-support-donate-title"', $html);
		self::assertStringContainsString('id="lck-support-enterprise-title"', $html);
		self::assertStringContainsString('role="region"', $html);
		self::assertStringContainsString('Donate', $html);
		self::assertStringContainsString('Donations go through GitHub Sponsors only.', $html);
		self::assertStringContainsString('GitHub Sponsors', $html);
		self::assertStringContainsString('Enterprise inquiry', $html);
		self::assertStringContainsString('https://github.com/sponsors/aSoftwareByDesignRepository', $html);
		self::assertStringContainsString('mailto:info@software-by-design.de?subject=', $html);
		self::assertStringContainsString(rawurlencode('LogCheck: enterprise inquiry'), $html);
		self::assertStringContainsString('noopener noreferrer', $html);
		self::assertStringContainsString('lck-support-links', $html);
		self::assertStringNotContainsString('PayPal', $html);
		self::assertStringNotContainsString('paypal', $html);
		self::assertStringNotContainsString('Stripe', $html);
		self::assertStringNotContainsString('stripe', $html);
		self::assertStringNotContainsString('Donate via', $html);
		self::assertStringNotContainsString('href="#"', $html);
		self::assertStringNotContainsString('<script', $html);
		self::assertDoesNotMatchRegularExpression('/<li>\s*<\/li>/', $html);
	}

	public function testRenderUsesGermanEnterpriseSubject(): void
	{
		$html = $this->render('de', [
			'Support us' => 'Unterstützen Sie uns',
			'Donate' => 'Spenden',
			'Donations go through GitHub Sponsors only.' => 'Spenden laufen ausschließlich über GitHub Sponsors.',
			'Enterprise' => 'Enterprise',
			'Need booked help or a custom quote? Contact us by email.' =>
				'Brauchen Sie gebuchte Hilfe oder ein individuelles Angebot? Schreiben Sie uns per E-Mail.',
			'Enterprise inquiry' => 'Enterprise-Anfrage',
		]);
		self::assertStringContainsString('Unterstützen Sie uns', $html);
		self::assertStringContainsString('Spenden', $html);
		self::assertStringContainsString(rawurlencode('LogCheck: Enterprise-Anfrage'), $html);
		self::assertStringNotContainsString('PayPal', $html);
		self::assertStringNotContainsString('Stripe', $html);
	}

	public function testRenderOmitsCtasWhenLinksMissingOrUnsafe(): void
	{
		$html = $this->render('en', [], [
			'sponsorsUrl' => '',
			'enterpriseMailto' => 'javascript:alert(1)',
			'supportPageUrl' => 'ftp://evil.example/x',
		]);
		self::assertStringContainsString('Donations go through GitHub Sponsors only.', $html);
		self::assertStringNotContainsString('lck-support-links', $html);
		self::assertStringNotContainsString('lck-btn--primary', $html);
		self::assertStringNotContainsString('lck-btn--secondary', $html);
		self::assertStringNotContainsString('Enterprise inquiry', $html);
		self::assertStringNotContainsString('javascript:', $html);
		self::assertStringNotContainsString('ftp://', $html);
		self::assertStringNotContainsString('href="#"', $html);
		self::assertDoesNotMatchRegularExpression('/<li>\s*<\/li>/', $html);
	}

	public function testRenderRejectsNonSponsorsHttpsAsDonationCta(): void
	{
		$html = $this->render('en', [], [
			'sponsorsUrl' => 'https://evil.example/donate',
			'enterpriseMailto' => 'mailto:info@software-by-design.de?subject=x',
			'supportPageUrl' => 'https://nextcloud.software-by-design.de/en/support.html',
		]);
		self::assertStringNotContainsString('evil.example', $html);
		self::assertStringNotContainsString('lck-btn--primary', $html);
		self::assertStringContainsString('Enterprise inquiry', $html);
		self::assertStringContainsString('https://nextcloud.software-by-design.de/en/support.html', $html);
	}

	public function testRenderSurvivesMissingSupportLinksKey(): void
	{
		$html = $this->renderWithPayload(null);
		self::assertStringContainsString('lck-support-title', $html);
		self::assertStringNotContainsString('lck-support-links', $html);
		self::assertDoesNotMatchRegularExpression('/<li>\s*<\/li>/', $html);
	}

	/**
	 * @param array<string, string> $map
	 * @param array<string, mixed>|null $linksOverride
	 */
	private function render(string $lang, array $map = [], ?array $linksOverride = null): string
	{
		$links = $linksOverride ?? (new SupportUsLinks())->forLocale($lang);
		return $this->renderWithPayload($links, $lang, $map);
	}

	/**
	 * @param array<string, mixed>|null $links
	 * @param array<string, string> $map
	 */
	private function renderWithPayload(?array $links, string $lang = 'en', array $map = []): string
	{
		$l = new class ($lang, $map) {
			/** @param array<string, string> $map */
			public function __construct(private string $lang, private array $map)
			{
			}

			public function getLanguageCode(): string
			{
				return $this->lang;
			}

			public function t(string $text, array $parameters = []): string
			{
				$out = $this->map[$text] ?? $text;
				if ($parameters !== []) {
					$out = str_replace('%s', (string)$parameters[0], $out);
				}
				return $out;
			}
		};

		$_ = [];
		if ($links !== null) {
			$_['supportLinks'] = $links;
		}
		ob_start();
		include dirname(__DIR__, 3) . '/templates/parts/settings/support.php';
		return (string)ob_get_clean();
	}
}
