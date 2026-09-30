# Browser end to end tests

Playwright tests that check what a merchant and a customer actually see for an order paid with
Qliro: the payment method on the order view, the fee lines in the totals, and the invoice.

They need a local Magento with this module installed. Qliro itself is not involved in most of
them: the seeder places orders through the module's own placement path with a Qliro order fixture
in place of the merchant API, so no credentials and no test merchant are required.

Two tests are the exception. `checkout-guest-email.spec.ts` and `checkout-delivery-refresh.spec.ts`
drive the real checkout iframe, so they need a store pointed at a Qliro test merchant and are
skipped unless `QLIRO_CHECKOUT_E2E=1`. They seed nothing, so `MAGENTO_SKIP_SEED=1` lets them run
against a store the seeder cannot place an order in, such as a copy of a merchant's own. The
payment authorisation and capture, refund and cancel against Qliro are still out of scope.

`checkout-delivery-refresh.spec.ts` reads `qliroone_log` through the database container to see
where the order was read back, so it also needs `MAGENTO_DB_CONTAINER` when that container is not
the app one with `-phpfpm-` swapped for `-db-`.

## The store under test

Any Magento the module is installed into. Both 2.4.8 and 2.4.9 are covered. It needs:

- the module enabled, `setup:upgrade` run
- `payment/qliroone/active 1` and any value in the two `qliro_api` credential fields
- `payment/qliroone/api/capture_on_invoice 0`, otherwise invoicing calls Qliro and fails
- `admin/security/use_form_key 0`, so the tests can open admin URLs directly
- `Magento_TwoFactorAuth` disabled
- `Test/E2e/seed/seed-qliro-order.php` and `Test/E2e/seed/print-pdf-payment-block.php` copied to the store's `var/` directory

## Running

```bash
cd Test/E2e
npm install
npx playwright install chromium
npx playwright test
```

Point it at your own store with environment variables, the defaults describe the local 2.4.9
stand:

| Variable | Default |
| --- | --- |
| `MAGENTO_BASE_URL` | `https://localhost:8445` |
| `MAGENTO_CONTAINER` | `magento249-phpfpm-1` |
| `MAGENTO_ADMIN_PATH` | `/admin` |
| `MAGENTO_ADMIN_USER` | `admin` |
| `MAGENTO_ADMIN_PASSWORD` | `Admin123!` |
| `MAGENTO_DB_CONTAINER` | the app container with `-phpfpm-` swapped for `-db-` |

`MAGENTO_CONTAINER` is the container the seeder runs in, through `docker exec`.

## What the seeder does

`seed/seed-qliro-order.php` creates the product if it is missing, builds a guest quote, and hands
`PlaceOrder` a Qliro order, which is the same object the module builds from a `GetOrder` response.
The payment method and the shape of the fee lines come from `Test/Fixtures/qliro`, the contract
fixtures PIS pins from the Qliro sandbox, so the payload is theirs rather than ours. Flags:

- `--fixture=external-capture` which fixture the payment method comes from, the default is the
  Ironman pay later one, `QLIROPAYLATER_INVOICE14` over `INVOICE`
- `--fees=29,10` the amounts of the fee lines, their shape comes from the fee fixture
- `--no-name` leave `PaymentMethodName` out, which is what an order placed before the module
  recorded the name looks like

Worth knowing when reading the assertions: `PaymentMethodName` is the product and
`PaymentTypeCode` the instrument behind it, so the code says `INVOICE` for every pay later
product. Neither is a display name.

It prints a JSON object with the ids it created, and the global setup keeps it in `.seeded.json`.

## Coverage, and what breaks it

Reverting the fee accumulation in `OrderItemsConverter` or the name in `Block/Info/QliroOne`
turns four of the eight tests red, so they are pinned to the behaviour rather than to the markup.

## The checkout test

`checkout-guest-email.spec.ts` guards the regression where Magento refused its own
`set-payment-information` calls because the thin checkout never filled the guest email. It needs
a store whose only payment method is Qliro, because that is what makes Magento select it by
itself, which is what triggers the call. With a second method enabled the regression is invisible.

```bash
QLIRO_CHECKOUT_E2E=1 \
QLIRO_E2E_PRODUCT_URL=/some-simple-product \
npx playwright test checkout-guest-email
```

| Variable | Default |
| --- | --- |
| `QLIRO_CHECKOUT_E2E` | unset, which skips the test |
| `QLIRO_E2E_PRODUCT_URL` | `/` |
| `QLIRO_E2E_EMAIL` | `qliro-e2e@example.com` |
| `QLIRO_E2E_POSTCODE` | `11329` |
| `QLIRO_E2E_LOCALE` | `sv-SE` |
| `QLIRO_E2E_USER_AGENT` | a desktop Chrome string |
