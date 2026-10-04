import { Browser, expect, Frame, Page, test } from '@playwright/test';

import { config } from '../playwright.config';

/**
 * Needs Qliro itself, so it is off unless asked for, and a store with **Show as payment method**
 * on and **Payment method display** set to the embedded iframe.
 *
 * Subscribing to `q1.onOrderUpdated` makes the widget poll the order and hold itself busy under
 * its own dimmer until the store calls `q1.unlock()`. The iframe is fetched when the buyer picks
 * Qliro, after the quote updates of the shipping step, so a subscription made on load left the
 * widget dimmed and polling for as long as the page was open (PLIN-419).
 *
 * ```
 * QLIRO_CHECKOUT_E2E=1 QLIRO_E2E_PRODUCT_URL=/some-simple-product npx playwright test checkout-iframe-unlock
 * ```
 */
const enabled = process.env.QLIRO_CHECKOUT_E2E === '1';
const productUrl = process.env.QLIRO_E2E_PRODUCT_URL ?? '/test-simple.html';
const email = process.env.QLIRO_E2E_EMAIL ?? 'qliro-e2e@example.com';
const postcode = process.env.QLIRO_E2E_POSTCODE ?? '111 22';

type OpenIframe = { page: Page; frame: Frame; orderPolls: () => number; challengeEmails: (string | null)[] };

async function openIframe(browser: Browser): Promise<OpenIframe> {
  const context = await browser.newContext({ baseURL: config.baseURL, ignoreHTTPSErrors: true });
  const page = await context.newPage();
  let polls = 0;
  const challengeEmails: (string | null)[] = [];

  page.on('request', request => {
    if (request.method() === 'GET' && /\/checkout\/webapi\/orders\?/.test(request.url())) {
      polls++;
    }

    if (/\/checkout\/webapi\/challenges\/completeChallenges/.test(request.url())) {
      challengeEmails.push(JSON.parse(request.postData() ?? '{}').email ?? null);
    }
  });

  await page.goto(productUrl);
  await page.click('#product-addtocart-button');
  await page.waitForSelector('.message-success');

  await page.goto('/checkout/');
  await page.waitForSelector('#customer-email');
  // Knockout binds the form a moment after it is visible, and a value typed before that is lost
  await page.waitForTimeout(3_000);

  await page.fill('#customer-email', email);
  await page.fill('input[name="firstname"]', 'Test');
  await page.fill('input[name="lastname"]', 'Person');
  await page.fill('input[name="street[0]"]', 'Testgatan 1');
  await page.fill('input[name="city"]', 'Stockholm');
  await page.fill('input[name="postcode"]', postcode);
  await page.fill('input[name="telephone"]', '0701234567');

  const shippingMethod = page.locator('#checkout-shipping-method-load input[type=radio]').first();
  await shippingMethod.waitFor();
  await shippingMethod.check();

  await page.click('button.continue');
  await page.waitForSelector('#checkout-payment-method-load');
  await page.locator('#checkout-payment-method-load input#qliroone').check();

  let frame: Frame | null = null;

  for (let attempt = 0; attempt < 45 && !frame; attempt++) {
    frame = page.frames().find(candidate => /checkout\.(staging\.)?qliro\.com/.test(candidate.url())) ?? null;

    if (!frame) {
      await page.waitForTimeout(2_000);
    }
  }

  expect(frame, 'the Qliro widget has to load before the rest means anything').toBeTruthy();

  return { page, frame: frame!, orderPolls: () => polls, challengeEmails };
}

test.describe('Qliro checkout, embedded iframe', () => {
  test.skip(!enabled, 'needs a Qliro test merchant, set QLIRO_CHECKOUT_E2E=1');

  test('releases the widget once it is on the page', async ({ browser }) => {
    const { page, frame, orderPolls } = await openIframe(browser);

    try {
      // The first visible field of the widget, whichever step it opens on. Under the dimmer the
      // click is intercepted and times out, which is the regression
      await frame.locator('input:visible').first().click({ timeout: 20_000 });

      // A quote update the widget reports on load locks it for a moment, so what is asserted is
      // that the polling goes quiet, not that it never happens
      await expect
        .poll(
          async () => {
            const before = orderPolls();
            await page.waitForTimeout(3_000);

            return orderPolls() - before;
          },
          { message: 'the widget has to stop polling once it is released', timeout: 30_000 }
        )
        .toBe(0);
    } finally {
      await page.context().close();
    }
  });

  /**
   * Without the fix this fails only when the snippet fetch beats core's set-payment-information,
   * which it did in every run on the local stand, so a pass on its own proves less than a failure.
   */
  test('hands Qliro the email the guest typed in the shipping step', async ({ browser }) => {
    const { page, challengeEmails } = await openIframe(browser);

    try {
      // The widget identifies the buyer from what the order was created with, as soon as it loads
      await expect.poll(() => challengeEmails.length, { timeout: 30_000 }).toBeGreaterThan(0);
      expect(challengeEmails[0]).toBe(email);
    } finally {
      await page.context().close();
    }
  });
});
