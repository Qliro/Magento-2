import { test, expect } from '@playwright/test';
import { paymentRow, seeded } from './support';

/**
 * The same payment block a customer sees on their order, through the guest order lookup.
 */
test('the guest order view names the payment method', async ({ page }) => {
  await page.goto('/sales/guest/form/');

  await page.fill('#oar-order-id', seeded.withName.incrementId);
  await page.fill('#oar-billing-lastname', 'Tester');
  await page.selectOption('#quick-search-type-id', 'email');
  await page.fill('#oar_email', 'qliro.e2e@example.com');
  await page.click('#oar-widget-orders-and-returns-form button[type="submit"]');

  await expect(paymentRow(page, 'Payment Method')).toHaveText(seeded.withName.methodLabel!);
  // the raw values are support's business, the customer only gets the wording
  await expect(paymentRow(page, 'Qliro Payment Method')).toHaveCount(0);
  await expect(paymentRow(page, 'Payment Type Code')).toHaveCount(0);
});
