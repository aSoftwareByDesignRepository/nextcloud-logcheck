<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Hard gates for HealthCheck localization: parity, placeholders, code coverage, formal DE, plurals.
 */
class L10nCatalogTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname(__DIR__, 3);
	}

	public function testParityScriptPasses(): void
	{
		$script = $this->root . '/scripts/check-l10n-parity.php';
		self::assertFileExists($script);
		passthru('php ' . escapeshellarg($script), $code);
		self::assertSame(0, $code);
	}

	public function testPlaceholderScriptPasses(): void
	{
		$script = $this->root . '/scripts/check-l10n-placeholders.php';
		self::assertFileExists($script);
		passthru('php ' . escapeshellarg($script), $code);
		self::assertSame(0, $code);
	}

	public function testCodeKeysScriptPasses(): void
	{
		$script = $this->root . '/scripts/check-l10n-code-keys.php';
		self::assertFileExists($script);
		passthru('php ' . escapeshellarg($script), $code);
		self::assertSame(0, $code);
	}

	public function testJsonJsCatalogsStayInSync(): void
	{
		foreach (['en', 'de', 'fr', 'es', 'da', 'nl', 'it', 'pl', 'sv', 'nb', 'pt_BR'] as $lang) {
			$json = json_decode((string)file_get_contents($this->root . '/l10n/' . $lang . '.json'), true, 512, JSON_THROW_ON_ERROR);
			$js = (string)file_get_contents($this->root . '/l10n/' . $lang . '.js');
			self::assertStringContainsString('OC.L10N.register', $js);
			self::assertSame($json['pluralForm'] ?? '', $this->extractJsPluralForm($js), $lang);
			$start = strpos($js, '{');
			$end = strrpos($js, '},');
			self::assertNotFalse($start);
			self::assertNotFalse($end);
			$body = substr($js, $start, $end - $start + 1);
			$fromJs = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
			self::assertSame($json['translations'], $fromJs, $lang . ' json/js drift');
		}
	}

	public function testGermanUsesFormalSieNotDu(): void
	{
		$data = json_decode((string)file_get_contents($this->root . '/l10n/de.json'), true, 512, JSON_THROW_ON_ERROR);
		$blob = mb_strtolower(json_encode($data['translations'], JSON_UNESCAPED_UNICODE));
		foreach (['\bdu\b', '\bdein\b', '\bdeine\b', '\bdir\b', '\bdich\b', '\bdein\w*\b'] as $pat) {
			self::assertDoesNotMatchRegularExpression('/' . $pat . '/u', $blob, 'informal German leaked: ' . $pat);
		}
		self::assertStringContainsString('sie', $blob);
	}

	public function testPolishNotifierPluralHasThreeForms(): void
	{
		$key = '_HealthCheck found %n new error_::_HealthCheck found %n new errors_';
		$email = '_HealthCheck: %n new error_::_HealthCheck: %n new errors_';
		$pl = json_decode((string)file_get_contents($this->root . '/l10n/pl.json'), true, 512, JSON_THROW_ON_ERROR);
		self::assertCount(3, $pl['translations'][$key]);
		self::assertCount(3, $pl['translations'][$email]);
		self::assertStringContainsString('nplurals=3', (string)($pl['pluralForm'] ?? ''));
	}

	public function testRequiredPortfolioLocalesPresent(): void
	{
		foreach (['en', 'de', 'fr', 'es', 'da', 'nl', 'it', 'pl', 'sv', 'nb', 'pt_BR'] as $lang) {
			self::assertFileExists($this->root . '/l10n/' . $lang . '.json');
			self::assertFileExists($this->root . '/l10n/' . $lang . '.js');
		}
		self::assertFileDoesNotExist($this->root . '/l10n/pt.json');
		self::assertFileDoesNotExist($this->root . '/l10n/pt_PT.json');
	}

	public function testNoBannedOpsJargonInAnyLocale(): void
	{
		$banned = ['cursor', 'inode', 'lease', 'coalesce', 'fingerprint', 'accumulator', 'pending digest'];
		foreach (glob($this->root . '/l10n/*.json') ?: [] as $path) {
			$data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
			$blob = json_encode($data['translations'] ?? [], JSON_UNESCAPED_UNICODE);
			foreach ($banned as $word) {
				self::assertDoesNotMatchRegularExpression(
					'/\b' . preg_quote($word, '/') . '\b/i',
					(string)$blob,
					basename($path) . ' contains ' . $word
				);
			}
		}
	}

	private function extractJsPluralForm(string $js): string
	{
		if (preg_match('/"([^"]*)"\s*\)\s*;\s*$/', $js, $m)) {
			return $m[1];
		}
		return '';
	}
}
