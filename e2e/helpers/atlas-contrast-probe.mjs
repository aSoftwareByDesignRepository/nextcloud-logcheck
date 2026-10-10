// @ts-check
/**
 * ATLAS ds_chrome live contrast probe (logcheck) — ported from
 * homecheck/projectcheck atlas-contrast-probe.mjs (3.5.15 lane duty).
 *
 * Logs in as the dedicated fixture users (lck_ds_probe / lck_ds_admin),
 * walks representative surfaces — home health dashboard, logs viewer,
 * settings sections, denied shell — and measures the COMPUTED WCAG 2.1
 * contrast of semantic chrome: primary/danger CTAs, control borders,
 * card/chip boundaries, status + callout wells, aria-invalid painted
 * borders and field-error ink — across light / dark / light-highcontrast /
 * dark-highcontrast user themes (server-pinned via the OCS theming API,
 * body[data-theme-*] marker asserted after a real navigation — never
 * client-emulated).
 *
 *   text ink   >= 4.5:1  (WCAG 1.4.3 AA)
 *   borders    >= 3.0:1  (WCAG 1.4.11 non-text contrast)
 *
 * Usage (from the app dir):
 *   DS_PROBE_PASS=<secret> node e2e/helpers/atlas-contrast-probe.mjs [--out <path.json>]
 *
 * Env: DS_PROBE_PASS (defaults to the farm fixture secret), NC_BASE_URL or
 * LOGCHECK_BASE_URL override the canonical origin — one consistent origin
 * only (chromium_formaction_redirect class).
 */
import { writeFileSync, mkdirSync } from 'node:fs'
import { dirname } from 'node:path'
import { fileURLToPath } from 'node:url'
import { chromium } from 'playwright'

const HERE = dirname(fileURLToPath(import.meta.url))

const BASE = (process.env.NC_BASE_URL || process.env.LOGCHECK_BASE_URL || 'http://localhost:8081').replace(/\/$/, '')
const PASS = process.env.DS_PROBE_PASS || 'DsProbe!lck2026'
const USERS = {
	probe: 'lck_ds_probe',
	admin: 'lck_ds_admin',
}
const THEMES = ['light', 'dark', 'light-highcontrast', 'dark-highcontrast']

// ── WCAG contrast helpers (injected into the page for computed colors) ──
const EVAL_FN = String.raw`
function hexToRgb(c) {
  c = c.trim()
  if (c.startsWith('color(')) {
    const m = c.match(/[\d.]+/g)
    if (m && m.length >= 3) {
      const s = m.map(parseFloat)
      const scale = s.every((v) => v <= 1) ? 255 : 1
      return [s[0] * scale, s[1] * scale, s[2] * scale]
    }
    return null
  }
  if (c.startsWith('rgb')) {
    const m = c.match(/[\d.]+/g)
    if (m && m.length >= 3) return [parseFloat(m[0]), parseFloat(m[1]), parseFloat(m[2])]
    return null
  }
  if (c.startsWith('#')) {
    let h = c.slice(1)
    if (h.length === 3) h = h.split('').map(x => x + x).join('')
    if (h.length === 4) h = h.split('').map(x => x + x).join('')
    if (h.length === 6 || h.length === 8) {
      return [parseInt(h.slice(0,2),16), parseInt(h.slice(2,4),16), parseInt(h.slice(4,6),16)]
    }
  }
  return null
}
function lum(rgb) {
  const f = v => {
    v /= 255
    return v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4)
  }
  return 0.2126 * f(rgb[0]) + 0.7152 * f(rgb[1]) + 0.0722 * f(rgb[2])
}
function effBgFrom(n) {
  while (n && n !== document.documentElement) {
    const bg = getComputedStyle(n).backgroundColor
    const m = bg && bg.match(/[\d.]+/g)
    if (m && m.length >= 4 && parseFloat(m[3]) > 0) return bg
    if (m && m.length === 3 && !bg.includes('transparent')) return bg
    n = n.parentElement
  }
  return getComputedStyle(document.body).backgroundColor
}
function effBg(el) {
  return effBgFrom(el)
}
function effBgParent(el) {
  return effBgFrom(el && el.parentElement)
}
function alphaOf(c) {
  const m = c && c.match(/[\d.]+/g)
  if (m && m.length >= 4) return parseFloat(m[3])
  if (c && c.startsWith('color(')) {
    const parts = c.match(/[\d.]+/g)
    if (parts && parts.length >= 4) return parseFloat(parts[3])
  }
  return 1
}
function blend(fgRgb, bgRgb, a) {
  return [
    a * fgRgb[0] + (1 - a) * bgRgb[0],
    a * fgRgb[1] + (1 - a) * bgRgb[1],
    a * fgRgb[2] + (1 - a) * bgRgb[2],
  ]
}
function ratio(fg, bg) {
  const a = hexToRgb(fg), b = hexToRgb(bg)
  if (!a || !b) return null
  const l1 = lum(a), l2 = lum(b)
  const hi = Math.max(l1, l2), lo = Math.min(l1, l2)
  return (hi + 0.05) / (lo + 0.05)
}
function borderRatio(border, bg) {
  const f = hexToRgb(border), b = hexToRgb(bg)
  if (!f || !b) return null
  const alpha = alphaOf(border)
  const eff = alpha >= 1 ? f : blend(f, b, alpha)
  const l1 = lum(eff), l2 = lum(b)
  const hi = Math.max(l1, l2), lo = Math.min(l1, l2)
  return (hi + 0.05) / (lo + 0.05)
}
window.__lckProbe = { effBg, effBgParent, ratio, alphaOf, borderRatio }
`

