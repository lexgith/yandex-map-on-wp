<?php
/**
 * Внутренние функции плагина «Яндекс.Карта на WordPress».
 *
 * @package YandexMap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Имя группы настроек плагина.
 *
 * @return string
 */
function ymap_settings_name() {
	return 'yandex_map_on_wp_settings';
}

/**
 * Имя опции с импортированными метками.
 *
 * @return string
 */
function ymap_markers_option_name() {
	return 'yandex_map_on_wp_markers';
}

/**
 * Имя опции с метаданными исходного файла, загруженного при импорте меток.
 *
 * @return string
 */
function ymap_source_file_option_name() {
	return 'yandex_map_on_wp_markers_source_file';
}

/**
 * Каталог для хранения исходных файлов импорта.
 *
 * Файл хранится в отдельной папке внутри загруженных файлов WordPress, чтобы
 * его можно было скачать без изменений: кодировка, разделители и формат
 * остаются такими же, какими их загрузил пользователь.
 *
 * @return string Абсолютный путь к каталогу или пустая строка.
 */
function ymap_source_file_dir() {
	$uploads = wp_upload_dir();

	if ( ! is_array( $uploads ) || ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
		return '';
	}

	return trailingslashit( $uploads['basedir'] ) . 'yandex-map-on-wp';
}

/**
 * Возвращает метаданные исходного файла последнего успешного импорта.
 *
 * @return array|null Массив с ключами path, name, type, size, imported_at или null.
 */
function ymap_get_source_file() {
	$meta = get_option( ymap_source_file_option_name(), array() );

	if ( ! is_array( $meta ) || empty( $meta['path'] ) || empty( $meta['name'] ) ) {
		return null;
	}

	$dir  = ymap_source_file_dir();
	$path = realpath( (string) $meta['path'] );
	$base = '' !== $dir ? realpath( $dir ) : false;

	if ( false === $path || false === $base || 0 !== strpos( $path, trailingslashit( $base ) ) || ! is_readable( $path ) ) {
		delete_option( ymap_source_file_option_name() );
		return null;
	}

	$meta['path'] = $path;

	if ( empty( $meta['size'] ) ) {
		$meta['size'] = filesize( $path );
	}

	return $meta;
}

/**
 * Удаляет сохранённый исходный файл импорта и его метаданные.
 */
