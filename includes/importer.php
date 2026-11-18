<?php
/**
 * Импорт меток из XLSX-файла в формате Яндекс.Конструктора карт.
 *
 * @package YandexMap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Кодировки, которые могут встретиться в файлах, выгруженных из
 * Яндекс.Конструктора карт (Windows-1251 и её варианты, KOI8-R, ISO-8859-5).
 * Приоритет отдан UTF-8, чтобы корректные файлы не перекодировались.
 *
 * @var array
 */
$GLOBALS['ymap_supported_encodings'] = array(
	'UTF-8',
	'Windows-1251',
	'CP1251',
	'KOI8-R',
	'ISO-8859-5',
);

/**
 * Приводит строку к корректному UTF-8.
 *
 * XLSX/CSV, выгруженные из Яндекс.Конструктора, могут содержать текст
 * в Windows-1251 (или другой однобайтовой кодировке) без объявления кодировки.
 * Такая строка не является валидным UTF-8 и ломает json_encode, поэтому её
 * нужно перекодировать до сохранения меток. Функция определяет фактическую
 * кодировку по байтам и возвращает строку в UTF-8.
 *
 * @param string $value Сырое значение ячейки.
 *
 * @return string Строка в UTF-8.
 */
function ymap_to_utf8( $value ) {
	$value = (string) $value;

	if ( '' === $value ) {
		return $value;
	}

	if ( function_exists( 'mb_check_encoding' ) && mb_check_encoding( $value, 'UTF-8' ) ) {
		return $value;
	}

	if ( ! function_exists( 'mb_convert_encoding' ) ) {
		return $value;
	}

	/* Пробуем определить кодировку, отдавая предпочтение Windows-1251.
	 * mb_detect_encoding с приоритетом доверяет только уверенным совпадениям. */
	$detected = mb_detect_encoding( $value, $GLOBALS['ymap_supported_encodings'], true );

	if ( false === $detected || 'UTF-8' === $detected ) {
		return $value;
	}

	$converted = @mb_convert_encoding( $value, 'UTF-8', $detected );

	if ( is_string( $converted ) && mb_check_encoding( $converted, 'UTF-8' ) ) {
		return $converted;
	}

	return $value;
}

/**
 * Имена возможных колонок XLSX-файла, соответствующие формату Яндекса.
 *
 * @var array
 */
$GLOBALS['ymap_known_headers'] = array(
	'Название'    => 'title',
	'Наименование' => 'title',
	'Имя'         => 'title',
	'Название точки' => 'title',
	'Название объекта' => 'title',
	'Адрес'       => 'hint',
	'Описание'    => 'hint',
	'Комментарий' => 'hint',
	'Дополнительная информация' => 'hint',
	'Широта'      => 'lat',
	'Latitude'    => 'lat',
	'lat'         => 'lat',
	'Долгота'     => 'lng',
	'Longitude'   => 'lng',
	'lng'         => 'lng',
	'lon'         => 'lng',
	'Долгота (Lon)' => 'lng',
	'Координаты'  => 'coords',
	'Подпись'     => 'label',
	'Номер метки' => 'marker_id',
	'№'           => 'marker_id',
	'preset'      => 'preset',
);

/**
 * Нормализует заголовок колонки: в нижний регистр, без лишних пробелов.
 *
 * @param string $header Заголовок колонки.
 *
 * @return string
 */
function ymap_normalize_header( $header ) {
	$header = trim( (string) $header );
	$header = function_exists( 'mb_strtolower' ) ? mb_strtolower( $header ) : strtolower( $header );
	$header = preg_replace( '/[\r\n\t]+/', ' ', $header );
	$header = preg_replace( '/\s{2,}/', ' ', $header );
	return trim( $header );
}

/**
 * Сопоставляет заголовок колонки с внутренним полем метки.
 *
 * @param string $header Заголовок колонки.
 *
 * @return string|null
 */
