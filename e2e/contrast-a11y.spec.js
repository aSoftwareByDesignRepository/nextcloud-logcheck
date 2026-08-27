// @ts-check
/**
 * WCAG 1.4.3 contrast proofs — computed luminance on themed surfaces.
 * Complements axe (which does not score all custom property chains).
 */
const { test, expect } = require('@playwright/test');
const { login, gotoLogCheck } = require('./helpers');
const { setUserTheme, resetUserTheme, USER_THEMES } = require('./helpers/theming');

/**
 * @param {number} r @param {number} g @param {number} b
 */
function relLuminance(r, g, b) {
	const [rs, gs, bs] = [r, g, b].map((c) => {
		const s = c / 255;
		return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
	});
	return 0.2126 * rs + 0.7152 * gs + 0.0722 * bs;
}

/**
 * @param {string} cssColor
 */
function parseCssColor(cssColor) {
	const m = cssColor.match(/rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)/);
	if (!m) {
		return null;
	}
	return { r: +m[1], g: +m[2], b: +m[3] };
}

/**
 * @param {string} fg @param {string} bg
 */
function contrastRatio(fg, bg) {
	const f = parseCssColor(fg);
	const b = parseCssColor(bg);
	if (!f || !b) {
		return null;
	}
	const l1 = relLuminance(f.r, f.g, f.b);
	const l2 = relLuminance(b.r, b.g, b.b);
	const lighter = Math.max(l1, l2);
	const darker = Math.min(l1, l2);
	return (lighter + 0.05) / (darker + 0.05);
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} selector
 * @param {number} minRatio
 * @param {string} label
 */
async function expectContrast(page, selector, minRatio, label) {
	const pair = await page.evaluate((sel) => {
		const el = document.querySelector(sel);
		if (!el) {
			return null;
		}
		const cs = getComputedStyle(el);
		let bg = cs.backgroundColor;
		if (!bg || bg === 'rgba(0, 0, 0, 0)' || bg === 'transparent') {
			let node = el.parentElement;
			while (node && node instanceof HTMLElement) {
				const p = getComputedStyle(node).backgroundColor;
				if (p && p !== 'rgba(0, 0, 0, 0)' && p !== 'transparent') {
					bg = p;
					break;
				}
				node = node.parentElement;
			}
		}
		return { fg: cs.color, bg: bg || 'rgb(255, 255, 255)' };
	}, selector);
	expect(pair, `${label}: missing ${selector}`).not.toBeNull();
	const ratio = contrastRatio(pair.fg, pair.bg);
	expect(ratio, `${label}: fg=${pair.fg} bg=${pair.bg}`).not.toBeNull();
	expect(ratio, `${label} needs ≥${minRatio}:1`).toBeGreaterThanOrEqual(minRatio);
}

test.describe('WCAG contrast on themed surfaces', () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!process.env.E2E_USER && !process.env.LOGCHECK_E2E_USER, 'Set E2E_USER to run');
		const ok = await login(page);
		test.skip(!ok, 'Login failed');
	});

	test.afterEach(async ({ page }) => {
		try {
			await resetUserTheme(page);
		} catch (_) {
			/* best-effort */
		}
	});

	for (const theme of USER_THEMES) {
		test(`theme=${theme}: primary CTA, body text, danger button`, async ({ page }) => {
			await setUserTheme(page, theme);
			await gotoLogCheck(page, '/settings/alerts');

			await expectContrast(page, '#app-content .lck-btn--primary', 4.5, `${theme} primary CTA`);
			await expectContrast(page, '#lck-page-title', 4.5, `${theme} page title`);
			await expectContrast(page, '#app-content .lck-muted', 4.5, `${theme} muted help text`);

			await gotoLogCheck(page, '/logs');
			const danger = page.locator('#app-content .lck-btn--danger').first();
			if (await danger.count()) {
				await expectContrast(page, '#app-content .lck-btn--danger', 4.5, `${theme} danger CTA`);
			}
		});

		test(`theme=${theme}: focus ring ≥3:1 (WCAG 2.4.11)`, async ({ page }) => {
			await setUserTheme(page, theme);
			await gotoLogCheck(page, '/settings/alerts');
			await page.locator('#lck-main-content').focus();

			/** @type {{ outline: string, bg: string } | null} */
			let pair = null;
			for (let i = 0; i < 28; i++) {
				await page.keyboard.press('Tab');
				pair = await page.evaluate(() => {
					const el = document.activeElement;
					if (!el || !(el instanceof HTMLElement) || !el.closest('#app-content')) {
						return null;
					}
					const cs = getComputedStyle(el);
					const ow = parseFloat(cs.outlineWidth || '0');
					if (ow < 2 || cs.outlineStyle === 'none') {
						return null;
					}
					let bg = cs.backgroundColor;
					if (!bg || bg === 'rgba(0, 0, 0, 0)' || bg === 'transparent') {
						let node = el.parentElement;
						while (node && node instanceof HTMLElement) {
							const p = getComputedStyle(node).backgroundColor;
							if (p && p !== 'rgba(0, 0, 0, 0)' && p !== 'transparent') {
								bg = p;
								break;
							}
							node = node.parentElement;
						}
					}
					return { outline: cs.outlineColor, bg: bg || 'rgb(255, 255, 255)' };
				});
				if (pair) {
					break;
				}
			}
			expect(pair, `${theme}: no focusable control with visible outline in #app-content`).not.toBeNull();
			const ratio = contrastRatio(pair.outline, pair.bg);
			expect(ratio, `${theme} focus ring: outline=${pair.outline} bg=${pair.bg}`).not.toBeNull();
			expect(ratio, `${theme} focus ring needs ≥3:1`).toBeGreaterThanOrEqual(3);
		});

		test(`theme=${theme}: text field focus outline ≥3:1`, async ({ page }) => {
			await setUserTheme(page, theme);
			await gotoLogCheck(page, '/settings/alerts');
			const field = page.locator('#app-content input[type="email"], #app-content input[type="text"], #app-content .form-input').first();
			test.skip(!(await field.count()), 'No text field on Alerts');
			await field.focus();
			const pair = await field.evaluate((el) => {
				const cs = getComputedStyle(el);
				let bg = cs.backgroundColor;
				if (!bg || bg === 'rgba(0, 0, 0, 0)' || bg === 'transparent') {
					let node = el.parentElement;
					while (node && node instanceof HTMLElement) {
						const p = getComputedStyle(node).backgroundColor;
						if (p && p !== 'rgba(0, 0, 0, 0)' && p !== 'transparent') {
							bg = p;
							break;
						}
						node = node.parentElement;
					}
				}
				return {
					outline: cs.outlineColor,
					outlineWidth: cs.outlineWidth,
					outlineStyle: cs.outlineStyle,
					bg: bg || 'rgb(255, 255, 255)',
				};
			});
			expect(parseFloat(pair.outlineWidth), `${theme} field outline width`).toBeGreaterThanOrEqual(2);
			expect(pair.outlineStyle, `${theme} field outline style`).not.toBe('none');
			const ratio = contrastRatio(pair.outline, pair.bg);
			expect(ratio, `${theme} field focus: outline=${pair.outline} bg=${pair.bg}`).not.toBeNull();
			expect(ratio, `${theme} field focus needs ≥3:1`).toBeGreaterThanOrEqual(3);
		});
	}
});
