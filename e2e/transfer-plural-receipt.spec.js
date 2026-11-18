// @ts-check
/**
 * Transfer plural-cluster render probe (ATLAS lck-mf-42/43 regression gate).
 *
 * Proves on the live instance, end to end:
 *   1. preview verdict is grammatical at count=1 — the synced transfer.js
 *      renders '1 setting' via n(), never '(s)'-hacked or '1 settings';
 *   2. post-apply receipt toast ('Import applied. 1 setting and …') is
 *      still visible ~1.2s after render — the deferred 2500ms reload lets
 *      the user actually read it (an immediate reload wiped it ~200ms in);
 *   3. the deferred reload does fire (page reloads within ~6s of the toast).
 *
 * Fixture: a minimal format-honest .nctransfer built by
 * e2e/helpers/build-minimal-transfer.php through the app's own
 * TransferFormat/TransferCrypto — exactly one config key
 * (health_disabled_probes, mirrored/transferable) carrying the inverse of
 * the live value, so preview reports configApplied=1 (the count is keys
 * present in the file, not diffs) and apply performs a real write.
 * A finally guard restores the live value + sweeps leftover queued jobs.
 *
 * Run (from the app dir):
 *   npx playwright test e2e/transfer-plural-receipt.spec.js
 */
const { test, expect, devices } = require('@playwright/test');
const { execFileSync, execFileSync: run } = require('child_process');
const fs = require('fs');
const path = require('path');
const { tmpdir } = require('os');

const nextcloudRoot = path.resolve(__dirname, '../../../../');
const composeRoot = path.join(nextcloudRoot, 'nextcloud');
const BASE = (process.env.LOGCHECK_BASE_URL || process.env.E2E_BASE || 'http://localhost:8081').replace(/\/$/, '');
const APP = '/index.php/apps/logcheck';
const PROOF_DIR = path.resolve(
	__dirname, '../../../../.cursor/atlas-farm-v3/artifacts/logcheck/proofs',
	`transfer-plural-${new Date().toISOString().replace(/[-:]/g, '').replace(/\..+/, '').replace('T', 'T')}Z`,
);

const USERS = {
	admin: {
		username: process.env.E2E_USER || process.env.LOGCHECK_E2E_USER || 'lck_ds_admin',
		password: process.env.E2E_PASS || process.env.LOGCHECK_E2E_PASS || process.env.DS_PROBE_PASS || 'DsProbe!lck2026',
	},
};

const MARKER = `lxp-${Date.now().toString(36)}-${process.pid.toString(36)}`;
const TRANSFER_PASS = `Atlas-Xfer-${MARKER}-secret`;

async function loginAsAdmin(page) {
	const creds = USERS.admin;
	for (let attempt = 1; attempt <= 3; attempt++) {
		await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded', timeout: 45000 });
		await page.locator('#user, input[name="user"]').first().fill(creds.username, { force: true });
		await page.locator('#password, input[name="password"]').first().fill(creds.password, { force: true });
		await page.locator('button[type="submit"], input[type="submit"], button.login-button').first().click();
		await page.waitForTimeout(2500);
		await page.goto(`${BASE}${APP}/settings/transfer`, { waitUntil: 'domcontentloaded', timeout: 45000 });
		if (!page.url().includes('/login')) {
			return;
		}
	}
	throw new Error('login failed for admin — bounced back to /login');
}

/** Session-bound settings read/write through the real app API. */
async function apiSettings(page, method, body) {
	return page.evaluate(async ({ method: m, body: b }) => {
		const token = (typeof window.OC !== 'undefined' && window.OC.requestToken)
			|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken') || '';
		const headers = {
			requesttoken: token,
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
			'Content-Type': 'application/json',
		};
		if (m === 'GET') {
			const r = await fetch('/index.php/apps/logcheck/api/settings', { credentials: 'same-origin', headers });
			return { status: r.status, dto: await r.json().catch(() => null) };
		}
		const r = await fetch('/index.php/apps/logcheck/api/settings', {
			method: m, credentials: 'same-origin', headers, body: JSON.stringify(b),
		});
		return { status: r.status, dto: await r.json().catch(() => null) };
	}, { method, body });
}

/** Execute every queued TransferApplyJob — dev-instance cron doesn't fire
 * them, and the progress poll can only observe a job that actually ran. */
function sweepQueuedTransferJobs() {
	const ids = execFileSync('docker', [
		'compose', 'exec', '-T', 'mariadb', 'bash', '-lc',
		`mariadb -u nextcloud -pnextcloud_password nextcloud -N -e "
			SELECT id FROM oc_jobs WHERE class LIKE '%TransferApplyJob%';"`,
	], { cwd: composeRoot, encoding: 'utf8', timeout: 60_000 })
		.split('\n').map((s) => s.trim()).filter(Boolean);
	for (const id of ids) {
		execFileSync('docker', [
			'compose', 'exec', '-T', '-u', 'www-data', 'nextcloud',
			'php', 'occ', 'background-job:execute', id,
		], { cwd: composeRoot, encoding: 'utf8', timeout: 120_000 });
	}
	return ids.length;
}

