<?php
/**
 * Регистрация, сохранение и валидация настроек плагина «Яндекс.Карта на WordPress».
 *
 * @package YandexMap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Показывает уведомления (включая результат импорта) на странице плагина.
 */
function ymap_admin_notices() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( $screen && isset( $screen->id ) && 'toplevel_page_yandex-map-on-wp' === $screen->id ) {
		$feedback = get_transient( 'ymap_import_feedback' );
		if ( is_array( $feedback ) && ! empty( $feedback['message'] ) ) {
			$type = 'success' === $feedback['type'] ? 'notice-success' : 'notice-error';
			echo '<div class="notice ' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $feedback['message'] ) . '</p></div>';
			delete_transient( 'ymap_import_feedback' );
		}
	}

	if ( isset( $_GET['ymap_import'] ) && 'cleared' === $_GET['ymap_import'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Все импортированные метки удалены.', 'yandex-map-on-wp' ) . '</p></div>';
	}
}
add_action( 'admin_notices', 'ymap_admin_notices' );

/**
 * Регистрирует настройки плагина через WordPress Settings API.
 */
function ymap_register_settings() {
	register_setting(
		ymap_settings_name(),
		ymap_settings_name(),
		array(
			'type'              => 'array',
			'default'           => ymap_default_settings(),
			'sanitize_callback' => 'ymap_sanitize_settings',
		)
	);
}
add_action( 'admin_init', 'ymap_register_settings' );

/**
 * Санитизация и валидация настроек перед сохранением в БД.
 *
 * @param mixed $input Сырые данные из формы настроек.
 *
 * @return array
 */
function ymap_sanitize_settings( $input ) {
	$current = ymap_get_settings();

	if ( ! is_array( $input ) ) {
		return $current;
	}

	$api_key = isset( $input['api_key'] ) ? trim( (string) $input['api_key'] ) : '';
	$api_key = mb_substr( $api_key, 0, 256 );
	$current['api_key'] = sanitize_text_field( $api_key );

	$address = isset( $input['address'] ) ? trim( (string) $input['address'] ) : '';
	$address = mb_substr( $address, 0, 256 );
	$current['address'] = sanitize_text_field( $address );

	$lat = isset( $input['latitude'] ) ? (float) $input['latitude'] : (float) $current['latitude'];
	if ( $lat < -90 || $lat > 90 ) {
		$lat = (float) ymap_default_settings()['latitude'];
	}
	$current['latitude'] = $lat;

	$lng = isset( $input['longitude'] ) ? (float) $input['longitude'] : (float) $current['longitude'];
	if ( $lng < -180 || $lng > 180 ) {
		$lng = (float) ymap_default_settings()['longitude'];
	}
	$current['longitude'] = $lng;

	$zoom = isset( $input['zoom'] ) ? (int) $input['zoom'] : (int) $current['zoom'];
	if ( $zoom < 0 || $zoom > 19 ) {
		$zoom = (int) ymap_default_settings()['zoom'];
	}
	$current['zoom'] = $zoom;

	$height = isset( $input['height'] ) ? (int) $input['height'] : (int) $current['height'];
	if ( $height < 100 ) {
		$height = (int) ymap_default_settings()['height'];
	}
	$current['height'] = $height;

	$width = isset( $input['width'] ) ? trim( (string) $input['width'] ) : '';
	$current['width'] = ymap_normalize_size( $width, $current['width'] );

	$caption = isset( $input['caption'] ) ? trim( (string) $input['caption'] ) : '';
	$caption = mb_substr( $caption, 0, 256 );
	$current['caption'] = sanitize_text_field( $caption );

	$current['group_markers'] = ! empty( $input['group_markers'] );

	return $current;
}

/**
 * Добавляет ссылку «Настройки» на странице плагинов.
 *
 * @param array $links Массив ссылок (обычно «Активировать», «Редактировать» и т. п.).
 *
 * @return array
 */
function ymap_action_links( $links ) {
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'admin.php?page=yandex-map-on-wp' ) ),
		esc_html__( 'Настройки', 'yandex-map-on-wp' )
	);
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( YMAP_PLUGIN_FILE ), 'ymap_action_links' );

/**
 * Регистрирует отдельный пункт меню плагина в админке WordPress
 * (не подпункт «Настройки»).
 */
function ymap_register_menu_page() {
	add_menu_page(
		__( 'Яндекс.Карта на WordPress', 'yandex-map-on-wp' ),
		__( 'Яндекс.Карта на WordPress', 'yandex-map-on-wp' ),
		'manage_options',
		'yandex-map-on-wp',
		'ymap_render_settings_page',
		'dashicons-location-alt',
		58
	);
}
add_action( 'admin_menu', 'ymap_register_menu_page' );

/**
 * Выводит HTML-страницу настроек плагина.
 */
