# Subscriptions

Version 1.0.3 · Zen Cart 1.5.8 through 3.0.0 · PHP 7.4 through 8.5 · GPL v3 · by My Zen Cart Host (dbltoe)

Sell anything your customers buy again and again as a subscription, with renewals by an emailed pay link that works with every payment module.

## What it does

Subscriptions lets you sell anything your customers buy again and again, on the schedule that fits it: coffee every two weeks, that great jerky every two months, balsamic vinegar every quarter, oil filters every six months. You choose the intervals each product offers, from every few days to once a year.

- On the product page the customer picks **One-Time Purchase** or a delivery interval such as **Every 2 Weeks**, with an optional subscribe-and-save discount.
- At checkout they agree to your subscription terms, and the order starts the subscription.
- Before each renewal they get an email with a **Renew Now** link. It fills their cart and they check out as usual, with any payment module you have.
- They can skip, pause, resume, change the interval or quantities, and cancel from **My Subscriptions** in their account.
- You see and manage every subscription under **Customers > Subscriptions**.

This plugin never charges anyone automatically. Charging a saved card automatically is planned for a paid add-on, Subscriptions Pro.

It changes no core file and no template file. The delivery choice is an ordinary product option the plugin manages, so every template already knows how to show it.

## Requirements

- Zen Cart 1.5.8a, 2.0.x, 2.1.0, 2.2.x, 2.3.x or 3.0.0.
- PHP 7.4 through 8.5.
- A way to open a web address every 6 hours: a cron job (every cPanel host has one) or an outside cron service. See Setting up the scheduler.
- Customers need an account to subscribe. Guest checkout still works for everything else.

## Installing

1. Upload the `zc_plugins/Subscriptions` folder from this package into your store's `zc_plugins` folder, so you have `zc_plugins/Subscriptions/v1.0.3/manifest.php`.
2. In the admin, go to **Modules > Plugin Manager**, select **Subscriptions** and click **Install**.
3. Set up the scheduler (next section).
4. Check the settings under **Configuration > Subscriptions**, especially **Subscription Terms**.
5. Turn subscriptions on for a product (Setting up a product).

The installer creates five tables (`subscriptions`, `subscriptions_products`, `subscriptions_history`, `subscriptions_intervals` and `products_subscription`, each with your table prefix), a product option named **Delivery**, the settings, and the admin page. If a table of the same name already exists and belongs to something else, it stops before writing anything and tells you.

Once it's installed, a superuser can open this guide from the **ReadMe** button in Plugin Manager.

## Setting up the scheduler

Zen Cart has no scheduler of its own, so the plugin answers on a secret address that something has to open every few hours; every 6 hours is plenty, and once a day still works, because all its timing is by date. Each run sends the renewal reminders that are due and moves on any renewals that weren't placed in time.

The address and a ready-made cron command are on **Customers > Subscriptions** (open **Scheduler Setup**) and in the description of **Configuration > Subscriptions > Scheduler Key**. The command looks like this:

```
curl -fsSL "https://www.example.com/index.php?main_page=subscriptions_cron&key=YOUR-KEY" >/dev/null
```

### With cPanel

1. Open **Cron Jobs**.
2. Under **Add New Cron Job**, enter **Minute** 17, **Hour** `*/6`, and `*` for Day, Month and Weekday (every 6 hours, at 17 past, which avoids the top-of-the-hour rush on shared servers).
3. Paste the command from your admin into **Command** and click **Add New Cron Job**.

### Without cron

Any outside cron service that can open a web address on a schedule will do. Give it the address (without `curl`) and set it to every 6 hours.

### Checking it

- **Customers > Subscriptions** shows when the scheduler last ran, in red when it hasn't run for 25 hours.
- **Run Renewals Now** on the same page runs it in a new tab and shows what it did. It's safe any time: each reminder is sent once.
- A wrong or missing key gets a bare *Forbidden*. Keep the key to yourself.

