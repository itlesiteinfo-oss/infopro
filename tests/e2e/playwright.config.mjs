import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
	testDir: '.',
	testMatch: /.*\.spec\.mjs/,
	timeout: 90000,
	expect: { timeout: 10000 },
	workers: 1,
	retries: 0,
	reporter: [ [ 'list' ] ],
	outputDir: './test-results',
	use: {
		baseURL: 'http://127.0.0.1:8080',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	projects: [ { name: 'chromium', use: { ...devices[ 'Desktop Chrome' ] } } ],
});
