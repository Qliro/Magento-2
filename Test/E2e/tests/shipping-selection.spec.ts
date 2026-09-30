import { test, expect, Page } from '@playwright/test';
import { config } from '../playwright.config';
import { runSeeder } from '../seeder';

/**
 * PLIN-461: the order has to carry the delivery the buyer ended on in Qliro, even when the store
 * received the buyer's choices out of order and its quote is still on an earlier one.
 * Needs `Test/E2e/seed/seed-shipping-mismatch.php` in the store's `var/`, and free shipping on.
 */

const PAID = 'flatrate_flatrate';
const FREE = 'freeshipping_freeshipping';

type SeededQuote = {
  quoteId: number;
  callbackToken: string;
  prices: Record<string, { incVat: number; exVat: number }>;
  productLines: Record<string, unknown>[];
  validate: Record<string, unknown>;
};

function seed<T>(...args: string[]): T {
  return runSeeder<T>('seed-shipping-mismatch.php', args);
}

function shippingLine(code: string, incVat: number, exVat: number) {
  return { MerchantReference: code, Description: code, Type: 'Shipping', Quantity: 1, PricePerItemIncVat: incVat, PricePerItemExVat: exVat };
}

async function postValidate(page: Page, seeded: SeededQuote, shipping: ReturnType<typeof shippingLine>) {
  return page.request.post(`/checkout/qliro_callback/validate?token=${seeded.callbackToken}`, {
    data: { ...seeded.validate, OrderItems: [...seeded.productLines, shipping] },
  });
}

test.describe('the delivery the buyer chose in Qliro', () => {
  test('validate accepts a quote left on another delivery and puts it on Qliro\'s', async ({ page }) => {
    const seeded = seed<SeededQuote>('quote');
    expect(Object.keys(seeded.prices).sort()).toEqual([PAID, FREE].sort());

    const response = await postValidate(page, seeded, shippingLine(FREE, 0, 0));

    expect(response.status()).toBe(200);
    expect(seed<{ applied: number }>('log', `--quote=${seeded.quoteId}`).applied).toBe(1);
    expect(seed<{ shippingMethod: string }>('read', `--quote=${seeded.quoteId}`).shippingMethod).toBe(FREE);
  });

  test('validate declines when the store prices Qliro\'s delivery differently', async ({ page }) => {
    const seeded = seed<SeededQuote>('quote');

    const response = await postValidate(page, seeded, shippingLine(FREE, 99, 79.2));

    expect(response.status()).toBe(400);
    expect(await response.json()).toEqual({ DeclineReason: 'Other' });
    expect(seed<{ shippingMethod: string }>('read', `--quote=${seeded.quoteId}`).shippingMethod).toBe(PAID);
  });

  test('the order is placed with the delivery of the Qliro order', async () => {
    const seeded = seed<SeededQuote>('quote');
    expect(seed<{ shippingMethod: string }>('read', `--quote=${seeded.quoteId}`).shippingMethod).toBe(PAID);

    const order = seed<{ shippingMethod: string; shippingInclTax: number; grandTotal: number; qliroTotal: number }>(
      'place',
      `--quote=${seeded.quoteId}`,
      `--shipping=${FREE}`
    );

    expect(order.shippingMethod).toBe(FREE);
    expect(order.shippingInclTax).toBe(0);
    expect(order.grandTotal).toBe(order.qliroTotal);
  });

  test('the checkout sends a later delivery only after the earlier one is answered', async ({ page }) => {
    await page.goto('/qliro-e2e-product.html');
    await page.click('#product-addtocart-button');
    await expect(page.locator('.minicart-wrapper .counter-number')).toHaveText('1');

    await page.goto('/checkout/cart/');
    const masked = await page.evaluate(() => (window as any).checkoutConfig.quoteData.entity_id as string);
    const cart = seed<{ quoteId: number; ajaxToken: string }>('cart', `--masked=${masked}`);

    // The first choice is held back on the network, which is how the second used to overtake it
    const sent: string[] = [];
    let first = true;
    await page.route('**/qliro_ajax/updateShippingMethod**', async (route) => {
      const method = JSON.parse(route.request().postData() ?? '{}').method;
      sent.push(`sent ${method}`);

      if (first) {
        first = false;
        await new Promise((resolve) => setTimeout(resolve, 3000));
      }

      const response = await route.fetch();
      sent.push(`answered ${method}`);
      await route.fulfill({ response });
    });

    const answered = Promise.all([
      page.waitForResponse((r) => r.url().includes('updateShippingMethod') && r.request().postData()!.includes(PAID)),
      page.waitForResponse((r) => r.url().includes('updateShippingMethod') && r.request().postData()!.includes(FREE)),
    ]);

    await page.evaluate(
      ({ baseUrl, token, paid, free }) =>
        new Promise<void>((resolve) => {
          (window as any).require(['Qliro_QliroOne/js/model/config', 'Qliro_QliroOne/js/model/qliro'], (qliroConfig: any, model: any) => {
            qliroConfig.updateShippingMethodUrl = `${baseUrl}/checkout/qliro_ajax/updateShippingMethod`;
            qliroConfig.securityToken = token;
            model.onShippingMethodChanged({ method: paid, price: 5 });
            model.onShippingMethodChanged({ method: free, price: 0 });
            resolve();
          });
        }),
      { baseUrl: config.baseURL, token: cart.ajaxToken, paid: PAID, free: FREE }
    );

    await answered;

    expect(seed<{ shippingMethod: string }>('read', `--quote=${cart.quoteId}`).shippingMethod).toBe(FREE);
    expect(sent).toEqual([`sent ${PAID}`, `answered ${PAID}`, `sent ${FREE}`, `answered ${FREE}`]);
  });
});
