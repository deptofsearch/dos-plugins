<?php
/**
 * Updates from GitHub releases.
 *
 * The repository is a monorepo holding several plugins, so this does not use
 * /releases/latest — that endpoint would happily hand back a release for a
 * different plugin. Instead it lists releases and takes the newest one whose
 * tag carries this plugin's prefix, then picks the asset named for this
 * plugin.
 *
 * Release convention: tag `dos-toolkit-v0.2.0`, asset `dos-toolkit.zip`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DOS_Updater {

	const SLUG       = 'dos-toolkit';
	const TAG_PREFIX = 'dos-toolkit-v';
	const TRANSIENT  = 'dos_toolkit_release';
	const TTL        = 6 * HOUR_IN_SECONDS;

	// A lookup that fails must not be remembered for as long as one that
	// succeeds. A rate limit, a slow host or a dropped connection would
	// otherwise read as "no updates exist" for hours, and since WordPress
	// only checks twice a day, a site could miss a release entirely.
	const MISS_TTL = 15 * MINUTE_IN_SECONDS;
	const DEFAULT_REPO = 'deptofsearch/dos-plugins';

	public static function boot() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_source_dir' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_update' ), 10, 2 );
	}

	/**
	 * Drop the cached release as soon as this plugin is updated.
	 *
	 * Without this, the cache still describes the version that was just
	 * installed, and WordPress's own update list keeps its now-satisfied
	 * entry until the next check — so the Plugins screen can offer an update
	 * to the version already running.
	 */
	public static function after_update( $upgrader, $options ) {
		if ( empty( $options['type'] ) || 'plugin' !== $options['type'] ) {
			return;
		}

		$plugins = isset( $options['plugins'] ) ? (array) $options['plugins'] : array();

		// Single-plugin updates report under 'plugin', bulk under 'plugins'.
		if ( ! empty( $options['plugin'] ) ) {
			$plugins[] = $options['plugin'];
		}

		if ( ! in_array( DOS_TOOLKIT_BASENAME, $plugins, true ) ) {
			return;
		}

		self::flush();
		delete_site_transient( 'update_plugins' );
	}

	public static function flush() {
		delete_site_transient( self::TRANSIENT );
	}

	public static function repo() {
		$repo = (string) DOS_Settings::get( 'github_repo', self::DEFAULT_REPO );

		return trim( $repo, "/ \t\n\r" );
	}

	private static function token() {
		if ( defined( 'DOS_TOOLKIT_GITHUB_TOKEN' ) && DOS_TOOLKIT_GITHUB_TOKEN ) {
			return DOS_TOOLKIT_GITHUB_TOKEN;
		}

		return (string) DOS_Settings::get( 'github_token', '' );
	}

	/**
	 * Newest release for this plugin, cached. Returns null when unconfigured
	 * or unreachable — a GitHub outage must never break the Plugins screen.
	 */
	private static function release( $force = false ) {
		$repo = self::repo();

		if ( ! $repo ) {
			self::remember_miss( __( 'No update repository is configured.', 'dos-toolkit' ) );

			return null;
		}

		if ( ! $force ) {
			$cached = get_site_transient( self::TRANSIENT );

			if ( is_array( $cached ) ) {
				return empty( $cached['version'] ) ? null : $cached;
			}
		}

		$args = array(
			'timeout' => 15,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => self::SLUG . '/' . DOS_TOOLKIT_VERSION,
			),
		);

		$token = self::token();

		if ( $token ) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_remote_get( 'https://api.github.com/repos/' . $repo . '/releases?per_page=30', $args );

		if ( is_wp_error( $response ) ) {
			self::remember_miss( $response->get_error_message() );

			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			self::remember_miss( sprintf(
				/* translators: %d: HTTP status code returned by the GitHub API */
				__( 'GitHub returned HTTP %d. A 403 usually means the request was rate limited.', 'dos-toolkit' ),
				$code
			) );

			return null;
		}

		$releases = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $releases ) ) {
			self::remember_miss( __( 'GitHub returned something that was not a release list.', 'dos-toolkit' ) );

			return null;
		}

		$best = null;

		foreach ( $releases as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['tag_name'] ) ) {
				continue;
			}

			if ( ! empty( $entry['draft'] ) || ! empty( $entry['prerelease'] ) ) {
				continue;
			}

			$version = self::version_from_tag( $entry['tag_name'] );

			if ( null === $version ) {
				continue;
			}

			if ( $best && ! version_compare( $version, $best['version'], '>' ) ) {
				continue;
			}

			$package = self::package_url( $entry );

			if ( ! $package ) {
				continue;
			}

			$best = array(
				'version' => $version,
				'package' => $package,
				'notes'   => isset( $entry['body'] ) ? (string) $entry['body'] : '',
				'date'    => isset( $entry['published_at'] ) ? (string) $entry['published_at'] : '',
				'url'     => isset( $entry['html_url'] ) ? (string) $entry['html_url'] : '',
			);
		}

		if ( ! $best ) {
			self::remember_miss( sprintf(
				/* translators: %s: expected release tag prefix */
				__( 'No release matched the tag prefix %s with a matching ZIP asset.', 'dos-toolkit' ),
				self::TAG_PREFIX
			) );

			return null;
		}

		set_site_transient( self::TRANSIENT, $best, self::TTL );

		return $best;
	}

	/**
	 * Record why a lookup came back empty, briefly, and in a form the
	 * settings screen can show. Silent failure is what made this hard to
	 * diagnose the first time.
	 */
	private static function remember_miss( $reason ) {
		set_site_transient(
			self::TRANSIENT,
			array( 'miss' => true, 'reason' => (string) $reason, 'at' => time() ),
			self::MISS_TTL
		);
	}

	/**
	 * The last failure, or null if the most recent lookup worked.
	 */
	public static function last_miss() {
		$cached = get_site_transient( self::TRANSIENT );

		return ( is_array( $cached ) && ! empty( $cached['miss'] ) ) ? $cached : null;
	}

	/**
	 * Current state for the settings screen: the newest release found, or
	 * why none was.
	 */
	public static function status( $force = false ) {
		if ( $force ) {
			self::flush();
		}

		$release = self::release( $force );

		return array(
			'installed' => DOS_TOOLKIT_VERSION,
			'latest'    => $release ? $release['version'] : '',
			'update'    => $release && version_compare( $release['version'], DOS_TOOLKIT_VERSION, '>' ),
			'miss'      => self::last_miss(),
		);
	}

	/**
	 * `dos-toolkit-v0.2.0` => `0.2.0`. Anything else, including another
	 * plugin's tag, returns null so it is skipped.
	 */
	private static function version_from_tag( $tag ) {
		$tag = (string) $tag;

		if ( 0 !== strpos( $tag, self::TAG_PREFIX ) ) {
			return null;
		}

		$version = substr( $tag, strlen( self::TAG_PREFIX ) );

		return preg_match( '/^\d+(\.\d+)*/', $version ) ? $version : null;
	}

	/**
	 * The asset named for this plugin. A monorepo release may carry several
	 * ZIPs, so the zipball fallback only applies when the release has no
	 * assets at all.
	 */
	private static function package_url( array $entry ) {
		if ( ! empty( $entry['assets'] ) && is_array( $entry['assets'] ) ) {
			foreach ( $entry['assets'] as $asset ) {
				if ( empty( $asset['name'] ) || empty( $asset['browser_download_url'] ) ) {
					continue;
				}

				if ( 0 === strpos( $asset['name'], self::SLUG ) && '.zip' === substr( $asset['name'], -4 ) ) {
					return $asset['browser_download_url'];
				}
			}

			return '';
		}

		return isset( $entry['zipball_url'] ) ? $entry['zipball_url'] : '';
	}

	public static function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = self::release();

		if ( ! $release || empty( $release['version'] ) ) {
			return $transient;
		}

		$item = (object) array(
			'id'               => self::SLUG,
			'slug'             => self::SLUG,
			'plugin'           => DOS_TOOLKIT_BASENAME,
			'new_version'      => $release['version'],
			'url'              => $release['url'],
			'package'          => $release['package'],
			'tested'           => get_bloginfo( 'version' ),
			'requires_php'     => '7.4',
			'icons'            => array(),
			'banners'          => array(),
			'banners_rtl'      => array(),
			// WordPress offers the auto-update toggle only for plugins it
			// believes have a working update mechanism, which it decides by
			// looking for them in this transient.
			'update-supported' => true,
		);

		if ( version_compare( $release['version'], DOS_TOOLKIT_VERSION, '>' ) ) {
			$transient->response[ DOS_TOOLKIT_BASENAME ] = $item;

			unset( $transient->no_update[ DOS_TOOLKIT_BASENAME ] );

			return $transient;
		}

		// Up to date. This has to be recorded too: a plugin that appears in
		// neither list reads to WordPress as one with no update mechanism at
		// all, and the Plugins screen then hides the auto-update toggle and
		// says auto-updates are unavailable for it.
		$item->new_version = DOS_TOOLKIT_VERSION;

		$transient->no_update[ DOS_TOOLKIT_BASENAME ] = $item;

		unset( $transient->response[ DOS_TOOLKIT_BASENAME ] );

		return $transient;
	}

	/**
	 * Describe this plugin to the View details screen.
	 *
	 * Once the slug is ours this always answers, even when the release
	 * lookup has nothing to say. Returning false hands the question to
	 * wordpress.org, which has never heard of this plugin and replies
	 * "Plugin not found." — an accurate answer to a question that should not
	 * have reached it, and one that reads as a broken plugin.
	 */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		$slug = is_object( $args ) && isset( $args->slug ) ? $args->slug : '';
		$slug = ( '' === $slug && is_array( $args ) && isset( $args['slug'] ) ) ? $args['slug'] : $slug;

		if ( self::SLUG !== $slug ) {
			return $result;
		}

		$release = self::release();
		$notes   = '';

		if ( $release && ! empty( $release['notes'] ) ) {
			$notes = wpautop( esc_html( $release['notes'] ) );
		} elseif ( $miss = self::last_miss() ) {
			$notes = wpautop( esc_html( sprintf(
				/* translators: %s: why the release could not be read */
				__( 'The release notes could not be read just now: %s', 'dos-toolkit' ),
				$miss['reason']
			) ) );
		}

		$info = array(
			'name'              => 'DoS Toolkit',
			'slug'              => self::SLUG,
			'plugin'            => DOS_TOOLKIT_BASENAME,
			'version'           => $release ? $release['version'] : DOS_TOOLKIT_VERSION,
			'author'            => '<a href="https://departmentofsearch.com">Department of Search</a>',
			'author_profile'    => 'https://departmentofsearch.com',
			'homepage'          => 'https://github.com/' . self::repo(),
			'download_link'     => $release ? $release['package'] : '',
			'trunk'             => $release ? $release['package'] : '',
			'last_updated'      => $release ? $release['date'] : '',
			'requires'          => '6.0',
			'tested'            => get_bloginfo( 'version' ),
			'requires_php'      => '7.4',
			'active_installs'   => 0,
			'rating'            => 0,
			'num_ratings'       => 0,
			'downloaded'        => 0,
			'added'             => '',
			'banners'           => array(),
			'icons'             => array(),
			'contributors'      => array(),
			'sections'          => array(
				'description' => wpautop( esc_html__( 'Department of Search standard toolkit. SEO, AI search, image and media management, internal links, redirects and site utilities, shipped as modules that are disabled until you turn them on.', 'dos-toolkit' ) ),
				'changelog'   => $notes ? $notes : wpautop( esc_html__( 'No release notes were published for this version.', 'dos-toolkit' ) ),
			),
		);

		if ( $release && ! empty( $release['url'] ) ) {
			$info['sections']['changelog'] .= sprintf(
				'<p><a href="%s" target="_blank" rel="noopener">%s</a></p>',
				esc_url( $release['url'] ),
				esc_html__( 'Read this release on GitHub', 'dos-toolkit' )
			);
		}

		return (object) $info;
	}

	/**
	 * A ZIP asset built by the release workflow already unpacks to
	 * `dos-toolkit/`. A GitHub source archive does not — it unpacks to
	 * `owner-repo-abc1234`, which WordPress would install as a second,
	 * differently-named plugin. Rename it back before install.
	 */
	public static function fix_source_dir( $source, $remote_source, $upgrader, $extra = array() ) {
		global $wp_filesystem;

		if ( empty( $extra['plugin'] ) || DOS_TOOLKIT_BASENAME !== $extra['plugin'] ) {
			return $source;
		}

		if ( ! $wp_filesystem ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . self::SLUG;

		if ( trailingslashit( $source ) === trailingslashit( $desired ) ) {
			return $source;
		}

		if ( ! $wp_filesystem->move( $source, $desired, true ) ) {
			return new WP_Error( 'dos_rename_failed', __( 'Could not rename the update folder.', 'dos-toolkit' ) );
		}

		return trailingslashit( $desired );
	}
}
