<?php
/**
 * Subscriptions -- storefront text (English).
 *
 * @package  Subscriptions
 * @license  http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

$define = [
    'SUBSCRIPTIONS_ONE_TIME' => 'One-Time Purchase',
    'SUBSCRIPTIONS_EVERY_DAY' => 'Every Day',
    'SUBSCRIPTIONS_EVERY_WEEK' => 'Every Week',
    'SUBSCRIPTIONS_EVERY_MONTH' => 'Every Month',
    'SUBSCRIPTIONS_EVERY_N_DAYS' => 'Every %u Days',
    'SUBSCRIPTIONS_EVERY_N_WEEKS' => 'Every %u Weeks',
    'SUBSCRIPTIONS_EVERY_N_MONTHS' => 'Every %u Months',

    'SUBSCRIPTIONS_CONSENT_HEADING' => 'Subscription Terms',
    'SUBSCRIPTIONS_CONSENT_LABEL' => 'I agree to the subscription terms above.',
    'SUBSCRIPTIONS_ERROR_CONSENT' => 'Your cart has a subscription. Please tick the box to agree to the subscription terms before placing your order.',
    'SUBSCRIPTIONS_ERROR_GUEST' => 'Your cart has a subscription, which needs an account so you can manage it later. Please log in or create an account to continue.',

    'SUBSCRIPTIONS_NOTE_STARTED' => 'Started subscription #%1$u (%2$s).',
    'SUBSCRIPTIONS_NOTE_GUEST' => 'This order included subscription items but was placed without a customer account, so no subscription was started.',

    'SUBSCRIPTIONS_EMAIL_SUBJECT' => 'Your subscription from %s',
    'SUBSCRIPTIONS_EMAIL_INTRO' => 'Thank you for subscribing. Here are the details of the subscription your order #%u started.',
    'SUBSCRIPTIONS_EMAIL_HEADING' => 'Subscription #%1$u: %2$s',
    'SUBSCRIPTIONS_EMAIL_EACH' => 'At today\'s prices, each delivery is %1$s for the items, tax included, plus %2$s shipping.',
    'SUBSCRIPTIONS_EMAIL_EACH_FREE' => 'At today\'s prices, each delivery is %s for the items, tax included, with free shipping.',
    'SUBSCRIPTIONS_EMAIL_NEXT' => 'Next renewal: %s',
    'SUBSCRIPTIONS_EMAIL_ENDS' => 'Ends after %u deliveries, this order included.',
    'SUBSCRIPTIONS_EMAIL_UNTIL_CANCELED' => 'Continues until you cancel.',
    'SUBSCRIPTIONS_EMAIL_TERMS' => 'The terms you agreed to:',
    'SUBSCRIPTIONS_EMAIL_MANAGE' => 'Skip, pause or cancel any time: %s',
    'SUBSCRIPTIONS_EMAIL_MANAGE_HTML' => 'Skip, pause or cancel any time:',

    // My Account link (sentence case, like core's own account links)
    'SUBSCRIPTIONS_ACCOUNT_LINK' => 'View or change my subscriptions.',

    // My Subscriptions page
    'SUBSCRIPTIONS_PAGE_NAVBAR_ACCOUNT' => 'My Account',
    'SUBSCRIPTIONS_PAGE_HEADING' => 'My Subscriptions',
    'SUBSCRIPTIONS_PAGE_NONE' => 'You don\'t have any subscriptions.',
    'SUBSCRIPTIONS_PAGE_PAST' => 'Past Subscriptions',
    'SUBSCRIPTIONS_PAGE_CARD_HEADING' => 'Subscription #%1$u: %2$s',
    'SUBSCRIPTIONS_PAGE_NEXT' => 'Next delivery:',
    'SUBSCRIPTIONS_PAGE_PAUSED_NOTE' => 'Paused. Nothing will be sent until you resume it.',
    'SUBSCRIPTIONS_PAGE_CANCELED_ON' => 'Canceled on %s.',
    'SUBSCRIPTIONS_PAGE_DELIVERIES' => 'Deliveries so far: %u',
    'SUBSCRIPTIONS_PAGE_DELIVERIES_OF' => 'Deliveries so far: %1$u of %2$u',
    'SUBSCRIPTIONS_PAGE_STARTED' => 'Started %s',
    'SUBSCRIPTIONS_PAGE_INTERVAL_LABEL' => 'Deliver',
    'SUBSCRIPTIONS_PAGE_QUANTITIES' => 'Quantities',
    'SUBSCRIPTIONS_PAGE_CANCEL_SURE' => 'Cancel this subscription? No more deliveries will be made. Orders already placed aren\'t affected.',
    'SUBSCRIPTIONS_PAGE_CANCEL_REASON' => 'Would you tell us why? (optional)',

    'SUBSCRIPTIONS_STATUS_ACTIVE' => 'Active',
    'SUBSCRIPTIONS_STATUS_PAUSED' => 'Paused',
    'SUBSCRIPTIONS_STATUS_PAST_DUE' => 'Payment Due',
    'SUBSCRIPTIONS_STATUS_CANCELED' => 'Canceled',
    'SUBSCRIPTIONS_STATUS_EXPIRED' => 'Completed',

    'SUBSCRIPTIONS_BUTTON_SKIP' => 'Skip Next Delivery',
    'SUBSCRIPTIONS_BUTTON_PAUSE' => 'Pause',
    'SUBSCRIPTIONS_BUTTON_RESUME' => 'Resume',
    'SUBSCRIPTIONS_BUTTON_INTERVAL' => 'Change Interval',
    'SUBSCRIPTIONS_BUTTON_QUANTITIES' => 'Update Quantities',
    'SUBSCRIPTIONS_BUTTON_CANCEL' => 'Cancel Subscription',
    'SUBSCRIPTIONS_BUTTON_CANCEL_YES' => 'Yes, Cancel It',
    'SUBSCRIPTIONS_BUTTON_CANCEL_KEEP' => 'Keep My Subscription',

    'SUBSCRIPTIONS_PAGE_SKIPPED' => 'Subscription #%u: the next delivery is skipped.',
    'SUBSCRIPTIONS_PAGE_PAUSED' => 'Subscription #%u is paused.',
    'SUBSCRIPTIONS_PAGE_RESUMED' => 'Subscription #%u is active again.',
    'SUBSCRIPTIONS_PAGE_INTERVAL_CHANGED' => 'Subscription #%u: the interval is changed, starting after the next delivery.',
    'SUBSCRIPTIONS_PAGE_QUANTITIES_CHANGED' => 'Subscription #%u: the quantities are updated.',
    'SUBSCRIPTIONS_PAGE_CANCELED' => 'Subscription #%u is canceled. We\'ve emailed you a confirmation.',
    'SUBSCRIPTIONS_PAGE_UNCHANGED' => 'Nothing was changed.',
    'SUBSCRIPTIONS_PAGE_BAD_QUANTITY' => 'Quantities must be whole numbers from 1 to 999. To stop an item completely, cancel the subscription.',
    'SUBSCRIPTIONS_PAGE_BAD_INTERVAL' => 'That interval isn\'t available for this subscription.',
    'SUBSCRIPTIONS_PAGE_NOT_ALLOWED' => 'That change isn\'t possible for this subscription right now.',

    'SUBSCRIPTIONS_EMAIL_CANCELED_SUBJECT' => 'Your subscription #%u is canceled',
    'SUBSCRIPTIONS_EMAIL_CANCELED' => 'Your subscription #%1$u (%2$s) is canceled. No more deliveries will be made.',
    'SUBSCRIPTIONS_EMAIL_PAUSED_SUBJECT' => 'Your subscription #%u is paused',
    'SUBSCRIPTIONS_EMAIL_PAUSED' => 'Your subscription #%1$u (%2$s) is paused. Nothing will be sent until you resume it.',
    'SUBSCRIPTIONS_EMAIL_SEE' => 'See your subscriptions: %s',

    // Renewals
    'SUBSCRIPTIONS_BUTTON_RENEW' => 'Renew Now',
    'SUBSCRIPTIONS_PAGE_RENEW_READY' => 'Your delivery due %s is ready to order.',
    'SUBSCRIPTIONS_PAGE_RENEW_ADDED' => 'Subscription #%1$u: the items for the delivery due %2$s are in your cart. Check out as usual to place the renewal order.',
    'SUBSCRIPTIONS_PAGE_RENEW_UNAVAILABLE' => 'These aren\'t available at your subscription\'s interval any more, so they weren\'t added: %s.',
    'SUBSCRIPTIONS_PAGE_RENEW_NOTHING' => 'None of the items in subscription #%u can be ordered right now, so nothing was added to your cart.',
    'SUBSCRIPTIONS_PAGE_RENEW_EXPIRED' => 'That renewal link has expired or was already used. A delivery that\'s ready to order has a Renew Now button below.',
    'SUBSCRIPTIONS_PAGE_RENEW_NOT_DUE' => 'That delivery isn\'t ready to order yet.',

    'SUBSCRIPTIONS_NOTE_RENEWED' => 'Renewal of subscription #%1$u (%2$s), delivery due %3$s.',
    'SUBSCRIPTIONS_NOTE_RENEWAL_LATE' => 'This order renews subscription #%u, but that delivery was already renewed, skipped or missed, or the subscription is no longer active, so the subscription was not changed.',

    'SUBSCRIPTIONS_REMIND_SUBJECT' => 'Time to renew subscription #%1$u: delivery due %2$s',
    'SUBSCRIPTIONS_REMIND_INTRO' => 'Your next delivery for subscription #%1$u (%2$s) is due on %3$s.',
    'SUBSCRIPTIONS_REMIND_LAST_PRICE' => 'Last time these came to %s, tax included. Checkout shows today\'s prices and adds shipping.',
    'SUBSCRIPTIONS_REMIND_HOW' => 'Nothing is charged automatically. To get this delivery, place the renewal order: the link puts these items in your cart and you check out as usual.',
    'SUBSCRIPTIONS_REMIND_LINK' => 'Renew Now: %s',
    'SUBSCRIPTIONS_REMIND_BUTTON' => 'Renew Now',
    'SUBSCRIPTIONS_REMIND_UNTIL' => 'The link works through %s.',
    'SUBSCRIPTIONS_REMIND_MANAGE' => 'Don\'t need this one? Skip it, pause or cancel from My Subscriptions: %s',
    'SUBSCRIPTIONS_REMIND_MANAGE_HTML' => 'Don\'t need this one? Skip it, pause or cancel from My Subscriptions:',

    'SUBSCRIPTIONS_LAPSED_SUBJECT' => 'We\'ve paused your subscription #%u',
    'SUBSCRIPTIONS_LAPSED_TEXT' => 'We\'ve paused your subscription #%1$u (%2$s) because the last %3$u renewals weren\'t ordered. Nothing more will be sent and you won\'t get any more reminders.',
    'SUBSCRIPTIONS_LAPSED_RESUME' => 'You can resume it any time from My Subscriptions: %s',
];

return $define;
