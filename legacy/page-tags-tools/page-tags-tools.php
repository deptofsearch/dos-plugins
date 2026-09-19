<?php
/**
 * Plugin Name: Page Tags Tools
 * Description: Adds tag support to WordPress Pages and provides Tools-page utilities plus a Pages bulk action for quickly applying tags.
 * Version: 1.3.0
 * Author: Ryan Rose / ChatGPT
 * License: GPL-2.0-or-later
 * Text Domain: page-tags-tools
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Page_Tags_Tools_Plugin {
    const NONCE_ACTION = 'ptt_add_tag_to_pages';
    const NONCE_NAME   = 'ptt_nonce';
    const MENU_SLUG    = 'page-tags-tools';

    public function __construct() {
        add_action( 'init', array( $this, 'register_page_tags' ) );
        add_action( 'admin_menu', array( $this, 'register_tools_page' ) );
        add_action( 'admin_post_ptt_add_tag_to_pages', array( $this, 'handle_tools_form' ) );

        add_filter( 'manage_pages_columns', array( $this, 'add_tags_column' ) );
        add_action( 'manage_pages_custom_column', array( $this, 'render_tags_column' ), 10, 2 );

        add_filter( 'bulk_actions-edit-page', array( $this, 'register_bulk_action' ) );
        add_filter( 'handle_bulk_actions-edit-page', array( $this, 'handle_bulk_action' ), 10, 3 );
        add_action( 'admin_notices', array( $this, 'bulk_action_notice' ) );
        add_action( 'admin_footer-edit.php', array( $this, 'bulk_action_prompt_script' ) );
        add_action( 'restrict_manage_posts', array( $this, 'render_pages_tag_filter_dropdown' ) );
        add_action( 'pre_get_posts', array( $this, 'filter_pages_by_tag_dropdown' ) );
    }

    public function register_page_tags() {
        // Attach WordPress's built-in post_tag taxonomy to Pages.
        register_taxonomy_for_object_type( 'post_tag', 'page' );
    }

    public function register_tools_page() {
        add_management_page(
            __( 'Page Tags', 'page-tags-tools' ),
            __( 'Page Tags', 'page-tags-tools' ),
            'edit_pages',
            self::MENU_SLUG,
            array( $this, 'render_tools_page' )
        );
    }

    public function add_tags_column( $columns ) {
        $new_columns = array();

        foreach ( $columns as $key => $label ) {
            // WordPress can add its own tag/taxonomy column once post_tag is attached to Pages.
            // Remove those duplicates so the Pages list shows only this plugin's single Tags column.
            if ( in_array( $key, array( 'tags', 'taxonomy-post_tag', 'ptt_page_tags' ), true ) ) {
                continue;
            }

            $new_columns[ $key ] = $label;
            if ( 'title' === $key ) {
                $new_columns['ptt_page_tags'] = __( 'Tags', 'page-tags-tools' );
            }
        }

        if ( ! isset( $new_columns['ptt_page_tags'] ) ) {
            $new_columns['ptt_page_tags'] = __( 'Tags', 'page-tags-tools' );
        }

        return $new_columns;
    }

    public function render_tags_column( $column, $post_id ) {
        if ( 'ptt_page_tags' !== $column ) {
            return;
        }

        $tags = get_the_terms( $post_id, 'post_tag' );

        if ( empty( $tags ) || is_wp_error( $tags ) ) {
            echo '&mdash;';
            return;
        }

        $tag_links = array();
        foreach ( $tags as $tag ) {
            $url = add_query_arg(
                array(
                    'post_type' => 'page',
                    'tag'       => $tag->slug,
                ),
                admin_url( 'edit.php' )
            );
            $tag_links[] = sprintf(
                '<a href="%s">%s</a>',
                esc_url( $url ),
                esc_html( $tag->name )
            );
        }

        echo implode( ', ', $tag_links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    private function get_page_tag_terms() {
        $all_terms = get_terms(
            array(
                'taxonomy'   => 'post_tag',
                'hide_empty' => false,
                'orderby'    => 'name',
                'order'      => 'ASC',
            )
        );

        if ( empty( $all_terms ) || is_wp_error( $all_terms ) ) {
            return array();
        }

        $page_terms = array();

        foreach ( $all_terms as $term ) {
            $page_ids = get_posts(
                array(
                    'post_type'              => 'page',
                    'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
                    'fields'                 => 'ids',
                    'posts_per_page'         => 1,
                    'no_found_rows'          => true,
                    'update_post_meta_cache' => false,
                    'update_post_term_cache' => false,
                    'tax_query'              => array(
                        array(
                            'taxonomy' => 'post_tag',
                            'field'    => 'term_id',
                            'terms'    => array( (int) $term->term_id ),
                        ),
                    ),
                )
            );

            if ( ! empty( $page_ids ) ) {
                $page_terms[] = $term;
            }
        }

        return $page_terms;
    }

    public function render_pages_tag_filter_dropdown( $post_type ) {
        if ( 'page' !== $post_type ) {
            return;
        }

        $terms = $this->get_page_tag_terms();
        if ( empty( $terms ) ) {
            return;
        }

        $selected = isset( $_GET['ptt_page_tag_filter'] ) ? absint( $_GET['ptt_page_tag_filter'] ) : 0;
        ?>
        <label class="screen-reader-text" for="ptt-page-tag-filter"><?php esc_html_e( 'Filter pages by tag', 'page-tags-tools' ); ?></label>
        <select name="ptt_page_tag_filter" id="ptt-page-tag-filter">
            <option value="0"><?php esc_html_e( 'All page tags', 'page-tags-tools' ); ?></option>
            <?php foreach ( $terms as $term ) : ?>
                <option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( $selected, $term->term_id ); ?>><?php echo esc_html( $term->name ); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public function filter_pages_by_tag_dropdown( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }

        global $pagenow;
        if ( 'edit.php' !== $pagenow || 'page' !== $query->get( 'post_type' ) ) {
            return;
        }

        $term_id = isset( $_GET['ptt_page_tag_filter'] ) ? absint( $_GET['ptt_page_tag_filter'] ) : 0;
        if ( ! $term_id ) {
            return;
        }

        $tax_query   = (array) $query->get( 'tax_query' );
        $tax_query[] = array(
            'taxonomy' => 'post_tag',
            'field'    => 'term_id',
            'terms'    => array( $term_id ),
        );

        $query->set( 'tax_query', $tax_query );
    }

    public function render_tools_page() {
        if ( ! current_user_can( 'edit_pages' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'page-tags-tools' ) );
        }

        $search         = isset( $_GET['ptt_search'] ) ? sanitize_text_field( wp_unslash( $_GET['ptt_search'] ) ) : '';
        $case_sensitive = ! empty( $_GET['ptt_case_sensitive'] );
        $pages          = $this->get_tools_pages( $search, $case_sensitive );
        $selected_tag   = isset( $_GET['ptt_tag'] ) ? sanitize_text_field( wp_unslash( $_GET['ptt_tag'] ) ) : '';
        $updated      = isset( $_GET['ptt_updated'] ) ? absint( $_GET['ptt_updated'] ) : 0;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Page Tags', 'page-tags-tools' ); ?></h1>

            <?php if ( $updated > 0 ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html( sprintf( _n( 'Tag added to %d page.', 'Tag added to %d pages.', $updated, 'page-tags-tools' ), $updated ) ); ?></p>
                </div>
            <?php endif; ?>

            <p><?php esc_html_e( 'Select one or more pages, enter a tag, and apply it to keep pages organized.', 'page-tags-tools' ); ?></p>

            <form method="get" action="<?php echo esc_url( admin_url( 'tools.php' ) ); ?>" style="margin: 16px 0 20px;">
                <input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
                <label class="screen-reader-text" for="ptt_search"><?php esc_html_e( 'Search pages by title or tag', 'page-tags-tools' ); ?></label>
                <input type="search" id="ptt_search" name="ptt_search" class="regular-text" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Search page titles or tags', 'page-tags-tools' ); ?>" />
                <label style="margin-left: 8px;">
                    <input type="checkbox" name="ptt_case_sensitive" value="1" <?php checked( $case_sensitive ); ?> />
                    <?php esc_html_e( 'Case-sensitive search', 'page-tags-tools' ); ?>
                </label>
                <?php submit_button( __( 'Search Pages', 'page-tags-tools' ), 'secondary', '', false ); ?>
                <?php if ( '' !== $search ) : ?>
                    <a class="button" href="<?php echo esc_url( add_query_arg( 'page', self::MENU_SLUG, admin_url( 'tools.php' ) ) ); ?>"><?php esc_html_e( 'Clear Search', 'page-tags-tools' ); ?></a>
                <?php endif; ?>
                <p class="description"><?php esc_html_e( 'Search only checks page titles and page tags. Page content is not searched. Enable case-sensitive search when capitalization matters.', 'page-tags-tools' ); ?></p>
            </form>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
                <input type="hidden" name="action" value="ptt_add_tag_to_pages" />
                <input type="hidden" name="ptt_search" value="<?php echo esc_attr( $search ); ?>" />
                <input type="hidden" name="ptt_case_sensitive" value="<?php echo $case_sensitive ? '1' : '0'; ?>" />

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="ptt_tag"><?php esc_html_e( 'Tag to add', 'page-tags-tools' ); ?></label></th>
                        <td>
                            <input name="ptt_tag" id="ptt_tag" type="text" class="regular-text" value="<?php echo esc_attr( $selected_tag ); ?>" required />
                            <p class="description"><?php esc_html_e( 'Enter a new or existing tag name. Multiple words are okay.', 'page-tags-tools' ); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Pages', 'page-tags-tools' ); ?></h2>
                <?php if ( '' !== $search ) : ?>
                    <p><?php echo esc_html( sprintf( $case_sensitive ? __( 'Showing pages whose title or tags match exactly by case: %s', 'page-tags-tools' ) : __( 'Showing pages whose title or tags match: %s', 'page-tags-tools' ), $search ) ); ?></p>
                <?php endif; ?>
                <p>
                    <button type="button" class="button" id="ptt-select-all"><?php esc_html_e( 'Select all', 'page-tags-tools' ); ?></button>
                    <button type="button" class="button" id="ptt-select-none"><?php esc_html_e( 'Select none', 'page-tags-tools' ); ?></button>
                </p>

                <div style="max-height: 520px; overflow: auto; border: 1px solid #ccd0d4; background: #fff; padding: 10px;">
                    <?php if ( empty( $pages ) ) : ?>
                        <p><?php esc_html_e( 'No matching pages found.', 'page-tags-tools' ); ?></p>
                    <?php else : ?>
                        <ul style="margin: 0;">
                            <?php foreach ( $pages as $page ) : ?>
                                <li style="margin-bottom: 8px;">
                                    <label>
                                        <input type="checkbox" name="ptt_page_ids[]" value="<?php echo esc_attr( $page->ID ); ?>" class="ptt-page-checkbox" />
                                        <?php echo esc_html( get_the_title( $page->ID ) ); ?>
                                        <span style="color:#646970;">#<?php echo esc_html( $page->ID ); ?></span>
                                    </label>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <?php submit_button( __( 'Add Tag to Selected Pages', 'page-tags-tools' ) ); ?>
            </form>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const boxes = document.querySelectorAll('.ptt-page-checkbox');
                const selectAll = document.getElementById('ptt-select-all');
                const selectNone = document.getElementById('ptt-select-none');

                if (selectAll) {
                    selectAll.addEventListener('click', function () {
                        boxes.forEach(function (box) { box.checked = true; });
                    });
                }

                if (selectNone) {
                    selectNone.addEventListener('click', function () {
                        boxes.forEach(function (box) { box.checked = false; });
                    });
                }
            });
        </script>
        <?php
    }


    private function get_tools_pages( $search = '', $case_sensitive = false ) {
        $pages = get_pages(
            array(
                'sort_column' => 'post_title',
                'sort_order'  => 'ASC',
                'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ),
            )
        );

        $search = trim( (string) $search );
        if ( '' === $search ) {
            return $pages;
        }

        $needle  = $case_sensitive ? $search : $this->ptt_lower( $search );
        $matches = array();

        foreach ( $pages as $page ) {
            $title    = get_the_title( $page->ID );
            $haystack = $case_sensitive ? $title : $this->ptt_lower( $title );

            if ( false !== strpos( $haystack, $needle ) ) {
                $matches[] = $page;
                continue;
            }

            $tags = get_the_terms( $page->ID, 'post_tag' );
            if ( empty( $tags ) || is_wp_error( $tags ) ) {
                continue;
            }

            foreach ( $tags as $tag ) {
                $tag_name = $case_sensitive ? $tag->name : $this->ptt_lower( $tag->name );
                $tag_slug = $case_sensitive ? $tag->slug : $this->ptt_lower( $tag->slug );

                if ( false !== strpos( $tag_name, $needle ) || false !== strpos( $tag_slug, $needle ) ) {
                    $matches[] = $page;
                    break;
                }
            }
        }

        return $matches;
    }

    private function ptt_lower( $value ) {
        $value = (string) $value;
        return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
    }

    public function handle_tools_form() {
        if ( ! current_user_can( 'edit_pages' ) ) {
            wp_die( esc_html__( 'You do not have permission to edit pages.', 'page-tags-tools' ) );
        }

        check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

        $tag            = isset( $_POST['ptt_tag'] ) ? sanitize_text_field( wp_unslash( $_POST['ptt_tag'] ) ) : '';
        $search         = isset( $_POST['ptt_search'] ) ? sanitize_text_field( wp_unslash( $_POST['ptt_search'] ) ) : '';
        $case_sensitive = ! empty( $_POST['ptt_case_sensitive'] );
        $page_ids       = isset( $_POST['ptt_page_ids'] ) && is_array( $_POST['ptt_page_ids'] ) ? array_map( 'absint', $_POST['ptt_page_ids'] ) : array();
        $updated        = $this->add_tag_to_pages( $page_ids, $tag );

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'        => self::MENU_SLUG,
                    'ptt_updated' => $updated,
                    'ptt_tag'     => rawurlencode( $tag ),
                    'ptt_search'         => rawurlencode( $search ),
                    'ptt_case_sensitive' => $case_sensitive ? '1' : '0',
                ),
                admin_url( 'tools.php' )
            )
        );
        exit;
    }

    public function register_bulk_action( $bulk_actions ) {
        $bulk_actions['ptt_add_tag'] = __( 'Add page tag', 'page-tags-tools' );
        return $bulk_actions;
    }

    public function handle_bulk_action( $redirect_url, $action, $post_ids ) {
        if ( 'ptt_add_tag' !== $action ) {
            return $redirect_url;
        }

        if ( ! current_user_can( 'edit_pages' ) ) {
            return add_query_arg( 'ptt_error', 'permission', $redirect_url );
        }

        $tag_id = isset( $_REQUEST['ptt_bulk_tag_id'] ) ? absint( $_REQUEST['ptt_bulk_tag_id'] ) : 0;
        $tag    = isset( $_REQUEST['ptt_bulk_tag'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['ptt_bulk_tag'] ) ) : '';

        if ( $tag_id ) {
            $term = get_term( $tag_id, 'post_tag' );
            if ( ! $term || is_wp_error( $term ) ) {
                return add_query_arg( 'ptt_error', 'missing_tag', $redirect_url );
            }

            $updated = $this->add_existing_tag_to_pages( $post_ids, $tag_id );
            $tag     = $term->name;
        } else {
            if ( '' === $tag ) {
                return add_query_arg( 'ptt_error', 'missing_tag', $redirect_url );
            }

            $updated = $this->add_tag_to_pages( $post_ids, $tag );
        }

        return add_query_arg(
            array(
                'ptt_updated' => $updated,
                'ptt_tag'     => rawurlencode( $tag ),
            ),
            $redirect_url
        );
    }

    private function add_existing_tag_to_pages( $page_ids, $term_id ) {
        $term_id = absint( $term_id );

        if ( ! $term_id || empty( $page_ids ) ) {
            return 0;
        }

        $updated = 0;

        foreach ( $page_ids as $page_id ) {
            $page_id = absint( $page_id );
            if ( ! $page_id || 'page' !== get_post_type( $page_id ) || ! current_user_can( 'edit_page', $page_id ) ) {
                continue;
            }

            $result = wp_set_object_terms( $page_id, array( $term_id ), 'post_tag', true );
            if ( ! is_wp_error( $result ) ) {
                $updated++;
            }
        }

        return $updated;
    }

    private function add_tag_to_pages( $page_ids, $tag ) {
        $tag = trim( (string) $tag );

        if ( '' === $tag || empty( $page_ids ) ) {
            return 0;
        }

        $updated = 0;

        foreach ( $page_ids as $page_id ) {
            $page_id = absint( $page_id );
            if ( ! $page_id || 'page' !== get_post_type( $page_id ) || ! current_user_can( 'edit_page', $page_id ) ) {
                continue;
            }

            $result = wp_set_object_terms( $page_id, $tag, 'post_tag', true );
            if ( ! is_wp_error( $result ) ) {
                $updated++;
            }
        }

        return $updated;
    }

    public function bulk_action_notice() {
        $screen = get_current_screen();
        if ( ! $screen || 'edit-page' !== $screen->id ) {
            return;
        }

        if ( isset( $_GET['ptt_updated'] ) ) {
            $updated = absint( $_GET['ptt_updated'] );
            $tag     = isset( $_GET['ptt_tag'] ) ? sanitize_text_field( wp_unslash( $_GET['ptt_tag'] ) ) : '';
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php echo esc_html( sprintf( _n( 'Added "%2$s" to %1$d page.', 'Added "%2$s" to %1$d pages.', $updated, 'page-tags-tools' ), $updated, $tag ) ); ?></p>
            </div>
            <?php
        }

        if ( isset( $_GET['ptt_error'] ) ) {
            $error = sanitize_key( wp_unslash( $_GET['ptt_error'] ) );
            $message = 'permission' === $error
                ? __( 'You do not have permission to edit pages.', 'page-tags-tools' )
                : __( 'No tag was entered, so no pages were updated.', 'page-tags-tools' );
            ?>
            <div class="notice notice-error is-dismissible">
                <p><?php echo esc_html( $message ); ?></p>
            </div>
            <?php
        }
    }

    public function bulk_action_prompt_script() {
        $screen = get_current_screen();
        if ( ! $screen || 'edit-page' !== $screen->id ) {
            return;
        }

        $terms = $this->get_page_tag_terms();
        ?>
        <script>
            (function () {
                var pageTagOptions = <?php echo wp_json_encode( array_map( static function ( $term ) { return array( 'id' => (int) $term->term_id, 'name' => $term->name ); }, $terms ) ); ?>;

                function addHiddenTagIdInput(form, tagId) {
                    var existing = form.querySelector('input[name="ptt_bulk_tag_id"]');
                    if (existing) {
                        existing.value = tagId;
                        return;
                    }
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'ptt_bulk_tag_id';
                    input.value = tagId;
                    form.appendChild(input);
                }

                function buildTagSelect(id) {
                    var select = document.createElement('select');
                    select.id = id;
                    select.className = 'ptt-bulk-tag-select';
                    select.style.marginLeft = '6px';

                    var defaultOption = document.createElement('option');
                    defaultOption.value = '';
                    defaultOption.textContent = '<?php echo esc_js( __( 'Select page tag to add', 'page-tags-tools' ) ); ?>';
                    select.appendChild(defaultOption);

                    pageTagOptions.forEach(function (tag) {
                        var option = document.createElement('option');
                        option.value = tag.id;
                        option.textContent = tag.name;
                        select.appendChild(option);
                    });

                    if (!pageTagOptions.length) {
                        select.disabled = true;
                        defaultOption.textContent = '<?php echo esc_js( __( 'No page tags found', 'page-tags-tools' ) ); ?>';
                    }

                    return select;
                }

                function insertBulkTagDropdown(actionSelectorId, selectId) {
                    var actionSelector = document.getElementById(actionSelectorId);
                    if (!actionSelector || document.getElementById(selectId)) {
                        return;
                    }

                    actionSelector.parentNode.insertBefore(buildTagSelect(selectId), actionSelector.nextSibling);
                }

                function getSelectedBulkTagId() {
                    var top = document.getElementById('ptt-bulk-tag-select-top');
                    var bottom = document.getElementById('ptt-bulk-tag-select-bottom');
                    return (top && top.value) || (bottom && bottom.value) || '';
                }

                document.addEventListener('DOMContentLoaded', function () {
                    insertBulkTagDropdown('bulk-action-selector-top', 'ptt-bulk-tag-select-top');
                    insertBulkTagDropdown('bulk-action-selector-bottom', 'ptt-bulk-tag-select-bottom');
                });

                document.addEventListener('submit', function (event) {
                    var form = event.target;
                    if (!form || form.id !== 'posts-filter') {
                        return;
                    }

                    var actionTop = document.getElementById('bulk-action-selector-top');
                    var actionBottom = document.getElementById('bulk-action-selector-bottom');
                    var selectedAction = '';

                    if (actionTop && actionTop.value !== '-1') {
                        selectedAction = actionTop.value;
                    } else if (actionBottom && actionBottom.value !== '-1') {
                        selectedAction = actionBottom.value;
                    }

                    if (selectedAction !== 'ptt_add_tag') {
                        return;
                    }

                    var tagId = getSelectedBulkTagId();
                    if (!tagId) {
                        window.alert('<?php echo esc_js( __( 'Choose an existing page tag from the dropdown before applying this bulk action.', 'page-tags-tools' ) ); ?>');
                        event.preventDefault();
                        return;
                    }

                    addHiddenTagIdInput(form, tagId);
                }, true);
            })();
        </script>
        <?php
    }
}

new Page_Tags_Tools_Plugin();
