# Customizing Subscriptions

## My Subscriptions template

The page's template is `catalog/includes/templates/default/templates/tpl_account_subscriptions_default.php` inside the plugin. Copy it to `includes/templates/YOUR_TEMPLATE/templates/` and edit the copy; Zen Cart uses your template's copy first.

Buttons are made with core's `zen_image_submit()`, so they look like your template's other buttons. Keep labels under 30 characters, core's limit for a CSS button.

Useful classes: `#accountSubscriptions`, `.subs-card`, `.subs-actions`, `.subs-block`, `.subs-renew`, `.subs-status-active` (and the other statuses), `.subs-past-heading`.

## Wording

- Storefront and customer emails: `catalog/includes/languages/english/extra_definitions/lang.subscriptions.php`
- Admin: `admin/includes/languages/english/extra_definitions/lang.subscriptions.php`

Both are inside the plugin. To translate, copy the `english` folder on each side to your language's folder name and translate the values.

## The consent checkbox

The checkbox is drawn on the payment step (core `checkout_payment`, One Page Checkout's `checkout_one`) by a small script, placed after OPC's conditions box, before the submit area, or at the end of the form, whichever exists. It's in a `fieldset.subs-consent`. Consent is checked on the server whether or not the template let the script place it, and the customer is told why an order was refused.

## The My Account link

My Account has no extension point on any release, so the link is inserted into the finished page after the newsletters link. A template that already links to `main_page=account_subscriptions` is left alone, so you can place the link yourself.

## Notifiers

| Notifier | Where | Parameters |
|---|---|---|
| `NOTIFY_HEADER_START_ACCOUNT_SUBSCRIPTIONS` | My Subscriptions, before anything runs | |
| `NOTIFY_HEADER_END_ACCOUNT_SUBSCRIPTIONS` | My Subscriptions, before the template | `$subscriptions` is set |
| `NOTIFY_SUBSCRIPTIONS_SCHEDULER_END` | The scheduler, after its pass | `$param2` (by reference): the report array with `reminded`, `missed`, `paused`, `errors`, `locked`. Add to `errors` to have a line printed. Subscriptions Pro charges saved cards here. |

## Session keys

- `subscriptions_consent`: the consent for the current cart (time, IP, text, its hash, and a hash of the subscription lines it covers).
- `subscriptions_renewal`: the lines a Renew Now link put in the cart, by subscription: `[subscriptions_id => ['due' => Y-m-d, 'prids' => [...]]]`. Those lines need no fresh consent and renew that subscription instead of starting a new one. Cleared when an order is written.

## Preview Email

`email_preview/subscriptions.php` returns the definitions Preview Email lists under Subscriptions. Each one calls the same static compose method the plugin sends with (`SubscriptionsCheckout::composeSignupEmail`, `SubscriptionsManager::composeChangeEmail`, `SubscriptionsRenewals::composeReminderEmail`, `SubscriptionsRenewals::composeLapsedEmail`), so a wording change shows in the preview at once. Every `zen_mail()` call in the plugin carries a `// Preview Email: <key>` comment naming its definition.

## Tables

`subscriptions` (one row per subscription), `subscriptions_products` (its lines), `subscriptions_history` (every event, with who did it), `subscriptions_intervals` (which Delivery value stands for which interval) and `products_subscription` (each product's panel settings). Change them through the plugin's pages: the history and the scheduler rely on their invariants.
