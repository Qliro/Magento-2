import { expect, test } from '@playwright/test';

import { config } from '../playwright.config';

/**
 * The one test in this suite that needs Qliro itself, so it is off unless asked for.
 *
 * Magento renders the checkout with the email from the quote's `customer_email`, and a Qliro
 * checkout learns the email long after the page was rendered and only on the server. Left empty,
 * `quote.guestEmail` stays null for the page's whole life, and every core call that carries it
 * goes out with `email: null`: `set-payment-information`, which the automatic selection of a
 * single payment method triggers on each totals refresh, and `place-order`. Magento answers
 * `"email" is required` and the buyer sees a failed checkout over an address they typed minutes
 * earlier. Vajper's browser log carried two of those per refresh (PLIN-376).
 *
 * The store has to have Qliro as its only payment method, which is what makes core select it by
 * itself. With a second method enabled the selection never happens and the regression is
 * invisible, which is why it was not caught on a stock install.
 *
 * ```
 * QLIRO_CHECKOUT_E2E=1 QLIRO_E2E_PRODUCT_URL=/some-simple-product npx playwright test checkout-guest-email
 * ```
 */
const enabled = process.env.QLIRO_CHECKOUT_E2E === '1';
const productUrl = process.env.QLIRO_E2E_PRODUCT_URL ?? '/';
const email = process.env.QLIRO_E2E_EMAIL ?? 'qliro-e2e@example.com';
const postcode = process.env.QLIRO_E2E_POSTCODE ?? '11329';

test.describe('Qliro checkout, guest email', () => {
  test.skip(!enabled, 'needs a Qliro test merchant, set QLIRO_CHECKOUT_E2E=1');

  test('fills the guest email so Magento accepts its own payment calls', async ({ browser }) => {
    /*
     * A context of its own rather than the shared fixture: the store under test routes by
     * language and can answer a headless browser with a page whose scripts never run, and both
     * leave the cart somewhere other than where the checkout looks for it.
     */
    const context = await browser.newContext({
      baseURL: config.baseURL,
      ignoreHTTPSErrors: true,
      locale: process.env.QLIRO_E2E_LOCALE ?? 'sv-SE',
      userAgent:
        process.env.QLIRO_E2E_USER_AGENT ??
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36',
    });
    const page = await context.newPage();
    const paymentInformationCalls: number[] = [];

    page.on('requestfinished', async request => {
      if (!/\/set-payment-information/.test(request.url())) {
        return;
      }

      const response = await request.response();
      paymentInformationCalls.push(response ? response.status() : 0);
    });

    await page.goto(productUrl, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#product_addtocart_form');
    // Magento replaces the form key from its own storage after the page is up, and submitting
    // before that leaves the add rejected and the cart empty
    await page.waitForTimeout(4_000);

    await page.evaluate(() => {
      (document.querySelector('#product_addtocart_form') as HTMLFormElement).requestSubmit();
    });

    // The add is a full page post followed by a redirect, and the session that carries the cart
    // is set on the way, so the checkout is opened once that has settled rather than at the
    // first response
    await page.waitForTimeout(10_000);

    await page.goto('/checkout/', { waitUntil: 'domcontentloaded' });
    expect(page.url(), 'the cart has to hold the product before the checkout means anything')
      .toContain('/checkout/qliro/');

    /*
     * The widget is an iframe served from checkout.qliro.com in production and from the staging
     * host in the sandbox. The frame object appears a moment after the element does, so the
     * frame list is what is waited on, not the DOM.
     */
    let frame = null as Awaited<ReturnType<typeof page.mainFrame>> | null;

    for (let attempt = 0; attempt < 45; attempt++) {
      frame = page.frames().find(candidate => /checkout\.(staging\.)?qliro\.com/.test(candidate.url())) ?? null;

      if (frame) {
        break;
      }

      await page.waitForTimeout(2_000);
    }

    expect(frame, 'the Qliro widget has to load before the rest means anything').toBeTruthy();

    const inputs = frame!.locator('input');
    await inputs.nth(0).pressSequentially(email, { delay: 30 });

    if ((await inputs.count()) > 1) {
      await inputs.nth(1).pressSequentially(postcode, { delay: 40 });
    }

    // The label is the store's language, the flow is not
    await frame!.locator('button, [role="button"]').filter({ hasText: /forts/i }).first().click();
    await page.waitForTimeout(12_000);

    const guestEmail = await page.evaluate(
      () =>
        new Promise<unknown>(resolve => {
          (window as any).require(['Magento_Checkout/js/model/quote'], (quote: any) => resolve(quote.guestEmail));
          setTimeout(() => resolve(null), 5_000);
        })
    );

    expect(guestEmail, 'the checkout model has to carry the buyer email').toBe(email);
    expect(
      paymentInformationCalls.filter(status => status >= 400),
      `set-payment-information answered ${paymentInformationCalls.join(', ')}`
    ).toEqual([]);

    await context.close();
  });
});
