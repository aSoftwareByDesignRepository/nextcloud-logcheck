// @ts-check
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { login, openLogCheck } = require('./helpers');

test.describe('LogCheck accessibility', () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!process.env.E2E_USER && !process.env.LOGCHECK_E2E_USER, 'Set E2E_USER + E2E_PASS to run against a live instance');
		const ok = await login(page);
		test.skip(!ok, 'Login failed — check E2E_USER / E2E_PASS');
	});

	test('axe serious=0 on /apps/logcheck/', async ({ page }) => {
		await openLogCheck(page);
		const results = await new AxeBuilder({ page })
			.include('#content')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.analyze();
		const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
		expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
	});

	test('axe serious=0 on settings people + rules', async ({ page }) => {
		await openLogCheck(page);
		for (const path of ['/apps/logcheck/settings/people', '/apps/logcheck/settings/rules']) {
			await page.goto(path);
			await expect(page.locator('#content.app-logcheck')).toBeVisible({ timeout: 20000 });
			const results = await new AxeBuilder({ page })
				.include('#content')
				.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
				.analyze();
			const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
			expect(serious, path + ' ' + JSON.stringify(serious, null, 2)).toEqual([]);
		}
	});
});
