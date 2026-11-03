import { execFileSync } from 'node:child_process';
import { test, expect, APIRequestContext } from '@playwright/test';
import { config } from '../playwright.config';
import { runSeeder } from '../seeder';
import { seeded } from './support';

/**
 * The order side lookup of a line by its merchant reference, on an order the seeder placed, and
 * the titles the Unifaun and Ingrid rates take from the shipping description left on the cart.
 */
function php(code: string, ...args: string[]): string {
  const bootstrap = 'require "app/bootstrap.php";'
    + '$om = \\Magento\\Framework\\App\\Bootstrap::create(BP, $_SERVER)->getObjectManager();'
    + '$om->get(\\Magento\\Framework\\App\\State::class)->setAreaCode("adminhtml");';
  return execFileSync('docker', ['exec', config.container, 'php', '-r', bootstrap + code, '--', ...args], {
    encoding: 'utf8',
  }).trim();
}

/** The quote item id of the line each reference finds, null for none, or the error it raised */
function lookUp(orderId: number, references: unknown[]): unknown[] {
  return JSON.parse(php(
    '$order = $om->get(\\Magento\\Sales\\Api\\OrderRepositoryInterface::class)->get((int)$argv[1]);'
      + '$provider = $om->create(\\Qliro\\QliroOne\\Model\\Product\\Type\\OrderSourceProvider::class);'
      + '$provider->setOrder($order);'
      + '$out = [];'
      + 'foreach (json_decode($argv[2], true) as $reference) {'
      + '  try { $item = $provider->getSourceItemByMerchantReference($reference); $out[] = $item ? $item->getId() : null; }'
      + '  catch (\\Throwable $e) { $out[] = get_class($e); }'
      + '}'
      + 'echo json_encode($out);',
    String(orderId),
    JSON.stringify(references),
  ));
}

function firstLine(orderId: number): { quoteItemId: number; sku: string } {
  return JSON.parse(php(
    '$order = $om->get(\\Magento\\Sales\\Api\\OrderRepositoryInterface::class)->get((int)$argv[1]);'
      + '$item = array_values($order->getAllVisibleItems())[0];'
      + 'echo json_encode(["quoteItemId" => (int)$item->getQuoteItemId(), "sku" => $item->getSku()]);',
    String(orderId),
  ));
}

test.describe('order side lookup by merchant reference', () => {
  test('a bare sku finds the line, an unknown one finds nothing, neither raises', () => {
    const { orderId } = seeded.withName;
    const { quoteItemId, sku } = firstLine(orderId);

    expect(lookUp(orderId, [
      sku,
      `${quoteItemId}:${sku}`,
      `999999:${sku}`,
      { qliro: 'checkout', quoteItems: { [`${quoteItemId}:${sku}`]: `${quoteItemId}:${sku}` } },
      'NO-SUCH-SKU-E2E',
      { qliro: 'checkout' },
    ])).toEqual([quoteItemId, quoteItemId, quoteItemId, quoteItemId, null, null]);
  });

  test('a bare sku of a configurable finds the child the line was built from', () => {
    type Line = { quoteItemId: number; sku: string; productId: number };
    const sku = process.env.E2E_CONFIGURABLE_SKU;
    const order = runSeeder<{ orderId: number; parent: Line; child: Line }>(
      'seed-configurable-order.php',
      sku ? [`--sku=${sku}`] : [],
    );

    expect(order.child.sku).toBe(order.parent.sku);
    expect(lookUp(order.orderId, [order.child.sku, `999999:${order.child.sku}`]))
      .toEqual([order.child.quoteItemId, order.child.quoteItemId]);
  });
});

test.describe('quote side lookup by merchant reference', () => {
  test('a bare sku in the line metadata finds the cart item', async ({ request }) => {
    const mask = await (await request.post('/rest/V1/guest-carts')).json();
    const added = await request.post(`/rest/V1/guest-carts/${mask}/items`, {
      data: { cartItem: { quote_id: mask, sku: 'QLIRO-E2E-PRODUCT', qty: 1 } },
    });
    expect(added.status()).toBe(200);
    const itemId = (await added.json()).item_id;

    const found = JSON.parse(php(
      '$quoteId = $om->get(\\Magento\\Quote\\Model\\QuoteIdMaskFactory::class)->create()->load($argv[1], "masked_id")->getQuoteId();'
        + '$quote = $om->get(\\Magento\\Quote\\Api\\CartRepositoryInterface::class)->get((int)$quoteId);'
        + '$provider = $om->create(\\Qliro\\QliroOne\\Model\\Product\\Type\\QuoteSourceProvider::class);'
        + '$provider->setQuote($quote);'
        + '$out = [];'
        + 'foreach (json_decode($argv[2], true) as $reference) {'
        + '  try { $item = $provider->getSourceItemByMerchantReference($reference); $out[] = $item ? $item->getId() : null; }'
        + '  catch (\\Throwable $e) { $out[] = get_class($e); }'
        + '}'
        + 'echo json_encode($out);',
      mask,
      JSON.stringify([
        { qliro: 'checkout', quoteItems: { 'QLIRO-E2E-PRODUCT': 'QLIRO-E2E-PRODUCT' } },
        { qliro: 'checkout', quoteItems: { [`${itemId}:QLIRO-E2E-PRODUCT`]: `${itemId}:QLIRO-E2E-PRODUCT` } },
        { qliro: 'checkout' },
      ]),
    ));

    expect(found).toEqual([itemId, itemId, null]);
  });
});

