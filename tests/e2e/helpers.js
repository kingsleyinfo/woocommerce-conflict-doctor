/**
 * Shared helpers for WCD E2E tests.
 */
const { execSync } = require( 'child_process' );

const ADMIN_USER = 'admin';
const ADMIN_PASS = 'password';
const WIZARD_PATH = '/wp-admin/admin.php?page=wcd-wizard';

/** Log into WP admin. Leaves the browser at the dashboard. */
async function loginAsAdmin( page ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', ADMIN_USER );
	await page.fill( '#user_pass', ADMIN_PASS );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/, { timeout: 15_000 } );
}

/** Navigate to the wizard page and wait for React-free app to mount. */
async function gotoWizard( page ) {
	await page.goto( WIZARD_PATH );
	// Wait until the app root has rendered past the initial data-loading state.
	await page.waitForSelector( '#wcd-wizard-app:not([data-loading])', { timeout: 10_000 } );
}

/**
 * Clean test state between runs: clear any active session options and remove
 * the MU-plugin file left behind by a previous test. wp-env's bind mount
 * persists /var/www/html state across test runs, so this is essential.
 */
function resetWcdState() {
	const cmd = [
		// Delete any stray wcd_session_* options (SQL to match the wildcard).
		`npx wp-env run cli wp db query "DELETE FROM wp_options WHERE option_name LIKE 'wcd_session_%'"`,
		// Remove the MU-plugin loader so each test starts from a clean install.
		`npx wp-env run cli rm -f /var/www/html/wp-content/mu-plugins/wcd-loader.php`,
	].join( ' && ' );
	try {
		execSync( cmd, { stdio: 'pipe' } );
	} catch ( e ) {
		// Non-fatal — tests will surface real failures.
	}
}

module.exports = {
	loginAsAdmin,
	gotoWizard,
	resetWcdState,
	WIZARD_PATH,
};
