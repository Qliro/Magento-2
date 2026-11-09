import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { test, expect, Page } from '@playwright/test';
import { config } from '../playwright.config';
import { runSeeder } from '../seeder';

/**
 * A customer removes the saved card of a subscription, against the Qliro sandbox: the card is
 * registered through Qliro's card form, an active subscription keeps it, and once the subscription
 * is cancelled the card goes and Qliro no longer has it.
 *
 * Needs the store pointed at a Qliro test merchant on staging, so it is skipped unless
 * QLIRO_CHECKOUT_E2E=1.
 */
const HISTORY = '/checkout/qliro_recurring/history/';
const RECURRING = 'payment/qliroone/recurring_payments/enable';

test.skip(process.env.QLIRO_CHECKOUT_E2E !== '1', 'needs a Qliro test merchant, QLIRO_CHECKOUT_E2E=1');

type Subscription = { orderId: number; incrementId: string; email: string; password: string };

function magento(...args: string[]): string {
  return execFileSync('docker', ['exec', config.container, 'bin/magento', ...args], { encoding: 'utf8' }).trim();
}

/** Fills Qliro's card form with the sandbox's 3DS-frictionless test card. */
async function registerCard(page: Page): Promise<string> {
  const registration = runSeeder<{ qliroOrderId: number; snippet: string }>('saved-card.php', ['register']);
  await page.setContent(`<!doctype html><html><body>${registration.snippet}</body></html>`);

  // the bootstrap script can render the form twice on setContent
  const form = page.frameLocator('iframe[src*="creditcardform"]').first();
  const pci = form.frameLocator('iframe[src*="pciIframe"]');
  await pci.first().locator('input[name="card.number"]').fill('4200000000000000');
  await form.locator('input[placeholder="MM/YY"]').fill('12/30');
  await pci.nth(1).locator('input[name="card.cvv"]').fill('123');
  await form.getByRole('button', { name: 'Add a card' }).click();

  let savedCardId: string | null = null;
  await expect.poll(() => {
    savedCardId = runSeeder<{ savedCardId: string | null }>(
      'saved-card.php', ['card', `--order=${registration.qliroOrderId}`]).savedCardId;
    return savedCardId;
  }, { timeout: 90_000, intervals: [3_000] }).not.toBeNull();

  return savedCardId!;
}

function row(page: Page, incrementId: string) {
  return page.locator('#my-orders-table tbody tr', { hasText: incrementId });
}

let previous: string;

test.beforeAll(() => {
  previous = magento('config:show', RECURRING).replace(/\D/g, '') || '0';
  magento('config:set', RECURRING, '1');
  magento('cache:clean', 'config', 'full_page');
});

test.afterAll(() => {
  magento('config:set', RECURRING, previous);
  magento('cache:clean', 'config', 'full_page');
});

test('a cancelled subscription gives its card up and Qliro deletes it', async ({ page }) => {
  const savedCardId = await registerCard(page);
  const subscription = runSeeder<Subscription>('saved-card.php', ['subscription', `--card=${savedCardId}`]);
  // A second subscription on the page: cancelling the first must leave it alone
  const other = runSeeder<Subscription>('saved-card.php',
    ['subscription', `--card=${randomUUID()}`, `--email=${subscription.email}`]);

  await page.goto('/customer/account/login/');
  await page.fill('#login-form #email', subscription.email);
  await page.fill('#login-form input[name="login[password]"]:visible', subscription.password);
  await page.click('#login-form button.action.login:visible');
  await expect(page).toHaveURL(/\/customer\/account\/?$/);

  // Active: the card is shown as saved and cannot be removed
  await page.goto(HISTORY, { waitUntil: 'networkidle' });
  const subscriptionRow = row(page, subscription.incrementId);
  await expect(subscriptionRow.locator('td.card')).toHaveText('Saved');
  await expect(subscriptionRow.locator('button.remove-saved-card')).toHaveCount(0);

  // Cancel asks first, and No keeps the subscription
  await subscriptionRow.locator('button.cancel-subscription').click();
  const modal = page.locator('.modal-popup._show');
  await expect(modal).toContainText('Are you sure you wish to cancel this subscription?');
  await modal.getByRole('button', { name: "No, don't cancel" }).click();
  await expect(subscriptionRow.locator('button.cancel-subscription')).toBeVisible();

  await subscriptionRow.locator('button.cancel-subscription').click();
  await page.locator('.modal-popup._show').getByRole('button', { name: 'Yes, cancel the subscription' }).click();
  await expect(page.locator('.message-success')).toContainText('Your subscription is now cancelled');
  await expect(row(page, subscription.incrementId).locator('button.cancel-subscription')).toHaveCount(0);
  await expect(row(page, other.incrementId).locator('button.cancel-subscription')).toBeVisible();

  await page.goto(HISTORY, { waitUntil: 'networkidle' });
  await row(page, subscription.incrementId).locator('button.remove-saved-card').click();
  const removeModal = page.locator('.modal-popup._show');
  await expect(removeModal).toContainText('Remove the saved card of this subscription?');
  await removeModal.getByRole('button', { name: 'Yes, remove the card' }).click();
  await expect(page.locator('.message-success')).toContainText('Your saved card has been removed');
  await expect(row(page, subscription.incrementId).locator('td.card')).toHaveText('Removed');

  // Qliro no longer has the card: asking again finds it already deleted
  expect(runSeeder<{ removed: boolean }>('saved-card.php', ['remove', `--card=${savedCardId}`]).removed).toBe(false);

  // The merchant sees it on the order
  await page.goto(`${config.adminPath}`);
  await page.fill('#username', config.adminUser);
  await page.fill('#login', config.adminPassword);
  await page.click('.action-login');
  await page.goto(`${config.adminPath}/sales/order/view/order_id/${subscription.orderId}/`);
  await expect(page.locator('#order_history_block, .order-history-block').first())
    .toContainText('The customer removed the saved card of this subscription. Qliro deleted it.');
});
