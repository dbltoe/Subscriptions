<?php
/**
 * Subscriptions -- admin observer.
 *
 * Zen Cart finds this file itself (init_observers.php scans every installed
 * plugin's classes/observers/ for auto.*.php) and constructs the class whose
 * name camelize() derives from the file name: auto.subscriptions_admin.php ->
 * zcObserverSubscriptionsAdmin. It must NOT also be listed in an auto_loader;
 * a second include fatals every admin page with "Cannot redeclare class".
 *
 * What it does:
 *   - adds the Subscription panel to the product edit page
 *     (NOTIFY_ADMIN_PRODUCT_COLLECT_INFO_EXTRA_INPUTS, every release),
 *   - saves it (NOTIFY_MODULES_UPDATE_PRODUCT_END, every release),
 *   - forgets a deleted product's settings (NOTIFIER_ADMIN_ZEN_REMOVE_PRODUCT),
 *   - keeps every product's Delivery choice in line with the master switch.
 *
 * Every panel field is a scalar on purpose: the product preview page re-posts
 * only scalar $_POST values (preview_info.php skips arrays on 1.5.8 and 3.0.0
 * alike), so an array of ticked intervals would be lost on the way to the save.
 *
 * @package  Subscriptions
 * @license  http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

if (!class_exists('SubscriptionsAttributes', false)) {
    require_once dirname(__DIR__, 4) . '/shared/SubscriptionsAttributes.php';
}

class zcObserverSubscriptionsAdmin extends base
{
    /** @var SubscriptionsAttributes|null */
    protected $attributes;

    public function __construct()
    {
        if (!defined('SUBSCRIPTIONS_STATUS')) {
            return;
        }
        $this->attach($this, [
            'NOTIFY_ADMIN_PRODUCT_COLLECT_INFO_EXTRA_INPUTS',
            'NOTIFY_MODULES_UPDATE_PRODUCT_END',
            'NOTIFIER_ADMIN_ZEN_REMOVE_PRODUCT',
        ]);
        $this->attributes()->reconcile(SUBSCRIPTIONS_STATUS === 'true');
    }

    public function update(&$class, $eventID, $param1 = null, &$param2 = null, &$param3 = null)
    {
        switch ($eventID) {
            case 'NOTIFY_ADMIN_PRODUCT_COLLECT_INFO_EXTRA_INPUTS':
                if (is_array($param2)) {
                    $param2 = array_merge($param2, $this->panel(is_object($param1) ? (int)($param1->products_id ?? 0) : 0));
                }
                break;

            case 'NOTIFY_MODULES_UPDATE_PRODUCT_END':
                $this->save(is_array($param1) ? (int)($param1['products_id'] ?? 0) : 0);
                break;

            case 'NOTIFIER_ADMIN_ZEN_REMOVE_PRODUCT':
                $this->attributes()->forgetProduct((int)$param2);
                break;
        }
    }

    protected function attributes(): SubscriptionsAttributes
    {
        if ($this->attributes === null) {
            $ids = [];
            if (function_exists('zen_get_languages')) {
                foreach (zen_get_languages() as $lang) {
                    $ids[] = (int)$lang['id'];
                }
            }
            $this->attributes = new SubscriptionsAttributes($GLOBALS['db'], $ids);
        }
        return $this->attributes;
    }

    /** The intervals the owner offers store-wide. */
    protected function allowedIntervals(): array
    {
        return SubscriptionsCore::parseIntervals(defined('SUBSCRIPTIONS_INTERVALS') ? SUBSCRIPTIONS_INTERVALS : '');
    }

    /**
     * The panel, as entries for core's extra-inputs block (it draws the
     * form-group, label and column wrappers itself).
     */
    protected function panel(int $productsId): array
    {
        $s = $this->attributes()->productSettings($productsId);
        if ($s['mode'] === 'off' && $s['intervals'] === []) {
            $s['discount'] = SubscriptionsCore::cleanDiscount(defined('SUBSCRIPTIONS_DEFAULT_DISCOUNT') ? SUBSCRIPTIONS_DEFAULT_DISCOUNT : 0);
        }
        $h = static function ($v) {
            return htmlspecialchars((string)$v, ENT_QUOTES, defined('CHARSET') ? CHARSET : 'UTF-8');
        };

        $modes = [
            'off' => SUBSCRIPTIONS_ADMIN_MODE_OFF,
            'optional' => SUBSCRIPTIONS_ADMIN_MODE_OPTIONAL,
            'required' => SUBSCRIPTIONS_ADMIN_MODE_REQUIRED,
        ];
        $select = '<input type="hidden" name="subs_panel" value="1">'
            . '<select name="subs_mode" id="subs_mode" class="form-control">';
        foreach ($modes as $value => $label) {
            $select .= '<option value="' . $value . '"' . ($s['mode'] === $value ? ' selected' : '') . '>' . $h($label) . '</option>';
        }
        $select .= '</select>';

        $boxes = '';
        $allowed = $this->allowedIntervals();
        foreach ($allowed as $key => $iv) {
            $boxes .= '<label class="checkbox-inline" style="margin-right:12px">'
                . '<input type="checkbox" name="subs_iv_' . $key . '" value="1"' . (in_array($key, $s['intervals'], true) ? ' checked' : '') . '> '
                . $h(SubscriptionsCore::intervalLabel($iv['count'], $iv['unit'])) . '</label>';
        }
        if ($allowed === []) {
            $boxes = '<p class="help-block">' . $h(SUBSCRIPTIONS_ADMIN_NO_INTERVALS) . '</p>';
        }
        // Intervals saved on the product but since removed from the store-wide list.
        $orphans = array_diff($s['intervals'], array_keys($allowed));
        if ($orphans !== []) {
            $boxes .= '<p class="help-block">' . $h(SUBSCRIPTIONS_ADMIN_ORPHANS) . '</p>';
        }

        $discount = '<div class="input-group" style="max-width:12em">'
            . '<input type="number" name="subs_discount" id="subs_discount" class="form-control" min="0" max="' . SubscriptionsCore::MAX_DISCOUNT . '" step="0.01" value="' . $h($s['discount']) . '">'
            . '<span class="input-group-addon">%</span></div>'
            . '<p class="help-block">' . $h(SUBSCRIPTIONS_ADMIN_DISCOUNT_HELP) . '</p>';

        $cycles = '<input type="number" name="subs_max_cycles" id="subs_max_cycles" class="form-control" style="max-width:12em" min="0" max="999" step="1" value="' . (int)$s['max_cycles'] . '">'
            . '<p class="help-block">' . $h(SUBSCRIPTIONS_ADMIN_CYCLES_HELP) . '</p>';

        return [
            ['label' => ['text' => SUBSCRIPTIONS_ADMIN_MODE, 'field_name' => 'subs_mode'], 'input' => $select],
            ['label' => ['text' => SUBSCRIPTIONS_ADMIN_INTERVALS, 'field_name' => 'subs_intervals'], 'input' => '<div id="subs_intervals">' . $boxes . '</div>'],
            ['label' => ['text' => SUBSCRIPTIONS_ADMIN_DISCOUNT, 'field_name' => 'subs_discount'], 'input' => $discount],
            ['label' => ['text' => SUBSCRIPTIONS_ADMIN_CYCLES, 'field_name' => 'subs_max_cycles'], 'input' => $cycles],
        ];
    }

    /**
     * Save the panel. Only when the panel was on the form: the marker field
     * tells "every box unticked" apart from "this save never showed the panel"
     * (a product type that doesn't fire the extra-inputs notifier, or a save
     * from somewhere else).
     */
    protected function save(int $productsId): void
    {
        if ($productsId <= 0 || !isset($_POST['subs_panel'])) {
            return;
        }
        $allowed = $this->allowedIntervals();
        $keys = [];
        foreach (array_keys($allowed) as $key) {
            if (!empty($_POST['subs_iv_' . $key])) {
                $keys[] = $key;
            }
        }
        $this->attributes()->saveProduct(
            $productsId,
            (string)($_POST['subs_mode'] ?? 'off'),
            $keys,
            $_POST['subs_discount'] ?? 0,
            (int)($_POST['subs_max_cycles'] ?? 0),
            $allowed,
            SUBSCRIPTIONS_STATUS === 'true'
        );
    }
}
