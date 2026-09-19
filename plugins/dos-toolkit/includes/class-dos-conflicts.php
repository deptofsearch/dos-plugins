<?php
/**
 * Conflict detection.
 *
 * Two different situations, handled differently on purpose.
 *
 * Superseded plugins are the one-off plugins this toolkit absorbed. Their
 * functionality now lives in a module, so running both means duplicated work
 * at best and contradictory results at worst — two SEO modules both stripping
 * the core canonical and writing their own, or two media scanners reaching
 * different conclusions about which images are unused. These are our own
 * plugins, so switching them off is safe and this does it automatically.
 *
 * Third-party plugins are somebody's deliberate choice. If a site runs Yoast,
 * the right response is for our SEO module to stand down, not for us to
 * disable the site's SEO plugin. These are only ever reported.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DOS_Conflicts {

	const NOTICE_OPTION = 'dos_conflicts_deactivated';

	public static function boot() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_deactivate' ), 5 );
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
	}

	/**
	 * Plugins this toolkit replaces.
	 *
	 * Matched on plugin file first, then on the exact plugin name, because a
	 * plugin folder can be renamed on the way onto a site and often has been.
	 *
	 * @return array file => [ name, module, replaced_by ]
	 */
	public static function superseded() {
		return array(
			'saab-toolkit/saab-toolkit.php' => array(
				'name'        => 'SAAB Toolkit',
				'module'      => 'seo',
				'replaced_by' => __( 'the SEO and Images modules', 'dos-toolkit' ),
				'severity'    => 'harmful',
				'detail'      => __( 'Both write meta tags and canonicals into the page head, so every page would carry two of each.', 'dos-toolkit' ),
			),
			'media-usage-manager/media-usage-manager.php' => array(
				'name'        => 'Media Usage Manager',
				'module'      => 'images',
				'replaced_by' => __( 'the Images & Media module', 'dos-toolkit' ),
				'severity'    => 'harmful',
				'detail'      => __( 'Two separate usage scans can disagree about which images are unused, and both offer to delete them.', 'dos-toolkit' ),
			),
			'breanm-clear-image-titles/breanm-clear-image-titles.php' => array(
				'name'        => 'BREANM Clear Image Titles',
				'module'      => 'images',
				'replaced_by' => __( 'the Images & Media module', 'dos-toolkit' ),
				'severity'    => 'duplicate',
				'detail'      => __( 'The same job now runs on the shared batch runner, behind a dry run.', 'dos-toolkit' ),
			),
			'breanm-plugin-downloader/breanm-plugin-downloader.php' => array(
				'name'        => 'BREANM Plugin Downloader',
				'module'      => 'utilities',
				'replaced_by' => __( 'the Utilities module', 'dos-toolkit' ),
				'severity'    => 'duplicate',
				'detail'      => __( 'Duplicate Tools page doing the same thing.', 'dos-toolkit' ),
			),
			'last-updated-column/last-updated-column.php' => array(
				'name'        => 'Last Updated Column',
				'module'      => 'utilities',
				'replaced_by' => __( 'the Utilities module', 'dos-toolkit' ),
				'severity'    => 'harmful',
				'detail'      => __( 'It filters get_the_date on every singular view, including the machine-readable timestamps the SEO module puts in structured data.', 'dos-toolkit' ),
			),
			'page-tags-tools/page-tags-tools.php' => array(
				'name'        => 'Page Tags Tools',
				'module'      => 'utilities',
				'replaced_by' => __( 'the Utilities module', 'dos-toolkit' ),
				'severity'    => 'duplicate',
				'detail'      => __( 'Both attach tags to Pages and both add their own admin UI for it.', 'dos-toolkit' ),
			),
		);
	}

	/**
	 * Third-party plugins a module defers to. Reported, never touched.
	 *
	 * @return array constant => [ name, module ]
	 */
	public static function third_party() {
		return array(
			'WPSEO_VERSION' => array(
				'name'   => 'Yoast SEO',
				'module' => 'seo',
			),
			'RANK_MATH_VERSION' => array(
				'name'   => 'Rank Math',
				'module' => 'seo',
			),
			'SEOPRESS_VERSION' => array(
				'name'   => 'SEOPress',
				'module' => 'seo',
			),
		);
	}

	public static function auto_deactivate_enabled() {
		$value = DOS_Settings::get( 'deactivate_superseded', null );

		return null === $value ? true : (bool) $value;
	}

	private static function load_plugin_api() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	/**
	 * Superseded plugins currently active on this site.
	 *
	 * @return array file => entry, with 'name' replaced by what the site
	 *               actually calls it.
	 */
	public static function active_superseded() {
		self::load_plugin_api();

		$installed = get_plugins();
		$found     = array();

		foreach ( self::superseded() as $file => $entry ) {
			if ( isset( $installed[ $file ] ) && is_plugin_active( $file ) ) {
				$found[ $file ] = $entry;
			}

			// Also scan by name, and keep scanning even when the expected
			// path matched: a renamed copy can sit alongside the original,
			// and finding one is no reason to miss the other.
			foreach ( $installed as $installed_file => $data ) {
				if ( isset( $found[ $installed_file ] ) ) {
					continue;
				}

				if ( empty( $data['Name'] ) || $data['Name'] !== $entry['name'] ) {
					continue;
				}

				if ( is_plugin_active( $installed_file ) ) {
					$found[ $installed_file ] = $entry;
				}
			}
		}

		return $found;
	}

	/**
	 * Third-party plugins present whose module would stand down.
	 */
	public static function active_third_party() {
		$found = array();

		foreach ( self::third_party() as $constant => $entry ) {
			if ( defined( $constant ) ) {
				$found[ $constant ] = $entry;
			}
		}

		return $found;
	}

	/**
	 * Deactivate a superseded plugin only once the module that replaces it is
	 * switched on. Doing it at activation instead would leave a site with
	 * neither the old plugin nor the new module, turning a conflict into a
	 * loss of function.
	 */
	public static function maybe_deactivate() {
		if ( ! self::auto_deactivate_enabled() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$to_deactivate = array();

		foreach ( self::active_superseded() as $file => $entry ) {
			if ( DOS_Toolkit::is_active( $entry['module'] ) ) {
				$to_deactivate[ $file ] = $entry;
			}
		}

		if ( ! $to_deactivate ) {
			return;
		}

		self::load_plugin_api();

		deactivate_plugins( array_keys( $to_deactivate ), true );

		$record = get_option( self::NOTICE_OPTION, array() );
		$record = is_array( $record ) ? $record : array();

		foreach ( $to_deactivate as $file => $entry ) {
			DOS_Log::add(
				'core',
				'superseded_deactivated',
				sprintf( '%s deactivated; replaced by %s.', $entry['name'], $entry['replaced_by'] )
			);

			$record[ $file ] = $entry['name'];
		}

		update_option( self::NOTICE_OPTION, $record, false );
	}

	public static function dismiss_notice() {
		delete_option( self::NOTICE_OPTION );
	}

	public static function render_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		// What was switched off, reported once.
		$deactivated = get_option( self::NOTICE_OPTION, array() );

		if ( is_array( $deactivated ) && $deactivated ) {
			?>
			<div class="notice notice-success">
				<p>
					<strong><?php esc_html_e( 'DoS Toolkit', 'dos-toolkit' ); ?></strong> —
					<?php
					printf(
						/* translators: %s: comma-separated list of plugin names */
						esc_html__( 'deactivated %s, because this toolkit now does the same job. Nothing was deleted; you can remove them from the Plugins screen, or reactivate them there if something is missing.', 'dos-toolkit' ),
						'<strong>' . esc_html( implode( ', ', $deactivated ) ) . '</strong>'
					);
					?>
				</p>
				<p>
					<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'dos_dismiss_conflicts', '1', admin_url( 'admin.php?page=' . DOS_Admin::MENU_SLUG ) ), 'dos_dismiss_conflicts' ) ); ?>">
						<?php esc_html_e( 'Got it', 'dos-toolkit' ); ?>
					</a>
				</p>
			</div>
			<?php
		}

		// What is still conflicting and has not been handled.
		$pending = array();

		foreach ( self::active_superseded() as $file => $entry ) {
			$pending[] = $entry;
		}

		if ( $pending ) {
			?>
			<div class="notice notice-warning">
				<p><strong><?php esc_html_e( 'DoS Toolkit — plugin conflict', 'dos-toolkit' ); ?></strong></p>
				<ul style="list-style:disc;margin-left:1.5em">
					<?php foreach ( $pending as $entry ) : ?>
						<li>
							<strong><?php echo esc_html( $entry['name'] ); ?></strong>
							<?php echo esc_html( $entry['detail'] ); ?>
							<em>
								<?php
								printf(
									/* translators: %s: name of the module that replaces it */
									esc_html__( 'Replaced by %s — switch that module on and this plugin is deactivated for you, or deactivate it yourself.', 'dos-toolkit' ),
									esc_html( $entry['replaced_by'] )
								);
								?>
							</em>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php
		}
	}
}
