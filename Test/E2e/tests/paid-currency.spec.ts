import { expect, test } from '@playwright/test';

import { runSeeder } from '../seeder';

/**
 * Needs a store with a second display currency and a rate to it, so it is off unless asked for:
 *
 * ```
 * E2E_PAY_CURRENCY=EUR MAGENTO_SKIP_SEED=1 npx playwright test paid-currency
 * ```
 *
 * The buyer's cart is in the currency they switched to. Qliro's delivery methods call and the push
 * that places the order run in requests of their own, without that currency, and both save the
 * quote. The order must still be in it, at the cart's rate and with Qliro's total.
 */
const currency = process.env.E2E_PAY_CURRENCY ?? '';

type Quote = { quoteId: number; currency: string; baseCurrency: string; rate: number; grandTotal: number };
type Shipping = { declineReason: string | null; methods: number; currency: string; rate: number };
type Placed = {
  incrementId: string;
  currency: string;
  baseCurrency: string;
  rate: number;
  grandTotal: number;
  qliroTotal: number;
};

test.describe('order paid in another display currency', () => {
  test.skip(currency === '', 'set E2E_PAY_CURRENCY to a display currency of the store that is not its default');

  test('is placed in that currency at the rate of the cart', () => {
    const quote = runSeeder<Quote>('seed-paid-currency.php', ['--step=quote', `--currency=${currency}`]);
    expect(quote.currency).toBe(currency);

    const shipping = runSeeder<Shipping>('seed-paid-currency.php', ['--step=shipping', `--quote=${quote.quoteId}`]);
    expect(shipping.declineReason).toBeNull();
    expect(shipping.methods).toBeGreaterThan(0);
    expect(shipping.currency).toBe(currency);
    expect(shipping.rate).toBeCloseTo(quote.rate, 4);

    const order = runSeeder<Placed>('seed-paid-currency.php', ['--step=place', `--quote=${quote.quoteId}`]);

    expect(order.currency).toBe(currency);
    expect(order.baseCurrency).toBe(quote.baseCurrency);
    // Magento keeps the rate to 4 decimals
    expect(order.rate).toBeCloseTo(quote.rate, 4);
    expect(order.grandTotal).toBeCloseTo(order.qliroTotal, 2);
  });
});
