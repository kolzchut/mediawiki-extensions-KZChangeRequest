# KZChangeRequest e2e tests

Playwright suite for the change-request ("suggest a change" / הציעו שינוי) form.

The form is anonymous and appears on every content article, so the specs land
on `Special:Random` and need no login.

## Run

```sh
npm install --ignore-scripts
npx playwright install chromium   # first time only

# Local dev (main site):
npx playwright test

# Staging:
MW_BASE_URL=https://staging-test.wikirights.org.il MW_SCRIPT_PATH=/he \
  npx playwright test
```

The platform runner `kz-infrastructure/tests/run-extension-e2e.sh` discovers and
runs this suite automatically (it lives next to `playwright.config.ts`).

### Environment

| Var | Default | Meaning |
|-----|---------|---------|
| `MW_BASE_URL` | `http://localhost:8082` | Wiki base URL |
| `MW_SCRIPT_PATH` | `/he` | Article-path prefix (language root) |

## What it covers — and the Turnstile caveat

1. **Front-end wiring** — the button opens the OOUI dialog with the request +
   contact fields and a submit action. Runs anywhere.

2. **The Cloudflare Turnstile gate.** Where a sitekey is configured
   (staging / prod) the widget bootstraps, but a **headless/automated browser
   cannot pass Cloudflare's bot attestation** — the Private Access Token /
   challenge flow fails and no token is minted, so the submission is gated.
   This is asserted, not worked around: it is the captcha doing its job.
   Consequently **there is no fully-automated happy-path submission** — a real
   end-to-end (solve → Jira ticket) needs a human solving the challenge, or the
   Jira half exercised server-side. In local dev, where no sitekey is set, this
   spec skips cleanly.
