import { expect, test } from '@playwright/test';

import { config } from '../playwright.config';
import { runSeeder } from '../seeder';

/**
 * Qliro pushes a capture's status to the callback url registered when the order was created, with a
 * token signed by the API credentials of that moment. After the store changes its key or secret,
 * that push is accepted for the configured days, and a refused one is logged at warning with the
 * reason, at most once an hour per reason.
 * Needs `Test/E2e/seed/callback-credentials.php`, `capture-hold.php` and `seed-qliro-order.php` in the store's `var/`.
 */
type Seeded = { orderId: number };
type Push = { status: number; response: { CallbackResponse: string } };
type Line = { level: string; message: string };

const internalUrl = process.env.MAGENTO_INTERNAL_URL ?? `https://${config.container.replace('-phpfpm-', '-app-')}:8443`;
const RECEIVED = 'received';
const REFUSED = 'Authenticate error';

function helper<T>(...args: string[]): T {
  return runSeeder<T>('callback-credentials.php', args);
}

function token(): string {
  return helper<{ token: string }>('token').token;
}

function mark(): number {
  return helper<{ id: number }>('mark').id;
}

function lines(after: number): Line[] {
  return helper<{ lines: Line[] }>('log', `--after=${after}`).lines;
}

function save(key: string, secret: string, type?: string): void {
  helper('save', `--key=${key}`, `--secret=${secret}`, ...(type ? [`--type=${type}`] : []));
}

function days(value: number): void {
  helper('days', `--value=${value}`);
}

let transaction = Date.now() % 1_000_000_000;

/** An order with a capture recorded, which Qliro's push reports on */
function capturedOrder(): { order: Seeded; transaction: number } {
  const order = runSeeder<Seeded>('seed-qliro-order.php', ['--qty=1']);
  transaction += 1;
  runSeeder('capture-hold.php', ['invoice', `--order=${order.orderId}`, `--transaction=${transaction}`]);

  return { order, transaction };
}

function push(seeded: { order: Seeded; transaction: number }, callbackToken: string): Push {
  return helper<Push>(
    'push',
    `--order=${seeded.order.orderId}`,
    `--transaction=${seeded.transaction}`,
    `--token=${callbackToken}`,
    `--url=${internalUrl}`
  );
}

test.describe('callbacks signed with replaced API credentials', () => {
  test.describe.configure({ mode: 'serial' });

  const suffix = Date.now().toString(36);
  const keyA = `e2e-key-a-${suffix}`;
  const keyB = `e2e-key-b-${suffix}`;
  const secretA = `e2e-secret-a-${suffix}`;
  const secretB = `e2e-secret-b-${suffix}`;

  test.beforeAll(() => {
    helper('backup');
  });

  test.afterAll(() => {
    helper('restore');
  });

  test.beforeEach(() => {
    helper('restore');
    helper('backup');
    days(90);
    save(keyA, secretA);
  });

  test('an older order still updates after the secret changes', () => {
    const seeded = capturedOrder();
    const older = token();

    save(keyA, secretB);
    const since = mark();
    const result = push(seeded, older);

    expect(result.status).toBe(200);
    expect(result.response.CallbackResponse).toBe(RECEIVED);
    expect(lines(since)).toEqual(
      expect.arrayContaining([expect.objectContaining({ level: 'INFO', message: expect.stringContaining('replaced at') })])
    );

    // A token of the new pair verifies as before
    expect(push(seeded, token()).response.CallbackResponse).toBe(RECEIVED);
  });

  test('an older order still updates after the key and secret change', () => {
    const seeded = capturedOrder();
    const older = token();

    save(keyB, secretB);

    expect(push(seeded, older).response.CallbackResponse).toBe(RECEIVED);
  });

  test('with the window off, the refusal is a warning naming the secret, once an hour', () => {
    const seeded = capturedOrder();
    const older = token();

    save(keyA, secretB);
    days(0);
    const since = mark();

    const first = push(seeded, older);
    const second = push(seeded, older);

    expect(first.status).toBe(400);
    expect(first.response.CallbackResponse).toBe(REFUSED);
    expect(second.response.CallbackResponse).toBe(REFUSED);

    const refusals = lines(since).filter((line) => line.message.includes('refused'));
    expect(refusals.map((line) => line.level)).toEqual(['WARNING', 'DEBUG']);
    expect(refusals[0].message).toContain('did not verify with the API secret (Signature verification failed.)');
    expect(JSON.stringify(refusals)).not.toContain(secretA);
    expect(JSON.stringify(refusals)).not.toContain(older);
  });

  test('with the window off, a changed key is named as the reason', () => {
    const seeded = capturedOrder();
    const older = token();

    save(keyB, secretB);
    days(0);
    const since = mark();

    expect(push(seeded, older).response.CallbackResponse).toBe(REFUSED);

    const refusals = lines(since).filter((line) => line.message.includes('refused'));
    expect(refusals[0]).toMatchObject({ level: 'WARNING', message: expect.stringContaining('another merchant API key') });
    expect(JSON.stringify(refusals)).not.toContain(keyA);
  });

  test('a malformed token is refused at debug', () => {
    const seeded = capturedOrder();
    const since = mark();

    expect(push(seeded, 'not.a.token').response.CallbackResponse).toBe(REFUSED);
    expect(lines(since).map((line) => line.level)).not.toContain('WARNING');
  });

  test('changing the API type drops the kept pair', () => {
    const seeded = capturedOrder();
    const older = token();

    save(keyA, secretB);
    expect(helper<{ count: number }>('replaced').count).toBeGreaterThan(0);

    save(keyA, '', 'prod');
    save(keyA, '', 'sandbox');

    expect(helper<{ count: number }>('replaced').count).toBe(0);
    expect(push(seeded, older).response.CallbackResponse).toBe(REFUSED);
  });

  test('going live in one save keeps no pair of the previous type', () => {
    const seeded = capturedOrder();
    const older = token();

    save(keyB, secretB, 'prod');

    expect(helper<{ count: number }>('replaced').count).toBe(0);
    expect(push(seeded, older).response.CallbackResponse).toBe(REFUSED);
  });

  test('saving the section without touching the credentials keeps nothing', () => {
    const before = helper<{ count: number }>('replaced').count;

    save(keyA, '');

    expect(helper<{ count: number; encrypted: boolean }>('replaced')).toEqual({ count: before, encrypted: true });
  });
});
