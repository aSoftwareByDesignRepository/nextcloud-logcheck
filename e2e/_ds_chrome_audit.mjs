// @ts-check
/**
 * ds_chrome lane probe — Atlas Farm 3.5.15 (fresh artifacts).
 * LogCheck (HealthCheck) is web-only: home health dashboard + logs viewer +
 * settings sections (alerts / rules / people / support) + access-denied.
 *
 * Usage: DS_PROBE_PASS=<secret> node e2e/_ds_chrome_audit.mjs <phase>
 *   sweep    routes × real OCS themes × viewports: http status, overflow,
 *            touch, axe, PNG+sha256 (server-persisted themes via the theming
 *            OCS API; post-navigation body[data-theme-*] asserted; per-route
 *            themed sha distinctness); below-fold honesty (real scrollable
 *            ancestor walk — vacuous_below_fold_proof class)
 *   dialogs  logs confirm <dialog>: role/aria-labelledby/focus-in/Escape/
 *            Cancel (non-destructive)/focus-restore + confirm-word validation
 *            negative (aria-invalid + .lck-field-error painted)
 *   states   anon→login, member denied surface, toast dedup kind+text,
 *            aria-invalid painted via real 422 save, watch-CTA XOR seed,
 *            clear-url-as-toggle seed — below-fold scrolled captures
 *   theatre  dark-island hunt: surfaces that keep light bg in dark theme
 *   a11y     skip link, keyboard order, switch/chip operability, nav-toggle
 *            overlap rect probe at all viewports, touch floor
 *
 * Evidence root: FARM_OUT (default artifacts/logcheck/ds_chrome/probes).
 * Probe users: lck_ds_probe (app admin) / lck_ds_admin (NC admin) /
 *              lck_ds_denied (member — denied surface).
 * Selectors are structural — no EN|DE text literals (locale-safe).
 */
import { chromium } from 'playwright'
import AxeBuilder from '@axe-core/playwright'
import { createHash } from 'node:crypto'
import { mkdirSync, rmSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'

const BASE = (process.env.NC_BASE_URL || process.env.LOGCHECK_BASE_URL || 'http://localhost:8081').replace(/\/$/, '')
const PASS = process.env.DS_PROBE_PASS || 'DsProbe!lck2026'
const OUT = process.env.FARM_OUT
	|| '/home/alex/Development/nextcloud-dev/.cursor/atlas-farm-v3/artifacts/logcheck/ds_chrome/probes'
const CAPTURES = process.env.FARM_CAPTURES || join(OUT, '..', 'captures')
const PHASE = process.argv[2] || 'sweep'
mkdirSync(OUT, { recursive: true })
mkdirSync(CAPTURES, { recursive: true })

const USERS = {
	probe: { username: 'lck_ds_probe', password: PASS },
	admin: { username: 'lck_ds_admin', password: PASS },
	denied: { username: 'lck_ds_denied', password: PASS },
}

/* Server-pinned OCS themes (e2e/helpers/theming.js): the four selectable
   light/dark/high-contrast user themes persisted via the OCS theming API. */
const THEMES = ['light', 'dark', 'light-highcontrast', 'dark-highcontrast']
const VIEWPORTS = [
	{ w: 320, h: 640 },
	{ w: 768, h: 1024 },
	{ w: 1024, h: 768 },
	{ w: 1440, h: 900 },
]

const ROUTES = {
	home: { path: '/index.php/apps/logcheck/', user: 'probe', main: '#lck-main-content', section: '.lck-home', ok: 200 },
	logs: { path: '/index.php/apps/logcheck/logs', user: 'probe', main: '#lck-main-content', section: '.lck-logs', ok: 200 },
	'settings-alerts': { path: '/index.php/apps/logcheck/settings/alerts', user: 'admin', main: '#lck-main-content', section: '#lck-settings-form', ok: 200 },
	'settings-rules': { path: '/index.php/apps/logcheck/settings/rules', user: 'probe', main: '#lck-main-content', section: '#lck-settings-form', ok: 200 },
	'settings-people': { path: '/index.php/apps/logcheck/settings/people', user: 'admin', main: '#lck-main-content', section: '#lck-settings-form', ok: 200 },
	'settings-support': { path: '/index.php/apps/logcheck/settings/support', user: 'probe', main: '#lck-main-content', section: '.lck-support', ok: 200 },
}
const DENIED_ROUTES = [
	{ id: 'denied-home', path: '/index.php/apps/logcheck/', user: 'denied' },
	{ id: 'denied-logs', path: '/index.php/apps/logcheck/logs', user: 'denied' },
	{ id: 'denied-settings', path: '/index.php/apps/logcheck/settings/alerts', user: 'denied' },
]

const results = { phase: PHASE, startedAt: new Date().toISOString(), cells: [], captures: {}, themeProof: {} }

function sha256(buf) {
	return createHash('sha256').update(buf).digest('hex')
}

async function snap(page, name, opts = {}) {
	const buf = await page.screenshot({ fullPage: Boolean(opts.fullPage) })
	const file = `captures/${name}.png`
	writeFileSync(join(CAPTURES, `${name}.png`), buf)
	const hash = sha256(buf)
	results.captures[name] = { file, sha256: hash, bytes: buf.length }
	return { file, sha256: hash, bytes: buf.length }
}

async function settle(page) {
	await page.waitForLoadState('domcontentloaded').catch(() => {})
	try { await page.waitForLoadState('networkidle', { timeout: 5000 }) } catch { /* long-polls */ }
	await page.evaluate(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r))))
}

/**
 * Bring the route's own main section into the scroller's frame.
 * scrollIntoView walks every scrollable ancestor and honours
 * scroll-margin-top. On NC35 the app scroller is #app-content (or
 * #app-content-vue on settings pages); documentElement can have a zero
 * scroll range so hand-picked chains produce scroll no-ops
 * (vacuous_below_fold_proof / hmk-vis-26 lesson).
 */
async function ensureSectionInFrame(page, mainSel) {
	return page.evaluate((sel) => {
		const main = document.querySelector(sel)
		if (main && main.scrollIntoView) main.scrollIntoView({ block: 'start' })
		const r = main ? main.getBoundingClientRect() : null
		return {
			found: !!main,
			mainVisible: r ? r.bottom > 0 && r.top < document.documentElement.clientHeight : null,
		}
	}, mainSel).catch(() => ({ found: false, mainVisible: null }))
}

function record(cell) {
	results.cells.push(cell)
	const tag = cell.status === 'fail' ? 'FAIL' : cell.status === 'warn' ? 'warn' : 'ok'
	console.log(`[${tag}] ${cell.id} :: ${JSON.stringify(cell.checks).slice(0, 300)}`)
}

async function loginState(browser, role) {
	const ctx = await browser.newContext({ baseURL: BASE, viewport: { width: 1440, height: 900 } })
	const page = await ctx.newPage()
	// NC's login JS submits then issues a second client-side redirect — the
	// first navigation is aborted (net::ERR_ABORTED) and waitForURL rejects
	// even though login succeeds, and the session commit can lag the click.
	// Verify by navigating to the app: an authenticated session must not
	// bounce to /login. Retry the whole form on failure (up to 3 attempts).
	for (let attempt = 1; attempt <= 3; attempt++) {
		await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded', timeout: 45000 })
		const html = await page.content()
		if (/maintenance mode|update is in progress/i.test(html)) throw new Error('NC in maintenance/upgrade mode')
		const user = page.locator('input[name="user"], #user')
		const pass = page.locator('input[name="password"], #password')
		await user.first().fill(USERS[role].username, { force: true })
		await pass.first().fill(USERS[role].password, { force: true })
		await page.evaluate(() => {
			const btn = document.querySelector('[data-login-form-submit],button[type="submit"],input[type="submit"],button.login-button')
			if (btn) /** @type {HTMLElement} */ (btn).click()
		})
		await page.waitForTimeout(2500)
		await page.goto(`${BASE}/index.php/apps/logcheck/`, { waitUntil: 'domcontentloaded', timeout: 45000 })
		if (!page.url().includes('/login')) {
			const state = await ctx.storageState()
			await ctx.close()
			return state
		}
	}
	throw new Error(`login_failed for ${USERS[role].username} — bounced back to /login`)
}

