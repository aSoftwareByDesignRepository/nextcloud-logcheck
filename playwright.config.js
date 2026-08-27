// @ts-check
const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
	testDir: './e2e',
	timeout: 90000,
	retries: 1,
	workers: 1,
	use: {
		baseURL: process.env.LOGCHECK_BASE_URL || process.env.E2E_BASE || 'http://localhost:8081',
		trace: 'on-first-retry',
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices['Desktop Chrome'] },
		},
	],
});
