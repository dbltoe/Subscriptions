# Subscriptions for Zen Cart

Sell anything your customers buy again and again as a subscription, on the schedule that fits it: coffee every two weeks, that great jerky every two months, balsamic vinegar every quarter, oil filters every six months. On the product page the customer chooses a one-time purchase or one of the intervals you offer, with an optional subscribe-and-save discount. Before each renewal they're emailed a Renew Now link that fills their cart, and they check out as usual, so it works with every payment module. Customers manage their subscriptions from My Account, and you manage them under Customers > Subscriptions.

Runs on Zen Cart 1.5.8 through 3.0.0 and PHP 7.4 through 8.5 from one codebase, as an encapsulated plugin: no core or template files are changed.

## What's in it

- A Subscription panel on the product edit page: off, optional or required; the intervals offered; a subscribe-and-save discount; an optional number of deliveries.
- The Delivery choice on the product page, as a product option the plugin manages, so every template shows it without edits.
- Checkout: required consent to your terms (saved with each subscription), an account required for subscriptions, a signup email.
- My Subscriptions: Renew Now, skip, pause, resume, change interval or quantities, cancel.
- Pay-link renewals driven by a scheduler (a cron job every 6 hours): reminders, missed renewals moved on, pause after three missed in a row.
- Customers > Subscriptions: search, filters, the full history and consent record, and admin actions.
- Preview Email definitions for all five emails.

Automatic charging of a saved card is planned for a paid add-on, Subscriptions Pro.

## Documentation

- `readme.html`: the store owner's guide (also linked from Plugin Manager).
- `docs/INSTALL.md`: installing, the scheduler, upgrading, uninstalling.
- `docs/CONFIGURATION.md`: every setting and the product panel.
- `docs/CUSTOMIZING.md`: templates, wording, notifiers and session keys.
- `docs/COMPATIBILITY.md`: versions, hooks used, and what was tested where.
- `CHANGELOG.md`: changes by version.

## Layout

- `zc_plugins/Subscriptions/v1.0.1/`: the plugin, exactly as it's uploaded.
  - `shared/`: the classes every side uses, loaded with `require_once` via `__DIR__`.
  - `Installer/`: Plugin Manager install, upgrade and uninstall, plus the Zen Cart 3.0 disable hook.
  - `admin/`: Customers > Subscriptions, the product panel observer, admin text.
  - `catalog/`: the storefront observer, My Subscriptions, the scheduler page, storefront text.
  - `email_preview/`: the Preview Email definitions.

## Source and releases

https://github.com/dbltoe/Subscriptions

## License

GNU General Public License v2.0. See `LICENSE`.