function ymap_map_header( $header ) {
	$header = ymap_normalize_header( $header );

	static $cache = array();
	static $normalized = null;

	if ( null === $normalized ) {
		$normalized = array();
		foreach ( $GLOBALS['ymap_known_headers'] as $known => $field ) {
			$normalized[ ymap_normalize_header( $known ) ] = $field;
		}
	}

	if ( isset( $cache[ $header ] ) ) {
		return $cache[ $header ];
	}

	if ( isset( $normalized[ $header ] ) ) {
		$cache[ $header ] = $normalized[ $header ];
		return $cache[ $header ];
	}

	$result = null;
	foreach ( $normalized as $needle => $field ) {
		if ( '' !== $needle && false !== strpos( $header, $needle ) ) {
			$result = $field;
			break;
		}
	}

	$cache[ $header ] = $result;
	return $result;
}

/**
 * Извлекает записи xl/sharedStrings.xml, xl/workbook.xml,
 * xl/_rels/workbook.xml.rels и xl/worksheets/*.xml из XLSX-архива.
 *
 * @param string $path Путь к XLSX-файлу.
 *
 * @return array|null Массив с ключами strings, workbook, rels, sheets или null при ошибке.
 */
function ymap_read_xlsx_entries( $path ) {
	$entries = array(
		'strings'  => null,
		'workbook' => null,
		'rels'     => null,
		'sheets'   => array(),
	);

	if ( class_exists( 'ZipArchive' ) ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return null;
		}

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );

			if ( 'xl/sharedStrings.xml' === $name ) {
				$entries['strings'] = $zip->getFromIndex( $i );
			} elseif ( 'xl/workbook.xml' === $name ) {
				$entries['workbook'] = $zip->getFromIndex( $i );
			} elseif ( 'xl/_rels/workbook.xml.rels' === $name ) {
				$entries['rels'] = $zip->getFromIndex( $i );
			} elseif ( preg_match( '#^xl/worksheets/.+\.xml$#', $name ) ) {
				$entries['sheets'][ $name ] = $zip->getFromIndex( $i );
			}
		}

		$zip->close();
		return $entries;
	}

	$zip = zip_open( $path );
	if ( ! is_resource( $zip ) ) {
		return null;
	}

	while ( ( $entry = zip_read( $zip ) ) !== false ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
		$name = zip_entry_name( $entry );

		if ( 'xl/sharedStrings.xml' === $name ) {
			$entries['strings'] = zip_entry_read( $entry, zip_entry_filesize( $entry ) );
		} elseif ( 'xl/workbook.xml' === $name ) {
			$entries['workbook'] = zip_entry_read( $entry, zip_entry_filesize( $entry ) );
		} elseif ( 'xl/_rels/workbook.xml.rels' === $name ) {
			$entries['rels'] = zip_entry_read( $entry, zip_entry_filesize( $entry ) );
		} elseif ( preg_match( '#^xl/worksheets/.+\.xml$#', $name ) ) {
			$entries['sheets'][ $name ] = zip_entry_read( $entry, zip_entry_filesize( $entry ) );
		}
	}
	zip_close( $zip );

	return $entries;
}

/**
 * Возвращает XML первой рабочей книги по данным workbook.xml/rels.
 *
 * @param array $entries Результат ymap_read_xlsx_entries().
 *
 * @return string|null
 */
