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

	test('mobile 320: atomic control labels never break mid-word (lck-mf-20)', async ({ page }) => {
		// clipped_control_text_at_screen_edge — the .lck-main overflow-wrap:anywhere
		// prose guard inherited into the 'Check again' CTA and shredded it
		// ('Sprawdź' / 'ponowni' / 'e' at pl/320). Controls must keep
		// overflow-wrap:normal and the summary row must offer a wrap path.
		await page.setViewportSize({ width: 320, height: 800 });
		await gotoLogCheck(page, '/');
		const probe = await page.evaluate(() => {
			const summary = document.querySelector('.lck-health-summary');
			const btn = document.querySelector('.lck-health-summary .lck-btn');
			if (!summary || !btn) {
				return { ok: false, reason: 'health-summary/CTA not rendered' };
			}
			const sCs = getComputedStyle(summary);
			const bCs = getComputedStyle(btn);
			const badge = summary.querySelector('.lck-badge');
			const badgeCs = badge ? getComputedStyle(badge) : null;
			// Natural min-content width of the CTA label: longest word rendered
			// on one line in the same font.
			const longest = btn.textContent.trim().split(/\s+/).sort((a, b) => b.length - a.length)[0] || '';
			const ruler = document.createElement('span');
			ruler.style.cssText = 'position:absolute;visibility:hidden;white-space:nowrap;'
				+ `font:${bCs.fontWeight} ${bCs.fontSize}/${bCs.lineHeight} ${bCs.fontFamily}`;
			ruler.textContent = longest;
			document.body.appendChild(ruler);
			const wordW = ruler.getBoundingClientRect().width;
			ruler.remove();
			const b = btn.getBoundingClientRect();
			const padX = parseFloat(bCs.paddingLeft) + parseFloat(bCs.paddingRight)
				+ parseFloat(bCs.borderLeftWidth) + parseFloat(bCs.borderRightWidth);
			const badgeR = badge ? badge.getBoundingClientRect() : null;
			return {
				ok: true,
				flexWrap: sCs.flexWrap,
				btnWrap: bCs.overflowWrap,
				btnWhiteSpace: bCs.whiteSpace,
				badgeWrap: badgeCs ? badgeCs.overflowWrap : 'absent',
				btnWidth: b.width,
				wordFloor: wordW + padX,
				btnTop: b.top,
				badgeBottom: badgeR ? badgeR.bottom : null,
				textOverflow: btn.scrollWidth - btn.clientWidth,
			};
		});
		expect(probe.ok, probe.reason || 'probe failed').toBeTruthy();
		expect(probe.flexWrap).toBe('wrap');
		expect(probe.btnWrap).toBe('normal');
		expect(probe.btnWhiteSpace).toBe('normal');
		expect(probe.badgeWrap).toBe('normal');
		// The CTA keeps at least its longest word + chrome — never a sliver.
		expect(probe.btnWidth).toBeGreaterThanOrEqual(probe.wordFloor - 2);
		// No label text overflowing the control box.
		expect(probe.textOverflow).toBeLessThanOrEqual(1);
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
