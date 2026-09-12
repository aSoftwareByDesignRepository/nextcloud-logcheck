// @ts-check
const { test, expect } = require('@playwright/test');
const { login, gotoLogCheck } = require('./helpers');

/**
 * J-LCK-21: desktop shell must be sidebar | main side-by-side — never stacked
 * (main content below the app nav). Guards NC core flex-basis:100vw wrap bug.
 */
test.describe('J-LCK-21 shell layout', () => {
	test.beforeEach(async ({ page }) => {
		const ok = await login(page);
		test.skip(!ok, 'E2E credentials not configured');
	});

	test('desktop: app-content sits beside nav, not below it', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 });
		await gotoLogCheck(page, '/');
		const geom = await page.evaluate(() => {
			const content = document.querySelector('#content.app-logcheck');
			const nav = document.querySelector('#app-navigation.lck-nav');
			const app = document.querySelector('#app-content');
			if (!content || !nav || !app) {
				return { ok: false, reason: 'missing nodes' };
			}
			const cs = getComputedStyle(content);
			const nr = nav.getBoundingClientRect();
			const ar = app.getBoundingClientRect();
			return {
				ok: true,
				display: cs.display,
				columns: cs.gridTemplateColumns,
				nav: { t: nr.top, l: nr.left, r: nr.right, b: nr.bottom, w: nr.width },
				app: { t: ar.top, l: ar.left, r: ar.right, b: ar.bottom, w: ar.width },
				stackedVertically: ar.top >= nr.bottom - 2,
				sideBySide: Math.abs(ar.top - nr.top) < 48 && ar.left >= nr.right - 8,
			};
		});
		expect(geom.ok, geom.reason || 'layout probe failed').toBeTruthy();
		expect(geom.display).toBe('grid');
		expect(geom.stackedVertically).toBeFalsy();
		expect(geom.sideBySide).toBeTruthy();
		expect(geom.app.l).toBeGreaterThanOrEqual(geom.nav.r - 8);
		expect(geom.app.w).toBeGreaterThan(400);
	});

	test('logs page keeps side-by-side shell', async ({ page }) => {
		await page.setViewportSize({ width: 1400, height: 900 });
		await gotoLogCheck(page, '/logs');
		const sideBySide = await page.evaluate(() => {
			const nav = document.querySelector('#app-navigation.lck-nav');
			const app = document.querySelector('#app-content');
			const nr = nav.getBoundingClientRect();
			const ar = app.getBoundingClientRect();
			return Math.abs(ar.top - nr.top) < 48 && ar.left >= nr.right - 8;
		});
		expect(sideBySide).toBeTruthy();
	});

	test('mobile: content is full-width single track (no 280px nav column)', async ({ page }) => {
		await page.setViewportSize({ width: 375, height: 812 });
		await gotoLogCheck(page, '/');
		const geom = await page.evaluate(() => {
			const content = document.querySelector('#content.app-logcheck');
			const app = document.querySelector('#app-content');
			if (!content || !app) {
				return { ok: false };
			}
			const cs = getComputedStyle(content);
			const ar = app.getBoundingClientRect();
			const docOverflow = document.documentElement.scrollWidth - document.documentElement.clientWidth;
			return {
				ok: true,
				columns: cs.gridTemplateColumns,
				appLeft: ar.left,
				appWidth: ar.width,
				docOverflow,
			};
		});
		expect(geom.ok).toBeTruthy();
		// Single track — not "280px 1fr"
		expect(geom.columns.split(' ').length).toBeLessThanOrEqual(2);
		expect(geom.appLeft).toBeLessThan(24);
		expect(geom.appWidth).toBeGreaterThan(300);
		expect(geom.docOverflow).toBeLessThanOrEqual(2);
	});

	test('mobile: in-page Menu reveals navigation (AZC#33 / Atlas contract)', async ({ page }) => {
		await page.setViewportSize({ width: 375, height: 812 });
		await gotoLogCheck(page, '/');
		const toggle = page.locator('[data-lck-nav-toggle], #lck-nav-toggle').first();
		await expect(toggle).toBeVisible({ timeout: 20_000 });
		await toggle.click();
		await expect(page.locator('#app-navigation.lck-nav')).toHaveClass(/lck-nav--open/);
		await expect(page.locator('#app-navigation.lck-nav a.lck-nav__link').first()).toBeVisible();
	});
});
