<?php
/**
 * Subscriptions -- the "Delivery" product option and its values.
 *
 * The storefront choice between a one-time purchase and a subscription is an
 * ordinary product attribute that this class owns: one option, one value per
 * interval, and a products_attributes row per product and offered interval.
 * Every template draws attributes already, so the storefront needs no template
 * edits, and the choice is recorded in orders_products_attributes on every
 * Zen Cart version.
 *
 * The owner's per-product settings live in products_subscription and are the
 * source of truth; the attribute rows are derived from them and can be
 * stripped and rebuilt at any time (reconcile()).
 *
 * Every method takes the queryFactory explicitly so the harness can hand it a
 * fake.
 *
 * @package  Subscriptions
 * @license  http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

require_once __DIR__ . '/SubscriptionsCore.php';

class SubscriptionsAttributes
{
    /** Option type Select. PRODUCTS_OPTIONS_TYPE_SELECT is 0 on every release. */
    public const OPTION_TYPE_SELECT = 0;

    /** The mapping row that marks a value as the one-time purchase. */
    public const ONE_TIME_UNIT = 'once';

    /** @var object queryFactory */
    protected $db;

    /** @var int[] */
    protected $languageIds;

    public function __construct($db, array $languageIds)
    {
        SubscriptionsCore::defineTables();
        $this->db = $db;
        $this->languageIds = array_values(array_unique(array_map('intval', $languageIds)));
        if ($this->languageIds === []) {
            $this->languageIds = [1];
        }
    }

    /* ----------------------------------------------------------------- *
     * Settings kept in the configuration table
     * ----------------------------------------------------------------- */

    /** Read a configuration value fresh from the database (constants may be stale in the installer). */
    public function config(string $key, string $default = ''): string
    {
        $r = $this->db->Execute(
            "SELECT configuration_value FROM " . TABLE_CONFIGURATION
            . " WHERE configuration_key = '" . $this->db->prepare_input($key) . "' LIMIT 1"
        );
        return $r->EOF ? $default : (string)$r->fields['configuration_value'];
    }

    public function setConfig(string $key, string $value): void
    {
        $this->db->Execute(
            "UPDATE " . TABLE_CONFIGURATION
            . " SET configuration_value = '" . $this->db->prepare_input($value) . "', last_modified = now()"
            . " WHERE configuration_key = '" . $this->db->prepare_input($key) . "' LIMIT 1"
        );
    }

    /* ----------------------------------------------------------------- *
     * The option and its values
     * ----------------------------------------------------------------- */

    /**
     * The id of the plugin's option, creating it if it is missing.
     *
     * products_options has a composite key (id, language) and no
     * auto-increment, so a new id is MAX + 1, the way the Option Name Manager
     * does it. The id is remembered in SUBSCRIPTIONS_OPTION_ID; the name is
     * only used when creating, so an owner who renames the option in the Option
     * Name Manager keeps the new name.
     */
    public function ensureOption(string $name): int
    {
        $id = (int)$this->config('SUBSCRIPTIONS_OPTION_ID', '0');
        if ($id > 0) {
            $r = $this->db->Execute("SELECT products_options_id FROM " . TABLE_PRODUCTS_OPTIONS . " WHERE products_options_id = " . $id . " LIMIT 1");
            if (!$r->EOF) {
                return $id;
            }
        }

        $r = $this->db->Execute("SELECT MAX(products_options_id) AS max_id FROM " . TABLE_PRODUCTS_OPTIONS);
        $id = (int)($r->fields['max_id'] ?? 0) + 1;
        foreach ($this->languageIds as $lang) {
            $this->db->Execute(
                "INSERT INTO " . TABLE_PRODUCTS_OPTIONS
                . " (products_options_id, language_id, products_options_name, products_options_sort_order, products_options_type)"
                . " VALUES (" . $id . ", " . $lang . ", '" . $this->db->prepare_input(substr($name, 0, 32)) . "', 0, " . self::OPTION_TYPE_SELECT . ")"
            );
        }
        $this->setConfig('SUBSCRIPTIONS_OPTION_ID', (string)$id);
        return $id;
    }

    /**
     * The option value for an interval (count 0 = the one-time purchase),
     * creating it, its link to the option and its mapping row if needed.
     */
    public function ensureValue(int $optionId, int $count, string $unit): int
    {
        if ($count === 0) {
            $unit = self::ONE_TIME_UNIT;
        }
        $r = $this->db->Execute(
            "SELECT si.options_values_id FROM " . TABLE_SUBSCRIPTIONS_INTERVALS . " si"
            . " INNER JOIN " . TABLE_PRODUCTS_OPTIONS_VALUES . " pov ON pov.products_options_values_id = si.options_values_id"
            . " WHERE si.interval_unit = '" . $this->db->prepare_input($unit) . "' AND si.interval_count = " . $count
            . " LIMIT 1"
        );
        if (!$r->EOF) {
            return (int)$r->fields['options_values_id'];
        }

        $label = $count === 0 ? SubscriptionsCore::oneTimeLabel() : SubscriptionsCore::intervalLabel($count, $unit);
        $sort = $count === 0 ? 0 : SubscriptionsCore::approxDays($count, $unit);

        $r = $this->db->Execute("SELECT MAX(products_options_values_id) AS max_id FROM " . TABLE_PRODUCTS_OPTIONS_VALUES);
        $valueId = (int)($r->fields['max_id'] ?? 0) + 1;
        foreach ($this->languageIds as $lang) {
            $this->db->Execute(
                "INSERT INTO " . TABLE_PRODUCTS_OPTIONS_VALUES
                . " (products_options_values_id, language_id, products_options_values_name, products_options_values_sort_order)"
                . " VALUES (" . $valueId . ", " . $lang . ", '" . $this->db->prepare_input(substr($label, 0, 64)) . "', " . $sort . ")"
            );
        }
        $this->db->Execute(
            "INSERT INTO " . TABLE_PRODUCTS_OPTIONS_VALUES_TO_PRODUCTS_OPTIONS
            . " (products_options_id, products_options_values_id) VALUES (" . $optionId . ", " . $valueId . ")"
        );
        $this->db->Execute(
            "DELETE FROM " . TABLE_SUBSCRIPTIONS_INTERVALS
            . " WHERE interval_unit = '" . $this->db->prepare_input($unit) . "' AND interval_count = " . $count
        );
        $this->db->Execute(
            "INSERT INTO " . TABLE_SUBSCRIPTIONS_INTERVALS . " (options_values_id, interval_unit, interval_count)"
            . " VALUES (" . $valueId . ", '" . $this->db->prepare_input($unit) . "', " . $count . ")"
        );
        return $valueId;
    }

    /* ----------------------------------------------------------------- *
     * Per-product settings
     * ----------------------------------------------------------------- */

    /** The product's saved settings, or the defaults for a product never set up. */
    public function productSettings(int $productsId): array
    {
        $default = ['mode' => 'off', 'intervals' => [], 'discount' => 0.0, 'max_cycles' => 0];
        if ($productsId <= 0) {
            return $default;
        }
        $r = $this->db->Execute("SELECT * FROM " . TABLE_PRODUCTS_SUBSCRIPTION . " WHERE products_id = " . $productsId . " LIMIT 1");
        if ($r->EOF) {
            return $default;
        }
        $intervals = [];
        foreach (explode(',', (string)$r->fields['intervals']) as $key) {
            if (SubscriptionsCore::parseKey(trim($key)) !== null) {
                $intervals[] = trim($key);
            }
        }
        return [
            'mode' => in_array($r->fields['subscription_mode'], SubscriptionsCore::MODES, true) ? $r->fields['subscription_mode'] : 'off',
            'intervals' => $intervals,
            'discount' => SubscriptionsCore::cleanDiscount($r->fields['discount_percent']),
            'max_cycles' => max(0, (int)$r->fields['max_cycles']),
        ];
    }

    /**
     * Store the owner's settings for a product, then rebuild its attribute rows.
     *
     * Intervals are kept only if they are still in the store-wide list; the
     * caller passes that list so an interval the owner has since removed can't
     * be smuggled back in through a stale form.
     *
     * @param string[] $intervalKeys
     * @param array<string, array{count:int, unit:string}> $allowed
     */
    public function saveProduct(int $productsId, string $mode, array $intervalKeys, $discount, int $maxCycles, array $allowed, bool $offer): void
    {
        if ($productsId <= 0) {
            return;
        }
        $mode = in_array($mode, SubscriptionsCore::MODES, true) ? $mode : 'off';
        $keys = [];
        foreach ($intervalKeys as $key) {
            if (isset($allowed[$key])) {
                $keys[$key] = true;
            }
        }
        // Keep the store-wide order (shortest first), not the order the boxes were ticked.
        $keys = array_values(array_intersect(array_keys($allowed), array_keys($keys)));
        $discount = SubscriptionsCore::cleanDiscount($discount);
        $maxCycles = max(0, min(999, $maxCycles));

        $this->db->Execute(
            "INSERT INTO " . TABLE_PRODUCTS_SUBSCRIPTION
            . " (products_id, subscription_mode, intervals, discount_percent, max_cycles, date_added, last_modified)"
            . " VALUES (" . $productsId . ", '" . $mode . "', '" . $this->db->prepare_input(implode(',', $keys)) . "', "
            . $discount . ", " . $maxCycles . ", now(), now())"
            . " ON DUPLICATE KEY UPDATE subscription_mode = VALUES(subscription_mode), intervals = VALUES(intervals),"
            . " discount_percent = VALUES(discount_percent), max_cycles = VALUES(max_cycles), last_modified = now()"
        );
        $this->applyProduct($productsId, $offer);
    }

    /**
     * Rebuild one product's rows for the plugin's option from its settings.
     *
     * Only rows for the plugin's own option are ever deleted or written; the
     * product's other attributes are not touched.
     */
    public function applyProduct(int $productsId, bool $offer): void
    {
        $optionId = (int)$this->config('SUBSCRIPTIONS_OPTION_ID', '0');
        if ($optionId <= 0 || $productsId <= 0) {
            return;
        }
        $this->db->Execute(
            "DELETE FROM " . TABLE_PRODUCTS_ATTRIBUTES
            . " WHERE products_id = " . $productsId . " AND options_id = " . $optionId
        );

        $s = $this->productSettings($productsId);
        if ($offer && $s['mode'] !== 'off' && $s['intervals'] !== []) {
            $factor = SubscriptionsCore::nativeFactorSafe()
                ? SubscriptionsCore::factorColumns($s['discount'])
                : SubscriptionsCore::factorColumns(0.0);

            $rows = [];
            if ($s['mode'] === 'optional') {
                $rows[] = [$this->ensureValue($optionId, 0, self::ONE_TIME_UNIT), SubscriptionsCore::factorColumns(0.0), 0];
            }
            foreach ($s['intervals'] as $key) {
                $iv = SubscriptionsCore::parseKey($key);
                $rows[] = [$this->ensureValue($optionId, $iv['count'], $iv['unit']), $factor, SubscriptionsCore::approxDays($iv['count'], $iv['unit'])];
            }

            foreach ($rows as $i => $row) {
                [$valueId, $cols, $sort] = $row;
                $this->db->Execute(
                    "INSERT INTO " . TABLE_PRODUCTS_ATTRIBUTES
                    . " (products_id, options_id, options_values_id, options_values_price, price_prefix,"
                    . " products_options_sort_order, attributes_default, attributes_discounted,"
                    . " attributes_price_factor, attributes_price_factor_offset)"
                    . " VALUES (" . $productsId . ", " . $optionId . ", " . $valueId . ", 0, '" . $cols['price_prefix'] . "', "
                    . $sort . ", " . ($i === 0 ? 1 : 0) . ", " . (int)$cols['attributes_discounted'] . ", "
                    . $cols['attributes_price_factor'] . ", " . $cols['attributes_price_factor_offset'] . ")"
                );
            }
        }

        if (function_exists('zen_update_products_price_sorter')) {
            zen_update_products_price_sorter($productsId);
        }
    }

    /** Product deleted in the admin: core removes its attributes, we remove the settings. */
    public function forgetProduct(int $productsId): void
    {
        if ($productsId > 0) {
            $this->db->Execute("DELETE FROM " . TABLE_PRODUCTS_SUBSCRIPTION . " WHERE products_id = " . $productsId);
        }
    }

    /* ----------------------------------------------------------------- *
     * Whole-store switches
     * ----------------------------------------------------------------- */

    /**
     * Bring every product's rows in line with the master switch.
     *
     * Called cheaply on admin page loads: it compares the switch with what was
     * last applied (SUBSCRIPTIONS_APPLIED) and only does work when they differ.
     * That is what makes "Offer subscriptions = false" actually take the option
     * off every product page, and true put it back, with no hook on the
     * configuration save. Returns the number of products rebuilt.
     */
    public function reconcile(bool $offer): int
    {
        $want = $offer ? 'true' : 'false';
        if ($this->config('SUBSCRIPTIONS_APPLIED', '') === $want) {
            return 0;
        }
        $n = 0;
        $r = $this->db->Execute("SELECT products_id FROM " . TABLE_PRODUCTS_SUBSCRIPTION);
        while (!$r->EOF) {
            $this->applyProduct((int)$r->fields['products_id'], $offer);
            $n++;
            $r->MoveNext();
        }
        $this->setConfig('SUBSCRIPTIONS_APPLIED', $want);
        return $n;
    }

    /** Remove the plugin's option from every product (uninstall). */
    public function stripAll(): void
    {
        $optionId = (int)$this->config('SUBSCRIPTIONS_OPTION_ID', '0');
        if ($optionId > 0) {
            $this->db->Execute("DELETE FROM " . TABLE_PRODUCTS_ATTRIBUTES . " WHERE options_id = " . $optionId);
        }
    }

    /** Remove the option and its values entirely (uninstall with "delete data"). */
    public function dropOption(): void
    {
        $optionId = (int)$this->config('SUBSCRIPTIONS_OPTION_ID', '0');
        if ($optionId <= 0) {
            return;
        }
        $this->stripAll();
        // Only the values this plugin created (its mapping table), never one an
        // owner may have linked to the option and also uses elsewhere.
        $values = [];
        $r = $this->db->Execute("SELECT options_values_id FROM " . TABLE_SUBSCRIPTIONS_INTERVALS);
        while (!$r->EOF) {
            $values[] = (int)$r->fields['options_values_id'];
            $r->MoveNext();
        }
        if ($values !== []) {
            $in = implode(',', $values);
            $this->db->Execute("DELETE FROM " . TABLE_PRODUCTS_OPTIONS_VALUES . " WHERE products_options_values_id IN (" . $in . ")");
            $this->db->Execute(
                "DELETE FROM " . TABLE_PRODUCTS_OPTIONS_VALUES_TO_PRODUCTS_OPTIONS
                . " WHERE products_options_values_id IN (" . $in . ")"
            );
        }
        $this->db->Execute("DELETE FROM " . TABLE_PRODUCTS_OPTIONS_VALUES_TO_PRODUCTS_OPTIONS . " WHERE products_options_id = " . $optionId);
        $this->db->Execute("DELETE FROM " . TABLE_PRODUCTS_OPTIONS . " WHERE products_options_id = " . $optionId);
    }
}
