<?php
/**
 * Subscriptions -- the emails this plugin sends, for Preview Email.
 *
 * Preview Email (Tools > Preview Email) reads every installed plugin's
 * <version>/email_preview/*.php. Each entry below builds its email with the
 * same compose method the plugin sends it with -- only the data is sample
 * data -- so what the store owner previews is the real email.
 *
 * Every zen_mail() call in this plugin is marked "Preview Email: <key>" and a
 * harness checks that each key is defined here.
 *
 * @package  Subscriptions
 * @license  http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

if (!class_exists('SubscriptionsCheckout', false)) {
    require_once dirname(__DIR__) . '/shared/SubscriptionsCheckout.php';
}
if (!class_exists('SubscriptionsManager', false)) {
    require_once dirname(__DIR__) . '/shared/SubscriptionsManager.php';
}
if (!class_exists('SubscriptionsRenewals', false)) {
    require_once dirname(__DIR__) . '/shared/SubscriptionsRenewals.php';
}

if (!function_exists('subscriptions_preview_setup')) {
    /**
     * Load the plugin's storefront text, and return sample data: a product
     * the store has set up for subscriptions when there is one (else a made-up
     * one), its first offered interval, and a money formatter.
     */
    function subscriptions_preview_setup(): array
    {
        if (function_exists('preview_email_load_plugin_language')) {
            preview_email_load_plugin_language('Subscriptions', 'catalog/includes/languages/', 'subscriptions', 'extra_definitions');
        }
        SubscriptionsCore::defineTables();

        $item = ['products_id' => 0, 'products_name' => 'Sample Product', 'products_model' => 'SAMPLE', 'quantity' => 2, 'final_price' => 9.00, 'products_tax' => 0];
        $interval = ['count' => 2, 'unit' => 'week'];
        global $db;
        if (isset($db) && is_object($db)) {
            $r = $db->Execute(
                "SELECT ps.products_id, ps.intervals, ps.discount_percent, p.products_price, p.products_model"
                . " FROM " . TABLE_PRODUCTS_SUBSCRIPTION . " ps, " . TABLE_PRODUCTS . " p"
                . " WHERE p.products_id = ps.products_id AND ps.subscription_mode <> 'off' LIMIT 1"
            );
            if (!$r->EOF) {
                $name = function_exists('zen_get_products_name') ? trim((string)zen_get_products_name((int)$r->fields['products_id'])) : '';
                $item = [
                    'products_id' => (int)$r->fields['products_id'],
                    'products_name' => $name !== '' ? $name : $item['products_name'],
                    'products_model' => (string)$r->fields['products_model'],
                    'quantity' => 1,
                    'final_price' => round((float)$r->fields['products_price'] * (1 - SubscriptionsCore::cleanDiscount($r->fields['discount_percent']) / 100), 4),
                    'products_tax' => 0,
                ];
                foreach (explode(',', (string)$r->fields['intervals']) as $key) {
                    $iv = SubscriptionsCore::parseKey(trim($key));
                    if ($iv !== null) {
                        $interval = $iv;
                        break;
                    }
                }
            }
        }
        $currencies = $GLOBALS['currencies'] ?? null;
        $money = static function ($amount) use ($currencies) {
            return (is_object($currencies) && method_exists($currencies, 'format')) ? $currencies->format($amount) : number_format((float)$amount, 2);
        };
        $page = defined('FILENAME_ACCOUNT_SUBSCRIPTIONS') ? FILENAME_ACCOUNT_SUBSCRIPTIONS : 'account_subscriptions';
        $url = function_exists('zen_catalog_href_link') ? zen_catalog_href_link($page, '', 'SSL') : '/index.php?main_page=' . $page;
        $customer = function_exists('preview_email_sample_customer')
            ? preview_email_sample_customer()
            : ['name' => 'Sample Customer', 'email' => 'sample.customer@example.com'];

        return ['item' => $item, 'interval' => $interval, 'money' => $money, 'url' => str_replace('&amp;', '&', $url), 'customer' => $customer];
    }
}

