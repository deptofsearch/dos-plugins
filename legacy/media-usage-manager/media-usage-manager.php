<?php
/**
 * Plugin Name: Media Usage Manager
 * Description: Scans WordPress media attachments to identify used and unused images across domains, adds featured-image indicators and filters to post/page lists, and allows deleting unused images from Tools > Media Usage.
 * Version: 1.3.0
 * Author: Best Real Estate Agents Near Me
 * License: GPL-2.0-or-later
 * Text Domain: media-usage-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class BREANM_Media_Usage_Manager {
    const OPTION_LAST_SCAN  = 'breanm_mum_last_scan';
    const OPTION_SCAN_STATE = 'breanm_mum_scan_state';
    const OPTION_DELETE_STATE = 'breanm_mum_delete_state';
    const META_STATUS       = '_breanm_mum_usage_status';
    const META_USED_IN     = '_breanm_mum_used_in';
    const NONCE_ACTION     = 'breanm_mum_action';
    const NONCE_NAME       = 'breanm_mum_nonce';
    const ATTACHMENT_BATCH = 100;
    const POST_BATCH       = 20;
    const DELETE_BATCH     = 50;

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'add_tools_page' ) );
        add_action( 'admin_init', array( $this, 'handle_admin_actions' ) );
        add_action( 'admin_notices', array( $this, 'admin_notices' ) );

        add_filter( 'manage_post_posts_columns', array( $this, 'add_featured_image_column' ) );
        add_filter( 'manage_page_posts_columns', array( $this, 'add_featured_image_column' ) );
        add_action( 'manage_post_posts_custom_column', array( $this, 'render_featured_image_column' ), 10, 2 );
        add_action( 'manage_page_posts_custom_column', array( $this, 'render_featured_image_column' ), 10, 2 );

        add_action( 'restrict_manage_posts', array( $this, 'add_featured_image_filter' ) );
        add_action( 'pre_get_posts', array( $this, 'filter_posts_by_featured_image' ) );

        add_filter( 'manage_upload_columns', array( $this, 'add_media_usage_column' ) );
        add_action( 'manage_media_custom_column', array( $this, 'render_media_usage_column' ), 10, 2 );
        add_filter( 'attachment_fields_to_edit', array( $this, 'attachment_usage_field' ), 10, 2 );
        add_filter( 'wp_prepare_attachment_for_js', array( $this, 'prepare_attachment_for_js' ), 10, 3 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_media_modal_assets' ) );

        add_action( 'wp_ajax_breanm_mum_process_scan', array( $this, 'ajax_process_scan' ) );
        add_action( 'wp_ajax_breanm_mum_process_delete', array( $this, 'ajax_process_delete' ) );
    }

    public function add_tools_page() {
        add_management_page(
            __( 'Media Usage', 'media-usage-manager' ),
            __( 'Media Usage', 'media-usage-manager' ),
            'manage_options',
            'breanm-media-usage',
            array( $this, 'render_tools_page' )
        );
    }

    public function handle_admin_actions() {
        if ( empty( $_GET['page'] ) || 'breanm-media-usage' !== $_GET['page'] ) {
            return;
        }

        if ( empty( $_POST['breanm_mum_action'] ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage media usage.', 'media-usage-manager' ) );
        }

        check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

        $action = sanitize_key( wp_unslash( $_POST['breanm_mum_action'] ) );

        if ( 'refresh' === $action ) {
            $state = $this->start_usage_scan();
            $redirect = add_query_arg(
                array(
                    'page'              => 'breanm-media-usage',
                    'breanm_mum_notice' => 'scan_started',
                    'attachments'       => absint( $state['attachments_total'] ),
                    'posts'             => absint( $state['posts_total'] ),
                ),
                admin_url( 'tools.php' )
            );
            wp_safe_redirect( $redirect );
            exit;
        }

        if ( 'delete_unused' === $action ) {
            $state = $this->start_unused_delete();
            $redirect = add_query_arg(
                array(
                    'page'              => 'breanm-media-usage',
                    'breanm_mum_notice' => 'delete_started',
                    'to_delete'         => absint( $state['total'] ),
                ),
                admin_url( 'tools.php' )
            );
            wp_safe_redirect( $redirect );
            exit;
        }
    }

    public function admin_notices() {
        if ( empty( $_GET['page'] ) || 'breanm-media-usage' !== $_GET['page'] || empty( $_GET['breanm_mum_notice'] ) ) {
            return;
        }

        $notice = sanitize_key( wp_unslash( $_GET['breanm_mum_notice'] ) );

        if ( 'refreshed' === $notice ) {
            $scanned = isset( $_GET['scanned'] ) ? absint( $_GET['scanned'] ) : 0;
            $used    = isset( $_GET['used'] ) ? absint( $_GET['used'] ) : 0;
            $unused  = isset( $_GET['unused'] ) ? absint( $_GET['unused'] ) : 0;
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( 'Media usage refreshed. Scanned: %d. Used: %d. Unused: %d.', $scanned, $used, $unused ) ) . '</p></div>';
        }

        if ( 'scan_started' === $notice ) {
            $attachments = isset( $_GET['attachments'] ) ? absint( $_GET['attachments'] ) : 0;
            $posts       = isset( $_GET['posts'] ) ? absint( $_GET['posts'] ) : 0;
            echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( sprintf( 'Media usage scan started. Images: %d. Content items: %d. Keep this page open until the progress bar finishes.', $attachments, $posts ) ) . '</p></div>';
        }

        if ( 'delete_started' === $notice ) {
            $to_delete = isset( $_GET['to_delete'] ) ? absint( $_GET['to_delete'] ) : 0;
            echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( sprintf( 'Unused image deletion started. Images queued: %d. Keep this page open until it finishes.', $to_delete ) ) . '</p></div>';
        }

        if ( 'deleted' === $notice ) {
            $deleted = isset( $_GET['deleted'] ) ? absint( $_GET['deleted'] ) : 0;
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( 'Deleted %d unused image(s).', $deleted ) ) . '</p></div>';
        }
    }

    public function render_tools_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $last_scan    = get_option( self::OPTION_LAST_SCAN );
        $scan_state   = get_option( self::OPTION_SCAN_STATE );
        $delete_state = get_option( self::OPTION_DELETE_STATE );
        $counts       = $this->get_usage_counts();
        $view      = isset( $_GET['usage_status'] ) ? sanitize_key( wp_unslash( $_GET['usage_status'] ) ) : 'all';
        $paged     = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;

        $attachments_query = $this->get_attachments_query( $view, $paged );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Media Usage', 'media-usage-manager' ); ?></h1>

            <p><?php esc_html_e( 'Refresh image usage status, review used and unused images, and delete images that are not currently used as featured images or embedded in post/page content.', 'media-usage-manager' ); ?></p>

            <div class="notice notice-warning inline">
                <p><strong><?php esc_html_e( 'Before deleting:', 'media-usage-manager' ); ?></strong> <?php esc_html_e( 'Make a database and uploads backup. This plugin detects featured images and images embedded in post/page content, but it cannot guarantee detection of every theme, page builder, shortcode, widget, CSS, custom field, or hard-coded image reference.', 'media-usage-manager' ); ?></p>
            </div>

            <p>
                <?php
                if ( $last_scan ) {
                    echo esc_html( sprintf( 'Last scan: %s', date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), absint( $last_scan ) ) ) );
                } else {
                    esc_html_e( 'Last scan: never', 'media-usage-manager' );
                }
                ?>
            </p>

            <form method="post" style="display:inline-block; margin-right: 12px;">
                <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
                <input type="hidden" name="breanm_mum_action" value="refresh" />
                <?php submit_button( __( 'Refresh Used / Unused Status', 'media-usage-manager' ), 'primary', 'submit', false ); ?>
            </form>

            <form method="post" style="display:inline-block;" onsubmit="return confirm('Delete all images currently marked unused? This cannot be undone. Please confirm you have a backup.');">
                <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
                <input type="hidden" name="breanm_mum_action" value="delete_unused" />
                <?php submit_button( __( 'Delete All Unused Images', 'media-usage-manager' ), 'delete', 'submit', false ); ?>
            </form>

            <?php if ( is_array( $scan_state ) && empty( $scan_state['complete'] ) ) : ?>
                <div id="breanm-mum-scan-progress" class="notice notice-info inline" style="margin-top:16px; padding:12px;">
                    <p><strong><?php esc_html_e( 'Scan in progress', 'media-usage-manager' ); ?></strong></p>
                    <progress value="0" max="100" style="width:100%; height:24px;"></progress>
                    <p class="breanm-mum-progress-text"><?php esc_html_e( 'Starting batch scan...', 'media-usage-manager' ); ?></p>
                </div>
            <?php endif; ?>

            <?php if ( is_array( $delete_state ) && empty( $delete_state['complete'] ) ) : ?>
                <div id="breanm-mum-delete-progress" class="notice notice-warning inline" style="margin-top:16px; padding:12px;">
                    <p><strong><?php esc_html_e( 'Delete in progress', 'media-usage-manager' ); ?></strong></p>
                    <progress value="0" max="100" style="width:100%; height:24px;"></progress>
                    <p class="breanm-mum-progress-text"><?php esc_html_e( 'Starting batched delete...', 'media-usage-manager' ); ?></p>
                </div>
            <?php endif; ?>

            <?php $this->render_batch_runner_script(); ?>

            <hr />

            <ul class="subsubsub">
                <li><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'breanm-media-usage', 'usage_status' => 'all', 'paged' => false ), admin_url( 'tools.php' ) ) ); ?>" class="<?php echo 'all' === $view ? 'current' : ''; ?>"><?php echo esc_html( sprintf( 'All (%d)', $counts['all'] ) ); ?></a> | </li>
                <li><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'breanm-media-usage', 'usage_status' => 'used', 'paged' => false ), admin_url( 'tools.php' ) ) ); ?>" class="<?php echo 'used' === $view ? 'current' : ''; ?>"><?php echo esc_html( sprintf( 'Used (%d)', $counts['used'] ) ); ?></a> | </li>
                <li><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'breanm-media-usage', 'usage_status' => 'unused', 'paged' => false ), admin_url( 'tools.php' ) ) ); ?>" class="<?php echo 'unused' === $view ? 'current' : ''; ?>"><?php echo esc_html( sprintf( 'Unused (%d)', $counts['unused'] ) ); ?></a> | </li>
                <li><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'breanm-media-usage', 'usage_status' => 'unknown', 'paged' => false ), admin_url( 'tools.php' ) ) ); ?>" class="<?php echo 'unknown' === $view ? 'current' : ''; ?>"><?php echo esc_html( sprintf( 'Unknown (%d)', $counts['unknown'] ) ); ?></a></li>
            </ul>

            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:70px;"><?php esc_html_e( 'Image', 'media-usage-manager' ); ?></th>
                        <th><?php esc_html_e( 'Title', 'media-usage-manager' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'media-usage-manager' ); ?></th>
                        <th><?php esc_html_e( 'Used In', 'media-usage-manager' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( $attachments_query->have_posts() ) : ?>
                        <?php while ( $attachments_query->have_posts() ) : $attachments_query->the_post(); ?>
                            <?php
                            $attachment_id = get_the_ID();
                            $status        = get_post_meta( $attachment_id, self::META_STATUS, true );
                            $used_in       = get_post_meta( $attachment_id, self::META_USED_IN, true );
                            if ( ! is_array( $used_in ) ) {
                                $used_in = array();
                            }
                            ?>
                            <tr>
                                <td><?php echo wp_get_attachment_image( $attachment_id, array( 60, 60 ), true ); ?></td>
                                <td><a href="<?php echo esc_url( get_edit_post_link( $attachment_id ) ); ?>"><?php echo esc_html( get_the_title( $attachment_id ) ); ?></a><br /><code><?php echo esc_html( basename( get_attached_file( $attachment_id ) ) ); ?></code></td>
                                <td><?php echo wp_kses_post( $this->status_badge( $status ) ); ?></td>
                                <td><?php echo wp_kses_post( $this->format_used_in( $used_in ) ); ?></td>
                            </tr>
                        <?php endwhile; wp_reset_postdata(); ?>
                    <?php else : ?>
                        <tr><td colspan="4"><?php esc_html_e( 'No images found for this view.', 'media-usage-manager' ); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php
            $total_pages = max( 1, absint( $attachments_query->max_num_pages ) );
            if ( $total_pages > 1 ) {
                echo '<div class="tablenav"><div class="tablenav-pages">';
                echo wp_kses_post( paginate_links( array(
                    'base'      => add_query_arg( array( 'page' => 'breanm-media-usage', 'usage_status' => $view, 'paged' => '%#%' ), admin_url( 'tools.php' ) ),
                    'format'    => '',
                    'current'   => $paged,
                    'total'     => $total_pages,
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                ) ) );
                echo '</div></div>';
            }
            ?>
        </div>
        <?php
    }

    private function get_attachments_query( $view, $paged ) {
        $args = array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'image',
            'posts_per_page' => 25,
            'paged'          => $paged,
            'orderby'        => 'date',
            'order'          => 'DESC',
        );

        if ( in_array( $view, array( 'used', 'unused' ), true ) ) {
            $args['meta_query'] = array(
                array(
                    'key'   => self::META_STATUS,
                    'value' => $view,
                ),
            );
        } elseif ( 'unknown' === $view ) {
            $args['meta_query'] = array(
                'relation' => 'OR',
                array(
                    'key'     => self::META_STATUS,
                    'compare' => 'NOT EXISTS',
                ),
                array(
                    'key'   => self::META_STATUS,
                    'value' => '',
                ),
            );
        }

        return new WP_Query( $args );
    }

    private function get_usage_counts() {
        $all = new WP_Query( array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'image',
            'fields'         => 'ids',
            'posts_per_page' => 1,
        ) );

        $used = new WP_Query( array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'image',
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'meta_query'     => array( array( 'key' => self::META_STATUS, 'value' => 'used' ) ),
        ) );

        $unused = new WP_Query( array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'image',
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'meta_query'     => array( array( 'key' => self::META_STATUS, 'value' => 'unused' ) ),
        ) );

        return array(
            'all'     => absint( $all->found_posts ),
            'used'    => absint( $used->found_posts ),
            'unused'  => absint( $unused->found_posts ),
            'unknown' => max( 0, absint( $all->found_posts ) - absint( $used->found_posts ) - absint( $unused->found_posts ) ),
        );
    }

    private function render_batch_runner_script() {
        $nonce = wp_create_nonce( self::NONCE_ACTION );
        ?>
        <script>
            (function($){
                function runBatch(action, boxSelector){
                    var $box = $(boxSelector);
                    if (!$box.length) {
                        return;
                    }

                    var $bar = $box.find('progress');
                    var $text = $box.find('.breanm-mum-progress-text');

                    function tick(){
                        $.post(ajaxurl, {
                            action: action,
                            nonce: '<?php echo esc_js( $nonce ); ?>'
                        }).done(function(response){
                            if (!response || !response.success) {
                                var message = response && response.data && response.data.message ? response.data.message : 'Batch failed. Check the PHP error log for details.';
                                $box.removeClass('notice-info notice-warning').addClass('notice-error');
                                $text.text(message);
                                return;
                            }

                            var data = response.data;
                            $bar.val(data.percent || 0);
                            $text.text(data.message || 'Processing...');

                            if (data.complete) {
                                window.location = data.redirect || window.location.href;
                                return;
                            }

                            window.setTimeout(tick, 250);
                        }).fail(function(xhr){
                            $box.removeClass('notice-info notice-warning').addClass('notice-error');
                            $text.text('Batch request failed with HTTP ' + xhr.status + '. Reload this page to continue, or check the PHP/server logs.');
                        });
                    }

                    tick();
                }

                $(function(){
                    runBatch('breanm_mum_process_scan', '#breanm-mum-scan-progress');
                    runBatch('breanm_mum_process_delete', '#breanm-mum-delete-progress');
                });
            })(jQuery);
        </script>
        <?php
    }

    private function count_query_total( $args ) {
        $args = wp_parse_args( $args, array(
            'fields'         => 'ids',
            'posts_per_page' => 1,
        ) );
        $query = new WP_Query( $args );
        return absint( $query->found_posts );
    }

    private function get_scannable_post_types() {
        $public_types = get_post_types( array( 'public' => true ), 'names' );
        $ui_types     = get_post_types( array( 'show_ui' => true ), 'names' );
        $types        = array_merge( array( 'post', 'page' ), $public_types, $ui_types );
        $excluded     = array( 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block' );
        $types        = array_diff( array_unique( $types ), $excluded );
        return apply_filters( 'breanm_mum_scannable_post_types', array_values( $types ) );
    }

    private function get_posts_total_for_scan() {
        return $this->count_query_total( array(
            'post_type'      => $this->get_scannable_post_types(),
            'post_status'    => 'any',
            'posts_per_page' => 1,
        ) );
    }

    private function get_attachments_total_for_scan() {
        return $this->count_query_total( array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'image',
            'posts_per_page' => 1,
        ) );
    }

    private function start_usage_scan() {
        $state = array(
            'started'           => time(),
            'phase'             => 'attachments',
            'attachment_offset' => 0,
            'post_offset'       => 0,
            'attachments_total' => $this->get_attachments_total_for_scan(),
            'posts_total'       => $this->get_posts_total_for_scan(),
            'used'              => 0,
            'scanned_posts'     => 0,
            'complete'          => false,
        );

        update_option( self::OPTION_SCAN_STATE, $state, false );
        return $state;
    }

    private function start_unused_delete() {
        $state = array(
            'started'  => time(),
            'deleted'  => 0,
            'failed'   => 0,
            'total'    => $this->count_query_total( array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'post_mime_type' => 'image',
                'meta_query'     => array( array( 'key' => self::META_STATUS, 'value' => 'unused' ) ),
            ) ),
            'complete' => false,
        );

        update_option( self::OPTION_DELETE_STATE, $state, false );
        return $state;
    }

    public function ajax_process_scan() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'media-usage-manager' ) ), 403 );
        }

        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        @set_time_limit( 25 );

        $state = get_option( self::OPTION_SCAN_STATE );
        if ( ! is_array( $state ) || ! empty( $state['complete'] ) ) {
            wp_send_json_success( array(
                'complete' => true,
                'percent'  => 100,
                'message'  => __( 'No active scan.', 'media-usage-manager' ),
                'redirect' => add_query_arg( array( 'page' => 'breanm-media-usage' ), admin_url( 'tools.php' ) ),
            ) );
        }

        if ( empty( $state['phase'] ) || 'attachments' === $state['phase'] ) {
            $state = $this->process_attachment_initialization_batch( $state );
        } else {
            $state = $this->process_content_scan_batch( $state );
        }

        update_option( self::OPTION_SCAN_STATE, $state, false );

        if ( ! empty( $state['complete'] ) ) {
            update_option( self::OPTION_LAST_SCAN, time(), false );
            $redirect = add_query_arg(
                array(
                    'page'              => 'breanm-media-usage',
                    'breanm_mum_notice' => 'refreshed',
                    'scanned'           => absint( $state['attachments_total'] ),
                    'used'              => absint( $state['used'] ),
                    'unused'            => max( 0, absint( $state['attachments_total'] ) - absint( $state['used'] ) ),
                ),
                admin_url( 'tools.php' )
            );

            wp_send_json_success( array(
                'complete' => true,
                'percent'  => 100,
                'message'  => __( 'Scan complete.', 'media-usage-manager' ),
                'redirect' => $redirect,
            ) );
        }

        $total_units = max( 1, absint( $state['attachments_total'] ) + absint( $state['posts_total'] ) );
        $done_units  = min( $total_units, absint( $state['attachment_offset'] ) + absint( $state['post_offset'] ) );
        $percent     = min( 99, floor( ( $done_units / $total_units ) * 100 ) );
        $message     = 'attachments' === $state['phase']
            ? sprintf( __( 'Preparing images: %1$d of %2$d', 'media-usage-manager' ), min( absint( $state['attachment_offset'] ), absint( $state['attachments_total'] ) ), absint( $state['attachments_total'] ) )
            : sprintf( __( 'Scanning content: %1$d of %2$d', 'media-usage-manager' ), min( absint( $state['post_offset'] ), absint( $state['posts_total'] ) ), absint( $state['posts_total'] ) );

        wp_send_json_success( array(
            'complete' => false,
            'percent'  => $percent,
            'message'  => $message,
        ) );
    }

    private function process_attachment_initialization_batch( $state ) {
        $offset = isset( $state['attachment_offset'] ) ? absint( $state['attachment_offset'] ) : 0;

        $ids = get_posts( array(
            'post_type'              => 'attachment',
            'post_status'            => 'inherit',
            'post_mime_type'         => 'image',
            'fields'                 => 'ids',
            'posts_per_page'         => self::ATTACHMENT_BATCH,
            'offset'                 => $offset,
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ) );

        foreach ( $ids as $attachment_id ) {
            update_post_meta( $attachment_id, self::META_STATUS, 'unused' );
            update_post_meta( $attachment_id, self::META_USED_IN, array() );
        }

        $state['attachment_offset'] = $offset + count( $ids );
        if ( count( $ids ) < self::ATTACHMENT_BATCH || $state['attachment_offset'] >= absint( $state['attachments_total'] ) ) {
            $state['phase'] = 'posts';
        } else {
            $state['phase'] = 'attachments';
        }

        return $state;
    }

    private function process_content_scan_batch( $state ) {
        $offset = isset( $state['post_offset'] ) ? absint( $state['post_offset'] ) : 0;

        $posts = get_posts( array(
            'post_type'              => $this->get_scannable_post_types(),
            'post_status'            => 'any',
            'posts_per_page'         => self::POST_BATCH,
            'offset'                 => $offset,
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ) );

        foreach ( $posts as $post ) {
            $featured_id = absint( get_post_thumbnail_id( $post->ID ) );
            if ( $featured_id ) {
                $this->mark_attachment_used( $featured_id, $post->ID, 'featured' );
            }

            $embedded_ids = $this->find_attachment_ids_in_content_fast( $post->post_content );
            foreach ( $embedded_ids as $attachment_id ) {
                $this->mark_attachment_used( $attachment_id, $post->ID, 'content' );
            }
        }

        $state['post_offset'] = $offset + count( $posts );
        if ( count( $posts ) < self::POST_BATCH || $state['post_offset'] >= absint( $state['posts_total'] ) ) {
            $state['phase']    = 'complete';
            $state['complete'] = true;
            $state['used']     = $this->count_query_total( array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'post_mime_type' => 'image',
                'meta_query'     => array( array( 'key' => self::META_STATUS, 'value' => 'used' ) ),
            ) );
        } else {
            $state['phase'] = 'posts';
        }

        return $state;
    }

    private function mark_attachment_used( $attachment_id, $post_id, $type ) {
        $attachment_id = absint( $attachment_id );
        $post_id       = absint( $post_id );

        if ( ! $attachment_id || ! $post_id || 'attachment' !== get_post_type( $attachment_id ) || 0 !== strpos( (string) get_post_mime_type( $attachment_id ), 'image/' ) ) {
            return;
        }

        $used_in = get_post_meta( $attachment_id, self::META_USED_IN, true );
        if ( ! is_array( $used_in ) ) {
            $used_in = array();
        }

        $key = sanitize_key( $type ) . '_' . $post_id;
        $map = array();
        foreach ( $used_in as $usage ) {
            if ( empty( $usage['post_id'] ) ) {
                continue;
            }
            $usage_type = ! empty( $usage['type'] ) ? sanitize_key( $usage['type'] ) : 'content';
            $map[ $usage_type . '_' . absint( $usage['post_id'] ) ] = array(
                'post_id' => absint( $usage['post_id'] ),
                'type'    => $usage_type,
            );
        }

        $map[ $key ] = array(
            'post_id' => $post_id,
            'type'    => sanitize_key( $type ),
        );

        update_post_meta( $attachment_id, self::META_STATUS, 'used' );
        update_post_meta( $attachment_id, self::META_USED_IN, array_values( $map ) );
    }

    private function find_attachment_ids_in_content_fast( $content ) {
        $found = array();

        if ( empty( $content ) ) {
            return $found;
        }

        $content_unescaped = html_entity_decode( $content, ENT_QUOTES, get_bloginfo( 'charset' ) );
        $content_unescaped = str_replace( '\\/', '/', $content_unescaped );
        $content_unescaped = rawurldecode( $content_unescaped );

        if ( preg_match_all( '/wp-image-(\d+)/', $content_unescaped, $matches ) ) {
            foreach ( $matches[1] as $id ) {
                $found[] = absint( $id );
            }
        }

        if ( preg_match_all( '/\[gallery[^\]]*ids=["\']([^"\']+)["\'][^\]]*\]/', $content_unescaped, $matches ) ) {
            foreach ( $matches[1] as $ids_string ) {
                foreach ( explode( ',', $ids_string ) as $id ) {
                    $found[] = absint( trim( $id ) );
                }
            }
        }

        if ( preg_match_all( '/"(?:id|mediaId)"\s*:\s*(\d+)/', $content_unescaped, $matches ) ) {
            foreach ( $matches[1] as $id ) {
                $found[] = absint( $id );
            }
        }

        foreach ( $this->extract_upload_paths_from_content( $content_unescaped ) as $upload_path ) {
            foreach ( $this->find_attachment_ids_by_upload_path( $upload_path ) as $attachment_id ) {
                $found[] = absint( $attachment_id );
            }
        }

        return array_values( array_unique( array_filter( array_map( 'absint', $found ) ) ) );
    }

    private function extract_upload_paths_from_content( $content ) {
        $paths      = array();
        $upload_dir = wp_get_upload_dir();
        $base_path  = '';

        if ( ! empty( $upload_dir['baseurl'] ) ) {
            $base_path = wp_parse_url( $upload_dir['baseurl'], PHP_URL_PATH );
            $base_path = $base_path ? '/' . trim( $base_path, '/' ) : '';
        }

        $quoted_url_pattern = '/["\']([^"\']+\.(?:jpe?g|png|gif|webp|avif|svg)(?:\?[^"\']*)?)["\']/i';
        if ( preg_match_all( $quoted_url_pattern, $content, $matches ) ) {
            foreach ( $matches[1] as $url ) {
                $path = wp_parse_url( $url, PHP_URL_PATH );
                if ( ! $path ) {
                    $path = $url;
                }
                $relative = $this->strip_upload_base_path( $path );
                if ( $relative && $relative !== ltrim( $path, '/' ) || ( $base_path && 0 === strpos( '/' . ltrim( $path, '/' ), $base_path . '/' ) ) ) {
                    $paths[] = $relative;
                }
            }
        }

        if ( preg_match_all( '/\/wp-content\/uploads\/([^\s"\'<>\)]+\.(?:jpe?g|png|gif|webp|avif|svg))(?:\?[^\s"\'<>\)]*)?/i', $content, $matches ) ) {
            foreach ( $matches[1] as $relative ) {
                $paths[] = $relative;
            }
        }

        $paths = array_map( array( $this, 'normalize_match_token' ), $paths );
        $paths = array_map( function( $path ) {
            return ltrim( strtok( $path, '?' ), '/' );
        }, $paths );

        return array_values( array_unique( array_filter( $paths ) ) );
    }

    private function find_attachment_ids_by_upload_path( $upload_path ) {
        global $wpdb;

        static $cache = array();

        $upload_path = ltrim( $this->normalize_match_token( $upload_path ), '/' );
        if ( '' === $upload_path ) {
            return array();
        }

        if ( isset( $cache[ $upload_path ] ) ) {
            return $cache[ $upload_path ];
        }

        $variants = array_values( array_unique( array_filter( array(
            $upload_path,
            $this->strip_image_size_suffix( $upload_path ),
        ) ) ) );

        $ids = array();
        foreach ( $variants as $variant ) {
            $exact_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 20",
                $variant
            ) );
            foreach ( $exact_ids as $id ) {
                $ids[] = absint( $id );
            }
        }

        if ( empty( $ids ) ) {
            $basename = wp_basename( $upload_path );
            if ( $basename ) {
                $like = '%' . $wpdb->esc_like( $basename ) . '%';
                $metadata_ids = $wpdb->get_col( $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_metadata' AND meta_value LIKE %s LIMIT 20",
                    $like
                ) );
                foreach ( $metadata_ids as $id ) {
                    $ids[] = absint( $id );
                }
            }
        }

        $ids = array_values( array_unique( array_filter( $ids ) ) );
        $cache[ $upload_path ] = $ids;
        return $ids;
    }

    private function strip_image_size_suffix( $path ) {
        return preg_replace( '/-\d+x\d+(?=\.(?:jpe?g|png|gif|webp|avif|svg)$)/i', '', $path );
    }

    public function ajax_process_delete() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'media-usage-manager' ) ), 403 );
        }

        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        @set_time_limit( 25 );

        $state = get_option( self::OPTION_DELETE_STATE );
        if ( ! is_array( $state ) || ! empty( $state['complete'] ) ) {
            wp_send_json_success( array(
                'complete' => true,
                'percent'  => 100,
                'message'  => __( 'No active delete job.', 'media-usage-manager' ),
                'redirect' => add_query_arg( array( 'page' => 'breanm-media-usage' ), admin_url( 'tools.php' ) ),
            ) );
        }

        $ids = get_posts( array(
            'post_type'              => 'attachment',
            'post_status'            => 'inherit',
            'post_mime_type'         => 'image',
            'fields'                 => 'ids',
            'posts_per_page'         => self::DELETE_BATCH,
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'meta_query'             => array( array( 'key' => self::META_STATUS, 'value' => 'unused' ) ),
        ) );

        foreach ( $ids as $attachment_id ) {
            if ( wp_delete_attachment( $attachment_id, true ) ) {
                $state['deleted'] = absint( $state['deleted'] ) + 1;
            } else {
                $state['failed'] = absint( $state['failed'] ) + 1;
                update_post_meta( $attachment_id, self::META_STATUS, 'delete_failed' );
            }
        }

        $processed = absint( $state['deleted'] ) + absint( $state['failed'] );
        if ( empty( $ids ) || $processed >= absint( $state['total'] ) ) {
            $state['complete'] = true;
            update_option( self::OPTION_DELETE_STATE, $state, false );
            $redirect = add_query_arg(
                array(
                    'page'              => 'breanm-media-usage',
                    'breanm_mum_notice' => 'deleted',
                    'deleted'           => absint( $state['deleted'] ),
                ),
                admin_url( 'tools.php' )
            );
            wp_send_json_success( array(
                'complete' => true,
                'percent'  => 100,
                'message'  => __( 'Delete complete.', 'media-usage-manager' ),
                'redirect' => $redirect,
            ) );
        }

        update_option( self::OPTION_DELETE_STATE, $state, false );
        $processed = absint( $state['deleted'] ) + absint( $state['failed'] );
        $percent = absint( $state['total'] ) ? min( 99, floor( ( $processed / absint( $state['total'] ) ) * 100 ) ) : 100;

        wp_send_json_success( array(
            'complete' => false,
            'percent'  => $percent,
            'message'  => sprintf( __( 'Processed %1$d of about %2$d unused images. Deleted: %3$d. Failed: %4$d.', 'media-usage-manager' ), $processed, absint( $state['total'] ), absint( $state['deleted'] ), absint( $state['failed'] ) ),
        ) );
    }

    public function refresh_usage_statuses() {
        @set_time_limit( 0 );

        $attachments = get_posts( array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'image',
            'fields'         => 'ids',
            'posts_per_page' => -1,
        ) );

        $usage_map = array();
        foreach ( $attachments as $attachment_id ) {
            $usage_map[ $attachment_id ] = array();
        }

        $content_posts = get_posts( array(
            'post_type'      => array( 'post', 'page' ),
            'post_status'    => 'any',
            'posts_per_page' => -1,
        ) );

        foreach ( $content_posts as $post ) {
            $featured_id = absint( get_post_thumbnail_id( $post->ID ) );
            if ( $featured_id && isset( $usage_map[ $featured_id ] ) ) {
                $usage_map[ $featured_id ][ 'featured_' . $post->ID ] = array(
                    'post_id' => $post->ID,
                    'type'    => 'featured',
                );
            }

            $embedded_ids = $this->find_attachment_ids_in_content( $post->post_content, $attachments );
            foreach ( $embedded_ids as $attachment_id ) {
                if ( isset( $usage_map[ $attachment_id ] ) ) {
                    $usage_map[ $attachment_id ][ 'content_' . $post->ID ] = array(
                        'post_id' => $post->ID,
                        'type'    => 'content',
                    );
                }
            }
        }

        $used_count = 0;
        foreach ( $attachments as $attachment_id ) {
            $used_in = array_values( $usage_map[ $attachment_id ] );
            $status  = empty( $used_in ) ? 'unused' : 'used';

            update_post_meta( $attachment_id, self::META_STATUS, $status );
            update_post_meta( $attachment_id, self::META_USED_IN, $used_in );

            if ( 'used' === $status ) {
                $used_count++;
            }
        }

        update_option( self::OPTION_LAST_SCAN, time(), false );

        return array(
            'scanned' => count( $attachments ),
            'used'    => $used_count,
            'unused'  => count( $attachments ) - $used_count,
        );
    }

    private function find_attachment_ids_in_content( $content, $attachment_ids ) {
        $found = array();

        if ( empty( $content ) ) {
            return $found;
        }

        $content_unescaped = html_entity_decode( $content, ENT_QUOTES, get_bloginfo( 'charset' ) );
        $content_unescaped = str_replace( '\\/', '/', $content_unescaped );
        $content_unescaped = rawurldecode( $content_unescaped );
        $content_lc        = strtolower( $content_unescaped );

        if ( preg_match_all( '/wp-image-(\d+)/', $content_unescaped, $matches ) ) {
            foreach ( $matches[1] as $id ) {
                $found[] = absint( $id );
            }
        }

        if ( preg_match_all( '/\[gallery[^\]]*ids=["\']([^"\']+)["\'][^\]]*\]/', $content_unescaped, $matches ) ) {
            foreach ( $matches[1] as $ids_string ) {
                foreach ( explode( ',', $ids_string ) as $id ) {
                    $found[] = absint( trim( $id ) );
                }
            }
        }

        if ( false !== strpos( $content_unescaped, 'wp:cover' ) || false !== strpos( $content_unescaped, 'wp:image' ) || false !== strpos( $content_unescaped, 'wp:media-text' ) ) {
            if ( preg_match_all( '/"(?:id|mediaId)"\s*:\s*(\d+)/', $content_unescaped, $matches ) ) {
                foreach ( $matches[1] as $id ) {
                    $found[] = absint( $id );
                }
            }
        }

        foreach ( $attachment_ids as $attachment_id ) {
            if ( in_array( $attachment_id, $found, true ) ) {
                continue;
            }

            $tokens = $this->get_domain_independent_attachment_tokens( $attachment_id );
            foreach ( $tokens as $token ) {
                if ( '' !== $token && false !== strpos( $content_lc, strtolower( $token ) ) ) {
                    $found[] = absint( $attachment_id );
                    break;
                }
            }
        }

        return array_values( array_unique( array_filter( array_map( 'absint', $found ) ) ) );
    }

    private function get_domain_independent_attachment_tokens( $attachment_id ) {
        $tokens = array();

        $file = get_post_meta( $attachment_id, '_wp_attached_file', true );
        if ( $file ) {
            $tokens[] = $file;
            $tokens[] = '/' . ltrim( $file, '/' );
            $tokens[] = wp_basename( $file );
        }

        $url = wp_get_attachment_url( $attachment_id );
        if ( $url ) {
            $tokens[] = $url;
            $url_path = wp_parse_url( $url, PHP_URL_PATH );
            if ( $url_path ) {
                $tokens[] = $url_path;
                $upload_rel_path = $this->strip_upload_base_path( $url_path );
                if ( $upload_rel_path ) {
                    $tokens[] = $upload_rel_path;
                    $tokens[] = '/' . ltrim( $upload_rel_path, '/' );
                }
            }
        }

        $attachment = get_post( $attachment_id );
        if ( $attachment && ! empty( $attachment->guid ) ) {
            $guid_path = wp_parse_url( $attachment->guid, PHP_URL_PATH );
            if ( $guid_path ) {
                $tokens[] = $guid_path;
                $upload_rel_path = $this->strip_upload_base_path( $guid_path );
                if ( $upload_rel_path ) {
                    $tokens[] = $upload_rel_path;
                    $tokens[] = '/' . ltrim( $upload_rel_path, '/' );
                }
            }
        }

        $metadata = wp_get_attachment_metadata( $attachment_id );
        if ( is_array( $metadata ) ) {
            if ( ! empty( $metadata['file'] ) ) {
                $tokens[] = $metadata['file'];
                $tokens[] = '/' . ltrim( $metadata['file'], '/' );
                $tokens[] = wp_basename( $metadata['file'] );
                $base_dir = trailingslashit( dirname( $metadata['file'] ) );
            } else {
                $base_dir = '';
            }

            if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
                foreach ( $metadata['sizes'] as $size ) {
                    if ( empty( $size['file'] ) ) {
                        continue;
                    }

                    $size_file = $size['file'];
                    $tokens[]  = $size_file;
                    $tokens[]  = wp_basename( $size_file );

                    if ( $base_dir && 0 !== strpos( $size_file, '/' ) && false === strpos( $size_file, '/' ) ) {
                        $tokens[] = $base_dir . $size_file;
                        $tokens[] = '/' . ltrim( $base_dir . $size_file, '/' );
                    }
                }
            }
        }

        $tokens = array_filter( array_map( array( $this, 'normalize_match_token' ), $tokens ) );
        return array_values( array_unique( $tokens ) );
    }

    private function strip_upload_base_path( $path ) {
        $path       = '/' . ltrim( (string) $path, '/' );
        $upload_dir = wp_get_upload_dir();

        if ( empty( $upload_dir['baseurl'] ) ) {
            return ltrim( $path, '/' );
        }

        $base_path = wp_parse_url( $upload_dir['baseurl'], PHP_URL_PATH );
        if ( ! $base_path ) {
            return ltrim( $path, '/' );
        }

        $base_path = '/' . trim( $base_path, '/' );
        if ( 0 === strpos( $path, $base_path . '/' ) ) {
            return ltrim( substr( $path, strlen( $base_path ) + 1 ), '/' );
        }

        return ltrim( $path, '/' );
    }

    private function normalize_match_token( $token ) {
        $token = html_entity_decode( (string) $token, ENT_QUOTES, get_bloginfo( 'charset' ) );
        $token = str_replace( '\\/', '/', $token );
        $token = rawurldecode( $token );
        $token = trim( $token );

        return $token;
    }

    public function delete_unused_images() {
        $unused = get_posts( array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'image',
            'fields'         => 'ids',
            'posts_per_page' => -1,
            'meta_query'     => array(
                array(
                    'key'   => self::META_STATUS,
                    'value' => 'unused',
                ),
            ),
        ) );

        $deleted = 0;
        foreach ( $unused as $attachment_id ) {
            $result = wp_delete_attachment( $attachment_id, true );
            if ( $result ) {
                $deleted++;
            }
        }

        return $deleted;
    }

    public function add_featured_image_column( $columns ) {
        $new_columns = array();
        foreach ( $columns as $key => $label ) {
            $new_columns[ $key ] = $label;
            if ( 'title' === $key ) {
                $new_columns['breanm_featured_image'] = __( 'Featured Image', 'media-usage-manager' );
            }
        }
        return $new_columns;
    }

    public function render_featured_image_column( $column, $post_id ) {
        if ( 'breanm_featured_image' !== $column ) {
            return;
        }

        $thumbnail_id = get_post_thumbnail_id( $post_id );
        if ( $thumbnail_id ) {
            echo wp_get_attachment_image( $thumbnail_id, array( 50, 50 ), true );
            echo '<br /><span style="color: #008a20; font-weight: 600;">' . esc_html__( 'Yes', 'media-usage-manager' ) . '</span>';
        } else {
            echo '<span style="color: #b32d2e; font-weight: 600;">' . esc_html__( 'No', 'media-usage-manager' ) . '</span>';
        }
    }

    public function add_featured_image_filter( $post_type ) {
        if ( ! in_array( $post_type, array( 'post', 'page' ), true ) ) {
            return;
        }

        $selected = isset( $_GET['breanm_featured_image_filter'] ) ? sanitize_key( wp_unslash( $_GET['breanm_featured_image_filter'] ) ) : '';
        ?>
        <select name="breanm_featured_image_filter">
            <option value=""><?php esc_html_e( 'All featured image statuses', 'media-usage-manager' ); ?></option>
            <option value="has" <?php selected( $selected, 'has' ); ?>><?php esc_html_e( 'Has featured image', 'media-usage-manager' ); ?></option>
            <option value="missing" <?php selected( $selected, 'missing' ); ?>><?php esc_html_e( 'Missing featured image', 'media-usage-manager' ); ?></option>
        </select>
        <?php
    }

    public function filter_posts_by_featured_image( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }

        global $pagenow;
        if ( 'edit.php' !== $pagenow ) {
            return;
        }

        $post_type = $query->get( 'post_type' );
        if ( empty( $post_type ) ) {
            $post_type = 'post';
        }

        if ( ! in_array( $post_type, array( 'post', 'page' ), true ) ) {
            return;
        }

        if ( empty( $_GET['breanm_featured_image_filter'] ) ) {
            return;
        }

        $filter = sanitize_key( wp_unslash( $_GET['breanm_featured_image_filter'] ) );
        $meta_query = (array) $query->get( 'meta_query' );

        if ( 'has' === $filter ) {
            $meta_query[] = array(
                'key'     => '_thumbnail_id',
                'compare' => 'EXISTS',
            );
        } elseif ( 'missing' === $filter ) {
            $meta_query[] = array(
                'relation' => 'OR',
                array(
                    'key'     => '_thumbnail_id',
                    'compare' => 'NOT EXISTS',
                ),
                array(
                    'key'   => '_thumbnail_id',
                    'value' => '',
                ),
            );
        }

        $query->set( 'meta_query', $meta_query );
    }

    public function add_media_usage_column( $columns ) {
        $columns['breanm_media_usage'] = __( 'Usage Status', 'media-usage-manager' );
        return $columns;
    }

    public function render_media_usage_column( $column_name, $attachment_id ) {
        if ( 'breanm_media_usage' !== $column_name ) {
            return;
        }

        if ( 0 !== strpos( get_post_mime_type( $attachment_id ), 'image/' ) ) {
            echo '&mdash;';
            return;
        }

        $status  = get_post_meta( $attachment_id, self::META_STATUS, true );
        $used_in = get_post_meta( $attachment_id, self::META_USED_IN, true );
        if ( ! is_array( $used_in ) ) {
            $used_in = array();
        }

        echo wp_kses_post( $this->status_badge( $status ) );
        echo '<div style="margin-top:4px;">' . wp_kses_post( $this->format_used_in( $used_in, 2 ) ) . '</div>';
    }

    public function attachment_usage_field( $form_fields, $post ) {
        if ( 0 !== strpos( get_post_mime_type( $post ), 'image/' ) ) {
            return $form_fields;
        }

        $status  = get_post_meta( $post->ID, self::META_STATUS, true );
        $used_in = get_post_meta( $post->ID, self::META_USED_IN, true );
        if ( ! is_array( $used_in ) ) {
            $used_in = array();
        }

        $form_fields['breanm_media_usage'] = array(
            'label' => __( 'Usage Status', 'media-usage-manager' ),
            'input' => 'html',
            'html'  => $this->status_badge( $status ) . '<br />' . $this->format_used_in( $used_in ),
        );

        return $form_fields;
    }


    public function prepare_attachment_for_js( $response, $attachment, $meta ) {
        if ( empty( $response['type'] ) || 'image' !== $response['type'] ) {
            return $response;
        }

        $status  = get_post_meta( $attachment->ID, self::META_STATUS, true );
        $used_in = get_post_meta( $attachment->ID, self::META_USED_IN, true );
        if ( ! is_array( $used_in ) ) {
            $used_in = array();
        }

        if ( ! in_array( $status, array( 'used', 'unused' ), true ) ) {
            $status = 'unknown';
        }

        $response['breanm_mum_usage_status'] = $status;
        $response['breanm_mum_usage_label']  = ucfirst( $status );
        $response['breanm_mum_usage_html']   = $this->status_badge( $status );
        $response['breanm_mum_used_in_html'] = $this->format_used_in( $used_in, 3 );

        return $response;
    }

    public function enqueue_media_modal_assets( $hook_suffix ) {
        if ( ! is_admin() ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen ) {
            return;
        }

        $allowed_screens = array( 'post', 'page', 'upload' );
        if ( ! in_array( $screen->base, $allowed_screens, true ) && ! in_array( $hook_suffix, array( 'post.php', 'post-new.php', 'upload.php' ), true ) ) {
            return;
        }

        wp_register_style( 'breanm-mum-media-modal', false, array(), '1.2.0' );
        wp_enqueue_style( 'breanm-mum-media-modal' );
        wp_add_inline_style( 'breanm-mum-media-modal', '
            .attachments .attachment .breanm-mum-modal-badge { position:absolute; left:6px; bottom:6px; z-index:20; padding:2px 7px; border-radius:999px; font-size:11px; line-height:1.4; font-weight:700; box-shadow:0 1px 2px rgba(0,0,0,.18); }
            .attachments .attachment.breanm-mum-status-used .breanm-mum-modal-badge { background:#d1e7dd; color:#0f5132; }
            .attachments .attachment.breanm-mum-status-unused .breanm-mum-modal-badge { background:#f8d7da; color:#842029; }
            .attachments .attachment.breanm-mum-status-unknown .breanm-mum-modal-badge { background:#fff3cd; color:#664d03; }
            .attachment-details .breanm-mum-sidebar-usage, .media-sidebar .breanm-mum-sidebar-usage { margin-top:12px; padding-top:12px; border-top:1px solid #dcdcde; }
            .attachment-details .breanm-mum-sidebar-usage strong, .media-sidebar .breanm-mum-sidebar-usage strong { display:block; margin-bottom:6px; }
        ' );

        wp_register_script( 'breanm-mum-media-modal', '', array( 'jquery', 'media-views' ), '1.2.0', true );
        wp_enqueue_script( 'breanm-mum-media-modal' );
        wp_add_inline_script( 'breanm-mum-media-modal', <<<'JS'
            (function($){
                function getStatus(model){
                    var status = model && model.get ? model.get('breanm_mum_usage_status') : '';
                    if (status !== 'used' && status !== 'unused') {
                        status = 'unknown';
                    }
                    return status;
                }

                function addBadgeToAttachment(view){
                    if (!view || !view.model || !view.el) {
                        return;
                    }
                    var status = getStatus(view.model);
                    var label = status.charAt(0).toUpperCase() + status.slice(1);
                    var $el = view.$el;
                    $el.removeClass('breanm-mum-status-used breanm-mum-status-unused breanm-mum-status-unknown')
                        .addClass('breanm-mum-status-' + status);
                    $el.find('.breanm-mum-modal-badge').remove();
                    $el.append('<span class="breanm-mum-modal-badge">' + label + '</span>');
                }

                if (wp && wp.media && wp.media.view && wp.media.view.Attachment) {
                    var originalAttachmentRender = wp.media.view.Attachment.prototype.render;
                    wp.media.view.Attachment.prototype.render = function(){
                        var result = originalAttachmentRender.apply(this, arguments);
                        addBadgeToAttachment(this);
                        return result;
                    };
                }

                function updateSidebar(){
                    var frame = wp.media.frame;
                    if (!frame || !frame.state) {
                        return;
                    }

                    var selection = frame.state().get('selection');
                    if (!selection || !selection.length) {
                        return;
                    }

                    var attachment = selection.first();
                    if (!attachment || attachment.get('type') !== 'image') {
                        return;
                    }

                    var statusHtml = attachment.get('breanm_mum_usage_html') || '';
                    var usedInHtml = attachment.get('breanm_mum_used_in_html') || '&mdash;';
                    var html = '<div class="breanm-mum-sidebar-usage"><strong>Usage Status</strong>' + statusHtml + '<div style="margin-top:6px;">' + usedInHtml + '</div></div>';

                    window.setTimeout(function(){
                        var $sidebar = $('.media-sidebar .attachment-details, .attachment-details').first();
                        if (!$sidebar.length) {
                            return;
                        }
                        $sidebar.find('.breanm-mum-sidebar-usage').remove();
                        $sidebar.append(html);
                    }, 50);
                }

                $(document).on('click', '.attachments .attachment', updateSidebar);
                $(document).on('change', '.attachments-browser select, .media-frame-content input, .media-toolbar input', function(){
                    window.setTimeout(function(){
                        $('.attachments .attachment').each(function(){
                            var view = $(this).data('backbone-view');
                            if (view) {
                                addBadgeToAttachment(view);
                            }
                        });
                    }, 250);
                });

                $(document).on('DOMNodeInserted', '.attachments .attachment', function(){
                    var view = $(this).data('backbone-view');
                    if (view) {
                        addBadgeToAttachment(view);
                    }
                });

                if (wp && wp.media && wp.media.frame) {
                    wp.media.frame.on('selection:toggle selection:single selection:unsingle content:render:browse', updateSidebar);
                }
            })(jQuery);
JS
        );
    }

    private function status_badge( $status ) {
        if ( 'used' === $status ) {
            return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#d1e7dd;color:#0f5132;font-weight:600;">Used</span>';
        }

        if ( 'unused' === $status ) {
            return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#f8d7da;color:#842029;font-weight:600;">Unused</span>';
        }

        return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#fff3cd;color:#664d03;font-weight:600;">Unknown</span>';
    }

    private function format_used_in( $used_in, $limit = 0 ) {
        if ( empty( $used_in ) ) {
            return '&mdash;';
        }

        $items = array();
        $count = 0;
        foreach ( $used_in as $usage ) {
            if ( $limit && $count >= $limit ) {
                break;
            }
            $post_id = isset( $usage['post_id'] ) ? absint( $usage['post_id'] ) : 0;
            $type    = isset( $usage['type'] ) ? sanitize_key( $usage['type'] ) : 'content';
            if ( ! $post_id ) {
                continue;
            }
            $title = get_the_title( $post_id );
            if ( ! $title ) {
                $title = sprintf( 'Post #%d', $post_id );
            }
            $label = 'featured' === $type ? __( 'Featured image', 'media-usage-manager' ) : __( 'Embedded image', 'media-usage-manager' );
            $items[] = sprintf(
                '<a href="%s">%s</a> <span style="color:#646970;">(%s)</span>',
                esc_url( get_edit_post_link( $post_id ) ),
                esc_html( $title ),
                esc_html( $label )
            );
            $count++;
        }

        if ( empty( $items ) ) {
            return '&mdash;';
        }

        $more = count( $used_in ) - count( $items );
        if ( $more > 0 ) {
            $items[] = esc_html( sprintf( '+%d more', $more ) );
        }

        return implode( '<br />', $items );
    }
}

BREANM_Media_Usage_Manager::instance();
