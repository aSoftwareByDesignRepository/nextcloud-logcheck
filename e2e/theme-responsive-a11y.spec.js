// @ts-check
/**
 * Theme × viewport × WCAG 2.1 AA gauntlet for LogCheck.
 *
 * Proves for every selectable NC theme and every shipped route:
 *  - theme actually switched (body[data-theme-*]),
 *  - design tokens resolve from Nextcloud --color-* (tints mix into main-bg),
 *  - zero horizontal overflow from 320 px through 4K,
 *  - LogCheck chrome touch targets ≥ 44×44,
 *  - zero axe WCAG 2.1 A/AA serious/critical inside #app-content,
 *  - custom accent + custom CSS variable overrides adapt without breakage.
 */
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { login, gotoLogCheck } = require('./helpers');
const {
	setUserTheme,
	resetUserTheme,
	USER_THEMES,
} = require('./helpers/theming');

const routes = [
	{ id: 'home', path: '/' },
	{ id: 'logs', path: '/logs' },
	{ id: 'alerts', path: '/settings/alerts' },
	{ id: 'rules', path: '/settings/rules' },
	{ id: 'people', path: '/settings/people' },
	{ id: 'support', path: '/settings/support' },
];

const overflowViewports = [
	{ width: 320, height: 640 },
	{ width: 375, height: 812 },
	{ width: 768, height: 1024 },
	{ width: 1024, height: 768 },
	{ width: 1440, height: 900 },
	{ width: 2560, height: 1440 },
];

const axeViewports = [
	{ width: 320, height: 640 },
	{ width: 768, height: 1024 },
	{ width: 1280, height: 800 },
];

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} label
 */
async function expectNoHorizontalOverflow(page, label) {
	const overflow = await page.evaluate(() => {
		const doc = document.documentElement;
		const app = document.querySelector('#app-content.lck-app, #content.app-logcheck #app-content');
		const shell = document.querySelector('#app-content-wrapper.lck-shell, #app-content-wrapper');
		const appOx = app ? getComputedStyle(app).overflowX : '';
		const shellOx = shell ? getComputedStyle(shell).overflowX : '';
		return {
			doc: doc.scrollWidth - doc.clientWidth,
			app: app ? app.scrollWidth - app.clientWidth : 0,
			shell: shell ? shell.scrollWidth - shell.clientWidth : 0,
			appClipped: appOx === 'hidden' || appOx === 'clip',
			shellClipped: shellOx === 'hidden' || shellOx === 'clip',
		};
	});
	expect(overflow.doc, `document horizontal overflow at ${label}`).toBeLessThanOrEqual(2);
	if (!overflow.appClipped) {
		expect(overflow.app, `#app-content overflow at ${label}`).toBeLessThanOrEqual(2);
	}
	if (!overflow.shellClipped) {
		expect(overflow.shell, `.lck-shell overflow at ${label}`).toBeLessThanOrEqual(2);
	}
}

/**
 * @param {import('@playwright/test').Page} page
 */
