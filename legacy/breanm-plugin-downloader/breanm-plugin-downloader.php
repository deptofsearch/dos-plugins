<?php
/**
 * Plugin Name: BREANM Plugin Downloader
 * Description: Adds a Tools page that lets administrators download any currently installed plugin as a ZIP file.
 * Version: 1.0.0
 * Author: Best Real Estate Agents Near Me
 * License: GPL-2.0-or-later
 * Text Domain: breanm-plugin-downloader
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BREANM_Plugin_Downloader {
    const NONCE_ACTION = 'breanm_download_plugin_zip';
    const NONCE_NAME   = 'breanm_plugin_downloader_nonce';
    const MENU_SLUG    = 'breanm-plugin-downloader';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_tools_page' ) );
        add_action( 'admin_post_breanm_download_plugin', array( $this, 'handle_download' ) );
    }

    public function add_tools_page() {
        add_management_page(
            __( 'Plugin Downloader', 'breanm-plugin-downloader' ),
            __( 'Plugin Downloader', 'breanm-plugin-downloader' ),
            'activate_plugins',
            self::MENU_SLUG,
            array( $this, 'render_tools_page' )
        );
    }

    public function render_tools_page() {
        if ( ! current_user_can( 'activate_plugins' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'breanm-plugin-downloader' ) );
        }

        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Plugin Downloader', 'breanm-plugin-downloader' ); ?></h1>
            <p><?php esc_html_e( 'Select an installed plugin below and download it as a ZIP file.', 'breanm-plugin-downloader' ); ?></p>

            <?php if ( ! class_exists( 'ZipArchive' ) ) : ?>
                <div class="notice notice-error">
                    <p><?php esc_html_e( 'The PHP ZipArchive extension is not available on this server. It is required to create plugin ZIP downloads.', 'breanm-plugin-downloader' ); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="breanm_download_plugin" />
                <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="breanm_plugin_file"><?php esc_html_e( 'Installed Plugin', 'breanm-plugin-downloader' ); ?></label>
                        </th>
                        <td>
                            <select name="plugin_file" id="breanm_plugin_file" required style="min-width: 360px; max-width: 100%;">
                                <option value=""><?php esc_html_e( '-- Select Plugin --', 'breanm-plugin-downloader' ); ?></option>
                                <?php foreach ( $plugins as $plugin_file => $plugin_data ) : ?>
                                    <option value="<?php echo esc_attr( $plugin_file ); ?>">
                                        <?php
                                        echo esc_html(
                                            sprintf(
                                                '%s (%s)',
                                                ! empty( $plugin_data['Name'] ) ? $plugin_data['Name'] : $plugin_file,
                                                $plugin_file
                                            )
                                        );
                                        ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php esc_html_e( 'Only users who can manage plugins can access this tool.', 'breanm-plugin-downloader' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( __( 'Download Plugin ZIP', 'breanm-plugin-downloader' ) ); ?>
            </form>
        </div>
        <?php
    }

    public function handle_download() {
        if ( ! current_user_can( 'activate_plugins' ) ) {
            wp_die( esc_html__( 'You do not have permission to download plugins.', 'breanm-plugin-downloader' ) );
        }

        check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

        if ( ! class_exists( 'ZipArchive' ) ) {
            wp_die( esc_html__( 'The PHP ZipArchive extension is required to create ZIP files.', 'breanm-plugin-downloader' ) );
        }

        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugin_file = isset( $_POST['plugin_file'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin_file'] ) ) : '';
        $plugins     = get_plugins();

        if ( empty( $plugin_file ) || ! isset( $plugins[ $plugin_file ] ) ) {
            wp_die( esc_html__( 'Invalid plugin selected.', 'breanm-plugin-downloader' ) );
        }

        $plugin_file = wp_normalize_path( $plugin_file );
        $plugin_dir  = wp_normalize_path( WP_PLUGIN_DIR );
        $plugin_path = wp_normalize_path( WP_PLUGIN_DIR . '/' . $plugin_file );

        if ( ! file_exists( $plugin_path ) ) {
            wp_die( esc_html__( 'The selected plugin file could not be found.', 'breanm-plugin-downloader' ) );
        }

        $relative_dir = dirname( $plugin_file );

        if ( '.' === $relative_dir || '' === $relative_dir ) {
            // Single-file plugin in wp-content/plugins.
            $source_path = $plugin_path;
            $zip_root    = basename( $plugin_file, '.php' );
        } else {
            // Normal plugin folder.
            $source_path = wp_normalize_path( WP_PLUGIN_DIR . '/' . $relative_dir );
            $zip_root    = basename( $source_path );
        }

        $real_plugins_dir = realpath( $plugin_dir );
        $real_source_path = realpath( $source_path );

        if ( false === $real_source_path || 0 !== strpos( wp_normalize_path( $real_source_path ), wp_normalize_path( $real_plugins_dir ) ) ) {
            wp_die( esc_html__( 'Invalid plugin path.', 'breanm-plugin-downloader' ) );
        }

        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) ) {
            wp_die( esc_html( $uploads['error'] ) );
        }

        $safe_name = sanitize_file_name( $zip_root );
        $zip_file  = trailingslashit( $uploads['basedir'] ) . $safe_name . '-' . gmdate( 'Ymd-His' ) . '.zip';

        $zip = new ZipArchive();
        if ( true !== $zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
            wp_die( esc_html__( 'Could not create ZIP file.', 'breanm-plugin-downloader' ) );
        }

        if ( is_file( $source_path ) ) {
            $zip->addFile( $source_path, $zip_root . '/' . basename( $source_path ) );
        } else {
            $this->add_directory_to_zip( $zip, $source_path, $zip_root );
        }

        $zip->close();

        if ( ! file_exists( $zip_file ) ) {
            wp_die( esc_html__( 'ZIP file was not created.', 'breanm-plugin-downloader' ) );
        }

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

    private function add_directory_to_zip( ZipArchive $zip, $source_dir, $zip_root ) {
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

new BREANM_Plugin_Downloader();