> While your store is in Down for Maintenance, or set so customers must log in before browsing, the scheduler can't run either. Reminders wait until the store is open.

## Configuration

**Configuration > Subscriptions**:

| Setting | What it does |
| --- | --- |
| Offer Subscriptions on Product Pages? | When false, the Delivery choice comes off every product page. Existing subscriptions keep renewing. Default true. |
| Intervals Offered | The intervals you can tick on a product, separated by commas: `1 week, 2 weeks, 1 month, 2 months, 3 months` by default. Use a number and day, week or month, up to 365 days, 52 weeks or 12 months. |
| Default Subscribe-and-Save Discount (%) | Filled in when you first turn subscriptions on for a product. 0 for none, at most 90. |
| Reminder E-Mail, Days Before Renewal | How many days ahead of each renewal the Renew Now email goes out. Default 3, from 0 (on the day) to 60. |
| Subscription Terms | Shown beside the consent box at checkout and repeated in the signup email. The exact text each customer agreed to is saved with their subscription. Write it for your store; see Auto-renewal laws. |
| Delete Subscription Data on Uninstall? | Default false: uninstalling keeps every subscription so a reinstall carries on. See Uninstalling. |
| Delivery Option ID | The product option the plugin manages. You can rename it in the Option Name Manager; don't delete it. |
| Product Pages Last Updated For | Maintained by the plugin. |
| Scheduler Key | The secret in the scheduler address, made at install. Its description has your cron command. |
| Scheduler Last Ran | Set by the scheduler each time it runs. |

## Setting up a product

Edit the product in **Catalog > Categories/Products**. Below the usual fields there's a **Subscription** panel:

- **Subscription**: Off (one-time purchase only), Optional (the customer chooses one-time or an interval) or Required (sold as a subscription only).
- **Intervals Offered**: tick the intervals this product is sold at, from your store-wide list.
- **Subscribe-and-Save Discount**: a percentage off the product price, or its sale price, on every subscription delivery. The customer sees it beside each interval.
- **Number of Deliveries**: 0 to continue until the customer cancels, or a number to end the subscription after that many deliveries, the first order included.

Save (through the preview, as usual) and the product page shows the **Delivery** choice. With Optional, One-Time Purchase comes first and is selected; with Required, the shortest interval is.

Don't edit the Delivery rows by hand in the Attributes Controller. The plugin rebuilds them from the panel and would put them back.

## What the customer sees

- **Product page**: a Delivery dropdown such as *One-Time Purchase*, *Every 2 Weeks ( -$0.40 )*, *Every Month ( -$0.40 )*. The cart, checkout and order show the choice like any other option.
- **An account**: while a subscription is in the cart, One Page Checkout's guest option is switched off and a guest is asked to log in or create an account.
- **Consent**: on the payment step, your Subscription Terms with a required checkbox. If it isn't ticked, the order isn't placed. If the customer changes the subscription part of the cart afterward, they're asked again.
- **Signup email**: right after the order confirmation, with the subscription's items, interval, price at today's prices, next renewal date, the terms they agreed to, and a link to My Subscriptions.

## My Subscriptions

Customers with a subscription get a *View or change my subscriptions.* link on My Account. The page lists each subscription with its items, next delivery and deliveries so far, and lets them:

- **Renew Now**, once the reminder for the next delivery is due.
- **Skip Next Delivery**, **Pause** and **Resume**. A resumed subscription picks up on its schedule after today; deliveries that fell in the pause aren't sent late.
- **Change Interval**, to any interval every product in the subscription is still offered at, from the next delivery on.
- **Update Quantities**, 1 to 999 each.
- **Cancel Subscription**, with a confirmation step and an optional reason. Pausing and canceling are confirmed by email.

## How renewals work