/** Programmatic login — NC canonicalizes to localhost; stay on BASE origin. */
async function login(page, user) {
	for (let attempt = 1; attempt <= 3; attempt++) {
		await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded', timeout: 45_000 })
		const html = await page.content()
		if (/maintenance mode|update is in progress|needs to be updated/i.test(html)) {
			throw new Error('Nextcloud is in maintenance/upgrade mode')
		}
		if (!page.url().includes('/login')) {
			return
		}
		const userInput = page.locator('input[name="user"], #user')
		const pass = page.locator('input[name="password"], #password')
		await userInput.first().waitFor({ state: 'visible', timeout: 30_000 })
		await userInput.first().fill(user, { force: true })
		await pass.first().fill(PASS, { force: true })
		await page.evaluate(() => {
			const btn = document.querySelector('[data-login-form-submit], button[type="submit"], input[type="submit"], button.login-button')
			if (btn) /** @type {HTMLElement} */ (btn).click()
		})
		await page.waitForTimeout(1500)
		// Confirm a real session by visiting the app surface on THIS origin —
		// unauthenticated app pages redirect back to /login.
		await page.goto(`${BASE}/index.php/apps/logcheck/`, { waitUntil: 'domcontentloaded', timeout: 45_000 })
		if (!page.url().includes('/login')) {
			return
		}
	}
	throw new Error(`login_failed for ${user} — check DS_PROBE_PASS`)
}

/** Server-pinned OCS theme switch (learned: client flips fake HC readings). */
async function setUserTheme(page, themeId) {
	const failures = await page.evaluate(async ({ target, all }) => {
		const token = (window.OC && window.OC.requestToken)
			|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken') || ''
		const headers = { requesttoken: token, 'OCS-APIRequest': 'true', Accept: 'application/json' }
		const problems = []
		for (const id of all.filter((t) => t !== target)) {
			const res = await fetch(`/ocs/v2.php/apps/theming/api/v1/theme/${id}`, { method: 'DELETE', credentials: 'same-origin', headers })
			if (!res.ok && res.status !== 400) problems.push(`disable ${id}: HTTP ${res.status}`)
		}
		const res = await fetch(`/ocs/v2.php/apps/theming/api/v1/theme/${target}/enable`, { method: 'PUT', credentials: 'same-origin', headers })
		if (!res.ok && res.status !== 400) problems.push(`enable ${target}: HTTP ${res.status}`)
		return problems
	}, { target: themeId, all: THEMES })
	if (failures.length) throw new Error(`theme ${themeId}: ${failures.join(';')}`)
}

async function themeMarkerOk(page, themeId) {
	return page.evaluate((t) => {
		const attr = `data-theme-${t}`
		const dataThemes = document.body.getAttribute('data-themes') || ''
		return document.body.hasAttribute(attr) || dataThemes.split(/\s+/).includes(t)
	}, themeId)
}

/** Open a collapsed <details> in-page (deterministic, no label lookup). */
async function openDetails(page, sel) {
	await page.locator(sel).first().evaluate((el) => {
		if (el instanceof HTMLDetailsElement) el.open = true
	}).catch(() => {})
}

