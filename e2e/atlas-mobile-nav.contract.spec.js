// @ts-check
/** ATLAS_MOBILE_NAV_CONTRACT — phone Menu → open nav + no h-scroll */
const { test } = require('@playwright/test');
const { assertAtlasMobileNav } = require('../../_shared/e2e/atlas-mobile-nav-contract');
const { login, gotoLogCheck } = require('./helpers');

test('ATLAS_MOBILE_NAV_CONTRACT in-page Menu opens drawer', async ({ page }) => {
	const ok = await login(page);
	test.skip(!ok, 'E2E credentials not configured');
	await page.setViewportSize({ width: 375, height: 812 });
	await gotoLogCheck(page, '/');
	await page.waitForSelector('[data-lck-nav-toggle], #lck-nav-toggle', { timeout: 30_000 });
	await assertAtlasMobileNav(page, {
		toggle: page.locator('[data-lck-nav-toggle], #lck-nav-toggle').first(),
		nav: page.locator('#app-navigation.lck-nav'),
		openClass: /lck-nav--open/,
		navLink: page.locator('#app-navigation.lck-nav a.lck-nav__link, #app-navigation.lck-nav a[href]').first(),
	});
});
