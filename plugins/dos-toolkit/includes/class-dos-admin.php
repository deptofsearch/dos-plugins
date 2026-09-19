<?php
/**
 * The "DoS Tools" menu: dashboard, module screens, logs, settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DOS_Admin {

	const MENU_SLUG = 'dos-tools';

	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_post' ), 20 );
		add_filter( 'plugin_action_links_' . DOS_TOOLKIT_BASENAME, array( __CLASS__, 'action_links' ) );
	}

	public static function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) . '">' . esc_html__( 'DoS Tools', 'dos-toolkit' ) . '</a>'
		);

		return $links;
	}

	public static function register_menu() {
		$cap = DOS_Settings::capability();

		add_menu_page(
			__( 'DoS Tools', 'dos-toolkit' ),
			__( 'DoS Tools', 'dos-toolkit' ),
			$cap,
			self::MENU_SLUG,
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-search',
			58
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Modules', 'dos-toolkit' ),
			__( 'Modules', 'dos-toolkit' ),
			$cap,
			self::MENU_SLUG,
			array( __CLASS__, 'render_dashboard' )
		);

		// Each active module contributes its own screens, in registry order.
		foreach ( DOS_Toolkit::modules() as $key => $module ) {
			if ( ! DOS_Toolkit::is_active( $key ) || ! class_exists( $module['class'] ) ) {
				continue;
			}

			$pages = call_user_func( array( $module['class'], 'pages' ) );

			if ( ! is_array( $pages ) ) {
				continue;
			}

			foreach ( $pages as $page ) {
				if ( empty( $page['slug'] ) || empty( $page['callback'] ) ) {
					continue;
				}

				add_submenu_page(
					self::MENU_SLUG,
					isset( $page['title'] ) ? $page['title'] : $page['slug'],
					isset( $page['title'] ) ? $page['title'] : $page['slug'],
					$cap,
					$page['slug'],
					$page['callback']
				);
			}
		}

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Activity Log', 'dos-toolkit' ),
			__( 'Activity Log', 'dos-toolkit' ),
			$cap,
			'dos-log',
			array( __CLASS__, 'render_log' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'dos-toolkit' ),
			__( 'Settings', 'dos-toolkit' ),
			$cap,
			'dos-settings',
			array( __CLASS__, 'render_settings' )
		);
	}

	public static function handle_post() {
		if ( empty( $_POST['dos_action'] ) || ! current_user_can( DOS_Settings::capability() ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['dos_action'] ) );

		switch ( $action ) {
			case 'save_modules':
				check_admin_referer( 'dos_save_modules' );
				self::save_modules();
				break;

			case 'save_settings':
				check_admin_referer( 'dos_save_settings' );
				self::save_settings();
				break;

			case 'check_updates':
				check_admin_referer( 'dos_check_updates' );

				DOS_Updater::status( true );

				// Make WordPress rebuild its own update list too, so the
				// Plugins screen agrees with what we just found.
				delete_site_transient( 'update_plugins' );
				wp_update_plugins();

				self::redirect( 'dos-settings', 'checked' );
				break;

			case 'clear_log':
				check_admin_referer( 'dos_clear_log' );
				DOS_Log::clear();
				self::redirect( 'dos-log', 'cleared' );
				break;
		}
	}

	private static function save_modules() {
		$submitted = isset( $_POST['dos_modules'] ) ? (array) wp_unslash( $_POST['dos_modules'] ) : array();
		$values    = array();

		foreach ( array_keys( DOS_Toolkit::modules() ) as $key ) {
			$enabled = in_array( $key, array_map( 'sanitize_key', $submitted ), true );

			if ( $enabled !== DOS_Toolkit::is_enabled( $key ) ) {
				DOS_Log::add( 'core', $enabled ? 'module_enabled' : 'module_disabled', ucfirst( $key ) . ' module ' . ( $enabled ? 'enabled' : 'disabled' ) . '.' );
			}

			$values[ 'enable_' . $key ] = $enabled ? 1 : 0;
		}

		DOS_Settings::update( $values );

		self::redirect( self::MENU_SLUG, 'saved' );
	}

	private static function save_settings() {
		DOS_Settings::update(
			array(
				'dry_run_default'    => empty( $_POST['dry_run_default'] ) ? 0 : 1,
				'log_retention_days' => isset( $_POST['log_retention_days'] ) ? max( 0, (int) $_POST['log_retention_days'] ) : 90,
				'github_repo'        => isset( $_POST['github_repo'] ) ? sanitize_text_field( wp_unslash( $_POST['github_repo'] ) ) : '',
				'github_token'       => isset( $_POST['github_token'] ) ? sanitize_text_field( wp_unslash( $_POST['github_token'] ) ) : '',
			)
		);

		DOS_Updater::flush();

		self::redirect( 'dos-settings', 'saved' );
	}

	private static function redirect( $page, $notice ) {
		wp_safe_redirect( add_query_arg( array( 'page' => $page, 'dos_notice' => $notice ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function notice() {
		$notice = isset( $_GET['dos_notice'] ) ? sanitize_key( wp_unslash( $_GET['dos_notice'] ) ) : '';

		$messages = array(
			'saved'   => __( 'Saved.', 'dos-toolkit' ),
			'cleared' => __( 'Activity log cleared.', 'dos-toolkit' ),
			'checked' => __( 'Checked for updates.', 'dos-toolkit' ),
		);

		if ( isset( $messages[ $notice ] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $messages[ $notice ] ) );
		}
	}

	public static function render_dashboard() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'DoS Tools', 'dos-toolkit' ); ?></h1>

			<?php self::notice(); ?>

			<p class="description">
				<?php esc_html_e( 'Modules are off until you switch them on. Turning one off removes its hooks and its menu entry, but leaves its stored data alone.', 'dos-toolkit' ); ?>
			</p>

			<form method="post">
				<?php wp_nonce_field( 'dos_save_modules' ); ?>
				<input type="hidden" name="dos_action" value="save_modules" />

				<table class="dos-modules widefat">
					<thead>
						<tr>
							<th><?php esc_html_e( 'On', 'dos-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Module', 'dos-toolkit' ); ?></th>
							<th><?php esc_html_e( 'What it does', 'dos-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Status', 'dos-toolkit' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( DOS_Toolkit::modules() as $key => $module ) : ?>
						<?php
						$available = DOS_Toolkit::is_available( $key );
						$enabled   = DOS_Toolkit::is_enabled( $key );
						?>
						<tr>
							<td>
								<input
									type="checkbox"
									name="dos_modules[]"
									value="<?php echo esc_attr( $key ); ?>"
									<?php checked( $enabled ); ?>
									<?php disabled( ! $available ); ?>
								/>
							</td>
							<td><strong><?php echo esc_html( $module['label'] ); ?></strong></td>
							<td class="dos-blurb"><?php echo esc_html( $module['blurb'] ); ?></td>
							<td>
								<?php if ( ! $available ) : ?>
									<span class="dos-badge dos-badge-missing"><?php esc_html_e( 'Not installed', 'dos-toolkit' ); ?></span>
								<?php elseif ( $enabled ) : ?>
									<span class="dos-badge dos-badge-on"><?php esc_html_e( 'Active', 'dos-toolkit' ); ?></span>
								<?php else : ?>
									<span class="dos-badge dos-badge-off"><?php esc_html_e( 'Off', 'dos-toolkit' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<?php submit_button( __( 'Save modules', 'dos-toolkit' ) ); ?>
			</form>
		</div>
		<?php
	}

	public static function render_log() {
		$module = isset( $_GET['module'] ) ? sanitize_key( wp_unslash( $_GET['module'] ) ) : '';
		$rows   = DOS_Log::recent( 200, $module );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Activity Log', 'dos-toolkit' ); ?></h1>

			<?php self::notice(); ?>

			<p class="description"><?php esc_html_e( 'Every change any module makes is recorded here, including dry runs.', 'dos-toolkit' ); ?></p>

			<form method="post" style="margin-bottom:1em;">
				<?php wp_nonce_field( 'dos_clear_log' ); ?>
				<input type="hidden" name="dos_action" value="clear_log" />
				<button type="submit" class="button"><?php esc_html_e( 'Clear log', 'dos-toolkit' ); ?></button>
			</form>

			<table class="widefat striped dos-log">
				<thead>
					<tr>
						<th><?php esc_html_e( 'When', 'dos-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Module', 'dos-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Action', 'dos-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Detail', 'dos-toolkit' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'Nothing logged yet.', 'dos-toolkit' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row->logged_at ); ?></td>
							<td><?php echo esc_html( $row->module ); ?></td>
							<td><?php echo esc_html( $row->action ); ?></td>
							<td>
								<?php echo esc_html( $row->message ); ?>
								<?php if ( $row->dry_run ) : ?>
									<span class="dos-dry"><?php esc_html_e( '(dry run)', 'dos-toolkit' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public static function render_settings() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'DoS Tools Settings', 'dos-toolkit' ); ?></h1>

			<?php self::notice(); ?>

			<form method="post">
				<?php wp_nonce_field( 'dos_save_settings' ); ?>
				<input type="hidden" name="dos_action" value="save_settings" />

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Default to dry run', 'dos-toolkit' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="dry_run_default" value="1" <?php checked( DOS_Settings::dry_run_default() ); ?> />
								<?php esc_html_e( 'New batch jobs start in report-only mode', 'dos-toolkit' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Log retention', 'dos-toolkit' ); ?></th>
						<td>
							<input type="number" name="log_retention_days" min="0" value="<?php echo esc_attr( DOS_Settings::get( 'log_retention_days', 90 ) ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'Days. 0 keeps everything.', 'dos-toolkit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Update repository', 'dos-toolkit' ); ?></th>
						<td>
							<input type="text" name="github_repo" value="<?php echo esc_attr( DOS_Updater::repo() ); ?>" class="regular-text" placeholder="deptofsearch/dos-plugins" />
							<p class="description"><?php esc_html_e( 'GitHub owner/repo. Releases tagged there appear as plugin updates on this site.', 'dos-toolkit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Access token', 'dos-toolkit' ); ?></th>
						<td>
							<input type="password" name="github_token" value="<?php echo esc_attr( DOS_Settings::get( 'github_token', '' ) ); ?>" class="regular-text" autocomplete="new-password" />
							<p class="description">
								<?php esc_html_e( 'Only needed for a private repository. Leave blank for a public one. Define DOS_TOOLKIT_GITHUB_TOKEN in wp-config.php to keep it out of the database.', 'dos-toolkit' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Updates', 'dos-toolkit' ); ?></h2>

			<?php $status = DOS_Updater::status(); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Installed', 'dos-toolkit' ); ?></th>
					<td><code><?php echo esc_html( $status['installed'] ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Latest release', 'dos-toolkit' ); ?></th>
					<td>
						<?php if ( $status['latest'] ) : ?>
							<code><?php echo esc_html( $status['latest'] ); ?></code>
							<?php if ( $status['update'] ) : ?>
								<a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>"><?php esc_html_e( 'Update available — go to Plugins', 'dos-toolkit' ); ?></a>
							<?php else : ?>
								<?php esc_html_e( 'Up to date.', 'dos-toolkit' ); ?>
							<?php endif; ?>
						<?php elseif ( $status['miss'] ) : ?>
							<span class="dos-media-warning"><?php esc_html_e( 'Could not check.', 'dos-toolkit' ); ?></span>
							<p class="description"><?php echo esc_html( $status['miss']['reason'] ); ?></p>
							<p class="description">
								<?php esc_html_e( 'This is retried automatically within 15 minutes. If it keeps failing, the host may be blocking outbound requests to api.github.com.', 'dos-toolkit' ); ?>
							</p>
						<?php else : ?>
							<?php esc_html_e( 'Not checked yet.', 'dos-toolkit' ); ?>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<form method="post">
				<?php wp_nonce_field( 'dos_check_updates' ); ?>
				<input type="hidden" name="dos_action" value="check_updates" />
				<button type="submit" class="button"><?php esc_html_e( 'Check for updates now', 'dos-toolkit' ); ?></button>
				<span class="description"><?php esc_html_e( 'Clears the cached result and asks GitHub again.', 'dos-toolkit' ); ?></span>
			</form>
		</div>
		<?php
	}
}
