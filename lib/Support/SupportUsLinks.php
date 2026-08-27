<?php

declare(strict_types=1);

namespace OCA\LogCheck\Support;

/**
 * Support us links — GitHub Sponsors + enterprise mailto. No payment PSPs.
 *
 * Security notes (auditor-facing):
 * - Destinations are compile-time constants or derived from validated locale only.
 * - Mailto subjects are rawurlencoded; no request input enters hrefs.
 * - Donation CTA is GitHub Sponsors only (no card/PSP checkout links).
 */
final class SupportUsLinks
{
	public const CONTACT_EMAIL = 'info@software-by-design.de';
	public const SPONSORS_URL = 'https://github.com/sponsors/aSoftwareByDesignRepository';
	public const SITE_ORIGIN = 'https://nextcloud.software-by-design.de';
	public const VENDOR_NAME = 'Software by Design GbR';

	private string $appDisplayName = 'HealthCheck';

	public function sponsorsUrl(): string
	{
		return self::SPONSORS_URL;
	}

	public function contactMailto(): string
	{
		return 'mailto:' . self::CONTACT_EMAIL;
	}

	public function enterpriseMailto(string $languageCode): string
	{
		$subject = $this->isGermanLocale($languageCode)
			? 'HealthCheck: Enterprise-Anfrage'
			: 'HealthCheck: enterprise inquiry';
		return 'mailto:' . self::CONTACT_EMAIL . '?subject=' . rawurlencode($subject);
	}

	public function supportPageUrl(string $languageCode): string
	{
		$path = $this->isGermanLocale($languageCode) ? '/de/support.html' : '/en/support.html';
		return self::SITE_ORIGIN . $path;
	}

	public function isGermanLocale(string $languageCode): bool
	{
		$lang = strtolower(str_replace('_', '-', trim($languageCode)));
		if ($lang === '') {
			return false;
		}
		return $lang === 'de' || str_starts_with($lang, 'de-');
	}

	/**
	 * Stable payload for templates and contract tests.
	 *
	 * @return array{
	 *   appDisplayName: string,
	 *   contactEmail: string,
	 *   contactMailto: string,
	 *   enterpriseMailto: string,
	 *   sponsorsUrl: string,
	 *   supportPageUrl: string,
	 *   vendorName: string,
	 *   isGerman: bool
	 * }
	 */
	public function forLocale(string $languageCode): array
	{
		return [
			'appDisplayName' => $this->appDisplayName,
			'contactEmail' => self::CONTACT_EMAIL,
			'contactMailto' => $this->contactMailto(),
			'enterpriseMailto' => $this->enterpriseMailto($languageCode),
			'sponsorsUrl' => self::SPONSORS_URL,
			'supportPageUrl' => $this->supportPageUrl($languageCode),
			'vendorName' => self::VENDOR_NAME,
			'isGerman' => $this->isGermanLocale($languageCode),
		];
	}
}
