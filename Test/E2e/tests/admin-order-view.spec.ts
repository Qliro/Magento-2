import { test, expect } from '@playwright/test';
import { loginToAdmin, openAdminOrder, paymentBlock, seeded } from './support';

/**
 * What a merchant sees on an order paid with one of the Ironman pay later methods (PLIN-374).
 * Before the change the payment block printed the raw code, QLIROPAYLATER_INVOICE30.
 */
test.describe('admin order view', () => {
  test.beforeEach(async ({ page }) => {
    await loginToAdmin(page);
  });

  test('shows the payment method name Qliro sent, with the code alongside it', async ({ page }) => {
    await openAdminOrder(page, seeded.withName.orderId);

    const block = paymentBlock(page);
    await expect(block).toContainText(seeded.withName.methodName!);
    await expect(block).toContainText(seeded.withName.methodCode);
    await expect(block).toContainText(String(seeded.withName.qliroOrderId));
    await expect(block).toContainText(seeded.withName.reference);
  });

  test('falls back to the code on an order stored before the name was recorded', async ({ page }) => {
    await openAdminOrder(page, seeded.withoutName.orderId);

    const block = paymentBlock(page);
    await expect(block).toContainText(seeded.withoutName.methodCode);
    // with nothing else to show, the code stands alone rather than being repeated in two rows
    await expect(block.locator('th', { hasText: 'Payment Method Code' })).toHaveCount(0);
  });

  test('carries every fee line of the Qliro order into the order totals', async ({ page }) => {
    await openAdminOrder(page, seeded.withName.orderId);

    const totals = page.locator('.order-totals');
    for (const fee of seeded.withName.fees) {
      await expect(totals).toContainText(fee.toFixed(2));
    }
    await expect(totals).toContainText(seeded.withName.expectedGrandTotal.toFixed(2));
  });

  test('finds the order by its Qliro reference in the orders grid', async ({ page }) => {
    await page.goto('/admin/sales/order/');
    const grid = page.locator('table[data-role="grid"]').first();
    await expect(grid).toBeVisible();

    await page.fill('#fulltext', seeded.withName.incrementId);
    await page.press('#fulltext', 'Enter');
    await expect(
      page.locator('[data-component="sales_order_grid.sales_order_grid.sales_order_columns"].admin__data-grid-loading-mask')
    ).toBeHidden();

    await expect(grid).toContainText(seeded.withName.incrementId);
  });
});
