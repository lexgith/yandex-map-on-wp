<?php
/**
 * Виджет «Яндекс.Карта» для сайдбара и произвольных зон виджетов.
 *
 * @package YandexMap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Виджет «Яндекс.Карта».
 */
class Ymap_Map_Widget extends WP_Widget {

	/**
	 * Конструктор: регистрирует виджет с описанием и названием.
	 */
	public function __construct() {
		parent::__construct(
			'yandex-map-on-wp-widget',
			__( 'Яндекс.Карта', 'yandex-map-on-wp' ),
			array(
				'classname'                   => 'widget_yandex_map_on_wp',
				'description'                 => __( 'Карта Яндекс.Карт с точкой, масштабом и высотой.', 'yandex-map-on-wp' ),
				'customize_selective_refresh' => true,
			)
		);
	}

	/**
	 * Настройки виджета по умолчанию.
	 *
	 * @return array
	 */
	protected function defaults() {
		$settings = ymap_default_settings();

		return array(
			'title'     => '',
			'address'   => '',
			'latitude'  => $settings['latitude'],
			'longitude' => $settings['longitude'],
			'zoom'      => $settings['zoom'],
			'height'    => $settings['height'],
			'width'     => isset( $settings['width'] ) ? $settings['width'] : '100%',
		);
	}

