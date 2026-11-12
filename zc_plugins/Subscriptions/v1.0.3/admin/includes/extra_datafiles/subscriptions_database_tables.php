<?php
/**
 * Subscriptions -- table names (admin).
 *
 * extra_datafiles are loaded per plugin on both sides from v1.5.8 on; the
 * definitions themselves live in SubscriptionsCore and are guarded.
 *
 * @package  Subscriptions
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

if (!class_exists('SubscriptionsCore', false)) {
    require_once dirname(__DIR__, 3) . '/shared/SubscriptionsCore.php';
}
SubscriptionsCore::defineTables();
