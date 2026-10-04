# Compatibility

## Versions

| | Supported |
|---|---|
| Zen Cart | 1.5.8a, 2.0.x, 2.1.0, 2.2.x, 2.3.x, 3.0.0 (one codebase; the manifest lists v158 through v300) |
| PHP | 7.4 through 8.5 (no PHP 8-only syntax; deprecation-clean on 8.5) |
| Database | MySQL or MariaDB. Order tables may be MyISAM, so the plugin never relies on transactions. |

## What was tested where

- **Every release, by harness:** 16 harnesses run on PHP 7.4, 8.0, 8.1, 8.2, 8.3, 8.4 and 8.5: installer, attributes, checkout, consent, renewals and the scheduler, My Subscriptions, the admin page, the Preview Email definitions, a security scan, and a compatibility scan that checks every core function the plugin calls against clean copies of 1.5.8, 2.0, 2.1, 2.2, 2.3 and 3.0.
- **Live, on a Zen Cart 2.2.2 store (PHP 8.4, One Page Checkout with guest checkout on, POSM):** product setup, the Delivery choice and discount, the guest block, consent refusal, signup order and email, every My Subscriptions action, the reminder email, a Renew Now renewal order, the scheduler and its cron job, and every admin action.
- **Not yet live-tested** on 1.5.8 or 3.0.0 stores. Their differences are covered by the harnesses and by reading those releases' code, as below.

## How it fits each release

- **Loading:** observers named `auto.*.php`, plugin storefront pages and templates, admin pages, `extra_datafiles` and `extra_definitions` all load from `zc_plugins` on every supported release. Root-level `filenames.php`/`database_tables.php` load only from 2.2.0, so the plugin uses `extra_datafiles` and guards every `define()`.
- **No payment, shipping or order-total module of its own:** those load from `zc_plugins` only from 2.1.0, so shipping one would mean files outside the plugin on 1.5.8 and 2.0.
- **Installer:** only `executeInstall`, `executeUninstall` and `executeUpgrade` exist before 3.0, so every check runs before anything is written. On 3.0 the plugin also uses `validateDisable()` to take the Delivery choice off product pages.
- **The discount:** on 1.5.8 through 2.3 it's core's attribute price factor (factor `1 - X/100`, offset 1), so the cart, order and totals agree. Zen Cart 3.0.0-dev declares `strict_types` in `shopping_cart.php` and passes the factor column (a database string) to a float parameter, which is a TypeError for any price-factor attribute; on 3.x the plugin writes factor 0 and applies the same discount from `NOTIFY_CART_CALCULATE_ATTRIBUTE_PRICE` / `NOTIFY_CART_ATTRIBUTES_PRICE_NEXT`.
- **Product preview:** the product preview page drops array fields from the form, so the panel posts only scalar fields.
- **One Page Checkout:** guest checkout is switched off through `NOTIFY_OPC_GUEST_CHECKOUT_OVERRIDE` while the cart holds a subscription, with backstops on every later checkout page. Consent is taken at OPC's confirmation (`NOTIFY_CHECKOUT_ONE_CONFIRMATION_PRE_ORDER_CHECK`) and, for card-on-page modules, from the AJAX `prepareConfirmation` call.
- **Off-site and IPN payments:** subscriptions are recorded from order-class notifiers (`NOTIFY_ORDER_DURING_CREATE_ADDED_PRODUCT_LINE_ITEM`, `NOTIFY_ORDER_AFTER_ORDER_CREATE_ADD_PRODUCTS`), so an order created by a PayPal IPN counts too.
- **My Account:** no release has an extension point there, so the link is added to the finished page between `NOTIFY_HTML_HEAD_END` and `NOTIFY_FOOTER_END`. A template that omits those notifiers won't get the link (or the consent box script); the server still enforces consent and the page still works at `index.php?main_page=account_subscriptions`.
- **Admin links:** core's admin `zen_href_link()` already returns `&amp;`-encoded links, so the admin page prints them as they come.
- **Money in the admin:** the admin doesn't create `$currencies` for every page; the admin page creates it as `orders.php` does (3.0 autoloads the class).

## Other plugins

- **Preview Email** (free): lists all five Subscriptions emails, built by the plugin's own code.
- **Products' Options' Stock Manager (POSM):** works; the plugin keeps the catalog product name rather than POSM's decorated order-line name ("[In Stock]").
- **Edit Orders:** renewal orders are ordinary checkout orders. Editing a renewal order doesn't change the subscription.

## Limits in 1.0.0

- No automatic charging (planned for Subscriptions Pro, Authorize.Net first).
- No free or paid trials, prepaid terms, or swapping products in a running subscription.
- Changing the interval takes effect from the next delivery; there's no proration.
