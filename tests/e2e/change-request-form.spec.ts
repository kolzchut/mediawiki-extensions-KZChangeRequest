import { test, expect, type Locator, type Page, type Response } from '@playwright/test';

/**
 * KZChangeRequest — the "suggest a change" (הציעו שינוי) form.
 *
 * The form is anonymous and rendered on every content article, so these specs
 * land on Special:Random and need no login. They cover two things:
 *
 *  1. Front-end wiring — the button opens the OOUI dialog with the request +
 *     contact fields. Environment-independent.
 *
 *  2. The Cloudflare Turnstile gate, which behaves differently per key:
 *
 *     - Cloudflare's always-pass TEST sitekey (what local dev ships) issues
 *       the fixed dummy token to any browser. The spec asserts that token is
 *       issued, that the form posts it, and that the server accepts it (the
 *       extension accepts the test secret only where MW_ENVIRONMENT is
 *       development). What happens next depends on the wiki's Jira config:
 *       by default dev has none, so the request must stop there with
 *       kzchangerequest-submission-error. With KZCR_JIRA_STANDIN=1 the
 *       operator declares that the wiki's Jira settings point at a local
 *       stand-in, and the submission must succeed. Never point a wiki that
 *       runs this spec at a real Jira: every run would send it a ticket.
 *
 *     - A real sitekey (staging / prod): a headless/automated browser cannot
 *       pass Cloudflare's bot attestation, so no token is minted and the
 *       submission is gated. This is asserted, not worked around: it is the
 *       captcha doing its job.
 *
 *     With no sitekey at all the widget never loads, and that spec skips
 *     (never silently passes).
 *
 * Run (local dev):   npx playwright test
 * Run (staging):     MW_BASE_URL=https://staging-test.wikirights.org.il \
 *                      MW_SCRIPT_PATH=/he npx playwright test
 */

const SCRIPT_PATH = process.env.MW_SCRIPT_PATH || '/he';

/** The token Cloudflare's always-pass test sitekey issues to every visitor. */
const TURNSTILE_TEST_TOKEN = 'XXXX.DUMMY.TOKEN.XXXX';

/** Set to 1 when the wiki's Jira Service Desk settings point at a stand-in. */
const JIRA_STANDIN = process.env.KZCR_JIRA_STANDIN === '1';

// Poll until the check returns true or the time runs out; returns whether it
// became true in time.
async function waitUntil(
	check: () => Promise<boolean> | boolean,
	timeoutMs: number,
): Promise<boolean> {
	const deadline = Date.now() + timeoutMs;
	while ( Date.now() < deadline ) {
		if ( await check() ) {
			return true;
		}
		await new Promise( ( resolve ) => {
			setTimeout( resolve, 250 );
		} );
	}
	return check();
}

// The Turnstile token the widget wrote into the dialog, or '' if none.
async function readToken( dialog: Locator ): Promise<string> {
	return dialog
		.locator( '[name="cf-turnstile-response"]' )
		.inputValue( { timeout: 1000 } )
		.catch( () => '' );
}

// Click the dialog's submit action once the form has enabled it (it stays
// disabled until the request field holds valid text).
async function clickSubmit( page: Page ): Promise<void> {
	const submit = page.locator( '.kzchangerequest-submit' );
	await expect( submit, 'the submit action is enabled' ).not.toHaveClass( /oo-ui-widget-disabled/ );
	await submit.locator( '.oo-ui-buttonElement-button' ).click();
}

// Whether a response answers the form's kzchangerequest API POST.
function isChangeRequestCall( response: Response ): boolean {
	const request = response.request();
	return request.method() === 'POST' &&
		new URL( response.url() ).pathname.endsWith( '/api.php' ) &&
		new URLSearchParams( request.postData() || '' ).get( 'action' ) === 'kzchangerequest';
}

async function openDialog( page: Page ): Promise<Locator> {
	const button = page.locator( '.changerequest-btn' );
	await expect( button, 'the "suggest a change" button should render on a content article' ).toBeVisible();
	// The button is in the server-rendered HTML, but its click handler arrives
	// with a ResourceLoader module. A click before then does nothing, which
	// happens on a cold cache, so wait for the module to bind the button.
	await expect( button ).toHaveAttribute( 'data-kz-change-request-bound', '1' );
	await button.click();
	const dialog = page.locator( '.kzchangerequest-dialog' );
	await expect( dialog ).toBeVisible();
	return dialog;
}