function ymap_first_sheet_xml( $entries ) {
	if ( empty( $entries['sheets'] ) ) {
		return null;
	}

	$workbook = isset( $entries['workbook'] ) ? (string) $entries['workbook'] : '';
	$rels     = isset( $entries['rels'] ) ? (string) $entries['rels'] : '';

	/* По умолчанию берём любой worksheet-файл. */
	$sheets = $entries['sheets'];
	$target = null;

	if ( '' !== $workbook && '' !== $rels
		&& preg_match( '#<sheet\b[^>]*r:id="rId(\d+)"[^>]*/>#', $workbook, $sm )
		&& preg_match( '#Id="[^"]*rId' . preg_quote( $sm[1], '#' ) . '"[^>]*Target="([^"]+\.xml)"#s', $rels, $tm )
	) {
		$target = trim( $tm[1] );
		if ( 0 === strpos( $target, '/xl/' ) ) {
			$target = ltrim( $target, '/' );
		} elseif ( 0 !== strpos( $target, 'xl/' ) ) {
			$target = 'xl/' . ltrim( $target, '/' );
		}
	}

	if ( null !== $target ) {
		foreach ( $sheets as $name => $xml ) {
			if ( $name === $target ) {
				return $xml;
			}
		}
	}

	if ( ! empty( $sheets ) ) {
		return reset( $sheets );
	}

	return null;
}

/**
 * Извлекает значение одной ячейки XLSX.
 *
 * Поддерживаются числовые значения (<v>), общие строки sharedStrings (t="s"),
 * а также inline-строки (t="inlineStr") с обычным и rich-text содержимым (<is><t>),
 * которые используют файлы, выгруженные из Яндекс.Конструктора карт.
 *
 * @param string $cell_xml  XML фрагмент ячейки (<c ...>...</c>), уже с парным закрывающим тегом.
 * @param array  $shared    Словарь общих строк по индексам.
 *
 * @return string
 */
function ymap_cell_value( $cell_xml, $shared ) {
	$attrs = '';
	$body  = '';

	if ( preg_match( '#^<c\b([^>]*)>(.*)</c>$#s', $cell_xml, $sm ) ) {
		$attrs = $sm[1];
		$body  = $sm[2];
	}

	$type = '';
	if ( preg_match( '#\bt="([^"]+)"#', $attrs, $tm ) ) {
		$type = $tm[1];
	}

	if ( 'inlineStr' === $type && preg_match( '#<is>(.*?)</is>#s', $body, $im ) ) {
		return trim( ymap_xml_text( $im[1] ) );
	}

	if ( 's' === $type ) {
		if ( preg_match( '#<v>([^<]*)</v>#s', $body, $vm ) ) {
			$idx = (int) $vm[1];
			return isset( $shared[ $idx ] ) ? trim( (string) $shared[ $idx ] ) : '';
		}
		if ( preg_match( '#<t>([^<]*)</t>#s', $body, $vm ) ) {
			return trim( html_entity_decode( $vm[1], ENT_QUOTES | ENT_XML1, 'UTF-8' ) );
		}
		return '';
	}

	if ( preg_match( '#<v>([^<]*)</v>#s', $body, $vm ) ) {
		return trim( (string) $vm[1] );
	}

	return trim( ymap_xml_text( $body ) );
}

/**
 * Читает строки листа XLSX-файла без внешних библиотек.
 *
 * @param string $path Путь к XLSX-файлу.
 *
 * @return array|null Массив строк (каждая строка — массив ячеек) или null при ошибке.
 */
