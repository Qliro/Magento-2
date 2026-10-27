import { test, expect, Page } from '@playwright/test';
import { runSeeder } from '../seeder';
import { loginToAdmin, openAdminOrder } from './support';

/**
 * An order whose lines differ from what Qliro reserved is placed on hold and says which lines.
 * Needs `Test/E2e/seed/seed-line-differences.php` in the store's `var/`.
 */

type SeededQuote = { quoteId: number; callbackToken: string; validate: Record<string, unknown> };
type PlacedOrder = { orderId: number; incrementId: string; dropped: string[]; orderSkus: string[] };
type OrderState = {
  state: string;
  status: string;
  holdBeforeState: string | null;
  holdBeforeStatus: string | null;
  history: string[];
};

function seed<T>(...args: string[]): T {
  return runSeeder<T>('seed-line-differences.php', args);
}

async function validate(page: Page, quote: SeededQuote) {
  const response = await page.request.post(`/checkout/qliro_callback/validate?token=${quote.callbackToken}`, {
    data: quote.validate,
  });
  expect(response.status(), await response.text()).toBe(200);
}

test.describe('an order whose lines differ from the Qliro order', () => {
  test('a line dropped after validation puts the order on hold until the merchant releases it', async ({ page }) => {
    const quote = seed<SeededQuote>('quote');
    await validate(page, quote);

    const order = seed<PlacedOrder>('place', `--quote=${quote.quoteId}`, '--drop=QLIRO-E2E-PRODUCT-B', '--status=InProcess');
    expect(order.dropped).toEqual(['QLIRO-E2E-PRODUCT-B']);
    expect(order.orderSkus).toEqual(['QLIRO-E2E-PRODUCT']);

    let state = seed<OrderState>('read', `--order=${order.orderId}`);
    expect(state.state).toBe('holded');
    const comment = state.history.find((entry) => entry.includes('differ from what Qliro reserved'));
    expect(comment).toMatch(/:QLIRO-E2E-PRODUCT-B: 0 in the order, 2 in Qliro \(not in the cart\)/);

    // Qliro's Completed arrives later, the hold stays
    seed<PlacedOrder>('place', `--quote=${quote.quoteId}`, '--status=Completed');
    state = seed<OrderState>('read', `--order=${order.orderId}`);
    expect(state.state).toBe('holded');
    expect(state.holdBeforeState).toBe('new');

    await loginToAdmin(page);
    await openAdminOrder(page, order.orderId);
    await expect(page.locator('#order_status')).toHaveText('On Hold');
    await expect(page.locator('#order_history_block')).toContainText('QLIRO-E2E-PRODUCT-B: 0 in the order, 2 in Qliro');

    await page.click('#order-view-unhold-button');
    await expect(page.locator('.message-success')).toContainText('You released the order from holding status');

    state = seed<OrderState>('read', `--order=${order.orderId}`);
    expect(state.state).toBe('new');
    expect(state.status).not.toBe('holded');
  });

  test('an order whose lines match is placed as before', async ({ page }) => {
    const quote = seed<SeededQuote>('quote');
    await validate(page, quote);

    const order = seed<PlacedOrder>('place', `--quote=${quote.quoteId}`, '--status=Completed');
    expect(order.orderSkus.sort()).toEqual(['QLIRO-E2E-PRODUCT', 'QLIRO-E2E-PRODUCT-B']);

    const state = seed<OrderState>('read', `--order=${order.orderId}`);
    expect(state.state).toBe('new');
    expect(state.history.some((entry) => entry.includes('differ from what Qliro reserved'))).toBe(false);
  });
});

type SandboxOrder = { orderId: number; state: string; skus: string[]; history: string[] };

/**
 * The same against a real Qliro order: the lines come from the merchant API's GetOrder.
 * Needs the store on a Qliro test merchant, `seed/seed-sandbox-order.php` in `var/` and QLIRO_CHECKOUT_E2E=1.
 */
test.describe('an order whose lines differ from a Qliro sandbox order', () => {
  test.skip(process.env.QLIRO_CHECKOUT_E2E !== '1', 'needs a Qliro test merchant, set QLIRO_CHECKOUT_E2E=1');

  test('an order whose lines match Qliro\'s is not held', () => {
    const order = runSeeder<SandboxOrder>('seed-sandbox-order.php', ['--qty=2', '--second=QLIRO-E2E-PRODUCT-B']);

    expect(order.skus.sort()).toEqual(['QLIRO-E2E-PRODUCT', 'QLIRO-E2E-PRODUCT-B']);
    expect(order.state).not.toBe('holded');
    expect(order.history.some((entry) => entry.includes('differ from what Qliro reserved'))).toBe(false);
  });

  test('a line dropped after the payment puts the order on hold', () => {
    const order = runSeeder<SandboxOrder>('seed-sandbox-order.php', [
      '--qty=2',
      '--second=QLIRO-E2E-PRODUCT-B',
      '--drop=QLIRO-E2E-PRODUCT-B',
    ]);

    expect(order.skus).toEqual(['QLIRO-E2E-PRODUCT']);
    expect(order.state).toBe('holded');
    expect(order.history.join('\n')).toMatch(/QLIRO-E2E-PRODUCT-B: 0 in the order, 1 in Qliro \(not in the cart\)/);
  });
});
