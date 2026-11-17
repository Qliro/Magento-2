import { execFileSync } from 'node:child_process';
import { APIRequestContext, APIResponse, expect, request, test } from '@playwright/test';

import { config } from '../playwright.config';
import { runSeeder } from '../seeder';
import { loginToAdmin, openAdminOrder } from './support';

/**
 * Needs Qliro itself, so it is off unless asked for. The store is on a Qliro test merchant on
 * staging with capture on invoice on.
 *
 * Before 1.8.2 an invoice Qliro refused as already shipped took the preauthorization from the
 * refusal as its capture, and Qliro refunds against a capture only. The orders here are captured
 * for real, then put back on the preauthorization the way those invoices were recorded.
 *
 * ```
 * QLIRO_CHECKOUT_E2E=1 MAGENTO_SKIP_SEED=1 npx playwright test capture-relink
 * ```
 */
const enabled = process.env.QLIRO_CHECKOUT_E2E === '1';

type SandboxOrder = { orderId: number; incrementId: string; qliroOrderId: number; grandTotal: number; itemId: number; qty: number };
type State = {
  invoices: { id: number; incrementId: string; grandTotal: number; transactionId: string }[];
  transactions: { txnId: string; parentTxnId: string | null; type: string; capturedAmount: number | null }[];
  atQliro: { id: number; type: string; status: string; amount: number }[];
  comments: string[];
  creditMemos: number;
};

function state(order: SandboxOrder): State {
  return runSeeder<State>('capture-relink.php', ['state', `--order=${order.orderId}`]);
}

function magento(args: string[]): string {
  return execFileSync('docker', ['exec', config.container, 'bin/magento', ...args], { encoding: 'utf8' });
}

async function adminApi(): Promise<APIRequestContext> {
  const anonymous = await request.newContext({ baseURL: config.baseURL, ignoreHTTPSErrors: true });
  const token = await (await anonymous.post('/rest/V1/integration/admin/token', {
    data: { username: config.adminUser, password: config.adminPassword },
  })).json();
  await anonymous.dispose();

  return request.newContext({
    baseURL: config.baseURL,
    ignoreHTTPSErrors: true,
    extraHTTPHeaders: { Authorization: `Bearer ${token}` },
  });
}

/** Qliro takes one transaction on an order at a time, the next waits until it settles */
async function post(api: APIRequestContext, url: string, data: object): Promise<APIResponse> {
  for (let attempt = 1; ; attempt++) {
    const response = await api.post(url, { data });
    if (response.ok() || attempt === 6 || !(await response.text()).includes('already in process')) {
      return response;
    }
    await new Promise(resolve => setTimeout(resolve, 10_000));
  }
}

/** Invoices over REST, as an ERP does, which captures at Qliro */
async function invoice(api: APIRequestContext, order: SandboxOrder, qty: number): Promise<void> {
  const response = await post(api, `/rest/V1/order/${order.orderId}/invoice`, {
    capture: true,
    items: [{ order_item_id: order.itemId, qty }],
  });
  expect(response.ok(), await response.text()).toBeTruthy();
}

function refund(api: APIRequestContext, invoiceId: number): Promise<APIResponse> {
  return post(api, `/rest/V1/invoice/${invoiceId}/refund`, { isOnline: true, notify: false });
}

function captures(current: State): { id: number; amount: number }[] {
  return current.atQliro.filter(row => row.type === 'Capture' && row.status !== 'Error');
}

