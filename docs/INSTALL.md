# Installing Subscriptions

## Requirements

- Zen Cart 1.5.8a, 2.0.x, 2.1.0, 2.2.x, 2.3.x or 3.0.0.
- PHP 7.4 through 8.5.
- Something that opens a web address once an hour: a cron job, or an outside cron service.

## Install

1. Upload `zc_plugins/Subscriptions` from this package into your store's `zc_plugins` folder, so `zc_plugins/Subscriptions/v1.0.0/manifest.php` exists.
2. In the admin, open **Modules > Plugin Manager**, select **Subscriptions**, and click **Install**.
3. Set up the scheduler (below).
4. Review **Configuration > Subscriptions**, above all **Subscription Terms**.
5. Edit a product and set its **Subscription** panel to Optional or Required.

The installer creates five tables (`subscriptions`, `subscriptions_products`, `subscriptions_history`, `subscriptions_intervals`, `products_subscription`, with your table prefix), a product option named Delivery, the settings, and two admin pages: **Configuration > Subscriptions** and **Customers > Subscriptions**. If a table with one of those names exists and isn't shaped like the plugin's, the install stops before writing anything and says which table.

## The scheduler

The scheduler sends renewal reminders and moves on renewals that weren't placed. It runs when this address is opened:

```
https://www.example.com/index.php?main_page=subscriptions_cron&key=YOUR-KEY
```

Your store's exact address and a ready-made command are on **Customers > Subscriptions** (Scheduler Setup) and in the description of **Configuration > Subscriptions > Scheduler Key**.

**cPanel:** Cron Jobs > Common Settings > Once Per Hour, then paste:

```
curl -fsSL "https://www.example.com/index.php?main_page=subscriptions_cron&key=YOUR-KEY" >/dev/null
```

**No cron:** have an outside cron service open the address hourly.

Customers > Subscriptions shows when the scheduler last ran and warns in red once it hasn't run for three hours. **Run Renewals Now** runs it on demand in a new tab. A wrong key gets a bare 403 Forbidden.

The scheduler is a normal storefront request, so Down for Maintenance, or a setting that makes customers log in before browsing, stops it too.

## Upgrade

Upload the new version folder beside the old one and click **Upgrade** in Plugin Manager. Settings, subscriptions and the scheduler key are kept; the settings' titles and descriptions are refreshed. Delete the old version folder afterward.

## Uninstall

- The Delivery choice always comes off every product page.
- With **Delete Subscription Data on Uninstall?** false (the default), the tables, product settings, the Delivery option and the **scheduler key** are kept, so a reinstall carries on and the cron job keeps working.
- Set it to true before uninstalling to remove the tables, the option and every setting.
- Remove the cron job if you aren't reinstalling.

On Zen Cart 3.0, **Disable** in Plugin Manager also takes the Delivery choice off product pages, and **Enable** puts it back. Earlier releases have no disable.