function ymap_read_xlsx( $path ) {
	$entries = ymap_read_xlsx_entries( $path );

	if ( null === $entries ) {
		return null;
	}

	$sheet_xml = ymap_first_sheet_xml( $entries );
	if ( null === $sheet_xml || '' === $sheet_xml ) {
		return null;
	}

	/* Разбираем sharedStrings.xml (если он есть) и собираем словарь по индексам. */
	$shared = array();
	if ( ! empty( $entries['strings'] ) && preg_match_all( '#<si>(.*?)</si>#s', $entries['strings'], $matches ) ) {
		foreach ( $matches[1] as $si ) {
			$shared[] = ymap_xml_text( $si );
		}
	}

	$rows      = array();
	$sheet_pat = '#<row\b[^>]*>(.*?)</row>#s';

	if ( ! preg_match_all( $sheet_pat, $sheet_xml, $row_matches ) ) {
		return null;
	}

	foreach ( $row_matches[1] as $row_xml ) {
		/* Приводим самозакрытые ячейки <c ... /> к парной форме, чтобы их можно было прочитать. */
		$row_xml = preg_replace( '#<c\b([^>]*?)/>#s', '<c$1></c>', $row_xml );

		if ( ! preg_match_all( '#<c\b[^>]*>.*?</c>#s', $row_xml, $cell_matches, PREG_SET_ORDER ) ) {
			continue;
		}

		$cols      = array();
		$max       = -1;
		$col_cursor = 0;

		foreach ( $cell_matches as $cm ) {
			$cell_xml = $cm[0];

			$col_index = $col_cursor;
			if ( preg_match( '#\br="([A-Z]+)\d+"#i', $cell_xml, $am ) ) {
				$col_index  = ymap_column_index( $am[1] );
				$col_cursor = $col_index + 1;
			} else {
				$col_cursor++;
			}

			$value = ymap_cell_value( $cell_xml, $shared );
			$value = ymap_to_utf8( $value );

			$cols[ $col_index ] = trim( (string) $value );
			if ( $col_index > $max ) {
				$max = $col_index;
			}
		}

		$row = array();
		for ( $i = 0; $i <= $max; $i++ ) {
			$row[] = isset( $cols[ $i ] ) ? $cols[ $i ] : '';
		}

		$rows[] = $row;
	}

	return $rows;
}

/**
 * Извлекает текстовое содержимое фрагмента XML (без тегов).
 *
 * @param string $xml Фрагмент XML.
 *
 * @return string
 */
function ymap_xml_text( $xml ) {
	$text = preg_replace( '#</t>#', '</t>|', $xml );
	$text = preg_replace( '#<t[^>]*>#', '', $text );
	$text = str_replace( '</t>', '', $text );
	$text = preg_replace( '#<[^>]+>#', '', $text );
	$text = str_replace( '|', '', $text );
	return html_entity_decode( $text, ENT_QUOTES | ENT_XML1, 'UTF-8' );
}

/**
 * Номер колонки по её буквенному имени (A → 0, B → 1, ..., AA → 26).
 *
 * @param string $letters Буквенное имя колонки.
 *
 * @return int
 */
function ymap_column_index( $letters ) {
	$letters = strtoupper( $letters );
	$index   = 0;
	$len     = strlen( $letters );

	for ( $i = 0; $i < $len; $i++ ) {
		$index = $index * 26 + ( ord( $letters[ $i ] ) - ord( 'A' ) + 1 );
	}

	return $index - 1;
}

/**
 * Преобразует число в координату.
 *
 * Понимает значение как обычное число с плавающей точкой, строку с запятой
 * (десятичный разделитель) и запись вида «55°45′» / «55 45», где минуты —
 * часть градусов. Возвращает null, если значение не является числом
 * или выходит за диапазон возможной координаты (-90..90, -180..180).
 *
 * @param mixed $value Сырое значение ячейки.
 *
 * @return float|null
 */
function ymap_to_coord( $value ) {
	$value = trim( (string) $value );
	$value = str_replace( array( '°', '′', '″' ), array( '', '', '' ), $value );

	/* Строка с несколькими числами: «55 45» -> та же чувствительность, что и у числа ниже. */
	$numeric = str_replace( ' ', '.', $value );
	if ( is_numeric( $numeric ) ) {
		$coord = (float) $numeric;
		if ( $coord < -180 || $coord > 180 ) {
			return null;
		}
		return $coord;
	}

	/* «55,45» -> «55.45»; без пробелов, чтобы не сломать «55.755814». */
	if ( false !== strpos( $value, ',' ) ) {
		$pieces = explode( ',', $value );
		if ( count( $pieces ) >= 2 ) {
			$deg = isset( $pieces[0] ) ? (float) $pieces[0] : 0.0;
			if ( isset( $pieces[1] ) && '' !== $pieces[1] ) {
				$deg += (float) ( '0.' . $pieces[1] );
			}
			if ( $deg < -180 || $deg > 180 ) {
				return null;
			}
			return $deg;
		}
	}

	return null;
}

