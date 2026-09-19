<?php
/**
 * Download any installed plugin as a ZIP.
 *
 * Useful when a site carries a plugin that exists nowhere else — a client's
 * one-off, or something inherited from a previous developer with no source
 * anywhere. Ported from BREANM Plugin Downloader.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DOS_Plugin_Download {

	const ACTION = 'dos_download_plugin';

	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	public static function is_supported() {
		return class_exists( 'ZipArchive' );
	}

	public static function installed_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return get_plugins();
	}

	public static function handle() {
		// Downloading a plugin hands over its source, including any
		// credentials a previous developer hard-coded into it. Gate it on the
		// capability that already implies full control of plugin code.
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to download plugins.', 'dos-toolkit' ), 403 );
		}

		check_admin_referer( self::ACTION );

		if ( ! self::is_supported() ) {
			wp_die( esc_html__( 'The PHP ZipArchive extension is required to create ZIP files.', 'dos-toolkit' ) );
		}

		$plugin_file = isset( $_POST['plugin_file'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin_file'] ) ) : '';
		$plugins     = self::installed_plugins();

		// Only a plugin WordPress itself lists is downloadable. This is what
		// stops the field being used to read an arbitrary path.
		if ( '' === $plugin_file || ! isset( $plugins[ $plugin_file ] ) ) {
			wp_die( esc_html__( 'Invalid plugin selected.', 'dos-toolkit' ), 400 );
		}

		$plugin_file = wp_normalize_path( $plugin_file );
		$plugin_dir  = wp_normalize_path( WP_PLUGIN_DIR );
		$plugin_path = wp_normalize_path( WP_PLUGIN_DIR . '/' . $plugin_file );

		if ( ! file_exists( $plugin_path ) ) {
			wp_die( esc_html__( 'The selected plugin file could not be found.', 'dos-toolkit' ), 404 );
		}

		$relative_dir = dirname( $plugin_file );

		if ( '.' === $relative_dir || '' === $relative_dir ) {
			$source_path = $plugin_path;                          // Single-file plugin.
			$zip_root    = basename( $plugin_file, '.php' );
		} else {
			$source_path = wp_normalize_path( WP_PLUGIN_DIR . '/' . $relative_dir );
			$zip_root    = basename( $source_path );
		}

		// Belt and braces: resolve symlinks and confirm the result is still
		// inside the plugins directory before reading anything.
		$real_plugins = realpath( $plugin_dir );
		$real_source  = realpath( $source_path );

		if ( false === $real_source || 0 !== strpos( wp_normalize_path( $real_source ), wp_normalize_path( $real_plugins ) ) ) {
			wp_die( esc_html__( 'Invalid plugin path.', 'dos-toolkit' ), 400 );
		}

		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			wp_die( esc_html( $uploads['error'] ) );
		}

		$safe_name = sanitize_file_name( $zip_root );
		$zip_file  = trailingslashit( $uploads['basedir'] ) . $safe_name . '-' . gmdate( 'Ymd-His' ) . '.zip';

		$zip = new ZipArchive();

		if ( true !== $zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			wp_die( esc_html__( 'Could not create ZIP file.', 'dos-toolkit' ) );
		}

		if ( is_file( $source_path ) ) {
			$zip->addFile( $source_path, $zip_root . '/' . basename( $source_path ) );
		} else {
			self::add_directory( $zip, $source_path, $zip_root );
		}

		$zip->close();

		if ( ! file_exists( $zip_file ) ) {
			wp_die( esc_html__( 'ZIP file was not created.', 'dos-toolkit' ) );
		}

		DOS_Log::add( 'utilities', 'plugin_downloaded', $zip_root );

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . basename( $zip_file ) . '"' );
		header( 'Content-Length: ' . filesize( $zip_file ) );
		header( 'Pragma: public' );
		header( 'Expires: 0' );

		readfile( $zip_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile

		wp_delete_file( $zip_file );
		exit;
	}

	private static function add_directory( ZipArchive $zip, $source_dir, $zip_root ) {
		$source_dir = wp_normalize_path( $source_dir );

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source_dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $file ) {
			$file_path = wp_normalize_path( $file->getPathname() );
			$relative  = ltrim( str_replace( $source_dir, '', $file_path ), '/' );
			$zip_path  = $zip_root . '/' . $relative;

			if ( $file->isDir() ) {
				$zip->addEmptyDir( $zip_path );
			} elseif ( $file->isFile() ) {
				$zip->addFile( $file_path, $zip_path );
			}
		}
	}
}
