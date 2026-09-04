import { test, expect } from '@playwright/test';
import { loginToAdmin, openAdminOrder, paymentBlock, paymentRow, seeded } from './support';

/**
 * What a merchant sees on an order paid with one of the Ironman pay later methods (PLIN-374).
 * Before the change the payment block printed the raw code, QLIROPAYLATER_INVOICE30.
 */
test.describe('admin order view', () => {
  test.beforeEach(async ({ page }) => {
    await loginToAdmin(page);
  });

  test('names the payment method, with the raw values alongside it', async ({ page }) => {
    await openAdminOrder(page, seeded.withName.orderId);

    // the merchant reads the wording, support reads the two raw values under it
    await expect(paymentRow(page, 'Payment Method')).toHaveText(seeded.withName.methodLabel!);
    await expect(paymentRow(page, 'Qliro Payment Method')).toHaveText(seeded.withName.methodName!);
    await expect(paymentRow(page, 'Payment Type Code')).toHaveText(seeded.withName.methodCode);
    await expect(paymentBlock(page)).toContainText(String(seeded.withName.qliroOrderId));
    await expect(paymentBlock(page)).toContainText(seeded.withName.reference);
  });

  test('names the method from the type code on an order stored before the name was recorded', async ({ page }) => {
    await openAdminOrder(page, seeded.withoutName.orderId);

    // nothing but the type code was stored, so that is what the wording is resolved from
    await expect(paymentRow(page, 'Payment Method')).toHaveText(seeded.withoutName.methodLabel!);
    await expect(paymentRow(page, 'Payment Type Code')).toHaveCount(0);
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