function ymap_delete_source_file() {
	$meta = get_option( ymap_source_file_option_name(), array() );

	delete_option( ymap_source_file_option_name() );

	if ( is_array( $meta ) && ! empty( $meta['path'] ) ) {
		$path = (string) $meta['path'];
		$dir  = ymap_source_file_dir();

		if ( '' !== $dir && 0 === strpos( $path, trailingslashit( $dir ) ) && is_file( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
}

/**
 * Сохраняет исходный загруженный файл, чтобы после импорта его можно было
 * скачать без изменений — в том же формате и с тем же содержимым.
 *
 * @param string $tmp_name      Временный путь загруженного файла.
 * @param string $original_name Исходное имя файла.
 *
 * @return array|false Метаданные сохранённого файла или false при ошибке.
 */
function ymap_store_source_file( $tmp_name, $original_name ) {
	$tmp_name = (string) $tmp_name;
	$dir      = ymap_source_file_dir();

	if ( '' === $dir || '' === $tmp_name || ! is_readable( $tmp_name ) ) {
		return false;
	}

	$ext = strtolower( (string) pathinfo( (string) $original_name, PATHINFO_EXTENSION ) );
	if ( ! in_array( $ext, array( 'xlsx', 'csv' ), true ) ) {
		return false;
	}

	if ( ! wp_mkdir_p( $dir ) ) {
		return false;
	}

	$name = sanitize_file_name( (string) $original_name );
	if ( '' === $name ) {
		$name = 'markers.' . $ext;
	}

	$stored_name = 'markers-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 8, false, false ) . '.' . $ext;
	$path        = trailingslashit( $dir ) . $stored_name;

	if ( ! @copy( $tmp_name, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return false;
	}

	$type = 'csv' === $ext
		? 'text/csv'
		: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

	$meta = array(
		'path'        => $path,
		'name'        => $name,
		'type'        => $type,
		'size'        => filesize( $path ),
		'imported_at' => gmdate( 'c' ),
	);

	$previous = get_option( ymap_source_file_option_name(), array() );

	update_option( ymap_source_file_option_name(), $meta, false );

	if ( is_array( $previous ) && ! empty( $previous['path'] ) ) {
		$previous_path = (string) $previous['path'];
		if ( $previous_path !== $path && 0 === strpos( $previous_path, trailingslashit( $dir ) ) && is_file( $previous_path ) ) {
			@unlink( $previous_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	return $meta;
}

/**
 * Значения по умолчанию для всех настроек плагина.
 *
 * @return array
 */
function ymap_default_settings() {
	return array(
		'api_key'       => '',
		'address'       => '',
		'latitude'      => 55.751244,
		'longitude'     => 37.618423,
		'zoom'          => 10,
		'height'        => 400,
		'width'         => '100%',
		'caption'       => '',
		'group_markers' => false,
	);
}

/**
 * Нормализует значение размера карты: допускаются «auto», процент (1–100)
 * или пиксели (50–4000). Некорректные значения заменяются значением по умолчанию.
 *
 * @param mixed  $value   Сырое значение из настроек/шорткода.
 * @param string $default Значение по умолчанию.
 *
 * @return string
 */
function ymap_normalize_size( $value, $default = '100%' ) {
	$value = trim( (string) $value );

	if ( '' !== $value && preg_match( '/^(\d{1,4})%$/', $value, $m ) ) {
		$percent = (int) $m[1];
		if ( $percent >= 1 && $percent <= 100 ) {
			return $percent . '%';
		}
	}

	if ( '' !== $value && preg_match( '/^(\d{1,5})px?$/i', $value, $m ) ) {
		$px = (int) $m[1];
		if ( $px >= 50 && $px <= 4000 ) {
			return $px . 'px';
		}
	}

	return (string) $default;
}

/**
 * Возвращает настройки плагина (массив), объединённые со значениями по умолчанию.
 * Устаревшие ключи, которых больше нет в defaults, отбрасываются.
 *
 * @return array
 */
function ymap_get_settings() {
	$saved    = get_option( ymap_settings_name(), array() );
	$defaults = ymap_default_settings();

	if ( ! is_array( $saved ) ) {
		$saved = array();
	}

	return array_merge( $defaults, $saved );
}

/**
 * Нормализует одну координату метки: строка/число приводятся к float,
 * допустимые диапазоны — широта -90..90, долгота -180..180.
 *
 * @param mixed $value Сырое значение координаты.
 * @param bool  $is_lat Это широта (true) или долгота (false).
 *
 * @return float|null Нормализованное значение или null, если координата некорректна.
 */
function ymap_normalize_coord( $value, $is_lat = true ) {
	if ( is_string( $value ) ) {
		$value = trim( $value );
		$value = str_replace( ',', '.', $value );
	}
	if ( ! is_numeric( $value ) ) {
		return null;
	}

	$coord = (float) $value;
	$limit = $is_lat ? 90 : 180;

	if ( $coord < -$limit || $coord > $limit ) {
		return null;
	}

	return $coord;
}

/**
 * Возвращает список импортированных меток.
 *
 * @return array Массив меток: каждая — массив с ключами title, lat, lng, hint, preset.
 */
function ymap_get_markers() {
	$markers = get_option( ymap_markers_option_name(), array() );

	if ( ! is_array( $markers ) ) {
		return array();
	}

	$clean = array();
	foreach ( $markers as $marker ) {
		if ( ! is_array( $marker ) ) {
			continue;
		}

		$lat = ymap_normalize_coord( isset( $marker['lat'] ) ? $marker['lat'] : 0.0, true );
		$lng = ymap_normalize_coord( isset( $marker['lng'] ) ? $marker['lng'] : 0.0, false );

		if ( null === $lat || null === $lng ) {
			continue;
		}

		$title  = ymap_utf8_text( isset( $marker['title'] ) ? $marker['title'] : '' );
		$hint   = ymap_utf8_text( isset( $marker['hint'] ) ? $marker['hint'] : '' );
		$preset = ymap_utf8_text( isset( $marker['preset'] ) ? $marker['preset'] : '' );

		$clean[] = array(
			'title' => function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 256 ) : substr( $title, 0, 256 ),
			'lat'   => $lat,
			'lng'   => $lng,
			'hint'  => function_exists( 'mb_substr' ) ? mb_substr( $hint, 0, 500 ) : substr( $hint, 0, 500 ),
			'preset' => function_exists( 'mb_substr' ) ? mb_substr( $preset, 0, 128 ) : substr( $preset, 0, 128 ),
		);
	}

	return $clean;
}

/**
 * Приводит произвольный текст метки к корректному UTF-8.
 *
 * На вход могут прийти данные, сохранённые старыми версиями плагина из
 * файлов в Windows-1251 (или другой однобайтовой кодировке). Такие строки
 * ломают json_encode при выводе данных карты в атрибут/скрипт, поэтому их
 * нужно перекодировать (или очистить от невалидных байтов) перед выводом.
 *
 * @param mixed $value Сырое значение.
 *
 * @return string
 */
function ymap_utf8_text( $value ) {
	$value = (string) $value;

	if ( '' === $value ) {
		return $value;
	}

	if ( function_exists( 'ymap_to_utf8' ) ) {
		$value = ymap_to_utf8( $value );
	}

	if ( function_exists( 'wp_check_invalid_utf8' ) ) {
		$value = wp_check_invalid_utf8( $value, true );
	}

	return $value;
}

/**
 * Сохраняет список меток, отбрасывая записи без координат.
 *
 * @param array $markers Массив меток.
 *
 * @return bool
 */
function ymap_save_markers( $markers ) {
	$markers = is_array( $markers ) ? $markers : array();

	$clean = array();
	foreach ( $markers as $marker ) {
		if ( ! is_array( $marker ) ) {
			continue;
		}

		$lat = ymap_normalize_coord( isset( $marker['lat'] ) ? $marker['lat'] : 0.0, true );
		$lng = ymap_normalize_coord( isset( $marker['lng'] ) ? $marker['lng'] : 0.0, false );

		if ( null === $lat || null === $lng ) {
			continue;
		}

		$clean[] = array(
			'title' => isset( $marker['title'] ) ? sanitize_text_field( $marker['title'] ) : '',
			'lat'   => $lat,
			'lng'   => $lng,
			'hint'  => isset( $marker['hint'] ) ? sanitize_text_field( $marker['hint'] ) : '',
			'preset' => isset( $marker['preset'] ) ? sanitize_text_field( $marker['preset'] ) : '',
		);
	}

	return update_option( ymap_markers_option_name(), $clean, false );
}

/**
 * Задан ли API-ключ Яндекс.Карт.
 *
 * @return bool
 */
function ymap_has_api_key() {
	$settings = ymap_get_settings();
	return ! empty( $settings['api_key'] ) && is_string( $settings['api_key'] );
}

/**
 * Экранирует атрибут для вывода в HTML, ограничивая его длину.
 *
 * @param mixed  $value   Сырое значение атрибута.
 * @param string $default Значение по умолчанию.
 * @param int    $maxlen  Максимальная длина строки.
 *
 * @return string
 */
function ymap_attr( $value, $default, $maxlen = 200 ) {
	$value  = (string) $value;
	$result = '' !== $value ? $value : (string) $default;
	if ( $maxlen > 0 ) {
		$result = function_exists( 'mb_substr' ) ? mb_substr( $result, 0, $maxlen ) : substr( $result, 0, $maxlen );
	}
	return esc_attr( $result );
}

/**
 * Уникальный суффикс для id и data-атрибутов карты.
 *
 * @return string
 */
function ymap_unique_id() {
	static $counter = 0;
	$counter++;
	return wp_generate_uuid4() . '-' . $counter;
}
