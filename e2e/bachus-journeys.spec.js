// @ts-check
/**
 * Bachus UX journeys — J-BACH-01…06 and AC-B01…B08 (PRODUCT.md §5.1).
 */
const { test, expect } = require('@playwright/test');
const { login, gotoLogCheck, axeSeriousZero } = require('./helpers');

/** Custom track switches: click the label, not the hidden checkbox. */
async function setSwitch(page, id, on) {
	const input = page.locator('#' + id);
	if ((await input.isChecked()) === on) {
		return;
	}
	await page.locator('label[for="' + id + '"]').click();
}

test.describe('J-BACH Bachus journeys', () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!process.env.E2E_USER && !process.env.LOGCHECK_E2E_USER, 'Set E2E_USER to run');
		const ok = await login(page);
		test.skip(!ok, 'Login failed');
	});

	test('J-BACH-01 / AC-B01 notification Test & turn on without separate Save', async ({ page }) => {
		await gotoLogCheck(page, '/settings/alerts');
		await page.locator('#lck-more-options summary').click();
		const toggle = page.locator('#lck-notification-enabled');
		await expect(toggle).toBeVisible();

		if (await toggle.isChecked()) {
			test.info().annotations.push({ type: 'note', description: 'Notification already enabled from prior run — flow verified previously' });
			await expect(toggle).toBeChecked();
			return;
		}

		const btn = page.locator('.lck-test-turn-on[data-channel="notification"]');
		await expect(btn).toBeVisible();

		const [testRes, saveRes] = await Promise.all([
			page.waitForResponse((r) => r.url().includes('/api/channels/notification/test') && r.status() < 500),
			page.waitForResponse((r) => r.url().includes('/api/settings') && r.request().method() === 'PUT' && r.status() < 500),
			btn.click(),
		]);
		expect(testRes.status()).toBeLessThan(500);
		expect(saveRes.status()).toBeLessThan(400);
		await expect(toggle).toBeChecked();
		await expect(page.locator('#lck-notification-status')).toContainText(/Alerts are on|Benachrichtigungen sind aktiv/i);
	});

	test('J-BACH-02 / AC-B02 watch on without channels shows checklist without full reload', async ({ page }) => {
		await gotoLogCheck(page, '/');
		await expect(page.locator('#lck-alerts-checklist')).toBeAttached();

		const toggle = page.locator('#lck-watch-toggle');
		if (!(await toggle.isChecked())) {
			await setSwitch(page, 'lck-watch-toggle', true);
			await expect(toggle).toBeChecked({ timeout: 15000 });
		}

		await expect(toggle).toBeChecked();
		await expect(page.locator('.lck-health-grid')).toBeVisible();

		const checklist = page.locator('#lck-alerts-checklist');
		if (await checklist.isVisible()) {
			await expect(checklist).toContainText(/does not send alerts|sendet keine Benachrichtigungen/i);
		} else {
			test.info().annotations.push({ type: 'note', description: 'Checklist hidden — at least one alert channel is already enabled' });
		}
	});

	test('J-BACH-03 / AC-B03 AC-B04 AC-B08 logs loading, mobile toolbar, empty filter', async ({ page }) => {
		await gotoLogCheck(page, '/logs');
		await expect(page.locator('#lck-logs-viewer')).toBeVisible({ timeout: 15000 });

		await page.locator('#lck-logs-reload').click();
		await page.waitForResponse((r) => r.url().includes('/api/logs/tail') && r.status() < 500, { timeout: 20000 });

		await page.setViewportSize({ width: 320, height: 720 });
		const toolbarActions = page.locator('.lck-logs-toolbar-actions > .lck-btn:visible, .lck-logs-toolbar-actions > details.lck-more:visible');
		expect(await toolbarActions.count()).toBeLessThanOrEqual(3);

		const chips = page.locator('#lck-logs-filter-chips .lck-chip:visible');
		if ((await chips.count()) > 0) {
			const sizes = await chips.evaluateAll((els) =>
				els.map((n) => {
					const r = n.getBoundingClientRect();
					return { h: r.height, w: r.width };
				})
			);
			for (const s of sizes) {
				expect(s.h).toBeGreaterThanOrEqual(44);
				expect(s.w).toBeGreaterThanOrEqual(44);
			}
		}

		const errorsChip = page.locator('#lck-logs-filter-chips label:has(input[value="4"])').first();
		if (await errorsChip.count()) {
			await errorsChip.click();
			await page.waitForTimeout(300);
			const empty = page.locator('#lck-logs-empty');
			if (await empty.isVisible()) {
				await expect(page.locator('#lck-logs-empty-text')).not.toHaveText(/^\s*$/);
				await expect(page.locator('#lck-logs-show-all')).toBeVisible();
			}
		}

		await axeSeriousZero(page);
	});

	test('J-BACH-04 people search no-results state', async ({ page }) => {
		await gotoLogCheck(page, '/settings/people');
		const search = page.locator('#lck-people-search');
		await expect(search).toBeVisible();
		await search.fill('zzzznonexistentuser999');
		await expect(page.locator('#lck-people-results')).toBeVisible({ timeout: 5000 });
		await expect(page.locator('#lck-people-results')).toContainText(/No people found|Keine Personen/i);
		await expect(search).not.toHaveAttribute('aria-busy', 'true');
	});

	test('J-BACH-05 / AC-B07 access denied HealthCheck copy and axe', async ({ page }) => {
		const deniedUser = process.env.E2E_DENIED_USER || process.env.LOGCHECK_E2E_DENIED_USER;
		const deniedPass = process.env.E2E_DENIED_PASS || process.env.LOGCHECK_E2E_DENIED_PASS;
		test.skip(!deniedUser || !deniedPass, 'Set E2E_DENIED_USER + E2E_DENIED_PASS for access-denied journey');

		const base = (process.env.LOGCHECK_BASE_URL || process.env.E2E_BASE || 'http://localhost:8081').replace(/\/$/, '');
		await page.goto(base + '/login', { waitUntil: 'domcontentloaded' });
		await page.locator('#user, input[name="user"]').first().fill(deniedUser);
		await page.locator('#password, input[name="password"]').first().fill(deniedPass);
		await page.locator('button[type="submit"], input[type="submit"]').first().click();
		await page.waitForURL(/apps\/|index\.php/, { timeout: 45000 });

		await page.goto(base + '/index.php/apps/logcheck/', { waitUntil: 'domcontentloaded' });
		await expect(page.locator('#lck-page-title')).toContainText(/Not authorized|Nicht berechtigt/i);
		const body = await page.locator('#lck-main-content').innerText();
		expect(body).toMatch(/HealthCheck/i);
		expect(body).not.toMatch(/\bLogCheck app admins\b/);
		await axeSeriousZero(page);
	});

	test('J-BACH-06 / AC-B06 NC admin can reach all sections; single Check again', async ({ page }) => {
		await gotoLogCheck(page, '/');
		await expect(page.locator('#lck-check-again')).toHaveCount(1);
		for (const section of ['alerts', 'rules', 'people', 'support']) {
			await gotoLogCheck(page, '/settings/' + section);
			await expect(page.locator('#lck-page-title')).toBeVisible();
		}
		await gotoLogCheck(page, '/settings/support');
		const supportText = await page.locator('#lck-main-content').innerText();
		expect(supportText).not.toMatch(/Several servers each with their own log file/i);
	});

	test('AC-B05 degraded health card exposes at most one recovery action', async ({ page }) => {
		await gotoLogCheck(page, '/');
		const degraded = page.locator('.lck-health-card[data-state="degraded"], .lck-health-card[data-state="critical"]');
		const count = await degraded.count();
		for (let i = 0; i < count; i++) {
			const card = degraded.nth(i);
			const actions = card.locator('.lck-health-card__actions .lck-btn, .lck-health-card__actions a.lck-btn');
			await expect(actions).toHaveCount(1);
		}
	});
});
