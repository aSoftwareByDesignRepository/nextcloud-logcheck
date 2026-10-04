// @ts-check
/**
 * ATLAS_RENDERED_SURFACE_CONTRACT — HealthCheck (logcheck) page surfaces.
 *
 * Asserts the *rendered* truth of each app page: content lists keep markers,
 * selects vertically centre their value, icons render non-zero, form controls
 * are not centered by shell leaks. DOM-only specs pass on visually broken
 * pages (marker resets, sunken selects, 0×0 icons) — this catches them.
 *
 * Every logcheck page surface is listed in SURFACES. Marker-less list designs
 * (health card grid, chip lists, support link rows, nav menus) are the app
 * design language and opt out via listAllow — never silently skipped.
 */
const { test } = require('@playwright/test');
const { login, gotoLogCheck } = require('./helpers');
const { assertAtlasRenderedSurface } = require('../../_shared/e2e/atlas-rendered-surface-contract');

const SURFACES = [
	['/', 'home'],
	['/logs', 'logs'],
	['/settings/alerts', 'settings-alerts'],
	['/settings/rules', 'settings-rules'],
	['/settings/people', 'settings-people'],
	['/settings/support', 'settings-support'],
];

const CONTRACT_OPTS = {
	content: '#lck-main-content',
	navExclude:
		'#app-navigation, nav, .lck-nav, .lck-settings-nav, .lck-breadcrumb, .lck-nav-footer',
	// Marker-less by design (card grid / chip rows / styled link rows —
	// css/app.css sets list-style:none intentionally on these components).
	listAllow:
		'.lck-health-grid, .lck-chip-list, .lck-people-results, .lck-support-links, ' +
		'.lck-nav__list, .lck-nav__sublist, .lck-nav-footer__menu, ' +
		'.lck-breadcrumb__list, .lck-logs-file-list, .lck-more',
};

test.describe('ATLAS_RENDERED_SURFACE_CONTRACT', () => {
	test.beforeEach(async ({ page }) => {
		const ok = await login(page);
		test.skip(!ok, 'E2E credentials not configured');
	});

	for (const [path, name] of SURFACES) {
		test(`ATLAS_RENDERED_SURFACE_CONTRACT ${name}`, async ({ page }) => {
			await gotoLogCheck(page, path);
			await assertAtlasRenderedSurface(page, CONTRACT_OPTS);
		});
	}

	test('ATLAS_RENDERED_SURFACE_CONTRACT access-denied is styled', async ({ page, browser }) => {
		const deniedUser = process.env.E2E_DENIED_USER || 'lck_denied';
		const deniedPass = process.env.E2E_DENIED_PASS || 'LckDenied!2026';
		test.skip(!deniedUser || !deniedPass, 'E2E_DENIED_USER + E2E_DENIED_PASS not set');

		const base = (page.url() && page.url().startsWith('http'))
			? new URL(page.url()).origin
			: (process.env.PLAYWRIGHT_BASE_URL || process.env.E2E_BASE_URL || 'http://localhost:8080');
		const ctx = await browser.newContext();
		const deniedPage = await ctx.newPage();
		try {
			await deniedPage.goto(base + '/login', { waitUntil: 'domcontentloaded' });
			await deniedPage.locator('#user, input[name="user"]').first().fill(deniedUser);
			await deniedPage.locator('#password, input[name="password"]').first().fill(deniedPass);
			await deniedPage.locator('button[type="submit"], input[type="submit"], button.login-button').first().click();
			await deniedPage.waitForURL(/apps\/|index\.php/, { timeout: 45000 });
			await deniedPage.goto(base + '/index.php/apps/logcheck/', { waitUntil: 'domcontentloaded' });

			// Shell styles must be registered even though the middleware path
			// never runs PageController::registerAssets — an unstyled deny shows
			// the sr-only live regions and an unstyled callout/button.
			await deniedPage.locator('.lck-app--denied .lck-callout[role="alert"]').waitFor({ state: 'visible' });
			const styled = await deniedPage.evaluate(() => {
				const sr = document.querySelector('#lck-alert-region');
				const skip = document.querySelector('.lck-skip-link');
				const btn = document.querySelector('.lck-callout .lck-btn--primary');
				const srCs = sr ? getComputedStyle(sr) : null;
				const skipCs = skip ? getComputedStyle(skip) : null;
				const btnCs = btn ? getComputedStyle(btn) : null;
				return {
					srHidden: !!srCs && (srCs.clip !== 'auto' || srCs.position === 'absolute' || sr.offsetHeight <= 1),
					skipPositioned: !!skipCs && skipCs.position !== 'static',
					btnPadded: !!btnCs && parseFloat(btnCs.paddingTop) > 0,
				};
			});
			test.expect(styled.srHidden, 'live region must be sr-only hidden (shell-chrome.css loaded)').toBe(true);
			test.expect(styled.skipPositioned, 'skip link must be positioned off-flow (shell-chrome.css loaded)').toBe(true);
			test.expect(styled.btnPadded, 'primary CTA must be styled (form-controls/page-patterns loaded)').toBe(true);
		} finally {
			await ctx.close();
		}
	});
});
