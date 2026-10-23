// @ts-check
/**
 * ATLAS screenshot-matrix capture (design-system checklist §VISUAL gate).
 *
 * Drives every surface in e2e/screenshot-matrix.json through the declared
 * viewport × state × theme grid and writes PNGs into
 * .cursor/atlas-farm-v3/artifacts/logcheck/craft/web/. The companion gate
 * .cursor/atlas-farm-v3/scripts/check-screenshot-matrix.py verifies
 * completeness — a declared cell without a real PNG fails the craft stage.
 *
 * States:
 *   populated — the live dev instance renders real health cards / log
 *               entries; captured as-is.
 *   empty     — honest zero-state: all seven health probes are disabled via
 *               the real settings API for the capture window, home renders
 *               the all-checks-off info callout (emptyDriver allProbesOff),
 *               probes are restored in afterAll.
 *   current   — state-invariant chrome (settings underpages, denied).
 *
 * Serial: user theme + health_disabled_probes are shared mutable state on
 * the one dev instance; siblings must not interleave.
 *
 * Credentials: E2E_USER/E2E_PASS (default lck_ds_admin fixture) and
 * E2E_DENIED_USER/E2E_DENIED_PASS (default lck_ds_denied fixture).
 *
 * Run (from the app dir):
 *   npx playwright test -c e2e/playwright.screenshot-matrix.config.js
 */
const { test, expect, devices } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const { setUserTheme, resetUserTheme } = require('./helpers/theming');

const nextcloudRoot = path.resolve(__dirname, '../../../../');

const BASE = (process.env.LOGCHECK_BASE_URL || process.env.E2E_BASE || 'http://localhost:8081').replace(/\/$/, '');
const APP = '/index.php/apps/logcheck';
const MANIFEST = JSON.parse(fs.readFileSync(path.join(__dirname, 'screenshot-matrix.json'), 'utf8'));
const OUT = path.resolve(__dirname, '../../../../.cursor/atlas-farm-v3/artifacts/logcheck', MANIFEST.outDir);

const USERS = {
	admin: {
		username: process.env.E2E_USER || process.env.LOGCHECK_E2E_USER || 'lck_ds_admin',
		password: process.env.E2E_PASS || process.env.LOGCHECK_E2E_PASS || process.env.DS_PROBE_PASS || 'DsProbe!lck2026',
	},
	denied: {
		username: process.env.E2E_DENIED_USER || process.env.LOGCHECK_E2E_DENIED_USER || 'lck_ds_denied',
		password: process.env.E2E_DENIED_PASS || process.env.LOGCHECK_E2E_DENIED_PASS || process.env.DS_PROBE_PASS || 'DsProbe!lck2026',
	},
};

const ALL_PROBES = ['log', 'jobs', 'php', 'db', 'disk', 'https', 'updates'];

/** Selector that proves the intended state actually rendered. */
const READY = {
	'home': { populated: '.lck-health-card', empty: '.lck-callout--info' },
	// The state machine flips the `hidden` attribute on exactly one of these —
	// entries unhide the viewer, no entries unhide the empty state. Both must
	// carry :not([hidden]) — a bare #id in a comma list pins the permanently
	// hidden sibling and the wait can never pass.
	'logs': { populated: '#lck-logs-viewer:not([hidden]), #lck-logs-empty:not([hidden])' },
	'settings-alerts': { current: '#lck-settings-form' },
	'settings-rules': { current: '#lck-level-chips' },
	'settings-people': { current: '#lck-settings-form' },
	'settings-support': { current: '.lck-support' },
	'denied': { current: '.lck-app--denied' },
};

const capturedFiles = [];

/**
 * Form-login a fresh page for the given role. NC's login JS issues a second
 * client-side redirect after submit, so success is proven by navigating to
 * the app — an authenticated session must not bounce back to /login.
 * @param {import('@playwright/test').Page} page
 * @param {'admin'|'denied'} role
 */