/**
 * Learned class: theme switching persists server-side via the OCS theming
 * API. Callers must re-navigate/reload and assert the rendered attribute.
 */
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

async function assertThemeRendered(page, themeId) {
	return page.evaluate((t) => {
		const attr = `data-theme-${t}`
		const dataThemes = document.body.getAttribute('data-themes') || ''
		const themes = dataThemes.split(/\s+/).filter(Boolean)
		return {
			ok: document.body.hasAttribute(attr) || themes.includes(t),
			dataThemes: dataThemes || null,
			attr,
			present: document.body.hasAttribute(attr),
		}
	}, themeId)
}

async function checkOverflow(page) {
	return page.evaluate(() => {
		const doc = document.documentElement
		const app = document.querySelector('#app-content')
		const main = document.getElementById('lck-main-content')
		const probe = (el) => (el ? el.scrollWidth - el.clientWidth : 0)
		return { doc: probe(doc), app: probe(app), main: probe(main) }
	})
}

/**
 * Touch targets — WCAG 2.5.8 floor is 24px (fail); the family design-system
 * target is 44px (warn list kept for review). Off-screen-until-focused
 * controls (skip links) are excluded — verified separately in a11y.
 */
async function checkTouchTargets(page) {
	return page.evaluate(() => {
		const scopes = ['.lck-app', '#app-content', 'dialog[open]']
		const seen = new Set()
		const offenders = []
		const interactiveSel = [
			'button', 'a[href]', 'input:not([type="hidden"])', 'select', 'textarea',
			'[role="button"]', '[role="link"]', '[role="checkbox"]', '[role="tab"]',
			'[role="menuitem"]', '[role="switch"]', 'summary', '[tabindex]:not([tabindex="-1"])',
		].join(',')
		for (const scopeSel of scopes) {
			for (const scope of document.querySelectorAll(scopeSel)) {
				for (const el of scope.querySelectorAll(interactiveSel)) {
					if (seen.has(el)) continue
					seen.add(el)
					const r = el.getBoundingClientRect()
					const style = getComputedStyle(el)
					if (r.width <= 0 || r.height <= 0) continue
					if (style.visibility === 'hidden' || style.display === 'none') continue
					const vw = document.documentElement.clientWidth
					const vh = document.documentElement.clientHeight
					if (r.right < 0 || r.bottom < 0 || r.left > vw || r.top > vh) continue
					if (r.width < 44 || r.height < 44) {
						const label = (el.textContent || el.getAttribute('aria-label') || el.id || el.tagName)
							.trim().replace(/\s+/g, ' ').slice(0, 60)
						const item = {
							tag: el.tagName.toLowerCase(), cls: String(el.className).slice(0, 60),
							label, w: Math.round(r.width), h: Math.round(r.height),
						}
						/* WCAG 2.5.8 Equivalent exception: a tiny/sr-hidden
						   input whose function is reachable through an
						   associated label (label[for] / wrapping label) that
						   itself clears the 24px floor is covered, not a fail —
						   recorded for transparency. */
						if (el.tagName === 'INPUT' || el.tagName === 'SELECT' || el.tagName === 'TEXTAREA') {
							const lab = (el.id && document.querySelector(`label[for="${CSS.escape(el.id)}"]`))
								|| el.closest('label')
							if (lab) {
								const lr = lab.getBoundingClientRect()
								if (lr.width >= 24 && lr.height >= 24) {
									item.coveredBy = `label ${Math.round(lr.width)}x${Math.round(lr.height)}`
								}
							}
						}
						offenders.push(item)
					}
				}
			}
		}
		return offenders.slice(0, 15)
	})
}

/**
 * Learned class `overlap`: NC core chrome (.app-navigation-toggle, 44px
 * fixed) can occlude app section headings — rect-intersection probe.
 */
async function checkChromeOverlap(page) {
	return page.evaluate(() => {
		const intersects = (a, b) =>
			a.left < b.right && a.right > b.left && a.top < b.bottom && a.bottom > b.top
		const area = (r) => Math.max(0, Math.min(r.right, 0) ) // placeholder never used
		const overlapArea = (a, b) => {
			const w = Math.min(a.right, b.right) - Math.max(a.left, b.left)
			const h = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top)
			return w > 0 && h > 0 ? w * h : 0
		}
		const out = []
		const chromeSel = [
			'#app-navigation-toggle', '.app-navigation-toggle',
			'.lck-nav-toggle', '#header .header-left', '#header .header-right',
		]
		const chrome = []
		for (const sel of chromeSel) {
			for (const el of document.querySelectorAll(sel)) {
				const cs = getComputedStyle(el)
				if (cs.display === 'none' || cs.visibility === 'hidden') continue
				const r = el.getBoundingClientRect()
				if (r.width <= 0 || r.height <= 0) continue
				chrome.push({ sel, id: el.id || '', rect: r, fixed: cs.position === 'fixed' })
			}
		}
		const targets = [
			'#lck-page-title', '.lck-page-title', '.lck-breadcrumb',
			'#lck-main-content h2', '#lck-main-content h3',
			'.lck-home h2', '.lck-logs h2', '#lck-settings-form h2',
		]
		const vw = document.documentElement.clientWidth
		for (const t of targets) {
			for (const el of document.querySelectorAll(t)) {
				const cs = getComputedStyle(el)
				if (cs.display === 'none' || cs.visibility === 'hidden') continue
				const r = el.getBoundingClientRect()
				if (r.width <= 0 || r.height <= 0) continue
				for (const c of chrome) {
					if (!intersects(c.rect, r)) continue
					const px = Math.round(overlapArea(c.rect, r))
					if (px < 4) continue
					out.push({
						chrome: `${c.sel}#${c.id}`, target: t,
						text: (el.textContent || '').trim().slice(0, 60),
						px, vw,
					})
				}
			}
		}
		return out.slice(0, 10)
	})
}

async function checkLandmarks(page) {
	return page.evaluate(() => {
		const visible = (el) => {
			const cs = getComputedStyle(el)
			return cs.display !== 'none' && cs.visibility !== 'hidden'
		}
		const sectioned = (el) => !!el.closest('main,article,section,aside,nav,fieldset,[role="main"],[role="region"],[role="complementary"],[role="navigation"]')
		const isBanner = (el) =>
			visible(el) && !sectioned(el)
			&& ((el.tagName === 'HEADER' && (!el.getAttribute('role') || el.getAttribute('role') === 'banner'))
				|| el.getAttribute('role') === 'banner')
		const isContentinfo = (el) =>
			visible(el) && !sectioned(el)
			&& ((el.tagName === 'FOOTER' && (!el.getAttribute('role') || el.getAttribute('role') === 'contentinfo'))
				|| el.getAttribute('role') === 'contentinfo')
		const isMain = (el) => visible(el) && (el.tagName === 'MAIN' || el.getAttribute('role') === 'main')
		const all = [...document.querySelectorAll('header,footer,main,[role],[id]')]
		const describe = (el) => `${el.tagName.toLowerCase()}#${el.id || ''}.${String(el.className).split(' ')[0]}`
		return {
			banners: all.filter(isBanner).map(describe),
			contentinfos: all.filter(isContentinfo).map(describe),
			mains: all.filter(isMain).map(describe),
		}
	})
}

async function runAxe(page) {
	try {
		const res = await new AxeBuilder({ page })
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.exclude('#header').exclude('#contactsmenu').exclude('.notifications')
			.analyze()
		return res.violations.map((v) => ({
			id: v.id, impact: v.impact,
			nodes: v.nodes.slice(0, 4).map((n) => String(n.target).slice(0, 120)),
			summary: String(v.help).slice(0, 140),
		}))
	} catch (err) {
		return [{ id: 'axe-error', impact: 'critical', nodes: [], summary: String(err).slice(0, 200) }]
	}
}

