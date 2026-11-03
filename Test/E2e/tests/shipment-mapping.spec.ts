import { expect, test } from '@playwright/test';
import { runSeeder } from '../seeder';

/**
 * A new Qliro shipment had no order items until a builder set them, so mapping a payload into one
 * died on the uninitialised property. Runs the store's own mapper and DI, no order or Qliro needed.
 */
type Mapped = { shipment: boolean; items: boolean[]; empty: unknown[]; array: Record<string, unknown> };

const payload = {
  PaymentTransactionId: 4711,
  OrderItems: [
    { MerchantReference: 'test-simple', Type: 'Product', Quantity: 2, PricePerItemIncVat: 199, PricePerItemExVat: 159.2 },
    { MerchantReference: 'flatrate_flatrate', Type: 'Shipping', Quantity: 1, PricePerItemIncVat: 59, PricePerItemExVat: 47.2 },
  ],
};

function mapShipment(data: object): Mapped {
  return runSeeder<Mapped>('map-shipment.php', [Buffer.from(JSON.stringify(data)).toString('base64')]);
}

test('a payload with order items maps into a new shipment', () => {
  const mapped = mapShipment(payload);

  expect(mapped.shipment).toBe(true);
  expect(mapped.items).toEqual([true, true]);
  expect(mapped.array.PaymentTransactionId).toBe(4711);
  expect(mapped.array.OrderItems).toEqual(payload.OrderItems.map((item) => expect.objectContaining(item)));
});

test('a payload without order items leaves a new shipment empty', () => {
  const mapped = mapShipment({ PaymentTransactionId: 4711 });

  expect(mapped.items).toEqual([]);
  expect(mapped.empty).toEqual([]);
});
