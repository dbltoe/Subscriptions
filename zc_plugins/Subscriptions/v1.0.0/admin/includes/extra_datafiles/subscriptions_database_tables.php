<?php
/**
 * Subscriptions -- table names (admin).
 *
 * extra_datafiles are loaded per plugin on both sides from v1.5.8 on; the
 * definitions themselves live in SubscriptionsCore and are guarded.
 *
 * @package  Subscriptions
 * @license  http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

require_once dirname(__DIR__, 3) . '/shared/SubscriptionsCore.php';
SubscriptionsCore::defineTables();