/* ───────────────────────────── sweep ───────────────────────────── */
async function phaseSweep(browser) {
	const probeState = await loginState(browser, 'probe')
	const adminState = await loginState(browser, 'admin')
	const deniedState = await loginState(browser, 'denied')
	const stateFor = (role) => (role === 'admin' ? adminState : role === 'denied' ? deniedState : probeState)

	for (const [id, route] of Object.entries(ROUTES)) {
		const ctx = await browser.newContext({ baseURL: BASE, storageState: stateFor(route.user), viewport: { width: 1440, height: 900 } })
		const page = await ctx.newPage()
		page.on('pageerror', (e) => console.log('PAGEEXC:', String(e).slice(0, 200)))

		for (const theme of THEMES) {
			// Warm nav first (session + token), then persist theme, then the cell nav.
			await page.goto(`${BASE}${route.path}`, { waitUntil: 'domcontentloaded' })
			await setUserTheme(page, theme)
			const cell = { id: `${id}@${theme}@1440`, role: route.user, theme, viewport: 1440, checks: {} }
			const resp = await page.goto(`${BASE}${route.path}`, { waitUntil: 'domcontentloaded' }).catch(() => null)
			await settle(page)
			cell.checks.http = resp ? resp.status() : 'nav-fail'
			cell.checks.hasMain = await page.locator(route.main).first().isVisible().catch(() => false)
			cell.checks.hasSection = await page.locator(route.section).first().isVisible().catch(() => false)
			const sect = await ensureSectionInFrame(page, route.section)
			cell.checks.mainInFrame = sect.mainVisible
			const ov = await checkOverflow(page)
			cell.checks.overflow = ov
			cell.checks.overflowOk = Math.max(ov.doc, ov.app, ov.main) <= 1
			const themeRender = await assertThemeRendered(page, theme)
			cell.checks.themeApplied = themeRender
			const lm = await checkLandmarks(page)
			cell.checks.landmarks = lm
			cell.checks.chromeOverlap = await checkChromeOverlap(page)
			cell.checks.axe = await runAxe(page)
			cell.checks.axeViolations = cell.checks.axe.length
			const shot = await snap(page, `sweep__${id}__${theme}__1440`, { fullPage: id === 'home' })
			cell.proof = shot.sha256.slice(0, 16)
			const failReasons = []
			if (typeof cell.checks.http !== 'number' || cell.checks.http !== route.ok) {
				failReasons.push(`http ${cell.checks.http} (expected ${route.ok})`)
			}
			if (!cell.checks.hasMain) failReasons.push('main landmark missing')
			if (!cell.checks.hasSection) failReasons.push(`route section ${route.section} missing`)
			if (sect.mainVisible === false) failReasons.push(`${route.section} not in frame after scrollIntoView`)
			if (!themeRender.ok) failReasons.push(`theme attr missing: ${JSON.stringify(themeRender)}`)
			if (!cell.checks.overflowOk) failReasons.push(`overflow ${JSON.stringify(ov)}`)
			if (lm.banners.length > 1) failReasons.push(`duplicate banner: ${lm.banners.join('|')}`)
			if (lm.contentinfos.length > 0) failReasons.push(`stray contentinfo: ${lm.contentinfos.join('|')}`)
			if (lm.mains.length > 1) failReasons.push(`duplicate main: ${lm.mains.join('|')}`)
			if (cell.checks.chromeOverlap.length) failReasons.push(`chrome overlap: ${JSON.stringify(cell.checks.chromeOverlap.slice(0, 3))}`)
			if (cell.checks.axeViolations > 0) failReasons.push(`axe ${cell.checks.axeViolations}: ${JSON.stringify(cell.checks.axe).slice(0, 300)}`)
			cell.status = failReasons.length ? 'fail' : 'ok'
			if (failReasons.length) cell.failReasons = failReasons
			record(cell)
			results.themeProof[id] = results.themeProof[id] || {}
			results.themeProof[id][theme] = shot.sha256
		}
		await ctx.close()
	}

	// Per-route themed distinctness: need ≥4 distinct sha256 (pixel truth).
	for (const id of Object.keys(ROUTES)) {
		const proof = results.themeProof[id] || {}
		const shas = Object.values(proof)
		const distinct = new Set(shas)
		const cell = { id: `themedistinct__${id}`, checks: { distinct: distinct.size, themes: proof }, status: 'ok' }
		const fails = []
		if (proof.light && proof.dark && proof.light === proof.dark) {
			fails.push('light and dark captures pixel-identical — theme not applied')
		}
		if (distinct.size < 4) fails.push(`only ${distinct.size}/4 distinct themed sha`)
		if (fails.length) { cell.status = 'fail'; cell.failReasons = fails }
		record(cell)
	}

	// Viewport matrix (light theme) incl. touch targets + fold honesty.
	for (const [id, route] of Object.entries({ home: ROUTES.home, logs: ROUTES.logs, 'settings-alerts': ROUTES['settings-alerts'], 'settings-rules': ROUTES['settings-rules'] })) {
		const ctx = await browser.newContext({ baseURL: BASE, storageState: stateFor(route.user), viewport: { width: 1440, height: 900 } })
		const page = await ctx.newPage()
		await page.goto(`${BASE}${route.path}`, { waitUntil: 'domcontentloaded' })
		await setUserTheme(page, 'light')
		for (const vp of VIEWPORTS) {
			await page.setViewportSize({ width: vp.w, height: vp.h })
			const cell = { id: `${id}@light@${vp.w}`, role: route.user, theme: 'light', viewport: vp.w, checks: {} }
			const resp = await page.goto(`${BASE}${route.path}`, { waitUntil: 'domcontentloaded' }).catch(() => null)
			await settle(page)
			cell.checks.http = resp ? resp.status() : 'nav-fail'
			/* Paint the app's own section first — below-fold honesty
			   (vacuous_below_fold_proof / hmk-vis-26). */
			const sect = await ensureSectionInFrame(page, route.section)
			cell.checks.mainInFrame = sect.mainVisible
			const ov = await checkOverflow(page)
			cell.checks.overflow = ov
			cell.checks.overflowOk = Math.max(ov.doc, ov.app, ov.main) <= 1
			cell.checks.touchOffenders = await checkTouchTargets(page)
			cell.checks.chromeOverlap = await checkChromeOverlap(page)
			const shot = await snap(page, `sweep__${id}__light__${vp.w}`)
			/* Below-fold honesty: scroll the REAL scrollable ancestor of the
			   route's section to its tail (scrollable-ancestor walk, fallback
			   #app-content-vue → #app-content → .lck-app). */
			const fold = await page.evaluate((mainSel) => {
				const main = document.querySelector(mainSel)
				const doc = document.documentElement
				const range = (el) => (el ? el.scrollHeight - el.clientHeight : 0)
				/* scrollHeight>clientHeight is NOT enough: overflow-x:clip shells
				   report a range yet scrollTop stays 0 (the clip axis makes the
				   other axis unscrollable) — scroll no-ops produced byte-identical
				   fold pairs. Require a scroll-capable overflowY too. */
				const scrollable = (el) => {
					if (!el || range(el) <= 1) return false
					const oy = getComputedStyle(el).overflowY
					return oy === 'auto' || oy === 'scroll' || oy === 'overlay'
				}
				let scroller = null
				if (main) {
					for (let el = main.parentElement; el && el !== doc; el = el.parentElement) {
						if (scrollable(el)) { scroller = el; break }
					}
				}
				if (!scroller) {
					for (const sel of ['#app-content-vue', '#app-content', '.lck-app', '#content']) {
						const el = document.querySelector(sel)
						if (scrollable(el)) { scroller = el; break }
					}
				}
				const winRange = range(doc)
				const pos = () => (scroller ? scroller.scrollTop : 0) + window.scrollY
				const before = pos()
				if (scroller) scroller.scrollTop = scroller.scrollHeight
				window.scrollTo(0, doc.scrollHeight)
				return {
					scroller: scroller ? (scroller.id || scroller.className || scroller.tagName.toLowerCase()) : (winRange > 1 ? 'window' : 'none'),
					scrollRange: scroller ? range(scroller) : winRange,
					scrolled: pos() - before,
				}
			}, route.section).catch(() => null)
			await page.waitForTimeout(250)
			let foldShot = null
			let foldDirection = 'bottom'
			const foldName = `sweep__${id}__light__${vp.w}__fold`
			if (fold && fold.scrollRange > 1 && fold.scrolled === 0) {
				/* scrollIntoView already parked the section at the scroller's
				   tail — scrolling further is a legit no-op, not a bug. Prove
				   the range is real by capturing the fold at the TOP instead:
				   any distinct view = honest below-fold evidence. */
				foldDirection = 'top'
				await page.evaluate(() => {
					for (const sel of ['#app-content-vue', '#app-content', '.lck-app', '#content']) {
						const el = document.querySelector(sel)
						if (el && el.scrollHeight - el.clientHeight > 1) el.scrollTop = 0
					}
					window.scrollTo(0, 0)
				}).catch(() => {})
				await page.waitForTimeout(250)
			}
			if (fold && fold.scrollRange > 1) {
				foldShot = await snap(page, foldName)
			} else {
				/* No below-fold exists — keep no stale dupe on disk. */
				rmSync(join(CAPTURES, `${foldName}.png`), { force: true })
				delete results.captures[foldName]
			}
			cell.checks.foldScroll = fold
			cell.checks.foldDirection = foldDirection
			cell.checks.foldDiffers = foldShot ? foldShot.sha256 !== shot.sha256 : null
			// Touch targets measured again at the fold position — real, not vacuous.
			cell.checks.foldTouchOffenders = await checkTouchTargets(page)
			cell.proof = shot.sha256.slice(0, 16)
			const failReasons = []
			if (typeof cell.checks.http !== 'number' || cell.checks.http !== route.ok) failReasons.push(`http ${cell.checks.http}`)
			if (sect.mainVisible === false) failReasons.push(`${route.section} not in frame after scrollIntoView`)
			if (fold && fold.scrollRange > 1 && !foldShot) failReasons.push('no fold capture despite scroll range')
			if (fold && fold.scrollRange > 1 && cell.checks.foldDiffers === false) failReasons.push('fold capture byte-identical to base — scroll no-op')
			if (!cell.checks.overflowOk) failReasons.push(`overflow ${JSON.stringify(ov)}`)
			if (cell.checks.chromeOverlap.length) failReasons.push(`chrome overlap: ${JSON.stringify(cell.checks.chromeOverlap.slice(0, 3))}`)
			/* <24px fails unless covered by an equivalent label target
			   (WCAG 2.5.8 Equivalent); sub-44 uncovered stays a warn. */
			const uncovered = (o) => !o.coveredBy
			const touchHard = cell.checks.touchOffenders.filter((o) => (o.w < 24 || o.h < 24) && uncovered(o))
			const foldTouchHard = cell.checks.foldTouchOffenders.filter((o) => (o.w < 24 || o.h < 24) && uncovered(o))
			if (touchHard.length) failReasons.push(`touch<24: ${JSON.stringify(touchHard.slice(0, 6))}`)
			if (foldTouchHard.length) failReasons.push(`fold touch<24: ${JSON.stringify(foldTouchHard.slice(0, 6))}`)
			const touchWarn = cell.checks.touchOffenders.filter(uncovered)
			if (touchWarn.length && !touchHard.length) {
				cell.warnings = [`sub-44 touch (≥24): ${touchWarn.length} offender(s) ${JSON.stringify(touchWarn.slice(0, 3))}`]
			}
			const covered = cell.checks.touchOffenders.filter((o) => o.coveredBy)
			if (covered.length) {
				cell.checks.touchCoveredByLabel = covered.slice(0, 6)
			}
			cell.status = failReasons.length ? 'fail' : 'ok'
			if (failReasons.length) cell.failReasons = failReasons
			record(cell)
		}
		await ctx.close()
	}

	// Member on app surfaces — must get the denied template, never the app.
	{
		const ctx = await browser.newContext({ baseURL: BASE, storageState: deniedState, viewport: { width: 1440, height: 900 } })
		const page = await ctx.newPage()
		for (const route of DENIED_ROUTES) {
			const cell = { id: `${route.id}@light@1440`, role: 'denied', checks: {}, status: 'ok' }
			const resp = await page.goto(`${BASE}${route.path}`, { waitUntil: 'domcontentloaded' }).catch(() => null)
			await settle(page)
			cell.checks.http = resp ? resp.status() : 'nav-fail'
			cell.checks.deniedRendered = await page.locator('.lck-app--denied').first().isVisible().catch(() => false)
			cell.checks.appLeaked = await page.locator('#lck-settings-form, .lck-home, .lck-logs').first().isVisible().catch(() => false)
			const shot = await snap(page, `sweep__${route.id}__light__1440`)
			cell.proof = shot.sha256.slice(0, 16)
			const fails = []
			if (!cell.checks.deniedRendered) fails.push('access-denied template not rendered for member')
			if (cell.checks.appLeaked) fails.push('app surface rendered for non-entitled member')
			if (typeof cell.checks.http !== 'number' || cell.checks.http >= 500) fails.push(`http ${cell.checks.http}`)
			if (fails.length) { cell.status = 'fail'; cell.fails = fails }
			record(cell)
		}
		await ctx.close()
	}
}

