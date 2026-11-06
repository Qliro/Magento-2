import { test, expect, Page } from '@playwright/test';
import { runSeeder } from '../seeder';

/**
 * Qliro's shipping provider notification is accepted before the order is placed and lands on the
 * order once it is. Qliro takes a notification as delivered only when the body says `received`,
 * whatever the status, and sends anything else again.
 * Needs `Test/E2e/seed/shipping-notification.php` in the store's `var/`.
 */

const RECEIVED = 'received';

type SeededQuote = { quoteId: number; qliroOrderId: number; reference: string; callbackToken: string };
type ReadOrder = {
  orderId: number;
  stored: number;
  shippingDescription?: string;
  shippingInfo?: { provider: string; payload: Record<string, unknown> } | null;
};

const PAYLOAD = { service: { name: 'PostNord MyPack Collect', id: 'P19' }, agent: { name: 'Pickup point' } };

function seed<T>(...args: string[]): T {
  return runSeeder<T>('shipping-notification.php', args);
}

async function notify(
  page: Page,
  quote: SeededQuote,
  payload: Record<string, unknown> = PAYLOAD,
  sentAt: string = new Date().toISOString()
) {
  const response = await page.request.post(
    `/checkout/qliro_callback/merchantNotification?token=${quote.callbackToken}`,
    {
      data: {
        OrderId: quote.qliroOrderId,
        MerchantReference: quote.reference,
        EventType: 'SHIPPING_PROVIDER_UPDATE',
        Provider: 'Unifaun',
        IsSuccess: true,
        // Set once by Qliro and kept on its redeliveries
        Timestamp: sentAt,
        Payload: payload,
      },
    }
  );

  return { status: response.status(), body: await response.json() };
}

test.describe('the shipping provider notification', () => {
  test('arriving before the order is accepted at once and applied on placement', async ({ page }) => {
    const quote = seed<SeededQuote>('quote');

    const first = await notify(page, quote);

    expect(first.status).toBe(200);
    expect(first.body.CallbackResponse).toBe(RECEIVED);
    expect(seed<ReadOrder>('read', `--quote=${quote.quoteId}`)).toEqual({ orderId: 0, stored: 1 });

    seed('place', `--quote=${quote.quoteId}`);
    const order = seed<ReadOrder>('read', `--quote=${quote.quoteId}`);

    expect(order.orderId).toBeGreaterThan(0);
    expect(order.stored).toBe(0);
    expect(order.shippingDescription).toBe('Unifaun - PostNord MyPack Collect (P19)');
    expect(order.shippingInfo).toMatchObject({ provider: 'Unifaun', payload: PAYLOAD });

    // Qliro repeating it after placement changes nothing
    const repeated = await notify(page, quote);
    expect(repeated.status).toBe(200);
    expect(repeated.body.CallbackResponse).toBe(RECEIVED);
  });

  test('a later notification before the order replaces the stored one', async ({ page }) => {
    const quote = seed<SeededQuote>('quote');
    const changed = { service: { name: 'DHL Service Point', id: 'DHL1' } };

    expect((await notify(page, quote)).body.CallbackResponse).toBe(RECEIVED);
    expect((await notify(page, quote, changed)).body.CallbackResponse).toBe(RECEIVED);

    seed('place', `--quote=${quote.quoteId}`);
    const order = seed<ReadOrder>('read', `--quote=${quote.quoteId}`);

    expect(order.shippingDescription).toBe('Unifaun - DHL Service Point (DHL1)');
    expect(order.shippingInfo).toMatchObject({ provider: 'Unifaun', payload: changed });
  });

  test('arriving while the order is being placed is answered try again, and its retry is applied', async ({ page }) => {
    const quote = seed<SeededQuote>('quote');
    seed('lock', `--quote=${quote.quoteId}`);

    try {
      const response = await notify(page, quote);

      expect(response.status).toBe(409);
      expect(response.body.CallbackResponse).not.toBe(RECEIVED);
      expect(seed<ReadOrder>('read', `--quote=${quote.quoteId}`)).toEqual({ orderId: 0, stored: 0 });
    } finally {
      seed('unlock', `--quote=${quote.quoteId}`);
    }

    expect((await notify(page, quote)).body.CallbackResponse).toBe(RECEIVED);
    seed('place', `--quote=${quote.quoteId}`);
    const order = seed<ReadOrder>('read', `--quote=${quote.quoteId}`);

    expect(order.stored).toBe(0);
    expect(order.shippingDescription).toBe('Unifaun - PostNord MyPack Collect (P19)');
  });

  test('a redelivery of an older one does not replace a newer one', async ({ page }) => {
    const quote = seed<SeededQuote>('quote');
    const newer = { service: { name: 'DHL Service Point', id: 'DHL1' } };

    expect((await notify(page, quote, newer, '2026-11-04T10:00:05.000Z')).body.CallbackResponse).toBe(RECEIVED);
    expect((await notify(page, quote, PAYLOAD, '2026-11-04T10:00:01.000Z')).body.CallbackResponse).toBe(RECEIVED);

    seed('place', `--quote=${quote.quoteId}`);
    expect(seed<ReadOrder>('read', `--quote=${quote.quoteId}`).shippingDescription).toBe('Unifaun - DHL Service Point (DHL1)');

    // The same after placement
    expect((await notify(page, quote, PAYLOAD, '2026-11-04T10:00:02.000Z')).body.CallbackResponse).toBe(RECEIVED);
    expect(seed<ReadOrder>('read', `--quote=${quote.quoteId}`).shippingDescription).toBe('Unifaun - DHL Service Point (DHL1)');
  });

  test('for a refused or replaced checkout is acknowledged and kept', async ({ page }) => {
    const quote = seed<SeededQuote>('quote');
    seed('deactivate', `--quote=${quote.quoteId}`);

    const response = await notify(page, quote);

    expect(response.status).toBe(200);
    expect(response.body.CallbackResponse).toBe(RECEIVED);
    expect(seed<ReadOrder>('read', `--quote=${quote.quoteId}`)).toEqual({ orderId: 0, stored: 1 });
  });

  test('for a canceled order is acknowledged and left off it', async ({ page }) => {
    const quote = seed<SeededQuote>('quote');
    seed('place', `--quote=${quote.quoteId}`);
    expect(seed<{ state: string }>('cancel', `--quote=${quote.quoteId}`).state).toBe('canceled');
    const before = seed<ReadOrder>('read', `--quote=${quote.quoteId}`).shippingDescription;

    const response = await notify(page, quote);

    expect(response.body.CallbackResponse).toBe(RECEIVED);
    const order = seed<ReadOrder>('read', `--quote=${quote.quoteId}`);
    expect(order.shippingDescription).toBe(before);
    expect(order.shippingInfo ?? null).toBeNull();
  });

  test('arriving after the order is applied straight away', async ({ page }) => {
    const quote = seed<SeededQuote>('quote');
    seed('place', `--quote=${quote.quoteId}`);

    const response = await notify(page, quote);

    expect(response.status).toBe(200);
    expect(response.body.CallbackResponse).toBe(RECEIVED);
    const order = seed<ReadOrder>('read', `--quote=${quote.quoteId}`);
    expect(order.stored).toBe(0);
    expect(order.shippingDescription).toBe('Unifaun - PostNord MyPack Collect (P19)');
  });
});
