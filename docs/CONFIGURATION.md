# Configuring Subscriptions

## Configuration > Subscriptions

| Setting | Default | What it does |
|---|---|---|
| Offer Subscriptions on Product Pages? | true | False takes the Delivery choice off every product page. Existing subscriptions keep renewing. The change reaches product pages the next time any admin page is opened. |
| Intervals Offered | `1 week, 2 weeks, 1 month, 2 months, 3 months` | The intervals you can tick per product, comma separated: a number and day, week or month, up to 365 days, 52 weeks or 12 months. Nonsense and duplicates are dropped. |
| Default Subscribe-and-Save Discount (%) | 0 | Filled in when you first turn subscriptions on for a product. 0 to 90. |
| Reminder E-Mail, Days Before Renewal | 3 | When the Renew Now email goes out, 0 (the due date) to 60 days ahead. The link then works until the next delivery's reminder is due. |
| Subscription Terms | (sample text) | Shown beside the required consent checkbox at checkout and repeated in the signup email. The exact text, time and IP address of each customer's consent are saved with the subscription. If you change the terms, a customer who agreed to the old ones in the same session is asked again. |
| Delete Subscription Data on Uninstall? | false | See INSTALL.md. |
| Delivery Option ID | (set) | The product option the plugin manages. Rename it in the Option Name Manager if you like; don't delete it. |
| Product Pages Last Updated For | (set) | Maintained by the plugin. |
| Scheduler Key | (generated) | The secret in the scheduler address. The description holds your cron command. |
| Scheduler Last Ran | (set) | Written by each scheduler run. |

## The product panel

In **Catalog > Categories/Products**, edit a product:

- **Subscription**: Off, Optional (One-Time Purchase is listed first and selected), or Required (no one-time choice; the shortest interval is selected).
- **Intervals Offered**: tick any of the store-wide intervals. If an interval is later removed from the store-wide list, the panel says so and drops it when you save.
- **Subscribe-and-Save Discount**: percent off the product's price, or its sale price, on subscription deliveries.
- **Number of Deliveries**: 0 for open-ended. Otherwise the subscription ends (as Completed) after that many deliveries, the first order included. When an order mixes products with different limits, the smallest non-zero one applies.

Save through the preview as usual. The plugin writes the product's Delivery rows from these settings. Don't edit those rows in the Attributes Controller; they'd be rebuilt.

## How subscriptions are grouped

One order starts one subscription per interval: two products at Every 2 Weeks and one at Every Month make two subscriptions. Each keeps its share of the order's shipping for the signup email's price line; renewals are priced fresh at checkout.

## Renewal timing

For a subscription due on the 31st, monthly, with a 3-day reminder:

- the reminder goes out on the 28th;
- Renew Now works until the next month's reminder is due;
- after that the delivery counts as missed and the subscription moves to the next month (end-of-month dates stay at month end);
- three reminded misses in a row pause it and tell the customer.

Change Date on the admin page moves the next delivery, and the schedule counts from the new date.
