// @ts-check
/**
 * ATLAS_UI_INVARIANTS — shared-contract coverage for LogCheck (HealthCheck)
 * web surfaces.
 *
 * Wires nextcloud/apps/_shared/e2e/atlas-ui-invariants.js onto representative
 * surfaces of this small web-only app:
 *   a11y-dom sweep + console errors + raw i18n keys on every page surface
 *   (home, logs, all four settings sections),
 *   bounded (O(1)) API requests on the logs viewer,
 *   stored-xss via the free-text `app_list` filter rendered escaped inside
 *   the rules form's value attribute,
 *   mutation→surface freshness via access.app_admins rendered as chips on
 *   the people section,
 *   double-submit guard on the settings save button,
 *   form-survival-on-5xx on the same settings form.
 *
 * Settings mutations go through the real PUT /api/settings CAS route and are
 * restored in `finally` (fresh GET before each restore so a concurrent lane
 * version bump cannot strand fixture state). No probe-owned users needed —
 * only settings fields this spec itself touched are restored.
 */
const { test, expect } = require('@playwright/test');
const {
	ATLAS_XSS_PAYLOADS,
	assertA11yDom,
	assertNoConsoleErrors,
	assertNoDuplicateSubmit,
	assertFormSurvivesFailure,
	assertNoInjection,
	assertNoRawI18nKeys,
	assertSurfaceFresh,
	countApiRequests,
	trackConsoleErrors,
} = require('../../_shared/e2e/atlas-ui-invariants');
const { login, gotoLogCheck } = require('./helpers');

const BASE = (process.env.LOGCHECK_BASE_URL || process.env.E2E_BASE || 'http://localhost:8081').replace(/\/$/, '');
const APP = `${BASE}/index.php/apps/logcheck`;
const CONTENT = '#lck-main-content';
const MARK = `lckui-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 7)}`;

/** session-cookie JSON call into the app API (in-page, same-origin). */
async function api(page, method, path, body) {
	return page.evaluate(async ({ method: m, path: p, body: b }) => {
		const token = (typeof window.OC !== 'undefined' && window.OC.requestToken)
			|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
			|| document.querySelector('input[name="requesttoken"]')?.getAttribute('value')
			|| '';
		const res = await fetch(p, {
			method: m,
			credentials: 'same-origin',
			headers: {
				requesttoken: token,
				'OCS-APIRequest': 'true',
				Accept: 'application/json',
				...(b !== undefined ? { 'Content-Type': 'application/json' } : {}),
			},
			body: b !== undefined ? JSON.stringify(b) : undefined,
		});
		return { status: res.status, body: await res.text() };
	}, { method, path: `${APP}${path}`, body });
}

/** Read the current settings DTO (version + full settings object). */
async function readSettings(page) {
	const res = await api(page, 'GET', '/api/settings');
	expect(res.status, `GET /api/settings failed: ${res.body.slice(0, 160)}`).toBe(200);
	return JSON.parse(res.body);
}

/**
 * PUT a partial settings patch through the real CAS route. Retries once on
 * 409 with a freshly-read version (concurrent farm lanes may bump it).
 */
async function patchSettings(page, patch) {
	for (let attempt = 0; attempt < 2; attempt++) {
		const { version } = await readSettings(page);
		const res = await api(page, 'PUT', '/api/settings', { expected_version: version, ...patch });
		if (res.status === 409 && attempt === 0) continue;
		return res;
	}
}

