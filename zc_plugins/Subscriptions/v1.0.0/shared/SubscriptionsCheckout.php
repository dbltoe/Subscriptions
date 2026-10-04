<?php
/**
 * Subscriptions -- what happens at checkout.
 *
 *   - which cart lines are subscriptions (the Delivery option set to an interval),
 *   - the customer's consent, kept in the session from the moment it's ticked,
 *   - turning a placed order's subscription lines into subscriptions,
 *   - the signup email.
 *
 * Consent is session state rather than a field checked at the end because the
 * final POST doesn't always reach checkout_process: One Page Checkout rebuilds
 * the confirmation form, card-on-page modules go through the AJAX
 * prepareConfirmation call, and off-site gateways never post back at all. So
 * consent is recorded wherever the customer's form lands and enforced before
 * the order is written, bound to the cart it was given for.
 *
 * @package  Subscriptions
 * @license  http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

require_once __DIR__ . '/SubscriptionsCore.php';

class SubscriptionsCheckout
{
    public const SESSION_KEY = 'subscriptions_consent';

    /** @var object queryFactory */
    protected $db;

    /** @var array<int, array{count:int, unit:string}>|null options_values_id => interval */
    protected $intervals;

    public function __construct($db)
    {
        SubscriptionsCore::defineTables();
        $this->db = $db;
    }

    public function optionId(): int
    {
        return defined('SUBSCRIPTIONS_OPTION_ID') ? (int)SUBSCRIPTIONS_OPTION_ID : 0;
    }

    /** The interval a Delivery value stands for, or null for the one-time value or a stranger. */
    public function intervalFor(int $valueId): ?array
    {
        if ($this->intervals === null) {
            $this->intervals = [];
            $r = $this->db->Execute("SELECT options_values_id, interval_unit, interval_count FROM " . TABLE_SUBSCRIPTIONS_INTERVALS);
            while (!$r->EOF) {
                if ((int)$r->fields['interval_count'] > 0 && isset(SubscriptionsCore::UNITS[$r->fields['interval_unit']])) {
                    $this->intervals[(int)$r->fields['options_values_id']] = [
                        'count' => (int)$r->fields['interval_count'],
                        'unit' => $r->fields['interval_unit'],
                    ];
                }
                $r->MoveNext();
            }
        }
        return $this->intervals[$valueId] ?? null;
    }

    /**
     * The subscription lines in the session cart: prid => [qty, value id].
     *
     * @param array    $contents shoppingCart::$contents
     * @param string[] $exclude  prids to leave out: the lines a Renew Now link
     *                           put in the cart, which renew a subscription the
     *                           customer already agreed to
     */
    public function cartSubscriptionLines(array $contents, array $exclude = []): array
    {
        $option = $this->optionId();
        $lines = [];
        if ($option <= 0) {
            return $lines;
        }
        foreach ($contents as $prid => $line) {
            if (in_array((string)$prid, $exclude, true)) {
                continue;
            }
            $value = $line['attributes'][$option] ?? ($line['attributes'][(string)$option] ?? null);
            if ($value !== null && $this->intervalFor((int)$value) !== null) {
                $lines[(string)$prid] = [(float)($line['qty'] ?? 0), (int)$value];
            }
        }
        ksort($lines);
        return $lines;
    }

    /** A fingerprint of the subscription part of the cart; consent is tied to it. */
    public function cartHash(array $contents, array $exclude = []): string
    {
        return sha1(json_encode($this->cartSubscriptionLines($contents, $exclude)));
    }

    /* ----------------------------------------------------------------- *
     * Consent
     * ----------------------------------------------------------------- */

    public static function termsText(): string
    {
        return defined('SUBSCRIPTIONS_TERMS') ? trim((string)SUBSCRIPTIONS_TERMS) : '';
    }

    /** Record that the customer ticked the box for this cart. */
    public function recordConsent(array &$session, array $contents, string $ip, array $exclude = []): void
    {
        $terms = self::termsText();
        $session[self::SESSION_KEY] = [
            'at' => date('Y-m-d H:i:s'),
            'ip' => substr($ip, 0, 96),
            'text' => $terms,
            'hash' => hash('sha256', $terms),
            'cart' => $this->cartHash($contents, $exclude),
        ];
    }

    public function clearConsent(array &$session): void
    {
        unset($session[self::SESSION_KEY]);
    }

    /**
     * True when the cart needs no consent, or the consent on record was given
     * for exactly this cart and these terms.
     */
    public function consentValid(array $session, array $contents, array $exclude = []): bool
    {
        if ($this->cartSubscriptionLines($contents, $exclude) === []) {
            return true;
        }
        $c = $session[self::SESSION_KEY] ?? null;
        return is_array($c)
            && ($c['cart'] ?? '') === $this->cartHash($contents, $exclude)
            && ($c['hash'] ?? '') === hash('sha256', self::termsText());
    }

    /* ----------------------------------------------------------------- *
     * Recording
     * ----------------------------------------------------------------- */

    /**
     * Create the subscriptions for a placed order.
     *
     * @param int   $ordersId
     * @param array $products        order::$products
     * @param array $info            order::$info
     * @param array $lineIds         order product index => orders_products_id
     * @param array|null $consent    the session's consent record, if any
     * @param int   $guestCustomerId OPC's placeholder customer, 0 if none
     * @param int[] $skip            order product indexes that renewed a subscription
     * @return int[] the new subscriptions_id values ([] when none, or refused)
     */
    public function recordOrder(int $ordersId, array $products, array $info, array $lineIds, ?array $consent, int $guestCustomerId, array $skip = []): array
    {
        $option = $this->optionId();
        if ($ordersId <= 0 || $option <= 0) {
            return [];
        }

        // Group the order's subscription lines by interval: one subscription each.
        $groups = [];
        foreach ($products as $i => $p) {
            if (in_array($i, $skip, true)) {
                continue;
            }
            foreach ($p['attributes'] ?? [] as $a) {
                if ((int)($a['option_id'] ?? 0) !== $option) {
                    continue;
                }
                $iv = $this->intervalFor((int)($a['value_id'] ?? 0));
                if ($iv !== null) {
                    $groups[SubscriptionsCore::intervalKey($iv['count'], $iv['unit'])]['interval'] = $iv;
                    $groups[SubscriptionsCore::intervalKey($iv['count'], $iv['unit'])]['lines'][] = $i;
                }
            }
        }
        if ($groups === []) {
            return [];
        }

        // Never twice for one order (a notifier fired again, a page reloaded).
        $r = $this->db->Execute("SELECT subscriptions_id FROM " . TABLE_SUBSCRIPTIONS . " WHERE origin_orders_id = " . $ordersId . " LIMIT 1");
        if (!$r->EOF) {
            return [];
        }

        // The order row as core wrote it: the customer, and the shipping method
        // and module code in exactly the form core stores them (it cuts the
        // module code at the first underscore: freeshipper_freeshipper -> freeshipper).
        $order = $this->db->Execute(
            "SELECT customers_id, shipping_method, shipping_module_code FROM " . TABLE_ORDERS . " WHERE orders_id = " . $ordersId . " LIMIT 1"
        );
        $customerId = $order->EOF ? 0 : (int)$order->fields['customers_id'];
        if (!$order->EOF) {
            $info['shipping_method'] = (string)$order->fields['shipping_method'];
            $info['shipping_module_code'] = (string)$order->fields['shipping_module_code'];
        }
        if ($customerId <= 0 || ($guestCustomerId > 0 && $customerId === $guestCustomerId)) {
            $this->orderNote($ordersId, self::text('SUBSCRIPTIONS_NOTE_GUEST', 'This order included subscription items but was placed without a customer account, so no subscription was started.'));
            return [];
        }

        $anchor = date('Y-m-d');
        $subtotal = (float)($info['subtotal'] ?? 0);
        $shippingCost = (float)($info['shipping_cost'] ?? 0);
        $created = [];

        foreach ($groups as $key => $g) {
            $iv = $g['interval'];
            $lineTotal = 0.0;
            $maxCycles = 0;
            foreach ($g['lines'] as $i) {
                $lineTotal += (float)$products[$i]['final_price'] * (float)$products[$i]['qty'];
                $cycles = $this->productMaxCycles((int)$products[$i]['id']);
                if ($cycles > 0 && ($maxCycles === 0 || $cycles < $maxCycles)) {
                    $maxCycles = $cycles;
                }
            }
            // The subscription's share of what the customer paid for shipping.
            $shipping = $subtotal > 0 ? round($shippingCost * $lineTotal / $subtotal, 4) : 0.0;
            $consentText = $consent['text'] ?? null;

            $this->db->Execute(
                "INSERT INTO " . TABLE_SUBSCRIPTIONS
                . " (customers_id, status, interval_unit, interval_count, anchor_date, next_date, cycles_completed, max_cycles,"
                . " origin_orders_id, last_orders_id, payment_mode, payment_module_code, shipping_method, shipping_module_code,"
                . " shipping_cost, currency, currency_value, consent_at, consent_ip, consent_hash, consent_text, date_added, last_modified)"
                . " VALUES (" . $customerId . ", '" . ($maxCycles === 1 ? 'expired' : 'active') . "', '" . $this->db->prepare_input($iv['unit']) . "', "
                . (int)$iv['count'] . ", '" . $this->db->prepare_input($anchor) . "', '"
                . $this->db->prepare_input(SubscriptionsCore::dueDate($anchor, 1, $iv['count'], $iv['unit'])) . "', 1, " . $maxCycles . ", "
                . $ordersId . ", " . $ordersId . ", 'paylink', '" . $this->db->prepare_input(substr((string)($info['payment_module_code'] ?? ''), 0, 64)) . "', '"
                . $this->db->prepare_input(substr((string)($info['shipping_method'] ?? ''), 0, 255)) . "', '"
                . $this->db->prepare_input(substr((string)($info['shipping_module_code'] ?? ''), 0, 64)) . "', "
                . $shipping . ", '" . $this->db->prepare_input(substr((string)($info['currency'] ?? ''), 0, 3)) . "', "
                . (float)($info['currency_value'] ?? 1) . ", "
                . ($consent ? "'" . $this->db->prepare_input($consent['at']) . "'" : "'0001-01-01 00:00:00'") . ", '"
                . $this->db->prepare_input((string)($consent['ip'] ?? '')) . "', '"
                . $this->db->prepare_input((string)($consent['hash'] ?? '')) . "', "
                . ($consentText === null ? 'NULL' : "'" . $this->db->prepare_input($consentText) . "'") . ", now(), now())"
            );
            $subId = (int)$this->db->Insert_ID();
            if ($subId <= 0) {
                continue;
            }
            foreach ($g['lines'] as $i) {
                $p = $products[$i];
                // The catalog name, not the order line's: other plugins decorate
                // the line (POSM appends "[In Stock]") and that shouldn't follow
                // the subscription into every email and renewal.
                $name = function_exists('zen_get_products_name') ? trim((string)zen_get_products_name((int)$p['id'])) : '';
                $p['name'] = $name !== '' ? $name : (string)$p['name'];
                $this->db->Execute(
                    "INSERT INTO " . TABLE_SUBSCRIPTIONS_PRODUCTS
                    . " (subscriptions_id, products_id, products_prid, products_name, products_model, quantity, final_price, products_tax, orders_products_id)"
                    . " VALUES (" . $subId . ", " . (int)$p['id'] . ", '" . $this->db->prepare_input(substr((string)$p['id'], 0, 255)) . "', '"
                    . $this->db->prepare_input(substr((string)$p['name'], 0, 255)) . "', '" . $this->db->prepare_input(substr((string)($p['model'] ?? ''), 0, 255)) . "', "
                    . (float)$p['qty'] . ", " . round((float)$p['final_price'], 4) . ", " . round((float)($p['tax'] ?? 0), 4) . ", " . (int)($lineIds[$i] ?? 0) . ")"
                );
            }
            $this->history($subId, 'created', $ordersId, $lineTotal, $consent ? '' : 'No consent record was in the session.', 'customer');
            $label = SubscriptionsCore::intervalLabel($iv['count'], $iv['unit']);
            $this->orderNote($ordersId, sprintf(self::text('SUBSCRIPTIONS_NOTE_STARTED', 'Started subscription #%1$u (%2$s).'), $subId, $label));
            $created[] = $subId;
        }
        return $created;
    }

    protected function productMaxCycles(int $productsId): int
    {
        $r = $this->db->Execute("SELECT max_cycles FROM " . TABLE_PRODUCTS_SUBSCRIPTION . " WHERE products_id = " . $productsId . " LIMIT 1");
        return $r->EOF ? 0 : max(0, (int)$r->fields['max_cycles']);
    }

    public function history(int $subId, string $event, int $ordersId, float $amount, string $detail, string $actor): void
    {
        $this->db->Execute(
            "INSERT INTO " . TABLE_SUBSCRIPTIONS_HISTORY . " (subscriptions_id, event, orders_id, amount, detail, actor, date_added)"
            . " VALUES (" . $subId . ", '" . $this->db->prepare_input($event) . "', " . $ordersId . ", " . round($amount, 4) . ", '"
            . $this->db->prepare_input($detail) . "', '" . $this->db->prepare_input(substr($actor, 0, 64)) . "', now())"
        );
    }

    /** An admin-only line on the order (customer_notified -1: no email, hidden from the customer). */
    protected function orderNote(int $ordersId, string $message): void
    {
        if (function_exists('zen_update_orders_history')) {
            zen_update_orders_history($ordersId, $message, 'Subscriptions', -1, -1);
        }
    }

    /* ----------------------------------------------------------------- *
     * The signup email
     * ----------------------------------------------------------------- */

    /**
     * The email for the subscriptions an order started: [subject, text, html]
     * or null when it started none. $money formats an amount in the order's
     * currency; it is passed in so this stays testable without $currencies.
     */
    public function signupEmail(int $ordersId, callable $money, string $manageUrl): ?array
    {
        $subs = [];
        $r = $this->db->Execute("SELECT * FROM " . TABLE_SUBSCRIPTIONS . " WHERE origin_orders_id = " . $ordersId);
        while (!$r->EOF) {
            $s = $r->fields;
            $s['items'] = [];
            $p = $this->db->Execute("SELECT * FROM " . TABLE_SUBSCRIPTIONS_PRODUCTS . " WHERE subscriptions_id = " . (int)$s['subscriptions_id']);
            while (!$p->EOF) {
                $s['items'][] = $p->fields;
                $p->MoveNext();
            }
            $subs[] = $s;
            $r->MoveNext();
        }
        if ($subs === []) {
            return null;
        }
        return self::composeSignupEmail($ordersId, $subs, $money, $manageUrl);
    }

    /**
     * The signup email from subscription rows (each with an 'items' list of
     * subscriptions_products rows): [subject, text, html]. No database: the
     * store's Preview Email definition (email_preview/subscriptions.php) calls
     * this with sample rows, so the preview is the real email, not a copy.
     */
    public static function composeSignupEmail(int $ordersId, array $subs, callable $money, string $manageUrl): array
    {
        $h = static function ($s) {
            return htmlspecialchars((string)$s, ENT_QUOTES, defined('CHARSET') ? CHARSET : 'UTF-8');
        };
        $text = sprintf(self::text('SUBSCRIPTIONS_EMAIL_INTRO', 'Thank you for subscribing. Here are the details of the subscription your order #%u started.'), $ordersId) . "\n\n";
        $html = '<p>' . $h(sprintf(self::text('SUBSCRIPTIONS_EMAIL_INTRO', 'Thank you for subscribing. Here are the details of the subscription your order #%u started.'), $ordersId)) . '</p>';
        $terms = '';

        foreach ($subs as $s) {
            $label = SubscriptionsCore::intervalLabel((int)$s['interval_count'], $s['interval_unit']);
            $items = [];
            $each = 0.0;
            foreach ($s['items'] ?? [] as $p) {
                $items[] = (float)$p['quantity'] . ' x ' . $p['products_name'];
                $each += (float)$p['final_price'] * (float)$p['quantity'] * (1 + (float)$p['products_tax'] / 100);
            }
            // Pay-link renewals go through a real checkout at that day's prices,
            // and shipping_cost excludes any shipping tax, so the email says so.
            $eachLine = (float)$s['shipping_cost'] > 0
                ? sprintf(
                    self::text('SUBSCRIPTIONS_EMAIL_EACH', 'At today\'s prices, each delivery is %1$s for the items, tax included, plus %2$s shipping.'),
                    $money($each),
                    $money((float)$s['shipping_cost'])
                )
                : sprintf(
                    self::text('SUBSCRIPTIONS_EMAIL_EACH_FREE', 'At today\'s prices, each delivery is %s for the items, tax included, with free shipping.'),
                    $money($each)
                );
            $next = function_exists('zen_date_long') ? zen_date_long($s['next_date']) : $s['next_date'];
            $ends = (int)$s['max_cycles'] > 0
                ? sprintf(self::text('SUBSCRIPTIONS_EMAIL_ENDS', 'Ends after %u deliveries, this order included.'), (int)$s['max_cycles'])
                : self::text('SUBSCRIPTIONS_EMAIL_UNTIL_CANCELED', 'Continues until you cancel.');

            $text .= sprintf(self::text('SUBSCRIPTIONS_EMAIL_HEADING', 'Subscription #%1$u: %2$s'), (int)$s['subscriptions_id'], $label) . "\n";
            foreach ($items as $item) {
                $text .= '  ' . $item . "\n";
            }
            $text .= $eachLine . "\n"
                . sprintf(self::text('SUBSCRIPTIONS_EMAIL_NEXT', 'Next renewal: %s'), $next) . "\n" . $ends . "\n\n";

            $html .= '<h3>' . $h(sprintf(self::text('SUBSCRIPTIONS_EMAIL_HEADING', 'Subscription #%1$u: %2$s'), (int)$s['subscriptions_id'], $label)) . '</h3><ul>';
            foreach ($items as $item) {
                $html .= '<li>' . $h($item) . '</li>';
            }
            $html .= '</ul><p>' . $h($eachLine) . '<br>'
                . $h(sprintf(self::text('SUBSCRIPTIONS_EMAIL_NEXT', 'Next renewal: %s'), $next)) . '<br>' . $h($ends) . '</p>';

            if ($terms === '' && !empty($s['consent_text'])) {
                $terms = (string)$s['consent_text'];
            }
        }

        if ($terms !== '') {
            $text .= self::text('SUBSCRIPTIONS_EMAIL_TERMS', 'The terms you agreed to:') . "\n" . $terms . "\n\n";
            $html .= '<p><strong>' . $h(self::text('SUBSCRIPTIONS_EMAIL_TERMS', 'The terms you agreed to:')) . '</strong><br>' . nl2br($h($terms)) . '</p>';
        }
        $text .= sprintf(self::text('SUBSCRIPTIONS_EMAIL_MANAGE', 'Skip, pause or cancel any time: %s'), $manageUrl) . "\n";
        $html .= '<p>' . $h(self::text('SUBSCRIPTIONS_EMAIL_MANAGE_HTML', 'Skip, pause or cancel any time:')) . ' <a href="' . $h($manageUrl) . '">' . $h($manageUrl) . '</a></p>';

        $subject = sprintf(self::text('SUBSCRIPTIONS_EMAIL_SUBJECT', 'Your subscription from %s'), defined('STORE_NAME') ? STORE_NAME : '');
        return [$subject, $text, $html];
    }

    protected static function text(string $constant, string $default): string
    {
        return defined($constant) ? (string)constant($constant) : $default;
    }
}
