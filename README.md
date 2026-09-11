# Qliro One Checkout for Magento 2
Qliro One for Magento 2 is an extension that integrates the Qliro One payment and checkout service into the Magento 2 e-commerce platform. Qliro One is a Nordic payment solution offering invoice, part payment, card payment, and direct bank payment options. The Magento 2 module enables seamless embedding of Qliro’s hosted checkout within the store, supporting features such as dynamic shipping options, order management synchronization, and compliance with local payment regulations. The module is a fully functional implementation of a custom checkout that uses Qliro One functionality through its API.

All documentation, setup guides, and troubleshooting instructions are maintained in the **Wiki**.

### 👉 [Go to the Wiki](https://github.com/Qliro/Magento-2/wiki)

---

## Quick links

The Wiki is organized into the following main sections to help you quickly find what you need:

- **Installation & Update** - How to install the module and keep it up to date  
 https://github.com/Qliro/Magento-2/wiki#installation--update

- **Configuration** - Learn how to configure the module for your store  
https://github.com/Qliro/Magento-2/wiki#configuration

- **Customization and tech details** - Database tables, events, plugins, logs, and customization guidelines  
https://github.com/Qliro/Magento-2/wiki#customization-and-tech-details

- **Troubleshooting** - Common issues and how to resolve them  
https://github.com/Qliro/Magento-2/wiki#troubleshooting

---

## Analytics and purchase tracking

The checkout has its own success page, `checkout/qliro/success`, so its layout handle is
**`checkout_qliro_success`** and not `checkout_onepage_success`. A tracking extension that declares
its block in `checkout_onepage_success.xml` renders nothing here until that block is mapped onto
this handle, in your own module or theme:

```xml
<!-- view/frontend/layout/checkout_qliro_success.xml -->
<page xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
      xsi:noNamespaceSchemaLocation="urn:magento:framework:View/Layout/etc/page_configuration.xsd">
    <body>
        <referenceContainer name="content">
            <block class="Vendor\Tracking\Block\Purchase"
                   name="vendor.tracking.purchase"
                   template="Vendor_Tracking::purchase.phtml"
                   cacheable="false"/>
        </referenceContainer>
    </body>
</page>
```

`<update handle="checkout_onepage_success"/>` brings every block of the core success page over in
one line, tracking blocks included, but it also brings `checkout.success` and
`checkout.registration`, which duplicate what this module's own success block already shows.

On the success page the module provides what core provides:

- the checkout session carries `last_order_id`, `last_real_order_id`, `last_quote_id`,
  `last_success_quote_id` and `last_order_status` for the placed order, so an extension that
  identifies the order through `getLastRealOrderId()` works unchanged
- the `checkout_onepage_controller_success_action` event is dispatched with `order_ids` and
  `order`, once per order: reloading the success page does not fire it a second time

Magento's own GA4 block needs nothing, it is declared in `Magento_GoogleGtag`'s `default.xml` and
therefore renders on every page, this one included.

**Client side tracking undercounts on this checkout.** An order can be placed by Qliro's
`checkoutStatus` callback while the buyer is still in the Qliro iframe, so a buyer who closes the
tab or never returns from a bank app produces a paid order and no browser event at all. If the
numbers have to be right, send the purchase server side, GA4 Measurement Protocol from an observer
on `sales_order_place_after`, and offline conversion import or server side GTM for Google Ads.

## What the module logs

Every API call and callback is logged to the `qliroone_log` database table and to
`var/log/qliroone.log`, request and response bodies included. This happens at debug level on every
request whatever **Debug Mode** is set to: that setting gates other behaviour, not the logging.

Before anything is written, `Model/Logger/Redactor.php` masks it as `[redacted]`:

- **Credentials**, always, by name, by value and inside a url. Any key named like one,
  `MerchantApiKey`, `MerchantApiSecret`, `Authorization`, a token, a password, or anything
  containing `secret` or `apikey`, whatever its spelling and however deep in the payload it sits.
  The API key and secret the store is configured with are masked wherever they appear, whatever the
  key they were logged under is called. In a url, the user and password before the host, a `token`
  in the query and a JSON web token anywhere are masked too: the callback token carries the merchant
  API key in its payload, and with Callback HTTP Auth the url carries the username and password.
- **Customer data**, always. Email, mobile number, personal identity number, VAT and organization
  number, date of birth, first and last name, care of, company name, street, postal code and city,
  under Qliro's spellings and Magento's own, `taxvat`, `dob`, `vat_id` and `company` included, and
  whether the field holds one value or a list. An email address and a Nordic identity number are
  masked wherever they appear in free text too.
