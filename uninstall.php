<?php
/**
 * Очистка данных при удалении плагина «Яндекс.Карта на WordPress».
 *
 * Вызывается WordPress при удалении плагина через админку.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'yandex_map_on_wp_settings' );
delete_option( 'yandex_map_on_wp_markers' );

$ymap_source_meta = get_option( 'yandex_map_on_wp_markers_source_file', array() );
delete_option( 'yandex_map_on_wp_markers_source_file' );

if ( is_array( $ymap_source_meta ) && ! empty( $ymap_source_meta['path'] ) && is_file( (string) $ymap_source_meta['path'] ) ) {
	@unlink( (string) $ymap_source_meta['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}

$ymap_uploads = wp_upload_dir();
if ( is_array( $ymap_uploads ) && empty( $ymap_uploads['error'] ) && ! empty( $ymap_uploads['basedir'] ) ) {
	$ymap_source_dir   = trailingslashit( $ymap_uploads['basedir'] ) . 'yandex-map-on-wp';
	$ymap_source_files = glob( trailingslashit( $ymap_source_dir ) . 'markers-*' );

	if ( is_array( $ymap_source_files ) ) {
		foreach ( $ymap_source_files as $ymap_source_file ) {
			if ( is_file( $ymap_source_file ) ) {
				@unlink( $ymap_source_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}

	if ( is_dir( $ymap_source_dir ) ) {
		@rmdir( $ymap_source_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
}
