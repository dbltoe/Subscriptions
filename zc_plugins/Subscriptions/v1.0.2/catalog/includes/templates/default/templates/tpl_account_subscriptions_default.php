<?php
/**
 * Subscriptions -- My Subscriptions page template.
 *
 * Plain template_default markup, and buttons made by core's zen_image_submit()
 * so they are the template's own buttons (stock templates style
 * input.cssButton, not <button>; every label is under core's 30-character
 * limit for a CSS button). Copy this file to your template's templates/ folder
 * to change it; that copy wins over this one.
 *
 * @package  Subscriptions
 * @license  http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

$subsH = static function ($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, CHARSET);
};
$subsDate = static function ($ymd) {
    return function_exists('zen_date_long') ? zen_date_long($ymd) : $ymd;
};
$subsStatus = [
    'active' => SUBSCRIPTIONS_STATUS_ACTIVE,
    'paused' => SUBSCRIPTIONS_STATUS_PAUSED,
    'past_due' => SUBSCRIPTIONS_STATUS_PAST_DUE,
    'canceled' => SUBSCRIPTIONS_STATUS_CANCELED,
    'expired' => SUBSCRIPTIONS_STATUS_EXPIRED,
];
$subsUrl = zen_href_link(FILENAME_ACCOUNT_SUBSCRIPTIONS, '', 'SSL');
$subsForm = static function (string $name, string $action, int $id, string $class = '') use ($subsUrl) {
    return zen_draw_form($name, $subsUrl, 'post', $class !== '' ? 'class="' . $class . '"' : '')
        . zen_draw_hidden_field('action', $action) . zen_draw_hidden_field('subscription', (string)$id);
};
$subsButton = static function (string $label) {
    return zen_image_submit(BUTTON_IMAGE_SUBMIT, $label);
};
// The catalog's current name for each product, falling back to the one saved at signup.
$subsName = static function (array $item) {
    $name = function_exists('zen_get_products_name') ? trim((string)zen_get_products_name((int)$item['products_id'])) : '';
    return $name !== '' ? $name : (string)$item['products_name'];
};
?>
<style>
#accountSubscriptions .subs-card{margin:0 0 2em}
#accountSubscriptions .subs-actions form{display:inline-block;margin:0 .5em .75em 0;vertical-align:middle}
#accountSubscriptions .subs-actions form.subs-block{display:block}
#accountSubscriptions .subs-actions label{margin-right:.5em}
#accountSubscriptions .subs-actions fieldset{margin:0 0 .5em}
</style>
<div class="centerColumn" id="accountSubscriptions">
<h1 id="accountSubscriptionsHeading"><?= HEADING_TITLE ?></h1>

<?php if ($messageStack->size('account_subscriptions') > 0) {
    echo $messageStack->output('account_subscriptions');
} ?>

<?php if ($subscriptions === []) { ?>
<p><?= $subsH(SUBSCRIPTIONS_PAGE_NONE) ?></p>
<?php } ?>

<?php
$subsPastShown = false;
foreach ($subscriptions as $s) {
    $id = (int)$s['subscriptions_id'];
    $label = SubscriptionsCore::intervalLabel((int)$s['interval_count'], $s['interval_unit']);
    if (!$s['live'] && !$subsPastShown) {
        $subsPastShown = true;
        echo '<h2 class="subs-past-heading">' . $subsH(SUBSCRIPTIONS_PAGE_PAST) . '</h2>';
    }
    ?>
<section class="subs-card" id="subscription-<?= $id ?>" aria-labelledby="subscription-<?= $id ?>-heading">
  <h2 id="subscription-<?= $id ?>-heading"><?= $subsH(sprintf(SUBSCRIPTIONS_PAGE_CARD_HEADING, $id, $label)) ?>
    <span class="subs-status subs-status-<?= $subsH($s['status']) ?>">(<?= $subsH($subsStatus[$s['status']] ?? $s['status']) ?>)</span></h2>

  <ul class="subs-items">
<?php foreach ($s['items'] as $item) { ?>
    <li><?= (int)(float)$item['quantity'] ?> x <a href="<?= zen_href_link(zen_get_info_page((int)$item['products_id']), 'products_id=' . (int)$item['products_id']) ?>"><?= $subsH($subsName($item)) ?></a></li>
<?php } ?>
  </ul>

  <p class="subs-dates">
<?php if ($s['status'] === 'active' || $s['status'] === 'past_due') { ?>
    <?= $subsH(SUBSCRIPTIONS_PAGE_NEXT) ?> <strong><?= $subsH($subsDate($s['next_date'])) ?></strong><br>
<?php } elseif ($s['status'] === 'paused') { ?>
    <?= $subsH(SUBSCRIPTIONS_PAGE_PAUSED_NOTE) ?><br>
<?php } elseif ($s['status'] === 'canceled') { ?>
    <?= $subsH(sprintf(SUBSCRIPTIONS_PAGE_CANCELED_ON, $subsDate(substr($s['date_canceled'], 0, 10)))) ?><br>
<?php } ?>
    <?= $subsH((int)$s['max_cycles'] > 0
            ? sprintf(SUBSCRIPTIONS_PAGE_DELIVERIES_OF, (int)$s['cycles_completed'], (int)$s['max_cycles'])
            : sprintf(SUBSCRIPTIONS_PAGE_DELIVERIES, (int)$s['cycles_completed'])) ?><br>
    <?= $subsH(sprintf(SUBSCRIPTIONS_PAGE_STARTED, $subsDate(substr($s['date_added'], 0, 10)))) ?>
  </p>

<?php if ($subsConfirmCancel === $id && $s['live']) { ?>
  <div class="subs-confirm" role="alert">
    <?= $subsForm('subs_cancel_' . $id, 'cancel', $id) ?>
      <p><strong><?= $subsH(SUBSCRIPTIONS_PAGE_CANCEL_SURE) ?></strong></p>
      <p><label for="subs-reason-<?= $id ?>"><?= $subsH(SUBSCRIPTIONS_PAGE_CANCEL_REASON) ?></label><br>
        <textarea name="reason" id="subs-reason-<?= $id ?>" rows="2" cols="40" maxlength="255"></textarea></p>
      <?= $subsButton(SUBSCRIPTIONS_BUTTON_CANCEL_YES) ?>
      <?php /* No #fragment: the confirmation came from a POST to this same URL, and a link that differs only by fragment is an in-page jump, so it would never close. */ ?>
      <a href="<?= $subsUrl ?>"><?= $subsH(SUBSCRIPTIONS_BUTTON_CANCEL_KEEP) ?></a>
    </form>
  </div>
