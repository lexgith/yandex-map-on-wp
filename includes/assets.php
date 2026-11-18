<?php
/**
 * Подключение CSS и JS для шорткода [yandex_map] только там, где он есть.
 *
 * @package YandexMap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Сколько карт встречается на странице.
 *
 * @var int
 */
$GLOBALS['ymap_instance_count'] = 0;

/**
 * Были ли ассеты подключены в head через wp_enqueue_scripts.
 *
 * @var bool
 */
$GLOBALS['ymap_assets_loaded'] = false;

/**
 * Есть ли на странице хотя бы одна отрисованная карта.
 *
 * @return bool
 */
function ymap_has_instances() {
	return isset( $GLOBALS['ymap_instance_count'] ) && (int) $GLOBALS['ymap_instance_count'] > 0;
}

/**
 * Отмечает, что на странице отрисована карта.
 */
function ymap_register_instance() {
	if ( ! isset( $GLOBALS['ymap_instance_count'] ) ) {
		$GLOBALS['ymap_instance_count'] = 0;
	}
	$GLOBALS['ymap_instance_count']++;
}

/**
 * Есть ли шорткод [yandex_map] в контенте текущего поста/страницы.
 *
 * @return bool
 */
function ymap_has_shortcode_in_post() {
	if ( is_singular() ) {
		$post = get_post();
		if ( $post instanceof WP_Post && has_shortcode( $post->post_content, 'yandex_map' ) ) {
			return true;
		}
	}

	$queried = get_queried_object();
	if ( $queried instanceof WP_Post && has_shortcode( $queried->post_content, 'yandex_map' ) ) {
		return true;
	}

	return false;
}

/**
 * Подключён ли хотя бы один виджет «Яндекс.Карта» в активных сайдбарах.
 *
 * @return bool
 */
function ymap_has_widget_in_sidebars() {
	foreach ( (array) wp_get_sidebars_widgets() as $widgets ) {
		if ( ! is_array( $widgets ) ) {
			continue;
		}
		foreach ( $widgets as $widget_id ) {
			if ( 0 === strpos( (string) $widget_id, 'yandex-map-on-wp-widget-' ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Подключает файлы только на страницах, где есть шорткод и задан API-ключ.
 */
function ymap_init_assets() {
	if ( ! ymap_has_shortcode_in_post() && ! ymap_has_widget_in_sidebars() ) {
		return;
	}

	if ( ! ymap_has_api_key() ) {
		return;
	}

	if ( ymap_has_widget_in_sidebars() ) {
		ymap_register_instance();
	}
	$GLOBALS['ymap_assets_loaded'] = true;

	wp_register_style( 'ymap-style', YMAP_PLUGIN_URL . 'assets/css/map.css', array(), YMAP_PLUGIN_VERSION );
	wp_enqueue_style( 'ymap-style' );

	wp_register_script(
		'ymap-frontend',
		YMAP_PLUGIN_URL . 'assets/js/frontend.js',
		array(),
		YMAP_PLUGIN_VERSION,
		true
	);
	wp_enqueue_script( 'ymap-frontend' );
}
add_action( 'wp_enqueue_scripts', 'ymap_init_assets' );

/**
 * Вызывается при реальном рендере шорткода. Страхует случаи, когда карта
 * добавлена вне основного контента (виджеты, блоки сайта) и не попала
 * в раннее обнаружение.
 */
function ymap_enqueue_assets() {
	ymap_register_instance();
}

/**
 * Выводит API-скрипт Яндекс.Карт и (при необходимости) файлы плагина,
 * которые не успели подключиться в head. Всё — только на страницах с картой,
 * чтобы не тормозить остальные.
 */
function ymap_print_footer_assets() {
	if ( ! ymap_has_instances() ) {
		return;
	}

	$settings = ymap_get_settings();
	$url      = add_query_arg(
		array(
			'lang'       => 'ru_RU',
			'apikey'     => rawurlencode( $settings['api_key'] ),
			'coordorder' => 'latlong',
		),
		'https://api-maps.yandex.ru/2.1/'
	);

	if ( empty( $GLOBALS['ymap_assets_loaded'] ) ) {
		printf(
			'<link rel="stylesheet" id="ymap-style-late" href="%s">',
			esc_url( YMAP_PLUGIN_URL . 'assets/css/map.css' )
		);
		printf(
			'<script id="ymap-frontend-late" src="%s"></script>',
			esc_url( YMAP_PLUGIN_URL . 'assets/js/frontend.js' )
		);
	}

	printf( '<script src="%s" defer></script>', esc_url( $url ) );
}
add_action( 'wp_footer', 'ymap_print_footer_assets', 1 );
