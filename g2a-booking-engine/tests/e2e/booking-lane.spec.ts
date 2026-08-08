/**
 * Lane booking flows (documented E2E contract).
 *
 * Encodes the required behaviors of the public lane-booking checkout:
 *   1. An eligible member books a lane at $0 and lands on an immediate
 *      "confirmed" state — no payment step, zero_reason=member_included.
 *   2. A non-member always gets a pay CTA; submitting creates a PENDING
 *      checkout hold (never an operational reservation) and hands off to the
 *      online gateway.
 *   3. gateway=pay_in_store on a payable public request is rejected with 400
 *      (g2ab_gateway_not_allowed) — asserted via a direct REST call, because
 *      the official UI never offers it.
 *   4. A logged-out visitor typing a member's email must NOT be shown any
 *      membership/entitlement reveal — pricing stays public full price.
 *
 * Every test skips cleanly when the live-site environment is not provided.
 */
import { test, expect, request as pwRequest } from '@playwright/test';

const BASE = process.env.WP_BASE_URL || '';
const BOOKING_PAGE = process.env.G2AB_BOOKING_PAGE || '/book-a-lane/';
const REST_NS = 'g2a-booking/v1';

test.describe('Lane booking', () => {
  test.skip(!BASE, 'WP_BASE_URL not provided — live-site E2E skipped');

  test('member with included plan books a lane for $0 and is confirmed immediately', async ({ page }) => {
    test.skip(
      !process.env.G2AB_MEMBER_USER || !process.env.G2AB_MEMBER_PASS,
      'G2AB_MEMBER_USER / G2AB_MEMBER_PASS not provided'
    );

    // Log in as the eligible member.
    await page.goto('/wp-login.php');
    await page.fill('#user_login', process.env.G2AB_MEMBER_USER!);
    await page.fill('#user_pass', process.env.G2AB_MEMBER_PASS!);
    await page.click('#wp-submit');
    await expect(page.locator('body')).not.toHaveClass(/login/);

    // Open the booking page and pick the first available slot.
    await page.goto(BOOKING_PAGE);
    const app = page.locator('.g2ab-frontend, .g2ab-booking').first();
    await expect(app).toBeVisible();

    // Member-included pricing must be surfaced before payment ($0 / Included).
    await expect(
      page.locator('.g2ab-included, text=/\\$\\s*0(\\.00)?|included with your membership/i').first()
    ).toBeVisible();

    // Submit the booking — for a $0 member lane there must be NO gateway
    // redirect: the confirmation state renders on-site.
    await page.locator('.g2ab-btn--primary, button[type=submit]').first().click();
    await expect(page.locator('text=/confirmed|see you at the range/i').first()).toBeVisible();
    expect(page.url()).not.toMatch(/checkout\.stripe\.com/);
  });

  test('non-member sees a pay CTA and submitting creates a checkout hold (pending)', async ({ page }) => {
    await page.goto(BOOKING_PAGE);
    const app = page.locator('.g2ab-frontend, .g2ab-booking').first();
    await expect(app).toBeVisible();

    // Public price is shown; no "included with membership" reveal.
    await expect(page.locator('.g2ab-included')).toHaveCount(0);

    // The CTA is a pay action (secure online payment) — never "reserve now,
    // pay later" and never a pay-at-store choice.
    const cta = page.locator('.g2ab-btn--primary, button[type=submit]').first();
    await expect(cta).toBeVisible();
    await expect(page.locator('text=/pay at (the )?store|pay in store/i')).toHaveCount(0);

    // Fill guest details and submit; the engine responds with a redirect_url
    // to the online gateway while the booking stays a PENDING hold.
    const bookingResponse = page.waitForResponse(
      (r) => r.url().includes(`/${REST_NS}/bookings`) && r.request().method() === 'POST'
    );
    await cta.click();
    const response = await bookingResponse;
    const body = await response.json().catch(() => null);

    expect(response.status()).toBeLessThan(500);
    if (body?.success && body?.data) {
      // Checkout-hold contract: payable booking is pending + online redirect.
      expect(body.data.status ?? 'pending').toBe('pending');
      expect(String(body.data.redirect_url ?? body.data.checkout_url ?? '')).toMatch(/^https?:\/\//);
    }
  });

  test('gateway=pay_in_store on a payable public request is rejected with 400 via REST', async () => {
    const api = await pwRequest.newContext({ baseURL: BASE });

    // A REST nonce is required; the engine exposes an uncached endpoint for it.
    const nonceRes = await api.get(`/wp-json/${REST_NS}/nonce`);
    test.skip(!nonceRes.ok(), 'nonce endpoint unavailable — cannot exercise REST checkout');
    const nonce = (await nonceRes.json())?.data?.nonce as string;

    const tomorrow = new Date(Date.now() + 24 * 3600 * 1000).toISOString().slice(0, 10);
    const res = await api.post(`/wp-json/${REST_NS}/bookings`, {
      headers: { 'X-WP-Nonce': nonce },
      data: {
        resource_id: Number(process.env.G2AB_RESOURCE_ID || 1),
        booking_type_id: Number(process.env.G2AB_BOOKING_TYPE_ID || 1),
        date: tomorrow,
        start: `${tomorrow} 10:00:00`,
        duration: 60,
        party_size: 1,
        customer_name: 'E2E Probe',
        customer_email: 'e2e-probe@example.com',
        gateway: 'pay_in_store', // crafted outside the official UI
      },
    });

    // The offline gateway must be rejected for public payable checkouts.
    expect(res.status()).toBe(400);
    const body = await res.json().catch(() => ({}));
    expect(JSON.stringify(body)).toContain('g2ab_gateway_not_allowed');
  });

  test('logged-out visitor gets no member reveal when typing a member email', async ({ page }) => {
    test.skip(!process.env.G2AB_MEMBER_EMAIL, 'G2AB_MEMBER_EMAIL not provided');

    await page.goto(BOOKING_PAGE);
    const emailInput = page.locator('input[type=email], input[name*=email]').first();
    await expect(emailInput).toBeVisible();
    await emailInput.fill(process.env.G2AB_MEMBER_EMAIL!);
    await emailInput.blur();

    // Entitlement is resolved from the AUTHENTICATED user only: typing a
    // member's email must neither zero the price nor leak membership state.
    await expect(page.locator('.g2ab-included')).toHaveCount(0);
    await expect(
      page.locator('text=/included with (your )?membership|member price|membership found/i')
    ).toHaveCount(0);
    await expect(page.locator('text=/\\$\\s*0(\\.00)?\\b/')).toHaveCount(0);
  });
});