- **An identity number**, written with its separator or as twelve digits, always. A ten digit one
  written without a separator is not masked by pattern: it cannot be told apart from a Qliro order
  id, and it arrives under a key of its own in every payload the module sends or receives.
- **An international phone number**, on the lines marked with the `sensitive` tag. That is every
  exchange with Qliro's APIs, every callback body Qliro posts back and every refusal Qliro
  explains, which is where a customer record travels.

Card data is not masked, and that is deliberate: Qliro sends only the first six and the last four
digits of a saved card, `CardBin` and `CardLast4Digits`, which is what PCI DSS permits a merchant to
retain and what support needs to identify a card. The card token itself is masked.

What stays readable is what a merchant needs in order to investigate: the merchant reference, the
endpoint and the uri with their order ids, the request method, the status code, the order items, the
amounts, the country and Qliro's own error codes. Those keep their digits even on a masked line, so
an order id is never mistaken for an identity number. An exception keeps its class, its file, its
code and its message and trace, with both masked. A merchant reference written as a date and a
counter, `20260909-0001`, has the shape of an identity number, so the reference of the line being
written is held out of the patterns by value: it stays readable wherever it appears in that line,
while any other value of that shape is still masked.

The masking runs on the log channel, so it applies to the table and to the files alike, to a payload
that arrives as an object or as text, and to anything logged from a plugin of your own that uses the
module's log manager.

**How long rows are kept** is a separate matter from what they hold, and it is the next section.

## Log retention

The module logs every API call and callback, payloads included, to the `qliroone_log` table, whatever
Debug Mode says. The nightly cron job `qliroone_prune_log` deletes the rows older than **Stores >
Configuration > Sales > Payment Methods > QliroOne Checkout > Debugging > Log Retention (days)**, 30 days
on a fresh install. Set it to 0 to keep every row.

**Upgrading from a version before 1.7.30:** an installation whose log table already holds rows keeps every
row, so nothing is deleted until you choose a window. Set the retention to 30, or to whatever the store
needs, to start pruning.

The setting lives on the default scope only. A log row carries no store id, so one window covers the
whole table, and a window saved on a website or a store view is refused rather than silently ignored.

The same pruning runs on demand:

```
bin/magento qliroone:log:prune            # the configured retention
bin/magento qliroone:log:prune --days=7   # this run only, the setting is untouched
```

Rows are deleted in batches of 5000, and a single run stops after 200 of them, so the job is safe to run
while the store is serving traffic. A backlog of tens of millions of rows is worked off over several runs,
and a run that stopped at that cap says so rather than looking like a finished one.

## Callback security

Qliro pushes order and transaction updates to callback urls this module registers on the order when
it is created. Each url carries a token this module signed with the store's API secret, and every
callback controller refuses a request whose token does not verify.

The token expires. **Stores > Configuration > Sales > Payment Methods > QliroOne Checkout >
Notification Callbacks > Callback Token Lifetime (days)** decides how long a newly minted one lasts,
1 to 1095 days, and 1095 by default.

**Shorten it to match your order lifecycle.** The url Qliro pushes to is the one registered when the
order was created, so it has to still be valid when the last capture or refund of that order settles.
The default is three years, the length of the Swedish right of complaint, so that no store is caught
out by it. If your orders are done sooner, set it shorter: that is the whole point of the setting.

What an expired token costs, so the choice is an informed one: the callback is refused, the capture
or the refund is never confirmed on the Magento order, an order that was held awaiting capture
confirmation stays held, a queued sequential refund stops advancing, and nothing reconciles any of it
afterwards, because the module does not poll Qliro for status. A refusal for this reason is logged as
a warning naming the setting, so the symptom points at the cause.

Changing the setting is safe at any time: the expiry is written into each token, so a callback url
already registered with Qliro keeps the lifetime it was given, and a shorter window applies only to
orders created after the change. Tokens issued before this feature existed carry the old three year
expiry and keep working until it passes.

**On upgrade** nothing changes for the orders you have already placed: their callback urls carry the
expiry they were minted with, three years for anything from before this release, and the check reads
it from the token rather than from the setting. Orders placed after the upgrade get the new default,
so this is the moment to decide whether a year covers your returns.

The token the checkout page uses for its own ajax calls is a different one: it lasts two hours, is
bound to the quote, and is not affected by this setting.

---

> 📘 **Documentation:** For complete guides, detailed instructions, and technical references, please refer to the Wiki.