test.describe( 'KZChangeRequest change-request form', () => {
	// Collect uncaught page errors so a broken form module is caught, but only
	// fail on errors from this extension — content articles carry unrelated
	// gadgets (TTS, ranking, third-party captcha) whose noise is not under test.
	let ownErrors: string[];

	test.beforeEach( async ( { page } ) => {
		ownErrors = [];
		page.on( 'pageerror', ( err ) => {
			const message = String( err );
			if ( /kzchangerequest/i.test( message ) ) {
				ownErrors.push( message );
			}
		} );
		// A random content article always carries the change-request button.
		await page.goto( `${ SCRIPT_PATH }/Special:Random`, { waitUntil: 'domcontentloaded' } );
	} );

	test( 'button opens a dialog with request + contact fields', async ( { page } ) => {
		const dialog = await openDialog( page );

		// Required free-text request field.
		await expect( dialog.locator( 'textarea' ) ).toBeVisible();

		// Two contact inputs (name + email).
		const contactInputs = dialog.locator( 'input[type="text"], input[type="email"], input:not([type])' );
		expect( await contactInputs.count(), 'name + email contact inputs' ).toBeGreaterThanOrEqual( 2 );

		expect( ownErrors, `KZChangeRequest page errors: ${ ownErrors.join( '; ' ) }` ).toHaveLength( 0 );
	} );

	test( 'Turnstile gates submission: the test key passes through, a real key stops an automated browser', async ( { page } ) => {
		let contactedTurnstile = false;
		page.on( 'request', ( req ) => {
			if ( req.url().includes( 'challenges.cloudflare.com' ) ) {
				contactedTurnstile = true;
			}
		} );

		const dialog = await openDialog( page );

		// The Turnstile mount point is always present in the dialog markup.
		await expect( dialog.locator( '.kzcr-turnstile' ) ).toBeAttached();

		// No sitekey configured for this environment → the widget never loads and
		// there is nothing to assert about the gate. Skip rather than silently pass.
		await waitUntil( () => contactedTurnstile, 6000 );
		test.skip( !contactedTurnstile, 'No Turnstile sitekey configured for this environment.' );

		// Fill the required field so form-validity is not what blocks submission.
		await dialog.locator( 'textarea' ).fill( 'e2e wiring check — please ignore' );

		// The test key mints its dummy token within a second or two. A real key
		// never mints one for an automated browser, so this wait runs out there.
		await waitUntil( async () => ( await readToken( dialog ) ) !== '', 10000 );
		const minted = await readToken( dialog );

		if ( minted !== TURNSTILE_TEST_TOKEN ) {
			// A real sitekey: an automated browser cannot solve Cloudflare's bot
			// attestation, so no token is minted…
			expect( minted, 'Turnstile must not issue a real token to an automated browser' ).toBe( '' );

			// …so a submit attempt is refused in the browser and never reaches the
			// success/confirmation panel (which is pre-rendered in the DOM but stays
			// hidden until a real submission).
			await clickSubmit( page );
			await expect( dialog.locator( '.kzchangerequest-error' ) ).toBeVisible();
			await expect( page.locator( '.kzchangerequest-success' ) ).toBeHidden();
			return;
		}

		// Cloudflare's always-pass test key: the form must post the token, and the
		// server must accept it.
		test.info().annotations.push( {
			type: 'turnstile',
			description: `test sitekey; Jira ${ JIRA_STANDIN ? 'stand-in declared' : 'expected unconfigured' }`,
		} );
		const apiCall = page.waitForResponse( isChangeRequestCall );
		await clickSubmit( page );
		const response = await apiCall;

		const sent = new URLSearchParams( response.request().postData() || '' );
		expect( sent.get( 'cf-turnstile-response' ), 'the form posts the issued token' ).toBe( TURNSTILE_TEST_TOKEN );

		const result = await response.json();
		const errorCode = result.error ? result.error.code : undefined;
		expect( errorCode, 'the server accepts the test token on a development wiki' ).not.toBe( 'kzchangerequest-captcha-fail' );

		if ( JIRA_STANDIN ) {
			// The wiki's Jira points at a stand-in: the request goes all the way.
			expect( result, `the submission succeeds (error: ${ errorCode })` ).toMatchObject( { success: 1 } );
			await expect( page.locator( '.kzchangerequest-success' ) ).toBeVisible();
		} else {
			// No Jira configured (dev's default): past the captcha, the request stops
			// at the missing Jira settings and nothing is sent anywhere.
			expect(
				errorCode,
				'with no Jira configured, the request stops at Jira (set KZCR_JIRA_STANDIN=1 if this wiki points at a stand-in)',
			).toBe( 'kzchangerequest-submission-error' );
			await expect( dialog.locator( '.kzchangerequest-error' ) ).toBeVisible();
			await expect( page.locator( '.kzchangerequest-success' ) ).toBeHidden();
		}

		expect( ownErrors, `KZChangeRequest page errors: ${ ownErrors.join( '; ' ) }` ).toHaveLength( 0 );
	} );
} );
