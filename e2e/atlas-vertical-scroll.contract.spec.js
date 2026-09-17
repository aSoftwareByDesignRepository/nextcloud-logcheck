// @ts-check
/**
 * ATLAS_VERTICAL_SCROLL_CONTRACT — tall alerts settings must scroll to the end.
 * Guards CSS Overflow L3 unpaired overflow-x:clip truncating sticky-save / more options.
 */
const { test } = require('@playwright/test');
const { assertAtlasVerticalScrollReachable } = require('../../_shared/e2e/atlas-vertical-scroll-contract');
const { login, gotoLogCheck } = require('./helpers');

test.describe('ATLAS_VERTICAL_SCROLL_CONTRACT', () => {
	test('alerts settings page scrolls to more-options end', async ({ page }) => {
		const ok = await login(page);
		test.skip(!ok, 'E2E credentials not configured');
		await page.setViewportSize({ width: 1280, height: 640 });
		await gotoLogCheck(page, '/settings/alerts');
		await page.waitForSelector('#lck-settings-form, #lck-more-options', { timeout: 45_000 });

		await assertAtlasVerticalScrollReachable(page, {
			scrollport: '#app-content',
			target: '#lck-more-options, #lck-excerpts-help, #lck-private-webhooks-help',
			bottomSlopPx: 12,
		});
	});

	test('alerts settings stays reachable at phone height', async ({ page }) => {
		const ok = await login(page);
		test.skip(!ok, 'E2E credentials not configured');
		await page.setViewportSize({ width: 390, height: 667 });
		await gotoLogCheck(page, '/settings/alerts');
		await page.waitForSelector('#lck-settings-form, #lck-more-options', { timeout: 45_000 });

		await assertAtlasVerticalScrollReachable(page, {
			scrollport: '#app-content',
			target: '#lck-more-options, #lck-excerpts-help, #lck-private-webhooks-help',
			bottomSlopPx: 16,
		});
	});
});
