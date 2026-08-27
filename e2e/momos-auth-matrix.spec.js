// @ts-check
/**
 * Momos auth/API matrix — adversarial role checks against a live instance.
 * Invariants: non-entitled users never read status/settings/logs; path jail never
 * returns config.php; unauthenticated stays 401.
 *
 * Nextcloud rejects session API calls without `requesttoken` (412 CSRF) — always send it.
 */
const { test, expect } = require('@playwright/test');

const base = (process.env.LOGCHECK_BASE_URL || process.env.E2E_BASE || 'http://localhost:8081').replace(/\/$/, '');

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} user
 * @param {string} pass
 */
async function loginAs(page, user, pass) {
	await page.goto(base + '/login', { waitUntil: 'domcontentloaded' });
	await page.locator('#user, input[name="user"]').first().fill(user);
	await page.locator('#password, input[name="password"]').first().fill(pass);
	await page.locator('button[type="submit"], input[type="submit"], button.login-button').first().click();
	await page.waitForURL(/apps\/|index\.php\/apps/, { timeout: 45000 });
	await page.waitForFunction(() => typeof window.OC !== 'undefined' && !!window.OC.requestToken, null, {
		timeout: 20000,
	});
}

/**
 * @param {import('@playwright/test').Page} page
 */
async function csrfHeaders(page) {
	const token = await page.evaluate(() => window.OC.requestToken);
	expect(token, 'OC.requestToken must be present after login').toBeTruthy();
	return { requesttoken: token };
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {'get'|'put'|'post'} method
 * @param {string} path
 * @param {Record<string, unknown>} [data]
 */
async function api(page, method, path, data) {
	const headers = await csrfHeaders(page);
	if (method === 'get') {
		return page.request.get(base + path, { headers });
	}
	if (method === 'put') {
		return page.request.put(base + path, { headers, data: data || {} });
	}
	return page.request.post(base + path, { headers, data: data || {} });
}

test.describe('Momos API/Auth matrix', () => {
	test('unauthenticated API returns 401 (not 200)', async ({ request }) => {
		const res = await request.get(base + '/index.php/apps/logcheck/api/status');
		expect(res.status()).toBe(401);
		const put = await request.put(base + '/index.php/apps/logcheck/api/settings', {
			data: { expected_version: 0, watch_enabled: true },
		});
		expect(put.status()).toBe(401);
	});

	test('non-entitled user gets 403 on every mutating and read API', async ({ page }) => {
		const deniedUser = process.env.E2E_DENIED_USER || 'momos_denied';
		const deniedPass = process.env.E2E_DENIED_PASS || 'MomosDenyT3st!';
		test.skip(!deniedUser || !deniedPass, 'Set E2E_DENIED_USER + E2E_DENIED_PASS');

		await loginAs(page, deniedUser, deniedPass);

		const endpoints = [
			{ method: /** @type {'get'} */ ('get'), path: '/index.php/apps/logcheck/api/status' },
			{ method: /** @type {'get'} */ ('get'), path: '/index.php/apps/logcheck/api/settings' },
			{ method: /** @type {'get'} */ ('get'), path: '/index.php/apps/logcheck/api/logs/meta' },
			{ method: /** @type {'get'} */ ('get'), path: '/index.php/apps/logcheck/api/logs/files' },
			{ method: /** @type {'get'} */ ('get'), path: '/index.php/apps/logcheck/api/logs/tail' },
			{ method: /** @type {'get'} */ ('get'), path: '/index.php/apps/logcheck/api/logs/before' },
			{ method: /** @type {'get'} */ ('get'), path: '/index.php/apps/logcheck/api/logs/search?q=error' },
			{ method: /** @type {'get'} */ ('get'), path: '/index.php/apps/logcheck/api/directory/search?search=ad' },
			{ method: /** @type {'put'} */ ('put'), path: '/index.php/apps/logcheck/api/settings', data: { expected_version: 0 } },
			{ method: /** @type {'post'} */ ('post'), path: '/index.php/apps/logcheck/api/run' },
			{
				method: /** @type {'post'} */ ('post'),
				path: '/index.php/apps/logcheck/api/turn-on-alerts',
				data: { email: 'x@example.com', expected_version: 0 },
			},
			{ method: /** @type {'post'} */ ('post'), path: '/index.php/apps/logcheck/api/channels/email/test', data: {} },
			{ method: /** @type {'post'} */ ('post'), path: '/index.php/apps/logcheck/api/channels/slack/reenable', data: {} },
			{
				method: /** @type {'post'} */ ('post'),
				path: '/index.php/apps/logcheck/api/logs/download',
				data: { file: 'nextcloud.log' },
			},
			{
				method: /** @type {'post'} */ ('post'),
				path: '/index.php/apps/logcheck/api/logs/start-fresh',
				data: { confirm: 'START_FRESH' },
			},
			{ method: /** @type {'post'} */ ('post'), path: '/index.php/apps/logcheck/api/logs/delete', data: { confirm: 'DELETE' } },
			{
				method: /** @type {'post'} */ ('post'),
				path: '/index.php/apps/logcheck/api/logs/delete-copy',
				data: { confirm: 'DELETE_COPY', file: 'nextcloud.log.1' },
			},
		];

		for (const ep of endpoints) {
			const res = await api(page, ep.method, ep.path, ep.data);
			expect(res.status(), `${ep.method.toUpperCase()} ${ep.path}`).toBe(403);
			const body = await res.json().catch(() => ({}));
			expect(body.error || '', `${ep.path} body`).toMatch(/LCK_FORBIDDEN|Not authorized/i);
		}

		await page.goto(base + '/index.php/apps/logcheck/', { waitUntil: 'domcontentloaded' });
		await expect(page.locator('body')).toContainText(/not authorized|don.?t have access|Access denied|Only Nextcloud admins/i);
		await expect(page.locator('.lck-home, #lck-alerts-checklist, .lck-status-card')).toHaveCount(0);
	});

	test('entitled admin: path-traversal download never leaks config.php', async ({ page }) => {
		const user = process.env.E2E_USER || process.env.LOGCHECK_E2E_USER;
		const pass = process.env.E2E_PASS || process.env.E2E_PASSWORD || process.env.LOGCHECK_E2E_PASS;
		test.skip(!user || !pass, 'Set E2E_USER + E2E_PASS');

		await loginAs(page, user, pass);
		await page.goto(base + '/index.php/apps/logcheck/', { waitUntil: 'domcontentloaded' });
		await page.locator('#lck-main-content, .lck-home').first().waitFor({ state: 'visible', timeout: 20000 });

		const payloads = [
			'../../config/config.php',
			'....//....//config/config.php',
			'/etc/passwd',
			'nextcloud.log/../../../config/config.php',
		];
		for (const file of payloads) {
			const res = await api(page, 'post', '/index.php/apps/logcheck/api/logs/download', { file });
			expect([400, 403, 422]).toContain(res.status());
			const text = await res.text();
			expect(text, `payload ${file}`).not.toMatch(/\$CONFIG|dbpassword|passwordsalt|dbname/i);
		}

		const status = await api(page, 'get', '/index.php/apps/logcheck/api/status');
		expect(status.status()).toBe(200);
		const json = await status.json();
		expect(JSON.stringify(json)).not.toMatch(/watcher_node/);
	});

	test('App Admin: NC-only endpoints stay 403; entitled reads still work', async ({ page }) => {
		const user = process.env.E2E_APP_ADMIN_USER || 'momos_appadmin';
		const pass = process.env.E2E_APP_ADMIN_PASS || 'MomosAppAdm1n!';
		test.skip(!user || !pass, 'Set E2E_APP_ADMIN_USER + E2E_APP_ADMIN_PASS');

		await loginAs(page, user, pass);
		await page.goto(base + '/index.php/apps/logcheck/', { waitUntil: 'domcontentloaded' });
		await page.locator('#lck-main-content, .lck-home').first().waitFor({ state: 'visible', timeout: 20000 });

		const status = await api(page, 'get', '/index.php/apps/logcheck/api/status');
		expect(status.status(), 'App Admin must be entitled to read status').toBe(200);
		expect(JSON.stringify(await status.json())).not.toMatch(/watcher_node/);

		const settingsRes = await api(page, 'get', '/index.php/apps/logcheck/api/settings');
		expect(settingsRes.status()).toBe(200);
		const settingsJson = await settingsRes.json();
		const expectedVersion = settingsJson.version ?? settingsJson.settings?.version ?? 0;

		const ncOnly = [
			{ method: /** @type {'get'} */ ('get'), path: '/index.php/apps/logcheck/api/directory/search?search=ad' },
			{
				method: /** @type {'post'} */ ('post'),
				path: '/index.php/apps/logcheck/api/logs/download',
				data: { file: 'nextcloud.log' },
			},
			{
				method: /** @type {'post'} */ ('post'),
				path: '/index.php/apps/logcheck/api/logs/start-fresh',
				data: { confirm: 'START_FRESH' },
			},
			{
				method: /** @type {'put'} */ ('put'),
				path: '/index.php/apps/logcheck/api/settings',
				data: {
					expected_version: expectedVersion,
					include_message_excerpts: true,
					excerpt_confirm: 'CONFIRM',
				},
			},
			{
				method: /** @type {'put'} */ ('put'),
				path: '/index.php/apps/logcheck/api/settings',
				data: {
					expected_version: expectedVersion,
					allow_private_webhooks: true,
				},
			},
		];

		for (const ep of ncOnly) {
			const res = await api(page, ep.method, ep.path, ep.data);
			expect(res.status(), `${ep.method.toUpperCase()} ${ep.path}`).toBe(403);
			const body = await res.json().catch(() => ({}));
			expect(body.error || '', `${ep.path} body`).toMatch(/LCK_FORBIDDEN|Not authorized/i);
		}
	});
});