1. **The reminder.** The set number of days before a delivery is due, the scheduler emails a Renew Now link.
2. **Renew Now.** The link, or the button on My Subscriptions, puts the subscription's items in the cart with the same options as last time and the subscribe-and-save price. A customer who isn't logged in logs in first and comes straight back.
3. **Checkout as usual.** Today's prices, today's shipping, any payment module. No second consent is asked for.
4. **The order renews the subscription.** It moves to its next date, the delivery is counted, and the order gets an admin-only note: *Renewal of subscription #12 (Every Month), delivery due ...*. The customer gets your normal order confirmation.

### Renewals that aren't placed

The link works until the next delivery's reminder is due (for intervals shorter than the reminder lead, until the day after the due date). A delivery not ordered by then is skipped, the subscription moves to its next date, and the customer is reminded about that one.

After **three** missed renewals in a row, each one reminded, the subscription is paused and the customer is told by email. Deliveries they were never reminded about (say the scheduler was off) don't count. Resuming starts the count again.

### Fixed terms

A subscription with a set number of deliveries ends as *Completed* when the last one is ordered.

## Customers > Subscriptions

- **The list**: counts by status (Active, Paused, Payment Due, Canceled, Completed) that work as filters, and a search for a subscription number, an order number, or part of a customer's name or email address. 50 to a page.
- **A subscription**: the customer, items and last prices, dates, first and last orders, payment mode, whether the reminder for the next delivery has gone out, missed renewals so far, the consent record (when, from which IP address, and the exact terms), and the full history with who did what.
- **Actions**: Skip Next Delivery, Pause, Resume, Change Date (moves the next delivery, and the schedule counts from it) and Cancel Subscription. Pause and Cancel can send the customer the same confirmation they'd get doing it themselves. Your changes follow the same rules as the customer's and show in the history with your admin name.
- **The scheduler**: when it last ran, the cron command, and Run Renewals Now.

## The emails

| E-Mail | Sent |
| --- | --- |
| Signup Confirmation | After the order confirmation, when an order starts a subscription. |
| Renewal Reminder | By the scheduler, ahead of each delivery, with the Renew Now link. |
| Subscription Paused | When the customer pauses a subscription, or you do with the box ticked. |
| Subscription Canceled | When the customer cancels one, or you do with the box ticked. |
| Paused After Missed Renewals | By the scheduler, after three missed renewals in a row. |

All of them go through Zen Cart's normal mail, with your store's email template and settings. The renewal order's receipt is your normal order confirmation.

With the free **Preview Email** plugin (Tools > Preview Email), every one of these is listed under **Subscriptions**. Each preview is built by the same code that sends the real email, so what you see is what customers get, and you can send yourself a test copy.

## Auto-renewal laws

