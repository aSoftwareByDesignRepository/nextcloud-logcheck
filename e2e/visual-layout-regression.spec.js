// @ts-check
/**
 * Visual layout regression — geometry fingerprints (stable across CI; no pixel baselines).
 * Catches clipped sidebars, stacked desktop nav, zero-height mains, modal off-screen.
 */
const { test, expect } = require('@playwright/test');
const { login, gotoLogCheck, axeSeriousZero } = require('./helpers');
const { setUserTheme, resetUserTheme } = require('./helpers/theming');

const routes = [
	{ id: 'home', path: '/' },
	{ id: 'logs', path: '/logs' },
	{ id: 'alerts', path: '/settings/alerts' },
	{ id: 'rules', path: '/settings/rules' },
	{ id: 'people', path: '/settings/people' },
	{ id: 'support', path: '/settings/support' },
];

const viewports = [
	{ name: 'mobile', width: 320, height: 640 },
	{ name: 'tablet', width: 768, height: 1024 },
	{ name: 'desktop', width: 1440, height: 900 },
	{ name: '4k', width: 2560, height: 1440 },
];

/**
 * @param {import('@playwright/test').Page} page
 */
async function layoutFingerprint(page) {
	return page.evaluate(() => {
		const content = document.querySelector('#content.app-logcheck');
		const nav = document.querySelector('#app-navigation.lck-nav');
		const app = document.querySelector('#app-content');
		const main = document.querySelector('#lck-main-content');
		const header = document.querySelector('.lck-page-header');
		if (!content || !app || !main) {
			return { ok: false, reason: 'missing shell nodes' };
		}
		const cr = content.getBoundingClientRect();
		const nr = nav ? nav.getBoundingClientRect() : null;
		const ar = app.getBoundingClientRect();
		const mr = main.getBoundingClientRect();
		const hr = header ? header.getBoundingClientRect() : null;
		const cs = getComputedStyle(content);
		return {
			ok: true,
			display: cs.display,
			gridCols: cs.gridTemplateColumns,
			contentW: cr.width,
			appW: ar.width,
			appH: ar.height,
			mainW: mr.width,
			mainH: mr.height,
			mainVisible: mr.height > 80 && mr.width > 100,
			headerVisible: hr ? hr.height > 20 : true,
			navSideBySide: nr ? Math.abs(ar.top - nr.top) < 48 && ar.left >= nr.right - 12 : true,
			appFillsWidth: ar.width >= cr.width * 0.85,
			docOverflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
		};
	});
}

test.describe('Visual layout regression', () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!process.env.E2E_USER && !process.env.LOGCHECK_E2E_USER, 'Set E2E_USER to run');
		const ok = await login(page);
		test.skip(!ok, 'Login failed');
	});

	test.afterEach(async ({ page }) => {
		try {
			await resetUserTheme(page);
		} catch (_) {
			/* best-effort */
		}
	});

	for (const vp of viewports) {
		test(`layout fingerprint @${vp.name} (${vp.width}px) all routes`, async ({ page }) => {
			test.setTimeout(180_000);
			await setUserTheme(page, 'dark-highcontrast');
			await page.setViewportSize({ width: vp.width, height: vp.height });

			for (const route of routes) {
				await gotoLogCheck(page, route.path);
				const fp = await layoutFingerprint(page);
				expect(fp.ok, `${route.id}: ${fp.reason || 'probe failed'}`).toBeTruthy();
				expect(fp.mainVisible, `${route.id}: main collapsed`).toBeTruthy();
				expect(fp.docOverflow, `${route.id}: horizontal scroll`).toBeLessThanOrEqual(2);

				if (vp.width >= 1024) {
					expect(fp.display).toBe('grid');
					expect(fp.navSideBySide, `${route.id}: nav stacked under content on desktop`).toBeTruthy();
				} else {
					expect(fp.appFillsWidth, `${route.id}: app pane too narrow on mobile`).toBeTruthy();
				}
			}
		});
	}

	test('confirm dialog visible and axe-clean in dark theme', async ({ page }) => {
		await setUserTheme(page, 'dark');
		await gotoLogCheck(page, '/logs');
		const btn = page.locator('#lck-logs-start-fresh');
		test.skip(!(await btn.count()), 'Start fresh not available');
		const actions = page.locator('#lck-logs-actions');
		if (await actions.count()) {
			await actions.locator('summary').click();
		}
		test.skip(!(await btn.isVisible()), 'Start fresh hidden');
		await btn.click();
		const dialog = page.locator('#lck-logs-confirm-dialog');
		await expect(dialog).toBeVisible();

		const geom = await page.evaluate(() => {
			const d = document.querySelector('#lck-logs-confirm-dialog');
			if (!d) {
				return null;
			}
			const r = d.getBoundingClientRect();
			const vh = window.innerHeight;
			const vw = window.innerWidth;
			return {
				w: r.width,
				h: r.height,
				inViewport: r.top >= 0 && r.left >= 0 && r.bottom <= vh + 2 && r.right <= vw + 2,
			};
		});
		expect(geom).not.toBeNull();
		expect(geom.w).toBeGreaterThan(200);
		expect(geom.h).toBeGreaterThan(100);
		expect(geom.inViewport).toBeTruthy();

		await page.setViewportSize({ width: 320, height: 640 });
		const geomMobile = await page.evaluate(() => {
			const d = document.querySelector('#lck-logs-confirm-dialog');
			if (!d) {
				return null;
			}
			const r = d.getBoundingClientRect();
			return { w: r.width, inViewport: r.left >= -2 && r.right <= window.innerWidth + 2 };
		});
		expect(geomMobile.w).toBeLessThanOrEqual(320);
		expect(geomMobile.inViewport).toBeTruthy();

		await axeSeriousZero(page);
		await page.locator('#lck-logs-confirm-cancel').click();
	});
});
