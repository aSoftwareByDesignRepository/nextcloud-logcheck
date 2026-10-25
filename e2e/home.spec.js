// @ts-check
/**
 * J-LCK-01 — Home shows Watching controls (no setup CTA card).
 * Skips when E2E_USER is unset (structure still present for CI with credentials).
 */
const { test, expect } = require('@playwright/test');
const { login, openLogCheck, gotoLogCheck } = require('./helpers');

test.describe('J-LCK-01 LogCheck home', () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!process.env.E2E_USER && !process.env.LOGCHECK_E2E_USER, 'Set E2E_USER + E2E_PASS to run against a live instance');
		const ok = await login(page);
		test.skip(!ok, 'Login failed — check E2E_USER / E2E_PASS');
	});

	test('home has Watching controls and no setup CTA', async ({ page }) => {
		await openLogCheck(page);
		await expect(page.locator('.lck-health-grid')).toBeVisible({ timeout: 15000 });
		await expect(page.locator('#lck-watch-toggle')).toBeVisible();
		await expect(page.locator('.lck-setup-cta')).toHaveCount(0);
		await expect(page.locator('#lck-setup-email, #lck-turn-on-form')).toHaveCount(0);
	});

	test('JS repaint never renders raw sprintf placeholders (lck-mf-24)', async ({ page }) => {
		await openLogCheck(page);
		await expect(page.locator('.lck-health-grid')).toBeVisible({ timeout: 15000 });
		// Drive the async repaint path directly — SSR detail is PHP-correct;
		// the bug lives in refreshHomeStatus() → applyHomeStatus().
		await page.evaluate(() => window.LogCheckApp && window.LogCheckApp.refreshHomeStatus());
		const detail = page.locator('.lck-health-card[data-probe="log"] .lck-health-card__detail');
		await expect(detail.first()).toBeVisible({ timeout: 15000 });
		const texts = await page
			.locator('.lck-health-card__detail, .lck-badge__label')
			.allTextContents();
		expect(texts.length).toBeGreaterThan(0);
		for (const txt of texts) {
			expect(txt).not.toMatch(/%[dsn]/);
		}
		// aria-labels are DOM too (settings.js chips bug announced 'Remove %s').
		const labels = await page
			.locator('[aria-label]')
			.evaluateAll((els) => els.map((e) => e.getAttribute('aria-label') || ''));
		for (const label of labels) {
			expect(label).not.toMatch(/%[dsn]/);
		}
		// People settings — chip remove buttons carry the same aria-label site.
		await gotoLogCheck(page, '/settings/people');
		const peopleLabels = await page
			.locator('[aria-label]')
			.evaluateAll((els) => els.map((e) => e.getAttribute('aria-label') || ''));
		for (const label of peopleLabels) {
			expect(label).not.toMatch(/%[dsn]/);
		}
	});
});
