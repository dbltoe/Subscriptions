<?php
/**
 * Subscriptions -- the scheduler.
 *
 *   index.php?main_page=subscriptions_cron&key=<Scheduler Key>
 *
 * Opened once an hour by a cron job (curl) or an outside cron service. It runs
 * inside a normal storefront request, so the emails get the store's templates,
 * currencies and links exactly as a customer's page would. Answers in plain
 * text and never renders a template; a wrong or missing key gets a bare 403.
 *
 * @package  Subscriptions
 * @license  http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

require_once dirname(__DIR__, 5) . '/shared/SubscriptionsRenewals.php';

header('Content-Type: text/plain; charset=' . CHARSET);
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$subsExpected = defined('SUBSCRIPTIONS_CRON_KEY') ? (string)SUBSCRIPTIONS_CRON_KEY : '';
if ($subsExpected === '' || !hash_equals($subsExpected, (string)($_GET['key'] ?? ''))) {
    http_response_code(403);
    echo "Forbidden\n";
    zen_exit();
}

$subsManageUrl = str_replace('&amp;', '&', zen_href_link(FILENAME_ACCOUNT_SUBSCRIPTIONS, '', 'SSL', false));
$subsCurrencies = isset($currencies) && is_object($currencies) ? $currencies : null;
$subsRenewals = new SubscriptionsRenewals($db);
$subsReport = $subsRenewals->run(
    static function (int $subId, string $token): string {
        return str_replace('&amp;', '&', zen_href_link(FILENAME_ACCOUNT_SUBSCRIPTIONS, 'renew=' . $subId . '&t=' . $token, 'SSL', false));
    },
    static function (float $amount, string $currency) use ($subsCurrencies): string {
        return $subsCurrencies !== null ? $subsCurrencies->format($amount, true, $currency) : number_format($amount, 2);
    },
    static function (array $mail): void {
        // Preview Email: subscriptions_reminder, subscriptions_lapsed
        zen_mail($mail['name'], $mail['email'], $mail['subject'], $mail['text'], STORE_NAME, EMAIL_FROM, ['EMAIL_MESSAGE_HTML' => $mail['html']], 'default');
    },
    $subsManageUrl
);

// Subscriptions Pro hooks in here to charge saved cards and add to the report.
$zco_notifier->notify('NOTIFY_SUBSCRIPTIONS_SCHEDULER_END', [], $subsReport);

echo 'Subscriptions scheduler, ' . date('Y-m-d H:i:s') . "\n";
if ($subsReport['locked']) {
    echo "Another run is still in progress, so this one did nothing.\n";
} else {
    echo 'Reminders sent: ' . $subsReport['reminded'] . "\n"
        . 'Missed renewals moved to the next date: ' . $subsReport['missed'] . "\n"
        . 'Paused after missed renewals: ' . $subsReport['paused'] . "\n"
        . 'Errors: ' . ($subsReport['errors'] === [] ? 'none' : "\n  " . implode("\n  ", $subsReport['errors'])) . "\n";
}
zen_exit();
