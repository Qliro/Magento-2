import { test, expect } from '@playwright/test';
import { loginToAdmin, openAdminOrder, seeded } from './support';

/**
 * The fee lines have to survive into the invoice, which is where the merchant charges them.
 */
test('invoicing the order charges the fee lines too', async ({ page }) => {
  await loginToAdmin(page);
  await openAdminOrder(page, seeded.withName.orderId);

  await page.click('#order_invoice');
  await expect(page.locator('#invoice_item_container').first()).toBeVisible();

  await page.click('button.submit-button, button[title="Submit Invoice"]');
  await expect(page.locator('#messages')).toContainText('The invoice has been created.');

  await page.click('a[id*="sales_order_view_tabs_order_invoices"]');
  const invoiceRow = page.locator('#sales_order_view_tabs_order_invoices_content tbody tr').first();
  await expect(invoiceRow).toContainText(seeded.withName.expectedGrandTotal.toFixed(2));
});