/**
 * Разбирает строку XLSX в метку.
 *
 * @param array $headers Сопоставление «индекс колонки → поле».
 * @param array $values  Значения ячеек строки.
 *
 * @return array|null
 */
function ymap_row_to_marker( $headers, $values ) {
	$title     = '';
	$hint      = '';
	$label     = '';
	$marker_id = '';
	$preset    = '';
	$lat       = null;
	$lng       = null;

	foreach ( $headers as $index => $field ) {
		$value = isset( $values[ $index ] ) ? trim( (string) $values[ $index ] ) : '';
		if ( '' === $value ) {
			continue;
		}

		switch ( $field ) {
			case 'title':
				if ( '' === $title ) {
					$title = $value;
				}
				break;
			case 'hint':
				if ( '' === $hint ) {
					$hint = $value;
				}
				break;
			case 'label':
				if ( '' === $label ) {
					$label = $value;
				}
				break;
			case 'marker_id':
				if ( '' === $marker_id ) {
					$marker_id = $value;
				}
				break;
			case 'preset':
				if ( '' === $preset ) {
					$preset = $value;
				}
				break;
			case 'coords':
				$parts = array_map( 'trim', explode( ',', $value ) );
				if ( count( $parts ) >= 2 ) {
					$coord_lat = ymap_to_coord( $parts[0] );
					$coord_lng = ymap_to_coord( $parts[1] );

					if ( null === $lat && null !== $coord_lat ) {
						$lat = $coord_lat;
					}
					if ( null === $lng && null !== $coord_lng ) {
						$lng = $coord_lng;
					}
				}
				break;
			case 'lat':
				if ( null === $lat ) {
					$lat = ymap_to_coord( $value );
				}
				break;
			case 'lng':
				if ( null === $lng ) {
					$lng = ymap_to_coord( $value );
				}
				break;
		}
	}

	if ( null === $lat || null === $lng ) {
		return null;
	}

	if ( '' === $title && '' !== $label ) {
		$title = $label;
	}

	return array(
		'title'     => $title,
		'lat'       => $lat,
		'lng'       => $lng,
		'hint'      => $hint,
		'label'     => $label,
		'marker_id' => $marker_id,
		'preset'    => $preset,
	);
}

/**
 * Превращает таблицу строк (первая строка — заголовки) в массив меток.
 *
 * Заголовки сопоставляются по тексту, а если текст повреждён (например,
 * заголовок в Windows-1251 превратился в «кракозябры» из-за неверной
 * перекодировки), применяется запасной вариант — сопоставление по позиции
 * колонки в формате экспорта Яндекс.Конструктора карт:
 *   колонка 0 = Широта, 1 = Долгота, 2 = Описание, 3 = Подпись,
 *   4 = Номер метки, 5 = preset.
 *
 * @param array $rows Массив строк; каждая строка — массив значений ячеек.
 *
 * @return array|WP_Error Массив меток или ошибка.
 */
