import { execFileSync } from 'node:child_process';

import { expect, test } from '@playwright/test';

import { config } from '../playwright.config';

/**
 * The second test that needs Qliro itself, so it is off unless asked for.
 *
 * Qliro withholds the buyer's address from the customer event, sending `{"isMasked": true}` in
 * its place until they identify, so the store can only learn it by reading the order back over
 * the merchant API. That read used to wait for the cart refresh the browser makes afterwards,
 * which left the timing of the whole delivery step to whichever script owns the customer
 * handler. On Vajper a mixin of the merchant's own polled an endpoint of its own for twelve
 * seconds first, and the widget had rendered the payment step before the shipping methods
 * arrived, so the buyer never saw a delivery to choose (PLIN-376).
 *
 * What is asserted is where the read happens, not what it found: the log line has to sit
 * between the two that bracket the customer request. A Qliro sandbox buyer never identifies,
 * because that needs BankID, so the order carries no address to bring back and only the
 * placement of the read can be checked from here.
 *
 * ```
 * QLIRO_CHECKOUT_E2E=1 QLIRO_E2E_PRODUCT_URL=/some-simple-product npx playwright test checkout-delivery-refresh
 * ```
 */
const enabled = process.env.QLIRO_CHECKOUT_E2E === '1';
const productUrl = process.env.QLIRO_E2E_PRODUCT_URL ?? '/';
const email = process.env.QLIRO_E2E_EMAIL ?? 'qliro-e2e@example.com';
const postcode = process.env.QLIRO_E2E_POSTCODE ?? '11329';

/** The database container, which is the app container's name with its role swapped by default. */
const dbContainer = process.env.MAGENTO_DB_CONTAINER ?? config.container.replace(/-phpfpm-(\d+)$/, '-db-$1');
const dbUser = process.env.MAGENTO_DB_USER ?? 'magento';
const dbPassword = process.env.MAGENTO_DB_PASSWORD ?? 'magento';
const dbName = process.env.MAGENTO_DB_NAME ?? 'magento';

function logMessagesSince(id: number): string[] {
  const sql = `select message from qliroone_log where id > ${id} order by id`;
  const output = execFileSync(
    'docker',
    ['exec', dbContainer, 'mysql', `-u${dbUser}`, `-p${dbPassword}`, dbName, '-N', '-B', '-e', sql],
    { encoding: 'utf8', maxBuffer: 10 * 1024 * 1024, stdio: ['ignore', 'pipe', 'ignore'] }
  );

  return output.split('\n').filter(Boolean);
}

function lastLogId(): number {
  const output = execFileSync(
    'docker',
    ['exec', dbContainer, 'mysql', `-u${dbUser}`, `-p${dbPassword}`, dbName, '-N', '-B', '-e', 'select max(id) from qliroone_log'],
    { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }
  );

  return Number.parseInt(output.trim(), 10) || 0;
}

test.describe('Qliro checkout, the delivery step', () => {
  test.skip(!enabled, 'needs a Qliro test merchant, set QLIRO_CHECKOUT_E2E=1');

  test('reads the order back while answering the customer event', async ({ browser }) => {
    const since = lastLogId();

    /*
     * A context of its own rather than the shared fixture: the store under test routes by
     * language and can answer a headless browser with a page whose scripts never run, and both
     * leave the cart somewhere other than where the checkout looks for it.
     */
    const context = await browser.newContext({
      baseURL: config.baseURL,
      ignoreHTTPSErrors: true,
      locale: process.env.QLIRO_E2E_LOCALE ?? 'sv-SE',
      userAgent:
        process.env.QLIRO_E2E_USER_AGENT ??
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36',
    });
    const page = await context.newPage();

    await page.goto(productUrl, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#product_addtocart_form');
    // Magento replaces the form key from its own storage after the page is up, and submitting
    // before that leaves the add rejected and the cart empty
    await page.waitForTimeout(4_000);

    await page.evaluate(() => {
      (document.querySelector('#product_addtocart_form') as HTMLFormElement).requestSubmit();
    });

    await page.waitForTimeout(10_000);

    await page.goto('/checkout/', { waitUntil: 'domcontentloaded' });
    expect(page.url(), 'the cart has to hold the product before the checkout means anything')
      .toContain('/checkout/qliro/');

    let frame = null as Awaited<ReturnType<typeof page.mainFrame>> | null;

    for (let attempt = 0; attempt < 45; attempt++) {
      frame = page.frames().find(candidate => /checkout\.(staging\.)?qliro\.com/.test(candidate.url())) ?? null;

      if (frame) {
        break;
      }

      await page.waitForTimeout(2_000);
    }

    expect(frame, 'the Qliro widget has to load before the rest means anything').toBeTruthy();

    const inputs = frame!.locator('input');
    await inputs.nth(0).pressSequentially(email, { delay: 30 });

    if ((await inputs.count()) > 1) {
      await inputs.nth(1).pressSequentially(postcode, { delay: 40 });
    }

    // Through the DOM, because the widget lays a dimmer over its own button while it works
    await frame!.evaluate(() => {
      const button = Array.from(document.querySelectorAll('button'))
        .find(candidate => /forts/i.test(candidate.textContent ?? ''));

      if (!button) {
        throw new Error('the widget offered no continue button');
      }

      button.click();
    });

    await page.waitForTimeout(15_000);

    const messages = logMessagesSince(since);
    const opened = messages.lastIndexOf('AJAX:UPDATE_CUSTOMER: <<< JSON payload has been received and processed.');

    expect(opened, `the customer event never reached the store, log said: ${messages.join(' | ')}`)
      .toBeGreaterThanOrEqual(0);

    const answered = messages.findIndex(
      (message, index) => index > opened && message.startsWith('AJAX:UPDATE_CUSTOMER: >>>')
    );
    const read = messages.findIndex(
      (message, index) => index > opened && message.startsWith('Read the QliroOne order back')
    );

    expect(read, 'the order was not read back while the customer event was being answered').toBeGreaterThan(opened);
    expect(
      answered === -1 || read < answered,
      'the read has to happen inside the customer request, not after it was answered'
    ).toBeTruthy();

    await context.close();
  });
});
