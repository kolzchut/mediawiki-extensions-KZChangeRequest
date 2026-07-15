import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright configuration for the KZChangeRequest change-request form e2e tests.
 *
 * Run:
 *   npx playwright test
 *   npx playwright test --headed
 *
 * Environment variables:
 *   MW_BASE_URL    - MediaWiki base URL (default: http://localhost:8082)
 *   MW_SCRIPT_PATH - article-path prefix, i.e. the wiki language root
 *                    (default: /he). The change-request form is anonymous, so
 *                    no MW_USERNAME / MW_PASSWORD are needed.
 *
 * The change-request button appears on content articles; the specs land on
 * Special:Random so they don't depend on any particular page existing.
 */
export default defineConfig({
  testDir: './e2e',
  testMatch: ['**/*.spec.ts'],
  timeout: 45000,
  expect: { timeout: 10000 },
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: 1,

  reporter: [
    ['html', { outputFolder: 'playwright-report', open: 'never' }],
    ['list'],
  ],

  use: {
    baseURL: process.env.MW_BASE_URL || 'http://localhost:8082',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },

  projects: [
    {
      name: 'kzcr-desktop',
      use: {
        ...devices['Desktop Chrome'],
        viewport: { width: 1440, height: 900 },
      },
    },
  ],
});
