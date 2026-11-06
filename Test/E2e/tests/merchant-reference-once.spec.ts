import { execFileSync } from 'node:child_process';
import { APIRequestContext, expect, test } from '@playwright/test';

import { config } from '../playwright.config';
import { runSeeder } from '../seeder';

/**
 * Needs Qliro itself, so it is off unless asked for. The store is on a Qliro test merchant on
 * staging with `use_increment_id_as_reference` off, and has the fixture module
 * QliroE2e_FailingOrderSave. Its order numbers must be new to the Qliro merchant.
 *
 * The module sets the Magento order number on the Qliro order once. Qliro repeats its status
 * callback until the store answers it, and a save of the order that failed after the call used to
 * lose the mark, so the next callback sent the number again and Qliro refused it as already set.
 *
 * ```
 * QLIRO_CHECKOUT_E2E=1 MAGENTO_SKIP_SEED=1 npx playwright test merchant-reference-once
 * ```
 */
const enabled = process.env.QLIRO_CHECKOUT_E2E === '1';

type SandboxOrder = { orderId: number; incrementId: string; qliroOrderId: number };
type Call = { outcome: 'ok' | 'refused' | 'pending'; level?: string; alreadySet?: boolean };
type State = { mark: boolean; state: string; calls: Call[]; criticals: number };

function state(order: SandboxOrder): State {
  return runSeeder<State>('merchant-reference.php', ['state', `--order=${order.orderId}`]);
}

async function callback(request: APIRequestContext, order: SandboxOrder): Promise<number> {
  const { token } = runSeeder<{ token: string }>('merchant-reference.php', ['token']);
  const response = await request.post(`/checkout/qliro_callback/checkoutStatus?token=${token}`, {
    data: { OrderId: order.qliroOrderId, MerchantReference: order.incrementId, Status: 'Completed' },
  });

  return response.status();
}

function failNextOrderSave(order: SandboxOrder): void {
  execFileSync('docker', [
    'exec', config.container, 'sh', '-c',
    `mkdir -p var/qliro-e2e && touch var/qliro-e2e/fail-order-save-${order.incrementId}`,
  ]);
}

test.describe('the merchant reference', () => {
  test.skip(!enabled, 'needs a Qliro test merchant, set QLIRO_CHECKOUT_E2E=1');

  let order: SandboxOrder;

  test.beforeEach(() => {
    order = runSeeder<SandboxOrder>('seed-sandbox-order.php', ['--qty=1']);
  });

  test('goes to Qliro once, whatever callbacks follow', async ({ request }) => {
    expect(await callback(request, order)).toBe(200);
    expect(await callback(request, order)).toBe(200);

    const after = state(order);
    expect(after.calls).toEqual([{ outcome: 'ok' }]);
    expect(after.mark).toBe(true);
    expect(after.criticals).toBe(0);
  });

  test('is not sent again when the order save after the call failed', async ({ request }) => {
    // The order already carries its number at Qliro, the mark is gone as on a store that lost it
    runSeeder<State>('merchant-reference.php', ['forget', `--order=${order.orderId}`]);
    const before = state(order).calls.length;
    failNextOrderSave(order);

    // The status update fails and Qliro gets told to call back
    expect(await callback(request, order)).toBe(500);
    expect(await callback(request, order)).toBe(200);

    const after = state(order);
    const calls = after.calls.slice(before);
    expect(calls).toHaveLength(1);
    // Qliro refuses a number the order already carries, which is the outcome asked for
    expect(calls[0]).toEqual({ outcome: 'refused', level: 'info', alreadySet: true });
    expect(after.criticals).toBe(0);
    expect(after.mark).toBe(true);
    expect(after.state).toBe('new');
  });
});
