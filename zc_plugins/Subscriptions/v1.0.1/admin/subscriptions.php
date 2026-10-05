<?php
/**
 * Subscriptions -- Customers > Subscriptions.
 *
 *   index.php?cmd=subscriptions                   the list: counts by status, search, pages
 *   index.php?cmd=subscriptions&sID=12            one subscription: items, consent, history, actions
 *
 * Actions are POSTs carrying `action` and `sID`. Core's init_sessions refuses
 * any admin POST without the session's securityToken before this file runs
 * (every release 1.5.8 -> 3.0.0). Each change goes through
 * SubscriptionsManager with the admin as the actor, so the admin obeys the
 * same rules as the customer, then the page redirects back to itself.
 *
 * Declares no functions: the work is in the shared classes.
 *
 * @package  Subscriptions
 * @license  http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

// Reached only as admin/index.php?cmd=subscriptions, and index.php loads the
// bootstrap (which defines IS_ADMIN_FLAG) before routing here. A direct request
// for this file, where zc_plugins' .htaccess isn't honored, stops here instead
// of failing in the relative require below.
if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

require 'includes/application_top.php';
if (!class_exists('SubscriptionsAdmin', false)) {
    require_once __DIR__ . '/../shared/SubscriptionsAdmin.php';
}
if (!class_exists('SubscriptionsManager', false)) {
    require_once __DIR__ . '/../shared/SubscriptionsManager.php';
}
if (!class_exists('SubscriptionsRenewals', false)) {
    require_once __DIR__ . '/../shared/SubscriptionsRenewals.php';
}

// The admin doesn't make $currencies for every page; pages that show money do,
// as orders.php does. 3.0 autoloads the class; earlier releases need the file.
if (!isset($currencies) || !is_object($currencies)) {
    if (!class_exists('currencies')) {
        require_once DIR_WS_CLASSES . 'currencies.php';
    }
    $currencies = new currencies();
}

$subsAdmin = new SubscriptionsAdmin($db);
$subsStatusLabels = [
    'active' => SUBSCRIPTIONS_ADMIN_STATUS_ACTIVE,
    'paused' => SUBSCRIPTIONS_ADMIN_STATUS_PAUSED,
    'past_due' => SUBSCRIPTIONS_ADMIN_STATUS_PAST_DUE,
    'canceled' => SUBSCRIPTIONS_ADMIN_STATUS_CANCELED,
    'expired' => SUBSCRIPTIONS_ADMIN_STATUS_EXPIRED,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $subsAction = (string)($_POST['action'] ?? '');
    $subsPostId = (int)($_POST['sID'] ?? 0);
    $subsSub = $subsAdmin->detail($subsPostId);
    $subsResult = 'not_found';
    $subsEmailed = false;
    if ($subsSub !== null) {
        $subsCustomerId = (int)$subsSub['customers_id'];
        $subsManager = new SubscriptionsManager($db);
        $subsManager->setActor('admin: ' . zen_get_admin_name((int)($_SESSION['admin_id'] ?? 0)));
        switch ($subsAction) {
            case 'skip':
                $subsResult = $subsManager->skip($subsCustomerId, $subsPostId);
                break;
            case 'pause':
                $subsResult = $subsManager->pause($subsCustomerId, $subsPostId);
                break;
            case 'resume':
                $subsResult = $subsManager->resume($subsCustomerId, $subsPostId);
                break;
            case 'next_date':
                $subsResult = $subsManager->setNextDate($subsCustomerId, $subsPostId, (string)($_POST['next_date'] ?? ''));
                break;
            case 'cancel':
                $subsResult = $subsManager->cancel($subsCustomerId, $subsPostId, (string)($_POST['reason'] ?? ''));
                break;
            default:
                $subsResult = 'unknown';
        }
        if ($subsResult === 'ok' && in_array($subsAction, ['pause', 'cancel'], true) && ($_POST['notify'] ?? '') === '1') {
            // The same confirmation the customer gets when they do it themselves.
            SubscriptionsCore::loadStorefrontText((string)($_SESSION['language'] ?? 'english'));
            $subsMail = $subsManager->changeEmail($subsCustomerId, $subsPostId, $subsAction, str_replace('&amp;', '&', zen_catalog_href_link(FILENAME_ACCOUNT_SUBSCRIPTIONS, '', 'SSL')));
            if ($subsMail !== null) {
                // Preview Email: subscriptions_paused, subscriptions_canceled
                zen_mail($subsMail['name'], $subsMail['email'], $subsMail['subject'], $subsMail['text'], STORE_NAME, EMAIL_FROM, ['EMAIL_MESSAGE_HTML' => $subsMail['html']], 'default');
                $subsEmailed = true;
            }
        }
    }
    $subsDone = [
        'skip' => SUBSCRIPTIONS_ADMIN_DONE_SKIP,
        'pause' => SUBSCRIPTIONS_ADMIN_DONE_PAUSE,
        'resume' => SUBSCRIPTIONS_ADMIN_DONE_RESUME,
        'next_date' => SUBSCRIPTIONS_ADMIN_DONE_NEXT_DATE,
        'cancel' => SUBSCRIPTIONS_ADMIN_DONE_CANCEL,
    ];
    if ($subsResult === 'ok') {
        $messageStack->add_session(sprintf($subsDone[$subsAction], $subsPostId) . ($subsEmailed ? ' ' . SUBSCRIPTIONS_ADMIN_EMAILED : ''), 'success');
    } elseif ($subsResult === 'unchanged') {
        $messageStack->add_session(SUBSCRIPTIONS_ADMIN_UNCHANGED, 'caution');
    } elseif ($subsResult === 'bad_date') {
        $messageStack->add_session(SUBSCRIPTIONS_ADMIN_ERR_BAD_DATE, 'error');
    } elseif ($subsResult === 'not_found' || $subsResult === 'unknown') {
        $messageStack->add_session(SUBSCRIPTIONS_ADMIN_ERR_NOT_FOUND, 'error');
    } else {
        $messageStack->add_session(sprintf(SUBSCRIPTIONS_ADMIN_ERR_NOT_ALLOWED, $subsStatusLabels[$subsSub['status'] ?? ''] ?? (string)($subsSub['status'] ?? '')), 'error');
    }
    zen_redirect(zen_href_link(FILENAME_SUBSCRIPTIONS, $subsSub !== null ? 'sID=' . $subsPostId : '', 'SSL'));
}

$subsH = static function ($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, CHARSET);
};
$subsDate = static function ($ymd): string {
    $ymd = (string)$ymd;
    return ($ymd === '' || strpos($ymd, '0001-01-01') === 0) ? '' : zen_date_short($ymd);
};
$subsToken = htmlspecialchars((string)($_SESSION['securityToken'] ?? ''), ENT_QUOTES, CHARSET);
// Core's zen_href_link() hands back HTML-ready links (& already &amp;), so
// they're printed as they come; only our own values go through $subsH.
$subsSelf = zen_href_link(FILENAME_SUBSCRIPTIONS, '', 'SSL');
$subsId = (int)($_GET['sID'] ?? 0);
$subsSub = $subsId > 0 ? $subsAdmin->detail($subsId) : null;
if ($subsId > 0 && $subsSub === null) {
    $messageStack->add(SUBSCRIPTIONS_ADMIN_ERR_NOT_FOUND, 'error');
}
if ($subsSub === null) {
    $subsList = $subsAdmin->search((string)($_GET['status'] ?? ''), (string)($_GET['q'] ?? ''), (int)($_GET['page'] ?? 1));
    $subsCounts = $subsAdmin->counts();
    $subsCronKey = defined('SUBSCRIPTIONS_CRON_KEY') ? (string)SUBSCRIPTIONS_CRON_KEY : '';
    $subsHealth = SubscriptionsAdmin::schedulerHealth(defined('SUBSCRIPTIONS_LAST_RUN') ? (string)SUBSCRIPTIONS_LAST_RUN : '', time());
}
?>
<!DOCTYPE html>
<html <?= HTML_PARAMS ?>>
<head>
<?php require DIR_WS_INCLUDES . 'admin_html_head.php'; ?>
<style>
.subs-admin h2{font-size:1.2em;margin:24px 0 8px;padding-bottom:4px;border-bottom:1px solid #d8dee4}
.subs-admin .subs-counts a{margin-right:14px}
.subs-admin .subs-counts a.subs-current{font-weight:bold;text-decoration:underline}
.subs-admin .subs-search{margin:12px 0}
.subs-admin .subs-search input[type=search]{min-width:320px}
.subs-admin .subs-scheduler{margin:12px 0 18px;padding:10px 12px;border:1px solid #d8dee4;border-radius:4px;background:#fafbfc}
.subs-admin .subs-stale{color:#a40000;font-weight:bold}
.subs-admin dl.subs-facts{display:grid;grid-template-columns:max-content 1fr;gap:4px 16px;margin:0}
.subs-admin dl.subs-facts dt{font-weight:bold}
.subs-admin dl.subs-facts dd{margin:0}
.subs-admin .subs-actions form{display:inline-block;margin:0 12px 10px 0;vertical-align:top}
.subs-admin .subs-actions form.subs-block{display:block}
.subs-admin .subs-consent-text{white-space:pre-wrap;background:#f7f7f7;padding:8px;border:1px solid #e3e3e3;border-radius:3px;max-width:60em}
.subs-admin code{white-space:pre-wrap;word-break:break-all}
</style>
</head>
<body>
<?php require DIR_WS_INCLUDES . 'header.php'; ?>
<div class="container-fluid subs-admin">
<?php if ($subsSub === null) { ?>
  <h1><?= $subsH(SUBSCRIPTIONS_ADMIN_HEADING) ?></h1>

  <div class="subs-scheduler">
    <strong><?= $subsH(SUBSCRIPTIONS_ADMIN_SCHEDULER) ?>:</strong>
<?php if ($subsHealth[0] === '') { ?>
    <span class="subs-stale"><?= $subsH(SUBSCRIPTIONS_ADMIN_NEVER_RAN) ?></span>
<?php } else { ?>
    <?= $subsH(sprintf(SUBSCRIPTIONS_ADMIN_LAST_RAN, zen_datetime_short($subsHealth[0]))) ?>
<?php if (!$subsHealth[2]) { ?>
    <span class="subs-stale"><?= $subsH(sprintf(SUBSCRIPTIONS_ADMIN_STALE, $subsHealth[1])) ?></span>
<?php } ?>
<?php } ?>
<?php if ($subsCronKey !== '') { ?>
    <a class="btn btn-default btn-sm" target="_blank" rel="noopener" href="<?= $subsH(SubscriptionsCore::schedulerUrl($subsCronKey)) ?>"><?= $subsH(SUBSCRIPTIONS_ADMIN_BUTTON_RUN) ?></a>
    <span class="help-block"><?= $subsH(SUBSCRIPTIONS_ADMIN_RUN_HELP) ?></span>
    <details<?= $subsHealth[2] ? '' : ' open' ?>>
      <summary><?= $subsH(SUBSCRIPTIONS_ADMIN_SETUP) ?></summary>
      <p><?= $subsH(SUBSCRIPTIONS_ADMIN_SETUP_CRON) ?><br><code>curl -fsSL "<?= $subsH(SubscriptionsCore::schedulerUrl($subsCronKey)) ?>" &gt;/dev/null</code></p>
      <p><?= $subsH(SUBSCRIPTIONS_ADMIN_SETUP_URL) ?><br><code><?= $subsH(SubscriptionsCore::schedulerUrl($subsCronKey)) ?></code></p>
    </details>
<?php } ?>
  </div>

  <p class="subs-counts">
    <a href="<?= zen_href_link(FILENAME_SUBSCRIPTIONS, '', 'SSL') ?>"<?= $subsList['status'] === '' ? ' class="subs-current" aria-current="page"' : '' ?>><?= $subsH(SUBSCRIPTIONS_ADMIN_ALL) ?> (<?= array_sum($subsCounts) ?>)</a>
<?php foreach ($subsStatusLabels as $subsKey => $subsLabel) { ?>
    <a href="<?= zen_href_link(FILENAME_SUBSCRIPTIONS, 'status=' . $subsKey, 'SSL') ?>"<?= $subsList['status'] === $subsKey ? ' class="subs-current" aria-current="page"' : '' ?>><?= $subsH($subsLabel) ?> (<?= (int)$subsCounts[$subsKey] ?>)</a>
<?php } ?>
  </p>

  <form class="subs-search form-inline" method="get" action="<?= $subsSelf ?>" role="search">
    <input type="hidden" name="cmd" value="<?= $subsH(FILENAME_SUBSCRIPTIONS) ?>">
    <label for="subs-q"><?= $subsH(SUBSCRIPTIONS_ADMIN_SEARCH) ?></label>
    <input type="search" class="form-control" name="q" id="subs-q" value="<?= $subsH($subsList['query']) ?>" placeholder="<?= $subsH(SUBSCRIPTIONS_ADMIN_SEARCH_HINT) ?>">
    <label for="subs-status"><?= $subsH(SUBSCRIPTIONS_ADMIN_STATUS) ?></label>
    <select class="form-control" name="status" id="subs-status">
      <option value=""><?= $subsH(SUBSCRIPTIONS_ADMIN_ALL) ?></option>
<?php foreach ($subsStatusLabels as $subsKey => $subsLabel) { ?>
      <option value="<?= $subsH($subsKey) ?>"<?= $subsList['status'] === $subsKey ? ' selected' : '' ?>><?= $subsH($subsLabel) ?></option>
<?php } ?>
    </select>
    <button type="submit" class="btn btn-primary"><?= $subsH(SUBSCRIPTIONS_ADMIN_BUTTON_SEARCH) ?></button>
    <a class="btn btn-default" href="<?= $subsSelf ?>"><?= $subsH(SUBSCRIPTIONS_ADMIN_BUTTON_RESET) ?></a>
  </form>

<?php if ($subsList['rows'] === []) { ?>
  <p><?= $subsH(SUBSCRIPTIONS_ADMIN_NONE) ?></p>
<?php } else { ?>
  <table class="table table-striped table-condensed">
    <thead><tr>
      <th scope="col"><?= $subsH(SUBSCRIPTIONS_ADMIN_COL_ID) ?></th>
      <th scope="col"><?= $subsH(SUBSCRIPTIONS_ADMIN_COL_CUSTOMER) ?></th>
      <th scope="col"><?= $subsH(SUBSCRIPTIONS_ADMIN_COL_ITEMS) ?></th>
      <th scope="col"><?= $subsH(SUBSCRIPTIONS_ADMIN_COL_INTERVAL) ?></th>
      <th scope="col"><?= $subsH(SUBSCRIPTIONS_ADMIN_COL_NEXT) ?></th>
      <th scope="col"><?= $subsH(SUBSCRIPTIONS_ADMIN_COL_STATUS) ?></th>
      <th scope="col"><?= $subsH(SUBSCRIPTIONS_ADMIN_COL_DELIVERIES) ?></th>
      <th scope="col"><?= $subsH(SUBSCRIPTIONS_ADMIN_COL_STARTED) ?></th>
      <th scope="col"><span class="sr-only"><?= $subsH(SUBSCRIPTIONS_ADMIN_BUTTON_DETAILS) ?></span></th>
    </tr></thead>
    <tbody>
<?php foreach ($subsList['rows'] as $subsRow) {
    $subsRowId = (int)$subsRow['subscriptions_id'];
    $subsItems = [];
    foreach ($subsRow['items'] as $subsItem) {
        $subsItems[] = (int)(float)$subsItem['quantity'] . ' x ' . $subsItem['products_name'];
    }
    ?>
      <tr>
        <td>#<?= $subsRowId ?></td>
        <td><?php if ($subsRow['customer'] !== null) { ?><a href="<?= zen_href_link(FILENAME_CUSTOMERS, 'cID=' . (int)$subsRow['customers_id'] . '&action=edit', 'NONSSL') ?>"><?= $subsH($subsRow['customer']['name']) ?></a><br><small><?= $subsH($subsRow['customer']['email']) ?></small><?php } else {
            echo $subsH(SUBSCRIPTIONS_ADMIN_NO_CUSTOMER);
        } ?></td>
        <td><?= $subsH(implode(', ', $subsItems)) ?></td>
        <td><?= $subsH(SubscriptionsCore::intervalLabel((int)$subsRow['interval_count'], $subsRow['interval_unit'])) ?></td>
        <td><?= in_array($subsRow['status'], ['active', 'past_due'], true) ? $subsH($subsDate($subsRow['next_date'])) : '' ?></td>
        <td><?= $subsH($subsStatusLabels[$subsRow['status']] ?? $subsRow['status']) ?></td>
        <td><?= (int)$subsRow['max_cycles'] > 0 ? $subsH(sprintf(SUBSCRIPTIONS_ADMIN_DELIVERIES_OF, (int)$subsRow['cycles_completed'], (int)$subsRow['max_cycles'])) : (int)$subsRow['cycles_completed'] ?></td>
        <td><?= $subsH($subsDate($subsRow['date_added'])) ?></td>
        <td><a class="btn btn-default btn-xs" href="<?= zen_href_link(FILENAME_SUBSCRIPTIONS, 'sID=' . $subsRowId, 'SSL') ?>" aria-label="<?= $subsH(SUBSCRIPTIONS_ADMIN_BUTTON_DETAILS . ' #' . $subsRowId) ?>"><?= $subsH(SUBSCRIPTIONS_ADMIN_BUTTON_DETAILS) ?></a></td>
      </tr>
<?php } ?>
    </tbody>
  </table>
<?php
    $subsFirst = ($subsList['page'] - 1) * SubscriptionsAdmin::PER_PAGE + 1;
    $subsLast = min($subsList['total'], $subsList['page'] * SubscriptionsAdmin::PER_PAGE);
    $subsKeep = ($subsList['status'] !== '' ? '&status=' . $subsList['status'] : '') . ($subsList['query'] !== '' ? '&q=' . urlencode($subsList['query']) : '');
    ?>
  <nav aria-label="<?= $subsH(SUBSCRIPTIONS_ADMIN_HEADING) ?>">
    <?= $subsH(sprintf(SUBSCRIPTIONS_ADMIN_SHOWING, $subsFirst, $subsLast, $subsList['total'])) ?>
<?php if ($subsList['page'] > 1) { ?>
    <a href="<?= zen_href_link(FILENAME_SUBSCRIPTIONS, 'page=' . ($subsList['page'] - 1) . $subsKeep, 'SSL') ?>"><?= $subsH(SUBSCRIPTIONS_ADMIN_PREVIOUS) ?></a>
<?php } ?>
<?php if ($subsList['page'] < $subsList['pages']) { ?>
    <a href="<?= zen_href_link(FILENAME_SUBSCRIPTIONS, 'page=' . ($subsList['page'] + 1) . $subsKeep, 'SSL') ?>"><?= $subsH(SUBSCRIPTIONS_ADMIN_NEXT) ?></a>
<?php } ?>
  </nav>
<?php } ?>

<?php } else {
    $subsId = (int)$subsSub['subscriptions_id'];
    $subsStatus = (string)$subsSub['status'];
    $subsLive = in_array($subsStatus, SubscriptionsManager::LIVE, true);
    $subsRenewals = new SubscriptionsRenewals($db);
    $subsOrderLink = static function (int $ordersId) use ($subsH): string {
        return $ordersId > 0 ? '<a href="' . zen_href_link(FILENAME_ORDERS, 'oID=' . $ordersId . '&action=edit', 'NONSSL') . '">#' . $ordersId . '</a>' : '';
    };
    ?>
  <p><a href="<?= $subsSelf ?>">&laquo; <?= $subsH(SUBSCRIPTIONS_ADMIN_BACK) ?></a></p>
  <h1><?= $subsH(sprintf(SUBSCRIPTIONS_ADMIN_DETAIL_HEADING, $subsId)) ?></h1>

  <dl class="subs-facts">
    <dt><?= $subsH(SUBSCRIPTIONS_ADMIN_LBL_CUSTOMER) ?></dt>
    <dd><?php if ($subsSub['customer'] !== null) { ?><a href="<?= zen_href_link(FILENAME_CUSTOMERS, 'cID=' . (int)$subsSub['customers_id'] . '&action=edit', 'NONSSL') ?>"><?= $subsH($subsSub['customer']['name']) ?></a> &lt;<?= $subsH($subsSub['customer']['email']) ?>&gt;<?php } else {
        echo $subsH(SUBSCRIPTIONS_ADMIN_NO_CUSTOMER);
    } ?></dd>
    <dt><?= $subsH(SUBSCRIPTIONS_ADMIN_LBL_STATUS) ?></dt>
    <dd><?= $subsH($subsStatusLabels[$subsStatus] ?? $subsStatus) ?></dd>
    <dt><?= $subsH(SUBSCRIPTIONS_ADMIN_LBL_INTERVAL) ?></dt>
    <dd><?= $subsH(SubscriptionsCore::intervalLabel((int)$subsSub['interval_count'], $subsSub['interval_unit'])) ?></dd>
<?php if ($subsLive) { ?>
    <dt><?= $subsH(SUBSCRIPTIONS_ADMIN_LBL_NEXT) ?></dt>
    <dd><?= $subsH($subsDate($subsSub['next_date'])) ?></dd>
<?php } ?>
    <dt><?= $subsH(SUBSCRIPTIONS_ADMIN_LBL_DELIVERIES) ?></dt>
    <dd><?= (int)$subsSub['max_cycles'] > 0 ? $subsH(sprintf(SUBSCRIPTIONS_ADMIN_DELIVERIES_OF, (int)$subsSub['cycles_completed'], (int)$subsSub['max_cycles'])) : (int)$subsSub['cycles_completed'] ?></dd>
    <dt><?= $subsH(SUBSCRIPTIONS_ADMIN_LBL_STARTED) ?></dt>
    <dd><?= $subsH($subsDate($subsSub['date_added'])) ?></dd>
    <dt><?= $subsH(SUBSCRIPTIONS_ADMIN_LBL_FIRST_ORDER) ?></dt>
    <dd><?= $subsOrderLink((int)$subsSub['origin_orders_id']) ?></dd>
    <dt><?= $subsH(SUBSCRIPTIONS_ADMIN_LBL_LAST_ORDER) ?></dt>
    <dd><?= $subsOrderLink((int)$subsSub['last_orders_id']) ?></dd>
    <dt><?= $subsH(SUBSCRIPTIONS_ADMIN_LBL_PAYMENT) ?></dt>
    <dd><?= $subsH($subsSub['payment_mode'] === 'paylink' ? SUBSCRIPTIONS_ADMIN_PAYMENT_PAYLINK : sprintf(SUBSCRIPTIONS_ADMIN_PAYMENT_AUTO, $subsSub['gateway'])) ?></dd>
    <dt><?= $subsH(SUBSCRIPTIONS_ADMIN_LBL_SHIPPING) ?></dt>
    <dd><?= $subsH(trim($subsSub['shipping_method'] . ' ' . (isset($currencies) && is_object($currencies) ? $currencies->format((float)$subsSub['shipping_cost'], true, (string)$subsSub['currency']) : number_format((float)$subsSub['shipping_cost'], 2)))) ?></dd>
<?php if ($subsStatus === 'active' && $subsSub['payment_mode'] === 'paylink') { ?>
    <dt><?= $subsH(SUBSCRIPTIONS_ADMIN_LBL_REMINDER) ?></dt>
    <dd><?= $subsH($subsSub['reminder_sent_for'] === $subsSub['next_date']
        ? sprintf(SUBSCRIPTIONS_ADMIN_REMINDER_SENT, $subsDate(substr((string)$subsSub['paylink_expires'], 0, 10)))
        : sprintf(SUBSCRIPTIONS_ADMIN_REMINDER_DUE, $subsDate($subsRenewals->remindOn($subsSub)))) ?></dd>
    <dt><?= $subsH(SUBSCRIPTIONS_ADMIN_LBL_MISSED) ?></dt>
    <dd><?= (int)$subsSub['failure_count'] ?> / <?= SubscriptionsRenewals::MISSED_LIMIT ?></dd>
<?php } ?>
<?php if ($subsStatus === 'canceled') { ?>
    <dt><?= $subsH(SUBSCRIPTIONS_ADMIN_LBL_CANCELED) ?></dt>
    <dd><?= $subsH($subsSub['cancel_reason'] !== '' ? sprintf(SUBSCRIPTIONS_ADMIN_CANCELED_REASON, $subsDate($subsSub['date_canceled']), $subsSub['cancel_reason']) : $subsDate($subsSub['date_canceled'])) ?></dd>
<?php } ?>
  </dl>

  <h2><?= $subsH(SUBSCRIPTIONS_ADMIN_ITEMS) ?></h2>
  <table class="table table-condensed">
    <thead><tr>
      <th scope="col"><?= $subsH(SUBSCRIPTIONS_ADMIN_COL_PRODUCT) ?></th>
      <th scope="col"><?= $subsH(SUBSCRIPTIONS_ADMIN_COL_QTY) ?></th>
      <th scope="col"><?= $subsH(SUBSCRIPTIONS_ADMIN_COL_LAST_PRICE) ?></th>
    </tr></thead>
    <tbody>
<?php foreach ($subsSub['items'] as $subsItem) { ?>
      <tr>
        <td><?= $subsH($subsItem['products_name']) ?> <small>(<?= (int)$subsItem['products_id'] ?>)</small></td>
        <td><?= (int)(float)$subsItem['quantity'] ?></td>
        <td><?= $subsH(isset($currencies) && is_object($currencies) ? $currencies->format((float)$subsItem['final_price'], true, (string)$subsSub['currency']) : number_format((float)$subsItem['final_price'], 2)) ?></td>
      </tr>
<?php } ?>
    </tbody>
  </table>

  <h2><?= $subsH(SUBSCRIPTIONS_ADMIN_ACTIONS) ?></h2>
<?php if (!$subsLive) { ?>
  <p><?= $subsH(SUBSCRIPTIONS_ADMIN_NO_ACTIONS) ?></p>
<?php } else {
    $subsFormStart = static function (string $action, string $class = '', string $extra = '') use ($subsH, $subsSelf, $subsToken, $subsId): string {
        return '<form method="post" action="' . $subsSelf . '"' . ($class !== '' ? ' class="' . $class . '"' : '') . $extra . '>'
            . '<input type="hidden" name="securityToken" value="' . $subsToken . '">'
            . '<input type="hidden" name="action" value="' . $subsH($action) . '">'
            . '<input type="hidden" name="sID" value="' . $subsId . '">';
    };
    ?>
  <div class="subs-actions">
<?php if ($subsStatus === 'active') { ?>
    <?= $subsFormStart('skip') ?><button type="submit" class="btn btn-default"><?= $subsH(SUBSCRIPTIONS_ADMIN_BUTTON_SKIP) ?></button></form>
    <?= $subsFormStart('pause') ?><button type="submit" class="btn btn-default"><?= $subsH(SUBSCRIPTIONS_ADMIN_BUTTON_PAUSE) ?></button>
      <label class="checkbox-inline"><input type="checkbox" name="notify" value="1" checked> <?= $subsH(SUBSCRIPTIONS_ADMIN_LBL_NOTIFY) ?></label></form>
<?php } elseif ($subsStatus === 'paused') { ?>
    <?= $subsFormStart('resume') ?><button type="submit" class="btn btn-default"><?= $subsH(SUBSCRIPTIONS_ADMIN_BUTTON_RESUME) ?></button></form>
<?php } ?>
<?php if (in_array($subsStatus, ['active', 'paused'], true)) { ?>
    <?= $subsFormStart('next_date', 'subs-block form-inline') ?>
      <label for="subs-next-date"><?= $subsH(SUBSCRIPTIONS_ADMIN_LBL_NEW_DATE) ?></label>
      <input type="date" class="form-control" name="next_date" id="subs-next-date" value="<?= $subsH($subsSub['next_date']) ?>" min="<?= date('Y-m-d') ?>" required>
      <button type="submit" class="btn btn-default"><?= $subsH(SUBSCRIPTIONS_ADMIN_BUTTON_SET_DATE) ?></button>
    </form>
<?php } ?>
    <?= $subsFormStart('cancel', 'subs-block', ' onsubmit="return confirm(' . $subsH(json_encode(sprintf(SUBSCRIPTIONS_ADMIN_CANCEL_SURE, $subsId))) . ')"') ?>
      <label for="subs-reason"><?= $subsH(SUBSCRIPTIONS_ADMIN_LBL_REASON) ?></label><br>
      <textarea class="form-control" name="reason" id="subs-reason" rows="2" maxlength="255" style="max-width:40em"></textarea>
      <label class="checkbox-inline"><input type="checkbox" name="notify" value="1" checked> <?= $subsH(SUBSCRIPTIONS_ADMIN_LBL_NOTIFY) ?></label><br>
      <button type="submit" class="btn btn-danger"><?= $subsH(SUBSCRIPTIONS_ADMIN_BUTTON_CANCEL) ?></button>
    </form>
  </div>
<?php } ?>

  <h2><?= $subsH(SUBSCRIPTIONS_ADMIN_CONSENT) ?></h2>
<?php if (strpos((string)$subsSub['consent_at'], '0001-01-01') === 0 || $subsSub['consent_text'] === null) { ?>
  <p><?= $subsH(SUBSCRIPTIONS_ADMIN_CONSENT_NONE) ?></p>
<?php } else { ?>
  <p><?= $subsH(sprintf(SUBSCRIPTIONS_ADMIN_CONSENT_AT, zen_datetime_short($subsSub['consent_at']), $subsSub['consent_ip'])) ?></p>
  <div class="subs-consent-text"><?= $subsH($subsSub['consent_text']) ?></div>
<?php } ?>

  <h2><?= $subsH(SUBSCRIPTIONS_ADMIN_HISTORY) ?></h2>
  <table class="table table-striped table-condensed">
    <thead><tr>
      <th scope="col"><?= $subsH(SUBSCRIPTIONS_ADMIN_COL_DATE) ?></th>
      <th scope="col"><?= $subsH(SUBSCRIPTIONS_ADMIN_COL_EVENT) ?></th>
      <th scope="col"><?= $subsH(SUBSCRIPTIONS_ADMIN_COL_ORDER) ?></th>
      <th scope="col"><?= $subsH(SUBSCRIPTIONS_ADMIN_COL_AMOUNT) ?></th>
      <th scope="col"><?= $subsH(SUBSCRIPTIONS_ADMIN_COL_DETAIL) ?></th>
      <th scope="col"><?= $subsH(SUBSCRIPTIONS_ADMIN_COL_BY) ?></th>
    </tr></thead>
    <tbody>
<?php foreach ($subsSub['history'] as $subsEvent) {
    $subsEventKey = 'SUBSCRIPTIONS_ADMIN_EVENT_' . strtoupper(preg_replace('/[^a-z_]/', '', (string)$subsEvent['event']));
    ?>
      <tr>
        <td><?= $subsH(zen_datetime_short($subsEvent['date_added'])) ?></td>
        <td><?= $subsH(defined($subsEventKey) ? constant($subsEventKey) : $subsEvent['event']) ?></td>
        <td><?= $subsOrderLink((int)$subsEvent['orders_id']) ?></td>
        <td><?= (float)$subsEvent['amount'] > 0 ? $subsH(isset($currencies) && is_object($currencies) ? $currencies->format((float)$subsEvent['amount'], true, (string)$subsSub['currency']) : number_format((float)$subsEvent['amount'], 2)) : '' ?></td>
        <td><?= $subsH($subsEvent['detail']) ?></td>
        <td><?= $subsH($subsEvent['actor']) ?></td>
      </tr>
<?php } ?>
    </tbody>
  </table>
<?php } ?>
</div>
<?php require DIR_WS_INCLUDES . 'footer.php'; ?>
</body>
</html>
<?php
    require DIR_WS_INCLUDES . 'application_bottom.php';
