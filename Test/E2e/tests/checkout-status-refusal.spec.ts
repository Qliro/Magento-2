import { APIRequestContext, expect, test } from '@playwright/test';
import { runSeeder } from '../seeder';

/**
 * A checkout status push the store cannot take says why. Qliro takes a push as delivered only when
 * the body says `received` and logs any other body, so the body is all its logs show.
 * Needs `Test/E2e/seed/checkout-status.php` in the store's `var/`. The placement needs a Qliro test
 * merchant on staging and `seed/seed-sandbox-order.php` in `var/`, so it runs only with
 * `QLIRO_CHECKOUT_E2E=1`.
 */

const RECEIVED = 'received';
const SKU = 'QLIRO-E2E-STOCK';

type Placed = { qliroOrderId: number; orderId: number; callbackToken: string };
type Unplaced = { quoteId: number; qliroOrderId: number };
type Read = {
  orderId: number;
  status: string;
  log: { level: string; message: string; extra: Record<string, unknown> | null }[];
};

function seed<T>(...args: string[]): T {
  return runSeeder<T>('checkout-status.php', args);
}

async function push(request: APIRequestContext, token: string, qliroOrderId: number, status: string) {
  const response = await request.post(`/checkout/qliro_callback/checkoutStatus?token=${token}`, {
    data: { OrderId: qliroOrderId, MerchantReference: 'e2e', Status: status, Timestamp: new Date().toISOString() },
  });

  return { status: response.status(), body: (await response.json()).CallbackResponse as string };
}

test.describe('a refused checkout status push', () => {
  test('for a Qliro order the store has no link for says so', async ({ request }) => {
    const { callbackToken } = seed<{ callbackToken: string }>('token');

    const response = await push(request, callbackToken, 999000000 + Math.floor(Math.random() * 1000000), 'Completed');

    expect(response).toEqual({ status: 404, body: 'Link not found' });
  });

  test('with a status the order cannot take names the status, and a status it can take is received', async ({ request }) => {
    const placed = seed<Placed>('placed');

    // Qliro does not know the seeded order, so the store falls back to the pushed status
    const refused = await push(request, placed.callbackToken, placed.qliroOrderId, 'InProcess');
    expect(refused).toEqual({ status: 500, body: 'Order status could not be applied: status InProcess' });

    const applied = await push(request, placed.callbackToken, placed.qliroOrderId, 'Completed');
    expect(applied).toEqual({ status: 200, body: RECEIVED });
  });

  test('whose placement fails names the error and logs it, and its retry places the order', async ({ request }) => {
    test.skip(process.env.QLIRO_CHECKOUT_E2E !== '1', 'needs a Qliro test merchant, set QLIRO_CHECKOUT_E2E=1');
    test.setTimeout(180_000);

    const { callbackToken } = seed<{ callbackToken: string }>('token');
    seed('stock', `--sku=${SKU}`, '--qty=1000');
    const unplaced = runSeeder<Unplaced>('seed-sandbox-order.php', ['--qty=1', `--second=${SKU}`, '--unplaced']);
    // Qliro's own push places it first on a store the sandbox can reach
    expect(seed<Read>('read', `--qliro=${unplaced.qliroOrderId}`).orderId).toBe(0);

    // Sold out while the buyer paid
    seed('stock', `--sku=${SKU}`, '--qty=0');
    try {
      const refused = await push(request, callbackToken, unplaced.qliroOrderId, 'Completed');

      expect(refused.status).toBe(500);
      expect(refused.body).toMatch(/^Order placement failed: \S/);
      expect(refused.body).not.toContain('\n');

      const read = seed<Read>('read', `--qliro=${unplaced.qliroOrderId}`);
      expect(read.orderId).toBe(0);
      const line = read.log.find((entry) => entry.message.startsWith('Order placement failed: '));
      expect(line, JSON.stringify(read.log.map((entry) => entry.message))).toBeTruthy();
      expect(line!.message.length).toBeGreaterThan('Order placement failed: '.length);
      expect(line!.extra).toMatchObject({ qliro_order_id: unplaced.qliroOrderId });
    } finally {
      seed('stock', `--sku=${SKU}`, '--qty=1000');
    }

    const retried = await push(request, callbackToken, unplaced.qliroOrderId, 'Completed');
    expect(retried).toEqual({ status: 200, body: RECEIVED });
    expect(seed<Read>('read', `--qliro=${unplaced.qliroOrderId}`).orderId).toBeGreaterThan(0);
  });
});