/** Elements to measure per page. Anchor = fabricated-capture guard. */
const PROBES = [
	{
		page: '/index.php/apps/logcheck/',
		anchor: '#lck-main-content .lck-home, .lck-home',
		label: 'home',
		rows: [
			{ sel: '.lck-page-title, #lck-page-title', kind: 'page-title-ink', what: 'text' },
			{ sel: '.lck-page-header__lead, .lck-muted', kind: 'muted-ink', what: 'text' },
			{ sel: '#lck-check-again, .lck-btn--primary', kind: 'primary-cta', what: 'both' },
			{ sel: '.lck-btn--secondary', kind: 'secondary-cta', what: 'both' },
			{ sel: '.lck-btn--ghost', kind: 'ghost-cta', what: 'both', optional: true },
			{ sel: '.lck-health-card', kind: 'card-boundary', what: 'outerborder' },
			{ sel: '.lck-health-card__title', kind: 'card-title-ink', what: 'text' },
			{ sel: '.lck-badge', kind: 'badge-ink', what: 'text' },
			{ sel: '.lck-badge', kind: 'badge-boundary', what: 'outerborder' },
			{ sel: '.lck-callout', kind: 'callout-boundary', what: 'outerborder', optional: true },
			{ sel: '.lck-status-card', kind: 'statuscard-boundary', what: 'outerborder', optional: true },
			{ sel: '.lck-watching-row, .lck-switch-field', kind: 'switch-row', what: 'outerborder', optional: true },
			{ sel: '.lck-skip-link', kind: 'skip-link', what: 'text', optional: true },
		],
	},
	{
		page: '/index.php/apps/logcheck/logs',
		anchor: '#lck-main-content .lck-logs, .lck-logs',
		label: 'logs',
		rows: [
			{ sel: '.lck-logs-file', kind: 'logfile-boundary', what: 'outerborder', optional: true },
			{ sel: '.lck-logs-filter-chip', kind: 'filter-chip-boundary', what: 'outerborder' },
			{ sel: '.lck-logs-filter-chip span', kind: 'filter-chip-ink', what: 'text' },
			{ sel: '#lck-logs-search', kind: 'search-input-border', what: 'border' },
			{ sel: '#lck-logs-search-btn, #lck-logs-reload, #lck-logs-copy', kind: 'logs-cta', what: 'both' },
			{ sel: '.lck-logs-more-menu summary', kind: 'more-summary', what: 'both' },
			{ sel: '#lck-logs-viewer', kind: 'viewer-boundary', what: 'outerborder', optional: true },
			{ sel: '.lck-logs-hint, .lck-logs-status', kind: 'logs-muted-ink', what: 'text' },
			{ sel: '.lck-logs-row', kind: 'log-row-ink', what: 'text', optional: true },
			{ sel: '.lck-logs-file__input', kind: 'file-radio-border', what: 'border', optional: true },
		],
	},
	{
		page: '/index.php/apps/logcheck/settings/alerts',
		anchor: '#lck-settings-form',
		label: 'settings-alerts',
		user: 'admin',
		setup: async (page) => {
			for (const sel of ['details.lck-more', '#lck-more-options']) {
				await openDetails(page, sel)
			}
		},
		rows: [
			{ sel: '#lck-email-recipients, #lck-slack-url, #lck-webhook-url, #lck-excerpt-confirm', kind: 'control-border', what: 'border' },
			{ sel: '#lck-settings-form button[type="submit"]', kind: 'primary-cta', what: 'both' },
			{ sel: '.lck-test-turn-on', kind: 'test-cta', what: 'both' },
			{ sel: '.lck-btn--danger, #lck-slack-clear-btn, #lck-webhook-clear-btn', kind: 'danger-cta', what: 'both', optional: true },
			{ sel: '.lck-channel-card', kind: 'channel-card-boundary', what: 'outerborder' },
			{ sel: '.lck-switch-field__track', kind: 'switch-track-boundary', what: 'outerborder' },
			{ sel: '.lck-switch-field__text', kind: 'switch-label-ink', what: 'text' },
			{ sel: '#lck-settings-form label', kind: 'label-ink', what: 'text' },
			{ sel: '.lck-callout--warning', kind: 'warning-callout-boundary', what: 'outerborder', optional: true },
			{ sel: '.lck-callout--warning p', kind: 'warning-callout-ink', what: 'text', optional: true },
			{ sel: '#lck-settings-form legend, .lck-settings-nav a', kind: 'nav-label-ink', what: 'text', optional: true },
		],
	},
	{
		page: '/index.php/apps/logcheck/settings/rules',
		anchor: '#lck-settings-form',
		label: 'settings-rules',
		setup: async (page) => {
			await openDetails(page, 'details.lck-more')
		},
		rows: [
			{ sel: '.lck-chip', kind: 'chip-boundary', what: 'outerborder' },
			{ sel: '.lck-chip', kind: 'chip-ink', what: 'text' },
			{ sel: '.lck-chip.is-active', kind: 'chip-active', what: 'both' },
			{ sel: '#lck-app-mode', kind: 'select-border', what: 'border' },
			{ sel: '#lck-app-list, #lck-mute-apps', kind: 'rules-control-border', what: 'border' },
			{ sel: '#lck-mutes', kind: 'textarea-border', what: 'border' },
			{ sel: '.lck-chip-group legend', kind: 'legend-ink', what: 'text' },
			{ sel: '#lck-settings-form button[type="submit"]', kind: 'primary-cta', what: 'both' },
		],
	},
	{
		page: '/index.php/apps/logcheck/settings/people',
		anchor: '#lck-settings-form',
		label: 'settings-people',
		user: 'admin',
		rows: [
			{ sel: '#lck-people-search', kind: 'combobox-border', what: 'border' },
			{ sel: '.lck-person-chip', kind: 'person-chip-boundary', what: 'outerborder' },
			{ sel: '.lck-remove-person', kind: 'remove-chip-btn', what: 'both', optional: true },
			{ sel: '.lck-access-explain', kind: 'info-callout-boundary', what: 'outerborder' },
			{ sel: '#lck-settings-form button[type="submit"]', kind: 'primary-cta', what: 'both' },
		],
	},
	{
		page: '/index.php/apps/logcheck/settings/support',
		anchor: '.lck-support',
		label: 'settings-support',
		rows: [
			{ sel: '.lck-support-links .lck-btn--primary', kind: 'donate-cta', what: 'both' },
			{ sel: '.lck-support-links .lck-btn--secondary', kind: 'enterprise-cta', what: 'both' },
			{ sel: '.lck-support__website-link', kind: 'ext-link-ink', what: 'text', optional: true },
			{ sel: '.lck-support__block', kind: 'support-block-boundary', what: 'outerborder', optional: true },
		],
	},
]

