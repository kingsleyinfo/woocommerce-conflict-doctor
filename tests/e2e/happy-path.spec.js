/**
 * Happy path: wizard identifies a culprit plugin.
 *
 * Drives the full state machine:
 *   symptom → mode → theme → confirm → waiting → "Works now" → culprit.
 *
 * With 2 non-allowlisted plugins active (akismet, classic-editor) and
 * "Works now" on the first round, binary search narrows to a single plugin
 * immediately: floor(2/2)=1 → array_slice(candidates, 0, 1) = one plugin.
 */
const { test, expect } = require( '@playwright/test' );
const { loginAsAdmin, gotoWizard, resetWcdState } = require( './helpers' );

test.beforeEach( () => {
	resetWcdState();
} );

test( 'full flow identifies a culprit after one round', async ( { page } ) => {
	await loginAsAdmin( page );
	await gotoWizard( page );

	// Step 1: symptom
	await expect( page.locator( '#wcd-wizard-app h2' ) ).toBeVisible();
	await page.selectOption( 'select[data-field="symptom"]', 'checkout' );
	await page.click( 'button[data-action="symptom:next"]' );

	// Step 2: mode — pick "test everything" then continue. Neither radio is
	// pre-selected (post-fix), so Continue is disabled until a mode is chosen.
	await page.waitForSelector( 'input[name="wcd-mode"][value="full"]' );
	await page.check( 'input[name="wcd-mode"][value="full"]' );
	await page.click( 'button[data-action="mode:next"]' );

	// Step 3: theme — pick the first safe theme.
	await page.waitForSelector( 'input[name="wcd-theme"]' );
	await page.locator( 'input[name="wcd-theme"]' ).first().check();
	await page.click( 'button[data-action="theme:next"]' );

	// Step 4: confirm (no cache plugin in wp-env — goes straight to confirm).
	await page.waitForSelector( 'button[data-action="confirm:go"]' );
	await page.click( 'button[data-action="confirm:go"]' );

	// Step 5: waiting — round buttons should appear.
	await page.waitForSelector( 'button[data-action="round:fixed"]', { timeout: 15_000 } );
	await page.click( 'button[data-action="round:fixed"]' );

	// Step 6: culprit screen.
	await expect( page.locator( '.wcd-result-culprit' ) ).toBeVisible( { timeout: 15_000 } );
	await expect( page.locator( '.wcd-culprit-name' ) ).not.toBeEmpty();
	// Diagnosis textarea is populated for copy.
	await expect( page.locator( '.wcd-diagnosis' ) ).toBeVisible();

	// Done resets the wizard and returns to the symptom start screen with
	// clean state (no pre-selected symptom).
	await page.click( 'button[data-action="culprit:done"]' );
	await expect( page.locator( 'select[data-field="symptom"]' ) ).toBeVisible();
	await expect( page.locator( 'select[data-field="symptom"]' ) ).toHaveValue( '' );
	await expect( page.locator( 'button[data-action="symptom:next"]' ) ).toBeDisabled();
} );
