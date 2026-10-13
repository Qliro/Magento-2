import { execFileSync } from 'node:child_process';
import { test, expect, Page } from '@playwright/test';
import { config } from '../playwright.config';
import { runSeeder } from '../seeder';

/**
 * The secondary option the buyer selects for a merchant's own shipping method reaches
 * qliroone_shipping_method_update_before. Needs the QliroE2e_SecondaryOption fixture module.
 */

const PAID = 'flatrate_flatrate';
const FREE = 'freeshipping_freeshipping';

type Recorded = { method: string; option: string | null; canSaveQuote: boolean };

function seed<T>(...args: string[]): T {
  return runSeeder<T>('seed-shipping-mismatch.php', args);
}

function recorded(quoteId: number): Recorded[] {
  const output = execFileSync(
    'docker',
    ['exec', config.container, 'sh', '-c', `cat /var/www/html/var/qliro-e2e/secondary-option-${quoteId}.json 2>/dev/null || echo '[]'`],
    { encoding: 'utf8' }
  );

  return JSON.parse(output);
}

async function guestCart(page: Page) {
  await page.goto('/qliro-e2e-product.html');
  await page.click('#product-addtocart-button');
  await expect(page.locator('.minicart-wrapper .counter-number')).toHaveText('1');

  await page.goto('/checkout/cart/');
  const masked = await page.evaluate(() => (window as any).checkoutConfig.quoteData.entity_id as string);

  return seed<{ quoteId: number; ajaxToken: string }>('cart', `--masked=${masked}`);
}

// Through the checkout's own model, which posts what Qliro's widget hands it
async function selectInWidget(page: Page, ajaxToken: string, shipping: Record<string, unknown>) {
  const answered = page.waitForResponse((r) => r.url().includes('updateShippingMethod'));

  await page.evaluate(
    ({ baseUrl, token, shipping }) =>
      new Promise<void>((resolve) => {
        (window as any).require(['Qliro_QliroOne/js/model/config', 'Qliro_QliroOne/js/model/qliro'], (qliroConfig: any, model: any) => {
          qliroConfig.updateShippingMethodUrl = `${baseUrl}/checkout/qliro_ajax/updateShippingMethod`;
          qliroConfig.securityToken = token;
          model.onShippingMethodChanged(shipping);
          resolve();
        });
      }),
    { baseUrl: config.baseURL, token: ajaxToken, shipping }
  );

  return (await answered).json();
}

test.describe('a secondary option of a merchant\'s own shipping method', () => {
  test('the shipping methods callback offers the options the merchant added', async ({ page }) => {
    const seeded = seed<{ qliroOrderId: number; callbackToken: string; validate: Record<string, any> }>('quote');

    const response = await page.request.post(`/checkout/qliro_callback/shippingMethods?token=${seeded.callbackToken}`, {
      data: {
        OrderId: seeded.qliroOrderId,
        MerchantReference: seeded.validate.MerchantReference,
        CountryCode: 'SE',
        Customer: seeded.validate.Customer,
        ShippingAddress: seeded.validate.ShippingAddress,
      },
    });

    expect(response.status()).toBe(200);
    const methods: any[] = (await response.json()).AvailableShippingMethods;
    const flatRate = methods.find((method) => method.MerchantReference === PAID);
    expect(flatRate).toBeDefined();
    expect(flatRate.SecondaryOptions.map((option: any) => option.MerchantReference)).toEqual(['pickup-point-1', 'pickup-point-2']);
  });

  test('the observer gets the option the buyer selected', async ({ page }) => {
    const cart = await guestCart(page);

    const answer = await selectInWidget(page, cart.ajaxToken, { method: PAID, secondaryOption: 'pickup-point-1', price: 5 });

    expect(answer.status).toBe('OK');
    expect(recorded(cart.quoteId)).toEqual([{ method: PAID, option: 'pickup-point-1', canSaveQuote: true }]);
    expect(seed<{ shippingMethod: string }>('read', `--quote=${cart.quoteId}`).shippingMethod).toBe(PAID);
  });

  test('another option on the same method reaches the observer, which saves the quote', async ({ page }) => {
    const cart = await guestCart(page);

    await selectInWidget(page, cart.ajaxToken, { method: PAID, secondaryOption: 'pickup-point-1', price: 5 });
    const answer = await selectInWidget(page, cart.ajaxToken, { method: PAID, secondaryOption: 'pickup-point-2', price: 5 });

    expect(answer.status).toBe('OK');
    expect(recorded(cart.quoteId)).toEqual([
      { method: PAID, option: 'pickup-point-1', canSaveQuote: true },
      { method: PAID, option: 'pickup-point-2', canSaveQuote: true },
    ]);
  });

  test('a method without options passes none', async ({ page }) => {
    const cart = await guestCart(page);

    const answer = await selectInWidget(page, cart.ajaxToken, { method: FREE, price: 0 });

    expect(answer.status).toBe('OK');
    expect(recorded(cart.quoteId)).toEqual([{ method: FREE, option: null, canSaveQuote: true }]);
  });
});