async function settle(page) {
	await page.waitForLoadState('domcontentloaded').catch(() => {})
	try { await page.waitForLoadState('networkidle', { timeout: 5000 }) } catch { /* long-polls */ }
	await page.evaluate(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r))))
}

async function measure(page, probes) {
	const results = []
	for (const p of probes) {
		const resp = await page.goto(`${BASE}${p.page}`, { waitUntil: 'domcontentloaded' })
		if (!resp || resp.status() >= 400) {
			results.push({ page: p.label, error: `http ${resp ? resp.status() : 'nav-fail'}`, rows: [] })
			continue
		}
		// Surface anchor assert BEFORE any measurement (fabricated-capture guard).
		try {
			await page.waitForSelector(p.anchor, { timeout: 30_000 })
		} catch {
			results.push({ page: p.label, error: `anchor ${p.anchor} missing — not the app surface`, rows: [] })
			continue
		}
		if (p.setup) await p.setup(page).catch(() => {})
		await settle(page)
		const pageRes = { page: p.label, rows: [] }
		for (const row of p.rows || []) {
			const found = await page.evaluate(
				async ({ sel, what, borderSide }) => {
					const els = Array.from(document.querySelectorAll(sel)).filter(
						(n) => n.offsetParent !== null,
					)
					const out = []
					for (const el of els.slice(0, 6)) {
						const cs = getComputedStyle(el)
						const bg = window.__lckProbe.effBg(el)
						const border = borderSide ? cs[borderSide] : cs.borderColor
						const bWidth = borderSide ? cs.borderInlineStartWidth : cs.borderWidth
						const item = {
							tag: el.tagName.toLowerCase(),
							cls: (el.getAttribute('class') || '').slice(0, 80),
							fg: cs.color,
							bg,
							borderColor: border,
							borderWidth: bWidth,
							selfBg: cs.backgroundColor,
						}
						if (what === 'outerborder') {
							/* Card/container boundaries are identified against
							   the SURROUNDING surface (canvas/card), not the
							   card's own fill — WCAG 1.4.11 adjacent colors. */
							item.borderRatio = parseFloat(bWidth) > 0
								? window.__lckProbe.borderRatio(border, window.__lckProbe.effBgParent(el))
								: null
							item.outerBg = window.__lckProbe.effBgParent(el)
						} else {
							if (what !== 'border') {
								item.textRatio = window.__lckProbe.ratio(cs.color, bg)
							}
							if (what !== 'text' && parseFloat(bWidth) > 0) {
								if (border !== cs.backgroundColor) {
									item.borderRatio = window.__lckProbe.borderRatio(border, bg)
									item.borderAlpha = window.__lckProbe.alphaOf(border)
								} else {
									item.fillRatio = window.__lckProbe.ratio(cs.backgroundColor, window.__lckProbe.effBgParent(el))
								}
							}
						}
						out.push(item)
					}
					return out
				},
				{ sel: row.sel, what: row.what, borderSide: row.borderSide || null },
			)
			pageRes.rows.push({ kind: row.kind, selector: row.sel, what: row.what, found: found.length, optional: !!row.optional, samples: found })
		}
		results.push(pageRes)
	}
	return results
}

/**
 * Logs confirm dialog (NC admin): measure control/button boundaries, the
 * danger confirm CTA, and the aria-invalid painted border + error ink after
 * a wrong confirm word (learned class: aria-invalid without painted border).
 * Cancel only — the destructive path is never confirmed.
 */