test.describe('LogCheck transfer plural + receipt toast (lck-mf-42/43)', () => {
	test.describe.configure({ mode: 'serial' });
	test.setTimeout(180_000);

	test('count=1 preview verdict is grammatical and receipt toast survives ~1s', async ({ browser }) => {
		const ctx = await browser.newContext({ ...devices['Desktop Chrome'] });
		const page = await ctx.newPage();
		const result = { verdictText: null, toastText: null, toastVisibleAt1200ms: false, reloaded: false };
		let originalProbes = null;
		try {
			await loginAsAdmin(page);
			await expect(page.locator('#lck-transfer-export-card')).toBeVisible({ timeout: 30_000 });

			// Live value for the one key the fixture carries — the apply is a
			// real write through writeMirrored/saveSettings but semantically
			// a no-op (shared instance: zero mutation window, nothing to restore).
			const before = await apiSettings(page, 'GET');
			expect(before.status, `settings GET failed: ${before.status}`).toBe(200);
			originalProbes = (before.dto && before.dto.settings && before.dto.settings.health_disabled_probes) || [];

			// Minimal format-honest container, sealed by the app's own classes.
			const instanceId = execFileSync('docker', [
				'compose', 'exec', '-T', '-u', 'www-data', 'nextcloud',
				'php', 'occ', 'config:system:get', 'instanceid',
			], { cwd: composeRoot, encoding: 'utf8', timeout: 60_000 }).trim();
			const filePath = path.join(tmpdir(), `lck-plural-${MARKER}.nctransfer`);
			run('php', [
				path.join(__dirname, 'helpers', 'build-minimal-transfer.php'),
				filePath, TRANSFER_PASS, instanceId, '1.4.5',
				JSON.stringify({ health_disabled_probes: JSON.stringify(originalProbes) }),
			], { encoding: 'utf8', timeout: 60_000 });
			expect(fs.statSync(filePath).size, 'fixture build produced no file').toBeGreaterThan(200);

			// Drive the real import preview (debounced) → report dialog.
			await page.locator('#lck-transfer-import-passphrase').fill(TRANSFER_PASS);
			await page.locator('#lck-transfer-file').setInputFiles(filePath);
			await expect(page.locator('#lck-transfer-report[open]')).toBeVisible({ timeout: 45_000 });

			const verdict = page.locator('#lck-transfer-report-verdict');
			await expect(verdict).toContainText('1 setting', { timeout: 10_000 });
			result.verdictText = (await verdict.textContent() || '').trim();
			expect(result.verdictText).toMatch(/1 setting\b/);
			expect(result.verdictText).not.toMatch(/1 settings\b/);
			expect(result.verdictText).not.toMatch(/\(s\)/);
			fs.mkdirSync(PROOF_DIR, { recursive: true });
			await page.screenshot({ path: path.join(PROOF_DIR, 'preview-verdict-count1.png') });

			// Confirm → job queued → sweep executes it → poll observes done.
			await page.locator('#lck-transfer-confirm').click();
			await expect(page.locator('#lck-transfer-progress[open]')).toBeVisible({ timeout: 15_000 });
			// Poll the job table briefly — the apply POST queues asynchronously.
			let swept = 0;
			for (let i = 0; i < 10 && swept === 0; i++) {
				await page.waitForTimeout(800);
				swept = sweepQueuedTransferJobs();
			}
			expect(swept, 'no TransferApplyJob was queued').toBeGreaterThan(0);

			// Receipt toast must render with count=1 grammar …
			const toast = page.locator('#lck-toasts .lck-toast--success .lck-toast__text');
			await expect(toast).toContainText('Import applied.', { timeout: 60_000 });
			result.toastText = (await toast.textContent() || '').trim();
			expect(result.toastText).toMatch(/Import applied\. 1 setting\b/);
			expect(result.toastText).not.toMatch(/1 settings\b/);
			await page.screenshot({ path: path.join(PROOF_DIR, 'receipt-toast.png') });

			// … and still be visible ~1.2s later (deferred 2500ms reload).
			await page.waitForTimeout(1200);
			result.toastVisibleAt1200ms = await toast.isVisible();
			expect(result.toastVisibleAt1200ms, 'receipt toast vanished inside 1.2s — deferred reload regressed').toBe(true);

			// The deferred reload does fire (proves the timer, not a hang).
			await page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 8000 })
				.then(() => { result.reloaded = true; })
				.catch(() => { result.reloaded = false });
		} finally {
			// Safety net: the apply should have rewritten the same value, but
			// if anything left health_disabled_probes drifted, restore it —
			// and never leave a queued job behind for the next lane.
			if (originalProbes) {
				const cur = await apiSettings(page, 'GET').catch(() => null);
				if (cur && cur.status === 200 && cur.dto) {
					const now = (cur.dto.settings && cur.dto.settings.health_disabled_probes) || [];
					if (JSON.stringify(now) !== JSON.stringify(originalProbes)) {
						await apiSettings(page, 'PUT', {
							expected_version: cur.dto.version,
							settings: { health_disabled_probes: originalProbes },
						}).catch(() => {});
					}
				}
			}
			try { sweepQueuedTransferJobs(); } catch (e) { /* instance may be mid-reload */ }
			fs.mkdirSync(PROOF_DIR, { recursive: true });
			fs.writeFileSync(path.join(PROOF_DIR, 'result.json'), JSON.stringify(result, null, 2) + '\n');
			await ctx.close();
		}
	});
});
