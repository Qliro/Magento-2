import { execFileSync } from 'node:child_process';
import { test, expect } from '@playwright/test';
import { config } from '../playwright.config';
import { seeded } from './support';

/**
 * The invoice, the credit memo and the shipment print their own payment block. It used to print
 * a hardcoded "Identification Number: 123" and nothing about the payment at all.
 */
function pdfPaymentBlock(orderId: number): string {
  return execFileSync(
    'docker',
    ['exec', config.container, 'sh', '-c', `cd /var/www/html && php var/print-pdf-payment-block.php ${orderId}`],
    { encoding: 'utf8' }
  );
}

test('the printed payment block names the method', () => {
  const block = pdfPaymentBlock(seeded.withName.orderId);

  expect(block).toContain(`Payment Method: ${seeded.withName.methodName}`);
  expect(block).toContain(`Qliro Order Id: ${seeded.withName.qliroOrderId}`);
  expect(block).toContain(`Qliro Reference: ${seeded.withName.reference}`);
  expect(block).not.toContain('Identification Number');
});

test('the printed payment block falls back to the code', () => {
  const block = pdfPaymentBlock(seeded.withoutName.orderId);

  expect(block).toContain(`Payment Method: ${seeded.withoutName.methodCode}`);
});