async function measureDialog(page) {
	const out = {}
	await page.goto(`${BASE}/index.php/apps/logcheck/logs`, { waitUntil: 'domcontentloaded' })
	await page.waitForSelector('.lck-logs', { timeout: 30_000 })
	await settle(page)
	const zone = page.locator('#lck-logs-actions')
	if (!(await zone.count())) {
		out.error = 'no #lck-logs-actions — log file not mutable on this instance'
		return out
	}
	await zone.evaluate((el) => { if (el instanceof HTMLDetailsElement) el.open = true })

	const measureIn = (sel, innerSel) => page.evaluate(({ sel, innerSel }) => {
		const dlg = [...document.querySelectorAll(sel)].find((d) => d.open || d.offsetParent !== null)
		if (!dlg) return { error: 'dialog not open: ' + sel }
		const els = [...dlg.querySelectorAll(innerSel)].filter((n) => n.offsetParent !== null)
		return {
			dialogBorder: (() => {
				const cs = getComputedStyle(dlg)
				return { borderColor: cs.borderColor, borderWidth: cs.borderWidth, ratio: window.__lckProbe.borderRatio(cs.borderColor, window.__lckProbe.effBg(dlg)) }
			})(),
			samples: els.slice(0, 8).map((el) => {
				const cs = getComputedStyle(el)
				const bg = window.__lckProbe.effBg(el)
				const bordered = parseFloat(cs.borderWidth) > 0
				const borderDiffers = bordered && cs.borderColor !== cs.backgroundColor
				return {
					tag: el.tagName.toLowerCase(),
					cls: (el.getAttribute('class') || '').slice(0, 60),
					fg: cs.color,
					borderColor: cs.borderColor,
					borderWidth: cs.borderWidth,
					bg,
					textRatio: window.__lckProbe.ratio(cs.color, bg),
					borderRatio: borderDiffers ? window.__lckProbe.borderRatio(cs.borderColor, bg) : null,
					fillRatio: bordered && !borderDiffers ? window.__lckProbe.ratio(cs.backgroundColor, window.__lckProbe.effBgParent(el)) : null,
				}
			}),
		}
	}, { sel, innerSel })

	// 1) delete confirm → dialog + danger CTA measured (cancel only).
	await page.locator('#lck-logs-delete').click()
	await page.locator('#lck-logs-confirm-dialog').waitFor({ state: 'visible', timeout: 8000 })
	await settle(page)
	out.confirmDialog = await measureIn('#lck-logs-confirm-dialog', 'button, input')
	out.dangerCta = await page.evaluate(() => {
		const el = document.getElementById('lck-logs-confirm-ok')
		if (!el) return { error: 'no danger cta' }
		const cs = getComputedStyle(el)
		return {
			fg: cs.color,
			selfBg: cs.backgroundColor,
			borderColor: cs.borderColor,
			textRatio: window.__lckProbe.ratio(cs.color, cs.backgroundColor),
		}
	})

	// 2) wrong confirm word → aria-invalid painted border + error ink.
	await page.locator('#lck-logs-confirm-input').fill('WRONG-WORD')
	await page.locator('#lck-logs-confirm-ok').click()
	await page.waitForTimeout(500)
	out.invalidState = await page.evaluate(() => {
		const input = document.getElementById('lck-logs-confirm-input')
		if (!input) return { error: 'confirm input detached' }
		const cs = getComputedStyle(input)
		const bg = window.__lckProbe.effBg(input)
		const res = {
			ariaInvalid: input.getAttribute('aria-invalid'),
			ariaDescribedby: input.getAttribute('aria-describedby'),
			border: {
				borderColor: cs.borderColor,
				borderWidth: cs.borderWidth,
				borderRatio: window.__lckProbe.borderRatio(cs.borderColor, bg),
			},
			boxShadow: cs.boxShadow,
		}
		const err = document.querySelector('dialog[open] .lck-field-error:not(:empty)')
		if (err) {
			const ecs = getComputedStyle(err)
			res.errorText = {
				text: (err.textContent || '').slice(0, 120),
				color: ecs.color,
				bg: window.__lckProbe.effBg(err),
				textRatio: window.__lckProbe.ratio(ecs.color, window.__lckProbe.effBg(err)),
			}
		}
		res.errorShown = !!err
		return res
	})
	await page.locator('#lck-logs-confirm-cancel').click().catch(() => {})
	await page.keyboard.press('Escape').catch(() => {})
	return out
}

/**
 * Settings field-error well: real 422 save (excerpts on, no CONFIRM) renders
 * aria-invalid + .lck-field-error on #lck-excerpt-confirm — measured on the
 * live control, not injected.
 */
