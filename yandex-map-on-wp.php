<?php
/**
 * Plugin Name:       Яндекс.Карта на WordPress
 * Plugin URI:        https://example.com/plugins/yandex-map-on-wp/
 * Description:       Простая вставка Яндекс.Карты через шорткод [yandex_map], а также импорт меток из XLSX и CSV.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Алексей Клёсов
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       yandex-map-on-wp
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'YMAP_PLUGIN_VERSION', '1.0.0' );
define( 'YMAP_PLUGIN_FILE', __FILE__ );
define( 'YMAP_PLUGIN_DIR', __DIR__ );
define( 'YMAP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/shortcode.php';
require_once __DIR__ . '/includes/widget.php';
require_once __DIR__ . '/includes/assets.php';
require_once __DIR__ . '/includes/importer.php';
