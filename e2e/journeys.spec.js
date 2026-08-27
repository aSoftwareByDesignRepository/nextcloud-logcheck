// @ts-check
const { test, expect } = require('@playwright/test');
const { login, gotoLogCheck, axeSeriousZero } = require('./helpers');

test.describe('J-LCK journeys', () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!process.env.E2E_USER && !process.env.LOGCHECK_E2E_USER, 'Set E2E_USER to run');
		const ok = await login(page);
		test.skip(!ok, 'Login failed');
	});

	test('J-LCK-01 home health and watching', async ({ page }) => {
		await gotoLogCheck(page, '/');
		await expect(page.locator('.lck-health-grid')).toBeVisible({ timeout: 15000 });
		await expect(page.locator('.lck-health-card[data-probe="log"]')).toBeVisible();
		await expect(page.locator('.lck-health-card[data-probe="disk"]')).toBeVisible();
		await expect(page.locator('.lck-health-card[data-probe="https"]')).toBeVisible();
		await expect(page.locator('.lck-health-card[data-probe="updates"]')).toBeVisible();
		await expect(page.locator('#lck-watch-toggle')).toBeVisible();
		await expect(page.locator('.lck-setup-cta')).toHaveCount(0);
		// Channel fields belong on Alerts — never duplicate email/Slack setup on Home.
		await expect(page.locator('#lck-turn-on-form, #lck-setup-email, #lck-setup-slack')).toHaveCount(0);
		// Healthy installs must not nag about multi-server topology on every visit.
		await expect(page.locator('.lck-topology-note')).toHaveCount(0);
		// One teacher: no duplicate “You’re all set” block beside the switch.
		await expect(page.locator('#lck-summary-title')).toHaveCount(0);
		// Home chrome: no Role/Timezone strip competing with the primary job.
		await expect(page.locator('.lck-scope-strip')).toHaveCount(0);
		await expect(page.locator('.lck-brand__title')).toContainText(/HealthCheck/i);
		await expect(page.locator('.lck-nav a.lck-nav__link').first()).toBeVisible();
	});

	test('J-HCK-01 health cards have real states', async ({ page }) => {
		await gotoLogCheck(page, '/');
		await expect(page.locator('.lck-health-summary')).toBeVisible();
		const cards = page.locator('.lck-health-card');
		await expect(cards).toHaveCount(7);
		const states = await cards.evaluateAll((els) =>
			els.map((el) => el.getAttribute('data-state'))
		);
		for (const state of states) {
			expect(['ok', 'degraded', 'critical', 'unknown']).toContain(state);
		}
		const httpsState = await page.locator('.lck-health-card[data-probe="https"]').getAttribute('data-state');
		expect(httpsState).not.toBeNull();
		await axeSeriousZero(page);
	});

	test('J-LCK-05 settings sections reachable', async ({ page }) => {
		for (const section of ['alerts', 'rules', 'people', 'support']) {
			await gotoLogCheck(page, '/settings/' + section);
			await expect(page.locator('#lck-page-title')).toBeVisible({ timeout: 15000 });
		}
	});

	test('J-LCK-05b Support us is GitHub Sponsors only', async ({ page }) => {
		await gotoLogCheck(page, '/settings/support');
		await expect(page.locator('#lck-support-title')).toBeVisible({ timeout: 15000 });
		await expect(page.locator('#lck-support-donate-title')).toBeVisible();
		await expect(page.locator('#lck-support-enterprise-title')).toBeVisible();
		await expect(page.locator('.lck-support__block[role="region"]')).toHaveCount(2);
		const sponsors = page.locator('.lck-support a[href*="github.com/sponsors/"]');
		await expect(sponsors.first()).toBeVisible();
		await expect(sponsors.first()).toHaveAttribute('rel', /noopener/);
		const body = await page.locator('.lck-support').innerText();
		expect(body.toLowerCase()).not.toMatch(/paypal|stripe/);
		await expect(page.locator('.lck-support a[href*="paypal"]')).toHaveCount(0);
		await expect(page.locator('.lck-support a[href*="stripe"]')).toHaveCount(0);
		const emptyLis = await page.locator('.lck-support li:empty').count();
		expect(emptyLis).toBe(0);
		await axeSeriousZero(page);
	});

	test('J-LCK-07 Rules Advanced filters closed by default', async ({ page }) => {
		await gotoLogCheck(page, '/settings/rules');
		await expect(page.locator('#lck-mutes')).toBeHidden();
		await page.locator('details.lck-more summary').first().click();
		await expect(page.locator('#lck-mutes')).toBeVisible();
	});

	test('J-LCK-11 no horizontal scroll at 320', async ({ page }) => {
		await page.setViewportSize({ width: 320, height: 720 });
		await gotoLogCheck(page, '/');
		const overflow = await page.evaluate(() => {
			const el = document.scrollingElement || document.documentElement;
			return el.scrollWidth - el.clientWidth;
		});
		expect(overflow).toBeLessThanOrEqual(2);
	});

	test('J-LCK-11b no horizontal scroll at key viewports', async ({ page }) => {
		const widths = [320, 375, 414, 768, 1024, 1280];
		const paths = ['/', '/settings/alerts', '/settings/people'];
		for (const width of widths) {
			await page.setViewportSize({ width, height: 800 });
			for (const path of paths) {
				await gotoLogCheck(page, path);
				const overflow = await page.evaluate(() => {
					const el = document.scrollingElement || document.documentElement;
					return el.scrollWidth - el.clientWidth;
				});
				expect(overflow, `overflow at ${width}px ${path}`).toBeLessThanOrEqual(2);
			}
		}
	});

	test('J-LCK-11c LogCheck controls meet 44px min height on mobile', async ({ page }) => {
		await page.setViewportSize({ width: 375, height: 800 });
		await gotoLogCheck(page, '/settings/alerts');
		const undersized = await page.evaluate(() => {
			const nodes = [...document.querySelectorAll('#app-content .lck-btn, #app-content .lck-chip, #app-content .lck-more > summary')];
			return nodes
				.filter((n) => n.offsetParent !== null)
				.map((n) => {
					const r = n.getBoundingClientRect();
					return { h: Math.round(r.height), w: Math.round(r.width), cls: (n.className || '').toString().slice(0, 48) };
				})
				.filter((x) => x.h < 44 || x.w < 44);
		});
		expect(undersized).toEqual([]);
	});

	test('axe serious=0 on settings alerts', async ({ page }) => {
		await gotoLogCheck(page, '/settings/alerts');
		await axeSeriousZero(page);
	});
});
