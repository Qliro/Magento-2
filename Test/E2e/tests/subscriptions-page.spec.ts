import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { test, expect } from '@playwright/test';
import { config } from '../playwright.config';

/**
 * The customer's subscriptions page. A guest used to get a 500 there, "Invalid return type".
 */
const HISTORY = '/checkout/qliro_recurring/history/';
const RECURRING = 'payment/qliroone/recurring_payments/enable';

function magento(...args: string[]): string {
  return execFileSync('docker', ['exec', config.container, 'bin/magento', ...args], { encoding: 'utf8' }).trim();
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

test('a guest is sent to the login page', async ({ page }) => {
  const response = await page.request.get(HISTORY, { maxRedirects: 0 });
  expect(response.status()).toBe(302);
  expect(response.headers()['location']).toContain('/customer/account/login');

  await page.goto(HISTORY);
  await expect(page).toHaveURL(/\/customer\/account\/login/);
});

test('a logged in customer sees the subscriptions page', async ({ page }) => {
  await page.goto('/customer/account/create/');
  await page.fill('#firstname', 'Qliro');
  await page.fill('#lastname', 'Tester');
  await page.fill('#email_address', `qliro.e2e.${randomUUID()}@example.com`);
  await page.fill('#password', 'Qliro-e2e-123');
  await page.fill('#password-confirmation', 'Qliro-e2e-123');
  await page.click('form.form-create-account button[type="submit"]');
  await expect(page).toHaveURL(/\/customer\/account\/?$/);

  const response = await page.goto(HISTORY);
  expect(response?.status()).toBe(200);
  await expect(page.locator('h1.page-title')).toHaveText('My Subscriptions through Qliro');
});
