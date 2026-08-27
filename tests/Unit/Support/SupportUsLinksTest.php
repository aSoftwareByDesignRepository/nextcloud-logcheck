<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Support;

use OCA\LogCheck\Support\SupportUsLinks;
use PHPUnit\Framework\TestCase;

final class SupportUsLinksTest extends TestCase
{
	public function testSponsorsUrlIsGitHubOnly(): void
	{
		$links = new SupportUsLinks();
		self::assertSame(
			'https://github.com/sponsors/aSoftwareByDesignRepository',
			$links->sponsorsUrl()
		);
		self::assertStringStartsWith('https://github.com/sponsors/', $links->sponsorsUrl());
		self::assertSame($links->sponsorsUrl(), $links->forLocale('en')['sponsorsUrl']);
	}

	public function testForLocaleOmitsPaymentPspsAndExposesRequiredKeys(): void
	{
		$payload = (new SupportUsLinks())->forLocale('en');
		self::assertArrayHasKey('sponsorsUrl', $payload);
		self::assertArrayHasKey('enterpriseMailto', $payload);
		self::assertArrayHasKey('supportPageUrl', $payload);
		self::assertArrayHasKey('contactEmail', $payload);
		self::assertArrayHasKey('contactMailto', $payload);
		self::assertArrayHasKey('vendorName', $payload);
		self::assertArrayHasKey('isGerman', $payload);
		self::assertArrayNotHasKey('paypalUrl', $payload);
		self::assertArrayNotHasKey('stripeUrl', $payload);
		self::assertArrayNotHasKey('PAYPAL_URL', $payload);
		self::assertArrayNotHasKey('STRIPE_URL', $payload);
		foreach ($payload as $value) {
			if (is_string($value)) {
				self::assertStringNotContainsStringIgnoringCase('paypal', $value);
				self::assertStringNotContainsStringIgnoringCase('stripe', $value);
			}
		}
	}

	public function testEnterpriseMailtoEncodesSubjectAndUsesLocale(): void
	{
		$links = new SupportUsLinks();
		$en = $links->enterpriseMailto('en');
		$de = $links->enterpriseMailto('de_DE');
		self::assertStringStartsWith('mailto:info@software-by-design.de?subject=', $en);
		self::assertStringContainsString(rawurlencode('LogCheck: enterprise inquiry'), $en);
		self::assertStringContainsString(rawurlencode('LogCheck: Enterprise-Anfrage'), $de);
		self::assertStringNotContainsString(' ', $en);
		self::assertStringNotContainsString("\r", $en);
		self::assertStringNotContainsString("\n", $en);
	}

	public function testGermanLocaleDetectionRejectsFalseFriendsAndEmpty(): void
	{
		$links = new SupportUsLinks();
		self::assertTrue($links->isGermanLocale('de'));
		self::assertTrue($links->isGermanLocale('de-DE'));
		self::assertTrue($links->isGermanLocale('de_CH'));
		self::assertFalse($links->isGermanLocale(''));
		self::assertFalse($links->isGermanLocale('   '));
		self::assertFalse($links->isGermanLocale('en'));
		self::assertFalse($links->isGermanLocale('den'));
		self::assertFalse($links->isGermanLocale('del'));
		self::assertFalse($links->forLocale('fr')['isGerman']);
		self::assertTrue($links->forLocale('de')['isGerman']);
	}

	public function testSupportPageUrlLocalizesPathUnderSiteOrigin(): void
	{
		$links = new SupportUsLinks();
		self::assertSame(
			'https://nextcloud.software-by-design.de/en/support.html',
			$links->supportPageUrl('en')
		);
		self::assertSame(
			'https://nextcloud.software-by-design.de/de/support.html',
			$links->supportPageUrl('de')
		);
		self::assertStringStartsWith(SupportUsLinks::SITE_ORIGIN, $links->supportPageUrl('nl'));
	}

	public function testClassSourceHasNoPaymentPspConstantsOrMethods(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Support/SupportUsLinks.php');
		self::assertStringNotContainsString('PAYPAL', $src);
		self::assertStringNotContainsString('STRIPE', $src);
		self::assertStringNotContainsString('paypal', $src);
		self::assertStringNotContainsString('stripe', $src);
		self::assertStringNotContainsString('function paypalUrl', $src);
		self::assertStringNotContainsString('function stripeUrl', $src);
	}
}