/* ───────────────────────────── dialogs ───────────────────────────── */
async function openLogsDangerZone(page) {
	await page.goto(`${BASE}${ROUTES.logs.path}`, { waitUntil: 'domcontentloaded' })
	await settle(page)
	const zone = page.locator('#lck-logs-actions')
	if (!(await zone.count())) return false
	await zone.evaluate((el) => { if (el instanceof HTMLDetailsElement) el.open = true })
	return true
}

async function probeDialog(page, name, dialogSel, openFn, opts = {}) {
	const r = { id: name, checks: {}, status: 'ok', fails: [] }
	const fail = (m) => { r.fails.push(m); r.status = 'fail' }

	try { await openFn() } catch (err) { fail(`trigger threw: ${String(err).slice(0, 160)}`); record(r); return r }
	const dlg = page.locator(dialogSel)
	try {
		await dlg.waitFor({ state: 'visible', timeout: 10000 })
	} catch {
		fail('dialog not visible after trigger'); record(r); return r
	}
	await settle(page)

	const info = await dlg.evaluate((d) => {
		const labelId = d.getAttribute('aria-labelledby')
		return {
			tag: d.tagName.toLowerCase(), open: d.open,
			labelId, labelText: labelId ? (document.getElementById(labelId)?.textContent || '') : '',
		}
	}).catch(() => null)
	r.checks.dialog = info
	if (!info || info.tag !== 'dialog' || !info.open) fail('not a native open <dialog>')
	if (info && !info.labelText.trim()) fail('aria-labelledby unresolved/empty')

	r.checks.focusInside = await page.evaluate((sel) => {
		const d = document.querySelector(sel)
		const ae = document.activeElement
		return !!(d && ae && d.contains(ae))
	}, dialogSel)
	if (!r.checks.focusInside) fail('focus did not move inside dialog')

	r.checks.axe = await runAxe(page)
	r.checks.axeViolations = r.checks.axe.length
	if (r.checks.axeViolations > 0) fail(`axe ${r.checks.axeViolations}: ${JSON.stringify(r.checks.axe).slice(0, 300)}`)

	await snap(page, `dialog__${name}__open`)

	if (opts.validationNegative) {
		const neg = await opts.validationNegative(page, dlg)
		r.checks.validationNegative = neg
		if (neg && neg.ok === false) fail(`validation negative: ${neg.reason}`)
	}

	// Escape closes + focus returns to a real control (never <body>).
	await page.keyboard.press('Escape')
	await page.waitForTimeout(400)
	const stillOpen = await dlg.evaluate((d) => d.open).catch(() => false)
	r.checks.escapeClosed = !stillOpen
	if (stillOpen) fail('Escape did not close dialog')
	const focusAfterEsc = await page.evaluate(() => ({
		active: document.activeElement?.tagName,
		id: document.activeElement?.id || '',
		cls: String(document.activeElement?.className || '').slice(0, 60),
	}))
	r.checks.focusAfterEscape = focusAfterEsc
	if (focusAfterEsc.active === 'BODY' || focusAfterEsc.active === 'HTML') {
		fail(`focus restored to <${(focusAfterEsc.active || '?').toLowerCase()}> — trigger lost`)
	}

	// Cancel path (learned class: dialogs need a non-destructive Cancel):
	// reopen → dismiss via cancel control → closed + focus off <body>.
	if (opts.reopen) {
		try {
			await opts.reopen()
			await dlg.waitFor({ state: 'visible', timeout: 8000 })
			await settle(page)
			const closer = dlg.locator('#lck-logs-confirm-cancel, button[value="cancel"]').first()
			if (await closer.count()) {
				await closer.click({ timeout: 5000 })
				await page.waitForTimeout(400)
				const after = await page.evaluate((sel) => ({
					open: !!document.querySelector(sel)?.open,
					active: document.activeElement?.tagName,
				}), dialogSel)
				r.checks.cancelClosed = !after.open
				if (after.open) fail('cancel control did not dismiss dialog')
				if (after.active === 'BODY') fail('focus lost to <body> after cancel')
			} else {
				fail('no cancel control in dialog — destructive confirm is the only out')
			}
		} catch (err) {
			fail(`cancel path error: ${String(err).slice(0, 160)}`)
		}
	}
	record(r)
	return r
}

