# Changelog

## [1.0.1] - 2026-10-04

### Fixed

- Upgrading from an earlier version in Plugin Manager no longer stops with "Cannot redeclare class". The Upgrade runs the new version's installer in an admin request that already holds the installed version's classes, loaded from that version's folder, so every require of a plugin class now happens only when the class isn't loaded yet. Upgrading from 1.0.0 to this version already works because of it. Nothing else changed: no settings, data or emails.

Upgrade in Plugin Manager: upload the v1.0.1 folder beside v1.0.0, click Upgrade, then Upgrade again on the confirmation screen.

## [1.0.0] - 2026-10-03

The first release: subscriptions with pay-link renewals, for Zen Cart 1.5.8 through 3.0.0 on PHP 7.4 through 8.5.

- Product setup: a Subscription panel on the product edit page (off, optional or required), the intervals offered, a subscribe-and-save discount and an optional number of deliveries.
- The storefront choice is a product option the plugin manages ("Delivery"), so it works in every template without edits. The discount uses core's attribute price factor, which keeps the cart, the order and the totals in agreement.
- Turning off "Offer subscriptions" takes the choice off every product page, and turning it on puts it back. Uninstalling always removes it from product pages, and on Zen Cart 3.0 so does disabling.
- The installer refuses, before writing anything, when a table of the same name belongs to another plugin.
- Checkout: placing an order with a delivery interval starts a subscription (one per interval in the order), recording the products, price, shipping share, currency and first renewal date, with an admin-only note on the order.
- A required consent checkbox with the store's subscription terms on the payment step of both the standard checkout and One Page Checkout. The exact terms, time and IP are saved with the subscription, and an order whose consent doesn't match the cart is refused.
- Guests can't buy a subscription: One Page Checkout's guest option is switched off while the cart holds one.
- A signup email with each subscription's items, interval, price at today's prices, next renewal date and the agreed terms.
- Zen Cart 3.0: the subscribe-and-save discount is applied without core's price factor, which fatals there.
- My Subscriptions page in My Account: each subscription with its items, next delivery and deliveries so far, and Skip Next Delivery, Pause / Resume, Change Interval (to any interval every product in it is still offered at), Update Quantities and Cancel Subscription (with a confirmation and an optional reason). Pausing and canceling are confirmed by email. Every change is recorded in the subscription's history.
- A "View or change my subscriptions." link on My Account, after the newsletters link, for customers who have a subscription.
- The signup email's manage link goes to My Subscriptions; the subscription keeps the catalog product name (not one decorated by another plugin) and the shipping module code exactly as the order stores it; free shipping reads "with free shipping".
- Renewals by pay link: a scheduler (a cron job every 6 hours or an outside cron service opening the scheduler address shown under Configuration > Subscriptions > Scheduler Key) emails a Renew Now link the set number of days before each renewal. The link (or the Renew Now button on My Subscriptions) puts the subscription's items in the cart with the last order's choices, and the customer checks out as usual at that day's prices. That order moves the subscription to its next date; it needs no fresh consent and never starts a second subscription. Nothing is ever charged automatically.
- A delivery not ordered by the time the next reminder is due is skipped and the customer is reminded about the next one. After three in a row the subscription is paused and the customer is told. Resuming starts the count again.
- The scheduler takes a database lock so two runs never overlap, sends each reminder once, and only a hash of each link's token is stored.
- Preview Email definitions for the renewal reminder and the paused-after-missed-renewals notice.
- Customers > Subscriptions in the admin: counts by status as filters, search by subscription or order number or by customer name or email, 50 to a page. Each subscription's page shows the customer, items, dates, orders, payment mode, reminder state, the consent record (time, IP and the exact terms) and the full history, with who did what.
- Admin actions: Skip Next Delivery, Pause / Resume, Change Date (the next delivery) and Cancel Subscription with an optional reason, each optionally confirmed to the customer by the same email they'd get doing it themselves. The admin's changes obey the same rules as the customer's and are recorded with the admin's name.
- The admin page shows when the scheduler last ran (and warns when it has stopped), the cron command to set it up, and a Run Renewals Now button.

[1.0.1]: https://github.com/dbltoe/Subscriptions/releases/tag/v1.0.1
[1.0.0]: https://github.com/dbltoe/Subscriptions/releases/tag/v1.0.0
