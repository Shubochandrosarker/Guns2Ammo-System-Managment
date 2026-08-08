/**
 * Playwright E2E scaffolding for g2a-booking-engine.
 *
 * These specs run against a LIVE WordPress site — they are documentation-grade
 * encodings of the required booking/checkout flows, not fabricated results.
 *
 * Required environment:
 *   WP_BASE_URL           Base URL of the WordPress site under test
 *                         (e.g. https://staging.example.com). When missing,
 *                         every spec self-skips so CI stays green.
 *
 * Optional environment (individual specs skip without them):
 *   G2AB_BOOKING_PAGE     Path of the page holding the lane booking shortcode
 *                         (default: /book-a-lane/)
 *   G2AB_EVENTS_PAGE      Path of the events page (default: /events/)
 *   G2AB_MEMBER_USER      WP username of an ELIGIBLE member (defender/patriot/
 *                         guardian, active) — used for the $0 member flow
 *   G2AB_MEMBER_PASS      Password for G2AB_MEMBER_USER
 *   G2AB_EXPIRED_PAY_URL  A previously issued ?g2ab_action=pay&g2ab_at=…
 *                         email link whose Stripe session/hold has expired —
 *                         used for the resume-payment flow
 *
 * Run:
 *   npm install @playwright/test && npx playwright install chromium
 *   WP_BASE_URL=https://staging.example.com npx playwright test
 */
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: '.',
  timeout: 60_000,
  expect: { timeout: 10_000 },
  fullyParallel: false, // booking flows mutate shared inventory — run serially.
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL: process.env.WP_BASE_URL || 'http://localhost.invalid',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
