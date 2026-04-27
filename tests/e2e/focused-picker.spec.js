/**
 * Mode screen: "specific plugin" radio reveals the plugin picker and gates
 * the Continue button until a plugin is chosen. "Test everything" hides the
 * picker and enables Continue immediately.
 *
 * Guards against a regression of the original bug: clicking the focused
 * radio did nothing visible and Continue stayed in an ambiguous state.
 */
const { test, expect } = require( '@playwright/test' );
const { loginAsAdmin, gotoWizard, resetWcdState } = require( './helpers' );

test.beforeEach( () => {
	resetWcdState();
} );

test( 'focused mode reveals picker and gates Continue; full mode hides it', async ( { page } ) => {
	await loginAsAdmin( page );
	await gotoWizard( page );

	await page.selectOption( 'select[data-field="symptom"]', 'checkout' );
	await page.click( 'button[data-action="symptom:next"]' );

	// Wait for mode step. Neither radio is pre-selected, so Continue starts disabled
	// and the plugin list is hidden.
	await page.waitForSelector( 'input[name="wcd-mode"][value="focused"]' );
	await expect( page.locator( 'button[data-action="mode:next"]' ) ).toBeDisabled();
	await expect( page.locator( '.wcd-plugin-list' ) ).toHaveCount( 0 );

	// Click "focused" → picker becomes visible; Continue still disabled until
	// a suspect is picked.
	await page.check( 'input[name="wcd-mode"][value="focused"]' );
	await expect( page.locator( '.wcd-plugin-list' ) ).toBeVisible();
	await expect( page.locator( 'button[data-action="mode:next"]' ) ).toBeDisabled();

	// Pick one plugin → Continue enables.
	await page.locator( 'input[data-suspect]' ).first().check();
	await expect( page.locator( 'button[data-action="mode:next"]' ) ).toBeEnabled();

	// Switch to "full" → picker hides, Continue enabled.
	await page.check( 'input[name="wcd-mode"][value="full"]' );
	await expect( page.locator( '.wcd-plugin-list' ) ).toHaveCount( 0 );
	await expect( page.locator( 'button[data-action="mode:next"]' ) ).toBeEnabled();
} );

test( 'focused radio label reads as a statement, not a duplicate of the step header', async ( { page } ) => {
	await loginAsAdmin( page );
	await gotoWizard( page );

	await page.selectOption( 'select[data-field="symptom"]', 'checkout' );
	await page.click( 'button[data-action="symptom:next"]' );

	await page.waitForSelector( 'input[name="wcd-mode"][value="focused"]' );

	// The <label> wrapping the focused radio should read as a statement
	// ("I suspect...") rather than the original question form.
	const focusedLabel = page.locator( 'label:has(input[name="wcd-mode"][value="focused"])' );
	await expect( focusedLabel ).toContainText( /I suspect/i );
	await expect( focusedLabel ).not.toContainText( /Do you have a specific plugin/i );
} );
