<?php
/**
 * Subscriptions -- plugin manifest.
 *
 * pluginDescription is echoed unescaped into Plugin Manager's info box on every
 * release from v1.5.8 to v3.0.0, so the Read Me link lives here. On v1.5.8,
 * v2.0 and v2.1 the description and pluginId are written only when Plugin
 * Manager first sees the plugin, so both have to be right before the first
 * store installs it.
 *
 * The GitHub and forum links render nothing while empty: a link that 404s is
 * worse than none.
 *
 * @package  Subscriptions
 * @license  http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

$subsPluginDir = 'zc_plugins/Subscriptions/v1.0.1/';
$subsReadmeUrl = (defined('DIR_WS_CATALOG') ? DIR_WS_CATALOG : '/') . $subsPluginDir . 'readme.html';
$subsGithubUrl = 'https://github.com/dbltoe/Subscriptions';
$subsForumUrl = 'https://www.zen-cart.com/threads/207379';

$subsGap = '6px';
$subsButton = static function ($url, $label) use ($subsGap) {
    return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer" class="btn btn-primary" role="button"'
        . ' style="margin:0 ' . $subsGap . ' 0 0">' . $label . '</a>';
};
$subsLinks = '<div style="margin:10px 0 0;padding:0 0 0 ' . $subsGap . '">'
    . $subsButton($subsReadmeUrl, 'Read Me')
    . ($subsGithubUrl !== '' ? $subsButton($subsGithubUrl, 'GitHub') : '')
    . '</div>'
    . ($subsForumUrl !== ''
        ? '<div style="margin:8px 0 0;padding:0 0 0 ' . $subsGap . '"><a href="' . $subsForumUrl . '" target="_blank" rel="noopener noreferrer">Forum Support Thread</a></div>'
        : '');

return [
    'pluginVersion' => 'v1.0.1',
    'pluginName' => 'Subscriptions',
    'pluginDescription' =>
        'Sell products as subscriptions. Customers choose a one-time purchase or a delivery '
        . 'interval on the product page, with an optional subscribe-and-save discount, and manage '
        . 'their subscriptions from My Account. Renewals are emailed as a pay link that works with '
        . 'any payment module.'
        . $subsLinks,
    'pluginAuthor' => 'My Zen Cart Host (dbltoe)',
    // Plugins Library id. 0 until the Library assigns one (submissions are on
    // hold); it must be set before the first store installs from the Library.
    'pluginId' => 0,
    'zcVersions' => ['v158', 'v200', 'v210', 'v220', 'v230', 'v300'],
    'changelog' => 'changelog.txt',
    'github_repo' => $subsGithubUrl,
    'pluginGroups' => [],
];
