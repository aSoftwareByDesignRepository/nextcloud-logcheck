// @ts-check
/**
 * Atlas craft: capture LogCheck web journey pages into artifacts/logcheck/craft/
 * POLICY 3.5.6 web_api lane — no AVD.
 * Honesty: Watching CTAs in home frame; Slack off while runtime-disabled; async-error Try again craft.
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { login, gotoLogCheck } = require('./helpers');
const { setUserTheme, resetUserTheme } = require('./helpers/theming');
const { patchWatchRuntime } = require('./helpers/atlas-runtime-seed');

/**
 * Track-switch checkbox is visually hidden; label intercepts clicks.
 * @param {import('@playwright/test').Page} page
 * @param {string} id
 * @param {boolean} on
 */
async function setSwitch(page, id, on) {
	const input = page.locator(`#${id}`);
	const checked = await input.isChecked();
	if (checked === on) {
		return;
	}
	await page.locator(`label[for="${id}"]`).click();
	if (on) {
		await expect(input).toBeChecked({ timeout: 15_000 });
	} else {
		await expect(input).not.toBeChecked({ timeout: 15_000 });
	}
}

const outDir = path.resolve(
	__dirname,
	'../../../../.cursor/atlas-farm-v3/artifacts/logcheck/craft',
);

const PAGES = [
	{ id: 'home', path: '/', wait: '.lck-health-grid, .lck-home' },
	{ id: 'logs', path: '/logs', wait: '.lck-logs, #lck-main-content' },
	{ id: 'alerts', path: '/settings/alerts', wait: '#lck-page-title, .lck-form' },
	{ id: 'rules', path: '/settings/rules', wait: '#lck-page-title, .lck-form' },
	{ id: 'people', path: '/settings/people', wait: '#lck-page-title, .lck-form' },
	{ id: 'support', path: '/settings/support', wait: '#lck-support-title, .lck-support' },
];

/**
 * After watch is on: exactly one alert CTA owner — Set up (checklist) XOR Manage (ready) XOR Try again (error).
 * @param {import('@playwright/test').Page} page
 */
async function assertWatchingAlertCtaXor(page) {
	await expect(page.locator('#lck-watch-toggle')).toBeChecked({ timeout: 15_000 });
	await expect
		.poll(
			async () => {
				const setup = await page.locator('#lck-alerts-checklist').isVisible();
				const ready = await page.locator('#lck-watching-actions-ready').isVisible();
				const err = await page.locator('#lck-watching-actions-error').isVisible();
				return (setup ? 1 : 0) + (ready ? 1 : 0) + (err ? 1 : 0);
			},
			{ timeout: 20_000 },
		)
		.toBe(1);

	const setup = await page.locator('#lck-alerts-checklist').isVisible();
	const ready = await page.locator('#lck-watching-actions-ready').isVisible();
	const err = await page.locator('#lck-watching-actions-error').isVisible();
	// Locale-robust: force_language may be de. Hidden checklist still has DOM nodes — assert visibility.
	const manageReady = page.locator('#lck-watching-actions-ready a.lck-btn[href*="alerts"]');
	const manageErr = page.locator('#lck-watching-actions-error a.lck-btn[href*="alerts"]');
	const setupCta = page.locator('#lck-alerts-checklist a.lck-btn[href*="alerts"]');
	if (setup) {
		await expect(setupCta).toBeVisible();
		await expect(page.locator('#lck-watching-actions-ready')).toBeHidden();
		await expect(page.locator('#lck-watching-actions-error')).toBeHidden();
	} else if (ready) {
		await expect(page.locator('#lck-alerts-checklist')).toBeHidden();
		await expect(page.locator('#lck-watching-actions-error')).toBeHidden();
		await expect(manageReady).toBeVisible();
		await expect(setupCta).toBeHidden();
	} else if (err) {
		await expect(page.locator('#lck-alerts-checklist')).toBeHidden();
		await expect(page.locator('#lck-watching-actions-ready')).toBeHidden();
		await expect(page.locator('#lck-watching-try-again')).toBeVisible();
		await expect(manageErr).toBeVisible();
		await expect(setupCta).toBeHidden();
	}
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} file
 * @param {{ scroll?: string, waitFor?: string, settleMs?: number, fullPage?: boolean, scrollBlock?: ScrollLogicalPosition, afterScroll?: () => Promise<void> }} [opts]
 */
