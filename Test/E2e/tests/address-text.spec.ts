import { test, expect } from '@playwright/test';
import { runSeeder } from '../seeder';

/**
 * A buyer whose names, city and phone Magento 2.4.8 or 2.4.9 refuses as Qliro sends them passes
 * the validate callback and gets the order. Only what the store refuses is reduced: the names on
 * both versions, the city and the phone on 2.4.8, while 2.4.9 keeps those as sent.
 * Needs `Test/E2e/seed/seed-address-text.php` in the store's `var/`.
 */

type SeededQuote = { quoteId: number; callbackToken: string; validate: Record<string, unknown> };
type Address = { firstname: string; lastname: string; city: string; telephone: string };

const NAMES = { firstname: 'Anna Maria', lastname: 'Hans Jr' };
const CITY = { sent: 'St. Gallen (Ost) 2', reduced: 'St Gallen Ost' };
const TELEPHONE = { sent: '+46 70 123 45 67 89 10', reduced: '+467012345678910' };

function expectAccepted(address: Address): void {
  expect({ firstname: address.firstname, lastname: address.lastname }).toEqual(NAMES);
  expect([CITY.sent, CITY.reduced]).toContain(address.city);
  expect([TELEPHONE.sent, TELEPHONE.reduced]).toContain(address.telephone);
}

function seed<T>(...args: string[]): T {
  return runSeeder<T>('seed-address-text.php', args);
}

test('a buyer Magento would refuse as Qliro sends them is accepted and gets the order', async ({ page }) => {
  const quote = seed<SeededQuote>('quote');

  const response = await page.request.post(`/checkout/qliro_callback/validate?token=${quote.callbackToken}`, {
    data: quote.validate,
  });
  expect(response.status(), await response.text()).toBe(200);

  const order = seed<{ orderId: number }>('place', `--quote=${quote.quoteId}`);
  const addresses = seed<{ billing: Address; shipping: Address }>('read', `--order=${order.orderId}`);

  expectAccepted(addresses.billing);
  expect(addresses.shipping).toEqual(addresses.billing);
});
