<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Static CSS contracts for theme / WCAG design-system rules.
 */
class ThemeTokenCssTest extends TestCase
{
	private string $cssRoot;

	protected function setUp(): void
	{
		$this->cssRoot = dirname(__DIR__, 3) . '/css';
	}

	public function testDangerButtonsNeverUsePaleColorErrorAsFill(): void
	{
		$blob = $this->readAllCss();
		self::assertDoesNotMatchRegularExpression(
			'/\.lck-btn--danger[^{]*\{[^}]*background:\s*var\(--color-error\b/s',
			$blob,
			'Danger CTAs must use --lck-danger-fill / --color-element-error, not pale --color-error'
		);
	}

	public function testTokensDefineDangerFillAndTouchLg(): void
	{
		$tokens = (string)file_get_contents($this->cssRoot . '/common/tokens.css');
		self::assertStringContainsString('--lck-danger-fill:', $tokens);
		self::assertStringContainsString('--lck-danger-on-fill:', $tokens);
		self::assertStringContainsString('--lck-danger-ink:', $tokens);
		self::assertStringContainsString('--lck-touch-lg:', $tokens);
		self::assertStringContainsString('--lck-primary:', $tokens);
		self::assertStringContainsString('--lck-focus:', $tokens);
		self::assertStringContainsString('color-element-error', $tokens);
		self::assertStringContainsString('data-theme-dark', $tokens);
		// Radii bind to NC --border-radius-* with design-system fallbacks
		self::assertStringContainsString('--lck-radius-sm: var(--border-radius', $tokens);
		self::assertStringContainsString('--lck-radius-md: var(--border-radius-element', $tokens);
		self::assertStringContainsString('--lck-radius-lg: var(--border-radius-large', $tokens);
		self::assertStringContainsString('--lck-radius-pill: var(--border-radius-pill', $tokens);
	}

	public function testEveryUsedLogcheckAliasIsDefined(): void
	{
		$blob = $this->readAllCss();
		preg_match_all('/--logcheck-[a-z0-9-]+/', $blob, $used);
		preg_match_all('/(--logcheck-[a-z0-9-]+)\s*:/', $blob, $defined);
		$missing = array_values(array_diff(array_unique($used[0] ?? []), array_unique($defined[1] ?? [])));
		self::assertSame([], $missing, 'Undefined --logcheck-* aliases: ' . implode(', ', $missing));
	}

	public function testAllFeatureCssHexOnlyQrCanvas(): void
	{
		$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->cssRoot));
		foreach ($it as $f) {
			if (!$f->isFile() || !str_ends_with($f->getFilename(), '.css')) {
				continue;
			}
			$raw = (string)file_get_contents($f->getPathname());
			$stripped = preg_replace('!/\*.*?\*/!s', '', $raw) ?? $raw;
			if (str_contains($f->getPathname(), 'tokens.css')) {
				$stripped = preg_replace('/--lck-qr-canvas:\s*#[0-9a-fA-F]+;/', '', $stripped) ?? $stripped;
			}
			self::assertDoesNotMatchRegularExpression(
				'/#[0-9a-fA-F]{3,8}\b/',
				$stripped,
				$f->getFilename() . ' must not hard-code hex colours (except --lck-qr-canvas)'
			);
		}
	}

	public function testTintsMixIntoMainBackgroundNotTransparent(): void
	{
		$tokens = (string)file_get_contents($this->cssRoot . '/common/tokens.css');
		foreach (['--lck-tint-success', '--lck-tint-warning', '--lck-tint-danger', '--lck-tint-info'] as $name) {
			self::assertMatchesRegularExpression(
				'/' . preg_quote($name, '/') . ':\s*color-mix\([^;]*var\(--color-main-background\)/',
				$tokens,
				$name . ' must mix into --color-main-background'
			);
		}
	}

	public function testFeatureCssHasNoRawHexOutsideQrCanvas(): void
	{
		$app = (string)file_get_contents($this->cssRoot . '/app.css');
		// Strip comments
		$stripped = preg_replace('!/\*.*?\*/!s', '', $app) ?? $app;
		self::assertDoesNotMatchRegularExpression(
			'/#[0-9a-fA-F]{3,8}\b/',
			$stripped,
			'app.css must not hard-code hex colours'
		);
	}

	public function testFocusRingsUseSolidTokensNotDilutedMix(): void
	{
		$blob = $this->readAllCss();
		self::assertDoesNotMatchRegularExpression(
			'/focus-visible[^{]*\{[^}]*outline:[^;]*color-mix\([^;]*transparent/s',
			$blob,
			':focus-visible outlines must use solid --lck-focus / --color-primary-element, not diluted color-mix'
		);
	}

	/** Form text fields must keep a solid outline (never outline:none with only a diluted halo). */
	public function testFormFocusVisibleKeepsSolidOutline(): void
	{
		$forms = (string)file_get_contents($this->cssRoot . '/common/form-controls.css');
		self::assertStringContainsString('outline: var(--lck-focus', $forms);
		// Strip comments so prose mentioning "outline:none" does not false-positive.
		$stripped = preg_replace('!/\*.*?\*/!s', '', $forms) ?? $forms;
		self::assertDoesNotMatchRegularExpression(
			'/\.form-input:focus-visible[^{]*\{[^}]*outline:\s*none/s',
			$stripped
		);
		self::assertDoesNotMatchRegularExpression(
			'/textarea:focus-visible[^{]*\{[^}]*outline:\s*none/s',
			$stripped
		);
		self::assertDoesNotMatchRegularExpression(
			'/\[role="combobox"\]:focus-visible[^{]*\{[^}]*outline:\s*none/s',
			$stripped
		);
	}

	public function testShellDangerUsesLckDangerFill(): void
	{
		$shell = (string)file_get_contents($this->cssRoot . '/common/shell-chrome.css');
		self::assertStringContainsString('var(--lck-danger-fill)', $shell);
		self::assertStringContainsString('var(--lck-danger-on-fill)', $shell);
	}

	private function readAllCss(): string
	{
		$out = '';
		$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->cssRoot));
		foreach ($it as $f) {
			if ($f->isFile() && str_ends_with($f->getFilename(), '.css')) {
				$out .= file_get_contents($f->getPathname()) . "\n";
			}
		}
		return $out;
	}
}
