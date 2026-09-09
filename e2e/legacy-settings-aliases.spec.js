// @ts-check
/**
 * Critic MF inv-false-legacy-pages: navigate fixed legacy /settings/{status,channels,watch,access,privacy}
 * and /settings index — must redirect into catalog sections (not DesignSystemChrome source-scan).
 */
const { test, expect } = require('@playwright/test');
const { login } = require('./helpers');

const base = (process.env.LOGCHECK_BASE_URL || process.env.E2E_BASE || 'http://localhost:8081').replace(/\/$/, '');

test.describe('Legacy settings aliases redirect', () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!process.env.E2E_USER && !process.env.LOGCHECK_E2E_USER, 'Set E2E_USER + E2E_PASS');
		const ok = await login(page);
		test.skip(!ok, 'Login failed');
	});

	const cases = [
		{ path: '/settings', expectPath: /\/settings\/alerts(?:\/)?(?:\?|#|$)/ },
		{ path: '/settings/status', expectPath: /\/(?:home|index\.php\/apps\/logcheck\/home)/ },
		{ path: '/settings/channels', expectPath: /\/settings\/alerts/ },
		{ path: '/settings/watch', expectPath: /\/settings\/rules/ },
		{ path: '/settings/access', expectPath: /\/settings\/people/ },
		{ path: '/settings/privacy', expectPath: /\/settings\/alerts/ },
	];

	for (const c of cases) {
		test(`GET ${c.path} lands on catalog target`, async ({ page }) => {
			await page.goto(base + '/index.php/apps/logcheck' + c.path, { waitUntil: 'domcontentloaded' });
			await page.waitForURL(c.expectPath, { timeout: 20000 });
			await expect(page.locator('#lck-main-content, .lck-home, .lck-form').first()).toBeVisible({
				timeout: 20000,
			});
			if (c.path === '/settings/privacy') {
				expect(page.url()).toMatch(/#lck-more-options/);
			}
		});
	}
});