async function measureFieldError(page) {
	await page.goto(`${BASE}/index.php/apps/logcheck/settings/alerts`, { waitUntil: 'domcontentloaded' })
	await page.waitForSelector('#lck-settings-form', { timeout: 30_000 })
	await settle(page)
	await openDetails(page, '#lck-more-options')
	const wasChecked = await page.locator('#lck-excerpts').isChecked()
	if (!wasChecked) await page.locator('label[for="lck-excerpts"]').click()
	await page.locator('#lck-excerpt-confirm').fill('')
	const out = await page.locator('#lck-settings-form button[type="submit"]').first()
		.click()
		.then(async () => {
			await page.waitForTimeout(1400)
			return page.evaluate(() => {
				const input = document.getElementById('lck-excerpt-confirm')
				const err = document.querySelector('.lck-field-error')
				if (!input || !err) return { error: 'no aria-invalid field-error pair after 422' }
				const ics = getComputedStyle(input)
				const ecs = getComputedStyle(err)
				return {
					ariaInvalid: input.getAttribute('aria-invalid'),
					text: (err.textContent || '').trim().slice(0, 120),
					inputBorder: {
						borderColor: ics.borderColor,
						borderWidth: ics.borderWidth,
						borderRatio: window.__lckProbe.borderRatio(ics.borderColor, window.__lckProbe.effBg(input)),
					},
					errorText: {
						color: ecs.color,
						textRatio: window.__lckProbe.ratio(ecs.color, window.__lckProbe.effBg(err)),
					},
				}
			})
		})
	// Restore the switch (save was rejected — nothing persisted).
	const nowChecked = await page.locator('#lck-excerpts').isChecked()
	if (nowChecked && !wasChecked) await page.locator('label[for="lck-excerpts"]').click()
	return out
}

/**
 * Semantic accent surfaces not always reachable from fixture state: inject
 * representative nodes into the live document and measure the COMPUTED token
 * resolution (stylesheet truth) — callouts, badges, toasts, empty state.
 * Danger fill+ink pair measured under each theme (theme-flipping mix class:
 * color-mix→primary-element-text resolves near-black in dark themes).
 */
async function measureAccentSurfaces(page) {
	// app.css is only loaded on logcheck surfaces — measure inside .lck-app.
	await page.goto(`${BASE}/index.php/apps/logcheck/`, { waitUntil: 'domcontentloaded' })
	await page.locator('#lck-main-content').first().waitFor({ state: 'visible', timeout: 20_000 })
	await settle(page)
	return page.evaluate(() => {
		const host = document.querySelector('.lck-app') || document.getElementById('app-content') || document.body
		const out = []
		const mk = (cls, tag = 'p', parent = host) => {
			const el = document.createElement(tag)
			el.className = cls
			el.textContent = 'probe'
			parent.appendChild(el)
			return el
		}
		for (const cls of ['lck-callout lck-callout--warning', 'lck-callout lck-callout--info', 'lck-callout']) {
			const el = mk(cls)
			const cs = getComputedStyle(el)
			const bg = window.__lckProbe.effBg(el)
			out.push({
				kind: `callout:${cls}`,
				selfBg: cs.backgroundColor,
				bg,
				fg: cs.color,
				borderColor: cs.borderColor,
				borderWidth: cs.borderWidth,
				textRatio: window.__lckProbe.ratio(cs.color, bg),
				borderRatio: parseFloat(cs.borderWidth) > 0 ? window.__lckProbe.borderRatio(cs.borderColor, bg) : null,
			})
			el.remove()
		}
		for (const cls of ['lck-badge', 'lck-badge lck-badge--neutral', 'lck-muted', 'lck-field-error', 'lck-empty-state']) {
			const el = mk(cls, cls === 'lck-badge' || cls.includes('badge') ? 'span' : 'p')
			const cs = getComputedStyle(el)
			const bg = window.__lckProbe.effBg(el)
			out.push({
				kind: `ink:${cls}`,
				fg: cs.color,
				selfBg: cs.backgroundColor,
				bg,
				textRatio: window.__lckProbe.ratio(cs.color, bg),
			})
			el.remove()
		}
		// Danger + secondary button fill/ink pairs under each theme.
		for (const [kind, cls] of [['danger-button', 'lck-btn lck-btn--danger'], ['primary-button', 'lck-btn lck-btn--primary'], ['secondary-button', 'lck-btn lck-btn--secondary'], ['ghost-button', 'lck-btn lck-btn--ghost']]) {
			const btn = mk(cls, 'button')
			const bcs = getComputedStyle(btn)
			out.push({
				kind,
				fg: bcs.color,
				selfBg: bcs.backgroundColor,
				borderColor: bcs.borderColor,
				borderWidth: bcs.borderWidth,
				/* effBg walks parents when the fill is transparent — WCAG measures
				   ink against what is actually painted behind it, not against
				   rgba(0,0,0,0) read as opaque black. */
				textRatio: window.__lckProbe.ratio(bcs.color, window.__lckProbe.effBg(btn)),
				borderRatio: parseFloat(bcs.borderWidth) > 0
					? window.__lckProbe.borderRatio(bcs.borderColor, window.__lckProbe.effBgParent(btn))
					: null,
			})
			btn.remove()
		}
		// Error toast (app-owned container + kind class).
		const toastHost = document.getElementById('lck-toasts') || (() => {
			const c = document.createElement('div')
			c.id = 'lck-toasts'
			c.className = 'lck-toasts'
			document.body.appendChild(c)
			return c
		})()
		const toast = mk('lck-toast lck-toast--error', 'div', toastHost)
		const tcs = getComputedStyle(toast)
		out.push({
			kind: 'toast-error',
			fg: tcs.color,
			selfBg: tcs.backgroundColor,
			bg: window.__lckProbe.effBg(toast),
			borderColor: tcs.borderColor,
			borderWidth: tcs.borderWidth,
			textRatio: window.__lckProbe.ratio(tcs.color, window.__lckProbe.effBg(toast)),
			borderRatio: parseFloat(tcs.borderWidth) > 0 ? window.__lckProbe.borderRatio(tcs.borderColor, window.__lckProbe.effBg(toast)) : null,
		})
		toast.remove()
		return out
	})
}