function ymap_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Яндекс.Карта на WordPress', 'yandex-map-on-wp' ); ?></h1>

		<?php if ( ! ymap_has_api_key() ) : ?>
			<div class="notice notice-warning">
				<p>
					<?php esc_html_e( 'Для отображения карты укажите API-ключ Яндекс.Карт. Без ключа вместо карты выводится подсказка.', 'yandex-map-on-wp' ); ?>
					<a href="https://developer.tech.yandex.ru/" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Получить ключ', 'yandex-map-on-wp' ); ?>
					</a>
				</p>
			</div>
		<?php endif; ?>

		<form action="options.php" method="post">
			<?php
			settings_fields( ymap_settings_name() );
			$settings = ymap_get_settings();
			?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="ymap-api-key"><?php esc_html_e( 'API-ключ Яндекс.Карт', 'yandex-map-on-wp' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							class="regular-text"
							id="ymap-api-key"
							name="<?php echo esc_attr( ymap_settings_name() ); ?>[api_key]"
							value="<?php echo esc_attr( $settings['api_key'] ); ?>"
							autocomplete="off"
						/>
						<p class="description">
							<?php esc_html_e( 'Вставьте ключ из кабинета разработчика Яндекс.Карт. Карта работает только с API-ключом.', 'yandex-map-on-wp' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="ymap-address"><?php esc_html_e( 'Точка по умолчанию', 'yandex-map-on-wp' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							class="regular-text"
							id="ymap-address"
							name="<?php echo esc_attr( ymap_settings_name() ); ?>[address]"
							value="<?php echo esc_attr( $settings['address'] ); ?>"
							placeholder="<?php esc_attr_e( 'например, Москва, Красная площадь', 'yandex-map-on-wp' ); ?>"
						/>
						<p class="description">
							<?php esc_html_e( 'Адрес точки по умолчанию. Можно оставить пустым и указать координаты ниже.', 'yandex-map-on-wp' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="ymap-latitude"><?php esc_html_e( 'Координаты по умолчанию (широта)', 'yandex-map-on-wp' ); ?></label>
					</th>
					<td>
						<input
							type="number"
							step="any"
							id="ymap-latitude"
							name="<?php echo esc_attr( ymap_settings_name() ); ?>[latitude]"
							value="<?php echo esc_attr( (string) $settings['latitude'] ); ?>"
						/>
						<p class="description">
							<?php esc_html_e( 'Используются, если адрес не задан. Например: 55.751244.', 'yandex-map-on-wp' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="ymap-longitude"><?php esc_html_e( 'Координаты по умолчанию (долгота)', 'yandex-map-on-wp' ); ?></label>
					</th>
					<td>
						<input
							type="number"
							step="any"
							id="ymap-longitude"
							name="<?php echo esc_attr( ymap_settings_name() ); ?>[longitude]"
							value="<?php echo esc_attr( (string) $settings['longitude'] ); ?>"
						/>
						<p class="description">
							<?php esc_html_e( 'Например: 37.618423.', 'yandex-map-on-wp' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="ymap-zoom"><?php esc_html_e( 'Масштаб по умолчанию', 'yandex-map-on-wp' ); ?></label>
					</th>
					<td>
						<input
							type="number"
							id="ymap-zoom"
							name="<?php echo esc_attr( ymap_settings_name() ); ?>[zoom]"
							min="0"
							max="19"
							value="<?php echo esc_attr( (string) $settings['zoom'] ); ?>"
						/>
						<p class="description"><?php esc_html_e( 'От 0 (весь мир) до 19 (дома).', 'yandex-map-on-wp' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="ymap-height"><?php esc_html_e( 'Высота карты по умолчанию', 'yandex-map-on-wp' ); ?></label>
					</th>
					<td>
						<input
							type="number"
							id="ymap-height"
							name="<?php echo esc_attr( ymap_settings_name() ); ?>[height]"
							min="100"
							step="10"
							value="<?php echo esc_attr( (string) $settings['height'] ); ?>"
						/>
						<p class="description">
							<?php esc_html_e( 'Высота карты в пикселях, когда она не задана в шорткоде.', 'yandex-map-on-wp' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="ymap-width"><?php esc_html_e( 'Ширина карты по умолчанию', 'yandex-map-on-wp' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							class="regular-text"
							id="ymap-width"
							name="<?php echo esc_attr( ymap_settings_name() ); ?>[width]"
							value="<?php echo esc_attr( $settings['width'] ); ?>"
							placeholder="100%"
						/>
						<p class="description">
							<?php esc_html_e( 'Ширина карты, когда она не задана в шорткоде. Формат: 100%, 70% или 600px.', 'yandex-map-on-wp' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="ymap-caption"><?php esc_html_e( 'Подпись по умолчанию', 'yandex-map-on-wp' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							class="regular-text"
							id="ymap-caption"
							name="<?php echo esc_attr( ymap_settings_name() ); ?>[caption]"
							value="<?php echo esc_attr( $settings['caption'] ); ?>"
							placeholder="<?php esc_attr_e( 'например, Как нас найти', 'yandex-map-on-wp' ); ?>"
						/>
						<p class="description">
							<?php esc_html_e( 'Заголовок над картой. Можно оставить пустым, чтобы карта выводилась без подписи.', 'yandex-map-on-wp' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="ymap-group-markers"><?php esc_html_e( 'Группировать метки', 'yandex-map-on-wp' ); ?></label>
					</th>
					<td>
						<label>
							<input
								type="checkbox"
								id="ymap-group-markers"
								name="<?php echo esc_attr( ymap_settings_name() ); ?>[group_markers]"
								value="1"
								<?php checked( ! empty( $settings['group_markers'] ) ); ?>
							/>
							<?php esc_html_e( 'Объединять близкие метки в группы с числом меток на разных масштабах карты', 'yandex-map-on-wp' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'При включении метки показываются не сразу, а группами: на мелком масштабе видно общее число меток в регионе, при увеличении масштаба группы постепенно распадаются вплоть до отдельных меток. При выключении все метки показываются сразу.', 'yandex-map-on-wp' ); ?>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Сохранить настройки', 'yandex-map-on-wp' ) ); ?>
		</form>

		<hr />

		<h2><?php esc_html_e( 'Импорт меток из XLSX и CSV', 'yandex-map-on-wp' ); ?></h2>
		<p>
			<?php esc_html_e( 'Загрузите файл со списком точек в формате Яндекс.Конструктора карт. Поддерживаются XLSX (Excel) и CSV с разделителем «;». Плагин распознаёт колонки «Широта», «Долгота», «Описание», «Подпись», «Номер метки» и «preset», в том числе в кодировке Windows-1251.', 'yandex-map-on-wp' ); ?>
		</p>

		<?php
		$markers = ymap_get_markers();
		if ( ! empty( $markers ) ) :
			?>
			<p>
				<?php
				/* translators: %d: количество меток. */
				printf( esc_html__( 'Сейчас сохранено меток: %d.', 'yandex-map-on-wp' ), (int) count( $markers ) );
				?>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ymap_clear_markers' ), 'ymap_clear_markers' ) ); ?>" class="button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Удалить все импортированные метки?', 'yandex-map-on-wp' ) ); ?>');">
					<?php esc_html_e( 'Удалить все метки', 'yandex-map-on-wp' ); ?>
				</a>
			</p>
		<?php endif; ?>

		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" enctype="multipart/form-data">
			<input type="hidden" name="action" value="ymap_import_markers" />
			<?php wp_nonce_field( 'ymap_import_markers', 'ymap_import_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="ymap-markers-file"><?php esc_html_e( 'Файл с метками', 'yandex-map-on-wp' ); ?></label>
					</th>
					<td>
						<input
							type="file"
							id="ymap-markers-file"
							name="ymap_markers_file"
							accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv,text/plain"
						/>
						<p class="description">
							<?php esc_html_e( 'XLSX-файл из Яндекс.Конструктора карт или CSV-файл с колонками, разделёнными «;» (кодировка UTF-8 или Windows-1251).', 'yandex-map-on-wp' ); ?>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Загрузить и импортировать', 'yandex-map-on-wp' ), 'secondary' ); ?>
		</form>

		<?php
		$source_file = ymap_get_source_file();
		if ( null !== $source_file ) :
			?>
			<p>
				<a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ymap_download_markers' ), 'ymap_download_markers' ) ); ?>">
					<?php esc_html_e( 'Скачать файл с метками', 'yandex-map-on-wp' ); ?>
				</a>
				<span class="description">
					<?php
					printf(
						/* translators: 1: имя файла, 2: размер файла. */
						esc_html__( 'Исходный файл последнего импорта: %1$s (%2$s).', 'yandex-map-on-wp' ),
						esc_html( (string) $source_file['name'] ),
						esc_html( size_format( (int) $source_file['size'] ) )
					);
					?>
				</span>
			</p>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Обработчик удаления всех импортированных меток.
 */
function ymap_handle_clear_markers() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Недостаточно прав.', 'yandex-map-on-wp' ) );
	}

	check_admin_referer( 'ymap_clear_markers' );

	ymap_delete_source_file();
	delete_option( ymap_markers_option_name() );

	wp_safe_redirect( add_query_arg( 'ymap_import', 'cleared', admin_url( 'admin.php?page=yandex-map-on-wp' ) ) );
	exit;
}
add_action( 'admin_post_ymap_clear_markers', 'ymap_handle_clear_markers' );
