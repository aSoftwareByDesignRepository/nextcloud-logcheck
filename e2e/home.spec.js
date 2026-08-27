// @ts-check
/**
 * J-LCK-01 — Home shows Watching controls (no setup CTA card).
 * Skips when E2E_USER is unset (structure still present for CI with credentials).
 */
const { test, expect } = require('@playwright/test');
const { login, openLogCheck } = require('./helpers');

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
});
