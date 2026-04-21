/**
 * Playwright config for WooCommerce Conflict Doctor E2E tests.
 *
 * Runs against the wp-env instance at localhost:8888.
 * Default wp-env admin credentials: admin / password.
 */
const { defineConfig, devices } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: './tests/e2e',
	timeout: 60_000,
	expect: { timeout: 10_000 },
	fullyParallel: false, // tests share the same WP install — run serially
	workers: 1,
	reporter: [ [ 'list' ] ],
	use: {
		baseURL: 'http://localhost:8888',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
} );
