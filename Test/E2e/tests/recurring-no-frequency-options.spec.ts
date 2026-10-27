import { execFileSync } from 'node:child_process';
import { test, expect, Page } from '@playwright/test';
import { config } from '../playwright.config';

/**
 * Recurring payments turned on with no frequency options saved, as `config:set` or a config dump
 * leaves them. The cart and the checkout used to fail on a null where a string was declared.
 * Settings are written directly: `config:set` encodes the options a second time and cannot delete.
 */
const RECURRING = 'payment/qliroone/recurring_payments/enable';
const OPTIONS = 'payment/qliroone/recurring_payments/frequency_options';
const MONTHLY = '{"__empty":"","_1":{"label":"Monthly","frequency":"1","time_unit":"month"}}';

function php(code: string, ...args: string[]): string {
  const bootstrap = 'require "app/bootstrap.php";'
    + '$om = \\Magento\\Framework\\App\\Bootstrap::create(BP, $_SERVER)->getObjectManager();';
  return execFileSync('docker', ['exec', config.container, 'php', '-r', bootstrap + code, '--', ...args], {
    encoding: 'utf8',
  }).trim();
}

/** The value stored at the default scope, null when there is none */
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
      + '$om->get(\\Magento\\Framework\\App\\Cache\\Manager::class)->clean(["config", "full_page"]);',
    path,
    ...(value === null ? [] : [value]),
  );
}

async function addToCart(page: Page): Promise<void> {
  await page.goto('/test-simple.html');
  await page.click('#product-addtocart-button');
  await expect(page.locator('.message-success')).toBeVisible();
}

async function recurringOrder(page: Page, path: string) {
  const response = await page.goto(path);
  expect(response?.status()).toBe(200);
  return page.evaluate(() => (window as any).checkoutConfig?.qliro?.recurringOrder);
}

let previous: { enable: string | null; options: string | null };

test.beforeAll(() => {
  previous = { enable: stored(RECURRING), options: stored(OPTIONS) };
  store(RECURRING, '1');
});

test.afterAll(() => {
  if (!previous) {
    return;
  }
  store(RECURRING, previous.enable);
  store(OPTIONS, previous.options);
});

test('the cart and the checkout load and offer no frequency', async ({ page }) => {
  store(OPTIONS, null);
  await addToCart(page);

  for (const path of ['/checkout/cart/', '/checkout/']) {
    const recurring = await recurringOrder(page, path);
    expect(recurring?.enabled).toBe(true);
    expect(recurring?.availableFrequencyOptions).toEqual([]);
  }
});

test('saved frequency options are still offered', async ({ page }) => {
  store(OPTIONS, MONTHLY);
  await addToCart(page);

  const recurring = await recurringOrder(page, '/checkout/');
  expect(recurring?.availableFrequencyOptions).toEqual([{ label: 'Monthly', value: '1|month', selected: false }]);
});
