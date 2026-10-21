import { execFileSync } from 'node:child_process';
import { APIRequestContext, expect, request, test } from '@playwright/test';

import { config } from '../playwright.config';
import { runSeeder } from '../seeder';
import { loginToAdmin, openAdminOrder } from './support';

/**
 * Needs Qliro itself, so it is off unless asked for. The store is on a Qliro test merchant on
 * staging with capture on shipment on, and has the fixture module QliroE2e_EarlyCaptureCallback.
 *
 * Qliro confirms a capture on shipment about half a second after MarkItemsAsShipped, while the
 * shipment is still being saved, so the callback found no record of the capture and the order was
 * never invoiced. The fixture module sends that callback from inside the shipment save, which is
 * where the sandbox cannot reach a local store. The capture itself is real.
 *
 * ```
 * QLIRO_CHECKOUT_E2E=1 MAGENTO_SKIP_SEED=1 npx playwright test capture-confirmed-early
 * ```
 */
const enabled = process.env.QLIRO_CHECKOUT_E2E === '1';
// The store as the container reaches it, the callback is sent from there
const internalUrl = process.env.MAGENTO_INTERNAL_URL ?? `https://${config.container.replace('-phpfpm-', '-app-')}:8443`;

type SandboxOrder = { orderId: number; incrementId: string; qliroOrderId: number; grandTotal: number; itemId: number; qty: number };
type OmRow = { transaction_id: string; record_type: string | null; transaction_status: string; notification_status: string };
type OrderState = {
  state: string;
  status: string;
  shipments: number;
  invoices: { grandTotal: number; transactionId: string; state: number }[];
  totalPaid: number;
  omRows: OmRow[];
};

function helper<T>(args: string[]): T {
  return runSeeder<T>('capture-reconciliation.php', args);
}

function orderState(order: SandboxOrder): OrderState {
  return helper<OrderState>(['state', `--order=${order.orderId}`]);
}

function magento(args: string[]): string {
  return execFileSync('docker', ['exec', config.container, 'bin/magento', ...args], { encoding: 'utf8' });
}

function askForEarlyCallback(order: SandboxOrder): void {
  execFileSync('docker', [
    'exec', config.container, 'sh', '-c',
    `mkdir -p var/qliro-e2e && echo '${JSON.stringify({ url: internalUrl })}' > var/qliro-e2e/early-callback-${order.incrementId}.json`,
  ]);
}

function earlyCallbackResult(order: SandboxOrder): { status: number; response: { CallbackResponse: string } } {
  return JSON.parse(execFileSync(
    'docker',
    ['exec', config.container, 'cat', `var/qliro-e2e/early-callback-${order.incrementId}.result.json`],
    { encoding: 'utf8' }
  ));
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

/** Ships the whole order the way an ERP does, over REST, which captures it at Qliro */
async function shipOverRest(order: SandboxOrder): Promise<void> {
  const api = await adminApi();
  const response = await api.post(`/rest/V1/order/${order.orderId}/ship`, {
    data: { items: [{ order_item_id: order.itemId, qty: order.qty }], notify: false },
  });
  expect(response.ok(), await response.text()).toBeTruthy();
  await api.dispose();
}

/** A shipped order whose capture Qliro confirmed before the shipment was committed */
async function shippedWithEarlyCallback(): Promise<SandboxOrder> {
  const order = runSeeder<SandboxOrder>('seed-sandbox-order.php', ['--qty=2']);
  askForEarlyCallback(order);
  await shipOverRest(order);

  const callback = earlyCallbackResult(order);
  expect(callback.status).toBe(200);
  expect(callback.response.CallbackResponse).toBe('received');

  const state = orderState(order);
  const capture = state.omRows.find(row => row.record_type === 'shipment' && row.transaction_status === 'Created');
  expect(capture, 'the shipment capture is recorded').toBeTruthy();
  // The callback arrived before the capture record was committed, which is the bug's condition
  expect(state.omRows).toContainEqual(expect.objectContaining({
    transaction_id: capture!.transaction_id,
    record_type: null,
    transaction_status: 'Success',
  }));
  expect(state.shipments).toBe(1);
  expect(state.invoices).toHaveLength(0);

  return order;
}

test.describe('Capture on shipment confirmed before the shipment is saved', () => {
  test.skip(!enabled, 'needs a Qliro test merchant, set QLIRO_CHECKOUT_E2E=1');

  test('the cron job invoices the shipment from what Qliro holds', async ({ page }) => {
    const order = await shippedWithEarlyCallback();

    helper(['cron']);

    const state = orderState(order);
    expect(state.invoices).toHaveLength(1);
    expect(state.invoices[0].grandTotal).toBeCloseTo(order.grandTotal, 2);
    expect(state.invoices[0].transactionId).toBe(
      state.omRows.find(row => row.record_type === 'shipment')!.transaction_id
    );
    expect(state.totalPaid).toBeCloseTo(order.grandTotal, 2);
    expect(state.state).toBe('complete');

    // Qliro's own callback arriving late, and the next run, must not invoice it again
    expect(helper<{ status: number }>(['resend', `--order=${order.orderId}`, `--url=${internalUrl}`]).status).toBe(200);
    helper(['cron']);
    expect(orderState(order).invoices).toHaveLength(1);

    await loginToAdmin(page);
    await openAdminOrder(page, order.orderId);
    await expect(page.locator('#order_status')).toHaveText('Complete');
    await expect(page.locator('#order_history_block')).toContainText('Capture of');
    await page.click('#sales_order_view_tabs_order_invoices');
    await expect(page.locator('#sales_order_view_tabs_order_invoices_content tbody tr')).toHaveCount(1);
  });

  test('older captures are left to the command, which a dry run does not apply', async () => {
    const order = await shippedWithEarlyCallback();
    helper(['age', `--order=${order.orderId}`, '--days=4']);

    helper(['cron']);
    expect(orderState(order).invoices, 'the job reads the last three days only').toHaveLength(0);

    const dryRun = magento(['qliroone:capture:reconcile', `--order=${order.incrementId}`, '--dry-run']);
    expect(dryRun).toContain('Would apply 1');
    expect(orderState(order).invoices).toHaveLength(0);

    const run = magento(['qliroone:capture:reconcile', `--order=${order.incrementId}`]);
    expect(run).toContain('Applied 1, not settled at Qliro 0, to check by hand 0, failed 0');

    const state = orderState(order);
    expect(state.invoices).toHaveLength(1);
    expect(state.state).toBe('complete');

    expect(magento(['qliroone:capture:reconcile', `--order=${order.incrementId}`])).toContain('Applied 0');
  });
});