async function assertThemeTokensResolved(page) {
	const tokens = await page.evaluate(() => {
		const bodyCs = getComputedStyle(document.body);
		const rootCs = getComputedStyle(document.documentElement);
		const el = document.querySelector('#content.app-logcheck, #app-content.lck-app');
		const cs = el ? getComputedStyle(el) : bodyCs;
		const shell = document.querySelector('#app-content-wrapper.lck-shell, #app-content.lck-app');
		return {
			ncBg: bodyCs.getPropertyValue('--color-main-background').trim(),
			ncText: bodyCs.getPropertyValue('--color-main-text').trim(),
			ncPrimary: bodyCs.getPropertyValue('--color-primary-element').trim(),
			ncElementError: bodyCs.getPropertyValue('--color-element-error').trim(),
			ncErrorTint: bodyCs.getPropertyValue('--color-error').trim(),
			bgSoft: cs.getPropertyValue('--lck-bg-soft').trim() || bodyCs.getPropertyValue('--lck-bg-soft').trim(),
			text: cs.getPropertyValue('--lck-text').trim() || bodyCs.getPropertyValue('--lck-text').trim(),
			muted: cs.getPropertyValue('--lck-muted').trim() || bodyCs.getPropertyValue('--lck-muted').trim(),
			primary: cs.getPropertyValue('--lck-primary').trim() || bodyCs.getPropertyValue('--lck-primary').trim(),
			tintInfo: cs.getPropertyValue('--lck-tint-info').trim() || bodyCs.getPropertyValue('--lck-tint-info').trim(),
			tintSuccess: cs.getPropertyValue('--lck-tint-success').trim() || bodyCs.getPropertyValue('--lck-tint-success').trim(),
			dangerFill: cs.getPropertyValue('--lck-danger-fill').trim() || bodyCs.getPropertyValue('--lck-danger-fill').trim(),
			dangerOnFill: cs.getPropertyValue('--lck-danger-on-fill').trim() || bodyCs.getPropertyValue('--lck-danger-on-fill').trim(),
			dangerInk: cs.getPropertyValue('--lck-danger-ink').trim() || bodyCs.getPropertyValue('--lck-danger-ink').trim(),
			scrim: cs.getPropertyValue('--lck-scrim').trim() || bodyCs.getPropertyValue('--lck-scrim').trim(),
			shadowSm: cs.getPropertyValue('--lck-shadow-sm').trim() || bodyCs.getPropertyValue('--lck-shadow-sm').trim(),
			touch: rootCs.getPropertyValue('--lck-touch').trim(),
			touchLg: rootCs.getPropertyValue('--lck-touch-lg').trim(),
			focus: cs.getPropertyValue('--lck-focus').trim() || bodyCs.getPropertyValue('--lck-focus').trim(),
			shellMax: shell ? getComputedStyle(shell).maxWidth : '',
		};
	});
	expect(tokens.ncBg, 'NC --color-main-background').not.toEqual('');
	expect(tokens.ncText, 'NC --color-main-text').not.toEqual('');
	expect(tokens.ncPrimary, 'NC --color-primary-element').not.toEqual('');
	expect(tokens.bgSoft, 'lck-bg-soft').not.toEqual('');
	expect(tokens.text, 'lck-text').not.toEqual('');
	expect(tokens.primary, 'lck-primary').not.toEqual('');
	expect(tokens.muted, 'lck-muted').not.toEqual('');
	expect(tokens.tintInfo, 'tint-info must resolve').not.toEqual('');
	expect(tokens.tintSuccess, 'tint-success must resolve').not.toEqual('');
	expect(tokens.dangerFill, 'danger-fill must resolve').not.toEqual('');
	expect(tokens.dangerOnFill, 'danger-on-fill must resolve').not.toEqual('');
	expect(tokens.dangerInk, 'danger-ink must resolve').not.toEqual('');
	if (tokens.ncErrorTint && tokens.dangerFill) {
		expect(
			tokens.dangerFill.toLowerCase() === tokens.ncErrorTint.toLowerCase(),
			`danger-fill must not equal pale --color-error (${tokens.ncErrorTint})`,
		).toBeFalsy();
	}
	expect(
		/,\s*transparent\s*\)\s*$/i.test(tokens.tintInfo),
		`tint-info must mix into main-background, got: ${tokens.tintInfo}`,
	).toBeFalsy();
	expect(tokens.scrim, 'scrim token').not.toEqual('');
	expect(tokens.shadowSm, 'shadow-sm token').not.toEqual('');
	expect(tokens.touch === '44px' || parseFloat(tokens.touch) >= 44, 'touch ≥44px').toBeTruthy();
	expect(tokens.touchLg === '48px' || parseFloat(tokens.touchLg) >= 48, 'touch-lg ≥48px').toBeTruthy();
	expect(tokens.focus).toContain('3px');
	expect(
		tokens.shellMax === 'none'
			|| tokens.shellMax === ''
			|| tokens.shellMax === '100%'
			|| parseFloat(tokens.shellMax) >= 2000,
		`shell must not be a fixed 72rem/1200px lock (got ${tokens.shellMax})`,
	).toBeTruthy();
}

/**
 * @param {import('@playwright/test').Page} page
 */
async function assertChromeTouchTargets(page) {
	const undersized = await page.evaluate(() => {
		const nodes = [
			...document.querySelectorAll('#app-content .lck-btn, #app-content .lck-chip, #app-content .lck-more > summary'),
		].filter((n) => n instanceof HTMLElement && n.offsetParent !== null);
		return nodes
			.map((n) => {
				const r = n.getBoundingClientRect();
				return { h: Math.round(r.height), w: Math.round(r.width), cls: (n.className || '').toString().slice(0, 48) };
			})
			.filter((x) => x.h < 44 || x.w < 44);
	});
	expect(undersized, JSON.stringify(undersized)).toEqual([]);
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} label
 */
async function assertAxeSeriousZero(page, label) {
	const builder = new AxeBuilder({ page })
		.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']);
	if (await page.locator('#app-content').count()) {
		builder.include('#app-content');
	} else if (await page.locator('#content').count()) {
		builder.include('#content');
	}
	const results = await builder.analyze();
	const bad = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
	expect(bad, `${label}\n` + JSON.stringify(bad, null, 2)).toEqual([]);
}

