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

## Callback security

Qliro pushes order and transaction updates to callback urls this module registers on the order when
it is created. Each url carries a token this module signed with the store's API secret, and every
callback controller refuses a request whose token does not verify.

The token expires. **Stores > Configuration > Sales > Payment Methods > QliroOne Checkout >
Notification Callbacks > Callback Token Lifetime (days)** decides how long a newly minted one lasts,
365 by default and 1095 at most.

**Set it before you go live, to outlast your order lifecycle.** The url Qliro pushes to is the one
registered when the order was created, so it has to still be valid when the last capture or refund of
that order settles. A store that captures on shipment and takes returns for a year needs more than a
year: a refund pushed to a url whose token has run out is refused, and that transaction never syncs
back to the order. The default suits a store whose orders are done within a year; a store with a
longer return or warranty window should raise it, up to the 1095 days the field allows. If a callback
is ever refused for this reason the module logs a warning naming the configured lifetime, so the
symptom points at the setting.

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