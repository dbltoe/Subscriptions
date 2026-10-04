<?php
/**
 * Subscriptions -- pay-link renewals and the scheduler.
 *
 * The free plugin never charges anyone. Ahead of each due date the scheduler
 * emails the customer a Renew Now link; the link fills their cart with the
 * subscription's items and they check out as usual, so prices, tax, shipping
 * and the payment module are all the store's own, at that day's values. The
 * order that comes out of that checkout moves the subscription to its next
 * date.
 *
 * A cycle's pay window runs from the reminder to the day the next cycle's
 * reminder is due (or the day after the due date, for intervals shorter than
 * the reminder lead). A cycle not renewed by then is missed: the subscription
 * moves to the next date and is reminded again. After MISSED_LIMIT missed
 * cycles in a row that the customer was reminded of, it is paused and the
 * customer is told.
 *
 * The scheduler takes a database lock, so two runs never overlap, and every
 * step is idempotent (a reminder is sent once per due date), so running it
 * more often than hourly does no harm.
 *
 * @package  Subscriptions
 * @license  http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

require_once __DIR__ . '/SubscriptionsCore.php';

class SubscriptionsRenewals
{
    public const SESSION_KEY = 'subscriptions_renewal';

    /** Missed cycles in a row, each reminded, before a subscription is paused. */
    public const MISSED_LIMIT = 3;

    public const NEVER = '0001-01-01';

    /** @var object queryFactory */
    protected $db;

    /** @var string Y-m-d */
    protected $today;

    /** @var int */
    protected $reminderDays;

    public function __construct($db, ?string $today = null, ?int $reminderDays = null)
    {
        SubscriptionsCore::defineTables();
        $this->db = $db;
        $this->today = $today ?? date('Y-m-d');
        if ($reminderDays === null) {
            $reminderDays = defined('SUBSCRIPTIONS_REMINDER_DAYS') ? (int)SUBSCRIPTIONS_REMINDER_DAYS : 3;
        }
        $this->reminderDays = max(0, min(60, $reminderDays));
    }

    /* ----------------------------------------------------------------- *
     * The pay window
     * ----------------------------------------------------------------- */

    /** The first day the cycle due on $s['next_date'] counts as missed. */
    public function missedOn(array $s): string
    {
        $following = SubscriptionsCore::nextAfter($s['anchor_date'], $s['next_date'], (int)$s['interval_count'], $s['interval_unit']);
        $dayAfter = self::addDays($s['next_date'], 1);
        $nextReminder = self::addDays($following, -$this->reminderDays);
        return max($dayAfter, $nextReminder);
    }

    /** The day the reminder for the current cycle is due. */
    public function remindOn(array $s): string
    {
        return self::addDays($s['next_date'], -$this->reminderDays);
    }

    /** Whether the customer may renew the current cycle today (Renew Now on My Subscriptions, or the emailed link). */
    public function canRenew(array $s): bool
    {
        return ($s['status'] ?? '') === 'active'
            && ($s['payment_mode'] ?? 'paylink') === 'paylink'
            && $this->today >= $this->remindOn($s)
            && $this->today < $this->missedOn($s);
    }

    /* ----------------------------------------------------------------- *
     * Filling the cart
     * ----------------------------------------------------------------- */

    /**
     * Check an emailed link: the token must be the one issued for the current
     * cycle, and the subscription must be this customer's and renewable today.
     * Returns 'ok' or an error code.
     */
    public function checkLink(int $customerId, int $subId, string $token): string
    {
        $s = $this->own($customerId, $subId);
        if ($s === null) {
            return 'not_found';
        }
        if (!preg_match('/^[0-9a-f]{64}$/', $token) || $s['paylink_token'] === '' || $s['reminder_sent_for'] !== $s['next_date']
            || !hash_equals((string)$s['paylink_token'], hash('sha256', $token))) {
            return 'link_expired';
        }
        return $this->canRenew($s) ? 'ok' : 'link_expired';
    }

    /**
     * What to put in the cart to renew a subscription:
     * ['code' => 'ok'|error, 'due' => Y-m-d, 'lines' => [[products_id, qty, attributes, name]], 'unavailable' => [names]].
     *
     * Each line gets the attributes the customer chose on the last order (from
     * orders_products_attributes) and the Delivery value for the subscription's
     * current interval, so the cart prices it with the subscribe-and-save
     * discount and checkout knows it's a subscription line.
     */
    public function cartFor(int $customerId, int $subId): array
    {
        $out = ['code' => 'ok', 'due' => '', 'lines' => [], 'unavailable' => []];
        $s = $this->own($customerId, $subId);
        if ($s === null) {
            return ['code' => 'not_found'] + $out;
        }
        if (!$this->canRenew($s)) {
            return ['code' => $s['status'] === 'active' ? 'not_due' : 'not_active'] + $out;
        }
        $out['due'] = $s['next_date'];
        $option = defined('SUBSCRIPTIONS_OPTION_ID') ? (int)SUBSCRIPTIONS_OPTION_ID : 0;
        $value = $this->intervalValue((int)$s['interval_count'], $s['interval_unit']);

        $r = $this->db->Execute("SELECT * FROM " . TABLE_SUBSCRIPTIONS_PRODUCTS . " WHERE subscriptions_id = " . $subId . " ORDER BY subscriptions_products_id");
        while (!$r->EOF) {
            $item = $r->fields;
            $r->MoveNext();
            $pid = (int)$item['products_id'];
            $p = $this->db->Execute("SELECT products_status FROM " . TABLE_PRODUCTS . " WHERE products_id = " . $pid . " LIMIT 1");
            $offered = $option > 0 && $value > 0 && !$this->db->Execute(
                "SELECT products_attributes_id FROM " . TABLE_PRODUCTS_ATTRIBUTES . " WHERE products_id = " . $pid
                . " AND options_id = " . $option . " AND options_values_id = " . $value . " LIMIT 1"
            )->EOF;
            if ($p->EOF || (int)$p->fields['products_status'] !== 1 || !$offered) {
                $out['unavailable'][] = (string)$item['products_name'];
                continue;
            }
            $attributes = $this->lastAttributes((int)$item['orders_products_id'], $option);
            $attributes[$option] = $value;
            $out['lines'][] = [$pid, max(1, (int)(float)$item['quantity']), $attributes, (string)$item['products_name']];
        }
        if ($out['lines'] === []) {
            $out['code'] = 'nothing_available';
        }
        return $out;
    }

    /** The Delivery value that stands for an interval, 0 if there is none. */
    protected function intervalValue(int $count, string $unit): int
    {
        $r = $this->db->Execute(
            "SELECT options_values_id FROM " . TABLE_SUBSCRIPTIONS_INTERVALS
            . " WHERE interval_unit = '" . $this->db->prepare_input($unit) . "' AND interval_count = " . $count . " LIMIT 1"
        );
        return $r->EOF ? 0 : (int)$r->fields['options_values_id'];
    }

    /**
     * The other attributes on an order line, in the shape the product page
     * posts them to the cart: option => value, option => [value => value] for
     * checkboxes, txt_option => text for text and file options.
     */
    protected function lastAttributes(int $ordersProductsId, int $skipOption): array
    {
        $attributes = [];
        if ($ordersProductsId <= 0) {
            return $attributes;
        }
        $text = defined('PRODUCTS_OPTIONS_TYPE_TEXT') ? (int)PRODUCTS_OPTIONS_TYPE_TEXT : 1;
        $checkbox = defined('PRODUCTS_OPTIONS_TYPE_CHECKBOX') ? (int)PRODUCTS_OPTIONS_TYPE_CHECKBOX : 3;
        $file = defined('PRODUCTS_OPTIONS_TYPE_FILE') ? (int)PRODUCTS_OPTIONS_TYPE_FILE : 4;
        $prefix = defined('TEXT_PREFIX') ? TEXT_PREFIX : 'txt_';

        $r = $this->db->Execute(
            "SELECT products_options_id, products_options_values_id, products_options_values FROM " . TABLE_ORDERS_PRODUCTS_ATTRIBUTES
            . " WHERE orders_products_id = " . $ordersProductsId . " ORDER BY orders_products_attributes_id"
        );
        while (!$r->EOF) {
            $optionId = (int)$r->fields['products_options_id'];
            $valueId = (int)$r->fields['products_options_values_id'];
            $label = (string)$r->fields['products_options_values'];
            $r->MoveNext();
            if ($optionId <= 0 || $optionId === $skipOption) {
                continue;
            }
            $t = $this->db->Execute("SELECT products_options_type FROM " . TABLE_PRODUCTS_OPTIONS . " WHERE products_options_id = " . $optionId . " LIMIT 1");
            $type = $t->EOF ? 0 : (int)$t->fields['products_options_type'];
            if ($type === $text || $type === $file) {
                $attributes[$prefix . $optionId] = $label;
            } elseif ($type === $checkbox) {
                $attributes[$optionId][$valueId] = $valueId;
            } else {
                $attributes[$optionId] = $valueId;
            }
        }
        return $attributes;
    }

    /* ----------------------------------------------------------------- *
     * Recording the renewal order
     * ----------------------------------------------------------------- */

    /**
     * Move each subscription the cart renewed to its next date.
     *
     * @param array $products order::$products
     * @param array $lineIds  order product index => orders_products_id
     * @param array $marker   the session's renewals: subscriptions_id => ['due' => Y-m-d, 'prids' => [uprid, ...]]
     * @return int[] the order product indexes that were renewal lines; these
     *               never start a new subscription, even when the renewal
     *               couldn't be recorded (the customer agreed to the
     *               subscription once, not to a second one)
     */
    public function recordOrder(int $ordersId, array $products, array $lineIds, array $marker): array
    {
        $consumed = [];
        if ($ordersId <= 0 || $marker === []) {
            return $consumed;
        }
        $order = $this->db->Execute("SELECT customers_id FROM " . TABLE_ORDERS . " WHERE orders_id = " . $ordersId . " LIMIT 1");
        $customerId = $order->EOF ? 0 : (int)$order->fields['customers_id'];

        foreach ($marker as $subId => $m) {
            $subId = (int)$subId;
            $prids = array_map('strval', (array)($m['prids'] ?? []));
            $lines = [];
            foreach ($products as $i => $p) {
                if (in_array((string)($p['id'] ?? ''), $prids, true) && !in_array($i, $consumed, true)) {
                    $lines[] = $i;
                }
            }
            if ($lines === []) {
                continue;
            }
            $consumed = array_merge($consumed, $lines);

            $s = $this->own($customerId, $subId);
            if ($s === null || $s['status'] !== 'active' || $s['next_date'] !== (string)($m['due'] ?? '')) {
                self::orderNote($ordersId, sprintf(self::text('SUBSCRIPTIONS_NOTE_RENEWAL_LATE', 'This order renews subscription #%u, but that delivery was already renewed, skipped or missed, or the subscription is no longer active, so the subscription was not changed.'), $subId));
                continue;
            }

            $amount = 0.0;
            foreach ($lines as $i) {
                $amount += (float)$products[$i]['final_price'] * (float)$products[$i]['qty'];
                $this->db->Execute(
                    "UPDATE " . TABLE_SUBSCRIPTIONS_PRODUCTS . " SET final_price = " . round((float)$products[$i]['final_price'], 4)
                    . ", products_tax = " . round((float)($products[$i]['tax'] ?? 0), 4) . ", orders_products_id = " . (int)($lineIds[$i] ?? 0)
                    . " WHERE subscriptions_id = " . $subId . " AND products_id = " . (int)$products[$i]['id']
                );
                // Keep the saved name the catalog's (the history and admin pages read it).
                $name = function_exists('zen_get_products_name') ? trim((string)zen_get_products_name((int)$products[$i]['id'])) : '';
                if ($name !== '') {
                    $this->db->Execute(
                        "UPDATE " . TABLE_SUBSCRIPTIONS_PRODUCTS . " SET products_name = '" . $this->db->prepare_input(substr($name, 0, 255)) . "'"
                        . " WHERE subscriptions_id = " . $subId . " AND products_id = " . (int)$products[$i]['id']
                    );
                }
            }
            $cycles = (int)$s['cycles_completed'] + 1;
            $next = SubscriptionsCore::nextAfter($s['anchor_date'], $s['next_date'], (int)$s['interval_count'], $s['interval_unit']);
            $finished = (int)$s['max_cycles'] > 0 && $cycles >= (int)$s['max_cycles'];
            $this->db->Execute(
                "UPDATE " . TABLE_SUBSCRIPTIONS . " SET status = '" . ($finished ? 'expired' : 'active') . "', next_date = '" . $this->db->prepare_input($next)
                . "', cycles_completed = " . $cycles . ", last_orders_id = " . $ordersId . ", failure_count = 0, paylink_token = '', paylink_expires = '"
                . self::NEVER . " 00:00:00', reminder_sent_for = '" . self::NEVER . "', last_modified = now() WHERE subscriptions_id = " . $subId . " LIMIT 1"
            );
            $this->history($subId, 'renewed', $ordersId, $amount, 'Delivery due ' . $s['next_date'] . ' renewed' . ($finished ? '; that was the last one.' : '; next is ' . $next . '.'), 'customer');
            $label = SubscriptionsCore::intervalLabel((int)$s['interval_count'], $s['interval_unit']);
            self::orderNote($ordersId, sprintf(self::text('SUBSCRIPTIONS_NOTE_RENEWED', 'Renewal of subscription #%1$u (%2$s), delivery due %3$s.'), $subId, $label, $s['next_date']));
        }
        return $consumed;
    }

    /* ----------------------------------------------------------------- *
     * The scheduler
     * ----------------------------------------------------------------- */

    /** A per-store lock name (GET_LOCK names are server-wide). */
    public static function lockName(): string
    {
        return 'zc_subs_' . substr(sha1((defined('DB_DATABASE') ? DB_DATABASE : '') . '|' . TABLE_SUBSCRIPTIONS), 0, 24);
    }

    /**
     * One scheduler pass.
     *
     * @param callable $link   (int subId, string token): string, the Renew Now URL
     * @param callable $money  (float amount, string currency): string, in that currency at today's rate
     *                         (stored prices are in the store's default currency, as core stores them)
     * @param callable $send   (array mail): void, mail = key, name, email, subject, text, html
     * @return array{locked:bool, reminded:int, missed:int, paused:int, errors:string[]}
     */
    public function run(callable $link, callable $money, callable $send, string $manageUrl): array
    {
        $report = ['locked' => false, 'reminded' => 0, 'missed' => 0, 'paused' => 0, 'errors' => []];
        $lock = $this->db->Execute("SELECT GET_LOCK('" . self::lockName() . "', 0) AS got");
        if ($lock->EOF || (int)$lock->fields['got'] !== 1) {
            $report['locked'] = true;
            return $report;
        }
        // For the admin page's "last ran" line (and its warning when cron stops).
        $this->db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '" . date('Y-m-d H:i:s') . "' WHERE configuration_key = 'SUBSCRIPTIONS_LAST_RUN' LIMIT 1");
        try {
            // Everything whose reminder is due, plus everything overdue.
            $horizon = self::addDays($this->today, $this->reminderDays);
            $ids = [];
            $r = $this->db->Execute(
                "SELECT subscriptions_id FROM " . TABLE_SUBSCRIPTIONS . " WHERE status = 'active' AND payment_mode = 'paylink' AND next_date <= '"
                . $this->db->prepare_input($horizon) . "' ORDER BY subscriptions_id"
            );
            while (!$r->EOF) {
                $ids[] = (int)$r->fields['subscriptions_id'];
                $r->MoveNext();
            }
            foreach ($ids as $subId) {
                try {
                    $this->runOne($subId, $link, $money, $send, $manageUrl, $report);
                } catch (Throwable $e) {
                    $report['errors'][] = '#' . $subId . ': ' . $e->getMessage();
                }
            }
        } finally {
            $this->db->Execute("SELECT RELEASE_LOCK('" . self::lockName() . "') AS released");
        }
        return $report;
    }

    protected function runOne(int $subId, callable $link, callable $money, callable $send, string $manageUrl, array &$report): void
    {
        $r = $this->db->Execute("SELECT * FROM " . TABLE_SUBSCRIPTIONS . " WHERE subscriptions_id = " . $subId . " LIMIT 1");
        if ($r->EOF || $r->fields['status'] !== 'active') {
            return;
        }
        $s = $r->fields;
        $customer = $this->customer((int)$s['customers_id']);

        // Roll past the cycles nobody renewed (several, if the scheduler was off a while).
        for ($guard = 0; $guard < 400 && $this->today >= $this->missedOn($s); $guard++) {
            $reminded = $s['reminder_sent_for'] === $s['next_date'];
            $following = SubscriptionsCore::nextAfter($s['anchor_date'], $s['next_date'], (int)$s['interval_count'], $s['interval_unit']);
            $failures = (int)$s['failure_count'] + ($reminded ? 1 : 0);
            $this->history($subId, 'missed', 0, 0, 'Delivery due ' . $s['next_date'] . ' was not renewed' . ($reminded ? '' : ' (no reminder had been sent)') . '; next is ' . $following . '.', 'scheduler');
            $report['missed']++;
            $s['next_date'] = $following;
            $s['failure_count'] = $failures;
            $s['reminder_sent_for'] = self::NEVER;
            $this->db->Execute(
                "UPDATE " . TABLE_SUBSCRIPTIONS . " SET next_date = '" . $this->db->prepare_input($following) . "', failure_count = " . $failures
                . ", reminder_sent_for = '" . self::NEVER . "', paylink_token = '', paylink_expires = '" . self::NEVER . " 00:00:00', last_modified = now()"
                . " WHERE subscriptions_id = " . $subId . " LIMIT 1"
            );
            if ($failures >= self::MISSED_LIMIT) {
                $this->db->Execute("UPDATE " . TABLE_SUBSCRIPTIONS . " SET status = 'paused', last_modified = now() WHERE subscriptions_id = " . $subId . " LIMIT 1");
                $this->history($subId, 'paused', 0, 0, self::MISSED_LIMIT . ' renewals in a row were not placed.', 'scheduler');
                $report['paused']++;
                if ($customer !== null) {
                    $send(['key' => 'subscriptions_lapsed'] + self::composeLapsedEmail($subId, (int)$s['interval_count'], $s['interval_unit'], self::MISSED_LIMIT, $customer['name'], $customer['email'], $manageUrl));
                }
                return;
            }
        }

        if ($this->today < $this->remindOn($s) || $s['reminder_sent_for'] === $s['next_date']) {
            return;
        }
        if ($customer === null) {
            $report['errors'][] = '#' . $subId . ': the customer account no longer exists.';
            return;
        }
        $token = bin2hex(random_bytes(32));
        $lastDay = self::addDays($this->missedOn($s), -1);
        $this->db->Execute(
            "UPDATE " . TABLE_SUBSCRIPTIONS . " SET paylink_token = '" . hash('sha256', $token) . "', paylink_expires = '" . $this->db->prepare_input($lastDay)
            . " 23:59:59', reminder_sent_for = '" . $this->db->prepare_input($s['next_date']) . "', last_modified = now() WHERE subscriptions_id = " . $subId . " LIMIT 1"
        );
        $items = [];
        $p = $this->db->Execute("SELECT * FROM " . TABLE_SUBSCRIPTIONS_PRODUCTS . " WHERE subscriptions_id = " . $subId . " ORDER BY subscriptions_products_id");
        while (!$p->EOF) {
            $item = $p->fields;
            // The catalog's current name, as My Subscriptions shows it; the saved
            // one may carry another plugin's decoration (POSM's "[In Stock]").
            $name = function_exists('zen_get_products_name') ? trim((string)zen_get_products_name((int)$item['products_id'])) : '';
            if ($name !== '') {
                $item['products_name'] = $name;
            }
            $items[] = $item;
            $p->MoveNext();
        }
        $currency = (string)$s['currency'];
        $format = static function ($amount) use ($money, $currency) {
            return $money((float)$amount, $currency);
        };
        $mail = self::composeReminderEmail($s + ['items' => $items], $s['next_date'], $lastDay, $format, $link($subId, $token), $manageUrl);
        $this->history($subId, 'reminded', 0, 0, 'Renew Now link sent for the delivery due ' . $s['next_date'] . ', good through ' . $lastDay . '.', 'scheduler');
        $report['reminded']++;
        $send(['key' => 'subscriptions_reminder', 'name' => $customer['name'], 'email' => $customer['email']] + $mail);
    }

    /* ----------------------------------------------------------------- *
     * The emails. Static and database-free, so the Preview Email
     * definitions build the real thing from sample values.
     * ----------------------------------------------------------------- */

    /**
     * The reminder: subject, text, html.
     *
     * @param array    $s      a subscriptions row with an 'items' list of subscriptions_products rows
     * @param callable $money  (float): string in the subscription's currency
     */
    public static function composeReminderEmail(array $s, string $due, string $lastDay, callable $money, string $renewUrl, string $manageUrl): array
    {
        $h = static function ($v) {
            return htmlspecialchars((string)$v, ENT_QUOTES, defined('CHARSET') ? CHARSET : 'UTF-8');
        };
        $date = static function ($ymd) {
            return function_exists('zen_date_long') ? zen_date_long($ymd) : $ymd;
        };
        $id = (int)$s['subscriptions_id'];
        $label = SubscriptionsCore::intervalLabel((int)$s['interval_count'], $s['interval_unit']);
        $items = [];
        $total = 0.0;
        foreach ($s['items'] ?? [] as $p) {
            $items[] = (float)$p['quantity'] . ' x ' . $p['products_name'];
            $total += (float)$p['final_price'] * (float)$p['quantity'] * (1 + (float)$p['products_tax'] / 100);
        }
        $intro = sprintf(self::text('SUBSCRIPTIONS_REMIND_INTRO', 'Your next delivery for subscription #%1$u (%2$s) is due on %3$s.'), $id, $label, $date($due));
        $last = sprintf(self::text('SUBSCRIPTIONS_REMIND_LAST_PRICE', 'Last time these came to %s, tax included. Checkout shows today\'s prices and adds shipping.'), $money($total));
        $how = self::text('SUBSCRIPTIONS_REMIND_HOW', 'Nothing is charged automatically. To get this delivery, place the renewal order: the link puts these items in your cart and you check out as usual.');
        $until = sprintf(self::text('SUBSCRIPTIONS_REMIND_UNTIL', 'The link works through %s.'), $date($lastDay));
        $manage = sprintf(self::text('SUBSCRIPTIONS_REMIND_MANAGE', 'Don\'t need this one? Skip it, pause or cancel from My Subscriptions: %s'), $manageUrl);

        $text = $intro . "\n\n";
        foreach ($items as $item) {
            $text .= '  ' . $item . "\n";
        }
        $text .= "\n" . $last . "\n\n" . $how . "\n" . sprintf(self::text('SUBSCRIPTIONS_REMIND_LINK', 'Renew Now: %s'), $renewUrl) . "\n" . $until . "\n\n" . $manage . "\n";

        $html = '<p>' . $h($intro) . '</p><ul>';
        foreach ($items as $item) {
            $html .= '<li>' . $h($item) . '</li>';
        }
        $html .= '</ul><p>' . $h($last) . '</p><p>' . $h($how) . '</p>'
            . '<p><a href="' . $h($renewUrl) . '"><strong>' . $h(self::text('SUBSCRIPTIONS_REMIND_BUTTON', 'Renew Now')) . '</strong></a><br>' . $h($until) . '</p>'
            . '<p>' . $h(self::text('SUBSCRIPTIONS_REMIND_MANAGE_HTML', 'Don\'t need this one? Skip it, pause or cancel from My Subscriptions:'))
            . ' <a href="' . $h($manageUrl) . '">' . $h($manageUrl) . '</a></p>';

        return [
            'subject' => sprintf(self::text('SUBSCRIPTIONS_REMIND_SUBJECT', 'Time to renew subscription #%1$u: delivery due %2$s'), $id, $date($due)),
            'text' => $text,
            'html' => $html,
        ];
    }

    /** The "we paused it" notice after missed renewals: name, email, subject, text, html. */
    public static function composeLapsedEmail(int $subId, int $count, string $unit, int $missed, string $name, string $email, string $manageUrl): array
    {
        $label = SubscriptionsCore::intervalLabel($count, $unit);
        $text = sprintf(self::text('SUBSCRIPTIONS_LAPSED_TEXT', 'We\'ve paused your subscription #%1$u (%2$s) because the last %3$u renewals weren\'t ordered. Nothing more will be sent and you won\'t get any more reminders.'), $subId, $label, $missed)
            . "\n\n" . sprintf(self::text('SUBSCRIPTIONS_LAPSED_RESUME', 'You can resume it any time from My Subscriptions: %s'), $manageUrl) . "\n";
        return [
            'name' => $name,
            'email' => $email,
            'subject' => sprintf(self::text('SUBSCRIPTIONS_LAPSED_SUBJECT', 'We\'ve paused your subscription #%u'), $subId),
            'text' => $text,
            'html' => '<p>' . nl2br(htmlspecialchars($text, ENT_QUOTES, defined('CHARSET') ? CHARSET : 'UTF-8')) . '</p>',
        ];
    }

    /* ----------------------------------------------------------------- */

    protected function own(int $customerId, int $subId): ?array
    {
        if ($customerId <= 0 || $subId <= 0) {
            return null;
        }
        $r = $this->db->Execute("SELECT * FROM " . TABLE_SUBSCRIPTIONS . " WHERE subscriptions_id = " . $subId . " AND customers_id = " . $customerId . " LIMIT 1");
        return $r->EOF ? null : $r->fields;
    }

    /** @return array{name:string, email:string}|null */
    protected function customer(int $customerId): ?array
    {
        $c = $this->db->Execute(
            "SELECT customers_firstname, customers_lastname, customers_email_address FROM " . TABLE_CUSTOMERS . " WHERE customers_id = " . $customerId . " LIMIT 1"
        );
        if ($c->EOF || trim((string)$c->fields['customers_email_address']) === '') {
            return null;
        }
        return ['name' => trim($c->fields['customers_firstname'] . ' ' . $c->fields['customers_lastname']), 'email' => (string)$c->fields['customers_email_address']];
    }

    protected function history(int $subId, string $event, int $ordersId, float $amount, string $detail, string $actor): void
    {
        $this->db->Execute(
            "INSERT INTO " . TABLE_SUBSCRIPTIONS_HISTORY . " (subscriptions_id, event, orders_id, amount, detail, actor, date_added)"
            . " VALUES (" . $subId . ", '" . $this->db->prepare_input($event) . "', " . $ordersId . ", " . round($amount, 4) . ", '"
            . $this->db->prepare_input($detail) . "', '" . $this->db->prepare_input($actor) . "', now())"
        );
    }

    /** An admin-only line on the order (no email, hidden from the customer). */
    public static function orderNote(int $ordersId, string $message): void
    {
        if (function_exists('zen_update_orders_history')) {
            zen_update_orders_history($ordersId, $message, 'Subscriptions', -1, -1);
        }
    }

    public static function addDays(string $ymd, int $days): string
    {
        $d = DateTime::createFromFormat('!Y-m-d', $ymd);
        if ($d === false) {
            throw new InvalidArgumentException('bad date');
        }
        return $d->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    protected static function text(string $constant, string $default): string
    {
        return defined($constant) ? (string)constant($constant) : $default;
    }
}
