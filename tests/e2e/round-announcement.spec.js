/**
 * Round-summary announcement: after the merchant answers "Works now" or
 * "Still broken", the next waiting screen explains what changed (which
 * plugins were re-enabled, which remain off) *before* they click "Try again".
 *
 * Uses a network intercept to force a narrowing response so we don't need
 * a 3+ plugin fixture for binary search to continue past round 1.
 */
const { test, expect } = require( '@playwright/test' );
const { loginAsAdmin, gotoWizard, resetWcdState } = require( './helpers' );

test.beforeEach( () => {
	resetWcdState();
} );

test( 'narrowing round shows a summary of re-enabled/disabled plugins', async ( { page } ) => {
	await loginAsAdmin( page );
	await gotoWizard( page );

	// Full start-test flow with real backend.
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

	// First waiting screen — no summary yet (nothing has happened).
	await page.waitForSelector( 'button[data-action="round:fixed"]', { timeout: 15_000 } );
	await expect( page.locator( '.wcd-round-summary' ) ).toHaveCount( 0 );

	// Intercept the next round request to force a narrowing response.
	await page.route( '**/admin-ajax.php', async ( route, request ) => {
		const body = request.postData() || '';
		if ( body.includes( 'action=wcd_round_result' ) ) {
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					success: true,
					data: {
						status: 'narrowing',
						session: {
							disabled: [ 'foo/foo.php' ],
							candidates: [ 'foo/foo.php', 'bar/bar.php' ],
							phase: 'full',
							symptom: 'checkout',
							theme: '',
							expires_at: Math.floor( Date.now() / 1000 ) + 3600,
						},
						not_sure: false,
						last_answer: 'fixed',
						reenabled_names: [ 'Widget Cleaner', 'Extra Boost' ],
						disabled_names: [ 'Mystery Plugin' ],
					},
				} ),
			} );
			return;
		}
		await route.continue();
	} );

	await page.click( 'button[data-action="round:fixed"]' );

	// Second waiting screen — summary must be visible with the names from the
	// mocked response, before the merchant clicks "Try again".
	await page.waitForSelector( '.wcd-round-summary', { timeout: 10_000 } );
	const summary = page.locator( '.wcd-round-summary' );
	await expect( summary ).toContainText( /worked/i );
	await expect( summary ).toContainText( /Widget Cleaner/ );
	await expect( summary ).toContainText( /Extra Boost/ );
	await expect( summary ).toContainText( /Mystery Plugin/ );

	// "Try it now" button is still the path forward — summary renders above it.
	await expect( page.locator( '[data-action="waiting:tryit"]' ) ).toBeVisible();
} );
