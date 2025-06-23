# Qliro One Checkout for Magento 2

(Versions starting from 1.4.0 verified in Magento 2.4.6)

The module is a fully functional implementation of a custom checkout that uses Qliro One functionality through its API.

Out of the box, only purchase of virtual, simple and configurable Magento products are supported, but there is a
built-in mechanism for extending support for custom product types, custom shipping methods, custom discounts, provided
within the code of the module.

## System configuration options

As Qliro One Checkout is designed as a custom payment method, all configuration options are located in admin panel,
under **STORES > Settings > Configuration > SALES > Payment Methods > Qliro One Checkout**.

- **Enabled** — must be set to "Yes" for specific website, to turn on Qliro One Checkout functionality.
  Note that Qliro One checkout replaces the standard Magento One Page Checkout for that website, they cannot be
  used together at the same time.
- **Title** — a store-specific name of Qliro One checkout, visible as a title on the checkout page, as well as used
  in order management to represent Qliro One payment method.
- **Debug Mode** — a website-specific flag that indicates debugging mode. In debugging mode, the logging is very
  detailed, but debug mode must only be used for troubleshooting or during development, otherwise website will be
  easily overflown with extensive logged information.
- **Eager Checkout Refreshes** - If enabled, the lock and unlock of the Qliro One checkout is skipped. It also skips 
  the refresh of checkout when an update is made in the Iframe. Enabling this is not encouraged! 

### Merchant Configuration Settings

All settings here are website-specific.

- **API type** — specifies a type of API. Two are supported, Sandbox and Production.
- **Merchant API Key** — specifies merchant'specific API Key.
- **Merchant API Secret** — specifies merchant'specific API secret. this option is stored encrypted.
- **Preset Shipping Address** — if set to "Yes", Qliro One checkout will use a fictional shipping address based on
  the current store postal code to create Qliro One order. Otherwise, the order will be created without any
  shipping address.

### General Module Settings

All settings here are website-specific.

- **Geo IP** — a flag that enables country detection using current GeoIP functionality on the server.
  By default only built-in PHP GeoIP extension is supported, but there is a way to extend support of custom GeoIP system.

- **New Order Status** — a status that is used for marking newly placed Magento orders.
- **Payment from Applicable Countries** — Magento standard countries setting, can limit which countries that can pay
- **Trigger capture when shipment is created** — makes Magento trigger capturing money on shipment creation.
- **Triger capture when invoice is created** — makes Magento trigger capturing money on invoice creation.
- **Allow Newsletter Signup** — if set to "Yes", makes Qliro One checkout display a corresponding checkbox in the
  checkout IFRAME.
- **Require Identity Verification** — if set to "Yes", makes Qliro One checkout perform identity verification. Even if 
  this flag is set to No, it will set this flag if a Virtual product is in the cart. Virtual products can be anything 
  from gift cards to vouchers and various downloadable content.
- **Minimum Customer Age** - Write the minimum age a person shopping on the site must be. If you enter anything in here
  it becomes mandatory for the customer to identify his age.
- **Only use B2B checkout on companies** - Set to 'Yes' to enforce B2B checkout for users who have provided a company name in their billing address.
- **Show as payment method** - If enabled show native magneto checkout with the possibility to select different payment options (not only Qliro). Qliro option stays available on checkout as well

### CSS Styling Input Fields

All settings here are store-specific.

- **Background Color** — specifies a CSS HEX value for Qliro One checkout IFRAME background.
- **Primary Color** — specifies a CSS HEX value for Qliro One checkout IFRAME primary text color.
- **Call To Action Color** — specifies a CSS HEX value for Qliro One checkout IFRAME button color.
- **Call To Action Hover Color** — specifies a CSS HEX value for Qliro One checkout IFRAME button hover color.
- **Corner Radius** — specifies a corner radius for all frames inside the IFRAME.
- **Button Corner Radius** — specifies a button corner radius inside the IFRAME.

### Merchant Specific Information

All settings here are website-specific.

- **Fee Merchant Reference** — a merchant reference assigned to the Qliro One fee, if the fee is applicable.
- **Terms URL** — should be a valid URL to the website page containing Terms and Conditions. If not specified, will be
  defaulted to the website's home page.
- **Integrity Policy URL** — if specified, must be a valid URL to Integrity Policy URL.

### Recurring Payments Settings

All settings here are Store View specific.

- **Enabled** — Enable or disable recurring payments.
- **Frequency Options** – Configure the subscription frequencies that should be available for Customers to choose from in checkout.

### Notification Callbacks

- **XDebug Session Flag Name for callback URLs** — store-specific value for the XDEBUG session flag used in callbacks
  for debugging purposes only.
- **Redirect Callbacks** — if set to "Yes", the next option appears, allowing substitute the callback base URL in order
  to debug Qliro One remote callback requests locally, through tunneling.