function ymap_rows_to_markers( $rows ) {
	if ( ! is_array( $rows ) || count( $rows ) < 2 ) {
		return new WP_Error( 'ymap_empty_file', __( 'Не удалось прочитать файл: он пуст или имеет неподдерживаемый формат.', 'yandex-map-on-wp' ) );
	}

	$headers    = array();
	$fields     = array();
	foreach ( $rows[0] as $index => $cell ) {
		$field = ymap_map_header( $cell );
		if ( null !== $field ) {
			$headers[ $index ] = $field;
			$fields[ $field ]  = true;
		}
	}

	$has_lat    = ! empty( $fields['lat'] );
	$has_lng    = ! empty( $fields['lng'] );
	$has_coords = ! empty( $fields['coords'] );

	if ( ( ! $has_lat || ! $has_lng ) && ! $has_coords ) {
		/* Заголовки не распознаны по тексту. Пробуем привязаться к позиции
		 * колонок формата Яндекс.Конструктора: 0=Широта, 1=Долгота, ...
		 * Такое бывает, если заголовки в Windows-1251 и после перекодировки
		 * превратились в символы замены. */
		$positional = array( 0 => 'lat', 1 => 'lng', 2 => 'hint', 3 => 'label', 4 => 'marker_id', 5 => 'preset' );
		$headers    = array();
		$fields     = array();
		foreach ( $rows[0] as $index => $cell ) {
			if ( isset( $positional[ $index ] ) ) {
				$headers[ $index ] = $positional[ $index ];
				$fields[ $positional[ $index ] ] = true;
			}
		}
		$has_lat    = ! empty( $fields['lat'] );
		$has_lng    = ! empty( $fields['lng'] );
		$has_coords = ! empty( $fields['coords'] );
	}

	if ( ( ! $has_lat || ! $has_lng ) && ! $has_coords ) {
		return new WP_Error(
			'ymap_missing_coords',
			__( 'В файле не найдены колонки с координатами (широта и долгота или «Координаты»).', 'yandex-map-on-wp' )
		);
	}

	$markers = array();
	for ( $i = 1, $count = count( $rows ); $i < $count; $i++ ) {
		$row = $rows[ $i ];
		if ( ! is_array( $row ) ) {
			continue;
		}
		/* Дополняем короткие строки пустыми ячейками, чтобы индексы колонок
		 * формата Конструктора всегда существовали. */
		for ( $k = count( $row ); $k <= 5; $k++ ) {
			$row[ $k ] = '';
		}
		$marker = ymap_row_to_marker( $headers, $row );
		if ( null !== $marker ) {
			$markers[] = $marker;
		}
	}

	if ( empty( $markers ) ) {
		return new WP_Error(
			'ymap_no_markers',
			__( 'В файле не найдено ни одной строки с корректными координатами.', 'yandex-map-on-wp' )
		);
	}

	return $markers;
}

/**
 * Разбирает XLSX-файл в массив меток.
 *
 * @param string $path Путь к загруженному XLSX-файлу.
 *
 * @return array|WP_Error Массив меток или ошибка.
 */
function ymap_parse_markers_file( $path ) {
	$rows = ymap_read_xlsx( $path );

	if ( null === $rows || empty( $rows ) ) {
		return new WP_Error( 'ymap_empty_file', __( 'Не удалось прочитать файл: он пуст или имеет неподдерживаемый формат.', 'yandex-map-on-wp' ) );
	}

	return ymap_rows_to_markers( $rows );
}

/**
 * Разбирает CSV-файл (разделитель «;», кавычки с ""-экранированием) в метки.
 *
 * Формат — как у файла, выгруженного из Яндекс.Конструктора карт:
 * заголовки Широта;Долгота;Описание;Подпись;Номер метки;preset.
 * Файл может быть в Windows-1251 (кириллица), поэтому текст приводится к UTF-8.
 *
 * @param string $path Путь к загруженному CSV-файлу.
 *
 * @return array|WP_Error Массив меток или ошибка.
 */
