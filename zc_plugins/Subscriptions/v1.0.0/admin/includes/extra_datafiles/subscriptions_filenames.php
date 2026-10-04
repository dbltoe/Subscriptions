<?php
/**
 * Subscriptions -- admin page names.
 *
 * In extra_datafiles (loaded per plugin from v1.5.8 on) rather than a root
 * filenames.php, which only v2.2.0+ loads. Guarded, because v2.2.0+ may also
 * pick up a root file.
 *
 * @package  Subscriptions
 * @license  http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

if (!defined('FILENAME_SUBSCRIPTIONS')) {
    define('FILENAME_SUBSCRIPTIONS', 'subscriptions');
}
// The customer's page, for the links in emails the admin page sends.
if (!defined('FILENAME_ACCOUNT_SUBSCRIPTIONS')) {
    define('FILENAME_ACCOUNT_SUBSCRIPTIONS', 'account_subscriptions');
}
