<?php
/**
 * Шорткод [yandex_map] плагина «Яндекс.Карта на WordPress».
 *
 * @package YandexMap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Данные, которые используются для отрисовки одной карты (или заглушки).
 *
 * @param array  $args     Массив аргументов шорткода.
 * @param string $map_id   Уникальный идентификатор карты на странице.
 *
 * @return array
 */
function ymap_map_data( $args, $map_id ) {
	$settings = ymap_get_settings();
	$zoom     = (int) ymap_attr( isset( $args['zoom'] ) ? $args['zoom'] : $settings['zoom'], $settings['zoom'], 2 );

	if ( $zoom < 0 ) {
		$zoom = (int) $settings['zoom'];
	}
	if ( $zoom > 19 ) {
		$zoom = 19;
	}

	$has_coords = ( isset( $args['latitude'] ) && '' !== (string) $args['latitude'] )
		|| ( isset( $args['longitude'] ) && '' !== (string) $args['longitude'] );
	$address    = '';
	if ( isset( $args['address'] ) && '' !== trim( (string) $args['address'] ) ) {
		$address = trim( (string) $args['address'] );
	} elseif ( ! $has_coords && '' !== trim( (string) $settings['address'] ) ) {
		$address = trim( (string) $settings['address'] );
	}

	$lat = isset( $args['latitude'] ) && '' !== (string) $args['latitude']
		? (float) $args['latitude'] : (float) $settings['latitude'];
	$lng = isset( $args['longitude'] ) && '' !== (string) $args['longitude']
		? (float) $args['longitude'] : (float) $settings['longitude'];

	if ( $lat < -90 || $lat > 90 ) {
		$lat = (float) $settings['latitude'];
	}
	if ( $lng < -180 || $lng > 180 ) {
		$lng = (float) $settings['longitude'];
	}

	$height = (int) ymap_attr( isset( $args['height'] ) ? $args['height'] : $settings['height'], $settings['height'], 4 );

	if ( $height < 100 ) {
		$height = (int) $settings['height'];
	}

	$width = ymap_normalize_size(
		isset( $args['width'] ) ? $args['width'] : '',
		isset( $settings['width'] ) ? $settings['width'] : '100%'
	);

	$caption = isset( $args['caption'] ) ? $args['caption'] : $settings['caption'];

	return array(
		'id'          => $map_id,
		'center'      => array( $lat, $lng ),
		'address'     => mb_substr( $address, 0, 256 ),
		'zoom'        => $zoom,
		'height'      => $height,
		'width'       => $width,
		'caption'     => mb_substr( trim( (string) $caption ), 0, 256 ),
		'api_key'     => trim( (string) $settings['api_key'] ),
		'markers'     => ymap_get_markers(),
		'group_markers' => ! empty( $settings['group_markers'] ),
	);
}

/**
 * HTML-заглушка на месте карты, когда API-ключ не задан.
 * Стили встраиваются инлайн, чтобы подсказка выглядела одинаково
 * на любой странице и не требовала подключения скриптов.
 *
 * @return string
 */
function ymap_no_key_notice() {
	$style = 'position:relative;width:100%;height:220px;box-sizing:border-box;'
		. 'display:flex;align-items:center;justify-content:center;'
		. 'color:#5b6270;background:#f5f6f8;border:1px dashed #c4c9d2;border-radius:8px;'
		. 'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;'
		. 'font-size:14px;line-height:1.45;text-align:center;padding:24px 20px;margin:0;';

	return '<div class="ymap ymap--empty" style="' . esc_attr( $style ) . '">' .
		'<p class="ymap__notice" style="margin:0">' . esc_html__( 'Яндекс.Карта ещё не настроена: укажите API-ключ в «Яндекс.Карта на WordPress».', 'yandex-map-on-wp' ) . '</p>' .
		'</div>';
}

/**
 * Безопасно кодирует данные карты в JSON для передачи на фронтенд.
 *
 * Проблема старой реализации: текст меток содержал HTML-сущности
 * (&quot;, &amp;, &laquo;, &#39; и т.п.), которые json_encode сохранял как есть,
 * а esc_attr()/браузер превращали обратно в символы (&, ", «), ломая JSON
 * внутри data-атрибута. Из-за этого JSON.parse() в assets/js/frontend.js падал,
 * и карта оставалась белым прямоугольником.
 *
 * Решение — экранировать все потенциально HTML-опасные байты уже в JSON
 * (флаги JSON_HEX_* превращают &, <, >, ", ' в \uXXXX), поэтому ни текст метки,
 * ни HTML-сущности в нём не могут обернуть скрипт или разметку. Бонус: символы
 * вне ASCII остаются читаемыми (JSON_UNESCAPED_UNICODE).
 *
 * @param array $map Данные карты из ymap_map_data().
 *
 * @return array Массив с ключами json и base64.
 */
