import { execFileSync } from 'node:child_process';

import { expect, test } from '@playwright/test';

import { config } from '../playwright.config';

/**
 * Needs Qliro itself, so it is off unless asked for.
 *
 * The buyer empties the cart in another tab while the checkout is open, and the checkout then
 * refreshes the quote. The update of the Qliro order is refused, the link is dropped and a new
 * order used to be created with no lines, which Qliro refuses, followed by a GET of `orders/*`.
 * The checkout has to send the buyer to the cart with a message and make neither call.
 *
 * ```
 * QLIRO_CHECKOUT_E2E=1 QLIRO_E2E_PRODUCT_URL=/some-simple-product npx playwright test checkout-emptied-cart
 * ```
 */
const enabled = process.env.QLIRO_CHECKOUT_E2E === '1';
const productUrl = process.env.QLIRO_E2E_PRODUCT_URL ?? '/test-simple.html';

const dbContainer = process.env.MAGENTO_DB_CONTAINER ?? config.container.replace(/-phpfpm-(\d+)$/, '-db-$1');
const dbClient = process.env.MAGENTO_DB_CLIENT ?? 'mysql';
const dbUser = process.env.MAGENTO_DB_USER ?? 'magento';
const dbPassword = process.env.MAGENTO_DB_PASSWORD ?? 'magento';
const dbName = process.env.MAGENTO_DB_NAME ?? 'magento';

function query(sql: string): string[] {
  const output = execFileSync(
    'docker',
    ['exec', dbContainer, dbClient, `-u${dbUser}`, `-p${dbPassword}`, dbName, '-N', '-B', '-e', sql],
    { encoding: 'utf8', maxBuffer: 20 * 1024 * 1024, stdio: ['ignore', 'pipe', 'ignore'] }
  );

  return output.split('\n').filter(Boolean);
}

function lastLogId(): number {
  return Number.parseInt(query('select max(id) from qliroone_log')[0] ?? '0', 10) || 0;
}

/** Message and context of every log row since the given id, one string per row. */
function logSince(id: number): string[] {
  return query(`select concat(message, ' ', coalesce(extra, '')) from qliroone_log where id > ${id} order by id`);
}

test.describe('Qliro checkout, a cart emptied while the checkout is open', () => {
  test.skip(!enabled, 'needs a Qliro test merchant, set QLIRO_CHECKOUT_E2E=1');

  test('sends the buyer to the cart without creating an order with no lines', async ({ browser }) => {
    // A cold store compiles the pages and the widget loads from the sandbox
    test.setTimeout(300_000);

    const context = await browser.newContext({
      baseURL: config.baseURL,
      ignoreHTTPSErrors: true,
      locale: process.env.QLIRO_E2E_LOCALE ?? 'sv-SE',
      userAgent:
        process.env.QLIRO_E2E_USER_AGENT ??
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36',
    });
    const page = await context.newPage();

    // Through the cart's own form post, a click before Magento sets the form key adds nothing
    await page.goto(productUrl, { waitUntil: 'domcontentloaded' });
    const productId = await page.locator('#product_addtocart_form input[name="product"]').inputValue();
    await expect.poll(async () => (await context.cookies()).some(cookie => cookie.name === 'form_key'), { timeout: 90_000 }).toBe(true);

    // A stand that is still warming up can drop the add, so it is checked and tried again
    for (let attempt = 0; attempt < 3; attempt++) {
      const formKey = (await context.cookies()).find(cookie => cookie.name === 'form_key')!.value;
      await context.request.post(`/checkout/cart/add/product/${productId}/`, {
        form: { form_key: formKey, product: productId, qty: '1' },
        maxRedirects: 0,
      });
      const cartPage = await (await context.request.get('/checkout/cart/')).text();

      if (!cartPage.includes('cart-empty')) {
        break;
      }
    }

    await page.goto('/checkout/', { waitUntil: 'domcontentloaded' });
    expect(page.url(), 'the cart has to hold the product').toContain('/checkout/qliro/');

    let widget = false;

    for (let attempt = 0; attempt < 45 && !widget; attempt++) {
      widget = page.frames().some(frame => /checkout\.(staging\.)?qliro\.com/.test(frame.url()));

      if (!widget) {
        await page.waitForTimeout(2_000);
      }
    }

    expect(widget, 'the Qliro widget has to load first').toBe(true);

    // The other tab
    const cart = await context.newPage();
    await cart.goto('/checkout/cart/', { waitUntil: 'load' });
    await cart.waitForTimeout(4_000);
    await cart.locator('.action-delete').first().click();
    await cart.waitForSelector('.cart-empty', { timeout: 60_000 });
    await cart.close();

    // The widget refreshes the cart on its own while it loads, that update is not this test's
    const since = lastLogId();

    // The refresh every widget event makes, through the checkout's own handler
    await page.evaluate(() => {
      (window as any).require(['Qliro_QliroOne/js/model/qliro'], (qliro: { updateCart: () => void }) => {
        qliro.updateCart();
      });
    });

    await page.waitForURL(/\/checkout\/cart\/?$/, { timeout: 60_000, waitUntil: 'commit' });
    await expect(page.locator('.page.messages')).toContainText('Your cart has nothing to pay for');

    const log = logSince(since);
    const all = log.join('\n');

    expect(all, 'the refusal names the quote and why').toMatch(
      /Qliro order (update|create) not sent, the request has no product lines.*"quote_id":\s*"?\d+.*"reason":\s*"the quote has no items"/
    );
    expect(log.filter(row => /^>>> (POST|PUT) /.test(row)), 'no create or update reaches Qliro').toHaveLength(0);
    expect(all).not.toContain('orders/*');
    expect(all).not.toContain('has no value for');

    await context.close();
  });
});
