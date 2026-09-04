import { execFileSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { config } from './playwright.config';

export type SeededOrder = {
  orderId: number;
  incrementId: string;
  quoteId: number;
  qliroOrderId: number;
  reference: string;
  grandTotal: number;
  expectedGrandTotal: number;
  fees: number[];
  methodCode: string;
  methodName: string | null;
  methodLabel: string | null;
};

/**
 * Seeds the orders the tests read. The seeder places them through the module the way the
 * checkout does, with a Qliro order fixture in place of the Qliro API, so no merchant
 * credentials are needed.
 */
function seed(args: string[]): SeededOrder {
  const output = execFileSync(
    'docker',
    ['exec', config.container, 'sh', '-c', `cd /var/www/html && php var/seed-qliro-order.php ${args.join(' ')}`],
    { encoding: 'utf8', maxBuffer: 10 * 1024 * 1024 }
  );

  return JSON.parse(output.slice(output.indexOf('{')));
}

export default async function globalSetup() {
  const seeded = {
    // the everyday case: a routed pay later method, an invoice fee and a second fee line
    withName: seed(['--fees=29,10']),
    // an order placed before the module recorded the name, which is the fallback case
    withoutName: seed(['--no-name', '--fees=29']),
  };

  writeFileSync(join(__dirname, '.seeded.json'), JSON.stringify(seeded, null, 2));
}
