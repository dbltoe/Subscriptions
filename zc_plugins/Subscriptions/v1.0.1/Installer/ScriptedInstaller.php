<?php
/**
 * Subscriptions -- Plugin Manager installer.
 *
 * Limited to the API every supported release has (v1.5.8 -> v3.0.0):
 *
 *   - Zen Cart calls only executeInstall(), executeUninstall() and
 *     executeUpgrade(). Any other method here is a helper, never a hook.
 *   - Returning false does not refuse an install; an errorContainer entry
 *     does. All checks run before anything is written, because nothing rolls
 *     back.
 *   - executeUpgrade() takes an optional argument: v1.5.8 passes none.
 *   - Database work goes through executeInstallerSql() or the queryFactory;
 *     the configuration helpers added in v2.0.1/v2.1.0 don't exist on v1.5.8.
 *
 * Every step is idempotent, so an upgrade is a re-run of the install.
 *
 * @package  Subscriptions
 * @license  http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

use Zencart\PluginSupport\ScriptedInstaller as ScriptedInstallBase;

// Plugin Manager's Upgrade runs this file in an admin request that already holds the
// installed version's classes, loaded from that version's folder; requiring this
// version's copy as well would fatal on the redeclared class. So every require of a
// plugin class, here and throughout the plugin, happens only when the class is missing.
if (!class_exists('SubscriptionsAttributes', false)) {
    require_once dirname(__DIR__) . '/shared/SubscriptionsAttributes.php';
}
if (!class_exists('SubscriptionsRenewals', false)) {
    require_once dirname(__DIR__) . '/shared/SubscriptionsRenewals.php';
}

class ScriptedInstaller extends ScriptedInstallBase
{
    public const CONFIG_GROUP_TITLE = 'Subscriptions';

    public const ADMIN_PAGE_KEYS = ['configSubscriptions', 'customersSubscriptions'];

    /**
     * The plugin's tables, by TABLE_* constant (each carries DB_PREFIX), with
     * the columns that prove an existing table is ours and not another plugin's.
     */
    public const OWNERSHIP = [
        'TABLE_SUBSCRIPTIONS' => ['next_date', 'paylink_token', 'renewing_orders_id'],
        'TABLE_SUBSCRIPTIONS_PRODUCTS' => ['subscriptions_id', 'products_prid'],
        'TABLE_SUBSCRIPTIONS_HISTORY' => ['subscriptions_id', 'event'],
        'TABLE_SUBSCRIPTIONS_INTERVALS' => ['options_values_id', 'interval_unit'],
        'TABLE_PRODUCTS_SUBSCRIPTION' => ['subscription_mode', 'intervals'],
    ];

    protected function executeInstall()
    {
        SubscriptionsCore::defineTables();

        $clash = $this->subsForeignTable();
        if ($clash !== '') {
            $this->errorContainer->addError(
                0,
                'Subscriptions was not installed: the database already has a table named "' . $clash
                . '" that belongs to something else. Rename or remove that table, then install again.',
                true
            );
            return false;
        }

        // Known before the keys are written: the scheduler address in its
        // description carries it. An upgrade keeps the store's existing key.
        $cronKey = $this->subsExistingValue('SUBSCRIPTIONS_CRON_KEY');
        if ($cronKey === '') {
            $cronKey = bin2hex(random_bytes(20));
        }

        $groupId = $this->subsGetOrCreateConfigGroup();
        if ($groupId === 0) {
            return false;
        }
        if ($this->subsAddConfigurationKeys($groupId, $cronKey) === false) {
            return false;
        }
        $this->subsRegisterAdminPages($groupId);
        if ($this->subsCreateTables() === false) {
            return false;
        }

        $attributes = new SubscriptionsAttributes($this->dbConn, $this->subsLanguageIds());
        if ((int)$attributes->config('SUBSCRIPTIONS_OPTION_ID', '0') === 0) {
            // A re-install after an uninstall that kept the data: find the
            // option the old install created instead of making a second one.
            $recovered = $this->subsRecoverOptionId();
            if ($recovered > 0) {
                $attributes->setConfig('SUBSCRIPTIONS_OPTION_ID', (string)$recovered);
            }
        }
        $attributes->ensureOption(defined('SUBSCRIPTIONS_OPTION_NAME') ? SUBSCRIPTIONS_OPTION_NAME : 'Delivery');

        if ($attributes->config('SUBSCRIPTIONS_CRON_KEY', '') === '') {
            $attributes->setConfig('SUBSCRIPTIONS_CRON_KEY', $cronKey);
        }

        // Forget what was last applied so the first admin page load rebuilds
        // every product's Delivery rows from the saved settings.
        $attributes->setConfig('SUBSCRIPTIONS_APPLIED', '');

        $this->subsLog('Subscriptions: installed/upgraded.');
        return true;
    }

    protected function executeUpgrade($oldVersion = null)
    {
        return $this->executeInstall();
    }

    protected function executeUninstall()
    {
        SubscriptionsCore::defineTables();
        zen_deregister_admin_pages(self::ADMIN_PAGE_KEYS);

        $attributes = new SubscriptionsAttributes($this->dbConn, $this->subsLanguageIds());
        $deleteData = $attributes->config('SUBSCRIPTIONS_DELETE_ON_UNINSTALL', 'false') === 'true';

        // Always: with the plugin gone nothing would stop a customer choosing a
        // discounted "Every month" that never renews.
        $attributes->stripAll();

        if ($deleteData) {
            $attributes->dropOption();
            foreach (array_keys(self::OWNERSHIP) as $table) {
                $this->executeInstallerSql("DROP TABLE IF EXISTS " . constant($table));
            }
        }

        // Keeping the data keeps the scheduler key too: the store's cron job
        // carries it, and a re-install that made a new one would silently stop
        // every renewal reminder. The re-install finds the row and adopts it.
        $keep = $deleteData ? '' : "configuration_key <> 'SUBSCRIPTIONS_CRON_KEY' AND ";
        $groupId = $this->subsGetConfigGroupId();
        if ($groupId > 0) {
            $this->executeInstallerSql("DELETE FROM " . TABLE_CONFIGURATION . " WHERE " . $keep . "configuration_group_id = " . $groupId);
            $this->executeInstallerSql("DELETE FROM " . TABLE_CONFIGURATION_GROUP . " WHERE configuration_group_id = " . $groupId);
        }
        $this->executeInstallerSql("DELETE FROM " . TABLE_CONFIGURATION . " WHERE " . $keep . "configuration_key LIKE 'SUBSCRIPTIONS\\_%'");

        $this->subsLog('Subscriptions: uninstalled' . ($deleteData ? ', data deleted.' : ', subscription data kept.'));
        return true;
    }

    /**
     * Zen Cart 3.0.0+ only: called when the owner disables the plugin in Plugin
     * Manager. Earlier releases have no disable hook at all (their base class
     * offers only install, uninstall and upgrade), so there the readme steers
     * owners to the "Offer subscriptions" switch or to Uninstall instead.
     *
     * Disabled, the admin observer stops running, so nothing would otherwise
     * take the Delivery choice off product pages. Clearing SUBSCRIPTIONS_APPLIED
     * makes the observer rebuild every product's rows on the first admin page
     * after the plugin is enabled again.
     */
    protected function validateDisable(): bool
    {
        SubscriptionsCore::defineTables();
        $attributes = new SubscriptionsAttributes($this->dbConn, $this->subsLanguageIds());
        $attributes->stripAll();
        $attributes->setConfig('SUBSCRIPTIONS_APPLIED', '');
        return true;
    }

    /* ----------------------------------------------------------------- *
     * Checks
     * ----------------------------------------------------------------- */

    /** The first table that exists but isn't shaped like ours, or ''. */
    protected function subsForeignTable(): string
    {
        foreach (self::OWNERSHIP as $table => $markers) {
            $full = constant($table);
            $r = $this->dbConn->Execute("SHOW TABLES LIKE '" . $this->dbConn->prepare_input($full) . "'");
            if ($r->EOF) {
                continue;
            }
            $columns = [];
            $c = $this->dbConn->Execute("SHOW COLUMNS FROM " . $full);
            while (!$c->EOF) {
                $columns[] = $c->fields['Field'];
                $c->MoveNext();
            }
            if (array_diff($markers, $columns) !== []) {
                return $full;
            }
        }
        return '';
    }

    protected function subsRecoverOptionId(): int
    {
        $r = $this->dbConn->Execute("SHOW TABLES LIKE '" . $this->dbConn->prepare_input(TABLE_SUBSCRIPTIONS_INTERVALS) . "'");
        if ($r->EOF) {
            return 0;
        }
        $r = $this->dbConn->Execute(
            "SELECT p2o.products_options_id FROM " . TABLE_PRODUCTS_OPTIONS_VALUES_TO_PRODUCTS_OPTIONS . " p2o"
            . " INNER JOIN " . TABLE_SUBSCRIPTIONS_INTERVALS . " si ON si.options_values_id = p2o.products_options_values_id"
            . " INNER JOIN " . TABLE_PRODUCTS_OPTIONS . " po ON po.products_options_id = p2o.products_options_id"
            . " LIMIT 1"
        );
        return $r->EOF ? 0 : (int)$r->fields['products_options_id'];
    }

    /* ----------------------------------------------------------------- *
     * Tables
     * ----------------------------------------------------------------- */

    protected function subsCreateTables(): bool
    {
        $never = "'0001-01-01 00:00:00'";
        $sql = [
            "CREATE TABLE IF NOT EXISTS " . TABLE_SUBSCRIPTIONS . " (
                subscriptions_id int(11) unsigned NOT NULL AUTO_INCREMENT,
                customers_id int(11) NOT NULL DEFAULT 0,
                status varchar(16) NOT NULL DEFAULT 'active',
                interval_unit varchar(8) NOT NULL DEFAULT 'month',
                interval_count smallint(4) NOT NULL DEFAULT 1,
                anchor_date date NOT NULL DEFAULT '0001-01-01',
                next_date date NOT NULL DEFAULT '0001-01-01',
                cycles_completed int(11) NOT NULL DEFAULT 0,
                max_cycles int(11) NOT NULL DEFAULT 0,
                origin_orders_id int(11) NOT NULL DEFAULT 0,
                last_orders_id int(11) NOT NULL DEFAULT 0,
                renewing_orders_id int(11) NOT NULL DEFAULT 0,
                payment_mode varchar(16) NOT NULL DEFAULT 'paylink',
                payment_module_code varchar(64) NOT NULL DEFAULT '',
                gateway varchar(32) NOT NULL DEFAULT '',
                gateway_customer_ref varchar(64) NOT NULL DEFAULT '',
                gateway_payment_ref varchar(64) NOT NULL DEFAULT '',
                gateway_network_ref varchar(64) NOT NULL DEFAULT '',
                failure_count tinyint(3) NOT NULL DEFAULT 0,
                shipping_method varchar(255) NOT NULL DEFAULT '',
                shipping_module_code varchar(64) NOT NULL DEFAULT '',
                shipping_cost decimal(15,4) NOT NULL DEFAULT 0.0000,
                currency char(3) NOT NULL DEFAULT '',
                currency_value decimal(14,6) NOT NULL DEFAULT 1.000000,
                paylink_token char(64) NOT NULL DEFAULT '',
                paylink_expires datetime NOT NULL DEFAULT $never,
                reminder_sent_for date NOT NULL DEFAULT '0001-01-01',
                consent_at datetime NOT NULL DEFAULT $never,
                consent_ip varchar(96) NOT NULL DEFAULT '',
                consent_hash char(64) NOT NULL DEFAULT '',
                consent_text text,
                date_added datetime NOT NULL DEFAULT $never,
                last_modified datetime NOT NULL DEFAULT $never,
                date_canceled datetime NOT NULL DEFAULT $never,
                cancel_reason varchar(255) NOT NULL DEFAULT '',
                PRIMARY KEY (subscriptions_id),
                KEY idx_subs_customer (customers_id),
                KEY idx_subs_due (status, next_date)
            )",
            "CREATE TABLE IF NOT EXISTS " . TABLE_SUBSCRIPTIONS_PRODUCTS . " (
                subscriptions_products_id int(11) unsigned NOT NULL AUTO_INCREMENT,
                subscriptions_id int(11) NOT NULL DEFAULT 0,
                products_id int(11) NOT NULL DEFAULT 0,
                products_prid varchar(255) NOT NULL DEFAULT '',
                products_name varchar(255) NOT NULL DEFAULT '',
                products_model varchar(255) NOT NULL DEFAULT '',
                quantity float NOT NULL DEFAULT 1,
                final_price decimal(15,4) NOT NULL DEFAULT 0.0000,
                products_tax decimal(7,4) NOT NULL DEFAULT 0.0000,
                orders_products_id int(11) NOT NULL DEFAULT 0,
                PRIMARY KEY (subscriptions_products_id),
                KEY idx_subs_products_sub (subscriptions_id)
            )",
            "CREATE TABLE IF NOT EXISTS " . TABLE_SUBSCRIPTIONS_HISTORY . " (
                subscriptions_history_id int(11) unsigned NOT NULL AUTO_INCREMENT,
                subscriptions_id int(11) NOT NULL DEFAULT 0,
                event varchar(32) NOT NULL DEFAULT '',
                orders_id int(11) NOT NULL DEFAULT 0,
                amount decimal(15,4) NOT NULL DEFAULT 0.0000,
                detail text,
                actor varchar(64) NOT NULL DEFAULT '',
                date_added datetime NOT NULL DEFAULT $never,
                PRIMARY KEY (subscriptions_history_id),
                KEY idx_subs_history_sub (subscriptions_id)
            )",
            "CREATE TABLE IF NOT EXISTS " . TABLE_SUBSCRIPTIONS_INTERVALS . " (
                options_values_id int(11) NOT NULL DEFAULT 0,
                interval_unit varchar(8) NOT NULL DEFAULT '',
                interval_count smallint(4) NOT NULL DEFAULT 0,
                PRIMARY KEY (options_values_id),
                UNIQUE KEY idx_subs_interval (interval_unit, interval_count)
            )",
            "CREATE TABLE IF NOT EXISTS " . TABLE_PRODUCTS_SUBSCRIPTION . " (
                products_id int(11) NOT NULL DEFAULT 0,
                subscription_mode varchar(10) NOT NULL DEFAULT 'off',
                intervals varchar(255) NOT NULL DEFAULT '',
                discount_percent decimal(5,2) NOT NULL DEFAULT 0.00,
                max_cycles smallint(4) NOT NULL DEFAULT 0,
                date_added datetime NOT NULL DEFAULT $never,
                last_modified datetime NOT NULL DEFAULT $never,
                PRIMARY KEY (products_id)
            )",
        ];
        foreach ($sql as $statement) {
            if ($this->executeInstallerSql($statement) === false) {
                return false;
            }
        }
        return true;
    }

    /* ----------------------------------------------------------------- *
     * Configuration
     * ----------------------------------------------------------------- */

    protected function subsConfigurationKeys(string $cronKey = ''): array
    {
        $yesNo = "zen_cfg_select_option(array('true', 'false'), ";
        $readOnly = 'zen_cfg_read_only(';
        $schedulerUrl = htmlspecialchars(SubscriptionsCore::schedulerUrl($cronKey), ENT_QUOTES, 'UTF-8');

        return [
            [
                'key' => 'SUBSCRIPTIONS_STATUS',
                'title' => 'Offer Subscriptions on Product Pages?',
                'value' => 'true',
                'description' => 'When true, products you have set up for subscriptions show the Delivery choice. When false, the choice is taken off every product page; existing subscriptions are not changed.',
                'sort_order' => 10,
                'set_function' => $yesNo,
            ],
            [
                'key' => 'SUBSCRIPTIONS_INTERVALS',
                'title' => 'Intervals Offered',
                'value' => '1 week, 2 weeks, 1 month, 2 months, 3 months',
                'description' => 'The delivery intervals you can tick on each product, separated by commas. Use a number and day, week or month: <code>2 weeks</code>, <code>30 days</code>, <code>1 month</code>.<br><br>Up to 365 days, 52 weeks or 12 months.',
                'sort_order' => 20,
                'set_function' => '',
            ],
            [
                'key' => 'SUBSCRIPTIONS_DEFAULT_DISCOUNT',
                'title' => 'Default Subscribe-and-Save Discount (%)',
                'value' => '0',
                'description' => 'Filled in on a product when you first turn subscriptions on for it. 0 for no discount; at most ' . SubscriptionsCore::MAX_DISCOUNT . '.',
                'sort_order' => 30,
                'set_function' => '',
            ],
            [
                'key' => 'SUBSCRIPTIONS_REMINDER_DAYS',
                'title' => 'Reminder E-Mail, Days Before Renewal',
                'value' => '3',
                'description' => 'How many days before each renewal the customer is emailed a Renew Now link. 0 sends it on the renewal date; at most 60.<br><br>The link stays good until the next renewal\'s reminder is due. A delivery not ordered by then is skipped, and after ' . SubscriptionsRenewals::MISSED_LIMIT . ' in a row the subscription is paused and the customer told.',
                'sort_order' => 40,
                'set_function' => '',
            ],
            [
                'key' => 'SUBSCRIPTIONS_TERMS',
                'title' => 'Subscription Terms',
                'value' => 'Your subscription renews automatically at the interval you chose until you cancel. You can skip, pause or cancel at any time from My Subscriptions in your account.',
                'description' => 'Shown beside the consent checkbox at checkout and in the signup email. The exact text each customer agreed to is saved with their subscription.',
                'sort_order' => 50,
                'set_function' => 'zen_cfg_textarea(',
            ],
            [
                'key' => 'SUBSCRIPTIONS_DELETE_ON_UNINSTALL',
                'title' => 'Delete Subscription Data on Uninstall?',
                'value' => 'false',
                'description' => 'When false, uninstalling keeps every subscription and product setting so a re-install picks up where it left off. The Delivery choice is always removed from product pages on uninstall.',
                'sort_order' => 60,
                'set_function' => $yesNo,
            ],
            [
                'key' => 'SUBSCRIPTIONS_OPTION_ID',
                'title' => 'Delivery Option ID',
                'value' => '0',
                'description' => 'The product option this plugin manages. Set by the installer. You can rename the option in the Option Name Manager; don\'t delete it.',
                'sort_order' => 900,
                'set_function' => $readOnly,
            ],
            [
                'key' => 'SUBSCRIPTIONS_APPLIED',
                'title' => 'Product Pages Last Updated For',
                'value' => '',
                'description' => 'Maintained by the plugin.',
                'sort_order' => 910,
                'set_function' => $readOnly,
            ],
            [
                'key' => 'SUBSCRIPTIONS_CRON_KEY',
                'title' => 'Scheduler Key',
                'value' => '',
                'description' => 'The secret in the scheduler address. Generated at install.<br><br>The scheduler sends the renewal reminders. Run it every 6 hours with a cron job using this command. In cPanel (Cron Jobs), enter Minute 17, Hour */6, and * for Day, Month and Weekday.<br><br><code>curl -fsSL "' . $schedulerUrl . '" &gt;/dev/null</code><br><br>Or have an outside cron service open this address every 6 hours:<br><code>' . $schedulerUrl . '</code>',
                'sort_order' => 920,
                'set_function' => $readOnly,
            ],
            [
                'key' => 'SUBSCRIPTIONS_LAST_RUN',
                'title' => 'Scheduler Last Ran',
                'value' => '',
                'description' => 'Set by the scheduler each time it runs. Customers > Subscriptions warns when it hasn\'t run for a while.',
                'sort_order' => 930,
                'set_function' => $readOnly,
            ],
        ];
    }

    protected function subsAddConfigurationKeys(int $groupId, string $cronKey = '')
    {
        $db = $this->dbConn;
        foreach ($this->subsConfigurationKeys($cronKey) as $key) {
            $setFunction = empty($key['set_function']) ? 'NULL' : "'" . $db->prepare_input($key['set_function']) . "'";
            $ok = $this->executeInstallerSql(
                "INSERT IGNORE INTO " . TABLE_CONFIGURATION . "
                    (configuration_title, configuration_key, configuration_value, configuration_description,
                     configuration_group_id, sort_order, date_added, use_function, set_function)
                 VALUES
                    ('" . $db->prepare_input($key['title']) . "', '" . $db->prepare_input($key['key']) . "',
                     '" . $db->prepare_input($key['value']) . "', '" . $db->prepare_input($key['description']) . "',
                     " . $groupId . ", " . (int)$key['sort_order'] . ", now(), NULL, " . $setFunction . ")"
            );
            if ($ok === false) {
                return false;
            }
            // The value belongs to the owner and survives an upgrade; the
            // wording, order and input type belong to the plugin.
            $ok = $this->executeInstallerSql(
                "UPDATE " . TABLE_CONFIGURATION . "
                    SET configuration_title = '" . $db->prepare_input($key['title']) . "',
                        configuration_description = '" . $db->prepare_input($key['description']) . "',
                        configuration_group_id = " . $groupId . ",
                        sort_order = " . (int)$key['sort_order'] . ",
                        set_function = " . $setFunction . "
                  WHERE configuration_key = '" . $db->prepare_input($key['key']) . "'
                  LIMIT 1"
            );
            if ($ok === false) {
                return false;
            }
        }
        return true;
    }

    protected function subsExistingValue(string $key): string
    {
        $r = $this->dbConn->Execute(
            "SELECT configuration_value FROM " . TABLE_CONFIGURATION . " WHERE configuration_key = '" . $this->dbConn->prepare_input($key) . "' LIMIT 1"
        );
        return $r->EOF ? '' : (string)$r->fields['configuration_value'];
    }

    protected function subsGetConfigGroupId(): int
    {
        $r = $this->dbConn->Execute(
            "SELECT configuration_group_id FROM " . TABLE_CONFIGURATION_GROUP
            . " WHERE configuration_group_title = '" . $this->dbConn->prepare_input(self::CONFIG_GROUP_TITLE) . "' LIMIT 1"
        );
        return $r->EOF ? 0 : (int)$r->fields['configuration_group_id'];
    }

    protected function subsGetOrCreateConfigGroup(): int
    {
        $id = $this->subsGetConfigGroupId();
        if ($id > 0) {
            return $id;
        }
        $ok = $this->executeInstallerSql(
            "INSERT INTO " . TABLE_CONFIGURATION_GROUP . "
                (configuration_group_title, configuration_group_description, sort_order, visible)
             VALUES ('" . $this->dbConn->prepare_input(self::CONFIG_GROUP_TITLE) . "',
                     'Subscribe-and-save products, renewals and the customer\'s My Subscriptions page.', 0, 1)"
        );
        if ($ok === false) {
            return 0;
        }
        $id = $this->subsGetConfigGroupId();
        if ($id > 0) {
            $this->executeInstallerSql(
                "UPDATE " . TABLE_CONFIGURATION_GROUP . " SET sort_order = " . $id . " WHERE configuration_group_id = " . $id . " LIMIT 1"
            );
        }
        return $id;
    }

    /** Without an admin_pages row the group never shows under Configuration. */
    protected function subsRegisterAdminPages(int $groupId): void
    {
        zen_deregister_admin_pages(self::ADMIN_PAGE_KEYS);
        zen_register_admin_page(
            'configSubscriptions',
            'BOX_CONFIGURATION_SUBSCRIPTIONS',
            'FILENAME_CONFIGURATION',
            'gID=' . $groupId,
            'configuration',
            'Y',
            $groupId
        );
        zen_register_admin_page(
            'customersSubscriptions',
            'BOX_CUSTOMERS_SUBSCRIPTIONS',
            'FILENAME_SUBSCRIPTIONS',
            '',
            'customers',
            'Y',
            50
        );
    }

    /* ----------------------------------------------------------------- *
     * Small helpers
     * ----------------------------------------------------------------- */

    /** @return int[] */
    protected function subsLanguageIds(): array
    {
        $ids = [];
        if (function_exists('zen_get_languages')) {
            foreach (zen_get_languages() as $lang) {
                $ids[] = (int)$lang['id'];
            }
        }
        return $ids === [] ? [1] : $ids;
    }

    protected function subsLog(string $message): void
    {
        if (function_exists('zen_record_admin_activity')) {
            zen_record_admin_activity($message, 'info');
        }
    }
}