/**
 * @param {import('@playwright/test').Page} page
 */
async function assertKeyboardFocusRing(page) {
	await page.locator('#lck-main-content').focus();
	let found = false;
	for (let i = 0; i < 24; i++) {
		await page.keyboard.press('Tab');
		const ring = await page.evaluate(() => {
			const el = document.activeElement;
			if (!el || !(el instanceof HTMLElement)) {
				return null;
			}
			if (!el.closest('#app-content')) {
				return null;
			}
			const cs = getComputedStyle(el);
			const w = parseFloat(cs.outlineWidth || '0');
			const style = cs.outlineStyle;
			return { w, style, tag: el.tagName, cls: (el.className || '').toString().slice(0, 40) };
		});
		if (ring && ring.w >= 2 && ring.style !== 'none') {
			found = true;
			break;
		}
	}
	expect(found, 'keyboard Tab must reveal a visible focus ring inside #app-content').toBeTruthy();
}

test.describe('LogCheck theme × responsive × a11y', () => {
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

	for (const theme of USER_THEMES) {
		test(`theme=${theme}: all routes tokens + overflow + axe`, async ({ page }) => {
			test.setTimeout(600_000);
			await setUserTheme(page, theme);
			await expect(page.locator(`body[data-theme-${theme}]`)).toBeAttached();

			for (const route of routes) {
				await gotoLogCheck(page, route.path);
				await assertThemeTokensResolved(page);

				for (const vp of overflowViewports) {
					await page.setViewportSize(vp);
					await expectNoHorizontalOverflow(page, `${theme} ${route.id} ${vp.width}x${vp.height}`);
				}

				for (const vp of axeViewports) {
					await page.setViewportSize(vp);
					await assertAxeSeriousZero(page, `${theme} ${route.id} @${vp.width}`);
				}
			}

			await page.setViewportSize({ width: 375, height: 800 });
			await gotoLogCheck(page, '/settings/alerts');
			await assertChromeTouchTargets(page);
			await gotoLogCheck(page, '/logs');
			await assertChromeTouchTargets(page);
		});
	}

	test('custom accent / primary maps into --lck-primary', async ({ page }) => {
		test.setTimeout(90_000);
		await setUserTheme(page, 'light');
		await gotoLogCheck(page, '/settings/alerts');

		const accent = '#c45c26';
		const mapped = await page.evaluate((hex) => {
			document.body.style.setProperty('--color-primary-element', hex);
			document.body.style.setProperty('--color-primary-element-text', '#ffffff');
			const body = getComputedStyle(document.body);
			const el = document.querySelector('#content.app-logcheck');
			const cs = el ? getComputedStyle(el) : body;
			const nc = body.getPropertyValue('--color-primary-element').trim().toLowerCase();
			const lck = (cs.getPropertyValue('--lck-primary').trim() || body.getPropertyValue('--lck-primary').trim()).toLowerCase();
			const btn = document.querySelector('#app-content .lck-btn--primary');
			const btnBg = btn ? getComputedStyle(btn).backgroundColor : '';
			return { nc, lck, btnBg };
		}, accent);

		expect(mapped.nc.replace(/\s/g, '')).toContain('c45c26');
		expect(mapped.lck.replace(/\s/g, '')).toContain('c45c26');
		expect(mapped.lck).toEqual(mapped.nc);
		expect(mapped.btnBg).not.toEqual('');
		expect(mapped.btnBg).not.toMatch(/rgba?\(\s*0\s*,\s*0\s*,\s*0/i);
	});

	test('custom CSS variable overrides keep layout and tokens valid', async ({ page }) => {
		test.setTimeout(120_000);
		await setUserTheme(page, 'dark');
		await gotoLogCheck(page, '/');

		await page.addStyleTag({
			content: `
				body {
					--color-main-background: #0f172a !important;
					--color-main-text: #f1f5f9 !important;
					--color-primary-element: #38bdf8 !important;
					--color-primary-element-text: #0f172a !important;
					--border-radius-large: 20px !important;
				}
			`,
		});
		await page.waitForTimeout(200);

		await assertThemeTokensResolved(page);
		await page.setViewportSize({ width: 375, height: 812 });
		await expectNoHorizontalOverflow(page, 'custom CSS dark @375');
		await assertAxeSeriousZero(page, 'custom CSS overrides @375');
	});

	test('keyboard focus rings visible on Alerts controls', async ({ page }) => {
		await gotoLogCheck(page, '/settings/alerts');
		await assertKeyboardFocusRing(page);
	});
});
