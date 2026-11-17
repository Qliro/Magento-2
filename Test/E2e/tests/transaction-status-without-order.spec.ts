import { APIRequestContext, expect, test } from '@playwright/test';
import { runSeeder } from '../seeder';

/**
 * Qliro resends a transaction status push until the body says `received`, whatever the HTTP status.
 * A push with no order to update is received with a message, a failed one is not.
 * Needs `Test/E2e/seed/transaction-status.php` in the store's `var/`.
 */

type Seeded = { qliroOrderId: number; orderId: number; callbackToken: string };
type Read = { statuses: string[]; log: { level: string; message: string }[] };

function seed<T>(...args: string[]): T {
  return runSeeder<T>('transaction-status.php', args);
}

function transactionId(): number {
  return 900000000 + Math.floor(Math.random() * 90000000);
}

async function push(
  request: APIRequestContext,
  token: string,
  qliroOrderId: number,
  txn: number,
  status: string,
  paymentType = 'Reversal'
) {
  const response = await request.post(`/checkout/qliro_callback/transactionStatus?token=${token}`, {
    data: {
      OrderId: qliroOrderId,
      MerchantReference: 'e2e',
      PaymentTransactionId: txn,
      PaymentType: paymentType,
      Status: status,
      Amount: 34,
      Currency: 'SEK',
      Timestamp: new Date().toISOString(),
    },
  });

  return { status: response.status(), body: await response.json() };
}

function logged(read: Read, text: string): boolean {
  return read.log.some((line) => line.message.includes(text));
}

test.describe('a transaction status push', () => {
  test('for a Qliro order the store has no link for is received and says nothing was updated', async ({ request }) => {
    const { callbackToken } = seed<{ callbackToken: string }>('token');
    const qliroOrderId = 990000000 + Math.floor(Math.random() * 1000000);

    const response = await push(request, callbackToken, qliroOrderId, transactionId(), 'Success');

    expect(response).toEqual({
      status: 200,
      body: { CallbackResponse: 'received', Message: 'No link for this Qliro order, nothing updated' },
    });
    const read = seed<Read>('read', `--qliro=${qliroOrderId}`);
    expect(read.statuses).toEqual([]);
    expect(logged(read, 'No link for this Qliro order'), JSON.stringify(read.log)).toBe(true);
  });

  test('for a checkout that never became an order is received and says nothing was updated', async ({ request }) => {
    const link = seed<Seeded>('link');
    expect(link.orderId).toBe(0);

    const response = await push(request, link.callbackToken, link.qliroOrderId, transactionId(), 'Success');

    expect(response).toEqual({
      status: 200,
      body: { CallbackResponse: 'received', Message: 'No Magento order for this Qliro order, nothing updated' },
    });
    const read = seed<Read>('read', `--qliro=${link.qliroOrderId}`);
    expect(read.statuses).toEqual([]);
    expect(logged(read, 'No Magento order for this Qliro order'), JSON.stringify(read.log)).toBe(true);

  });

  test('taking money on a checkout without an order is answered 500, as the order may still come', async ({ request }) => {
    const link = seed<Seeded>('link');

    const response = await push(request, link.callbackToken, link.qliroOrderId, transactionId(), 'Success', 'Capture');

    expect(response).toEqual({ status: 500, body: { CallbackResponse: 'Order not placed yet' } });
    const read = seed<Read>('read', `--qliro=${link.qliroOrderId}`);
    expect(read.statuses).toEqual([]);
    const warned = read.log.filter((line) => line.message.includes('Order not placed yet'));
    expect(warned.map((line) => line.level.toLowerCase()), JSON.stringify(read.log)).toEqual(['warning']);
  });

  test('for an order whose link was deactivated is stored for that order', async ({ request }) => {
    const cancelled = seed<Seeded>('link', '--placed', '--inactive');
    expect(cancelled.orderId).toBeGreaterThan(0);

    const response = await push(request, cancelled.callbackToken, cancelled.qliroOrderId, transactionId(), 'Success');

    expect(response).toEqual({ status: 200, body: { CallbackResponse: 'received' } });
    expect(seed<Read>('read', `--qliro=${cancelled.qliroOrderId}`).statuses).toContain('Success');
  });

  test('that fails is answered 500 so Qliro sends it again, and the retry is received', async ({ request }) => {
    const placed = seed<Seeded>('link', '--placed');
    expect(placed.orderId).toBeGreaterThan(0);
    const txn = transactionId();

    seed('fail', `--txn=${txn}`);
    try {
      const failed = await push(request, placed.callbackToken, placed.qliroOrderId, txn, 'Success');

      expect(failed).toEqual({ status: 500, body: { CallbackResponse: 'Transaction status could not be applied' } });
      expect(seed<Read>('read', `--qliro=${placed.qliroOrderId}`).statuses).toEqual([]);
    } finally {
      seed('unfail', `--txn=${txn}`);
    }

    const retried = await push(request, placed.callbackToken, placed.qliroOrderId, txn, 'Success');
    expect(retried).toEqual({ status: 200, body: { CallbackResponse: 'received' } });
    expect(seed<Read>('read', `--qliro=${placed.qliroOrderId}`).statuses).toContain('Success');
  });
});
