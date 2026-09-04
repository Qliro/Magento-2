import { test, expect } from '@playwright/test';
import { paymentRow, seeded } from './support';

/**
 * The same payment block a customer sees on their order, through the guest order lookup.
 */
test('the guest order view shows the payment method name', async ({ page }) => {
  await page.goto('/sales/guest/form/');

  await page.fill('#oar-order-id', seeded.withName.incrementId);
  await page.fill('#oar-billing-lastname', 'Tester');
  await page.selectOption('#quick-search-type-id', 'email');
  await page.fill('#oar_email', 'qliro.e2e@example.com');
  await page.click('#oar-widget-orders-and-returns-form button[type="submit"]');

  await expect(paymentRow(page, 'Payment Method')).toHaveText(seeded.withName.methodName!);
  // the code row is support's business, the customer only gets the method
  await expect(paymentRow(page, 'Payment Method Code')).toHaveCount(0);
});
