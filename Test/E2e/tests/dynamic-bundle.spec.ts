import { execFileSync } from 'node:child_process';
import { APIRequestContext, expect, request, test } from '@playwright/test';

import { config } from '../playwright.config';
import { runSeeder } from '../seeder';

/**
 * Needs Qliro itself, so it is off unless asked for. The store is on a Qliro test merchant on
 * staging and taxes Taxable Goods in Sweden.
 *
 * A bundle priced by its children goes to Qliro as a zero bundle line and its children. The child
 * at 4.79 used to carry 25.05, read back from its rounded prices, the children went out for one
 * bundle whatever the cart held, and a capture sent the zero line without the children, so Qliro
 * took the shipping only while Magento recorded the whole invoice.
 *
 * ```
 * QLIRO_CHECKOUT_E2E=1 MAGENTO_SKIP_SEED=1 npx playwright test dynamic-bundle
 * ```
 */
const enabled = process.env.QLIRO_CHECKOUT_E2E === '1';

type QliroLine = { reference: string; quantity: number; price: number; vatRate: number };
type SandboxOrder = { orderId: number; qliroOrderId: number; grandTotal: number; qliroLines: QliroLine[] };
type Totals = { reserved: number; captured: number };

function magento(args: string[]): string {
  return execFileSync('docker', ['exec', config.container, 'bin/magento', ...args], { encoding: 'utf8' });
}

function captureMode(onInvoice: boolean, onShipment: boolean): void {
  magento(['config:set', 'payment/qliroone/api/capture_on_invoice', onInvoice ? '1' : '0']);
  magento(['config:set', 'payment/qliroone/api/capture_on_shipment', onShipment ? '1' : '0']);
  magento(['cache:clean', 'config']);
}

function totals(order: SandboxOrder): Totals {
  return runSeeder<Totals>('qliro-transactions.php', [`--qliro-order=${order.qliroOrderId}`]);
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

async function post(path: string, data: object): Promise<void> {
  const api = await adminApi();
  const response = await api.post(path, { data });
  expect(response.ok(), await response.text()).toBeTruthy();
  await api.dispose();
}

async function expectCapturedInFull(order: SandboxOrder): Promise<void> {
  await expect.poll(() => totals(order).captured, { timeout: 60_000 }).toBeCloseTo(order.grandTotal, 2);
}

test.describe('A bundle priced by its children', () => {
  test.skip(!enabled, 'needs a Qliro test merchant, set QLIRO_CHECKOUT_E2E=1');

  let saved: string[] = [];

  test.beforeAll(() => {
    saved = ['capture_on_invoice', 'capture_on_shipment'].map(
      key => magento(['config:show', `payment/qliroone/api/${key}`]).trim()
    );
  });

  test.afterAll(() => {
    captureMode(saved[0] === '1', saved[1] === '1');
  });

  test('reaches Qliro with the children of every bundle at the rate Magento taxed them', () => {
    const order = runSeeder<SandboxOrder>('seed-sandbox-order.php', ['--bundle', '--qty=2']);
    const children = order.qliroLines.filter(line => /:QLIRO-E2E-BUNDLE-[AB]$/.test(line.reference));

    expect(children.map(line => [line.quantity, line.price, line.vatRate])).toEqual([[2, 5.99, 25], [2, 42.5, 25]]);
    expect(totals(order).reserved).toBeCloseTo(order.grandTotal, 2);
  });

  test('is captured in full by its invoice', async () => {
    captureMode(true, false);
    const order = runSeeder<SandboxOrder>('seed-sandbox-order.php', ['--bundle', '--qty=1']);

    await post(`/rest/V1/order/${order.orderId}/invoice`, { capture: true, notify: false });

    await expectCapturedInFull(order);
  });

  test('is captured in full by a shipment that ships it together', async () => {
    captureMode(false, true);
    const order = runSeeder<SandboxOrder>('seed-sandbox-order.php', ['--bundle', '--qty=2']);

    await post(`/rest/V1/order/${order.orderId}/ship`, { notify: false });

    await expectCapturedInFull(order);
  });
});
