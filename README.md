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

---

> 📘 **Documentation:** For complete guides, detailed instructions, and technical references, please refer to the Wiki.