function ymap_parse_markers_csv( $path ) {
	if ( ! is_readable( $path ) ) {
		return new WP_Error( 'ymap_empty_file', __( 'Не удалось прочитать файл: он пуст или имеет неподдерживаемый формат.', 'yandex-map-on-wp' ) );
	}

	$contents = file_get_contents( $path );
	if ( false === $contents || '' === $contents ) {
		return new WP_Error( 'ymap_empty_file', __( 'Не удалось прочитать файл: он пуст или имеет неподдерживаемый формат.', 'yandex-map-on-wp' ) );
	}

	/* Приводим байты к UTF-8: если файл в Windows-1251 (или другой
	 * однобайтовой кодировке) — перекодируем, чтобы кириллица
	 * не превращалась в «кракозябры». */
	$contents = ymap_to_utf8( $contents );

	/* Отбрасываем BOM и переводим переносы строк к LF. */
	$contents = preg_replace( '/^\xEF\xBB\xBF/', '', $contents );
	$contents = str_replace( "\r\n", "\n", $contents );
	$contents = str_replace( "\r", "\n", $contents );

	$rows     = array();
	$row      = array();
	$field    = '';
	$in_quote = false;
	$len      = strlen( $contents );

	for ( $i = 0; $i < $len; $i++ ) {
		$ch = $contents[ $i ];

		if ( $in_quote ) {
			if ( '"' === $ch ) {
				if ( isset( $contents[ $i + 1 ] ) && '"' === $contents[ $i + 1 ] ) {
					$field  .= '"';
					$i++;
					continue;
				}
				$in_quote = false;
				continue;
			}
			$field .= $ch;
			continue;
		}

		if ( '"' === $ch ) {
			$in_quote = true;
			continue;
		}

		if ( ';' === $ch ) {
			$row[]  = $field;
			$field  = '';
			continue;
		}

		if ( "\n" === $ch ) {
			$row[] = $field;
			$rows[] = array_map( 'trim', $row );
			$row   = array();
			$field = '';
			continue;
		}

		$field .= $ch;
	}

	if ( '' !== $field || ! empty( $row ) ) {
		$row[] = $field;
		$rows[] = array_map( 'trim', $row );
	}

	if ( empty( $rows ) ) {
		return new WP_Error( 'ymap_empty_file', __( 'Не удалось прочитать файл: он пуст или имеет неподдерживаемый формат.', 'yandex-map-on-wp' ) );
	}

	return ymap_rows_to_markers( $rows );
}

/**
 * Сохраняет сообщение об ошибке импорта в транзиент и делает редирект
 * обратно на страницу плагина.
 *
 * @param string $message Текст сообщения.
 */
function ymap_import_fail( $message ) {
	set_transient( 'ymap_import_feedback', array( 'type' => 'error', 'message' => $message ), 60 );
	wp_safe_redirect( add_query_arg( 'ymap_import', '1', admin_url( 'admin.php?page=yandex-map-on-wp' ) ) );
	exit;
}

/**
 * Обработчик загрузки XLSX-файла с метками.
 */
