// @ts-check
/** Shared Playwright helpers for LogCheck e2e (pattern from homecheck/snackcheck). */
const AxeBuilder = require('@axe-core/playwright').default;

/**
 * @param {import('@playwright/test').Page} page
 */
async function login(page) {
	const base = (process.env.LOGCHECK_BASE_URL || process.env.E2E_BASE || 'http://localhost:8081').replace(/\/$/, '');
	const user = process.env.E2E_USER || process.env.LOGCHECK_E2E_USER;
	const pass = process.env.E2E_PASS || process.env.E2E_PASSWORD || process.env.LOGCHECK_E2E_PASS;
	if (!user || !pass) {
		return false;
	}

	await page.goto(base + '/index.php/apps/logcheck/', { waitUntil: 'domcontentloaded' });
	if (await page.locator('#lck-main-content, .lck-home, #lck-turn-on-form').first().isVisible().catch(() => false)) {
		return true;
	}

	for (let attempt = 0; attempt < 3; attempt++) {
		await page.goto(base + '/login', { waitUntil: 'domcontentloaded' });
		const userInput = page.locator('#user, input[name="user"]').first();
		try {
			await userInput.waitFor({ state: 'visible', timeout: 45000 });
		} catch (err) {
			if (attempt === 2) {
				throw err;
			}
			continue;
		}
		await userInput.fill(user);
		await page.locator('#password, input[name="password"]').first().fill(pass);
		await page.locator('button[type="submit"], input[type="submit"], button.login-button').first().click();
		try {
			await page.waitForURL(/apps\/|index\.php\/apps/, { timeout: 45000 });
			return true;
		} catch (err) {
			if (attempt === 2) {
				return false;
			}
		}
	}
	return false;
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} path
 */
async function gotoLogCheck(page, path = '/') {
	const base = (process.env.LOGCHECK_BASE_URL || process.env.E2E_BASE || 'http://localhost:8081').replace(/\/$/, '');
	const suffix = path.startsWith('/') ? path : '/' + path;
	await page.goto(base + '/index.php/apps/logcheck' + (suffix === '/' ? '/' : suffix), {
		waitUntil: 'domcontentloaded',
	});
	await page.locator('#lck-main-content, .lck-home, .lck-form, .lck-logs').first().waitFor({ state: 'visible', timeout: 20000 });
}

/**
 * @param {import('@playwright/test').Page} page
 */
async function openLogCheck(page) {
	await gotoLogCheck(page, '/');
}

/**
 * @param {import('@playwright/test').Page} page
 */
async function axeSeriousZero(page) {
	const builder = new AxeBuilder({ page })
		.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']);
	if (await page.locator('#app-content').count()) {
		builder.include('#app-content');
	} else if (await page.locator('#content').count()) {
		builder.include('#content');
	}
	const results = await builder.analyze();
	const bad = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
	if (bad.length > 0) {
		const summary = bad.map((v) => `${v.id}: ${v.help}`).join('\n');
		throw new Error('axe serious/critical:\n' + summary);
	}
}

module.exports = { login, openLogCheck, gotoLogCheck, axeSeriousZero };
