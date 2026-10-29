import { execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';

import { config } from '../playwright.config';
import { runSeeder } from '../seeder';

/**
 * A capture Qliro reports as Error holds the order, and only that capture succeeding releases it.
 * Two invoices are two captures. Qliro's transaction status callback is sent to the store the way
 * Qliro sends it, so the whole path from the callback runs. The invoices are offline: the capture
 * records are written the way the module writes them once Qliro accepted a capture. Capture on
 * shipment is turned off for the run, so the shipments call no Qliro, and restored afterwards.
 */
type Seeded = { orderId: number; incrementId: string };
type State = { state: string; status: string; holdBeforeStatus: string | null; invoiced: number; history: string[] };

// The store as the container reaches it, the callback is sent from there
const internalUrl = process.env.MAGENTO_INTERNAL_URL ?? `https://${config.container.replace('-phpfpm-', '-app-')}:8443`;
const WAREHOUSE = 'e2e_erp_warehouse';
const GONE = 'e2e_erp_gone';

function magento(args: string[]): string {
  return execFileSync('docker', ['exec', config.container, 'bin/magento', ...args], { encoding: 'utf8' }).trim();
}

function helper<T>(args: string[]): T {
  return runSeeder<T>('capture-hold.php', args);
}

function order(qty: number): Seeded {
  return runSeeder<Seeded>('seed-qliro-order.php', [`--qty=${qty}`]);
}

function state(seeded: Seeded): State {
  return helper<State>(['state', `--order=${seeded.orderId}`]);
}

function push(seeded: Seeded, transaction: number, status: string): void {
  const result = helper<{ status: number; response: { CallbackResponse: string } }>([
    'push', `--order=${seeded.orderId}`, `--transaction=${transaction}`, `--status=${status}`, `--url=${internalUrl}`,
  ]);
  expect(result.status).toBe(200);
  expect(result.response.CallbackResponse).toBe('received');
}

test.describe('A failed capture keeps the order on hold until it succeeds', () => {
  let captureOnShipment = '';

  test.beforeAll(() => {
    captureOnShipment = magento(['config:show', 'payment/qliroone/api/capture_on_shipment']) || '1';
    magento(['config:set', 'payment/qliroone/api/capture_on_shipment', '0']);
    helper(['assign', `--status=${WAREHOUSE}`, '--state=processing']);
    helper(['assign', `--status=${GONE}`, '--state=processing']);
  });

  test.afterAll(() => {
    magento(['config:set', 'payment/qliroone/api/capture_on_shipment', captureOnShipment]);
  });

  test('another capture succeeding keeps the hold, the failed one releases it', () => {
    const seeded = order(2);
    const [a, b] = [Date.now() % 1_000_000_000, (Date.now() % 1_000_000_000) + 1];
    helper(['invoice', `--order=${seeded.orderId}`, `--transaction=${a}`]);
    helper(['invoice', `--order=${seeded.orderId}`, `--transaction=${b}`]);
    helper(['erp', `--order=${seeded.orderId}`, `--status=${WAREHOUSE}`]);

    push(seeded, a, 'Error');
    expect(state(seeded)).toMatchObject({ state: 'holded', holdBeforeStatus: WAREHOUSE });

    push(seeded, b, 'Success');
    const kept = state(seeded);
    expect(kept.state).toBe('holded');
    expect(kept.history).toContain(`Capture ${b} succeeded, the order stays on hold: capture ${a} is still failed`);

    push(seeded, a, 'Success');
    const released = state(seeded);
    expect(released).toMatchObject({ state: 'processing', status: WAREHOUSE });
    expect(released.history).toContain(`Capture ${a} succeeded, the order is released from hold`);
  });

  test('a status no longer assigned to the state gives the state default and says so', () => {
    const seeded = order(1);
    const a = Date.now() % 1_000_000_000;
    helper(['invoice', `--order=${seeded.orderId}`, `--transaction=${a}`]);
    helper(['erp', `--order=${seeded.orderId}`, `--status=${GONE}`]);
    push(seeded, a, 'Error');
    helper(['unassign', `--status=${GONE}`, '--state=processing']);

    push(seeded, a, 'Success');

    const released = state(seeded);
    expect(released).toMatchObject({ state: 'processing', status: 'processing' });
    expect(released.history).toContain(
      `The status ${GONE} the order had before the hold is not assigned to state processing, so the order gets processing`
    );
  });

  test('shipment captures are invoiced on a held order, which stays held until the failed one succeeds', () => {
    const seeded = order(3);
    const [a, b] = [(Date.now() % 1_000_000_000) + 10, (Date.now() % 1_000_000_000) + 11];
    helper(['ship', `--order=${seeded.orderId}`, `--transaction=${a}`]);
    helper(['ship', `--order=${seeded.orderId}`, `--transaction=${b}`]);
    helper(['erp', `--order=${seeded.orderId}`, `--status=${WAREHOUSE}`]);

    push(seeded, a, 'Error');
    push(seeded, b, 'Success');
    const kept = state(seeded);
    expect(kept).toMatchObject({ state: 'holded', holdBeforeStatus: WAREHOUSE });
    expect(kept.invoiced).toBeGreaterThan(0);

    push(seeded, a, 'Success');
    const released = state(seeded);
    expect(released).toMatchObject({ state: 'processing', status: WAREHOUSE });
    expect(released.invoiced).toBeGreaterThan(kept.invoiced);
  });

  test('an Error after the same capture succeeded is ignored', () => {
    const seeded = order(1);
    const a = Date.now() % 1_000_000_000;
    helper(['invoice', `--order=${seeded.orderId}`, `--transaction=${a}`]);

    push(seeded, a, 'Success');
    push(seeded, a, 'Error');

    const after = state(seeded);
    expect(after.state).toBe('processing');
    expect(after.history).toContain(`Qliro One reported Error for capture ${a}, which already succeeded, so it is ignored`);
  });

  test('a release by hand ends the earlier failure', () => {
    const seeded = order(2);
    const [a, b] = [(Date.now() % 1_000_000_000) + 20, (Date.now() % 1_000_000_000) + 21];
    helper(['invoice', `--order=${seeded.orderId}`, `--transaction=${a}`]);
    helper(['invoice', `--order=${seeded.orderId}`, `--transaction=${b}`]);
    push(seeded, a, 'Error');
    helper(['unhold', `--order=${seeded.orderId}`]);

    push(seeded, b, 'Error');
    expect(state(seeded).state).toBe('holded');
    push(seeded, b, 'Success');

    expect(state(seeded).state).toBe('processing');
  });

  test('a hold the merchant placed stays when a capture succeeds', () => {
    const seeded = order(1);
    const a = Date.now() % 1_000_000_000;
    helper(['invoice', `--order=${seeded.orderId}`, `--transaction=${a}`]);
    helper(['hold', `--order=${seeded.orderId}`]);

    push(seeded, a, 'Success');

    expect(state(seeded).state).toBe('holded');
  });
});
