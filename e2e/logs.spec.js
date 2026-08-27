// @ts-check
const { test, expect } = require('@playwright/test');
const { login, gotoLogCheck, axeSeriousZero } = require('./helpers');

test.describe('J-LCK-20 Logs browser', () => {
	test.beforeEach(async ({ page }) => {
		const ok = await login(page);
		test.skip(!ok, 'E2E credentials not configured');
	});

	test('logs page shows viewer, search, and is axe-clean', async ({ page }) => {
		await gotoLogCheck(page, '/logs');
		await expect(page.locator('#lck-main-content')).toBeVisible();
		await expect(page.locator('.lck-nav__link[href*="/logs"], .lck-nav__link.is-active').first()).toBeVisible();
		await expect(page.locator('.lck-logs-files')).toBeVisible();
		const picker = page.locator('#lck-logs-file-list');
		await expect(picker).toBeVisible();
		await expect(page.getByRole('heading', { name: /Which log\?|Welche Protokolldatei/i })).toBeVisible();
		const viewer = page.locator('#lck-logs-viewer');
		const unreadable = page.locator('.lck-logs .lck-callout--warning, .lck-logs .lck-callout--info');
		const hasViewer = await viewer.count();
		if (hasViewer) {
			await expect(viewer).toBeVisible();
			await expect(page.locator('#lck-logs-search')).toBeVisible();
			await expect(page.locator('#lck-logs-copy')).toBeVisible();
			await page.fill('#lck-logs-search', 'logcheck');
			await page.click('#lck-logs-search-btn');
			await expect(page.locator('#lck-logs-status')).toBeVisible();
		} else {
			await expect(unreadable.first()).toBeVisible();
		}
		await axeSeriousZero(page);
	});

	test('file picker keeps radiogroup semantics and live actions when current selected', async ({ page }) => {
		await gotoLogCheck(page, '/logs');
		const list = page.locator('#lck-logs-file-list');
		await expect(list).toHaveAttribute('role', 'radiogroup');
		const radios = page.locator('input[name="lck-logs-file"]');
		const count = await radios.count();
		test.skip(count === 0, 'No log files listed (backend unsupported or missing)');
		const live = page.locator('input[name="lck-logs-file"][data-live="1"]');
		await expect(live.first()).toBeChecked();
		const actions = page.locator('#lck-logs-actions');
		if (await actions.count()) {
			await expect(actions).toBeVisible();
		}
		await expect(page.locator('#lck-logs-archive-banner')).toHaveAttribute('hidden', /.*/);
	});

	test('selecting older copy shows archive banner and hides live actions', async ({ page }) => {
		await gotoLogCheck(page, '/logs');
		const older = page.locator('input[name="lck-logs-file"][data-live="0"]:not([disabled])').first();
		test.skip(!(await older.count()), 'No older copies present on this instance');
		await older.check();
		await expect(page.locator('#lck-logs-archive-banner')).not.toHaveAttribute('hidden', /.*/);
		await expect(page.locator('#lck-logs-archive-banner')).toBeVisible();
		const actions = page.locator('#lck-logs-actions');
		if (await actions.count()) {
			await expect(actions).toHaveAttribute('hidden', /.*/);
		}
		await expect(page.locator('#lck-logs-delete-copy')).toBeVisible();
		await expect(page.locator('#lck-logs-name')).not.toHaveText(/^\s*$/);
		await axeSeriousZero(page);
	});

	test('start-fresh confirm dialog requires exact word', async ({ page }) => {
		await gotoLogCheck(page, '/logs');
		const btn = page.locator('#lck-logs-start-fresh');
		test.skip(!(await btn.count()), 'Start fresh not available (permissions / backend)');
		const actions = page.locator('#lck-logs-actions');
		if (await actions.count()) {
			await actions.locator('summary').click();
		}
		test.skip(!(await btn.isVisible()), 'Start fresh hidden (permissions / backend)');
		await btn.click();
		const dialog = page.locator('#lck-logs-confirm-dialog');
		await expect(dialog).toBeVisible();
		await page.fill('#lck-logs-confirm-input', 'wrong');
		await page.click('#lck-logs-confirm-ok');
		// Dialog stays open on mismatch
		await expect(dialog).toBeVisible();
		await page.click('#lck-logs-confirm-cancel');
	});

	test('remove copy deletes and reloads the page', async ({ page }) => {
		await gotoLogCheck(page, '/logs');
		const older = page.locator('input[name="lck-logs-file"][data-live="0"]:not([disabled])').first();
		test.skip(!(await older.count()), 'No older copies present on this instance');
		const deletedId = await older.inputValue();
		await older.check();
		const btn = page.locator('#lck-logs-delete-copy');
		test.skip(!(await btn.count()), 'Remove copy not available');
		await btn.click();
		const dialog = page.locator('#lck-logs-confirm-dialog');
		await expect(dialog).toBeVisible();
		await page.fill('#lck-logs-confirm-input', 'DELETE_COPY');
		await Promise.all([
			page.waitForURL(/\/logs/, { timeout: 30000 }),
			page.click('#lck-logs-confirm-ok'),
		]);
		await page.locator('.lck-logs, .lck-callout').first().waitFor({ state: 'visible', timeout: 20000 });
		await expect(page.locator(`input[name="lck-logs-file"][value="${deletedId}"]`)).toHaveCount(0);
	});
});
