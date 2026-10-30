import { execFileSync } from 'node:child_process';
import { APIRequestContext, expect, test } from '@playwright/test';

import { config } from '../playwright.config';
import { runSeeder } from '../seeder';
import { loginToAdmin, openAdminOrder } from './support';

/**
 * An order never stands on a status that is not assigned to its state, and a checkout status Qliro
 * delivers again never moves an invoiced order back. Orders are placed through the module with a
 * Qliro order fixture, later statuses come in on the store's own callbacks.
 *
 * Needs `Test/E2e/seed/status-for-state.php` in the store's `var/`.
 */
type Placed = { orderId: number; qliroOrderId: number; reference: string; callbackToken: string };
type OrderState = { state: string; status: string; assigned: boolean; gridStatus: string };

function helper<T>(args: string[]): T {
  return runSeeder<T>('status-for-state.php', args);
}

function place(status: string): Placed {
  return helper<Placed>(['place', `--status=${status}`]);
}

function orderState(order: Placed): OrderState {
  return helper<OrderState>(['state', `--order=${order.orderId}`]);
}

/** The module's warnings about this order */
function warnings(order: Placed): string {
  return execFileSync(
    'docker',
    ['exec', config.container, 'sh', '-c', `grep -h 'WARNING.*"order_id":"${order.orderId}"' var/log/*.log || true`],
    { encoding: 'utf8', maxBuffer: 50 * 1024 * 1024 }
  );
}

async function checkoutStatus(request: APIRequestContext, order: Placed, status: string): Promise<void> {
  const response = await request.post(`/checkout/qliro_callback/checkoutStatus?token=${order.callbackToken}`, {
    data: { OrderId: order.qliroOrderId, MerchantReference: order.reference, Status: status },
  });
  expect(response.status(), await response.text()).toBe(200);
}

async function captureStatus(request: APIRequestContext, order: Placed, transactionId: string, status: string) {
  const response = await request.post(`/checkout/qliro_callback/transactionStatus?token=${order.callbackToken}`, {
    data: {
      OrderId: order.qliroOrderId,
      MerchantReference: order.reference,
      PaymentTransactionId: Number(transactionId),
      Status: status,
      Amount: 1,
      Currency: 'USD',
    },
  });
  expect(response.status(), await response.text()).toBe(200);
}

function expectAssigned(state: OrderState, expected: [string, string]): void {
  expect([state.state, state.status]).toEqual(expected);
  expect(state.assigned, `${state.state} / ${state.status} is assigned`).toBe(true);
  expect(state.gridStatus).toBe(expected[1]);
}

test.describe('the order status', () => {
  test('is pending payment while Qliro has not completed the purchase', async ({ page, request }) => {
    const order = place('InProcess');
    expectAssigned(orderState(order), ['pending_payment', 'pending_payment']);

    await loginToAdmin(page);
    await openAdminOrder(page, order.orderId);
    await expect(page.locator('#order_status')).toHaveText('Pending Payment');

    await checkoutStatus(request, order, 'Completed');
    expectAssigned(orderState(order), ['new', 'pending']);
  });

  test('of an invoiced order stays when Qliro repeats a checkout status', async ({ request }) => {
    const order = place('Completed');
    expectAssigned(orderState(order), ['new', 'pending']);
    helper(['invoice', `--order=${order.orderId}`]);
    expectAssigned(orderState(order), ['processing', 'processing']);

    for (const [status, state] of [['Completed', 'new'], ['OnHold', 'payment_review']]) {
      await checkoutStatus(request, order, status);
      expectAssigned(orderState(order), ['processing', 'processing']);
      expect(warnings(order)).toContain(`Order is already processing / processing, it is not moved back to ${state}`);
    }
  });

  test('of an invoiced order whose capture Qliro puts on hold is pending payment', async ({ request }) => {
    const order = place('Completed');
    helper(['invoice', `--order=${order.orderId}`]);
    const { transactionId } = helper<{ transactionId: string }>(['capture', `--order=${order.orderId}`]);

    await captureStatus(request, order, transactionId, 'OnHold');
    expectAssigned(orderState(order), ['pending_payment', 'pending_payment']);
  });

  test.describe('with a New Order Status not assigned to new', () => {
    let before = '';
    test.beforeAll(() => {
      before = helper<{ before: string }>(['new-status', '--status=processing']).before;
    });
    test.afterAll(() => helper(['new-status', `--status=${before}`]));

    test('is the default of new and a warning is logged', async ({ request }) => {
      const order = place('InProcess');
      await checkoutStatus(request, order, 'Completed');

      expectAssigned(orderState(order), ['new', 'pending']);
      expect(warnings(order)).toContain('Status processing is not assigned to state new, the order gets pending');
    });
  });
});