<?php } elseif ($s['live']) { ?>
  <div class="subs-actions">
<?php if ($subsRenewals->canRenew($s)) { ?>
    <?= $subsForm('subs_renew_' . $id, 'renew', $id, 'subs-block subs-renew') ?>
      <p><?= $subsH(sprintf(SUBSCRIPTIONS_PAGE_RENEW_READY, $subsDate($s['next_date']))) ?></p>
      <?= $subsButton(SUBSCRIPTIONS_BUTTON_RENEW) ?>
    </form>
<?php } ?>
<?php if ($s['status'] === 'active') { ?>
    <?= $subsForm('subs_skip_' . $id, 'skip', $id) . $subsButton(SUBSCRIPTIONS_BUTTON_SKIP) ?></form>
    <?= $subsForm('subs_pause_' . $id, 'pause', $id) . $subsButton(SUBSCRIPTIONS_BUTTON_PAUSE) ?></form>
<?php } elseif ($s['status'] === 'paused') { ?>
    <?= $subsForm('subs_resume_' . $id, 'resume', $id) . $subsButton(SUBSCRIPTIONS_BUTTON_RESUME) ?></form>
<?php } ?>
<?php if (in_array($s['status'], ['active', 'paused'], true) && count($s['intervals']) > 1) { ?>
    <?= $subsForm('subs_interval_' . $id, 'interval', $id, 'subs-block') ?>
      <label for="subs-interval-<?= $id ?>"><?= $subsH(SUBSCRIPTIONS_PAGE_INTERVAL_LABEL) ?></label>
      <select name="interval" id="subs-interval-<?= $id ?>">
<?php foreach ($s['intervals'] as $key => $iv) {
    $current = $iv['count'] === (int)$s['interval_count'] && $iv['unit'] === $s['interval_unit'];
    ?>
        <option value="<?= $subsH($key) ?>"<?= $current ? ' selected' : '' ?>><?= $subsH(SubscriptionsCore::intervalLabel($iv['count'], $iv['unit'])) ?></option>
<?php } ?>
      </select>
      <?= $subsButton(SUBSCRIPTIONS_BUTTON_INTERVAL) ?>
    </form>
<?php } ?>
<?php if (in_array($s['status'], ['active', 'paused'], true)) { ?>
    <?= $subsForm('subs_qty_' . $id, 'quantities', $id, 'subs-block') ?>
      <fieldset><legend><?= $subsH(SUBSCRIPTIONS_PAGE_QUANTITIES) ?></legend>
<?php foreach ($s['items'] as $item) {
    $qid = (int)$item['subscriptions_products_id'];
    ?>
        <label for="subs-qty-<?= $qid ?>"><?= $subsH($subsName($item)) ?></label>
        <input type="number" name="qty[<?= $qid ?>]" id="subs-qty-<?= $qid ?>" value="<?= (int)(float)$item['quantity'] ?>" min="1" max="<?= SubscriptionsManager::MAX_QTY ?>" step="1" size="4"><br>
<?php } ?>
      </fieldset>
      <?= $subsButton(SUBSCRIPTIONS_BUTTON_QUANTITIES) ?>
    </form>
<?php } ?>
    <?= $subsForm('subs_cancel_ask_' . $id, 'cancel_ask', $id) . $subsButton(SUBSCRIPTIONS_BUTTON_CANCEL) ?></form>
  </div>
<?php } ?>
</section>
<?php } ?>

<div class="buttonRow back"><?= '<a href="' . zen_href_link(FILENAME_ACCOUNT, '', 'SSL') . '">' . zen_image_button(BUTTON_IMAGE_BACK, BUTTON_BACK_ALT) . '</a>' ?></div>
</div>
