import { expect, test } from '@playwright/test';

import { loginToAdmin, openAdminOrder } from './support';
import { runSeeder } from '../seeder';

/**
 * A cancellation Qliro does not take. The seeded order's Qliro order is unknown to the merchant the
 * store is configured with, so Qliro refuses the cancel. Magento still cancels the order, and the
 * admin who cancelled it and the order history both say the amount may still be reserved.
 */
type Seeded = { orderId: number; incrementId: string };

test('a refused cancel is shown to the admin and kept on the order', async ({ page }) => {
  const seeded = runSeeder<Seeded>('seed-qliro-order.php', []);
  await loginToAdmin(page);
  await openAdminOrder(page, seeded.orderId);

  await page.click('#order-view-cancel-button');
  // Magento confirms the cancel in its own modal
  await page.locator('.modal-popup._show .action-accept').click();

  const messages = page.locator('#messages');
  await expect(messages).toContainText('You canceled the order.');
  await expect(messages).toContainText('Qliro did not cancel the payment, so the amount may still be reserved');

  await expect(page.locator('#order_status')).toHaveText(/Canceled/i);
  await expect(page.locator('.note-list li').filter({ hasText: 'Qliro did not cancel the payment' })).toHaveCount(1);
});
