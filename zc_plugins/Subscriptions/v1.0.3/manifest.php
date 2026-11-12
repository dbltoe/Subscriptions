<?php
/**
 * Subscriptions -- plugin manifest.
 *
 * pluginDescription is echoed unescaped into Plugin Manager's info box on every
 * release from v1.5.8 to v3.0.0, so the ReadMe link lives here. On v1.5.8,
 * v2.0 and v2.1 the description and pluginId are written only when Plugin
 * Manager first sees the plugin, so both have to be right before the first
 * store installs it.
 *
 * ReadMe opens README.md through the plugin's own admin page
 * (index.php?cmd=subscriptions_readme): behind the admin login, superusers
 * only, in its own window. It is shown only to superusers; the page enforces
 * that too, because Zen Cart 1.5.8-2.1 keep the description they first saw.
 * Forum Thread and GitHub go off-site, so they open in a new window with
 * rel="noopener noreferrer" (zencart/zencart#8031).
 *
 * The GitHub and forum links render nothing while empty: a link that 404s is
 * worse than none.
 *
 * @package  Subscriptions
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

$subsReadmeUrl = 'index.php?cmd=subscriptions_readme';
// Zen Cart 3.0.0 reads every manifest on every admin page, early (template resolver),
// before admin access is set up: zen_is_superuser() there is a fatal "Undefined constant
// SUPERUSER_PROFILE" that takes down the whole admin. Ask only once that exists.
$subsShowReadme = true;
if (function_exists('zen_is_superuser') && defined('SUPERUSER_PROFILE')) {
    $subsShowReadme = zen_is_superuser();
}
$subsGithubUrl = 'https://github.com/dbltoe/Subscriptions';
$subsForumUrl = 'https://www.zen-cart.com/threads/207379';

/*
 * Settings: this plugin's Configuration page, shown only once installed. Its
 * gID differs per store, so it's read from the admin page the installer
 * registers (configSubscriptions) and linked relatively. Guarded so a read
 * with no database or outside the admin just leaves it out. v2.2+ re-read
 * this file on every Plugin Manager visit; on v1.5.8-2.1 the installer saves
 * the description again at Install, Upgrade and Uninstall.
 */
$subsSettingsUrl = '';
if (defined('IS_ADMIN_FLAG') && IS_ADMIN_FLAG === true && defined('TABLE_ADMIN_PAGES')
    && isset($GLOBALS['db']) && is_object($GLOBALS['db'])) {
    $subsPage = $GLOBALS['db']->Execute(
        "SELECT main_page, page_params FROM " . TABLE_ADMIN_PAGES
        . " WHERE page_key = 'configSubscriptions' LIMIT 1"
    );
    if (!$subsPage->EOF && defined($subsPage->fields['main_page'])) {
        $subsSettingsUrl = 'index.php?cmd=' . constant($subsPage->fields['main_page'])
            . '&' . $subsPage->fields['page_params'];
    }
}

// One row: ReadMe | Settings | Forum Thread | GitHub. 4px gaps and 8px side
// padding keep all four on one line from a 1366px-wide window up.
$subsGap = '4px';
$subsButton = static function ($url, $label, $newTab = true) use ($subsGap) {
    return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"'
        . ($newTab ? ' target="_blank" rel="noopener noreferrer"' : '')
        . ' class="btn btn-primary" role="button"'
        . ' style="margin:0 ' . $subsGap . ' 0 0;padding-left:8px;padding-right:8px">' . $label . '</a>';
};
$subsLinks = '<div style="margin:10px 0 0;padding:0 0 0 ' . $subsGap . '">'
    . ($subsShowReadme ? $subsButton($subsReadmeUrl, 'ReadMe') : '')
    . ($subsSettingsUrl !== '' ? $subsButton($subsSettingsUrl, 'Settings', false) : '')
    . ($subsForumUrl !== '' ? $subsButton($subsForumUrl, 'Forum Thread') : '')
    . ($subsGithubUrl !== '' ? $subsButton($subsGithubUrl, 'GitHub') : '')
    . '</div>';

return [
    'pluginVersion' => 'v1.0.3',
    'pluginName' => 'Subscriptions',
    'pluginDescription' =>
        'Sell products as subscriptions. Customers choose a one-time purchase or a delivery '
        . 'interval on the product page, with an optional subscribe-and-save discount, and manage '
        . 'their subscriptions from My Account. Renewals are emailed as a pay link that works with '
        . 'any payment module.'
        . $subsLinks,
    'pluginAuthor' => 'My Zen Cart Host (dbltoe)',
    // Plugins Library id, assigned 2026-10-06 (the listing's first number was
    // 2265). Unquoted: the Library's packaging only rewrites a bare integer.
    'pluginId' => 2463,
    'zcVersions' => ['v158', 'v200', 'v210', 'v220', 'v230', 'v300'],
    'changelog' => 'changelog.txt',
    'github_repo' => $subsGithubUrl,
    'pluginGroups' => [],
];
