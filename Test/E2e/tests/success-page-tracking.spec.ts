import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { test, expect, Page, BrowserContext } from '@playwright/test';
import { config } from '../playwright.config';
import { seeded } from './support';

/**
 * The purchase Magento's own GA4 module pushes to `window.dataLayer` on the success page, read
 * from the page the way a tag manager would. The fixture module QliroE2e_SuccessPage stands in for
 * the last step of PollPending and puts a seeded order on the success session.
 */
const SUCCESS = '/checkout/qliro/success';
const DISPLAYED = '/checkout/qliro_ajax/successDisplayed';
const CORE_EVENT = 'checkout_onepage_controller_success_action';
const REPEAT_EVENT = 'qliroone_checkout_success_repeat';

/** The fixture answers only to this run's secret, left in the store's var/ directory. */
const SECRET = randomUUID();

test.beforeAll(() => {
  execFileSync('docker', ['exec', config.container, 'sh', '-c', `printf %s ${SECRET} > /var/www/html/var/qliro-e2e-secret`]);
});

test.afterAll(() => {
  execFileSync('docker', ['exec', config.container, 'rm', '-f', '/var/www/html/var/qliro-e2e-secret']);
});

async function prime(context: BrowserContext, incrementId: string): Promise<void> {
  const response = await context.request.get(`/qliroe2e/success/prime?order=${incrementId}`, {
    headers: { 'X-Qliro-E2e-Secret': SECRET },
  });
  expect(await response.json()).toEqual({ primed: true });
}

/** What a server-side listener saw, recorded by the fixture module per order. */
function serverEvents(orderId: number): string[] {
  const output = execFileSync(
    'docker',
    ['exec', config.container, 'sh', '-c', `cat /var/www/html/var/qliro-e2e/success-events-${orderId}.log 2>/dev/null || true`],
    { encoding: 'utf8' }
  );

  return output.split('\n').filter(Boolean);
}

async function purchases(page: Page): Promise<string[]> {
  return page.evaluate(() =>
    ((window as any).dataLayer ?? [])
      .filter((entry: any) => entry[0] === 'event' && entry[1] === 'purchase')
      .map((entry: any) => entry[2].transaction_id)
  );
}

/** Opens the success page and waits for Magento's widgets, which push the purchase, to run. */
async function openSuccess(page: Page): Promise<void> {
  await page.goto(SUCCESS);
  await expect(page.locator('.qliroone-checkout-success')).toBeVisible();
  await page.waitForFunction(() => Array.isArray((window as any).dataLayer));
}

test.beforeEach(async ({ context }) => {
  // gtag.js itself is not needed, the push to dataLayer happens without it
  await context.route(/googletagmanager\.com|google-analytics\.com/, (route) => route.abort());
});

test('the page a buyer sees fires the purchase once', async ({ context, page }) => {
  const order = seeded.withName.incrementId;
  await prime(context, order);

  const reported = page.waitForResponse((response) => response.url().includes(DISPLAYED));
  await openSuccess(page);
  expect(await purchases(page)).toEqual([order]);
  // the body of a keepalive request is not readable here, the reload below shows what it did
  expect((await reported).request().postData()).toBe(`order=${seeded.withName.orderId}`);

  await openSuccess(page);
  expect(await purchases(page)).toEqual([]);
  expect(serverEvents(seeded.withName.orderId)).toEqual([CORE_EVENT]);
});

test('a render no browser ran leaves the purchase for the page the buyer sees', async ({ context, page }) => {
  const order = seeded.withName.incrementId;
  await prime(context, order);

  // a request that reached the store but whose page never ran, the tab discarded in the background
  const lost = await context.request.get(SUCCESS);
  expect(await lost.text()).toContain(`"transaction_id":"${order}"`);

  await openSuccess(page);
  expect(await purchases(page)).toEqual([order]);
  // core's event stays once per order, the page gets the order through the repeat
  expect(serverEvents(seeded.withName.orderId)).toEqual([CORE_EVENT, REPEAT_EVENT]);
});

test('a report that fails is sent again', async ({ context, page }) => {
  const order = seeded.withName.incrementId;
  await prime(context, order);

  let calls = 0;
  await page.route((url) => url.pathname.includes(DISPLAYED), async (route) => {
    if (calls++ === 0) {
      await route.fulfill({ status: 503, body: '' });
    } else {
      await route.continue();
    }
  });

  const reported = page.waitForResponse(
    (response) => response.url().includes(DISPLAYED) && response.status() === 200
  );
  await openSuccess(page);
  expect(await purchases(page)).toEqual([order]);
  // the body of a keepalive request is not readable here, the reload below shows what it did
  expect((await reported).request().postData()).toBe(`order=${seeded.withName.orderId}`);

  const reportedAgain = page.waitForResponse((response) => response.url().includes(DISPLAYED));
  await openSuccess(page);
  expect(await purchases(page)).toEqual([]);
  await reportedAgain;
  // the refused one and its retry, then the report of the reload
  expect(calls).toBe(3);
});

test('a page that can never report repeats the purchase on every render', async ({ context, page }) => {
  const order = seeded.withName.incrementId;
  await prime(context, order);
  await page.route((url) => url.pathname.includes(DISPLAYED), (route) => route.abort());

  // two renders nobody saw are as likely as one, GA4 deduplicates the repeats by transaction_id
  await openSuccess(page);
  expect(await purchases(page)).toEqual([order]);
  await openSuccess(page);
  expect(await purchases(page)).toEqual([order]);
  await openSuccess(page);
  expect(await purchases(page)).toEqual([order]);
  expect(serverEvents(seeded.withName.orderId)).toEqual([CORE_EVENT, REPEAT_EVENT, REPEAT_EVENT]);
});

test('the fixture refuses a caller without the run secret', async ({ context }) => {
  const response = await context.request.get(`/qliroe2e/success/prime?order=${seeded.withName.incrementId}`);
  expect(response.status()).toBe(404);
});

test('the same order polled again from another tab stays reported', async ({ context, page }) => {
  const order = seeded.withName.incrementId;
  await prime(context, order);

  const reported = page.waitForResponse((response) => response.url().includes(DISPLAYED));
  await openSuccess(page);
  await reported;

  await prime(context, order);
  await openSuccess(page);
  expect(await purchases(page)).toEqual([]);
});

test('a report for an older order leaves the newer one alone', async ({ context, page }) => {
  const newer = seeded.withoutName.incrementId;
  await prime(context, newer);

  const response = await context.request.post(DISPLAYED, {
    form: { order: String(seeded.withName.orderId) },
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(await response.json()).toEqual({ displayed: false });

  await openSuccess(page);
  expect(await purchases(page)).toEqual([newer]);
});