async function shot(page, file, opts = {}) {
	if (opts.scroll) {
		const el = page.locator(opts.scroll).first();
		await el.scrollIntoViewIfNeeded();
		await el.evaluate((node, block) => {
			node.scrollIntoView({ block: block || 'center', inline: 'nearest' });
		}, opts.scrollBlock || 'center');
		await expect(el).toBeVisible({ timeout: 15_000 });
	}
	if (typeof opts.afterScroll === 'function') {
		await opts.afterScroll();
	}
	if (opts.waitFor) {
		await expect(page.locator(opts.waitFor).first()).toBeVisible({ timeout: 45_000 });
	}
	if (opts.settleMs) {
		await page.waitForTimeout(opts.settleMs);
	}
	await page.screenshot({ path: file, fullPage: !!opts.fullPage });
	const st = fs.statSync(file);
	expect(st.size, `${path.basename(file)} too small`).toBeGreaterThan(8_000);
	return st.size;
}

test.describe('Atlas web craft screenshots', () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!process.env.E2E_USER && !process.env.LOGCHECK_E2E_USER, 'Set E2E_USER + E2E_PASS');
		await page.setViewportSize({ width: 1280, height: 900 });
		const ok = await login(page);
		test.skip(!ok, 'Login failed');
	});

	test('capture journey pages', async ({ page }) => {
		fs.mkdirSync(outDir, { recursive: true });
		const meta = { captured_at: new Date().toISOString(), files: [] };

		for (const p of PAGES) {
			await gotoLogCheck(page, p.path);
			await expect(page.locator(p.wait).first()).toBeVisible({ timeout: 30_000 });
			await expect(page.locator('.lck-brand__title, #lck-page-title, .lck-nav').first()).toBeVisible({
				timeout: 15_000,
			});

			const file = path.join(outDir, `logcheck-web-${p.id}.png`);
			/** @type {{ scroll?: string, waitFor?: string, settleMs?: number, fullPage?: boolean, scrollBlock?: ScrollLogicalPosition, afterScroll?: () => Promise<void> }} */
			const opts = {};

			if (p.id === 'home') {
				await setSwitch(page, 'lck-watch-toggle', false);
				await expect(page.locator('#lck-alerts-checklist')).toBeHidden();
				await expect(page.locator('#lck-watching-actions-error')).toBeHidden();
				await expect(page.locator('#lck-watching-actions-ready')).toBeHidden();
				// Log alerts card must match Off (not stale Watching from SSR).
				await expect
					.poll(async () => {
						const label = await page
							.locator('.lck-health-card[data-probe="log"] .lck-badge__label')
							.innerText();
						return label.replace(/\s+/g, ' ').trim();
					}, { timeout: 15_000 })
					.toMatch(/\b(Off|Aus)\b/i);
				await expect(page.locator('.lck-health-card[data-probe="log"]')).toHaveAttribute(
					'data-state',
					'degraded',
				);
				opts.scroll = '#lck-watch-toggle, .lck-health-grid';
				opts.waitFor = '#lck-watch-toggle';
				opts.scrollBlock = 'center';
				opts.fullPage = false;
			} else if (p.id === 'logs') {
				opts.waitFor = '.lck-logs-row, #lck-logs-empty:not([hidden]), #lck-logs-missing';
				opts.scroll = '#lck-logs-viewer-region, .lck-logs-row';
				opts.settleMs = 400;
			} else if (p.id === 'alerts') {
				const outbound = page.locator('details.lck-more').first();
				if (await outbound.count()) {
					await outbound.evaluate((el) => {
						if (el instanceof HTMLDetailsElement) {
							el.open = true;
						}
					});
				}
				// Muted Slack callout fully above sticky Speichern (clear gutter).
				// Scroll the callout band itself — button-only end-align left callout padding under sticky.
				opts.scroll = '.lck-channel-card .lck-callout--warning';
				opts.waitFor =
					'#lck-slack-enabled, .lck-callout--warning .lck-reenable-channel, .lck-form-actions .lck-btn--primary';
				opts.scrollBlock = 'end';
				opts.afterScroll = async () => {
					await page.evaluate(() => {
						const app = document.querySelector('#app-content');
						const callout = document.querySelector('.lck-channel-card .lck-callout--warning');
						const save = document.querySelector('.lck-form-actions');
						if (!(app instanceof HTMLElement) || !(callout instanceof HTMLElement) || !(save instanceof HTMLElement)) {
							return;
						}
						callout.scrollIntoView({ block: 'end', inline: 'nearest' });
						const cr = callout.getBoundingClientRect();
						const sr = save.getBoundingClientRect();
						const need = 16; // clear gutter between callout bottom and sticky top
						const overlap = cr.bottom + need - sr.top;
						if (overlap > 0) {
							app.scrollTop = Math.max(0, app.scrollTop - overlap);
						}
					});
				};
			} else if (p.id === 'rules' || p.id === 'people') {
				opts.scroll = '.lck-form-actions .lck-btn--primary';
				opts.waitFor = '.lck-form-actions .lck-btn--primary';
				opts.scrollBlock = 'center';
			}

			const bytes = await shot(page, file, opts);
			meta.files.push({ id: p.id, path: `craft/logcheck-web-${p.id}.png`, bytes });
			console.log('craft', file, bytes);

			if (p.id === 'home') {
				const watchFile = path.join(outDir, 'logcheck-web-home-watching.png');
				await setSwitch(page, 'lck-watch-toggle', true);
				await assertWatchingAlertCtaXor(page);
				const setupOn = await page.locator('#lck-alerts-checklist').isVisible();
				const watchBytes = await shot(page, watchFile, {
					scroll: setupOn
						? '#lck-alerts-checklist a.lck-btn, #lck-watching-actions-setup, #lck-watch-toggle'
						: '#lck-watching-actions-ready, #lck-watching-actions-error, #lck-watch-toggle',
					waitFor: setupOn
						? '#lck-alerts-checklist a.lck-btn, #lck-watch-toggle'
						: '#lck-watching-actions-ready a, #lck-watching-try-again, #lck-watch-toggle',
					scrollBlock: 'center',
					settleMs: 300,
				});
				expect(watchBytes, 'watching craft too small').toBeGreaterThan(8_000);
				const homeBuf = fs.readFileSync(file);
				const watchBuf = fs.readFileSync(watchFile);
				expect(
					homeBuf.equals(watchBuf),
					'home.png and home-watching.png must differ (Off vs Watching)',
				).toBe(false);
				meta.files.push({
					id: 'home-watching',
					path: 'craft/logcheck-web-home-watching.png',
					bytes: watchBytes,
				});
				console.log('craft', watchFile, watchBytes);
			}
		}

		// Async Log alerts / Watching error + Try again (visual must_fix after 3.5.6 reset).
		try {
			patchWatchRuntime('error');
			await gotoLogCheck(page, '/');
			await expect(page.locator('.lck-health-grid, .lck-home').first()).toBeVisible({ timeout: 30_000 });
			const asyncFile = path.join(outDir, 'logcheck-web-home-async-error.png');
			const asyncBytes = await shot(page, asyncFile, {
				scroll: '.lck-health-card[data-probe="log"] .lck-btn, #lck-watching-try-again',
				waitFor: '.lck-health-card[data-probe="log"] .lck-btn, #lck-watching-try-again',
				scrollBlock: 'center',
			});
			meta.files.push({
				id: 'home-async-error',
				path: 'craft/logcheck-web-home-async-error.png',
				bytes: asyncBytes,
			});
			console.log('craft', asyncFile, asyncBytes);
		} finally {
			patchWatchRuntime('ok');
		}

		// Rules Advanced: open filters and keep Save sticky in frame
		await gotoLogCheck(page, '/settings/rules');
		const details = page.locator('details.lck-more summary').first();
		if (await details.count()) {
			await details.click();
			await expect(page.locator('#lck-mutes')).toBeVisible({ timeout: 10_000 });
			const file = path.join(outDir, 'logcheck-web-rules-advanced.png');
			const bytes = await shot(page, file, {
				scroll: '.lck-form-actions .lck-btn--primary',
				waitFor: '.lck-form-actions .lck-btn--primary',
				scrollBlock: 'center',
			});
			meta.files.push({ id: 'rules-advanced', path: 'craft/logcheck-web-rules-advanced.png', bytes });
			console.log('craft', file, bytes);
		}

		// Theme bar: dark + high-contrast home (POLICY theme_light_dark_hc_ok)
		for (const theme of [
			{ id: 'dark', file: 'logcheck-web-home-dark.png', nc: 'dark' },
			{ id: 'hc', file: 'logcheck-web-home-hc.png', nc: 'dark-highcontrast' },
		]) {
			await setUserTheme(page, theme.nc);
			await gotoLogCheck(page, '/');
			await expect(page.locator('.lck-health-grid, .lck-home').first()).toBeVisible({ timeout: 30_000 });
			await setSwitch(page, 'lck-watch-toggle', true);
			await assertWatchingAlertCtaXor(page);
			const setupOn = await page.locator('#lck-alerts-checklist').isVisible();
			const file = path.join(outDir, theme.file);
			const bytes = await shot(page, file, {
				scroll: setupOn
					? '#lck-alerts-checklist a.lck-btn, #lck-watching-actions-setup, #lck-watch-toggle'
					: '#lck-watching-actions-ready, #lck-watching-actions-error, #lck-watch-toggle',
				waitFor: setupOn
					? '#lck-alerts-checklist a.lck-btn, #lck-watch-toggle'
					: '#lck-watching-actions-ready a, #lck-watching-try-again, #lck-watch-toggle',
				scrollBlock: 'center',
				settleMs: 300,
			});
			meta.files.push({ id: `home-${theme.id}`, path: `craft/${theme.file}`, bytes, theme: theme.nc });
			console.log('craft', file, bytes);
		}
		await resetUserTheme(page);

		// Open destructive confirm dialogs for critic Read() (start-fresh + delete).
		await gotoLogCheck(page, '/logs');
		const danger = page.locator('#lck-logs-actions');
		if (await danger.count()) {
			await danger.evaluate((el) => {
				if (el instanceof HTMLDetailsElement) {
					el.open = true;
				}
			});
		}
		const startFresh = page.locator('#lck-logs-start-fresh');
		if ((await startFresh.count()) && (await startFresh.isVisible())) {
			await startFresh.click();
			const dialog = page.locator('#lck-logs-confirm-dialog');
			await expect(dialog).toBeVisible({ timeout: 10_000 });
			await expect(page.locator('#lck-logs-confirm-input')).toBeVisible();
			await expect(page.locator('#lck-logs-confirm-cancel')).toBeVisible();
			await expect(page.locator('#lck-logs-confirm-ok')).toBeVisible();
			const dlgFile = path.join(outDir, 'logcheck-web-dlg-start-fresh.png');
			const dlgBytes = await shot(page, dlgFile, {
				scroll: '#lck-logs-confirm-dialog',
				waitFor: '#lck-logs-confirm-dialog',
				scrollBlock: 'center',
				settleMs: 200,
			});
			meta.files.push({ id: 'dlg-start-fresh', path: 'craft/logcheck-web-dlg-start-fresh.png', bytes: dlgBytes });
			console.log('craft', dlgFile, dlgBytes);
			await page.locator('#lck-logs-confirm-cancel').click();
			await expect(dialog).toBeHidden();
		}
		const deleteBtn = page.locator('#lck-logs-delete');
		if (await danger.count()) {
			await danger.evaluate((el) => {
				if (el instanceof HTMLDetailsElement) {
					el.open = true;
				}
			});
		}
		if ((await deleteBtn.count()) && (await deleteBtn.isVisible())) {
			await deleteBtn.click();
			const dialog = page.locator('#lck-logs-confirm-dialog');
			await expect(dialog).toBeVisible({ timeout: 10_000 });
			await expect(page.locator('#lck-logs-confirm-title')).toContainText(/Delete the log file|Protokolldatei löschen/i);
			await expect(page.locator('#lck-logs-confirm-input')).toBeVisible();
			await expect(page.locator('#lck-logs-confirm-cancel')).toBeVisible();
			await expect(page.locator('#lck-logs-confirm-ok')).toBeVisible();
			const dlgFile = path.join(outDir, 'logcheck-web-dlg-delete-log.png');
			const dlgBytes = await shot(page, dlgFile, {
				scroll: '#lck-logs-confirm-dialog',
				waitFor: '#lck-logs-confirm-dialog',
				scrollBlock: 'center',
				settleMs: 200,
			});
			meta.files.push({ id: 'dlg-delete-log', path: 'craft/logcheck-web-dlg-delete-log.png', bytes: dlgBytes });
			console.log('craft', dlgFile, dlgBytes);
			await page.locator('#lck-logs-confirm-cancel').click();
			await expect(dialog).toBeHidden();
		}

		fs.writeFileSync(path.join(outDir, 'craft-capture.log'), JSON.stringify(meta, null, 2) + '\n');
	});
});
