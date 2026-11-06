import { Page, expect, test } from '@playwright/test';

import { config } from '../playwright.config';
import { runSeeder } from '../seeder';
import { loginToAdmin } from './support';

/**
 * The invoice fee goes back on the credit memo that completes the refund of the order, to the cent,
 * and not before. Credit memos are made from the invoice and refunded offline, Qliro is not called.
 *
 * The order in another currency needs a display currency of the store with a rate from the base one:
 *
 * ```
 * E2E_PAY_CURRENCY=EUR MAGENTO_SKIP_SEED=1 npx playwright test credit-memo-fee
 * ```
 */
const currency = process.env.E2E_PAY_CURRENCY ?? '';

type Order = { orderId: number; grandTotal: number; currency: string; baseCurrency: string };
type Invoice = { invoiceId: number; grandTotal: number };
type Memo = { creditMemoId: number; grandTotal: number; orderTotalRefunded: number };

function memo(order: Order, invoice: Invoice, args: string[]): Memo {
  return runSeeder<Memo>('credit-memo-fee.php', [
    'memo',
    `--order=${order.orderId}`,
    `--invoice=${invoice.invoiceId}`,
    ...args,
  ]);
}

function invoiceOf(order: Order): Invoice {
  const invoice = runSeeder<Invoice>('credit-memo-fee.php', ['invoice', `--order=${order.orderId}`]);
  expect(invoice.grandTotal).toBeCloseTo(order.grandTotal, 2);

  return invoice;
}

async function feeRowOf(page: Page, creditMemoId: number) {
  await page.goto(`${config.adminPath}/sales/creditmemo/view/creditmemo_id/${creditMemoId}/`);
  const row = page.locator('.order-subtotal-table tr').filter({ hasText: 'Invoice fee' });
  await expect(row).toHaveCount(1);

  return row;
}

test.describe('invoice fee on credit memos', () => {
  test('is returned only by the memo that refunds the order to the cent', async ({ page }) => {
    // 2 x 49.80, shipping 10 and a fee of 29: 109.60 to refund before the fee
    const order = runSeeder<Order>('seed-qliro-order.php', ['--fees=29', '--qty=2', '--price=49.80']);
    const invoice = invoiceOf(order);

    const first = memo(order, invoice, ['--qty=1', '--shipping=0']);
    expect(first.grandTotal).toBeCloseTo(49.8, 2);

    // 109.10 refunded of 109.60
    const short = memo(order, invoice, ['--qty=1', '--adjustmentNegative=0.50']);
    expect(short.grandTotal).toBeCloseTo(59.3, 2);

    const last = memo(order, invoice, ['--adjustmentPositive=0.50']);
    expect(last.grandTotal).toBeCloseTo(0.5 + 29, 2);
    expect(last.orderTotalRefunded).toBeCloseTo(order.grandTotal, 2);

    await loginToAdmin(page);
    await expect(await feeRowOf(page, short.creditMemoId)).not.toContainText('29');
    await expect(await feeRowOf(page, first.creditMemoId)).not.toContainText('29');
    await expect(await feeRowOf(page, last.creditMemoId)).toContainText('29');
  });

  test('compares the amounts of an order in another currency in that currency', async ({ page }) => {
    test.skip(currency === '', 'set E2E_PAY_CURRENCY to a display currency of the store that is not its default');

    const order = runSeeder<Order>('seed-qliro-order.php', [
      '--fees=2.50',
      '--qty=2',
      '--price=4.30',
      `--currency=${currency}`,
    ]);
    expect(order.currency).toBe(currency);
    expect(order.baseCurrency).not.toBe(currency);
    const invoice = invoiceOf(order);

    memo(order, invoice, ['--qty=1', '--shipping=0']);
    // the goods refunded, the shipping not: in the base currency this alone can pass the order total
    const goods = memo(order, invoice, ['--qty=1', '--shipping=0']);
    expect(goods.grandTotal).toBeCloseTo(4.3, 2);

    const last = memo(order, invoice, []);
    expect(last.orderTotalRefunded).toBeCloseTo(order.grandTotal, 2);
    expect(last.grandTotal).toBeGreaterThan(2.5);

    await loginToAdmin(page);
    await expect(await feeRowOf(page, goods.creditMemoId)).not.toContainText('2.50');
    await expect(await feeRowOf(page, last.creditMemoId)).toContainText('2.50');
  });
});