function ymap_handle_markers_import() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Недостаточно прав.', 'yandex-map-on-wp' ) );
	}

	if ( empty( $_POST['ymap_import_nonce'] ) || empty( $_FILES['ymap_markers_file'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		wp_die( esc_html__( 'Форма импорта не заполнена.', 'yandex-map-on-wp' ) );
	}

	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ymap_import_nonce'] ) ), 'ymap_import_markers' ) ) {
		wp_die( esc_html__( 'Ошибка безопасности: недействительный nonce.', 'yandex-map-on-wp' ) );
	}

	$file = $_FILES['ymap_markers_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

	if ( ! isset( $file['error'], $file['tmp_name'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
		ymap_import_fail( __( 'Не удалось загрузить файл. Проверьте, что выбран XLSX-или CSV-файл.', 'yandex-map-on-wp' ) );
		return;
	}

	$tmp_name = $file['tmp_name'];
	if ( ! is_uploaded_file( $tmp_name ) ) {
		ymap_import_fail( __( 'Файл не был загружен через форму.', 'yandex-map-on-wp' ) );
		return;
	}

	$pathinfo = function_exists( 'pathinfo' ) ? pathinfo( $file['name'] ) : array();
	$ext      = isset( $pathinfo['extension'] ) ? strtolower( (string) $pathinfo['extension'] ) : '';

	if ( 'csv' === $ext ) {
		$markers = ymap_parse_markers_csv( $tmp_name );
	} elseif ( 'xlsx' === $ext ) {
		if ( ! class_exists( 'ZipArchive' ) && ! function_exists( 'zip_open' ) ) {
			ymap_import_fail( __( 'На сервере не установлено расширение Zip, необходимое для чтения XLSX.', 'yandex-map-on-wp' ) );
			return;
		}
		$markers = ymap_parse_markers_file( $tmp_name );
	} else {
		ymap_import_fail( __( 'Разрешены только XLSX-файл (Excel) и CSV-файл с разделителем «;».', 'yandex-map-on-wp' ) );
		return;
	}

	if ( is_wp_error( $markers ) ) {
		ymap_import_fail( $markers->get_error_message() );
		return;
	}

	/* Сохраняем исходный файл без изменений: после импорта пользователь
	 * сможет скачать именно его — в том же формате и с тем же содержимым. */
	$source_saved = ymap_store_source_file( $tmp_name, isset( $file['name'] ) ? $file['name'] : '' );
	if ( false === $source_saved ) {
		/* Чтобы кнопка скачивания не отдала файл от предыдущего импорта,
		 * расходящийся с новыми метками, убираем устаревший файл. */
		ymap_delete_source_file();
	}

	ymap_save_markers( $markers );

	$message = sprintf(
		/* translators: %d: количество импортированных меток. */
		__( 'Импортировано меток: %d.', 'yandex-map-on-wp' ),
		count( $markers )
	);

	if ( false === $source_saved ) {
		$message .= ' ' . __( 'Исходный файл не удалось сохранить, поэтому кнопка скачивания недоступна.', 'yandex-map-on-wp' );
	}

	set_transient( 'ymap_import_feedback', array( 'type' => 'success', 'message' => $message ), 60 );

	wp_safe_redirect( add_query_arg( 'ymap_import', '1', admin_url( 'admin.php?page=yandex-map-on-wp' ) ) );
	exit;
}
add_action( 'admin_post_ymap_import_markers', 'ymap_handle_markers_import' );

/**
 * Отдаёт браузеру исходный файл, загруженный при последнем импорте меток.
 *
 * Скачивается тот же файл, что был загружен: без перекодирования, повторной
 * сборки CSV/XLSX или изменения имени, чтобы его можно было сохранить или
 * передать дальше.
 */
function ymap_handle_markers_download() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Недостаточно прав.', 'yandex-map-on-wp' ) );
	}

	check_admin_referer( 'ymap_download_markers' );

	$source = ymap_get_source_file();
	if ( null === $source ) {
		wp_die( esc_html__( 'Файл с метками не найден. Загрузите файл заново через форму импорта.', 'yandex-map-on-wp' ) );
	}

	$path = (string) $source['path'];
	if ( ! is_readable( $path ) ) {
		wp_die( esc_html__( 'Файл с метками недоступен для чтения.', 'yandex-map-on-wp' ) );
	}

	$filename = sanitize_file_name( (string) $source['name'] );
	if ( '' === $filename ) {
		$filename = 'markers';
	}
	$filename = str_replace( array( "\r", "\n", '"' ), '', $filename );

	$fallback = preg_replace( '/[^A-Za-z0-9._-]+/', '_', $filename );
	if ( '' === (string) $fallback ) {
		$fallback = 'markers';
	}

	$type = isset( $source['type'] ) ? sanitize_mime_type( (string) $source['type'] ) : 'application/octet-stream';
	if ( '' === $type ) {
		$type = 'application/octet-stream';
	}

	nocache_headers();
	header( 'Content-Type: ' . $type );
	header( 'Content-Disposition: attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode( $filename ) );
	header( 'Content-Length: ' . (string) filesize( $path ) );

	readfile( $path );
	exit;
}
add_action( 'admin_post_ymap_download_markers', 'ymap_handle_markers_download' );