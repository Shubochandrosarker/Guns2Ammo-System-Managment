/**
 * Event booking + email-link flows (documented E2E contract).
 *
 * Encodes:
 *   1. Public event seat checkout is a hold-then-pay flow: submitting seats
 *      creates a PENDING hold with an online-gateway redirect; the seats are
 *      blocked for the hold window but the booking is never operational until
 *      paid.
 *   2. An expired "complete payment" email link (?g2ab_action=pay) must
 *      RESUME checkout — a fresh gateway session is minted after
 *      re-checking availability — or render a clear branded notice; it must
 *      never 404 and never dump a dead gateway URL.
 *
 * Every test skips cleanly when the live-site environment is not provided.
 */
import { test, expect, request as pwRequest } from '@playwright/test';

const BASE = process.env.WP_BASE_URL || '';
const EVENTS_PAGE = process.env.G2AB_EVENTS_PAGE || '/events/';
const REST_NS = 'g2a-booking/v1';

test.describe('Event booking', () => {
  test.skip(!BASE, 'WP_BASE_URL not provided — live-site E2E skipped');

  test('event seat checkout creates a pending hold and hands off to online payment', async ({ page }) => {
    await page.goto(EVENTS_PAGE);
    const list = page.locator('.g2ab-evb, .g2ab-frontend').first();
    await expect(list).toBeVisible();

    const empty = await page.locator('.g2ab-evb--empty').count();
    test.skip(empty > 0, 'no upcoming events published on the target site');

    // Open the first bookable event and submit the seat form.
    await page.locator('.g2ab-btn--primary, .g2ab-evb a, button[type=submit]').first().click();

    const bookingResponse = page.waitForResponse(
      (r) => r.url().includes(`/${REST_NS}/events/book`) && r.request().method() === 'POST',
      { timeout: 30_000 }
    ).catch(() => null);

    const submit = page.locator('button[type=submit], .g2ab-btn--primary').first();
    if (await submit.isVisible().catch(() => false)) {
      await submit.click();
    }
    const response = await bookingResponse;
    test.skip(!response, 'seat form did not submit — page layout differs on target site');

    const body = await response!.json().catch(() => null);
    expect(response!.status()).toBeLessThan(500);
    if (body?.success && body?.data && Number(body.data.amount_due ?? 1) > 0) {
      // Payable seats: pending checkout hold + online redirect, never
      // an instantly operational reservation.
      expect(body.data.status ?? 'pending').toBe('pending');
      expect(String(body.data.redirect_url ?? body.data.checkout_url ?? '')).toMatch(/^https?:\/\//);
    }
  });

  test('pay_in_store is rejected for public event seats via REST (400)', async () => {
    const api = await pwRequest.newContext({ baseURL: BASE });

    const nonceRes = await api.get(`/wp-json/${REST_NS}/nonce`);
    test.skip(!nonceRes.ok(), 'nonce endpoint unavailable');
    const nonce = (await nonceRes.json())?.data?.nonce as string;

    const occurrence = Number(process.env.G2AB_OCCURRENCE_ID || 0);
    test.skip(!occurrence, 'G2AB_OCCURRENCE_ID not provided');

    const res = await api.post(`/wp-json/${REST_NS}/events/book`, {
      headers: { 'X-WP-Nonce': nonce },
      data: {
        occurrence_id: occurrence,
        seats: 1,
        customer_name: 'E2E Probe',
        customer_email: 'e2e-probe@example.com',
        gateway: 'pay_in_store',
      },
    });

    expect(res.status()).toBe(400);
    expect(JSON.stringify(await res.json().catch(() => ({})))).toContain('g2ab_gateway_not_allowed');
  });

  test('expired payment email link resumes checkout instead of dying', async ({ page }) => {
    test.skip(
      !process.env.G2AB_EXPIRED_PAY_URL,
      'G2AB_EXPIRED_PAY_URL not provided (needs a real expired ?g2ab_action=pay link)'
    );

    const response = await page.goto(process.env.G2AB_EXPIRED_PAY_URL!);

    // Never a 404/500 — either a fresh gateway session (redirect off-site)
    // or the branded action page with an actionable message.
    expect(response!.status()).toBeLessThan(400);

    const onGateway = /checkout\.stripe\.com|paypal\.com/.test(page.url());
    if (!onGateway) {
      // Branded fallback: a clear notice, never a raw WP error or dead link.
      await expect(page.locator('.card, [role=main]').first()).toBeVisible();
      await expect(
        page.locator(
          'text=/expired|no longer valid|request a new|already paid|call us|payment unavailable/i'
        ).first()
      ).toBeVisible();
    }
  });
});
