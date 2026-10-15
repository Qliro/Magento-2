import { writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { runSeeder } from './seeder';

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
};

/**
 * Seeds the orders the tests read. The seeder places them through the module the way the
 * checkout does, with a Qliro order fixture in place of the Qliro API, so no merchant
 * credentials are needed.
 */
function seed(args: string[]): SeededOrder {
  return runSeeder<SeededOrder>('seed-qliro-order.php', args);
}

export default async function globalSetup() {
  /*
   * The seeder places an order through the module, which needs a carrier the store actually
   * offers. A copy of a merchant's store has their carriers and not `flatrate`, so the two
   * checkout tests, which seed nothing, would be unrunnable there. Skipping leaves the specs
   * that read seeded orders without their fixture, so it is only for running the checkout ones.
   */
  if (process.env.MAGENTO_SKIP_SEED === '1') {
    return;
  }

  const seeded = {
    // the everyday case: a routed pay later method, an invoice fee and a second fee line
    withName: seed(['--fees=29,10']),
    // an order placed before the module recorded the name, which is the fallback case
    withoutName: seed(['--no-name', '--fees=29']),
  };

  writeFileSync(join(__dirname, '.seeded.json'), JSON.stringify(seeded, null, 2));
}
