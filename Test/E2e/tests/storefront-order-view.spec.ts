import { test, expect } from '@playwright/test';
import { paymentBlock, seeded } from './support';

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

  const block = paymentBlock(page);
  await expect(block).toContainText(seeded.withName.methodName!);
  // the raw code is support's business, not the customer's
  await expect(block).not.toContainText(seeded.withName.methodCode);
});
