import { execFileSync } from 'node:child_process';
import { test, expect, Page } from '@playwright/test';
import { config } from '../playwright.config';
import { runSeeder } from '../seeder';

/**
 * The secondary option the buyer selects for a merchant's own shipping method reaches
 * qliroone_shipping_method_update_before, from the browser and from Qliro's validate callback,
 * and the placed order keeps the one on Qliro's shipping line.
 * Needs the QliroE2e_SecondaryOption fixture module and `seed/seed-shipping-mismatch.php` in `var/`.
 */

const PAID = 'flatrate_flatrate';
const FREE = 'freeshipping_freeshipping';

type Recorded = { method: string; option: string | null; canSaveQuote: boolean; validateCallback: boolean };

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

type SeededQuote = {
  quoteId: number;
  callbackToken: string;
  prices: Record<string, { incVat: number; exVat: number }>;
  productLines: Record<string, unknown>[];
  validate: Record<string, unknown>;
};

// Qliro's validate callback for the seeded quote, on that method and option
async function postValidate(page: Page, seeded: SeededQuote, method: string, option?: string) {
  const price = seeded.prices[method];
  const shipping = { MerchantReference: method, Description: method, Type: 'Shipping', Quantity: 1, PricePerItemIncVat: price.incVat, PricePerItemExVat: price.exVat };

  return page.request.post(`/checkout/qliro_callback/validate?token=${seeded.callbackToken}`, {
    data: {
      ...seeded.validate,
      SelectedShippingMethod: method,
      ...(option === undefined ? {} : { SelectedShippingSecondaryOption: option }),
      OrderItems: [...seeded.productLines, shipping],
    },
  });
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
    expect(recorded(cart.quoteId)).toEqual([{ method: PAID, option: 'pickup-point-1', canSaveQuote: true, validateCallback: false }]);
    expect(seed<{ shippingMethod: string }>('read', `--quote=${cart.quoteId}`).shippingMethod).toBe(PAID);
  });

  test('another option on the same method reaches the observer, which saves the quote', async ({ page }) => {
    const cart = await guestCart(page);

    await selectInWidget(page, cart.ajaxToken, { method: PAID, secondaryOption: 'pickup-point-1', price: 5 });
    const answer = await selectInWidget(page, cart.ajaxToken, { method: PAID, secondaryOption: 'pickup-point-2', price: 5 });

    expect(answer.status).toBe('OK');
    expect(recorded(cart.quoteId)).toEqual([
      { method: PAID, option: 'pickup-point-1', canSaveQuote: true, validateCallback: false },
      { method: PAID, option: 'pickup-point-2', canSaveQuote: true, validateCallback: false },
    ]);
  });

  test('the same option sent again leaves the quote alone', async ({ page }) => {
    const cart = await guestCart(page);

    await selectInWidget(page, cart.ajaxToken, { method: PAID, secondaryOption: 'pickup-point-1', price: 5 });
    const answer = await selectInWidget(page, cart.ajaxToken, { method: PAID, secondaryOption: 'pickup-point-1', price: 5 });

    expect(answer.status).toBe('SKIPPED');
    expect(recorded(cart.quoteId)).toEqual([
      { method: PAID, option: 'pickup-point-1', canSaveQuote: true, validateCallback: false },
      { method: PAID, option: 'pickup-point-1', canSaveQuote: false, validateCallback: false },
    ]);
  });

  test('a method without options passes none', async ({ page }) => {
    const cart = await guestCart(page);

    const answer = await selectInWidget(page, cart.ajaxToken, { method: FREE, price: 0 });

    expect(answer.status).toBe('OK');
    expect(recorded(cart.quoteId)).toEqual([{ method: FREE, option: null, canSaveQuote: true, validateCallback: false }]);
  });
});

test.describe('the secondary option Qliro confirms', () => {
  test('validate passes the option on when the browser update for it never came', async ({ page }) => {
    const seeded = seed<SeededQuote>('quote');

    const response = await postValidate(page, seeded, PAID, 'pickup-point-2');

    expect(response.status()).toBe(200);
    expect(recorded(seeded.quoteId)).toEqual([{ method: PAID, option: 'pickup-point-2', canSaveQuote: true, validateCallback: true }]);
    expect(seed<{ shippingMethod: string }>('read', `--quote=${seeded.quoteId}`).shippingMethod).toBe(PAID);
  });

  test('validate that moves the quote to Qliro\'s method passes that method and its option on', async ({ page }) => {
    const seeded = seed<SeededQuote>('quote');
    expect(seeded.prices[FREE]).toBeDefined();

    const response = await postValidate(page, seeded, FREE, 'pickup-point-1');

    expect(response.status()).toBe(200);
    expect(seed<{ shippingMethod: string }>('read', `--quote=${seeded.quoteId}`).shippingMethod).toBe(FREE);
    expect(recorded(seeded.quoteId)).toEqual([{ method: FREE, option: 'pickup-point-1', canSaveQuote: true, validateCallback: true }]);
  });

  test('validate sent again with the same option leaves the quote alone', async ({ page }) => {
    const seeded = seed<SeededQuote>('quote');

    await postValidate(page, seeded, PAID, 'pickup-point-2');
    const response = await postValidate(page, seeded, PAID, 'pickup-point-2');

    expect(response.status()).toBe(200);
    expect(recorded(seeded.quoteId)).toEqual([
      { method: PAID, option: 'pickup-point-2', canSaveQuote: true, validateCallback: true },
      { method: PAID, option: 'pickup-point-2', canSaveQuote: false, validateCallback: true },
    ]);
  });

  test('a declined validate passes nothing on', async ({ page }) => {
    const seeded = seed<SeededQuote>('quote');
    const shipping = { MerchantReference: FREE, Description: FREE, Type: 'Shipping', Quantity: 1, PricePerItemIncVat: 99, PricePerItemExVat: 79.2 };

    const response = await page.request.post(`/checkout/qliro_callback/validate?token=${seeded.callbackToken}`, {
      data: { ...seeded.validate, SelectedShippingMethod: FREE, SelectedShippingSecondaryOption: 'pickup-point-1', OrderItems: [...seeded.productLines, shipping] },
    });

    expect(response.status()).toBe(400);
    expect(recorded(seeded.quoteId)).toEqual([]);
  });

  test('the order keeps the option on Qliro\'s shipping line', () => {
    const seeded = seed<SeededQuote>('quote');

    const order = seed<{ shippingMethod: string; secondaryOption: string | null }>(
      'place',
      `--quote=${seeded.quoteId}`,
      `--shipping=${PAID}`,
      '--option=pickup-point-2'
    );

    expect(order.shippingMethod).toBe(PAID);
    expect(order.secondaryOption).toBe('pickup-point-2');
  });

  test('an order whose shipping line holds no option gets none', () => {
    const seeded = seed<SeededQuote>('quote');

    const order = seed<{ secondaryOption: string | null }>('place', `--quote=${seeded.quoteId}`, `--shipping=${PAID}`);

    expect(order.secondaryOption).toBeNull();
  });
});