function ymap_encode_map_data( $map ) {
	$flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

	$json = wp_json_encode( $map, $flags );

	/* Если в данных остался невалидный UTF-8, wp_json_encode вернёт false.
	 * Метки уже проходят через ymap_get_markers()/ymap_utf8_text(), но
	 * подстрахуемся и очистим данные ещё раз. */
	if ( false === $json ) {
		$map['markers'] = ymap_get_markers();
		$json = wp_json_encode( $map, $flags );
	}

	if ( false === $json || null === $json ) {
		$json = 'null';
	}

	return array(
		'json'   => $json,
		'base64' => base64_encode( $json ),
	);
}

/**
 * Отображает контейнер карты с данными для фронтенд-скрипта.
 *
 * Данные передаются двумя независимыми способами:
 *  - <script type="application/json" class="ymap-data"> внутри контейнера —
 *    основной, читается через textContent; не участвует в HTML-экранировании;
 *  - data-ymap-base64 — запасной, для скриптов/сценариев, где JSON-блок
 *    недоступен (например, после вставки контента через некоторые редакторы).
 *
 * Ни один текст метки не может оборвать разметку или скрипт: JSON кодируется
 * с JSON_HEX_* (все HTML-опасные символы превращаются в \uXXXX), а base64
 * не содержит символов, опасных для HTML-атрибута.
 *
 * @param array $map Данные карты из ymap_map_data().
 *
 * @return string
 */
function ymap_render_map( $map ) {
	$caption = '';
	if ( ! empty( $map['caption'] ) ) {
		$caption = '<p class="ymap__caption">' . esc_html( $map['caption'] ) . '</p>';
	}

	$width = isset( $map['width'] ) ? (string) $map['width'] : '100%';
	$style = 'height:' . esc_attr( (string) $map['height'] ) . 'px;width:' . esc_attr( $width ) . ';';

	$encoded = ymap_encode_map_data( $map );

	return $caption .
		'<div id="' . esc_attr( $map['id'] ) . '" class="ymap"'
		. ' data-ymap-base64="' . esc_attr( $encoded['base64'] ) . '"'
		. ' style="' . $style . '">'
		. '<script type="application/json" class="ymap-data">' . $encoded['json'] . '</script>'
		. '</div>';
}

/**
 * Разбор аргументов шорткода: координаты в строках приводятся к числам,
 * а параметр coords="55.75, 37.62" раскладывается на latitude/longitude.
 *
 * @param array|string $args Аргументы шорткода (массив) или атрибуты в строке.
 *
 * @return array
 */
function ymap_normalize_args( $args ) {
	$args = is_array( $args ) ? $args : array();

	foreach ( array( 'latitude', 'longitude' ) as $coord ) {
		if ( array_key_exists( $coord, $args ) && is_string( $args[ $coord ] ) ) {
			$args[ $coord ] = str_replace( ',', '.', $args[ $coord ] );
		}
	}

	if ( isset( $args['coords'] ) && is_string( $args['coords'] ) ) {
		$parts = array_map( 'trim', explode( ',', $args['coords'] ) );
		if ( count( $parts ) === 2 && '' !== $parts[0] && '' !== $parts[1] ) {
			$args['latitude']  = str_replace( ',', '.', $parts[0] );
			$args['longitude'] = str_replace( ',', '.', $parts[1] );
		} elseif ( count( $parts ) === 4 ) {
			$args['latitude']  = $parts[0] . '.' . $parts[1];
			$args['longitude'] = $parts[2] . '.' . $parts[3];
		}
		unset( $args['coords'] );
	}

	if ( isset( $args['title'] ) && ! isset( $args['caption'] ) ) {
		$args['caption'] = $args['title'];
	}
	unset( $args['title'] );

	return $args;
}

/**
 * Даёт отрисовать shortcode на странице.
 *
 * @param array|string $args    Аргументы шорткода.
 * @param string       $content Текст внутри шорткода (опционально).
 *
 * @return string
 */
function ymap_shortcode( $args, $content ) {
	$args = ymap_normalize_args( $args );

	if ( ! ymap_has_api_key() ) {
		return ymap_no_key_notice();
	}

	$map_id = 'ymap-' . ymap_unique_id();
	$html   = ymap_render_map( ymap_map_data( $args, $map_id ) );

	ymap_enqueue_assets();

	return $html;
}
add_shortcode( 'yandex_map', 'ymap_shortcode' );