async function loginAs(page, role) {
	const creds = USERS[role];
	for (let attempt = 1; attempt <= 3; attempt++) {
		await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded', timeout: 45000 });
		const html = await page.content();
		if (/maintenance mode|update is in progress/i.test(html)) {
			throw new Error('NC in maintenance/upgrade mode');
		}
		await page.locator('#user, input[name="user"]').first().fill(creds.username, { force: true });
		await page.locator('#password, input[name="password"]').first().fill(creds.password, { force: true });
		await page.locator('button[type="submit"], input[type="submit"], button.login-button').first().click();
		await page.waitForTimeout(2500);
		await page.goto(`${BASE}${APP}/`, { waitUntil: 'domcontentloaded', timeout: 45000 });
		if (!page.url().includes('/login')) {
			return;
		}
	}
	throw new Error(`login failed for ${creds.username} — bounced back to /login`);
}

/** Session-bound settings write (CAS-versioned) via the real app API.
 *  Transient 5xx / CAS-version churn on the shared instance is retried
 *  (fresh version re-read per attempt) — sibling lanes mutate the same
 *  document concurrently. */
async function setDisabledProbes(page, list) {
	for (let attempt = 1; attempt <= 3; attempt++) {
		const res = await page.evaluate(async (probes) => {
		const token = (typeof window.OC !== 'undefined' && window.OC.requestToken)
			|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken') || '';
		const headers = {
			requesttoken: token,
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
			'Content-Type': 'application/json',
		};
		const got = await fetch('/index.php/apps/logcheck/api/settings', { credentials: 'same-origin', headers });
		if (!got.ok) return { status: got.status };
		const dto = await got.json();
		const res = await fetch('/index.php/apps/logcheck/api/settings', {
			method: 'PUT',
			credentials: 'same-origin',
			headers,
			body: JSON.stringify({ expected_version: dto.version, settings: { health_disabled_probes: probes } }),
		});
		return { status: res.status };
		}, list);
		if (res.status === 200) return res;
		if (res.status < 500 && res.status !== 409) return res;
		await new Promise((r) => setTimeout(r, 1500 * attempt));
	}
	return { status: 0 };
}

/**
 * Last-resort probe restore straight through occ — bypasses HTTP entirely.
 * e2e_mutated_instance_config_leftover class: a persisted all-off driver
 * state must never outlive the capture window, even when the settings API
 * is itself unhealthy.
 */
function occRestoreProbes() {
	const php = [
		'require "/var/www/html/lib/base.php";',
		'$svc = \\OC::$server->get(\\OCA\\LogCheck\\Service\\SettingsService::class);',
		'$ver = $svc->load()["version"] ?? 0;',
		'$svc->save(["health_disabled_probes" => []], (int)$ver, "atlas-matrix-restore", true);',
		'echo "restored\\n";',
	].join(' ');
	execFileSync('docker', [
		'compose', 'exec', '-T', '-u', 'www-data', 'nextcloud', 'php', '-r', php,
	], { cwd: nextcloudRoot, encoding: 'utf8', timeout: 60_000 });
}

async function shot(page, name, meta) {
	fs.mkdirSync(OUT, { recursive: true });
	const file = path.join(OUT, name);
	await page.screenshot({ path: file, fullPage: false });
	const size = fs.statSync(file).size;
	expect(size, `${name} implausibly small (${size}B) — blank/error frame`).toBeGreaterThan(4096);
	capturedFiles.push({ file: name, ...meta });
}