Several US states (California's law is the strictest) require four things of automatically renewing offers: clear terms before purchase, affirmative consent, a confirmation that repeats the terms, and a way to cancel online. The plugin gives you all four: the terms and a required checkbox at checkout, the signup email with the agreed terms, and Cancel Subscription on My Subscriptions. It saves the time, IP address and exact text of each customer's consent.

What your terms say is up to you and your advisor; this isn't legal advice. Say how often it renews, that the customer places each renewal order from the emailed link, and how to cancel.

## Payment modules and Subscriptions Pro

Because each renewal is a normal checkout, this plugin works with every payment module, including off-site ones such as PayPal. It never sees or stores card details.

**Subscriptions Pro**, a paid add-on in development, will add automatic renewal through Authorize.Net: the card from the first order saved at Authorize.Net, and each renewal order created and charged on the due date, with retries and a pay-link fallback when a charge fails. Until then, every renewal is a pay-link renewal.

## Customizing

- **My Subscriptions page**: copy `zc_plugins/Subscriptions/v1.0.3/catalog/includes/templates/default/templates/tpl_account_subscriptions_default.php` to `includes/templates/YOUR_TEMPLATE/templates/` and edit the copy. Your copy wins.
- **Wording**: every customer-facing word is in the plugin's `catalog/includes/languages/english/extra_definitions/lang.subscriptions.php`, and the admin's in `admin/includes/languages/english/extra_definitions/lang.subscriptions.php`.
- **The option name**: rename *Delivery* in the Option Name Manager if you'd rather call it something else.
- **The account link**: it's added after the newsletters link on My Account. A template that already links to `main_page=account_subscriptions` is left alone.

More detail, including the notifiers other plugins can use, is in `docs/CUSTOMIZING.md` in the download.

## Translating

Copy the two `english` folders inside the plugin (`catalog/includes/languages/english` and `admin/includes/languages/english`) to a folder named for your language, for example `spanish`, and translate the values, not the names. The interval labels (*Every %u Weeks* and so on) are in both files. The Delivery option's values take their names from those labels when the plugin creates them.

## Zen Cart 3.0 notes

- Zen Cart 3.0's cart currently fails on attribute price factors, which is how the plugin applies the discount on 1.5.8 through 2.3. On 3.0 the plugin applies the same discount another way, automatically.
- 3.0's Plugin Manager can **disable** a plugin without uninstalling it. Disabling Subscriptions takes the Delivery choice off product pages; enabling puts it back. Earlier releases have no disable, so use **Offer Subscriptions on Product Pages?** or Uninstall.

## Upgrading

Upload the new version folder beside the old one, then click **Upgrade** in Plugin Manager. Your settings and subscriptions are kept, and the settings' wording is refreshed. When the upgrade is done you can delete the old version folder.

**Version 1.0.3** moves the plugin to the GNU General Public License, version 3, and replaces readme.html with this guide: the ReadMe button in Plugin Manager now opens it inside the admin, for superusers, in its own window. Nothing changes on the storefront, in the settings, in the data or in the emails.

## Uninstalling

- Uninstalling always takes the Delivery choice off every product page, so nobody can choose a discounted subscription that would never renew.
- With **Delete Subscription Data on Uninstall?** at false (the default), the subscriptions, product settings, the Delivery option and the scheduler key are kept. Install again and everything carries on, and your cron job keeps working.
- Set it to true first to remove the tables, the option and every setting.
- Remove the cron job if you're not reinstalling.

## Troubleshooting

### The Delivery choice doesn't show on a product

Check that **Offer Subscriptions on Product Pages?** is true, that the product's Subscription is Optional or Required, and that at least one interval is ticked. After changing the store-wide setting, open any admin page once; the product pages are updated then.

### No reminders are going out

Look at **Customers > Subscriptions**. If the scheduler has never run or hasn't run lately, check the cron job and try **Run Renewals Now**. If it ran but sent nothing, no delivery is inside the reminder window yet: each subscription's page says when its reminder is due.

### The scheduler says Forbidden

The key in the address doesn't match **Scheduler Key**. Copy the command again from Customers > Subscriptions.

### A customer says the Renew Now link has expired

The delivery was already renewed, skipped or missed, or a newer reminder has gone out. If the next delivery is ready to order, My Subscriptions has a Renew Now button for it.

### A customer can't check out as a guest

That's on purpose while the cart holds a subscription: they need an account to manage it. A one-time purchase still allows guest checkout.

## Support

Support is through the plugin's thread on the Zen Cart forum (https://www.zen-cart.com/threads/207379), which is also linked from the plugin's Plugin Manager panel, and My Zen Cart Host. The source, releases and issue tracker are on GitHub (https://github.com/dbltoe/Subscriptions).

## Changelog

See `CHANGELOG.md`. Version 1.0.3 moves the plugin to the GNU General Public License, version 3, and replaces readme.html with this guide, opened from the ReadMe button in Plugin Manager.

## License

Subscriptions is free software under the GNU General Public License, version 3 (https://www.gnu.org/licenses/gpl-3.0.html); the full text is in `LICENSE`. Copyright © 2026 My Zen Cart Host (dbltoe).