const SETTINGS = [
  'payment/qliroone/unifaun/enable',
  'carriers/qlirounifaun/active',
  'payment/qliroone/ingrid/enable',
  'carriers/qliroingrid/active',
];

/** The value the default store reads, whatever scope it comes from */
function configured(path: string): string {
  return php(
    'echo $om->get(\\Magento\\Framework\\App\\Config\\ScopeConfigInterface::class)'
      + '->getValue($argv[1], \\Magento\\Store\\Model\\ScopeInterface::SCOPE_STORE, 1);',
    path,
  );
}

function stored(path: string): string | null {
  return JSON.parse(php(
    '$c = $om->get(\\Magento\\Framework\\App\\ResourceConnection::class)->getConnection();'
      + '$v = $c->fetchOne($c->select()->from($c->getTableName("core_config_data"), "value")'
      + '->where("path = ?", $argv[1])->where("scope = ?", "default")->where("scope_id = ?", 0));'
      + 'echo json_encode($v === false ? null : $v);',
    path,
  ));
}

function store(path: string, value: string | null): void {
  php(
    '$w = $om->get(\\Magento\\Framework\\App\\Config\\Storage\\WriterInterface::class);'
      + '$argc > 2 ? $w->save($argv[1], $argv[2]) : $w->delete($argv[1]);'
      + '$om->get(\\Magento\\Framework\\App\\Cache\\Manager::class)->clean(["config"]);',
    path,
    ...(value === null ? [] : [value]),
  );
}

/** A guest cart with the seeded product, its shipping description set the way the Qliro checkout leaves it */
async function guestCart(request: APIRequestContext, description: string): Promise<string> {
  const mask = await (await request.post('/rest/V1/guest-carts')).json();
  const added = await request.post(`/rest/V1/guest-carts/${mask}/items`, {
    data: { cartItem: { quote_id: mask, sku: 'QLIRO-E2E-PRODUCT', qty: 1 } },
  });
  expect(added.status()).toBe(200);

  php(
    '$quoteId = $om->get(\\Magento\\Quote\\Model\\QuoteIdMaskFactory::class)->create()->load($argv[1], "masked_id")->getQuoteId();'
      + '$quote = $om->get(\\Magento\\Quote\\Api\\CartRepositoryInterface::class)->get((int)$quoteId);'
      + '$quote->getShippingAddress()->setCountryId("SE")->setShippingDescription($argv[2]);'
      + '$quote->save();',
    mask,
    description,
  );

  return mask;
}

async function rates(request: APIRequestContext, mask: string) {
  const response = await request.post(`/rest/V1/guest-carts/${mask}/estimate-shipping-methods`, {
    data: { address: { country_id: 'SE', postcode: '11122', city: 'Stockholm' } },
  });
  const body = await response.text();
  expect(response.status(), body).toBe(200);
  return JSON.parse(body) as { carrier_code: string; carrier_title: string; method_title: string }[];
}

test.describe('Unifaun and Ingrid titles from the shipping description', () => {
  let previous: Record<string, string | null>;

  test.beforeAll(() => {
    previous = Object.fromEntries(SETTINGS.map((path) => [path, stored(path)]));
    SETTINGS.forEach((path) => store(path, '1'));
  });

  test.afterAll(() => {
    if (!previous) {
      return;
    }
    Object.entries(previous).forEach(([path, value]) => store(path, value));
  });

  for (const [provider, code] of [['Unifaun', 'qlirounifaun'], ['Ingrid', 'qliroingrid']]) {
    test(`${provider}: carrier and method split once, a missing method keeps the configured title`, async ({ request }) => {
      let rate = (await rates(request, await guestCart(request, `${provider} - PostNord - Ombud (12)`)))
        .find((r) => r.carrier_code === code);
      expect(rate?.carrier_title).toBe(provider);
      expect(rate?.method_title).toBe('PostNord - Ombud (12)');

      rate = (await rates(request, await guestCart(request, `${provider} - `)))
        .find((r) => r.carrier_code === code);
      expect(rate?.carrier_title).toBe(provider);
      expect(rate?.method_title).toBe(configured(`carriers/${code}/name`));
    });
  }

  test('Unifaun: a description without a space after the dash keeps the configured titles', async ({ request }) => {
    const rate = (await rates(request, await guestCart(request, 'Unifaun -PostNord')))
      .find((r) => r.carrier_code === 'qlirounifaun');
    expect(rate?.carrier_title).toBe(configured('carriers/qlirounifaun/title'));
    expect(rate?.method_title).toBe(configured('carriers/qlirounifaun/name'));
  });
});