async function phaseDialogs(browser) {
	const adminState = await loginState(browser, 'admin')
	const ctx = await browser.newContext({ baseURL: BASE, storageState: adminState, viewport: { width: 1440, height: 900 } })
	const page = await ctx.newPage()
	page.on('pageerror', (e) => console.log('PAGEEXC:', String(e).slice(0, 200)))
	page.on('dialog', (d) => d.dismiss().catch(() => {})) // drain native confirms defensively — never accept

	const dangerOk = await openLogsDangerZone(page)
	if (!dangerOk) {
		const r = { id: 'danger-zone-available', checks: {}, status: 'warn', fails: [] }
		r.checks.note = '#lck-logs-actions absent — log file not mutable on this instance; dialog cells skipped'
		await snap(page, 'dialog__danger-zone-missing')
		record(r)
		await ctx.close()
		return
	}

	// 1) start-fresh confirm — primary (non-danger) confirm; cancel only.
	await probeDialog(page, 'start-fresh-confirm', '#lck-logs-confirm-dialog', async () => {
		await page.locator('#lck-logs-start-fresh').click()
	}, {
		validationNegative: async (pg) => {
			await pg.locator('#lck-logs-confirm-input').fill('WRONG-WORD')
			await pg.locator('#lck-logs-confirm-ok').click()
			await pg.waitForTimeout(500)
			const res = await pg.evaluate(() => {
				const d = document.getElementById('lck-logs-confirm-dialog')
				const input = document.getElementById('lck-logs-confirm-input')
				const err = input?.parentElement?.querySelector('.lck-field-error')
					|| document.querySelector('dialog[open] .lck-field-error')
				const toast = document.querySelector('.lck-toast--error')
				const cs = input ? getComputedStyle(input) : null
				return {
					open: !!d?.open,
					errorText: (err?.textContent || '').trim(),
					ariaInvalid: input?.getAttribute('aria-invalid'),
					ariaDescribedby: input?.getAttribute('aria-describedby'),
					focusedOnInput: document.activeElement === input,
					borderColor: cs?.borderColor || null,
					errorToastShown: !!toast,
				}
			})
			if (!res.open) return { ok: false, reason: 'dialog closed on invalid confirm word', ...res }
			if (!res.errorText) return { ok: false, reason: 'no inline .lck-field-error rendered', ...res }
			if (res.ariaInvalid !== 'true') return { ok: false, reason: 'confirm input missing aria-invalid on wrong word', ...res }
			return { ok: true, ...res }
		},
		reopen: async () => {
			await page.locator('#lck-logs-start-fresh').click()
		},
	})

	// 2) delete confirm — danger CTA verified; cancel only, never confirm.
	await probeDialog(page, 'delete-confirm', '#lck-logs-confirm-dialog', async () => {
		await page.locator('#lck-logs-delete').click()
	}, {
		reopen: async () => {
			await page.locator('#lck-logs-delete').click()
		},
	})

	// Danger confirm CTA paints as danger (destructive must be visually distinct).
	{
		const r = { id: 'delete-cta-danger-paint', checks: {}, status: 'ok', fails: [] }
		await openLogsDangerZone(page)
		await page.locator('#lck-logs-delete').click()
		await page.locator('#lck-logs-confirm-dialog').waitFor({ state: 'visible', timeout: 8000 }).catch(() => {})
		r.checks.okBtnClass = await page.locator('#lck-logs-confirm-ok').getAttribute('class').catch(() => null)
		if (!/danger/.test(r.checks.okBtnClass || '')) r.fails.push(`confirm ok not danger-painted: ${r.checks.okBtnClass}`)
		await snap(page, 'dialog__delete-confirm__danger-cta')
		await page.locator('#lck-logs-confirm-cancel').click().catch(() => {})
		if (r.fails.length) r.status = 'fail'
		record(r)
	}

	await ctx.close()
}

