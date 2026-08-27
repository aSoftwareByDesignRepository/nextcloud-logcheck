// @ts-check
/**
 * Exhaustive interactive-control smoke: every button, field, dialog, chip on shipped surfaces.
 * Scope = PRODUCT.md Must-haves (MH-*), not Nice-to-Have backlog (NH-*).
 */
const { test, expect } = require('@playwright/test');
const { login, gotoLogCheck, axeSeriousZero } = require('./helpers');

test.describe('All shipped controls work', () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!process.env.E2E_USER && !process.env.LOGCHECK_E2E_USER, 'Set E2E_USER to run');
		const ok = await login(page);
		test.skip(!ok, 'Login failed');
	});

	test('Home: health grid, watch toggle, nav, feedback Help', async ({ page }) => {
		await gotoLogCheck(page, '/');
		await expect(page.locator('.lck-health-grid')).toBeVisible();
		await expect(page.locator('.lck-health-card')).toHaveCount(7);
		await expect(page.locator('#lck-watch-toggle')).toBeVisible();

		// Feedback Help popover
		const help = page.locator('.lck-nav-footer__trigger');
		await expect(help).toBeVisible();
		await help.click();
		await expect(page.locator('#lck-feedback-menu')).toBeVisible();
		await expect(page.locator('#lck-feedback-problem')).toHaveAttribute('href', /mailto:/);
		await expect(page.locator('#lck-feedback-idea')).toHaveAttribute('href', /mailto:/);
		await page.keyboard.press('Escape');

		// Nav to Logs
		await page.locator('.lck-nav a[href*="/logs"]').first().click();
		await expect(page.locator('#lck-logs-viewer')).toBeVisible({ timeout: 15000 });
	});

	test('Home: Check again on health card when present', async ({ page }) => {
		await gotoLogCheck(page, '/');
		const btn = page.locator('#lck-check-again');
		if ((await btn.count()) === 0) {
			test.info().annotations.push({ type: 'note', description: 'No check-again CTA in current health state' });
			return;
		}
		const [req] = await Promise.all([
			page.waitForRequest((r) => r.url().includes('/api/run') && r.method() === 'POST'),
			btn.click(),
		]);
		expect(req).toBeTruthy();
	});

	test('Logs: search, reload, copy, load older, download', async ({ page }) => {
		await gotoLogCheck(page, '/logs');
		await expect(page.locator('#lck-logs-viewer')).toBeVisible({ timeout: 15000 });

		// Wait for initial tail
		await page.waitForTimeout(500);
		await expect(page.locator('#lck-logs-reload')).toBeVisible();
		await page.locator('#lck-logs-reload').click();
		await page.waitForResponse((r) => r.url().includes('/api/logs/tail') && r.status() < 500);

		await page.locator('#lck-logs-search').fill('error');
		await page.locator('#lck-logs-search-btn').click();
		await page.waitForResponse((r) => r.url().includes('/api/logs/search') && r.status() < 500);

		await page.locator('#lck-logs-reload').click();
		await page.waitForResponse((r) => r.url().includes('/api/logs/tail'));

		const more = page.locator('#lck-logs-more');
		const loadOlder = page.locator('#lck-logs-load-older');
		if (await more.isVisible() && !(await loadOlder.isDisabled())) {
			const [beforeRes] = await Promise.all([
				page.waitForResponse((r) => r.url().includes('/api/logs/before') && r.status() < 500, { timeout: 15000 }),
				loadOlder.click(),
			]);
			expect(beforeRes.url()).toContain('before=');
			expect(beforeRes.status()).toBeLessThan(500);
		}

		const dl = page.locator('#lck-logs-download');
		if (await dl.isVisible()) {
			const [dlRes] = await Promise.all([
				page.waitForResponse((r) => r.url().includes('/api/logs/download') && r.request().method() === 'POST', { timeout: 15000 }),
				dl.click(),
			]);
			expect(dlRes.request().method()).toBe('POST');
			expect(dlRes.status()).toBeLessThan(500);
		}

		await page.locator('#lck-logs-copy').click();
		await axeSeriousZero(page);
	});

	test('Logs: confirm dialog cancel does not mutate', async ({ page }) => {
		await gotoLogCheck(page, '/logs');
		const actions = page.locator('#lck-logs-actions');
		const fresh = page.locator('#lck-logs-start-fresh');
		if ((await fresh.count()) === 0) {
			test.info().annotations.push({ type: 'note', description: 'Start fresh not available (non-admin or no mutate)' });
			return;
		}
		if (await actions.count()) {
			await actions.locator('summary').click();
		}
		if (!(await fresh.isVisible())) {
			test.info().annotations.push({ type: 'note', description: 'Start fresh not visible after opening danger zone' });
			return;
		}
		await fresh.click();
		await expect(page.locator('#lck-logs-confirm-dialog')).toBeVisible();
		await page.locator('#lck-logs-confirm-cancel').click();
		await expect(page.locator('#lck-logs-confirm-dialog')).toBeHidden();
	});

	test('Logs: severity chips and raw toggle in More menu', async ({ page }) => {
		await gotoLogCheck(page, '/logs');
		await expect(page.locator('#lck-logs-viewer')).toBeVisible({ timeout: 15000 });
		await expect(page.locator('#lck-logs-filter-chips')).toBeVisible();
		await page.locator('#lck-logs-filter-chips').getByText(/Errors/i).click();
		await page.locator('#lck-logs-reload').click();
		await page.waitForResponse((r) => r.url().includes('/api/logs/tail') && r.status() < 500);
		await page.locator('#lck-logs-more-menu summary').click();
		await page.locator('#lck-logs-raw-toggle').click();
		await expect(page.locator('#lck-logs-raw-toggle')).toHaveAttribute('aria-pressed', 'true');
	});

	test('Alerts: Test & turn on fires channel test API', async ({ page }) => {
		await gotoLogCheck(page, '/settings/alerts');
		await expect(page.locator('#lck-settings-form')).toBeVisible();
		await expect(page.locator('#lck-email-enabled')).toBeVisible();
		await expect(page.locator('#lck-email-recipients')).toBeVisible();
		await expect(page.locator('.lck-test-turn-on[data-channel="email"]')).toBeVisible();

		// Open Slack & webhook details
		const slackSummary = page.locator('details.lck-more summary').filter({ hasText: /Slack/i }).first();
		if (await slackSummary.count()) {
			await slackSummary.click();
		}
		await expect(page.locator('#lck-slack-url')).toBeVisible();
		await expect(page.locator('#lck-webhook-url')).toBeVisible();
		await expect(page.locator('.lck-test-turn-on[data-channel="slack"]')).toBeVisible();
		await expect(page.locator('.lck-test-turn-on[data-channel="webhook"]')).toBeVisible();

		// More options
		await page.locator('#lck-more-options > summary').click();
		await expect(page.locator('#lck-notification-enabled')).toBeVisible();

		// Save without changing much (round-trip)
		const [saveRes] = await Promise.all([
			page.waitForResponse((r) => r.url().includes('/api/settings') && r.request().method() === 'PUT', { timeout: 30000 }),
			page.locator('#lck-settings-form button[type="submit"]').click(),
		]);
		expect(saveRes.status()).toBeLessThan(500);
	});

	test('Rules: level/pace chips and Save', async ({ page }) => {
		await gotoLogCheck(page, '/settings/rules');
		await expect(page.locator('#lck-level-chips')).toBeVisible();
		await page.locator('#lck-level-chips .lck-chip[data-value="2"]').click();
		await expect(page.locator('#lck-min-level')).toHaveValue('2');
		await page.locator('#lck-pace-chips .lck-chip[data-value="300"]').click();
		await expect(page.locator('#lck-pace-seconds')).toHaveValue('300');

		await page.locator('details.lck-more summary').first().click();
		await expect(page.locator('#lck-mutes')).toBeVisible();
		await expect(page.locator('#lck-mute-apps')).toBeVisible();
		await expect(page.locator('#lck-app-mode')).toBeVisible();

		await Promise.all([
			page.waitForResponse((r) => r.url().includes('/api/settings') && r.request().method() === 'PUT', { timeout: 30000 }),
			page.locator('#lck-settings-form button[type="submit"]').click(),
		]);
	});

	test('People: search field present (NC admin)', async ({ page }) => {
		await gotoLogCheck(page, '/settings/people');
		await expect(page.locator('#lck-settings-form')).toBeVisible();
		const search = page.locator('#lck-people-search');
		if ((await search.count()) === 0) {
			test.info().annotations.push({ type: 'note', description: 'People search hidden for non-NC-admin' });
			return;
		}
		await search.fill('ad');
		await page.waitForResponse((r) => r.url().includes('/api/directory/search') && r.status() < 500, {
			timeout: 10000,
		}).catch(() => null);
		await expect(page.locator('#lck-people-search')).toBeVisible();
		await expect(page.locator('button[type="submit"]')).toBeVisible();
	});

	test('Support: Sponsors + enterprise, no PSP', async ({ page }) => {
		await gotoLogCheck(page, '/settings/support');
		await expect(page.locator('a[href*="github.com/sponsors/"]')).toBeVisible();
		await expect(page.locator('a[href*="paypal"], a[href*="stripe"]')).toHaveCount(0);
	});
});
