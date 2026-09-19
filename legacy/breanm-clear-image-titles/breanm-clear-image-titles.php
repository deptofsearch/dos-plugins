<?php
/**
 * Plugin Name: BREANM Clear Image Titles
 * Description: Adds an admin tool to clear the Title field for all image attachments in the WordPress Media Library.
 * Version: 1.0.0
 * Author: Best Real Estate Agents Near Me
 * License: GPL-2.0-or-later
 * Text Domain: breanm-clear-image-titles
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class BREANM_Clear_Image_Titles {
    const SLUG        = 'breanm-clear-image-titles';
    const NONCE       = 'breanm_clear_image_titles_nonce';
    const BATCH_SIZE  = 100;

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_admin_page' ) );
        add_action( 'admin_post_breanm_clear_image_titles', array( __CLASS__, 'handle_clear_titles' ) );
    }

    public static function add_admin_page() {
        add_management_page(
            'Clear Image Titles',
            'Clear Image Titles',
            'manage_options',
            self::SLUG,
            array( __CLASS__, 'render_admin_page' )
        );
    }

    private static function get_images_with_titles_count() {
        global $wpdb;

        return (int) $wpdb->get_var(
            "SELECT COUNT(ID)
             FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
               AND post_mime_type LIKE 'image/%'
               AND post_title <> ''"
        );
    }

    private static function clear_batch() {
        global $wpdb;

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID
                 FROM {$wpdb->posts}
                 WHERE post_type = 'attachment'
                   AND post_mime_type LIKE 'image/%%'
                   AND post_title <> ''
                 ORDER BY ID ASC
                 LIMIT %d",
                self::BATCH_SIZE
            )
        );

        if ( empty( $ids ) ) {
            return 0;
        }

        $ids = array_map( 'absint', $ids );
        $ids_sql = implode( ',', $ids );

        $updated = $wpdb->query(
            "UPDATE {$wpdb->posts}
             SET post_title = ''
             WHERE ID IN ({$ids_sql})"
        );

        foreach ( $ids as $attachment_id ) {
            clean_post_cache( $attachment_id );
        }

        return (int) $updated;
    }

    public static function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'breanm-clear-image-titles' ) );
        }

        $remaining = self::get_images_with_titles_count();
        $cleared   = isset( $_GET['cleared'] ) ? absint( $_GET['cleared'] ) : 0;
        $done      = isset( $_GET['done'] ) ? absint( $_GET['done'] ) : 0;
        ?>
        <div class="wrap">
            <h1>Clear Image Titles</h1>

            <?php if ( $cleared > 0 ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html( sprintf( 'Cleared %d image title(s).', $cleared ) ); ?></p>
                </div>
            <?php endif; ?>

            <?php if ( $done ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>All image titles have been cleared.</p>
                </div>
            <?php endif; ?>

            <p>This tool clears the <strong>Title</strong> field for image attachments in the WordPress Media Library.</p>
            <p>It does <strong>not</strong> delete the image files, captions, alt text, descriptions, posts, pages, or galleries.</p>

            <table class="widefat striped" style="max-width: 720px; margin: 20px 0;">
                <tbody>
                    <tr>
                        <th scope="row">Images with titles remaining</th>
                        <td><strong><?php echo esc_html( number_format_i18n( $remaining ) ); ?></strong></td>
                    </tr>
                    <tr>
                        <th scope="row">Batch size</th>
                        <td><?php echo esc_html( number_format_i18n( self::BATCH_SIZE ) ); ?> images per click</td>
                    </tr>
                </tbody>
            </table>

            <?php if ( $remaining > 0 ) : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( self::NONCE ); ?>
                    <input type="hidden" name="action" value="breanm_clear_image_titles">
                    <?php submit_button( 'Clear Next Batch of Image Titles', 'primary', 'submit', false ); ?>
                </form>
                <p class="description">Run this repeatedly until the remaining count reaches zero. This batching avoids timeouts on large media libraries.</p>
            <?php else : ?>
                <p><strong>No image titles need to be cleared.</strong></p>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function handle_clear_titles() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'breanm-clear-image-titles' ) );
        }

        check_admin_referer( self::NONCE );

        $cleared   = self::clear_batch();
        $remaining = self::get_images_with_titles_count();

        $redirect_args = array(
            'page'    => self::SLUG,
            'cleared' => $cleared,
        );

        if ( 0 === $remaining ) {
            $redirect_args['done'] = 1;
        }

        wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'tools.php' ) ) );
        exit;
    }
}

BREANM_Clear_Image_Titles::init();
