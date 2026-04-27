/**
 * "Done" resets the wizard.
 *
 * After completing a run and clicking Done on the culprit screen, the wizard
 * must return to the symptom start screen with *clean* state — no pre-selected
 * symptom, no stale suspects, no leftover session in localStorage. Starting a
 * second run should look indistinguishable from the very first one.
 *
 * Guards against a regression of the original bug: Done left state.symptom,
 * state.suspects, etc. populated and showed a "restored" terminal screen the
 * merchant then had to dismiss before re-running.
 */
const { test, expect } = require( '@playwright/test' );
const { loginAsAdmin, gotoWizard, resetWcdState } = require( './helpers' );

test.beforeEach( () => {
	resetWcdState();
} );

test( 'Done returns to symptom screen with clean state, second run starts fresh', async ( { page } ) => {
	await loginAsAdmin( page );
	await gotoWizard( page );

	// --- First run: drive to culprit and click Done. ---
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

	await page.waitForSelector( 'button[data-action="round:fixed"]', { timeout: 15_000 } );
	await page.click( 'button[data-action="round:fixed"]' );

	await expect( page.locator( '.wcd-result-culprit' ) ).toBeVisible( { timeout: 15_000 } );
	await page.click( 'button[data-action="culprit:done"]' );

	// --- Reset assertions: symptom screen, empty selection, disabled CTA. ---
	await expect( page.locator( 'select[data-field="symptom"]' ) ).toBeVisible();
	await expect( page.locator( 'select[data-field="symptom"]' ) ).toHaveValue( '' );
	await expect( page.locator( 'button[data-action="symptom:next"]' ) ).toBeDisabled();

	// localStorage cleared (no resume token left behind).
	const stored = await page.evaluate( () => window.localStorage.getItem( 'wcd_device_token_v1' ) );
	expect( stored ).toBeNull();

	// --- Second run: pick a different symptom, confirm mode screen has no
	// stale suspects from the first run. ---
	await page.selectOption( 'select[data-field="symptom"]', 'emails' );
	await page.click( 'button[data-action="symptom:next"]' );

	await page.waitForSelector( 'input[name="wcd-mode"][value="full"]' );

	// Neither radio pre-selected (clean state, not inheriting "full" from run 1).
	await expect( page.locator( 'input[name="wcd-mode"][value="full"]' ) ).not.toBeChecked();
	await expect( page.locator( 'input[name="wcd-mode"][value="focused"]' ) ).not.toBeChecked();
	await expect( page.locator( 'button[data-action="mode:next"]' ) ).toBeDisabled();

	// No suspect checkboxes are pre-checked from the previous run.
	const checkedSuspects = await page.locator( 'input[data-suspect]:checked' ).count();
	expect( checkedSuspects ).toBe( 0 );
} );
