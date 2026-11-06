import { execFileSync } from 'node:child_process';
import { APIRequestContext, APIResponse, Page, expect, request, test } from '@playwright/test';

import { config } from '../playwright.config';
import { runSeeder } from '../seeder';
import { loginToAdmin, openAdminOrder } from './support';

/**
 * Needs Qliro itself, so it is off unless asked for. The store is on a Qliro test merchant on
 * staging with capture on invoice and on shipment on, and has the fixture module
 * QliroE2e_LostCaptureAnswer. `MAGENTO_INTERNAL_URL` is the store as the container reaches it,
 * when it is not the app container on port 8443.
 *
 * The fixture sends the first capture to Qliro for real and then fails the call as a timeout does.
 * The document is rolled back, Qliro's notification of the capture arrives, and the merchant sends
 * the document again under the same RequestId, which Qliro refuses as DUPLICATE_REQUEST. The retry
 * has to complete with the capture already made.
 *
 * ```
 * QLIRO_CHECKOUT_E2E=1 MAGENTO_SKIP_SEED=1 npx playwright test capture-retry
 * ```
 */
const enabled = process.env.QLIRO_CHECKOUT_E2E === '1';
// The store as the container reaches it, Qliro's notification is sent from there
const internalUrl = process.env.MAGENTO_INTERNAL_URL ?? `https://${config.container.replace('-phpfpm-', '-app-')}:8443`;

type SandboxOrder = { orderId: number; qliroOrderId: number; grandTotal: number; itemId: number; qty: number };
type OmRow = { transaction_id: string; record_type: string | null; transaction_status: string };
type OrderState = {
  shipments: number;
  invoices: { grandTotal: number; transactionId: string }[];
  totalPaid: number;
  omRows: OmRow[];
};
type QliroCaptures = { captures: { transactionId: number; status: string; amount: number }[] };
type LostAnswer = { transactionId: number; status: string };

function orderState(order: SandboxOrder): OrderState {
  return runSeeder<OrderState>('capture-reconciliation.php', ['state', `--order=${order.orderId}`]);
}

function capturesAtQliro(order: SandboxOrder): QliroCaptures['captures'] {
  return runSeeder<QliroCaptures>('capture-retry.php', ['captures', `--order=${order.orderId}`]).captures;
}

/** Qliro's own notification of the lost capture, which reaches a live store before the merchant retries */
function notifyCapture(order: SandboxOrder, lost: LostAnswer): void {
  const sent = runSeeder<{ status: number }>('capture-retry.php', [
    'notify', `--order=${order.orderId}`, `--transaction=${lost.transactionId}`,
    `--amount=${order.grandTotal}`, `--url=${internalUrl}`,
  ]);
  expect(sent.status).toBe(200);
  expect(orderState(order).omRows).toEqual([
    expect.objectContaining({ transaction_id: String(lost.transactionId), record_type: null }),
  ]);
}

function loseNextCaptureAnswer(order: SandboxOrder): void {
  execFileSync('docker', [
    'exec', config.container, 'sh', '-c',
    `mkdir -p var/qliro-e2e && touch var/qliro-e2e/lose-capture-${order.qliroOrderId}`,
  ]);
}

function lostAnswer(order: SandboxOrder): LostAnswer {
  return JSON.parse(execFileSync(
    'docker',
    ['exec', config.container, 'cat', `var/qliro-e2e/lose-capture-${order.qliroOrderId}.result.json`],
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

/** Invoices the whole order in the admin, which captures it online and shows the module's message */
async function invoiceInAdmin(page: Page, order: SandboxOrder): Promise<string> {
  await openAdminOrder(page, order.orderId);
  await page.click('#order_invoice');
  await expect(page.locator('#invoice_item_container').first()).toBeVisible();

  const captureCase = page.locator('select[name="invoice[capture_case]"]');

  if (await captureCase.count()) {
    await captureCase.selectOption('online');
  }

  await page.click('button.submit-button, button[title="Submit Invoice"]');
  await expect(page.locator('#messages .message')).toBeVisible();

  return (await page.locator('#messages').innerText()).trim();
}

async function ship(api: APIRequestContext, order: SandboxOrder): Promise<APIResponse> {
  return api.post(`/rest/V1/order/${order.orderId}/ship`, {
    data: { items: [{ order_item_id: order.itemId, qty: order.qty }], notify: false },
  });
}

/** Qliro captured the first attempt while Magento kept nothing of it */
function capturedButNotKept(order: SandboxOrder): LostAnswer {
  const lost = lostAnswer(order);
  expect(lost.status).toBe('Created');
  expect(capturesAtQliro(order).map(capture => capture.transactionId)).toEqual([lost.transactionId]);

  const state = orderState(order);
  expect(state.invoices).toHaveLength(0);
  expect(state.shipments).toBe(0);
  expect(state.omRows).toHaveLength(0);

  return lost;
}

test.describe('A capture sent again after its answer was lost', () => {
  test.skip(!enabled, 'needs a Qliro test merchant, set QLIRO_CHECKOUT_E2E=1');

  test('the invoice completes with the capture Qliro already made', async ({ page }) => {
    const order = runSeeder<SandboxOrder>('seed-sandbox-order.php', ['--qty=2']);
    await loginToAdmin(page);

    loseNextCaptureAnswer(order);
    expect(await invoiceInAdmin(page, order)).toContain('may have gone through');
    const lost = capturedButNotKept(order);
    notifyCapture(order, lost);

    expect(await invoiceInAdmin(page, order)).toContain('The invoice has been created.');

    const state = orderState(order);
    expect(state.invoices).toHaveLength(1);
    expect(state.invoices[0].transactionId).toBe(String(lost.transactionId));
    expect(state.totalPaid).toBeCloseTo(order.grandTotal, 2);
    expect(state.omRows).toContainEqual(
      expect.objectContaining({ transaction_id: String(lost.transactionId), record_type: 'payment' })
    );
    expect(capturesAtQliro(order), 'Qliro captured once').toHaveLength(1);
  });

  test('the shipment completes with the capture Qliro already made', async () => {
    const order = runSeeder<SandboxOrder>('seed-sandbox-order.php', ['--qty=2']);
    const api = await adminApi();

    loseNextCaptureAnswer(order);
    // REST hides the module's message behind "Could not save a shipment"
    expect((await ship(api, order)).ok(), 'the lost answer fails the shipment').toBeFalsy();
    const lost = capturedButNotKept(order);
    notifyCapture(order, lost);

    const retry = await ship(api, order);
    expect(retry.ok(), await retry.text()).toBeTruthy();
    await api.dispose();

    const state = orderState(order);
    expect(state.shipments).toBe(1);
    expect(state.omRows).toContainEqual(
      expect.objectContaining({ transaction_id: String(lost.transactionId), record_type: 'shipment' })
    );
    expect(capturesAtQliro(order), 'Qliro captured once').toHaveLength(1);
  });
});
