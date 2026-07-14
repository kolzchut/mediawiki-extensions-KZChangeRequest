import { test, expect, type Page } from '@playwright/test';

/**
 * KZChangeRequest — the "suggest a change" (הציעו שינוי) form.
 *
 * The form is anonymous and rendered on every content article, so these specs
 * land on Special:Random and need no login. They cover two things:
 *
 *  1. Front-end wiring — the button opens the OOUI dialog with the request +
 *     contact fields. Environment-independent.
 *
 *  2. The Cloudflare Turnstile gate. Where a sitekey is configured (staging /
 *     prod) the widget bootstraps, but a headless/automated browser cannot
 *     pass Cloudflare's bot attestation — the Private Access Token / challenge
 *     flow fails and NO token is minted. Combined with the server's
 *     fail-closed check, the submission is gated. This is asserted, not worked
 *     around: it is the captcha doing its job, and it is why there is no
 *     fully-automated happy-path submission. In local dev, where no sitekey is
 *     set, that spec skips cleanly (never silently passes).
 *
 * Run (local dev):   npx playwright test
 * Run (staging):     MW_BASE_URL=https://staging-test.wikirights.org.il \
 *                      MW_SCRIPT_PATH=/he npx playwright test
 */

const SCRIPT_PATH = process.env.MW_SCRIPT_PATH || '/he';

async function openDialog(page: Page) {
  const button = page.locator('.changerequest-btn');
  await expect(button, 'the "suggest a change" button should render on a content article').toBeVisible();
  await button.click();
  const dialog = page.locator('.kzchangerequest-dialog');
  await expect(dialog).toBeVisible();
  return dialog;
}

test.describe('KZChangeRequest change-request form', () => {
  // Collect uncaught page errors so a broken form module is caught, but only
  // fail on errors from this extension — content articles carry unrelated
  // gadgets (TTS, ranking, third-party captcha) whose noise is not under test.
  let ownErrors: string[];

  test.beforeEach(async ({ page }) => {
    ownErrors = [];
    page.on('pageerror', (err) => {
      const message = String(err);
      if (/kzchangerequest/i.test(message)) ownErrors.push(message);
    });
    // A random content article always carries the change-request button.
    await page.goto(`${SCRIPT_PATH}/Special:Random`, { waitUntil: 'domcontentloaded' });
  });

  test('button opens a dialog with request + contact fields', async ({ page }) => {
    const dialog = await openDialog(page);

    // Required free-text request field.
    await expect(dialog.locator('textarea')).toBeVisible();

    // Two contact inputs (name + email).
    const contactInputs = dialog.locator('input[type="text"], input[type="email"], input:not([type])');
    expect(await contactInputs.count(), 'name + email contact inputs').toBeGreaterThanOrEqual(2);

    expect(ownErrors, `KZChangeRequest page errors: ${ownErrors.join('; ')}`).toHaveLength(0);
  });

  test('Turnstile is wired in and gates submission for an automated browser', async ({ page }) => {
    let contactedTurnstile = false;
    page.on('request', (req) => {
      if (req.url().includes('challenges.cloudflare.com')) contactedTurnstile = true;
    });

    const dialog = await openDialog(page);

    // The Turnstile mount point is always present in the dialog markup.
    await expect(dialog.locator('.kzcr-turnstile')).toBeAttached();

    // Give the widget a chance to bootstrap.
    await page.waitForTimeout(6000);

    // No sitekey configured for this environment (e.g. local dev) → nothing to
    // assert about the gate. Skip rather than silently pass.
    test.skip(!contactedTurnstile, 'No Turnstile sitekey configured for this environment (e.g. local dev).');

    // Fill the required field so form-validity is not what blocks submission.
    await dialog.locator('textarea').fill('e2e wiring check — please ignore');

    // The widget bootstrapped, but an automated browser cannot solve Cloudflare's
    // bot attestation, so no token is minted…
    const token = await dialog
      .locator('[name="cf-turnstile-response"]')
      .inputValue()
      .catch(() => '');
    expect(token, 'Turnstile must not issue a token to an automated browser').toBe('');

    // …so a submit attempt never reaches the success/confirmation panel (which
    // is pre-rendered in the DOM but stays hidden until a real submission).
    await page.evaluate(() => {
      const primary = document.querySelector(
        '.oo-ui-processDialog-actions-primary a[role="button"], .oo-ui-processDialog-actions-primary button'
      ) as HTMLElement | null;
      if (primary && !primary.classList.contains('oo-ui-widget-disabled')) primary.click();
    });
    await page.waitForTimeout(3000);
    await expect(page.locator('.kzchangerequest-success')).toBeHidden();
  });
});
