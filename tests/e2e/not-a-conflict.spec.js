/**
 * Not-a-conflict path: merchant reports "Still broken" after all plugins
 * are disabled. Wizard terminates with the not-a-conflict result screen
 * and restores the site.
 *
 * Also asserts the allowlist-boundary diagnostic is shown (WooCommerce
 * + WCD itself are kept active during the test — that list appears on
 * the not-a-conflict screen for HE review).
 */
const { test, expect } = require( '@playwright/test' );
const { loginAsAdmin, gotoWizard, resetWcdState } = require( './helpers' );

test.beforeEach( () => {
	resetWcdState();
} );

test( 'still-broken on first round terminates as not-a-conflict', async ( { page } ) => {
	await loginAsAdmin( page );
	await gotoWizard( page );

	await page.selectOption( 'select[data-field="symptom"]', 'checkout' );
	await page.click( 'button[data-action="symptom:next"]' );

	await page.waitForSelector( 'input[name="wcd-mode"][value="full"]' );
	await page.check( 'input[name="wcd-mode"][value="full"]' );
	await page.click( 'button[data-action="mode:next"]' );

	await page.waitForSelector( 'input[name="wcd-theme"]' );
	await page.locator( 'input[name="wcd-theme"]' ).first().check();
	await page.click( 'button[data-action="theme:next"]' );

	await page.waitForSelector( 'button[data-action="confirm:go"]' );
	await page.click( 'button[data-action="confirm:go"]' );

	// Round 1: still broken — this should terminate immediately.
	await page.waitForSelector( 'button[data-action="round:broken"]', { timeout: 15_000 } );
	await page.click( 'button[data-action="round:broken"]' );

	// Not-a-conflict result.
	await expect( page.locator( '.wcd-result-not-conflict' ) ).toBeVisible( { timeout: 15_000 } );

	// Allowlist-boundary diagnostic: WooCommerce is allowlisted so it should
	// appear in the kept-active list for HE review.
	const body = page.locator( '.wcd-result-not-conflict' );
	await expect( body ).toContainText( /WooCommerce/i );

	// Done resets and returns to the symptom start screen.
	await page.click( 'button[data-action="done"]' );
	await expect( page.locator( 'select[data-field="symptom"]' ) ).toBeVisible();
	await expect( page.locator( 'select[data-field="symptom"]' ) ).toHaveValue( '' );
} );