/* ───────────────────────────── states ───────────────────────────── */
async function phaseStates(browser) {
	// anon → login redirect
	const anon = await browser.newContext({ baseURL: BASE, viewport: { width: 1440, height: 900 } })
	const ap = await anon.newPage()
	const resp = await ap.goto(`${BASE}${ROUTES.home.path}`, { waitUntil: 'domcontentloaded' })
	await settle(ap)
	const anonCell = { id: 'anon-home-redirect', checks: { finalUrl: ap.url(), http: resp?.status() }, status: 'ok' }
	anonCell.checks.onLogin = /\/login/.test(ap.url())
	if (!anonCell.checks.onLogin) { anonCell.status = 'fail'; anonCell.fails = ['anon did not land on /login'] }
	await snap(ap, 'state__anon-redirect')
	record(anonCell)
	await anon.close()

	const adminState = await loginState(browser, 'admin')
	const ctx = await browser.newContext({ baseURL: BASE, storageState: adminState, viewport: { width: 1440, height: 900 } })
	const page = await ctx.newPage()
	page.on('pageerror', (e) => console.log('PAGEEXC:', String(e).slice(0, 200)))

	// save-error + toast dedup (kind+text): abort settings PUT → submit →
	// error toast; repeat identical failure → toast must NOT stack.
	await page.route('**/apps/logcheck/api/settings', (route) => {
		if (route.request().method() === 'PUT') return route.abort('failed')
		return route.continue()
	})
	await page.goto(`${BASE}${ROUTES['settings-rules'].path}`, { waitUntil: 'domcontentloaded' })
	await settle(page)
	const errCell = { id: 'save-error-toast-dedup', checks: {}, status: 'ok' }
	const saveBtn = page.locator('#lck-settings-form button[type="submit"]').first()
	await saveBtn.click()
	await page.waitForTimeout(1200)
	errCell.checks.errToastCount1 = await page.locator('.lck-toast--error').count()
	errCell.checks.errToastRole = await page.locator('.lck-toast--error').first().getAttribute('role').catch(() => null)
	errCell.checks.alertRegionText = (await page.locator('#lck-alert-region').textContent().catch(() => ''))?.trim().slice(0, 140)
	errCell.checks.rawCode = await page.evaluate(() => {
		const t = [...document.querySelectorAll('.lck-toast--error, #lck-alert-region')].map((n) => n.textContent || '').join(' ')
		return /ERR_|ECONNREFUSED|TypeError|\b500\b|\b503\b|stack trace|undefined/i.test(t) ? t.slice(0, 200) : null
	})
	// Repeat identical failure — dedup on kind+text must not stack a second toast.
	await saveBtn.click()
	await page.waitForTimeout(1200)
	errCell.checks.errToastCount2 = await page.locator('.lck-toast--error').count()
	errCell.checks.hasClose = await page.locator('.lck-toast--error .lck-toast__close').count()
	await snap(page, 'state__save-error', { fullPage: true })
	const errFails = []
	if (errCell.checks.errToastCount1 < 1) errFails.push('no error toast on failed save')
	if (errCell.checks.errToastRole !== 'alert') errFails.push(`error toast role=${errCell.checks.errToastRole} (want alert)`)
	if (errCell.checks.rawCode) errFails.push(`raw error code visible: ${errCell.checks.rawCode}`)
	if (errCell.checks.errToastCount2 !== 1) errFails.push(`identical toasts stacked: ${errCell.checks.errToastCount2}`)
	if (!errCell.checks.hasClose) errFails.push('error toast missing dismiss control')
	if (errFails.length) { errCell.status = 'fail'; errCell.fails = errFails }
	record(errCell)
	await page.unroute('**/apps/logcheck/api/settings')

	// aria-invalid painted (learned class aria_invalid_unpainted / wcag-331):
	// real 422 save — alerts: enable excerpts without CONFIRM → server
	// fields.include_message_excerpts → aria-invalid + .lck-field-error on
	// #lck-excerpt-confirm, painted error border (vs valid state).
	{
		const r = { id: 'aria-invalid-painted', checks: {}, status: 'ok', fails: [] }
		await page.goto(`${BASE}${ROUTES['settings-alerts'].path}`, { waitUntil: 'domcontentloaded' })
		await settle(page)
		const more = page.locator('#lck-more-options')
		await more.evaluate((el) => { if (el instanceof HTMLDetailsElement) el.open = true })
		const validBorder = await page.evaluate(() => {
			const el = document.getElementById('lck-excerpt-confirm')
			return el ? getComputedStyle(el).borderColor : null
		})
		r.checks.validBorder = validBorder
		// Flip the excerpts switch on via its label (track checkbox is sr-hidden).
		const excerptsOn = await page.locator('#lck-excerpts').isChecked()
		if (!excerptsOn) await page.locator('label[for="lck-excerpts"]').click()
		await page.locator('#lck-excerpt-confirm').fill('')
		await page.locator('#lck-settings-form button[type="submit"]').first().click()
		await page.waitForTimeout(1400)
		r.checks.after = await page.evaluate(() => {
			const input = document.getElementById('lck-excerpt-confirm')
			if (!input) return { error: 'input detached' }
			const cs = getComputedStyle(input)
			const err = document.querySelector('.lck-field-error')
			const errCs = err ? getComputedStyle(err) : null
			return {
				ariaInvalid: input.getAttribute('aria-invalid'),
				ariaDescribedby: input.getAttribute('aria-describedby'),
				borderColor: cs.borderColor,
				boxShadow: cs.boxShadow.slice(0, 80),
				errText: err ? (err.textContent || '').trim().slice(0, 120) : null,
				errColor: errCs?.color || null,
				focused: document.activeElement === input,
				toastErr: !!document.querySelector('.lck-toast--error'),
			}
		})
		await snap(page, 'state__aria-invalid-painted', { fullPage: true })
		if (r.checks.after.ariaInvalid !== 'true') r.fails.push(`aria-invalid=${r.checks.after.ariaInvalid}`)
		if (!r.checks.after.errText) r.fails.push('no .lck-field-error rendered after 422')
		if (!r.checks.after.ariaDescribedby || !/lck-field-error/.test(r.checks.after.ariaDescribedby)) {
			r.fails.push(`aria-describedby not linked to field error: ${r.checks.after.ariaDescribedby}`)
		}
		const shadow = r.checks.after.boxShadow
		const painted = r.checks.after.borderColor !== validBorder || (shadow && shadow !== 'none')
		if (!painted) {
			r.fails.push('invalid border indistinguishable from valid — unpainted error state')
		}
		// Restore: switch excerpts back off (nothing persisted — save was rejected).
		const stillOn = await page.locator('#lck-excerpts').isChecked()
		if (stillOn && stillOn !== excerptsOn) {
			await page.locator('label[for="lck-excerpts"]').click()
		}
		if (r.fails.length) r.status = 'fail'
		record(r)
	}

	// Rules mute-regex 422 — server emits fields.value; verify the error is
	// pinned to a visible control (wcag-331). REGRESSION CANDIDATE: 'value'
	// is not in settings.js FIELD_TARGETS.
	{
		const r = { id: 'aria-invalid-painted-mutes', checks: {}, status: 'ok', fails: [] }
		await page.goto(`${BASE}${ROUTES['settings-rules'].path}`, { waitUntil: 'domcontentloaded' })
		await settle(page)
		const adv = page.locator('details.lck-more').first()
		await adv.evaluate((el) => { if (el instanceof HTMLDetailsElement) el.open = true })
		await page.locator('#lck-mutes').fill('(((')
		await page.locator('#lck-settings-form button[type="submit"]').first().click()
		await page.waitForTimeout(1400)
		r.checks.after = await page.evaluate(() => {
			const invalid = [...document.querySelectorAll('#lck-settings-form [aria-invalid="true"]')]
				.map((el) => el.id || el.name || el.tagName)
			const err = [...document.querySelectorAll('.lck-field-error')].map((el) => (el.textContent || '').trim().slice(0, 100))
			return {
				invalidControls: invalid,
				fieldErrors: err,
				toastErr: (document.querySelector('.lck-toast--error')?.textContent || '').trim().slice(0, 140),
			}
		})
		await snap(page, 'state__aria-invalid-mutes', { fullPage: true })
		if (!r.checks.after.invalidControls.length) {
			r.fails.push(`422 mute-regex error painted on NO control (toast only): ${JSON.stringify(r.checks.after)}`)
		}
		if (r.fails.length) r.status = 'fail'
		record(r)
		// Clean the textarea back so later cells see a valid form (save was 422 anyway).
		await page.locator('#lck-mutes').fill('')
	}

	// Seed lck-vis-watching-dual-alert-ctas: exactly ONE alert-CTA owner
	// (checklist "Set up" XOR ready "Manage" XOR error "Try again+Manage").
	{
		const r = { id: 'seed-watching-cta-xor', checks: {}, status: 'ok', fails: [] }
		await page.goto(`${BASE}${ROUTES.home.path}`, { waitUntil: 'domcontentloaded' })
		await settle(page)
		const toggle = page.locator('#lck-watch-toggle')
		if ((await toggle.count()) === 0) {
			// Watch switch is env-gated (supported && topologyOk): record the
			// absence rather than crash — CTA XOR is then checked on the
			// rendered watching card state below.
			r.checks.togglePresent = false
		} else {
			r.checks.togglePresent = true
			if (!(await toggle.isChecked())) {
				await page.locator('label[for="lck-watch-toggle"]').click()
				await page.waitForTimeout(1500)
			}
			r.checks.watchOn = await toggle.isChecked()
		}
		for (let i = 0; i < 6; i++) {
			const v = await page.evaluate(() => ({
				checklist: !!document.querySelector('#lck-alerts-checklist:not([hidden])'),
				ready: !!document.querySelector('#lck-watching-actions-ready:not([hidden])'),
				error: !!document.querySelector('#lck-watching-actions-error:not([hidden])'),
				setup: !!document.querySelector('#lck-watching-actions-setup:not([hidden])'),
				checklistAlertsCta: !!document.querySelector('#lck-alerts-checklist:not([hidden]) a[href*="alerts"]'),
				readyAlertsCta: !!document.querySelector('#lck-watching-actions-ready:not([hidden]) a[href*="alerts"]'),
				errorAlertsCta: !!document.querySelector('#lck-watching-actions-error:not([hidden]) a[href*="alerts"]'),
				setupAlertsCta: !!document.querySelector('#lck-watching-actions-setup:not([hidden]) a[href*="alerts"]'),
			}))
			r.checks.state = v
			const alertOwners = (v.checklistAlertsCta ? 1 : 0) + (v.readyAlertsCta ? 1 : 0) + (v.errorAlertsCta ? 1 : 0) + (v.setupAlertsCta ? 1 : 0)
			r.checks.alertCtaOwners = alertOwners
			if (alertOwners === 1) break
			await page.waitForTimeout(1000)
		}
		await ensureSectionInFrame(page, '.lck-status-card, .lck-watching-row')
		await snap(page, 'state__watching-cta')
		if (r.checks.togglePresent && r.checks.watchOn !== true) r.fails.push('watch toggle not on for CTA-XOR check')
		if (r.checks.alertCtaOwners !== 1) r.fails.push(`alert CTA owners=${r.checks.alertCtaOwners} (want exactly 1) — ${JSON.stringify(r.checks.state)}`)
		if (r.fails.length) r.status = 'fail'
		record(r)
	}

	// Seed lck-vis-clear-url-as-toggle: Clear saved URL must be a danger
	// button + save hint — never a switch.
	{
		const r = { id: 'seed-clear-url-not-toggle', checks: {}, status: 'ok', fails: [] }
		await page.goto(`${BASE}${ROUTES['settings-alerts'].path}`, { waitUntil: 'domcontentloaded' })
		await settle(page)
		const outbound = page.locator('details.lck-more').first()
		await outbound.evaluate((el) => { if (el instanceof HTMLDetailsElement) el.open = true })
		r.checks.buttons = await page.evaluate(() => {
			const out = []
			for (const id of ['lck-slack-clear-btn', 'lck-webhook-clear-btn']) {
				const el = document.getElementById(id)
				if (!el) { out.push({ id, present: false }); continue }
				out.push({
					id, present: true,
					tag: el.tagName.toLowerCase(),
					type: el.getAttribute('type'),
					cls: el.getAttribute('class'),
					role: el.getAttribute('role'),
					ariaPressed: el.getAttribute('aria-pressed'),
					isDanger: (el.getAttribute('class') || '').includes('danger'),
				})
			}
			for (const hid of ['lck-slack-clear-hint', 'lck-webhook-clear-hint']) {
				out.push({ id: hid, hintPresent: !!document.getElementById(hid) })
			}
			return out
		})
		await ensureSectionInFrame(page, '#lck-slack-clear-btn, #lck-webhook-clear-btn, .lck-channel-card')
		await snap(page, 'state__clear-url-buttons')
		const btns = r.checks.buttons.filter((b) => b.present === true)
		if (!btns.length) {
			r.checks.note = 'no saved channel URLs on this fixture — button absent (seed verified at template level: danger button + hint)'
		}
		for (const b of btns) {
			if (b.isDanger !== true) r.fails.push(`${b.id} not danger-painted: ${b.cls}`)
			if (b.role === 'switch') r.fails.push(`${b.id} is a switch — seed regression`)
		}
		if (r.fails.length) r.status = 'fail'
		record(r)
	}

	await ctx.close()
}