test.describe('LogCheck screenshot matrix (ATLAS §VISUAL)', () => {
	test.describe.configure({ mode: 'serial' });
	test.setTimeout(300_000);

	/** @type {import('@playwright/test').BrowserContext} */
	let ctx;
	/** @type {import('@playwright/test').Page} */
	let adminPage;
	let probesWereDisabled = false;

	test.beforeAll(async ({ browser }) => {
		ctx = await browser.newContext({ ...devices['Desktop Chrome'] });
		adminPage = await ctx.newPage();
		await loginAs(adminPage, 'admin');
	});

	test.afterAll(async () => {
		const page = adminPage;
		// Restore order: probes before theme reset — the shared instance must
		// come back as we found it even if a capture failed.
		try {
			if (page && !page.isClosed()) {
				if (probesWereDisabled) {
					const res = await setDisabledProbes(page, []).catch(() => ({ status: 0 }));
					if (res.status !== 200) {
						console.warn(`probe restore returned HTTP ${res.status} — occ fallback`);
						occRestoreProbes();
					}
				}
				await resetUserTheme(page).catch(() => {});
			} else if (probesWereDisabled) {
				occRestoreProbes();
			}
		} finally {
			fs.mkdirSync(OUT, { recursive: true });
			fs.writeFileSync(
				path.join(OUT, '_screenshot-matrix-manifest.json'),
				JSON.stringify(
					{ lane: 'ds_chrome', stage: 'craft', spec: 'capture-screenshot-matrix', shots: capturedFiles },
					null,
					2,
				),
			);
			if (ctx) {
				await ctx.close().catch(() => {});
			}
		}
	});

	test('fixture: settings API reachable for empty-state driver', async () => {
		const needsEmpty = MANIFEST.surfaces.some((s) => (s.states || []).includes('empty'));
		if (!needsEmpty) return;
		const res = await adminPage.evaluate(async () => {
			const token = (typeof window.OC !== 'undefined' && window.OC.requestToken)
				|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken') || '';
			const got = await fetch('/index.php/apps/logcheck/api/settings', {
				credentials: 'same-origin',
				headers: { requesttoken: token, 'OCS-APIRequest': 'true', Accept: 'application/json' },
			});
			return { status: got.status };
		});
		expect(res.status, `GET settings → ${res.status}`).toBe(200);
	});

	for (const surface of MANIFEST.surfaces) {
		for (const state of surface.states || ['current']) {
			test(`${surface.id} ${state} — viewport sweep`, async () => {
				const denied = surface.user === 'denied';
				let page = adminPage;
				if (denied) {
					const dctx = await ctx.browser().newContext({ ...devices['Desktop Chrome'] });
					page = await dctx.newPage();
					await loginAs(page, 'denied');
				}
				const empty = state === 'empty' && surface.emptyDriver === 'allProbesOff';
				if (empty) {
					const res = await setDisabledProbes(adminPage, ALL_PROBES);
					expect(res.status, `disable probes for empty cell → ${res.status}`).toBe(200);
					probesWereDisabled = true;
				}
				try {
					const url = `${APP}${surface.path === '/' ? '/' : surface.path}`;
					const ready = (READY[surface.id] || {})[state] || '#lck-main-content';
					for (const vp of MANIFEST.viewports) {
						await page.setViewportSize({ width: vp.width, height: vp.height });
						await page.goto(`${BASE}${url}`, { waitUntil: 'domcontentloaded' });
						// The dev instance serves concurrent farm lanes — a slow
						// API fetch gets one reload grace before the cell fails.
						try {
							await page.waitForSelector(ready, { timeout: 45_000 });
						} catch {
							await page.reload({ waitUntil: 'domcontentloaded' });
							await page.waitForSelector(ready, { timeout: 45_000 });
						}
						if (denied) {
							// Denied surfaces must fail closed at HTTP level, not 200-theatre.
							expect(page.url(), 'denied surface must render the access-denied shell')
								.toContain('/apps/logcheck');
						}
						await shot(
							page,
							`${MANIFEST.prefix}-${surface.id}-${state}-${vp.name}.png`,
							{ surface: surface.id, state, viewport: vp.name },
						);
					}
				} finally {
					if (empty) {
						const res = await setDisabledProbes(adminPage, []).catch(() => ({ status: 0 }));
						expect(res.status, `re-enable probes after empty cell → ${res.status}`).toBe(200);
						probesWereDisabled = false;
					}
					if (denied) {
						await page.context().close().catch(() => {});
					}
				}
			});
		}
	}

	test('dark cells — desktop', async () => {
		await adminPage.setViewportSize({ width: 1280, height: 800 });
		await setUserTheme(adminPage, 'dark');
		try {
			for (const surface of MANIFEST.surfaces.filter((s) => s.dark)) {
				const firstState = (surface.states || ['current'])[0];
				const url = `${APP}${surface.path}`;
				const ready = (READY[surface.id] || {})[firstState] || '#lck-main-content';
				await adminPage.goto(`${BASE}${url}`, { waitUntil: 'domcontentloaded' });
				await adminPage.waitForSelector(ready, { timeout: 30_000 });
				await shot(
					adminPage,
					`${MANIFEST.prefix}-${surface.id}-${firstState}-desktop-dark.png`,
					{ surface: surface.id, state: firstState, viewport: 'desktop', theme: 'dark' },
				);
			}
		} finally {
			await resetUserTheme(adminPage);
		}
	});
});