	/**
	 * Вывод виджета на сайте.
	 *
	 * @param array $args     Параметры сайдбара (before_widget и т. п.).
	 * @param array $instance Сохранённые настройки виджета.
	 */
	public function widget( $args, $instance ) {
		$instance = wp_parse_args( (array) $instance, $this->defaults() );
		$title    = isset( $instance['title'] ) ? trim( (string) $instance['title'] ) : '';

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( '' !== $title ) {
			echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( ! ymap_has_api_key() ) {
			echo ymap_no_key_notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		$map = ymap_map_data( $this->map_args( $instance ), 'ymap-' . ymap_unique_id() );

		echo ymap_render_map( $map ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		ymap_enqueue_assets();

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Параметры карты из настроек виджета.
	 *
	 * @param array $instance Настройки виджета.
	 *
	 * @return array
	 */
	protected function map_args( $instance ) {
		$settings = ymap_get_settings();
		$defaults = $this->defaults();

		$width = isset( $instance['width'] ) && '' !== trim( (string) $instance['width'] )
			? (string) $instance['width']
			: ( isset( $settings['width'] ) ? (string) $settings['width'] : '100%' );

		return array(
			'address'   => isset( $instance['address'] ) ? (string) $instance['address'] : '',
			'latitude'  => isset( $instance['latitude'] ) ? (float) $instance['latitude'] : (float) $defaults['latitude'],
			'longitude' => isset( $instance['longitude'] ) ? (float) $instance['longitude'] : (float) $defaults['longitude'],
			'zoom'      => isset( $instance['zoom'] ) ? (int) $instance['zoom'] : (int) $defaults['zoom'],
			'height'    => isset( $instance['height'] ) ? (int) $instance['height'] : (int) $defaults['height'],
			'width'     => ymap_normalize_size( $width, '100%' ),
			'caption'   => '',
		);
	}

	/**
	 * Форма настройки виджета в админке.
	 *
	 * @param array $instance Текущие настройки виджета.
	 */
	public function form( $instance ) {
		$instance = wp_parse_args( (array) $instance, $this->defaults() );
		$settings = ymap_get_settings();
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">
				<?php esc_html_e( 'Заголовок', 'yandex-map-on-wp' ); ?>
			</label>
			<input
				class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
				type="text"
				value="<?php echo esc_attr( $instance['title'] ); ?>"
				placeholder="<?php esc_attr_e( 'например, Как нас найти', 'yandex-map-on-wp' ); ?>"
			/>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'address' ) ); ?>">
				<?php esc_html_e( 'Адрес точки', 'yandex-map-on-wp' ); ?>
			</label>
			<input
				class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'address' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'address' ) ); ?>"
				type="text"
				value="<?php echo esc_attr( $instance['address'] ); ?>"
				placeholder="<?php esc_attr_e( 'например, Москва, Красная площадь', 'yandex-map-on-wp' ); ?>"
			/>
		</p>
		<p>
			<?php esc_html_e( 'Координаты используют, если адрес не задан (приоритет у адреса).', 'yandex-map-on-wp' ); ?>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'latitude' ) ); ?>">
				<?php esc_html_e( 'Широта', 'yandex-map-on-wp' ); ?>
			</label>
			<input
				class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'latitude' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'latitude' ) ); ?>"
				type="number"
				step="any"
				min="-90"
				max="90"
				value="<?php echo esc_attr( (string) $instance['latitude'] ); ?>"
			/>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'longitude' ) ); ?>">
				<?php esc_html_e( 'Долгота', 'yandex-map-on-wp' ); ?>
			</label>
			<input
				class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'longitude' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'longitude' ) ); ?>"
				type="number"
				step="any"
				min="-180"
				max="180"
				value="<?php echo esc_attr( (string) $instance['longitude'] ); ?>"
			/>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'zoom' ) ); ?>">
				<?php esc_html_e( 'Масштаб', 'yandex-map-on-wp' ); ?>
			</label>
			<input
				class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'zoom' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'zoom' ) ); ?>"
				type="number"
				min="0"
				max="19"
				value="<?php echo esc_attr( (string) $instance['zoom'] ); ?>"
			/>
			<small><?php esc_html_e( 'От 0 (весь мир) до 19 (дома).', 'yandex-map-on-wp' ); ?></small>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'height' ) ); ?>">
				<?php esc_html_e( 'Высота карты, пикс.', 'yandex-map-on-wp' ); ?>
			</label>
			<input
				class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'height' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'height' ) ); ?>"
				type="number"
				min="100"
				step="10"
				value="<?php echo esc_attr( (string) $instance['height'] ); ?>"
			/>
			<small>
				<?php
				printf(
					/* translators: %s: значение высоты по умолчанию. */
					esc_html__( 'Умолчание: %s.', 'yandex-map-on-wp' ),
					esc_html( (string) $settings['height'] )
				);
				?>
			</small>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'width' ) ); ?>">
				<?php esc_html_e( 'Ширина карты', 'yandex-map-on-wp' ); ?>
			</label>
			<input
				class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'width' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'width' ) ); ?>"
				type="text"
				value="<?php echo esc_attr( $instance['width'] ); ?>"
				placeholder="100%"
			/>
			<small>
				<?php
				printf(
					/* translators: %s: значение ширины по умолчанию. */
					esc_html__( 'Формат: 100% или 600px. Умолчание: %s.', 'yandex-map-on-wp' ),
					esc_html( isset( $settings['width'] ) ? (string) $settings['width'] : '100%' )
				);
				?>
			</small>
		</p>
		<?php
	}

	/**
	 * Сохранение и санитизация настроек виджета.
	 *
	 * @param array $new_instance Новые настройки из формы.
	 * @param array $old_instance Старые сохранённые настройки.
	 *
	 * @return array
	 */
	public function update( $new_instance, $old_instance ) {
		$instance = wp_parse_args( (array) $old_instance, $this->defaults() );
		$defaults = ymap_default_settings();

		$title = isset( $new_instance['title'] ) ? trim( (string) $new_instance['title'] ) : '';
		$title = function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 256 ) : substr( $title, 0, 256 );
		$instance['title'] = sanitize_text_field( $title );

		$address = isset( $new_instance['address'] ) ? trim( (string) $new_instance['address'] ) : '';
		$address = function_exists( 'mb_substr' ) ? mb_substr( $address, 0, 256 ) : substr( $address, 0, 256 );
		$instance['address'] = sanitize_text_field( $address );

		$lat = isset( $new_instance['latitude'] ) ? (float) $new_instance['latitude'] : (float) $instance['latitude'];
		$instance['latitude'] = ( $lat < -90 || $lat > 90 ) ? (float) $defaults['latitude'] : $lat;

		$lng = isset( $new_instance['longitude'] ) ? (float) $new_instance['longitude'] : (float) $instance['longitude'];
		$instance['longitude'] = ( $lng < -180 || $lng > 180 ) ? (float) $defaults['longitude'] : $lng;

		$zoom = isset( $new_instance['zoom'] ) ? (int) $new_instance['zoom'] : (int) $instance['zoom'];
		$instance['zoom'] = ( $zoom < 0 || $zoom > 19 ) ? (int) $defaults['zoom'] : $zoom;

		$height = isset( $new_instance['height'] ) ? (int) $new_instance['height'] : (int) $instance['height'];
		$instance['height'] = ( $height < 100 ) ? (int) $defaults['height'] : $height;

		$width = isset( $new_instance['width'] ) ? trim( (string) $new_instance['width'] ) : '';
		$instance['width'] = ymap_normalize_size( $width, isset( $defaults['width'] ) ? (string) $defaults['width'] : '100%' );

		return $instance;
	}
}

/**
 * Регистрирует виджет «Яндекс.Карта» в системе виджетов WordPress.
 */
function ymap_register_widget() {
	register_widget( 'Ymap_Map_Widget' );
}
add_action( 'widgets_init', 'ymap_register_widget' );