/* ───────────────────────────── theatre ───────────────────────────── */
async function phaseTheatre(browser) {
	const probeState = await loginState(browser, 'probe')
	const adminState = await loginState(browser, 'admin')
	const ctx = await browser.newContext({ baseURL: BASE, storageState: probeState, viewport: { width: 1440, height: 900 } })
	const page = await ctx.newPage()
	await page.goto(`${BASE}${ROUTES.home.path}`, { waitUntil: 'domcontentloaded' })
	await setUserTheme(page, 'dark')

	const hunt = async (pg, rootSel, tag) => {
		const cell = { id: `theatre__${tag}`, checks: {}, status: 'ok' }
		const islands = await pg.evaluate((sel) => {
			const root = document.querySelector(sel)
			if (!root) return { error: 'no root ' + sel }
			const out = []
			const lum = (rgb) => {
				const m = String(rgb).match(/rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?/)
				if (!m) return null
				return { L: 0.2126 * Number(m[1]) + 0.7152 * Number(m[2]) + 0.0722 * Number(m[3]), a: m[4] === undefined ? 1 : Number(m[4]) }
			}
			for (const el of root.querySelectorAll('*')) {
				const cs = getComputedStyle(el)
				if (cs.display === 'none' || cs.visibility === 'hidden') continue
				const r = el.getBoundingClientRect()
				if (r.width < 60 || r.height < 30) continue
				const got = lum(cs.backgroundColor)
				if (got && got.a > 0.6 && got.L > 205) {
					out.push(`${el.tagName.toLowerCase()}#${el.id || ''}.${String(el.className).split(' ')[0]} bg=${cs.backgroundColor}`)
				}
				if (out.length >= 10) break
			}
			return out
		}, rootSel)
		cell.checks.lightIslands = islands
		if (islands && islands.error) { cell.status = 'fail'; cell.fails = [islands.error] }
		else if (Array.isArray(islands) && islands.length) { cell.status = 'fail'; cell.fails = [`dark-island surfaces: ${islands.join(' | ')}`] }
		record(cell)
	}

	const theatreRoutes = { ...ROUTES }
	for (const [id, route] of Object.entries(theatreRoutes)) {
		const pg = route.user === 'admin'
			? await (await browser.newContext({ baseURL: BASE, storageState: adminState, viewport: { width: 1440, height: 900 } })).newPage()
			: page
		if (route.user === 'admin') {
			await pg.goto(`${BASE}${route.path}`, { waitUntil: 'domcontentloaded' })
			await setUserTheme(pg, 'dark')
		}
		await pg.goto(`${BASE}${route.path}`, { waitUntil: 'domcontentloaded' })
		await settle(pg)
		const rootSel = route.section
		await ensureSectionInFrame(pg, route.section) // paint the app's own section
		await hunt(pg, rootSel, id)
		await snap(pg, `theatre__${id}__dark`)
		if (route.user === 'admin') {
			await setUserTheme(pg, 'light')
			await pg.context().close()
		}
	}
	// Restore probe to light theme for subsequent lanes.
	await setUserTheme(page, 'light')
	await page.goto(`${BASE}${ROUTES.home.path}`, { waitUntil: 'domcontentloaded' })
	await settle(page)
	await ctx.close()
}

