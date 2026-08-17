<?php
/**
 * Plugin Name: Ship Modal
 * Description: HTML・画像バナー・期間指定・表示頻度・計測に対応したモーダル管理プラグイン。
 * Version: 0.6.4
 * Author: Ship Inc.
 * License: GPL-2.0-or-later
 * Text Domain: ship-modal
 */

if (! defined('ABSPATH')) {
    exit;
}

define('SHIP_MODAL_VERSION', '0.6.4');
define('SHIP_MODAL_FILE', __FILE__);
define('SHIP_MODAL_DIR', plugin_dir_path(__FILE__));
define('SHIP_MODAL_URL', plugin_dir_url(__FILE__));
define('SHIP_MODAL_REPOSITORY', 'https://github.com/Avurora/ship-modal');

require_once SHIP_MODAL_DIR . 'includes/class-ship-modal.php';

Ship_Modal::instance();

// GitHubのmainブランチを参照する自動アップデータ。
if (file_exists(SHIP_MODAL_DIR . 'lib/plugin-update-checker/plugin-update-checker.php')) {
    require_once SHIP_MODAL_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';

    $ship_modal_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        SHIP_MODAL_REPOSITORY,
        SHIP_MODAL_FILE,
        'ship-modal'
    );
    $ship_modal_update_checker->setBranch('main');
}
