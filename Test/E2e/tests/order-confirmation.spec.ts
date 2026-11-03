import { APIRequestContext, expect, test } from '@playwright/test';
import { runSeeder } from '../seeder';

/**
 * The order confirmation goes out once Qliro approves the purchase, never while it is in review
 * or after a refusal. Orders are placed through the module with a Qliro order fixture, the later
 * status comes in on the store's own checkoutStatus callback. Qliro does not know these orders,
 * so the callback keeps the status it was sent.
 *
 * Reads the store's mail from a MailCatcher, `MAGENTO_MAIL_URL`, and needs
 * `Test/E2e/seed/order-confirmation.php` in the store's `var/` and `sales_email/general/async_sending 0`,
 * since a count of none is read right away.
 */
const mailUrl = process.env.MAGENTO_MAIL_URL ?? 'http://localhost:1090';

type Placed = { orderId: number; qliroOrderId: number; reference: string; email: string; callbackToken: string };
type OrderState = { state: string; status: string; emailSent: boolean };

function place(status: string): Placed {
  const email = `confirmation+${status.toLowerCase()}-${Date.now()}@example.com`;

  return runSeeder<Placed>('seed-qliro-order.php', [`--status=${status}`, `--email=${email}`]);
}

function orderState(orderId: number): OrderState {
  return runSeeder<OrderState>('order-confirmation.php', ['--do=state', `--order=${orderId}`]);
}

async function confirmations(request: APIRequestContext, email: string): Promise<number> {
  const messages: { recipients: string[]; subject: string }[] = await (await request.get(`${mailUrl}/messages`)).json();

  return messages.filter(message =>
    message.recipients.some(recipient => recipient.includes(email)) && /order confirmation/i.test(message.subject)
  ).length;
}

async function callback(request: APIRequestContext, order: Placed, status: string): Promise<void> {
  const response = await request.post(`/checkout/qliro_callback/checkoutStatus?token=${order.callbackToken}`, {
    data: { OrderId: order.qliroOrderId, MerchantReference: order.reference, Status: status },
  });
  expect(response.status(), await response.text()).toBe(200);
}

test.describe('the order confirmation', () => {
  test('goes out at placement when Qliro has approved the purchase', async ({ request }) => {
    const order = place('Completed');

    await expect.poll(() => confirmations(request, order.email)).toBe(1);
    expect(orderState(order.orderId).emailSent).toBe(true);

    await callback(request, order, 'Completed');
    expect(await confirmations(request, order.email)).toBe(1);
  });

  for (const status of ['InProcess', 'OnHold']) {
    test(`waits while Qliro reports ${status} and goes out once on Completed`, async ({ request }) => {
      const order = place(status);

      expect(await confirmations(request, order.email)).toBe(0);
      expect(orderState(order.orderId).emailSent).toBe(false);

      await callback(request, order, 'Completed');
      await expect.poll(() => confirmations(request, order.email)).toBe(1);
      expect(orderState(order.orderId).emailSent).toBe(true);

      // Qliro repeats its callbacks
      await callback(request, order, 'Completed');
      expect(await confirmations(request, order.email)).toBe(1);
    });
  }

  test('never goes out for a purchase Qliro refuses', async ({ request }) => {
    const order = place('OnHold');
    await callback(request, order, 'Refused');

    const state = orderState(order.orderId);
    expect(state.state).toBe('canceled');
    expect(state.emailSent).toBe(false);
    expect(await confirmations(request, order.email)).toBe(0);
  });

  test('goes out at placement for another payment method', async ({ request }) => {
    const email = `confirmation+checkmo-${Date.now()}@example.com`;
    runSeeder('order-confirmation.php', ['--do=other', `--email=${email}`]);

    await expect.poll(() => confirmations(request, email)).toBe(1);
  });
});
