<?php
/**
 * Redirects & 404s module.
 *
 * The two belong together: the 404 log is where redirects come from, and a
 * log you have to retype into a form is busywork.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-dos-redirects-store.php';
require_once __DIR__ . '/class-dos-redirects-router.php';
require_once __DIR__ . '/class-dos-redirects-slug.php';

final class DOS_Module_Redirects extends DOS_Module {

	const KEY = 'redirects';

	public static function init() {
		add_action( 'admin_init', array( 'DOS_Redirects_Store', 'maybe_install' ), 4 );
		add_action( 'admin_init', array( __CLASS__, 'handle_post' ), 20 );

		DOS_Redirects_Router::init();
		DOS_Redirects_Slug::init();
	}

	public static function pages() {
		return array(
			array(
				'slug'     => 'dos-redirects',
				'title'    => __( 'Redirects & 404s', 'dos-toolkit' ),
				'callback' => array( __CLASS__, 'render_page' ),
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * Actions
	 * ------------------------------------------------------------------- */

	public static function handle_post() {
		if ( empty( $_POST['dos_action'] ) || ! current_user_can( DOS_Settings::capability() ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['dos_action'] ) );

		if ( 0 !== strpos( $action, 'redirects_' ) ) {
			return;
		}

		check_admin_referer( 'dos_redirects' );

		$notice = 'saved';

		switch ( $action ) {
			case 'redirects_add':
				$source = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : '';
				$target = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : '';
				$code   = isset( $_POST['code'] ) ? (int) $_POST['code'] : 301;

				$result = DOS_Redirects_Store::add( $source, $target, $code );

				if ( is_wp_error( $result ) ) {
					set_transient( 'dos_redirects_error', $result->get_error_message(), 60 );

					$notice = 'error';
					break;
				}

				self::log( 'redirect_added', sprintf( '%s -> %s (%d)', DOS_Redirects_Store::normalise( $source ), $target, $code ) );

				// Adding a redirect resolves the 404 it came from.
				if ( ! empty( $_POST['resolve_404'] ) ) {
					DOS_Redirects_Store::delete_404( (int) $_POST['resolve_404'] );
				}
				break;

			case 'redirects_delete':
				DOS_Redirects_Store::delete( (int) $_POST['id'] );
				self::log( 'redirect_deleted', 'Redirect #' . (int) $_POST['id'] . ' deleted.' );
				break;

			case 'redirects_toggle':
				DOS_Redirects_Store::set_enabled( (int) $_POST['id'], ! empty( $_POST['enabled'] ) );
				break;

			case 'redirects_ignore_404':
				DOS_Redirects_Store::ignore_404( (int) $_POST['id'] );
				break;

			case 'redirects_delete_404':
				DOS_Redirects_Store::delete_404( (int) $_POST['id'] );
				break;

			case 'redirects_clear_404s':
				DOS_Redirects_Store::clear_404s();
				self::log( 'log_cleared', '404 log cleared.' );
				break;

			case 'redirects_save_settings':
				DOS_Settings::update(
					array(
						'redirects_log_404'   => empty( $_POST['log_404'] ) ? 0 : 1,
						'redirects_auto_slug' => empty( $_POST['auto_slug'] ) ? 0 : 1,
						'redirects_log_days'  => isset( $_POST['log_days'] ) ? max( 0, (int) $_POST['log_days'] ) : 60,
					)
				);
				break;
		}

		DOS_Redirects_Store::prune_404s();

		wp_safe_redirect( add_query_arg( array( 'page' => 'dos-redirects', 'dos_notice' => $notice ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Screen
	 * ------------------------------------------------------------------- */

	public static function render_page() {
		$error = get_transient( 'dos_redirects_error' );

		if ( $error ) {
			delete_transient( 'dos_redirects_error' );
		}

		$prefill = isset( $_GET['source'] ) ? sanitize_text_field( wp_unslash( $_GET['source'] ) ) : '';
		$from    = isset( $_GET['from_404'] ) ? (int) $_GET['from_404'] : 0;
		$log     = DOS_Redirects_Store::log( 200 );
		$rules   = DOS_Redirects_Store::all( 500 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Redirects & 404s', 'dos-toolkit' ); ?></h1>

			<?php if ( $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php else : ?>
				<?php DOS_Admin::notice(); ?>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Add a redirect', 'dos-toolkit' ); ?></h2>

			<form method="post">
				<?php wp_nonce_field( 'dos_redirects' ); ?>
				<input type="hidden" name="dos_action" value="redirects_add" />
				<?php if ( $from ) : ?>
					<input type="hidden" name="resolve_404" value="<?php echo (int) $from; ?>" />
				<?php endif; ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="source"><?php esc_html_e( 'Redirect this path', 'dos-toolkit' ); ?></label></th>
						<td>
							<input type="text" id="source" name="source" value="<?php echo esc_attr( $prefill ); ?>" class="regular-text code" placeholder="/old-page" required />
							<p class="description"><?php esc_html_e( 'Compared without a trailing slash and without regard to case. Any query string on the incoming URL is carried across.', 'dos-toolkit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="target"><?php esc_html_e( 'To', 'dos-toolkit' ); ?></label></th>
						<td>
							<input type="text" id="target" name="target" class="regular-text code" placeholder="/new-page" required />
							<p class="description"><?php esc_html_e( 'A path on this site, or a full https:// address elsewhere.', 'dos-toolkit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="code"><?php esc_html_e( 'Type', 'dos-toolkit' ); ?></label></th>
						<td>
							<select id="code" name="code">
								<option value="301"><?php esc_html_e( '301 — moved permanently', 'dos-toolkit' ); ?></option>
								<option value="302"><?php esc_html_e( '302 — temporary', 'dos-toolkit' ); ?></option>
								<option value="307"><?php esc_html_e( '307 — temporary, keeps the method', 'dos-toolkit' ); ?></option>
								<option value="410"><?php esc_html_e( '410 — gone, no target', 'dos-toolkit' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( '301 passes ranking to the new URL and is what a permanent move wants. 410 tells crawlers to stop asking for something deliberately removed.', 'dos-toolkit' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Add redirect', 'dos-toolkit' ) ); ?>
			</form>

			<hr>
			<h2>
				<?php esc_html_e( 'Redirects', 'dos-toolkit' ); ?>
				<span class="dos-badge dos-badge-off"><?php echo (int) count( $rules ); ?></span>
			</h2>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'From', 'dos-toolkit' ); ?></th>
						<th><?php esc_html_e( 'To', 'dos-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Type', 'dos-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Hits', 'dos-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Added by', 'dos-toolkit' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $rules ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No redirects yet.', 'dos-toolkit' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rules as $rule ) : ?>
						<tr<?php echo $rule['enabled'] ? '' : ' style="opacity:.5"'; ?>>
							<td><code><?php echo esc_html( $rule['source'] ); ?></code></td>
							<td><code><?php echo esc_html( $rule['target'] ); ?></code></td>
							<td><?php echo (int) $rule['code']; ?></td>
							<td><?php echo (int) $rule['hits']; ?></td>
							<td><?php echo 'slug-change' === $rule['origin'] ? esc_html__( 'slug change', 'dos-toolkit' ) : esc_html__( 'by hand', 'dos-toolkit' ); ?></td>
							<td>
								<form method="post" style="display:inline">
									<?php wp_nonce_field( 'dos_redirects' ); ?>
									<input type="hidden" name="dos_action" value="redirects_toggle" />
									<input type="hidden" name="id" value="<?php echo (int) $rule['id']; ?>" />
									<input type="hidden" name="enabled" value="<?php echo $rule['enabled'] ? '0' : '1'; ?>" />
									<button type="submit" class="button-link"><?php echo $rule['enabled'] ? esc_html__( 'Disable', 'dos-toolkit' ) : esc_html__( 'Enable', 'dos-toolkit' ); ?></button>
								</form>
								&nbsp;
								<form method="post" style="display:inline">
									<?php wp_nonce_field( 'dos_redirects' ); ?>
									<input type="hidden" name="dos_action" value="redirects_delete" />
									<input type="hidden" name="id" value="<?php echo (int) $rule['id']; ?>" />
									<button type="submit" class="button-link"><?php esc_html_e( 'Delete', 'dos-toolkit' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<hr>
			<h2>
				<?php esc_html_e( 'Pages nobody could find', 'dos-toolkit' ); ?>
				<span class="dos-badge dos-badge-off"><?php echo (int) DOS_Redirects_Store::log_count(); ?></span>
			</h2>

			<p class="description">
				<?php esc_html_e( 'Requests that hit nothing, most-hit first. Obvious probe traffic is filtered out. A row with a referrer is a real broken link somewhere; one without is usually a stale bookmark or an old search result.', 'dos-toolkit' ); ?>
			</p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Path', 'dos-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Hits', 'dos-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Last seen', 'dos-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Came from', 'dos-toolkit' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $log ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'Nothing logged. That is the good outcome.', 'dos-toolkit' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $log as $row ) : ?>
						<tr>
							<td><code><?php echo esc_html( $row['path'] ); ?></code></td>
							<td><?php echo (int) $row['hits']; ?></td>
							<td><?php echo esc_html( $row['last_seen'] ); ?></td>
							<td class="dos-blurb"><?php echo $row['referrer'] ? esc_html( $row['referrer'] ) : '—'; ?></td>
							<td>
								<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => 'dos-redirects', 'source' => $row['path'], 'from_404' => $row['id'] ), admin_url( 'admin.php' ) ) ); ?>#source">
									<?php esc_html_e( 'Redirect this', 'dos-toolkit' ); ?>
								</a>
								<form method="post" style="display:inline">
									<?php wp_nonce_field( 'dos_redirects' ); ?>
									<input type="hidden" name="dos_action" value="redirects_ignore_404" />
									<input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>" />
									<button type="submit" class="button-link"><?php esc_html_e( 'Ignore', 'dos-toolkit' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<form method="post" style="margin-top:1em">
				<?php wp_nonce_field( 'dos_redirects' ); ?>
				<input type="hidden" name="dos_action" value="redirects_clear_404s" />
				<button type="submit" class="button"><?php esc_html_e( 'Clear the 404 log', 'dos-toolkit' ); ?></button>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Settings', 'dos-toolkit' ); ?></h2>

			<form method="post">
				<?php wp_nonce_field( 'dos_redirects' ); ?>
				<input type="hidden" name="dos_action" value="redirects_save_settings" />

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Log 404s', 'dos-toolkit' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="log_404" value="1" <?php checked( DOS_Redirects_Router::logging_enabled() ); ?> />
								<?php esc_html_e( 'Record requests that hit nothing', 'dos-toolkit' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Slug changes', 'dos-toolkit' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="auto_slug" value="1" <?php checked( DOS_Redirects_Slug::is_enabled() ); ?> />
								<?php esc_html_e( 'Redirect the old URL when a published page is renamed', 'dos-toolkit' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Only for posts and pages already published under the old URL. Each one appears above marked "slug change" and can be removed.', 'dos-toolkit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="log_days"><?php esc_html_e( 'Forget 404s after', 'dos-toolkit' ); ?></label></th>
						<td>
							<input type="number" id="log_days" name="log_days" min="0" class="small-text" value="<?php echo (int) DOS_Settings::get( 'redirects_log_days', 60 ); ?>" />
							<?php esc_html_e( 'days without a hit. 0 keeps everything.', 'dos-toolkit' ); ?>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save settings', 'dos-toolkit' ) ); ?>
			</form>
		</div>
		<?php
	}
}