test.describe('Invoices recorded on the preauthorization', () => {
  test.skip(!enabled, 'needs a Qliro test merchant, set QLIRO_CHECKOUT_E2E=1');

  test('cannot be refunded until the command moves them onto the capture', async ({ page }) => {
    const order = runSeeder<SandboxOrder>('seed-sandbox-order.php', ['--qty=2']);
    const api = await adminApi();
    await invoice(api, order, order.qty);

    const captured = state(order);
    const capture = captures(captured)[0];
    expect(captured.invoices[0].transactionId).toBe(String(capture.id));

    const { preauthorization } = runSeeder<{ preauthorization: number }>('capture-relink.php', ['break', `--order=${order.orderId}`]);
    expect(state(order).invoices[0].transactionId).toBe(String(preauthorization));

    // What the merchant saw: Qliro refuses a refund against the preauthorization
    expect((await refund(api, captured.invoices[0].id)).ok()).toBeFalsy();
    expect(state(order).creditMemos).toBe(0);

    const dryRun = magento(['qliroone:capture:relink', `--order=${order.incrementId}`, '--dry-run']);
    expect(dryRun).toContain(`transaction ${preauthorization}: relinked ${capture.id}`);
    expect(dryRun).toContain('Would relink 1,');
    expect(state(order).invoices[0].transactionId).toBe(String(preauthorization));

    expect(magento(['qliroone:capture:relink', `--order=${order.incrementId}`])).toContain('Relinked 1,');

    const relinked = state(order);
    expect(relinked.invoices[0].transactionId).toBe(String(capture.id));
    expect(relinked.transactions).toContainEqual(expect.objectContaining({
      txnId: String(capture.id),
      type: 'capture',
      capturedAmount: capture.amount,
    }));
    expect(relinked.comments.join('\n')).toContain(`now carries Qliro capture ${capture.id} instead of ${preauthorization}`);
    // Nothing left to relink on the order
    expect(magento(['qliroone:capture:relink', `--order=${order.incrementId}`])).toContain('Relinked 0, captured amount recorded 0, left on their capture 0');

    const refunded = await refund(api, relinked.invoices[0].id);
    expect(refunded.ok(), await refunded.text()).toBeTruthy();
    expect(state(order).creditMemos).toBe(1);
    // Qliro books a refund through AddItemsToInvoice as UpdateInvoice of a negative amount
    expect(state(order).atQliro).toContainEqual(expect.objectContaining({ type: 'UpdateInvoice', amount: -order.grandTotal }));
    await api.dispose();

    await loginToAdmin(page);
    await openAdminOrder(page, order.orderId);
    await expect(page.locator('#order_history_block')).toContainText(`now carries Qliro capture ${capture.id}`);
  });

  test('two invoices on one preauthorization get a capture each', async () => {
    const order = runSeeder<SandboxOrder>('seed-sandbox-order.php', ['--qty=2']);
    const api = await adminApi();
    await invoice(api, order, 1);
    await invoice(api, order, 1);

    const captured = state(order);
    expect(captured.invoices).toHaveLength(2);
    const byInvoice = captured.invoices.map(row => row.transactionId);
    expect(new Set(byInvoice).size).toBe(2);

    runSeeder('capture-relink.php', ['break', `--order=${order.orderId}`]);
    expect(new Set(state(order).invoices.map(row => row.transactionId)).size).toBe(1);

    expect(magento(['qliroone:capture:relink', `--order=${order.incrementId}`])).toContain('Relinked 2,');

    const relinked = state(order);
    // Each invoice back on the capture of its own amount, the shipping went with the first
    expect(relinked.invoices.map(row => row.transactionId)).toEqual(byInvoice);
    for (const row of relinked.invoices) {
      expect(relinked.transactions).toContainEqual(expect.objectContaining({
        txnId: row.transactionId,
        type: 'capture',
        capturedAmount: row.grandTotal,
      }));
    }

    const refunded = await refund(api, relinked.invoices[1].id);
    expect(refunded.ok(), await refunded.text()).toBeTruthy();
    expect(state(order).atQliro).toContainEqual(expect.objectContaining({
      type: 'UpdateInvoice',
      amount: -relinked.invoices[1].grandTotal,
    }));
    // The order is refunded in part now, so a later run leaves it alone
    runSeeder('capture-relink.php', ['break', `--order=${order.orderId}`]);
    expect(magento(['qliroone:capture:relink', `--order=${order.incrementId}`])).toContain('Relinked 0, captured amount recorded 0, left on their capture 0, not settled at Qliro 0, to check by hand 2');
    await api.dispose();
  });
});