return [
    [
        'key' => 'subscriptions_signup',
        'group' => 'Subscriptions',
        'sort' => 10,
        'label' => 'Signup Confirmation',
        'describe' => 'Sent right after the order confirmation when an order starts a subscription: the items, interval, price, next renewal date and the terms the customer agreed to.',
        'module' => 'default',
        'page_base' => 'checkout_process',
        'build' => static function (array $def): array {
            $x = subscriptions_preview_setup();
            $sub = [
                'subscriptions_id' => 12,
                'interval_count' => $x['interval']['count'],
                'interval_unit' => $x['interval']['unit'],
                'next_date' => SubscriptionsCore::dueDate(date('Y-m-d'), 1, $x['interval']['count'], $x['interval']['unit']),
                'max_cycles' => 0,
                'shipping_cost' => 4.95,
                'consent_text' => SubscriptionsCheckout::termsText(),
                'items' => [$x['item']],
            ];
            [$subject, $text, $html] = SubscriptionsCheckout::composeSignupEmail(1234, [$sub], $x['money'], $x['url']);
            return [
                'subject' => $subject,
                'text' => $text,
                'block' => ['EMAIL_MESSAGE_HTML' => $html],
                'to_name' => $x['customer']['name'],
                'to_email' => $x['customer']['email'],
                'notes' => ['Order #1234, subscription #12 and the $4.95 shipping are samples. The product is one you have set up for subscriptions, when there is one.'],
            ];
        },
    ],
    [
        'key' => 'subscriptions_paused',
        'group' => 'Subscriptions',
        'sort' => 20,
        'label' => 'Subscription Paused',
        'describe' => 'Sent when a customer pauses a subscription from My Subscriptions.',
        'module' => 'default',
        'page_base' => 'account_subscriptions',
        'build' => static function (array $def): array {
            $x = subscriptions_preview_setup();
            $m = SubscriptionsManager::composeChangeEmail('pause', 12, $x['interval']['count'], $x['interval']['unit'], $x['customer']['name'], $x['customer']['email'], $x['url']);
            return ['subject' => $m['subject'], 'text' => $m['text'], 'block' => ['EMAIL_MESSAGE_HTML' => $m['html']], 'to_name' => $m['name'], 'to_email' => $m['email']];
        },
    ],
    [
        'key' => 'subscriptions_canceled',
        'group' => 'Subscriptions',
        'sort' => 30,
        'label' => 'Subscription Canceled',
        'describe' => 'Sent when a customer cancels a subscription from My Subscriptions, so they have the cancellation in writing.',
        'module' => 'default',
        'page_base' => 'account_subscriptions',
        'build' => static function (array $def): array {
            $x = subscriptions_preview_setup();
            $m = SubscriptionsManager::composeChangeEmail('cancel', 12, $x['interval']['count'], $x['interval']['unit'], $x['customer']['name'], $x['customer']['email'], $x['url']);
            return ['subject' => $m['subject'], 'text' => $m['text'], 'block' => ['EMAIL_MESSAGE_HTML' => $m['html']], 'to_name' => $m['name'], 'to_email' => $m['email']];
        },
    ],
    [
        'key' => 'subscriptions_reminder',
        'group' => 'Subscriptions',
        'sort' => 40,
        'label' => 'Renewal Reminder',
        'describe' => 'Sent by the scheduler ahead of each renewal (Reminder E-Mail, Days Before Renewal) with the Renew Now link that fills the cart for checkout.',
        'module' => 'default',
        'page_base' => 'subscriptions_cron',
        'build' => static function (array $def): array {
            $x = subscriptions_preview_setup();
            $s = [
                'subscriptions_id' => 12,
                'status' => 'active',
                'interval_count' => $x['interval']['count'],
                'interval_unit' => $x['interval']['unit'],
                'anchor_date' => date('Y-m-d'),
                'next_date' => SubscriptionsCore::dueDate(date('Y-m-d'), 1, $x['interval']['count'], $x['interval']['unit']),
                'items' => [$x['item']],
            ];
            $renewals = new SubscriptionsRenewals(null, date('Y-m-d'));
            $lastDay = SubscriptionsRenewals::addDays($renewals->missedOn($s), -1);
            $renew = $x['url'] . (strpos($x['url'], '?') === false ? '?' : '&') . 'renew=12&t=' . str_repeat('0', 64);
            $m = SubscriptionsRenewals::composeReminderEmail($s, $s['next_date'], $lastDay, $x['money'], $renew, $x['url']);
            return [
                'subject' => $m['subject'],
                'text' => $m['text'],
                'block' => ['EMAIL_MESSAGE_HTML' => $m['html']],
                'to_name' => $x['customer']['name'],
                'to_email' => $x['customer']['email'],
                'notes' => ['The Renew Now link here is a sample and goes nowhere. A real one works only for the customer it was sent to.'],
            ];
        },
    ],
    [
        'key' => 'subscriptions_lapsed',
        'group' => 'Subscriptions',
        'sort' => 50,
        'label' => 'Paused After Missed Renewals',
        'describe' => 'Sent by the scheduler when a subscription is paused because several renewals in a row weren\'t ordered.',
        'module' => 'default',
        'page_base' => 'subscriptions_cron',
        'build' => static function (array $def): array {
            $x = subscriptions_preview_setup();
            $m = SubscriptionsRenewals::composeLapsedEmail(12, $x['interval']['count'], $x['interval']['unit'], SubscriptionsRenewals::MISSED_LIMIT, $x['customer']['name'], $x['customer']['email'], $x['url']);
            return ['subject' => $m['subject'], 'text' => $m['text'], 'block' => ['EMAIL_MESSAGE_HTML' => $m['html']], 'to_name' => $m['name'], 'to_email' => $m['email']];
        },
    ],
];
