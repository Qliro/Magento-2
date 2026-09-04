import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { Page, expect } from '@playwright/test';
import { config } from '../playwright.config';
import type { SeededOrder } from '../global-setup';

export const seeded: { withName: SeededOrder; withoutName: SeededOrder } = JSON.parse(
  readFileSync(join(__dirname, '..', '.seeded.json'), 'utf8')
);

export async function loginToAdmin(page: Page): Promise<void> {
  await page.goto(`${config.adminPath}`);

  if (await page.locator('#login-form').count()) {
    await page.fill('input[name="login[username]"]', config.adminUser);
    await page.fill('input[name="login[password]"]', config.adminPassword);
    await page.click('button.action-login');
  }

  await expect(page.locator('.admin__menu')).toBeVisible();
}

export async function openAdminOrder(page: Page, orderId: number): Promise<void> {
  await page.goto(`${config.adminPath}/sales/order/view/order_id/${orderId}/`);
  await expect(page.locator('.order-view-payment-shipping, .order-payment-method')).toBeVisible();
}

/**
 * The module's own table in the payment block, addressed by what it contains rather than by a
 * wrapper class, because the admin and the storefront wrap it differently.
 */
export function paymentBlock(page: Page) {
  return page.locator('table').filter({ hasText: 'Qliro Order Id' }).first();
}

/** The value cell of one row of that table, by its label. */
export function paymentRow(page: Page, label: string) {
  return paymentBlock(page)
    .locator('tr')
    .filter({ has: page.getByText(`${label}:`, { exact: true }) })
    .locator('td');
}
