<?php
/**
 * Subscriptions -- the customer's My Subscriptions page.
 *
 * Every change is a POST carrying `action`, so core's init_sanitize checks the
 * securityToken that zen_draw_form() adds before this file runs (every release
 * 1.5.8 -> 3.0.0). After a change the page redirects to itself, so a reload
 * never repeats it.
 *
 * @package  Subscriptions
 * @license  http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

$zco_notifier->notify('NOTIFY_HEADER_START_ACCOUNT_SUBSCRIPTIONS');

if (!zen_is_logged_in() || zen_in_guest_checkout()) {
    $_SESSION['navigation']->set_snapshot();
    zen_redirect(zen_href_link(FILENAME_LOGIN, '', 'SSL'));
}

require DIR_WS_MODULES . zen_get_module_directory('require_languages.php');
require_once dirname(__DIR__, 5) . '/shared/SubscriptionsManager.php';
require_once dirname(__DIR__, 5) . '/shared/SubscriptionsRenewals.php';

// This page's own titles; defined here so they exist only on this page.
if (!defined('NAVBAR_TITLE_1')) {
    define('NAVBAR_TITLE_1', SUBSCRIPTIONS_PAGE_NAVBAR_ACCOUNT);
}
if (!defined('NAVBAR_TITLE')) {
    define('NAVBAR_TITLE', SUBSCRIPTIONS_PAGE_HEADING);
}
if (!defined('HEADING_TITLE')) {
    define('HEADING_TITLE', SUBSCRIPTIONS_PAGE_HEADING);
}
$breadcrumb->add(NAVBAR_TITLE_1, zen_href_link(FILENAME_ACCOUNT, '', 'SSL'));
$breadcrumb->add(NAVBAR_TITLE);

$subsCustomerId = (int)$_SESSION['customer_id'];
$subsManager = new SubscriptionsManager($db);
$subsRenewals = new SubscriptionsRenewals($db);
$subsConfirmCancel = 0;

// Renew Now: the link in the reminder email (GET, with the token issued for
// this delivery) or the button on this page (POST, CSRF-checked by core).
// Either way the items go in the cart and the customer checks out as usual.
$subsRenewId = 0;
$subsRenewCode = '';
if (isset($_GET['renew'])) {
    $subsRenewId = (int)$_GET['renew'];
    $subsRenewCode = $subsRenewals->checkLink($subsCustomerId, $subsRenewId, (string)($_GET['t'] ?? ''));
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'renew') {
    $subsRenewId = (int)($_POST['subscription'] ?? 0);
    $subsRenewCode = 'ok';
}
if ($subsRenewId > 0) {
    $subsCart = $subsRenewCode === 'ok' ? $subsRenewals->cartFor($subsCustomerId, $subsRenewId) : ['code' => $subsRenewCode, 'lines' => [], 'unavailable' => []];
    if ($subsCart['code'] === 'ok') {
        $subsPrids = [];
        foreach ($subsCart['lines'] as $subsLine) {
            $_SESSION['cart']->add_cart($subsLine[0], $subsLine[1], $subsLine[2], false);
            $subsPrids[] = zen_get_uprid($subsLine[0], $subsLine[2]);
        }
        $_SESSION[SubscriptionsRenewals::SESSION_KEY][$subsRenewId] = ['due' => $subsCart['due'], 'prids' => $subsPrids];
        $messageStack->add_session('shopping_cart', sprintf(SUBSCRIPTIONS_PAGE_RENEW_ADDED, $subsRenewId, zen_date_long($subsCart['due'])), 'success');
        if ($subsCart['unavailable'] !== []) {
            $messageStack->add_session('shopping_cart', sprintf(SUBSCRIPTIONS_PAGE_RENEW_UNAVAILABLE, implode(', ', $subsCart['unavailable'])), 'caution');
        }
        zen_redirect(zen_href_link(FILENAME_SHOPPING_CART, '', 'NONSSL'));
    }
    $subsRenewMessages = [
        'link_expired' => SUBSCRIPTIONS_PAGE_RENEW_EXPIRED,
        'not_due' => SUBSCRIPTIONS_PAGE_RENEW_NOT_DUE,
        'nothing_available' => sprintf(SUBSCRIPTIONS_PAGE_RENEW_NOTHING, $subsRenewId),
    ];
    $messageStack->add_session('account_subscriptions', $subsRenewMessages[$subsCart['code']] ?? SUBSCRIPTIONS_PAGE_NOT_ALLOWED, 'error');
    zen_redirect(zen_href_link(FILENAME_ACCOUNT_SUBSCRIPTIONS, '', 'SSL'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $subsAction = (string)($_POST['action'] ?? '');
    $subsId = (int)($_POST['subscription'] ?? 0);
    $subsResult = '';

    switch ($subsAction) {
        case 'skip':
            $subsResult = $subsManager->skip($subsCustomerId, $subsId);
            break;
        case 'pause':
            $subsResult = $subsManager->pause($subsCustomerId, $subsId);
            break;
        case 'resume':
            $subsResult = $subsManager->resume($subsCustomerId, $subsId);
            break;
        case 'interval':
            $subsResult = $subsManager->changeInterval($subsCustomerId, $subsId, (string)($_POST['interval'] ?? ''));
            break;
        case 'quantities':
            $subsQuantities = [];
            foreach ((array)($_POST['qty'] ?? []) as $subsKey => $subsValue) {
                $subsQuantities[(int)$subsKey] = is_scalar($subsValue) ? (string)$subsValue : '';
            }
            $subsResult = $subsManager->changeQuantities($subsCustomerId, $subsId, $subsQuantities);
            break;
        case 'cancel_ask':
            // Not a change yet: show the confirmation for this subscription.
            $subsConfirmCancel = $subsId;
            break;
        case 'cancel':
            $subsResult = $subsManager->cancel($subsCustomerId, $subsId, (string)($_POST['reason'] ?? ''));
            break;
    }

    if ($subsResult !== '') {
        $subsMessages = [
            'ok' => ['skip' => SUBSCRIPTIONS_PAGE_SKIPPED, 'pause' => SUBSCRIPTIONS_PAGE_PAUSED, 'resume' => SUBSCRIPTIONS_PAGE_RESUMED,
                'interval' => SUBSCRIPTIONS_PAGE_INTERVAL_CHANGED, 'quantities' => SUBSCRIPTIONS_PAGE_QUANTITIES_CHANGED, 'cancel' => SUBSCRIPTIONS_PAGE_CANCELED],
            'unchanged' => SUBSCRIPTIONS_PAGE_UNCHANGED,
            'bad_quantity' => SUBSCRIPTIONS_PAGE_BAD_QUANTITY,
            'bad_interval' => SUBSCRIPTIONS_PAGE_BAD_INTERVAL,
        ];
        if ($subsResult === 'ok') {
            $messageStack->add_session('account_subscriptions', sprintf($subsMessages['ok'][$subsAction], $subsId), 'success');
            // Confirm a pause or a cancellation in writing (several US states
            // require it for a cancellation).
            $subsMail = in_array($subsAction, ['pause', 'cancel'], true)
                ? $subsManager->changeEmail($subsCustomerId, $subsId, $subsAction, zen_href_link(FILENAME_ACCOUNT_SUBSCRIPTIONS, '', 'SSL', false))
                : null;
            if ($subsMail !== null && function_exists('zen_mail')) {
                // Preview Email: subscriptions_paused, subscriptions_canceled
                zen_mail($subsMail['name'], $subsMail['email'], $subsMail['subject'], $subsMail['text'], STORE_NAME, EMAIL_FROM, ['EMAIL_MESSAGE_HTML' => $subsMail['html']], 'default');
            }
        } else {
            $messageStack->add_session('account_subscriptions', $subsMessages[$subsResult] ?? SUBSCRIPTIONS_PAGE_NOT_ALLOWED, $subsResult === 'unchanged' ? 'caution' : 'error');
        }
        zen_redirect(zen_href_link(FILENAME_ACCOUNT_SUBSCRIPTIONS, '', 'SSL'));
    }
}

$subscriptions = $subsManager->forCustomer($subsCustomerId);

$zco_notifier->notify('NOTIFY_HEADER_END_ACCOUNT_SUBSCRIPTIONS');
