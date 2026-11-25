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
| `KZCR_JIRA_STANDIN` | unset | Set to `1` only when the wiki's Jira Service Desk settings point at a local stand-in. The test-key submission is then expected to succeed. |

## What it covers — and the Turnstile caveat

1. **Front-end wiring** — the button opens the OOUI dialog with the request +
   contact fields and a submit action. Runs anywhere.

2. **The Cloudflare Turnstile gate.** What the spec asserts depends on the
   sitekey the wiki serves:

   - **Cloudflare's always-pass test sitekey** (what local dev ships): the
     widget issues the fixed dummy token `XXXX.DUMMY.TOKEN.XXXX` to any
     browser, automated or not. The spec asserts that the token is issued,
     that the form posts it, and that the server accepts it. The extension
     accepts the test secret only where `MW_ENVIRONMENT` is `development`, so
     the same keys anywhere else fail this spec on purpose. After the
     captcha the request needs Jira:
     - by default dev has no Jira configured, so the API must answer
       `kzchangerequest-submission-error`, the form must show its error and
       nothing is sent anywhere;
     - with `KZCR_JIRA_STANDIN=1` the operator declares that the wiki's Jira
       points at a local stand-in (for example a temporary dev-only
       `conf.d/main` override of `$wgKZChangeRequestJiraServiceDeskApi`), and
       the submission must succeed and show the confirmation.

     **Never run this spec against a wiki whose Jira is the real one.** On a
     development wiki with the test keys, every run sends a ticket to whatever
     Jira that wiki is configured for.

   - **A real sitekey** (staging / prod): a headless/automated browser cannot
     pass Cloudflare's bot attestation, so no token is minted and the form
     refuses the submission before it calls the API. This is asserted, not
     worked around: it is the captcha doing its job. So there is no
     fully-automated happy-path submission against a real key. A real end to
     end run (solve, then a Jira ticket) needs a human to solve the challenge.

   - **No sitekey at all**: the widget never loads, and the spec skips (it
     never silently passes).