async function runForUser(browser, role) {
	const context = await browser.newContext({ baseURL: BASE, viewport: { width: 1440, height: 900 } })
	const page = await context.newPage()
	await login(page, USERS[role])
	await context.addInitScript(EVAL_FN)
	await page.goto(`${BASE}/index.php/apps/logcheck/`, { waitUntil: 'domcontentloaded' })
	await page.evaluate(new Function(EVAL_FN))
	return { context, page }
}

async function main() {
	const browser = await chromium.launch({ headless: true })
	const report = {
		app: 'logcheck', probe: 'live-computed-contrast', base: BASE,
		users: USERS,
		generated_at: new Date().toISOString(),
		note: 'lck_ds_probe (app admin — entitled non-NC-admin) / lck_ds_admin (NC admin) fixtures; OCS-persisted themes; canonical localhost origin',
		themes: {},
	}

	// Probe leg — home/logs/rules/support surfaces, all four themes.
	try {
		const { context, page } = await runForUser(browser, 'probe')
		for (const theme of THEMES) {
			await page.goto(`${BASE}/index.php/apps/logcheck/`, { waitUntil: 'domcontentloaded' })
			await setUserTheme(page, theme)
			// Marker assert AFTER a real navigation — OCS persistence is only
			// painted on the next render (never trust pre-nav body attrs).
			await page.goto(`${BASE}/index.php/apps/logcheck/`, { waitUntil: 'domcontentloaded' })
			const markerOk = await themeMarkerOk(page, theme)
			const themeRes = { themeMarker: markerOk, pages: await measure(page, PROBES.filter((p) => p.user !== 'admin')) }
			themeRes.accents = await measureAccentSurfaces(page)
			report.themes[theme] = themeRes
		}
		// Restore fixture user to light for subsequent lanes.
		await page.goto(`${BASE}/index.php/apps/logcheck/`, { waitUntil: 'domcontentloaded' })
		await setUserTheme(page, 'light')
		await context.close()
	} catch (e) {
		report.probeError = String(e)
	}

	// Admin leg — settings pages + confirm dialog + real field-error well.
	try {
		const { context, page } = await runForUser(browser, 'admin')
		for (const theme of THEMES) {
			await page.goto(`${BASE}/index.php/apps/logcheck/settings/alerts`, { waitUntil: 'domcontentloaded' })
			await setUserTheme(page, theme)
			await page.goto(`${BASE}/index.php/apps/logcheck/settings/alerts`, { waitUntil: 'domcontentloaded' })
			const markerOk = await themeMarkerOk(page, theme)
			const t = (report.themes[theme] = report.themes[theme] || {})
			t.adminThemeMarker = markerOk
			t.adminPages = await measure(page, PROBES.filter((p) => p.user === 'admin'))
			if (theme === 'light' || theme === 'dark') {
				t.dialog = await measureDialog(page)
				t.fieldErrorWell = await measureFieldError(page)
			}
		}
		await page.goto(`${BASE}/index.php/apps/logcheck/settings/alerts`, { waitUntil: 'domcontentloaded' })
		await setUserTheme(page, 'light')
		await context.close()
	} catch (e) {
		report.adminError = String(e)
	}

	await browser.close()

	const TEXT_MIN = 4.5
	const BORDER_MIN = 3.0
	const findings = []
	const judgeRows = (theme, pr) => {
		for (const row of pr.rows || []) {
			if (row.found === 0 && !row.optional) {
				findings.push({ theme, page: pr.page, kind: 'probe-selector-empty', detail: row.selector })
				continue
			}
			for (const s of row.samples || []) {
				if (s.textRatio !== undefined && s.textRatio !== null && s.textRatio < TEXT_MIN) {
					findings.push({ theme, page: pr.page, kind: row.kind, cls: s.cls, ratio: s.textRatio, min: TEXT_MIN })
				}
				if (s.borderRatio !== undefined && s.borderRatio !== null && s.borderRatio < BORDER_MIN) {
					findings.push({ theme, page: pr.page, kind: row.kind + '-border', cls: s.cls, ratio: s.borderRatio, min: BORDER_MIN })
				}
				if (s.fillRatio !== undefined && s.fillRatio !== null && s.fillRatio < BORDER_MIN) {
					findings.push({ theme, page: pr.page, kind: row.kind + '-fill-boundary', cls: s.cls, ratio: s.fillRatio, min: BORDER_MIN })
				}
			}
		}
	}
	for (const [theme, t] of Object.entries(report.themes)) {
		if (t.themeMarker === false) findings.push({ theme, kind: 'theme-marker-missing' })
		if (t.adminThemeMarker === false) findings.push({ theme, kind: 'admin-theme-marker-missing' })
		for (const pr of t.pages || []) {
			if (pr.error) { findings.push({ theme, page: pr.page, kind: 'page-error', detail: pr.error }); continue }
			judgeRows(theme, pr)
		}
		for (const pr of t.adminPages || []) {
			if (pr.error) { findings.push({ theme, page: pr.page, kind: 'page-error', detail: pr.error }); continue }
			judgeRows(theme, pr)
		}
		for (const s of t.accents || []) {
			if (s.textRatio !== null && s.textRatio !== undefined && s.textRatio < TEXT_MIN) {
				findings.push({ theme, page: 'accents', kind: s.kind + '-ink', ratio: s.textRatio, min: TEXT_MIN })
			}
			if (s.borderRatio !== null && s.borderRatio !== undefined && s.borderRatio < BORDER_MIN) {
				findings.push({ theme, page: 'accents', kind: s.kind + '-border', ratio: s.borderRatio, min: BORDER_MIN })
			}
		}
		const d = t.dialog
		if (d && !d.error) {
			for (const s of d.confirmDialog?.samples || []) {
				if (s.textRatio !== null && s.textRatio < TEXT_MIN) {
					findings.push({ theme, page: 'dialog:confirm', kind: 'dialog-ink', cls: s.cls, ratio: s.textRatio, min: TEXT_MIN })
				}
				if (s.borderRatio !== null && s.borderRatio < BORDER_MIN) {
					findings.push({ theme, page: 'dialog:confirm', kind: 'dialog-border', cls: s.cls, ratio: s.borderRatio, min: BORDER_MIN })
				}
			}
			const inv = d.invalidState
			if (inv && !inv.error) {
				if (inv.ariaInvalid !== 'true') {
					findings.push({ theme, page: 'confirm-dialog', kind: 'aria-invalid-missing', detail: `aria-invalid=${inv.ariaInvalid}` })
				} else if (inv.border && inv.border.borderRatio !== null && inv.border.borderRatio < BORDER_MIN) {
					findings.push({ theme, page: 'confirm-dialog', kind: 'invalid-border', ratio: inv.border.borderRatio, min: BORDER_MIN })
				}
				// aria-invalid unpainted: invalid control must carry a DIFFERENT
				// border than its valid state.
				const validInput = (d.confirmDialog?.samples || []).find((s) => s.tag === 'input')
				if (validInput && inv.border && inv.border.borderColor === validInput.borderColor) {
					findings.push({
						theme, page: 'confirm-dialog', kind: 'aria-invalid-unpainted',
						detail: `invalid border equals valid border (${inv.border.borderColor}) — no visual differentiation`,
					})
				}
				if (inv.errorText && inv.errorText.textRatio !== null && inv.errorText.textRatio < TEXT_MIN) {
					findings.push({ theme, page: 'confirm-dialog', kind: 'field-error-text', ratio: inv.errorText.textRatio, min: TEXT_MIN })
				}
			}
			if (d.dangerCta && !d.dangerCta.error && d.dangerCta.textRatio !== null && d.dangerCta.textRatio < TEXT_MIN) {
				findings.push({ theme, page: 'dialog:confirm', kind: 'danger-cta-ink', ratio: d.dangerCta.textRatio, min: TEXT_MIN })
			}
		}
		const well = t.fieldErrorWell
		if (well && !well.error) {
			if (well.errorText && well.errorText.textRatio !== null && well.errorText.textRatio < TEXT_MIN) {
				findings.push({ theme, page: 'settings-alerts', kind: 'field-error-ink', ratio: well.errorText.textRatio, min: TEXT_MIN })
			}
			if (well.inputBorder && well.inputBorder.borderRatio !== null && well.inputBorder.borderRatio < BORDER_MIN) {
				findings.push({ theme, page: 'settings-alerts', kind: 'invalid-input-border', ratio: well.inputBorder.borderRatio, min: BORDER_MIN })
			}
		}
	}
	report.findings = findings
	report.verdict = findings.length === 0 ? 'PASS' : 'FAIL'

	const outIdx = process.argv.indexOf('--out')
	const outPath = outIdx > 0 ? process.argv[outIdx + 1] : null
	if (outPath) {
		mkdirSync(dirname(outPath), { recursive: true })
		writeFileSync(outPath, JSON.stringify(report, null, 2))
		console.log(`wrote ${outPath}`)
	} else {
		console.log(JSON.stringify(report, null, 2).slice(0, 4000))
	}
	console.log(`contrast probe: ${report.verdict} (${findings.length} findings)`)
	process.exit(findings.length > 0 ? 1 : 0)
}

main().catch((e) => {
	console.error(e)
	process.exit(2)
})