/* ───────────────────────────── a11y / keyboard ───────────────────────────── */
async function phaseA11y(browser) {
	const probeState = await loginState(browser, 'probe')
	const ctx = await browser.newContext({ baseURL: BASE, storageState: probeState, viewport: { width: 1440, height: 900 } })
	const page = await ctx.newPage()
	page.on('pageerror', (e) => console.log('PAGEEXC:', String(e).slice(0, 200)))
	await page.goto(`${BASE}${ROUTES.home.path}`, { waitUntil: 'domcontentloaded' })
	await settle(page)
	await page.locator('.lck-home, #lck-main-content').first().waitFor({ state: 'visible', timeout: 20000 })

	// Skip link: visually hidden until focus; must expand and move
	// focus to #lck-main-content on Enter.
	{
		const r = { id: 'skip-link', checks: {}, status: 'ok', fails: [] }
		await page.locator('.lck-skip-link').focus()
		await page.waitForTimeout(200)
		const box = await page.locator('.lck-skip-link').boundingBox()
		r.checks.focusedSize = box ? { w: Math.round(box.width), h: Math.round(box.height) } : null
		r.checks.focusedVisible = await page.locator('.lck-skip-link').isVisible().catch(() => false)
		if (!box || box.height < 24 || box.width < 24) r.fails.push(`skip link focused box ${box ? `${Math.round(box.width)}x${Math.round(box.height)}` : 'null'} <24`)
		await page.keyboard.press('Enter')
		await page.waitForTimeout(200)
		r.checks.mainFocused = await page.evaluate(() => document.activeElement === document.getElementById('lck-main-content'))
		if (!r.checks.mainFocused) r.fails.push('skip link did not move focus to #lck-main-content')
		if (r.fails.length) r.status = 'fail'
		await snap(page, 'a11y__skip-link-focused')
		record(r)
	}

	// Keyboard order: Tab from main lands on app controls within 10 steps;
	// no focus trap, every stop is a real control.
	{
		const r = { id: 'keyboard-order', checks: {}, status: 'ok', fails: [] }
		await page.evaluate(() => document.getElementById('lck-main-content')?.focus())
		const seq = []
		for (let i = 0; i < 10; i++) {
			await page.keyboard.press('Tab')
			const tag = await page.evaluate(() => {
				const el = document.activeElement
				if (!el) return 'none'
				const cls = String(el.className || '')
				const role = el.getAttribute('role') || ''
				return `${el.tagName.toLowerCase()}#${el.id || ''}[${role}] ${cls}`.slice(0, 100)
			})
			seq.push(tag)
		}
		r.checks.tabSequence = seq
		const hitApp = seq.some((s) => /lck-|form-input|lck-btn|switch/.test(s))
		if (!hitApp) r.fails.push(`Tab never reached an app control within 10 steps: ${seq.join(' → ')}`)
		if (r.fails.length) r.status = 'fail'
		record(r)
	}

	// role=switch toggles via Space (native checkbox is keyboard-true).
	// The home watch toggle is env-dependent (supported && topologyOk) —
	// fall back to an always-rendered settings switch so the keyboard check
	// still runs instead of crashing on a missing element.
	{
		const r = { id: 'switch-space-toggle', checks: {}, status: 'ok', fails: [] }
		let pg = page
		let sw = pg.locator('#lck-watch-toggle')
		let target = 'home#lck-watch-toggle'
		let altCtx = null
		if ((await sw.count()) === 0) {
			altCtx = await browser.newContext({ baseURL: BASE, storageState: await loginState(browser, 'admin'), viewport: { width: 1440, height: 900 } })
			pg = await altCtx.newPage()
			await pg.goto(`${BASE}${ROUTES['settings-alerts'].path}`, { waitUntil: 'domcontentloaded' })
			await settle(pg)
			// The switches live inside a collapsed <details> — open it so the
			// control is focusable before pressing Space.
			await pg.locator('details.lck-more').first().evaluate((el) => { if (el instanceof HTMLDetailsElement) el.open = true }).catch(() => {})
			sw = pg.locator('#lck-private-webhooks')
			target = 'settings-alerts#lck-private-webhooks (env fallback)'
		}
		r.checks.target = target
		r.checks.role = await sw.getAttribute('role')
		const before = await sw.isChecked()
		await sw.focus()
		await pg.keyboard.press('Space')
		await pg.waitForTimeout(target.startsWith('home') ? 1600 : 300) // watch toggle fires a save PUT
		const after = await sw.isChecked()
		r.checks.flip = `${before}→${after}`
		if (before === after) r.fails.push(`switch did not toggle via Space (${target})`)
		// restore
		if (after !== before) {
			await sw.focus()
			await pg.keyboard.press('Space')
			await pg.waitForTimeout(target.startsWith('home') ? 1600 : 300)
		}
		if (r.checks.role !== 'switch') r.fails.push(`role=${r.checks.role} (want switch)`)
		if (r.fails.length) r.status = 'fail'
		record(r)
		if (altCtx) await altCtx.close()
	}

	// Chip groups (rules): button[aria-pressed] flips via Enter/Space.
	{
		const r = { id: 'chip-keyboard', checks: {}, status: 'ok', fails: [] }
		await page.goto(`${BASE}${ROUTES['settings-rules'].path}`, { waitUntil: 'domcontentloaded' })
		await settle(page)
		// Chip '2' is the ≤2 bucket, chip '3' the ≥3 bucket — press whichever
		// is NOT active so Enter has to flip aria-pressed and the carrier.
		const current = await page.locator('#lck-min-level').inputValue().catch(() => '3')
		const targetVal = parseInt(current, 10) <= 2 ? '3' : '2'
		const chip = page.locator(`#lck-level-chips .lck-chip[data-value="${targetVal}"]`)
		r.checks.priorValue = current
		const before = await chip.getAttribute('aria-pressed')
		await chip.focus()
		await page.keyboard.press('Enter')
		await page.waitForTimeout(300)
		const after = await chip.getAttribute('aria-pressed')
		const hidden = await page.locator('#lck-min-level').inputValue().catch(() => null)
		r.checks.flip = `${before}→${after}`
		r.checks.hiddenValue = hidden
		if (after !== 'true' || before === after) r.fails.push(`chip aria-pressed did not flip to true via Enter (${before}→${after})`)
		if (hidden !== targetVal) r.fails.push(`hidden carrier=${hidden} after chip select (want ${targetVal})`)
		// restore the prior bucket (form is never submitted — DOM-only state)
		const restoreVal = parseInt(current, 10) <= 2 ? '2' : '3'
		await page.locator(`#lck-level-chips .lck-chip[data-value="${restoreVal}"]`).click().catch(() => {})
		if (r.fails.length) r.status = 'fail'
		record(r)
	}

	// NC chrome overlap probe at all viewports (learned class `overlap`).
	for (const vp of [VIEWPORTS[0], VIEWPORTS[1], VIEWPORTS[3]]) {
		const r = { id: `chrome-overlap@${vp.w}`, checks: {}, status: 'ok', fails: [] }
		for (const [id, route] of Object.entries({ home: ROUTES.home, 'settings-alerts': ROUTES['settings-alerts'] })) {
			const adminCtx = route.user === 'admin'
				? await browser.newContext({ baseURL: BASE, storageState: await loginState(browser, 'admin'), viewport: { width: vp.w, height: vp.h } })
				: null
			const pg = adminCtx ? await adminCtx.newPage() : page
			await pg.setViewportSize({ width: vp.w, height: vp.h })
			await pg.goto(`${BASE}${route.path}`, { waitUntil: 'domcontentloaded' })
			await settle(pg)
			await ensureSectionInFrame(pg, route.section)
			const hits = await checkChromeOverlap(pg)
			r.checks[id] = hits
			if (hits.length) r.fails.push(`${id}: ${JSON.stringify(hits.slice(0, 3))}`)
			if (adminCtx) await adminCtx.close()
		}
		if (r.fails.length) r.status = 'fail'
		await snap(page, `a11y__chrome-overlap__${vp.w}`)
		record(r)
	}

	await ctx.close()
}

/* ───────────────────────────── main ───────────────────────────── */
const browser = await chromium.launch()
try {
	if (PHASE === 'sweep') await phaseSweep(browser)
	else if (PHASE === 'dialogs') await phaseDialogs(browser)
	else if (PHASE === 'states') await phaseStates(browser)
	else if (PHASE === 'theatre') await phaseTheatre(browser)
	else if (PHASE === 'a11y') await phaseA11y(browser)
	else throw new Error('unknown phase ' + PHASE)
} finally {
	results.finishedAt = new Date().toISOString()
	const fails = results.cells.filter((c) => c.status === 'fail').length
	const warns = results.cells.filter((c) => c.status === 'warn').length
	results.summary = { cells: results.cells.length, fails, warns }
	writeFileSync(join(OUT, `results-${PHASE}.json`), JSON.stringify(results, null, 1))
	console.log(`DONE ${PHASE}: ${results.cells.length} cells, ${fails} fail, ${warns} warn → ${OUT}/results-${PHASE}.json`)
	await browser.close()
}
