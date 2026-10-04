<?php
/**
 * Subscriptions -- the parts every side of the plugin shares.
 *
 * Pure functions only: table names, interval parsing, labels, due dates and the
 * price-factor arithmetic. Nothing in here touches the database, so the
 * harnesses can exercise all of it without a store.
 *
 * Loaded with require_once via __DIR__ from every entry point (installer, admin
 * observer, storefront observer). No Zen Cart loader is relied on: the catalog
 * side never auto-loads a plugin's extra_functions, and the admin side stopped
 * doing so on master in 2026.
 *
 * Source must parse on PHP 7.4 and stay deprecation-clean on 8.5.
 *
 * @package  Subscriptions
 * @license  http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

class SubscriptionsCore
{
    public const VERSION = 'v1.0.0';

    /** Units a subscription can repeat in, with the most each may count up to. */
    public const UNITS = ['day' => 365, 'week' => 52, 'month' => 12];

    /** The most a subscribe-and-save discount may be, in percent. */
    public const MAX_DISCOUNT = 90;

    /** Product settings the admin panel offers. */
    public const MODES = ['off', 'optional', 'required'];

    /**
     * Define the plugin's table constants. Guarded, because v2.2+ also loads a
     * plugin's root-level database_tables.php and every extra_datafiles file is
     * loaded on both sides.
     */
    public static function defineTables(): void
    {
        $prefix = defined('DB_PREFIX') ? DB_PREFIX : '';
        $tables = [
            'TABLE_SUBSCRIPTIONS' => 'subscriptions',
            'TABLE_SUBSCRIPTIONS_PRODUCTS' => 'subscriptions_products',
            'TABLE_SUBSCRIPTIONS_HISTORY' => 'subscriptions_history',
            'TABLE_SUBSCRIPTIONS_INTERVALS' => 'subscriptions_intervals',
            'TABLE_PRODUCTS_SUBSCRIPTION' => 'products_subscription',
        ];
        foreach ($tables as $name => $table) {
            if (!defined($name)) {
                define($name, $prefix . $table);
            }
        }
    }

    /**
     * Turn the owner's interval list ("1 week, 2 weeks, 1 month") into
     * normalized intervals, shortest first, duplicates and nonsense dropped.
     *
     * @return array<string, array{count:int, unit:string}> keyed by intervalKey()
     */
    public static function parseIntervals(string $list): array
    {
        $out = [];
        foreach (preg_split('/[,;\r\n]+/', $list) as $part) {
            $part = strtolower(trim($part));
            if ($part === '') {
                continue;
            }
            if (!preg_match('/^(\d{1,3})\s*(day|week|month)s?$/', $part, $m)) {
                continue;
            }
            $count = (int)$m[1];
            $unit = $m[2];
            if ($count < 1 || $count > self::UNITS[$unit]) {
                continue;
            }
            $out[self::intervalKey($count, $unit)] = ['count' => $count, 'unit' => $unit];
        }
        // Ties ("30 days" and "1 month") break by unit, so the order is the
        // same on PHP 7.4, whose sort isn't stable, as on 8.x.
        $rank = ['day' => 0, 'week' => 1, 'month' => 2];
        uasort($out, static function ($a, $b) use ($rank) {
            return [self::approxDays($a['count'], $a['unit']), $rank[$a['unit']]]
                <=> [self::approxDays($b['count'], $b['unit']), $rank[$b['unit']]];
        });
        return $out;
    }

    /** A short, form-safe key: w2 = every 2 weeks, m1 = every month. */
    public static function intervalKey(int $count, string $unit): string
    {
        return substr($unit, 0, 1) . $count;
    }

    /** The reverse of intervalKey(); null when the key isn't one we'd make. */
    public static function parseKey(string $key): ?array
    {
        if (!preg_match('/^([dwm])(\d{1,3})$/', $key, $m)) {
            return null;
        }
        $unit = ['d' => 'day', 'w' => 'week', 'm' => 'month'][$m[1]];
        $count = (int)$m[2];
        if ($count < 1 || $count > self::UNITS[$unit]) {
            return null;
        }
        return ['count' => $count, 'unit' => $unit];
    }

    /** For ordering only; never for due dates. */
    public static function approxDays(int $count, string $unit): int
    {
        return $count * ['day' => 1, 'week' => 7, 'month' => 30][$unit];
    }

    /**
     * The customer-facing label for an interval. The templates come from the
     * language file; the English defaults keep the installer and the harness
     * working without one.
     */
    public static function intervalLabel(int $count, string $unit): string
    {
        $one = [
            'day' => self::text('SUBSCRIPTIONS_EVERY_DAY', 'Every Day'),
            'week' => self::text('SUBSCRIPTIONS_EVERY_WEEK', 'Every Week'),
            'month' => self::text('SUBSCRIPTIONS_EVERY_MONTH', 'Every Month'),
        ];
        $many = [
            'day' => self::text('SUBSCRIPTIONS_EVERY_N_DAYS', 'Every %u Days'),
            'week' => self::text('SUBSCRIPTIONS_EVERY_N_WEEKS', 'Every %u Weeks'),
            'month' => self::text('SUBSCRIPTIONS_EVERY_N_MONTHS', 'Every %u Months'),
        ];
        return $count === 1 ? $one[$unit] : sprintf($many[$unit], $count);
    }

    public static function oneTimeLabel(): string
    {
        return self::text('SUBSCRIPTIONS_ONE_TIME', 'One-Time Purchase');
    }

    /**
     * The date of the Nth renewal, counted from the signup date.
     *
     * Always computed from the anchor, never from the previous due date, so a
     * subscription started on the 31st renews on the last day of short months
     * and goes back to the 31st afterward, instead of drifting to the 28th for
     * good.
     */
    public static function dueDate(string $anchorYmd, int $n, int $count, string $unit): string
    {
        $anchor = DateTime::createFromFormat('!Y-m-d', $anchorYmd);
        if ($anchor === false || !isset(self::UNITS[$unit]) || $n < 0 || $count < 1) {
            throw new InvalidArgumentException('bad due-date arguments');
        }
        $steps = $n * $count;
        if ($unit === 'day') {
            return $anchor->modify('+' . $steps . ' days')->format('Y-m-d');
        }
        if ($unit === 'week') {
            return $anchor->modify('+' . ($steps * 7) . ' days')->format('Y-m-d');
        }
        $y = (int)$anchor->format('Y');
        $m = (int)$anchor->format('n') + $steps;
        $d = (int)$anchor->format('j');
        $y += intdiv($m - 1, 12);
        $m = (($m - 1) % 12) + 1;
        $first = new DateTime(sprintf('%04d-%02d-01', $y, $m));
        $last = (int)$first->format('t');
        return sprintf('%04d-%02d-%02d', $y, $m, min($d, $last));
    }

    /**
     * The first due date on the subscription's schedule that falls strictly
     * after $afterYmd. Used by Skip (the date after the current next date) and
     * Resume (the first date after today), so both stay on the anchor's
     * schedule rather than drifting.
     */
    public static function nextAfter(string $anchorYmd, string $afterYmd, int $count, string $unit): string
    {
        $anchor = DateTime::createFromFormat('!Y-m-d', $anchorYmd);
        $after = DateTime::createFromFormat('!Y-m-d', $afterYmd);
        if ($anchor === false || $after === false) {
            throw new InvalidArgumentException('bad date');
        }
        // Start a little before the estimate, then walk forward.
        $days = (int)$anchor->diff($after)->format('%r%a');
        $n = max(0, intdiv(max(0, $days), max(1, self::approxDays($count, $unit))) - 2);
        for ($guard = 0; $guard < 1000; $guard++, $n++) {
            $due = self::dueDate($anchorYmd, $n, $count, $unit);
            if ($due > $afterYmd) {
                return $due;
            }
        }
        throw new RuntimeException('no due date found');
    }

    /** Clamp an owner-typed discount to 0..MAX_DISCOUNT, two decimals. */
    public static function cleanDiscount($value): float
    {
        $v = round((float)str_replace(',', '.', (string)$value), 2);
        return max(0.0, min((float)self::MAX_DISCOUNT, $v));
    }

    /**
     * The products_attributes columns that make core take X% off.
     *
     * The cart applies a price factor only when attributes_price_factor > 0
     * (shopping_cart.php calculate() and attributes_price(), every branch), so
     * a plain negative factor is silently dropped. With factor = 1 - X/100 and
     * offset = 1 the factor passes that gate and the charge is
     * price * (factor - offset) = -X% of the base or special price.
     *
     * No discount means factor 0 / offset 0: the gate skips it entirely.
     *
     * @return array{attributes_price_factor:float, attributes_price_factor_offset:float, price_prefix:string, attributes_discounted:int}
     */
    public static function factorColumns(float $percent): array
    {
        $percent = self::cleanDiscount($percent);
        if ($percent <= 0) {
            return ['attributes_price_factor' => 0.0, 'attributes_price_factor_offset' => 0.0, 'price_prefix' => '+', 'attributes_discounted' => 0];
        }
        return [
            'attributes_price_factor' => round(1 - $percent / 100, 4),
            'attributes_price_factor_offset' => 1.0,
            'price_prefix' => '-',
            'attributes_discounted' => 1,
        ];
    }

    /**
     * Whether core's price factor can carry the discount on this store.
     *
     * Zen Cart 3.0.0-dev declares strict_types in shopping_cart.php and passes
     * the factor column (a database string) to a float parameter, which is a
     * TypeError (found 2026-10-03). Until that is fixed upstream the discount
     * is applied by the storefront observer there instead, and the attribute
     * rows carry no factor.
     */
    public static function nativeFactorSafe(): bool
    {
        $major = defined('PROJECT_VERSION_MAJOR') ? (int)PROJECT_VERSION_MAJOR : 1;
        return $major < 3;
    }

    /**
     * The storefront address the scheduler answers on, built in the admin from
     * its configure.php (which names the catalog) for the installer's setting
     * description and the admin page.
     */
    public static function schedulerUrl(string $cronKey): string
    {
        $ssl = defined('ENABLE_SSL_CATALOG') && ENABLE_SSL_CATALOG === 'true' && defined('HTTPS_CATALOG_SERVER');
        $server = $ssl ? HTTPS_CATALOG_SERVER : (defined('HTTP_CATALOG_SERVER') ? HTTP_CATALOG_SERVER : '');
        $dir = ($ssl && defined('DIR_WS_HTTPS_CATALOG')) ? DIR_WS_HTTPS_CATALOG : (defined('DIR_WS_CATALOG') ? DIR_WS_CATALOG : '/');
        return $server . $dir . 'index.php?main_page=subscriptions_cron&key=' . $cronKey;
    }

    /**
     * Define the storefront text (the customer emails' wording) on the admin
     * side, where Zen Cart loads only the admin language files. Constants
     * already defined win. Falls back to English for a language the plugin
     * doesn't ship.
     */
    public static function loadStorefrontText(string $language): void
    {
        $base = dirname(__DIR__) . '/catalog/includes/languages/';
        $file = $base . basename($language) . '/extra_definitions/lang.subscriptions.php';
        if (!is_file($file)) {
            $file = $base . 'english/extra_definitions/lang.subscriptions.php';
        }
        $define = require $file;
        foreach ((array)$define as $name => $value) {
            if (!defined($name)) {
                define($name, $value);
            }
        }
    }

    private static function text(string $constant, string $default): string
    {
        return defined($constant) ? (string)constant($constant) : $default;
    }
}
