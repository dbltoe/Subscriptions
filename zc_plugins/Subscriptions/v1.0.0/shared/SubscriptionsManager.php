<?php
/**
 * Subscriptions -- what a customer can do with their own subscriptions.
 *
 * Every action checks that the subscription belongs to the customer and that
 * its status allows the change, writes a history row, and returns a result
 * code the page turns into a message. Nothing here reads a superglobal; the
 * page hands in only cleaned values.
 *
 * Statuses: active, paused, past_due (Pro, after a failed charge), canceled,
 * expired (reached its number of deliveries).
 *
 * @package  Subscriptions
 * @license  http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

require_once __DIR__ . '/SubscriptionsCore.php';

class SubscriptionsManager
{
    public const LIVE = ['active', 'paused', 'past_due'];

    public const MAX_QTY = 999;

    /** @var object queryFactory */
    protected $db;

    /** @var string Y-m-d, injectable for tests */
    protected $today;

    /** @var string who the history says made each change */
    protected $actor = 'customer';

    public function __construct($db, ?string $today = null)
    {
        SubscriptionsCore::defineTables();
        $this->db = $db;
        $this->today = $today ?? date('Y-m-d');
    }

    /**
     * Name who is acting, for the history ("admin: Pat"). The admin page acts
     * through the same methods as the customer, passing the subscription's own
     * customer id, so every rule applies to both.
     */
    public function setActor(string $actor): self
    {
        $this->actor = substr($actor, 0, 64);
        return $this;
    }

    /** The customer's subscriptions, live ones first, each with its items and the intervals it may switch to. */
    public function forCustomer(int $customerId): array
    {
        $out = [];
        if ($customerId <= 0) {
            return $out;
        }
        $r = $this->db->Execute("SELECT * FROM " . TABLE_SUBSCRIPTIONS . " WHERE customers_id = " . $customerId . " ORDER BY subscriptions_id DESC");
        while (!$r->EOF) {
            $s = $r->fields;
            $s['items'] = $this->items((int)$s['subscriptions_id']);
            $s['intervals'] = $this->allowedIntervals($s['items']);
            $s['live'] = in_array($s['status'], self::LIVE, true);
            $out[] = $s;
            $r->MoveNext();
        }
        usort($out, static function ($a, $b) {
            return [$b['live'], (int)$b['subscriptions_id']] <=> [$a['live'], (int)$a['subscriptions_id']];
        });
        return $out;
    }

    public function items(int $subId): array
    {
        $items = [];
        $r = $this->db->Execute("SELECT * FROM " . TABLE_SUBSCRIPTIONS_PRODUCTS . " WHERE subscriptions_id = " . $subId . " ORDER BY subscriptions_products_id");
        while (!$r->EOF) {
            $items[] = $r->fields;
            $r->MoveNext();
        }
        return $items;
    }

    /**
     * The intervals every product in the subscription is still offered at:
     * a customer can only switch to an interval the store sells all of them on.
     *
     * @return array<string, array{count:int, unit:string}>
     */
    public function allowedIntervals(array $items): array
    {
        $allowed = null;
        foreach ($items as $item) {
            $r = $this->db->Execute(
                "SELECT subscription_mode, intervals FROM " . TABLE_PRODUCTS_SUBSCRIPTION . " WHERE products_id = " . (int)$item['products_id'] . " LIMIT 1"
            );
            $keys = [];
            if (!$r->EOF && $r->fields['subscription_mode'] !== 'off') {
                foreach (explode(',', (string)$r->fields['intervals']) as $key) {
                    if (SubscriptionsCore::parseKey(trim($key)) !== null) {
                        $keys[] = trim($key);
                    }
                }
            }
            $allowed = $allowed === null ? $keys : array_values(array_intersect($allowed, $keys));
        }
        $out = [];
        foreach ($allowed ?? [] as $key) {
            $out[$key] = SubscriptionsCore::parseKey($key);
        }
        uasort($out, static function ($a, $b) {
            return SubscriptionsCore::approxDays($a['count'], $a['unit']) <=> SubscriptionsCore::approxDays($b['count'], $b['unit']);
        });
        return $out;
    }

    /** The subscription if it's this customer's, else null. */
    protected function own(int $customerId, int $subId): ?array
    {
        if ($customerId <= 0 || $subId <= 0) {
            return null;
        }
        $r = $this->db->Execute(
            "SELECT * FROM " . TABLE_SUBSCRIPTIONS . " WHERE subscriptions_id = " . $subId . " AND customers_id = " . $customerId . " LIMIT 1"
        );
        return $r->EOF ? null : $r->fields;
    }

    /* ----------------------------------------------------------------- *
     * Actions. Each returns 'ok' or an error code.
     * ----------------------------------------------------------------- */

    public function skip(int $customerId, int $subId): string
    {
        $s = $this->own($customerId, $subId);
        if ($s === null) {
            return 'not_found';
        }
        if ($s['status'] !== 'active') {
            return 'not_active';
        }
        $next = SubscriptionsCore::nextAfter($s['anchor_date'], $s['next_date'], (int)$s['interval_count'], $s['interval_unit']);
        $this->update($subId, ['next_date' => $next, 'reminder_sent_for' => '0001-01-01', 'paylink_token' => '']);
        $this->history($subId, 'skipped', 'Delivery due ' . $s['next_date'] . ' skipped; next is ' . $next . '.');
        return 'ok';
    }

    public function pause(int $customerId, int $subId): string
    {
        $s = $this->own($customerId, $subId);
        if ($s === null) {
            return 'not_found';
        }
        if ($s['status'] !== 'active') {
            return 'not_active';
        }
        $this->update($subId, ['status' => 'paused']);
        $this->history($subId, 'paused', '');
        return 'ok';
    }

    public function resume(int $customerId, int $subId): string
    {
        $s = $this->own($customerId, $subId);
        if ($s === null) {
            return 'not_found';
        }
        if ($s['status'] !== 'paused') {
            return 'not_paused';
        }
        $next = $s['next_date'];
        // A date that went by while paused is not delivered late: the next
        // one on the schedule after today is.
        if ($next <= $this->today) {
            $next = SubscriptionsCore::nextAfter($s['anchor_date'], $this->today, (int)$s['interval_count'], $s['interval_unit']);
        }
        // A fresh start: renewals missed before the pause no longer count toward pausing it again.
        $this->update($subId, ['status' => 'active', 'next_date' => $next, 'reminder_sent_for' => '0001-01-01', 'failure_count' => 0, 'paylink_token' => '']);
        $this->history($subId, 'resumed', 'Next delivery ' . $next . '.');
        return 'ok';
    }

    public function changeInterval(int $customerId, int $subId, string $key): string
    {
        $s = $this->own($customerId, $subId);
        if ($s === null) {
            return 'not_found';
        }
        if (!in_array($s['status'], ['active', 'paused'], true)) {
            return 'not_live';
        }
        $allowed = $this->allowedIntervals($this->items($subId));
        if (!isset($allowed[$key])) {
            return 'bad_interval';
        }
        $iv = $allowed[$key];
        if ($iv['count'] === (int)$s['interval_count'] && $iv['unit'] === $s['interval_unit']) {
            return 'unchanged';
        }
        // The next date stands; the new schedule counts from it.
        $this->update($subId, ['interval_unit' => $iv['unit'], 'interval_count' => $iv['count'], 'anchor_date' => $s['next_date']]);
        $this->history($subId, 'interval_changed', SubscriptionsCore::intervalLabel((int)$s['interval_count'], $s['interval_unit']) . ' to ' . SubscriptionsCore::intervalLabel($iv['count'], $iv['unit']) . '.');
        return 'ok';
    }

    /** @param array<int, mixed> $quantities subscriptions_products_id => new quantity */
    public function changeQuantities(int $customerId, int $subId, array $quantities): string
    {
        $s = $this->own($customerId, $subId);
        if ($s === null) {
            return 'not_found';
        }
        if (!in_array($s['status'], ['active', 'paused'], true)) {
            return 'not_live';
        }
        $changes = [];
        foreach ($this->items($subId) as $item) {
            $id = (int)$item['subscriptions_products_id'];
            if (!array_key_exists($id, $quantities)) {
                continue;
            }
            $raw = trim((string)$quantities[$id]);
            if (!preg_match('/^\d{1,3}$/', $raw) || (int)$raw < 1 || (int)$raw > self::MAX_QTY) {
                return 'bad_quantity';
            }
            if ((int)$raw !== (int)(float)$item['quantity']) {
                $changes[$id] = [(int)(float)$item['quantity'], (int)$raw, $item['products_name']];
            }
        }
        if ($changes === []) {
            return 'unchanged';
        }
        $notes = [];
        foreach ($changes as $id => $c) {
            $this->db->Execute("UPDATE " . TABLE_SUBSCRIPTIONS_PRODUCTS . " SET quantity = " . $c[1] . " WHERE subscriptions_products_id = " . $id . " AND subscriptions_id = " . $subId . " LIMIT 1");
            $notes[] = $c[2] . ': ' . $c[0] . ' to ' . $c[1];
        }
        $this->update($subId, []);
        $this->history($subId, 'quantity_changed', implode('; ', $notes) . '.');
        return 'ok';
    }

    /**
     * Move the next delivery to another date (the admin page offers this). The
     * schedule then counts from the new date, and any reminder already sent is
     * void: the scheduler reminds again for the new date.
     */
    public function setNextDate(int $customerId, int $subId, string $ymd): string
    {
        $s = $this->own($customerId, $subId);
        if ($s === null) {
            return 'not_found';
        }
        if (!in_array($s['status'], ['active', 'paused'], true)) {
            return 'not_live';
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || $ymd < $this->today) {
            return 'bad_date';
        }
        if ($ymd === $s['next_date']) {
            return 'unchanged';
        }
        $this->update($subId, ['next_date' => $ymd, 'anchor_date' => $ymd, 'reminder_sent_for' => '0001-01-01', 'paylink_token' => '']);
        $this->history($subId, 'date_changed', 'Next delivery moved from ' . $s['next_date'] . ' to ' . $ymd . '.');
        return 'ok';
    }

    public function cancel(int $customerId, int $subId, string $reason): string
    {
        $s = $this->own($customerId, $subId);
        if ($s === null) {
            return 'not_found';
        }
        if (!in_array($s['status'], self::LIVE, true)) {
            return 'not_live';
        }
        $reason = trim(preg_replace('/\s+/', ' ', strip_tags($reason)));
        if (function_exists('mb_substr')) {
            $reason = mb_substr($reason, 0, 255);
        } else {
            $reason = substr($reason, 0, 255);
        }
        $this->update($subId, ['status' => 'canceled', 'date_canceled' => 'NOW', 'cancel_reason' => $reason]);
        $this->history($subId, 'canceled', $reason);
        return 'ok';
    }

    /**
     * The confirmation for a pause or a cancellation: name, email, subject,
     * text, html. Null when the subscription or the customer isn't found.
     * Building it here rather than in the page keeps the page free of function
     * declarations (a conditionally declared function doesn't exist until the
     * line declaring it runs -- that cost a fatal on 2026-10-03).
     */
    public function changeEmail(int $customerId, int $subId, string $action, string $url): ?array
    {
        $s = $this->own($customerId, $subId);
        $c = $this->db->Execute(
            "SELECT customers_firstname, customers_lastname, customers_email_address FROM " . TABLE_CUSTOMERS
            . " WHERE customers_id = " . $customerId . " LIMIT 1"
        );
        if ($s === null || $c->EOF || !in_array($action, ['pause', 'cancel'], true)) {
            return null;
        }
        return self::composeChangeEmail(
            $action,
            $subId,
            (int)$s['interval_count'],
            (string)$s['interval_unit'],
            trim($c->fields['customers_firstname'] . ' ' . $c->fields['customers_lastname']),
            (string)$c->fields['customers_email_address'],
            $url
        );
    }

    /**
     * The pause or cancel confirmation from plain values: name, email,
     * subject, text, html. No database, so the Preview Email definition calls
     * it with sample values and shows the real email.
     */
    public static function composeChangeEmail(string $action, int $subId, int $count, string $unit, string $name, string $email, string $url): array
    {
        $label = SubscriptionsCore::intervalLabel($count, $unit);
        $cancel = $action === 'cancel';
        $text = sprintf($cancel ? SUBSCRIPTIONS_EMAIL_CANCELED : SUBSCRIPTIONS_EMAIL_PAUSED, $subId, $label) . "\n\n"
            . sprintf(SUBSCRIPTIONS_EMAIL_SEE, $url) . "\n";
        return [
            'name' => $name,
            'email' => $email,
            'subject' => sprintf($cancel ? SUBSCRIPTIONS_EMAIL_CANCELED_SUBJECT : SUBSCRIPTIONS_EMAIL_PAUSED_SUBJECT, $subId),
            'text' => $text,
            'html' => SubscriptionsCore::textToHtml($text),
        ];
    }

    /* ----------------------------------------------------------------- */

    protected function update(int $subId, array $set): void
    {
        $parts = ['last_modified = now()'];
        foreach ($set as $col => $val) {
            if ($val === 'NOW') {
                $parts[] = $col . ' = now()';
            } elseif (is_int($val)) {
                $parts[] = $col . ' = ' . $val;
            } else {
                $parts[] = $col . " = '" . $this->db->prepare_input((string)$val) . "'";
            }
        }
        $this->db->Execute("UPDATE " . TABLE_SUBSCRIPTIONS . " SET " . implode(', ', $parts) . " WHERE subscriptions_id = " . $subId . " LIMIT 1");
    }

    protected function history(int $subId, string $event, string $detail): void
    {
        $this->db->Execute(
            "INSERT INTO " . TABLE_SUBSCRIPTIONS_HISTORY . " (subscriptions_id, event, orders_id, amount, detail, actor, date_added)"
            . " VALUES (" . $subId . ", '" . $this->db->prepare_input($event) . "', 0, 0, '" . $this->db->prepare_input($detail) . "', '"
            . $this->db->prepare_input($this->actor) . "', now())"
        );
    }
}
