<?php
/**
 * Utilities module: the small jobs that come up on every site.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-dos-plugin-download.php';
require_once __DIR__ . '/class-dos-page-tags.php';

final class DOS_Module_Utilities extends DOS_Module {

	const KEY = 'utilities';

	/**
	 * Settings that may cross between sites on an import.
	 *
	 * Everything is prefix-matched rather than listed, so a new module's
	 * settings travel without anyone remembering to update this. The
	 * exclusions are the point: a credential must never ride along in a
	 * config file that gets emailed around, and a log schema version
	 * belongs to the site it was installed on.
	 */
	const PORTABLE_PREFIXES = array( 'enable_', 'seo_', 'images_', 'utilities_', 'ai_' );
	const PORTABLE_KEYS     = array( 'dry_run_default', 'log_retention_days', 'github_repo' );
	const NEVER_PORTABLE    = array( 'github_token', 'log_db_version' );

	public static function init() {
		DOS_Plugin_Download::init();
		DOS_Page_Tags::init();

		add_action( 'admin_init', array( __CLASS__, 'handle_post' ), 20 );
		add_action( 'admin_post_dos_export_settings', array( __CLASS__, 'handle_export' ) );
	}

	public static function pages() {
		return array(
			array(
				'slug'     => 'dos-utilities',
				'title'    => __( 'Utilities', 'dos-toolkit' ),
				'callback' => array( __CLASS__, 'render_page' ),
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * Settings portability
	 * ------------------------------------------------------------------- */

	public static function portable_settings() {
		$out = array();

		foreach ( DOS_Settings::all() as $key => $value ) {
			if ( in_array( $key, self::NEVER_PORTABLE, true ) ) {
				continue;
			}

			if ( in_array( $key, self::PORTABLE_KEYS, true ) ) {
				$out[ $key ] = $value;

				continue;
			}

			foreach ( self::PORTABLE_PREFIXES as $prefix ) {
				if ( 0 === strpos( $key, $prefix ) ) {
					$out[ $key ] = $value;

					break;
				}
			}
		}

		return $out;
	}

	/**
	 * Filter an imported payload down to what is allowed to cross sites.
	 * Anything unrecognised is dropped rather than trusted.
	 */
	public static function filter_import( $payload ) {
		if ( ! is_array( $payload ) ) {
			return array();
		}

		$settings = isset( $payload['settings'] ) && is_array( $payload['settings'] ) ? $payload['settings'] : $payload;
		$allowed  = array();

		foreach ( $settings as $key => $value ) {
			if ( ! is_string( $key ) || in_array( $key, self::NEVER_PORTABLE, true ) ) {
				continue;
			}

			// Values are settings, never callables or structures. Scalars and
			// flat arrays of scalars only.
			if ( is_object( $value ) ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$flat = true;

				foreach ( $value as $item ) {
					if ( ! is_scalar( $item ) && null !== $item ) {
						$flat = false;
						break;
					}
				}

				if ( ! $flat ) {
					continue;
				}
			}

			$permitted = in_array( $key, self::PORTABLE_KEYS, true );

			if ( ! $permitted ) {
				foreach ( self::PORTABLE_PREFIXES as $prefix ) {
					if ( 0 === strpos( $key, $prefix ) ) {
						$permitted = true;

						break;
					}
				}
			}

			if ( $permitted ) {
				$allowed[ $key ] = $value;
			}
		}

		return $allowed;
	}

	public static function handle_export() {
		if ( ! current_user_can( DOS_Settings::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to export settings.', 'dos-toolkit' ), 403 );
		}

		check_admin_referer( 'dos_export_settings' );

		$payload = wp_json_encode(
			array(
				'plugin'   => 'dos-toolkit',
				'version'  => DOS_TOOLKIT_VERSION,
				'exported' => gmdate( 'c' ),
				'site'     => home_url( '/' ),
				'settings' => self::portable_settings(),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		DOS_Log::add( 'utilities', 'settings_exported', 'Settings exported.' );

		nocache_headers();
		header( 'Content-Type: application/json' );
		header( 'Content-Disposition: attachment; filename="dos-toolkit-settings-' . gmdate( 'Ymd-His' ) . '.json"' );

		echo $payload; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Actions
	 * ------------------------------------------------------------------- */

	public static function handle_post() {
		if ( empty( $_POST['dos_action'] ) || ! current_user_can( DOS_Settings::capability() ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['dos_action'] ) );

		switch ( $action ) {
			case 'save_utilities':
				check_admin_referer( 'dos_save_utilities' );

				DOS_Settings::set( 'utilities_page_tags', empty( $_POST['page_tags'] ) ? 0 : 1 );
				self::log( 'settings_saved', 'Utility settings updated.' );
				self::redirect( 'saved' );
				break;

			case 'flush_permalinks':
				check_admin_referer( 'dos_flush_permalinks' );

				flush_rewrite_rules( false );
				self::log( 'permalinks_flushed', 'Rewrite rules flushed.' );
				self::redirect( 'flushed' );
				break;

			case 'import_settings':
				check_admin_referer( 'dos_import_settings' );

				$raw     = isset( $_POST['payload'] ) ? trim( (string) wp_unslash( $_POST['payload'] ) ) : '';
				$decoded = json_decode( $raw, true );

				if ( ! is_array( $decoded ) ) {
					self::redirect( 'import_invalid' );
				}

				$allowed = self::filter_import( $decoded );

				if ( ! $allowed ) {
					self::redirect( 'import_empty' );
				}

				DOS_Settings::update( $allowed );
				self::log( 'settings_imported', sprintf( 'Imported %d settings.', count( $allowed ) ) );
				self::redirect( 'imported' );
				break;
		}
	}

	private static function redirect( $notice ) {
		wp_safe_redirect( add_query_arg(
			array( 'page' => 'dos-utilities', 'dos_notice' => $notice ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	private static function notice() {
		$notice = isset( $_GET['dos_notice'] ) ? sanitize_key( wp_unslash( $_GET['dos_notice'] ) ) : '';

		$messages = array(
			'flushed'        => array( 'success', __( 'Permalinks flushed.', 'dos-toolkit' ) ),
			'imported'       => array( 'success', __( 'Settings imported.', 'dos-toolkit' ) ),
			'import_invalid' => array( 'error', __( 'That was not valid JSON.', 'dos-toolkit' ) ),
			'import_empty'   => array( 'warning', __( 'Nothing in that file was importable.', 'dos-toolkit' ) ),
		);

		if ( isset( $messages[ $notice ] ) ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $messages[ $notice ][0] ),
				esc_html( $messages[ $notice ][1] )
			);

			return;
		}

		if ( 'saved' === $notice && isset( $_GET['dos_tagged'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( sprintf(
					/* translators: %d: number of pages tagged */
					__( 'Tagged %d pages.', 'dos-toolkit' ),
					absint( $_GET['dos_tagged'] )
				) )
			);

			return;
		}

		DOS_Admin::notice();
	}

	/* ---------------------------------------------------------------------
	 * Screen
	 * ------------------------------------------------------------------- */

	public static function render_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Utilities', 'dos-toolkit' ); ?></h1>

			<?php self::notice(); ?>

			<h2><?php esc_html_e( 'Settings', 'dos-toolkit' ); ?></h2>

			<form method="post">
				<?php wp_nonce_field( 'dos_save_utilities' ); ?>
				<input type="hidden" name="dos_action" value="save_utilities" />

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Tags on Pages', 'dos-toolkit' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="page_tags" value="1" <?php checked( DOS_Page_Tags::is_enabled() ); ?> />
								<?php esc_html_e( 'Let Pages use tags', 'dos-toolkit' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Attaches the existing post tag taxonomy to Pages, so the tags are the same tags posts already use. Turning this off hides the UI but leaves every tag assignment in place.', 'dos-toolkit' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save utility settings', 'dos-toolkit' ) ); ?>
			</form>

			<?php if ( DOS_Page_Tags::is_enabled() ) : ?>
				<hr>
				<h2><?php esc_html_e( 'Tag pages in bulk', 'dos-toolkit' ); ?></h2>

				<?php
				$search = isset( $_GET['dos_search'] ) ? sanitize_text_field( wp_unslash( $_GET['dos_search'] ) ) : '';
				$pages  = DOS_Page_Tags::pages( $search );
				$terms  = get_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false ) );
				$terms  = is_wp_error( $terms ) ? array() : $terms;
				?>

				<form method="get" style="margin-bottom:1em;">
					<input type="hidden" name="page" value="dos-utilities" />
					<input type="search" name="dos_search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Filter pages by title', 'dos-toolkit' ); ?>" />
					<button type="submit" class="button"><?php esc_html_e( 'Filter', 'dos-toolkit' ); ?></button>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( DOS_Page_Tags::ACTION ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( DOS_Page_Tags::ACTION ); ?>" />

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="dos_tag_new"><?php esc_html_e( 'Tag to apply', 'dos-toolkit' ); ?></label></th>
							<td>
								<?php if ( $terms ) : ?>
									<select name="term_id">
										<option value="0"><?php esc_html_e( '— new tag below —', 'dos-toolkit' ); ?></option>
										<?php foreach ( $terms as $term ) : ?>
											<option value="<?php echo (int) $term->term_id; ?>"><?php echo esc_html( $term->name ); ?></option>
										<?php endforeach; ?>
									</select>
									<br><br>
								<?php endif; ?>
								<input type="text" id="dos_tag_new" name="tag" class="regular-text" placeholder="<?php esc_attr_e( 'or type a new tag', 'dos-toolkit' ); ?>" />
								<p class="description"><?php esc_html_e( 'Tags are added, never replaced — existing tags on a page are left alone.', 'dos-toolkit' ); ?></p>
							</td>
						</tr>
					</table>

					<table class="widefat striped" style="max-width:60em">
						<thead>
							<tr>
								<td class="check-column"></td>
								<th><?php esc_html_e( 'Page', 'dos-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Status', 'dos-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Current tags', 'dos-toolkit' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php if ( ! $pages ) : ?>
							<tr><td colspan="4"><?php esc_html_e( 'No pages matched.', 'dos-toolkit' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $pages as $page ) : ?>
								<?php $page_terms = get_the_terms( $page->ID, 'post_tag' ); ?>
								<tr>
									<th class="check-column">
										<input type="checkbox" name="page_ids[]" value="<?php echo (int) $page->ID; ?>" />
									</th>
									<td><?php echo esc_html( $page->post_title ? $page->post_title : __( '(no title)', 'dos-toolkit' ) ); ?></td>
									<td><?php echo esc_html( $page->post_status ); ?></td>
									<td>
										<?php
										echo esc_html(
											$page_terms && ! is_wp_error( $page_terms )
												? implode( ', ', wp_list_pluck( $page_terms, 'name' ) )
												: '—'
										);
										?>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
						</tbody>
					</table>

					<?php submit_button( __( 'Apply tag to selected pages', 'dos-toolkit' ) ); ?>
				</form>
			<?php endif; ?>

			<hr>
			<h2><?php esc_html_e( 'Move settings between sites', 'dos-toolkit' ); ?></h2>

			<p class="description">
				<?php esc_html_e( 'Configure one site, then carry the same configuration to the rest. Access tokens are never exported, and an import only accepts settings this plugin recognises.', 'dos-toolkit' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:1.5em;">
				<?php wp_nonce_field( 'dos_export_settings' ); ?>
				<input type="hidden" name="action" value="dos_export_settings" />
				<button type="submit" class="button"><?php esc_html_e( 'Export settings', 'dos-toolkit' ); ?></button>
			</form>

			<form method="post">
				<?php wp_nonce_field( 'dos_import_settings' ); ?>
				<input type="hidden" name="dos_action" value="import_settings" />
				<p>
					<textarea name="payload" rows="6" class="large-text code" placeholder="<?php esc_attr_e( 'Paste an exported settings file here', 'dos-toolkit' ); ?>"></textarea>
				</p>
				<button type="submit" class="button"><?php esc_html_e( 'Import settings', 'dos-toolkit' ); ?></button>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Maintenance', 'dos-toolkit' ); ?></h2>

			<form method="post" style="margin-bottom:1.5em;">
				<?php wp_nonce_field( 'dos_flush_permalinks' ); ?>
				<input type="hidden" name="dos_action" value="flush_permalinks" />
				<button type="submit" class="button"><?php esc_html_e( 'Flush permalinks', 'dos-toolkit' ); ?></button>
				<span class="description"><?php esc_html_e( 'Rebuilds rewrite rules. The usual fix for a 404 on a page that plainly exists.', 'dos-toolkit' ); ?></span>
			</form>

			<h2><?php esc_html_e( 'Download an installed plugin', 'dos-toolkit' ); ?></h2>

			<?php if ( ! DOS_Plugin_Download::is_supported() ) : ?>
				<p class="description"><?php esc_html_e( 'This needs the PHP ZipArchive extension, which is not available on this server.', 'dos-toolkit' ); ?></p>
			<?php else : ?>
				<p class="description">
					<?php esc_html_e( 'For the plugin that exists on one site and nowhere else — a client one-off, or something inherited with no source anywhere.', 'dos-toolkit' ); ?>
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( DOS_Plugin_Download::ACTION ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( DOS_Plugin_Download::ACTION ); ?>" />

					<select name="plugin_file">
						<?php foreach ( DOS_Plugin_Download::installed_plugins() as $file => $data ) : ?>
							<option value="<?php echo esc_attr( $file ); ?>">
								<?php echo esc_html( $data['Name'] . ' ' . $data['Version'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>

					<button type="submit" class="button"><?php esc_html_e( 'Download ZIP', 'dos-toolkit' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