- **URI Prefix for Callbacks** — a base URI for callbacks that allows debugging Qliro One remote callback requests
  locally, through tunneling.

### Nshift Integration 

- **Enabled** — enables the integration to Nshift (formerly known as unifaun). Before you can use this, you will need 
  to have it enabled in your Qliro One account 
- **Nshift Checkout ID** — the checkout id provided to you
- **Parameters** — a way to configure additional parameters. Pushing add adds a new line with three columns. Tag is the 
  tag name added, the function is one of the three user defined values or a plain user defined one. Last column is the
  value sent for that tag. For more information, see the comment in the setting.
- If Nshift is enabled and only virtual products are added to cart, the Nshift integration is not sent to Qliro, which
  means it will not requrire the customer to choose a freight option.

## Events

Module-specific event dispatch points are provided in the places which cannot be customized using Magento plugins.

### `qliroone_shipping_method_build_after`

**Arguments:**

- `quote` — an instance of quote (`\Magento\Quote\Model\Quote`)
- `rate` — an instance of a shipping rate (`\Magento\Quote\Model\Quote\Address\Rate`)
- `container` — shipping method container (`\Qliro\QliroOne\Api\Data\QliroOrderShippingMethodInterface`)

**Notes:** Happens after the shipping method container is already built, can be updated before sent to the Qliro order
create or update requests. If you want to have this particular shipping method skipped and not sent to the request,
set the merchant reference of the container to `null`.

### `qliroone_shipping_methods_response_build_after`

**Arguments:**

- `quote` — an instance of quote (`\Magento\Quote\Model\Quote`)
- `container` — shipping methods response container (`\Qliro\QliroOne\Api\Data\UpdateShippingMethodsResponseInterface`)

**Notes:** Happens after all shipping methods are built and packed into a shipping methods response container.


### `qliroone_order_item_build_after`

**Arguments:**

- `quote` — an instance of quote (`\Magento\Quote\Model\Quote`)
- `container` — QliroOne order item container (`\Qliro\QliroOne\Api\Data\QliroOrderItemInterface`)

**Notes:** Happens after a QliroOne order item container is formed, can be updated before it is sent to the create or
update request. If you want to have this particular order item skipped and not sent to the request, set the merchant
reference of the container to `null`.


### `qliroone_order_create_request_build_after`

**Arguments:**

- `quote` — an instance of quote (`\Magento\Quote\Model\Quote`)
- `container` — QliroOne order item container (`\Qliro\QliroOne\Api\Data\QliroOrderCreateRequestInterface`)

**Notes:** Happens after a QliroOne order create request container is formed, can be updated before it is sent to Qliro.


### `qliroone_shipping_method_update_before`

**Arguments:**

- `quote` — an instance of quote (`\Magento\Quote\Model\Quote`)
- `container` — simple `\Magento\Framework\DataObject` container that contains three fields,
  - `shipping_method` — a code of the shipping method, as it is coming from QliroOne update
  - `secondary_option` — a secondary option, as it is coming from QliroOne update
  - `shipping_price` — an updated shipping price, as it is coming from QliroOne update
  - `can_save_quote` — a flag that is initially set to `true` if the shipping method is different from one in quote.
    If only secondary option has to be applied, or something else requires quote recalculation and saving, it should be
    set to `true` by one of the event listeners.

**Notes:** Happens before QliroOne checkout module is ready to set the updated shipping method to Magento quote.


### `qliroone_shipping_price_update_before`

**Arguments:**

- `quote` — an instance of quote (`\Magento\Quote\Model\Quote`)
- `container` — simple `\Magento\Framework\DataObject` container that contains three fields,
  - `shipping_price` — an updated shipping price, as it is coming from QliroOne update
  - `can_save_quote` — a flag that is initially set to `false`. It should be set to `true` by one of the event
    listeners if the quote requires recalculation and saving.

**Notes:** Happens before QliroOne checkout module is ready to get the shipping price updated in Magento quote. 



### Plugins

Obviously, no special plugin entry points are provided for the original module, but developers can use standard Magento 2
plugin mechanism to plug into any public method in any class instantiated using DI, as specified in
https://devdocs.magento.com/guides/v2.2/extension-dev-guide/plugins.html.


### Database tables and Logs

**qliroone_link** - The table that stores the Qliro One reference with Magento Quote. It contains more details as well,
    such as status and last known comment regarding that order.

**qliroone_log** - a detailed log of everything that takes place in the code. All logs are linked with the Qliro One
    reference id. 

**qliroone_om_status** - contains the history of all notifications received from Qliro One, plus some additional status 
    updates

**qliroone_order_lock** - during certain events and functions, we use this table as a semaphore lock to allow or 
    disallow certain concurrent functions.
