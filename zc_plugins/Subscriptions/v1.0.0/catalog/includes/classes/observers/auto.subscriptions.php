<?php
/**
 * Subscriptions -- storefront observer.
 *
 * Found and constructed by Zen Cart itself (auto.subscriptions.php ->
 * zcObserverSubscriptions). Never list it in an auto_loader.
 *
 * Checkout:
 *   - guests can't buy a subscription: One Page Checkout's guest option is
 *     switched off while the cart holds one, with backstops on every later page;
 *   - the consent checkbox is drawn on the payment step (core checkout_payment,
 *     OPC checkout_one) and recorded wherever that form lands: the core
 *     confirmation page, OPC's confirmation page, or the AJAX
 *     prepareConfirmation call card-on-page modules use;
 *   - checkout_process refuses an order whose consent doesn't match the cart;
 *   - lines a Renew Now link put in the cart renew their subscription: they
 *     need an account but no fresh consent, and never start a second one.
 *
 * Orders (order-class notifiers, so a PayPal IPN-created order is covered too):
 *   - each order line's orders_products_id is noted as it's written,
 *   - the subscriptions are created once the products are in,
 *   - the signup email goes out after the order confirmation.
 *
 * Zen Cart 3.0.0-dev only: the subscribe-and-save discount is applied here,
 * because core's price factor fatals there (see SubscriptionsCore).
 *
 * @package  Subscriptions
 * @license  http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

if (!class_exists('SubscriptionsCheckout', false)) {
    require_once dirname(__DIR__, 4) . '/shared/SubscriptionsCheckout.php';
}
if (!class_exists('SubscriptionsRenewals', false)) {
    require_once dirname(__DIR__, 4) . '/shared/SubscriptionsRenewals.php';
}

class zcObserverSubscriptions extends base
{
    /** @var SubscriptionsCheckout|null */
    protected $checkout;

    /** order product index => orders_products_id, for the order being written */
    protected $lineIds = [];

    /** @var int */
    protected $ordersId = 0;

    /** products_id => discount percent, for the 3.0 fallback */
    protected $discounts = [];

    /** Output-buffer level of the My Account link buffer, 0 when none is open. */
    protected $obLevel = 0;

    public function __construct()
    {
        if (!defined('SUBSCRIPTIONS_STATUS')) {
            return;
        }
        $events = [
            'NOTIFY_HTML_HEAD_END',
            'NOTIFY_FOOTER_END',
            'NOTIFY_OPC_GUEST_CHECKOUT_OVERRIDE',
            'NOTIFY_HEADER_START_CHECKOUT_ONE',
            'NOTIFY_HEADER_START_CHECKOUT_CONFIRMATION',
            'NOTIFY_HEADER_START_CHECKOUT_ONE_CONFIRMATION',
            'NOTIFY_CHECKOUT_ONE_CONFIRMATION_PRE_ORDER_CHECK',
            'NOTIFY_ORDER_CART_FINISHED',
            'NOTIFY_CHECKOUT_PROCESS_BEGIN',
            'NOTIFY_ORDER_DURING_CREATE_ADDED_PRODUCT_LINE_ITEM',
            'NOTIFY_ORDER_AFTER_ORDER_CREATE_ADD_PRODUCTS',
            'NOTIFY_ORDER_AFTER_SEND_ORDER_EMAIL',
        ];
        if (!SubscriptionsCore::nativeFactorSafe()) {
            $events[] = 'NOTIFY_CART_CALCULATE_ATTRIBUTE_PRICE';
            $events[] = 'NOTIFY_CART_ATTRIBUTES_PRICE_NEXT';
        }
        $this->attach($this, $events);
    }

    public function update(&$class, $eventID, $param1 = null, &$param2 = null, &$param3 = null, &$param4 = null)
    {
        switch ($eventID) {
            case 'NOTIFY_HTML_HEAD_END':
                $this->drawConsent();
                $this->startAccountLink();
                break;

            case 'NOTIFY_FOOTER_END':
                // Close only our own buffer, and only if nothing else is open above it.
                if ($this->obLevel > 0 && ob_get_level() === $this->obLevel) {
                    ob_end_flush();
                }
                $this->obLevel = 0;
                break;

            case 'NOTIFY_OPC_GUEST_CHECKOUT_OVERRIDE':
                if ($param2 && $this->cartHasSubscription()) {
                    $param2 = false;
                }
                break;

            case 'NOTIFY_HEADER_START_CHECKOUT_ONE':
            case 'NOTIFY_HEADER_START_CHECKOUT_ONE_CONFIRMATION':
                $this->refuseGuest();
                break;

            case 'NOTIFY_HEADER_START_CHECKOUT_CONFIRMATION':
                // Core three-page checkout: the payment form posts here.
                if ($this->cartNeedsConsent()) {
                    $this->takeConsentFromPost();
                    if (!$this->consentValid()) {
                        $this->back(FILENAME_CHECKOUT_PAYMENT, SUBSCRIPTIONS_ERROR_CONSENT);
                    }
                }
                break;

            case 'NOTIFY_CHECKOUT_ONE_CONFIRMATION_PRE_ORDER_CHECK':
                if ($this->cartNeedsConsent()) {
                    $this->takeConsentFromPost();
                    if (!$this->consentValid()) {
                        $param2 = true;
                        $GLOBALS['messageStack']->add_session('checkout_payment', SUBSCRIPTIONS_ERROR_CONSENT, 'error');
                    }
                }
                break;

            case 'NOTIFY_ORDER_CART_FINISHED':
                // Card-on-page modules post the payment form to ajax.php's
                // prepareConfirmation, which builds an order: that's the only
                // place their POST is seen.
                if (($_GET['act'] ?? '') === 'ajaxPayment' && $_SERVER['REQUEST_METHOD'] === 'POST' && $this->cartNeedsConsent()) {
                    $this->takeConsentFromPost();
                }
                break;

            case 'NOTIFY_CHECKOUT_PROCESS_BEGIN':
                if ($this->cartHasSubscription()) {
                    $this->refuseGuest();
                    if ($this->cartNeedsConsent() && !$this->consentValid()) {
                        $usesOpc = defined('FILENAME_CHECKOUT_ONE') && isset($_SESSION['opc']);
                        $this->back($usesOpc ? FILENAME_CHECKOUT_ONE : FILENAME_CHECKOUT_PAYMENT, SUBSCRIPTIONS_ERROR_CONSENT);
                    }
                }
                break;

            case 'NOTIFY_ORDER_DURING_CREATE_ADDED_PRODUCT_LINE_ITEM':
                if (is_array($param1)) {
                    $this->ordersId = (int)($param1['orders_id'] ?? 0);
                    $this->lineIds[(int)$param1['i']] = (int)$param1['orders_products_id'];
                }
                break;

            case 'NOTIFY_ORDER_AFTER_ORDER_CREATE_ADD_PRODUCTS':
                if ($this->ordersId > 0 && is_object($class)) {
                    // Renewals first: their lines are then left out of the new-subscription pass.
                    $marker = $_SESSION[SubscriptionsRenewals::SESSION_KEY] ?? [];
                    $renewed = [];
                    if (is_array($marker) && $marker !== []) {
                        $renewals = new SubscriptionsRenewals($GLOBALS['db']);
                        $renewed = $renewals->recordOrder($this->ordersId, (array)$class->products, $this->lineIds, $marker);
                    }
                    unset($_SESSION[SubscriptionsRenewals::SESSION_KEY]);
                    $consent = $_SESSION[SubscriptionsCheckout::SESSION_KEY] ?? null;
                    $created = $this->checkout()->recordOrder(
                        $this->ordersId,
                        (array)$class->products,
                        (array)$class->info,
                        $this->lineIds,
                        is_array($consent) ? $consent : null,
                        defined('CHECKOUT_ONE_GUEST_CUSTOMER_ID') ? (int)CHECKOUT_ONE_GUEST_CUSTOMER_ID : 0,
                        $renewed
                    );
                    if ($created !== []) {
                        unset($_SESSION[SubscriptionsCheckout::SESSION_KEY]);
                    }
                }
                $this->lineIds = [];
                break;

            case 'NOTIFY_ORDER_AFTER_SEND_ORDER_EMAIL':
                $this->sendSignupEmail((int)$param1);
                break;

            case 'NOTIFY_CART_CALCULATE_ATTRIBUTE_PRICE':
            case 'NOTIFY_CART_ATTRIBUTES_PRICE_NEXT':
                if (is_array($param2)) {
                    $this->applyDiscount((string)$param1, $param2);
                }
                break;
        }
    }

    protected function checkout(): SubscriptionsCheckout
    {
        if ($this->checkout === null) {
            $this->checkout = new SubscriptionsCheckout($GLOBALS['db']);
        }
        return $this->checkout;
    }

    protected function cartContents(): array
    {
        return (isset($_SESSION['cart']) && is_object($_SESSION['cart']) && is_array($_SESSION['cart']->contents)) ? $_SESSION['cart']->contents : [];
    }

    /** Any subscription line, renewals included: these all need a customer account. */
    protected function cartHasSubscription(): bool
    {
        return $this->checkout()->cartSubscriptionLines($this->cartContents()) !== [];
    }

    /** The prids a Renew Now link put in the cart. */
    protected function renewalPrids(): array
    {
        $prids = [];
        foreach ((array)($_SESSION[SubscriptionsRenewals::SESSION_KEY] ?? []) as $m) {
            foreach ((array)($m['prids'] ?? []) as $prid) {
                $prids[] = (string)$prid;
            }
        }
        return $prids;
    }

    /** A line that would start a new subscription: those need the customer's consent. */
    protected function cartNeedsConsent(): bool
    {
        return $this->checkout()->cartSubscriptionLines($this->cartContents(), $this->renewalPrids()) !== [];
    }

    protected function consentValid(): bool
    {
        return $this->checkout()->consentValid($_SESSION, $this->cartContents(), $this->renewalPrids());
    }

    /** The box is on the posted form: ticked records consent, unticked withdraws it. */
    protected function takeConsentFromPost(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }
        if (($_POST['subs_consent'] ?? '') === '1') {
            $ip = (string)($_SESSION['customers_ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''));
            $this->checkout()->recordConsent($_SESSION, $this->cartContents(), $ip, $this->renewalPrids());
        } else {
            $this->checkout()->clearConsent($_SESSION);
        }
    }

    protected function refuseGuest(): void
    {
        if (!function_exists('zen_in_guest_checkout') || !zen_in_guest_checkout() || !$this->cartHasSubscription()) {
            return;
        }
        if (isset($_SESSION['opc']) && is_object($_SESSION['opc']) && method_exists($_SESSION['opc'], 'resetGuestSessionValues')) {
            $_SESSION['opc']->resetGuestSessionValues();
        }
        $this->back(FILENAME_LOGIN, SUBSCRIPTIONS_ERROR_GUEST, 'login');
    }

    protected function back(string $page, string $message, string $stack = 'checkout_payment'): void
    {
        $GLOBALS['messageStack']->add_session($stack, $message, 'error');
        zen_redirect(zen_href_link($page, '', 'SSL'));
    }

    /**
     * The consent block, placed by script on the payment step. Placement, first
     * that exists: after OPC's conditions, before OPC's submit block, before
     * core's #paymentSubmit, before the form's last submit control, else at the
     * end of the form. The server enforces consent whether or not the template
     * let the script place it, and says why when it refuses.
     */
    protected function drawConsent(): void
    {
        global $current_page_base;
        if (!in_array($current_page_base ?? '', ['checkout_payment', 'checkout_one'], true) || !$this->cartNeedsConsent()) {
            return;
        }
        $h = static function ($s) {
            return htmlspecialchars((string)$s, ENT_QUOTES, defined('CHARSET') ? CHARSET : 'UTF-8');
        };
        $checked = $this->consentValid() ? ' checked' : '';
        $html = '<fieldset class="subs-consent"><legend>' . $h(SUBSCRIPTIONS_CONSENT_HEADING) . '</legend>'
            . '<p class="subs-consent-terms">' . nl2br($h(SubscriptionsCheckout::termsText())) . '</p>'
            . '<p><input type="checkbox" name="subs_consent" value="1" id="subs-consent" required' . $checked . '> '
            . '<label class="checkboxLabel" for="subs-consent">' . $h(SUBSCRIPTIONS_CONSENT_LABEL) . '</label></p></fieldset>';
        $json = json_encode($html, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        echo '<style>.subs-consent{clear:both;margin:1em 0}.subs-consent-terms{margin:0 0 .5em}</style>' . "\n"
            . '<script>document.addEventListener("DOMContentLoaded",function(){'
            . 'var f=document.forms.checkout_payment;if(!f||document.getElementById("subs-consent"))return;'
            . 'var box=document.createElement("div");box.innerHTML=' . $json . ';box=box.firstChild;'
            . 'var c=document.getElementById("conditions-div");if(c&&f.contains(c)){c.parentNode.insertBefore(box,c.nextSibling);return;}'
            . 'var t=document.getElementById("checkoutOneSubmit")||document.getElementById("paymentSubmit");'
            . 'if(!t||!f.contains(t)){var s=f.querySelectorAll("input[type=submit],button[type=submit],input[type=image]");t=s.length?s[s.length-1]:null;'
            . 'while(t&&t.parentNode&&t.parentNode!==f&&!/^(DIV|P|FIELDSET)$/.test(t.parentNode.tagName)){t=t.parentNode;}'
            . 'if(t&&t.parentNode&&t.parentNode.tagName!=="FORM"&&t.parentNode!==f){t=t.parentNode;}}'
            . 'if(t&&t.parentNode){t.parentNode.insertBefore(box,t);}else{f.appendChild(box);}'
            . '});</script>' . "\n";
    }

    /**
     * My Account has no extension point on any release (tpl_account_default.php
     * is a fixed list), so the link is added to the finished page: buffered from
     * NOTIFY_HTML_HEAD_END, placed after core's newsletters link, flushed at
     * NOTIFY_FOOTER_END (or by PHP at the end, if a template skips that).
     * Only for a logged-in customer who has a subscription.
     */
    protected function startAccountLink(): void
    {
        global $current_page_base;
        if (($current_page_base ?? '') !== 'account' || empty($_SESSION['customer_id'])
            || (function_exists('zen_in_guest_checkout') && zen_in_guest_checkout())) {
            return;
        }
        $r = $GLOBALS['db']->Execute("SELECT subscriptions_id FROM " . TABLE_SUBSCRIPTIONS . " WHERE customers_id = " . (int)$_SESSION['customer_id'] . " LIMIT 1");
        if ($r->EOF) {
            return;
        }
        $link = '<a href="' . zen_href_link(FILENAME_ACCOUNT_SUBSCRIPTIONS, '', 'SSL') . '">'
            . htmlspecialchars(SUBSCRIPTIONS_ACCOUNT_LINK, ENT_QUOTES, defined('CHARSET') ? CHARSET : 'UTF-8') . '</a>';
        if (ob_start(static function ($html) use ($link) {
            return zcObserverSubscriptions::insertAccountLink((string)$html, $link);
        })) {
            $this->obLevel = ob_get_level();
        }
    }

    /**
     * Put $link right after the account page's newsletters link: as a new <li>
     * when that link is the last thing in an <li> (core's markup), otherwise
     * beside it. Leaves the page alone if the link is already there (a template
     * that added it by hand) or the newsletters link can't be found.
     */
    public static function insertAccountLink(string $html, string $link): string
    {
        if (strpos($html, 'main_page=' . FILENAME_ACCOUNT_SUBSCRIPTIONS) !== false) {
            return $html;
        }
        if (!preg_match('~<a\b[^>]*href="[^"]*main_page=account_newsletters[^"]*"[^>]*>.*?</a>(\s*</li>)?~is', $html, $m, PREG_OFFSET_CAPTURE)) {
            return $html;
        }
        $end = $m[0][1] + strlen($m[0][0]);
        $insert = (isset($m[1]) && $m[1][1] >= 0) ? "\n<li>" . $link . '</li>' : ' ' . $link;
        return substr($html, 0, $end) . $insert . substr($html, $end);
    }

    protected function sendSignupEmail(int $ordersId): void
    {
        global $currencies;
        if ($ordersId <= 0 || !function_exists('zen_mail')) {
            return;
        }
        $o = $GLOBALS['db']->Execute(
            "SELECT customers_name, customers_email_address, currency, currency_value FROM " . TABLE_ORDERS . " WHERE orders_id = " . $ordersId . " LIMIT 1"
        );
        if ($o->EOF || $o->fields['customers_email_address'] === '') {
            return;
        }
        $currency = $o->fields['currency'];
        $value = (float)$o->fields['currency_value'];
        $money = static function ($amount) use ($currencies, $currency, $value) {
            return is_object($currencies) ? $currencies->format($amount, true, $currency, $value) : number_format((float)$amount, 2);
        };
        $mail = $this->checkout()->signupEmail($ordersId, $money, zen_href_link(FILENAME_ACCOUNT_SUBSCRIPTIONS, '', 'SSL', false));
        if ($mail === null) {
            return;
        }
        [$subject, $text, $html] = $mail;
        // Preview Email: subscriptions_signup
        zen_mail(
            (string)$o->fields['customers_name'],
            (string)$o->fields['customers_email_address'],
            $subject,
            $text,
            STORE_NAME,
            EMAIL_FROM,
            ['EMAIL_MESSAGE_HTML' => $html],
            'default'
        );
    }

    /**
     * 3.0.0-dev only. Rewrite the Delivery row in memory so core subtracts X%
     * of the product's special (or base) price as a plain "-" amount. Values
     * are strings: 3.0's cart compares them strictly ('1', '-').
     */
    protected function applyDiscount(string $uprid, array &$row): void
    {
        $option = $this->checkout()->optionId();
        if ((int)($row['options_id'] ?? 0) !== $option || $option <= 0) {
            return;
        }
        if ($this->checkout()->intervalFor((int)($row['options_values_id'] ?? 0)) === null) {
            return;
        }
        $pid = (int)$uprid;
        if (!isset($this->discounts[$pid])) {
            $r = $GLOBALS['db']->Execute("SELECT discount_percent FROM " . TABLE_PRODUCTS_SUBSCRIPTION . " WHERE products_id = " . $pid . " LIMIT 1");
            $this->discounts[$pid] = $r->EOF ? 0.0 : SubscriptionsCore::cleanDiscount($r->fields['discount_percent']);
        }
        if ($this->discounts[$pid] <= 0) {
            return;
        }
        $special = zen_get_products_special_price($pid, false);
        $base = ($special !== false && (float)$special > 0) ? (float)$special : (float)zen_get_products_base_price($pid);
        $row['options_values_price'] = (string)round($base * $this->discounts[$pid] / 100, 4);
        $row['options_values_price_w'] = '';
        $row['price_prefix'] = '-';
        $row['attributes_discounted'] = '0';
        $row['attributes_price_factor'] = '0';
        $row['attributes_price_factor_offset'] = '0';
    }
}