test.describe('LogCheck UI invariants (atlas-ui-invariants)', () => {
	test.describe.configure({ mode: 'serial' });

	test.beforeEach(async ({ page }) => {
		test.skip(!process.env.E2E_USER && !process.env.LOGCHECK_E2E_USER, 'Set E2E_USER + E2E_PASS');
		const ok = await login(page);
		test.skip(!ok, 'Login failed');
		await page.setViewportSize({ width: 1280, height: 800 });
	});

	for (const [label, path] of [
		['home', '/'],
		['logs', '/logs'],
		['settings-alerts', '/settings/alerts'],
		['settings-rules', '/settings/rules'],
		['settings-people', '/settings/people'],
		['settings-support', '/settings/support'],
	]) {
		test(`a11y-dom sweep /${label}`, async ({ page }) => {
			await gotoLogCheck(page, path);
			await page.waitForLoadState('networkidle').catch(() => {});
			// Wrapped-label inputs — the native control is sr-only/hidden and the
			// enclosing <label> row is the real pointer target (same pattern as
			// snackcheck .snk-check):
			//   .lck-switch-field__input  switch checkbox → label + track
			//   .lck-logs-file__input     file-picker radio → .lck-logs-file row
			//   .lck-logs-filter-chip input  sr-only radio → .lck-chip label
			// Allow-listed on the input, then proven ≥24px on the visible label
			// targets so the allow-list hides nothing real.
			const sizeAllow = '.lck-switch-field__input, .lck-logs-file__input, .lck-logs-filter-chip input';
			let findings = await assertA11yDom(page, { content: CONTENT, sizeAllow });
			// WCAG 2.5.8 inline exception: a sub-24px-height <a> that flows inside
			// a text-bearing <p> (e.g. the "More on our website" URL on Support)
			// is exempt by spec — the shared helper doesn't model it, so exempt
			// it precisely here (display:inline + sibling text nodes present).
			const inlineExempt = await page.evaluate((content) => {
				const root = document.querySelector(content) || document.body;
				const dims = [];
				root.querySelectorAll('p a[href]').forEach((el) => {
					if (getComputedStyle(el).display !== 'inline') return;
					const p = el.closest('p');
					const hasSibText = Array.from(p.childNodes).some(
						(n) => n !== el && n.nodeType === 3 && n.textContent.trim().length > 0,
					);
					if (!hasSibText) return;
					const r = el.getBoundingClientRect();
					if (r.width > 0) dims.push(`hit target ${Math.round(r.width)}x${Math.round(r.height)} < 24px: a`);
				});
				return dims;
			}, CONTENT);
			findings = findings.filter((f) => {
				const i = inlineExempt.indexOf(f);
				if (i === -1) return true;
				inlineExempt.splice(i, 1);
				return false;
			});
			expect(findings, `a11y-dom findings on /${label}:\n${findings.join('\n')}`).toEqual([]);
			const smallTargets = await page.evaluate(() => {
				const out = [];
				document.querySelectorAll(
					'.lck-switch-field__label, .lck-switch-field__track, .lck-logs-file, .lck-logs-filter-chip',
				).forEach((el) => {
					const r = el.getBoundingClientRect();
					if (r.width === 0) return;
					if (r.width < 24 || r.height < 24) {
						out.push(`${el.className} ${Math.round(r.width)}x${Math.round(r.height)}`);
					}
				});
				return out;
			});
			expect(smallTargets, `sub-24px switch label/track targets on /${label}`).toEqual([]);
		});

		test(`console errors + raw i18n keys /${label}`, async ({ page }) => {
			const errs = trackConsoleErrors(page);
			await gotoLogCheck(page, path);
			await page.waitForLoadState('networkidle').catch(() => {});
			assertNoConsoleErrors(errs, { allow: [/favicon/i] });
			await assertNoRawI18nKeys(page, { content: CONTENT });
		});
	}

	test('n+1: /logs issues a bounded number of app API requests', async ({ page }) => {
		const hits = await countApiRequests(page, async () => {
			await gotoLogCheck(page, '/logs');
			await page.waitForLoadState('networkidle').catch(() => {});
		}, '/apps/logcheck/api/');
		expect(
			hits.length,
			`logs fired ${hits.length} app API requests (N+1 suspect): ${hits.map((h) => `${h.method} ${h.url}`).join(' | ')}`,
		).toBeLessThanOrEqual(8);
	});

	test('stored-xss: app_list filter value renders escaped on /settings/rules', async ({ page }) => {
		await gotoLogCheck(page, '/settings/rules');
		const before = await readSettings(page);
		const originalList = before.settings.app_list || [];
		const payload = `${MARK} ${ATLAS_XSS_PAYLOADS[0]}`;
		const saved = await patchSettings(page, { app_list: [payload] });
		test.skip(saved.status !== 200, `settings save returned ${saved.status}: ${saved.body.slice(0, 160)}`);
		try {
			await page.reload({ waitUntil: 'domcontentloaded' });
			await page.locator(CONTENT).waitFor({ state: 'visible', timeout: 20000 });
			// payload sits inside the #lck-app-list value attribute — escaped by p()
			const rawValue = await page.locator('#lck-app-list').inputValue();
			expect(rawValue, 'saved app_list payload did not round-trip into the form').toContain(MARK);
			await assertNoInjection(page);
		} finally {
			await patchSettings(page, { app_list: originalList });
		}
	});

	test('mutation freshness: app-admin grant via API renders on /settings/people', async ({ page }) => {
		await gotoLogCheck(page, '/settings/people');
		const before = await readSettings(page);
		const access = before.settings.access || { mode: 'restricted', app_admins: [] };
		const original = Array.isArray(access.app_admins) ? access.app_admins : [];
		const grantee = 'lck_ds_denied';
		test.skip(original.includes(grantee), 'grantee already an app admin — pick another');
		const saved = await patchSettings(page, {
			access: { mode: access.mode || 'restricted', app_admins: [...original, grantee] },
		});
		test.skip(saved.status !== 200, `access save returned ${saved.status}: ${saved.body.slice(0, 160)} — needs an NC-admin session`);
		try {
			await assertSurfaceFresh(page, {
				mutate: async () => {}, // mutation already done above (CAS-safe)
				visit: () => gotoLogCheck(page, '/settings/people'),
				content: CONTENT,
			});
			// chips render displayName as text; the uid is carried on data-uid
			await expect(
				page.locator(`#lck-people-chips .lck-person-chip[data-uid="${grantee}"]`),
				'app-admin grant via API did not render on /settings/people',
			).toHaveCount(1);
		} finally {
			await patchSettings(page, {
				access: { mode: access.mode || 'restricted', app_admins: original },
			});
		}
	});

	test('double-submit: settings save fires at most one PUT', async ({ page }) => {
		await gotoLogCheck(page, '/settings/rules');
		const form = page.locator('form.lck-form').first();
		const submit = form.locator('button[type="submit"]');
		await expect(submit).toBeEnabled();
		await assertNoDuplicateSubmit(page, {
			mutatingUrl: '/apps/logcheck/api/settings',
			method: 'PUT',
			submit: () => submit.click({ force: true }),
		});
	});

	test('form survival: settings form keeps values on server 500', async ({ page }) => {
		await gotoLogCheck(page, '/settings/rules');
		// open <details> so the free-text filter input is interactable
		const summary = page.locator('details.lck-more > summary');
		if (await summary.isVisible().catch(() => false)) {
			await summary.click();
		}
		const field = page.locator('#lck-app-list');
		const original = await field.inputValue();
		const marker = `${MARK} keepme`;
		try {
			await assertFormSurvivesFailure(page, {
				failUrl: '/apps/logcheck/api/settings',
				method: 'PUT',
				fields: { '#lck-app-list': marker },
				submit: () => page.locator('form.lck-form button[type="submit"]').first().click({ force: true }),
				errorSel: '.lck-toast',
			});
		} finally {
			// server never saw the write (500 injected) — only the DOM value
			// needs restoring so later tests read a clean form
			await field.fill(original).catch(() => {});
		}
	});
});
