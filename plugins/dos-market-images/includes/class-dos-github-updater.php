<?php
/**
 * Updates a plugin from GitHub releases.
 *
 * This is the one copy that matters. Each plugin vendors a byte-identical copy
 * into its own includes/ folder (a ZIP can only hold its own plugin's files),
 * and tests/test-shared-copies.php fails if any copy drifts from this one.
 * Edit it here, then copy it out.
 *
 * The repository is a monorepo holding several plugins, so this does not use
 * /releases/latest — that endpoint would happily hand back a release for a
 * different plugin. Instead it lists releases and takes the newest one whose
 * tag carries this plugin's prefix, then picks the asset named for this
 * plugin.
 *
 * Release convention: tag `<slug>-v0.2.0`, asset `<slug>.zip`.
 *
 * Several DoS plugins can be active on one site, each carrying this class. The
 * first to load defines it and the rest reuse it, so the copies have to stay
 * interchangeable. API is the number to bump if a change would break a plugin
 * written against an older copy.
 *
 *     $updater = new DOS_GitHub_Updater( array(
 *         'slug'        => 'dos-city-search',
 *         'basename'    => plugin_basename( __FILE__ ),
 *         'version'     => DOS_CS_VERSION,
 *         'name'        => 'DoS - City Search',
 *         'description' => 'What the View details screen opens on.',
 *     ) );
 *     $updater->boot();
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'DOS_GitHub_Updater' ) ) {

	final class DOS_GitHub_Updater {

		const API = 1;

		const DEFAULT_REPO = 'deptofsearch/dos-plugins';
		const TTL          = 6 * HOUR_IN_SECONDS;

		// A lookup that fails must not be remembered for as long as one that
		// succeeds. A rate limit, a slow host or a dropped connection would
		// otherwise read as "no updates exist" for hours, and since WordPress
		// only checks twice a day, a site could miss a release entirely.
		const MISS_TTL = 15 * MINUTE_IN_SECONDS;

		/** Releases fetched per request, and how many requests are worth making. */
		const PER_PAGE  = 100;
		const MAX_PAGES = 5;

		/** Release notes kept per release in the cached list. */
		const MAX_NOTES_BYTES = 4096;

		private $slug;
		private $basename;
		private $version;
		private $name;
		private $description;
		private $repo;

		/**
		 * @param array $config slug, basename, version, name, description, and
		 *                      optionally repo (owner/name on GitHub).
		 */
		public function __construct( array $config ) {
			$config = array_merge(
				array(
					'slug'        => '',
					'basename'    => '',
					'version'     => '0',
					'name'        => '',
					'description' => '',
					'repo'        => self::DEFAULT_REPO,
				),
				$config
			);

			$this->slug        = (string) $config['slug'];
			$this->basename    = (string) $config['basename'];
			$this->version     = (string) $config['version'];
			$this->name        = (string) $config['name'];
			$this->description = (string) $config['description'];
			$this->repo        = trim( (string) $config['repo'], "/ \t\n\r" );
		}

		/**
		 * Register the hooks. Call this on load, not behind is_admin(): the
		 * update check runs from WP-Cron and from the REST API too, and a
		 * plugin that only listens in the admin never hears about those.
		 */
		public function boot() {
			add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
			add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );

			// And again, last. Another plugin's updater that answers `plugins_api`
			// without checking the slug it was asked about will overwrite our
			// answer at any priority above ours, and the symptom is core falling
			// through to wordpress.org and reporting this plugin as missing. The
			// handler only ever acts on its own slug, so running it twice is
			// harmless and asserting last is the only way to be sure.
			add_filter( 'plugins_api', array( $this, 'plugin_info' ), PHP_INT_MAX, 3 );
			add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
			add_action( 'upgrader_process_complete', array( $this, 'after_update' ), 10, 2 );

			// Dashboard -> Updates -> "Check again" adds ?force-check. Core drops its own
			// update_plugins cache for it, but our cached release list would still answer
			// with the list from before a release that was just published.
			add_action( 'load-update-core.php', array( $this, 'maybe_force_check' ) );
		}

		public function maybe_force_check() {
			if ( isset( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- only drops a cache; core gates the page itself
				$this->flush();
			}
		}

		private function tag_prefix() {
			return $this->slug . '-v';
		}

		/**
		 * One list of every release in the repository is shared by every DoS
		 * plugin on the site, so three plugins make one walk of the API
		 * rather than three. Each plugin's own answer, derived from it, is
		 * kept separately.
		 */
		private function list_key() {
			return 'dos_github_releases_' . md5( $this->repo );
		}

		private function release_key() {
			return 'dos_release_' . $this->slug;
		}

		/**
		 * Drop the cached release as soon as this plugin is updated.
		 *
		 * Without this, the cache still describes the version that was just
		 * installed, and WordPress's own update list keeps its now-satisfied
		 * entry until the next check — so the Plugins screen can offer an update
		 * to the version already running.
		 */
		public function after_update( $upgrader, $options ) {
			if ( empty( $options['type'] ) || 'plugin' !== $options['type'] ) {
				return;
			}

			$plugins = isset( $options['plugins'] ) ? (array) $options['plugins'] : array();

			// Single-plugin updates report under 'plugin', bulk under 'plugins'.
			if ( ! empty( $options['plugin'] ) ) {
				$plugins[] = $options['plugin'];
			}

			if ( ! in_array( $this->basename, $plugins, true ) ) {
				return;
			}

			$this->flush();
			delete_site_transient( 'update_plugins' );
		}

		public function flush() {
			delete_site_transient( $this->release_key() );
			delete_site_transient( $this->list_key() );
		}

		public function repo() {
			return $this->repo;
		}

		/**
		 * The constant that applies to every DoS plugin wins, then the
		 * Toolkit's older one (sites already have it set), then a filter for
		 * anything that keeps the token somewhere else.
		 */
		private function token() {
			if ( defined( 'DOS_GITHUB_TOKEN' ) && DOS_GITHUB_TOKEN ) {
				return (string) DOS_GITHUB_TOKEN;
			}

			if ( defined( 'DOS_TOOLKIT_GITHUB_TOKEN' ) && DOS_TOOLKIT_GITHUB_TOKEN ) {
				return (string) DOS_TOOLKIT_GITHUB_TOKEN;
			}

			return (string) apply_filters( 'dos_github_updater_token', '' );
		}

		/**
		 * Every release in the repository, cached and shared. Returns null when
		 * unreachable, having recorded why.
		 */
		private function release_list( $force = false ) {
			if ( ! $force ) {
				$cached = get_site_transient( $this->list_key() );

				if ( is_array( $cached ) ) {
					return empty( $cached['miss'] ) ? $cached : null;
				}
			}

			$args = array(
				'timeout' => 15,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => $this->slug . '/' . $this->version,
				),
			);

			$token = $this->token();

			if ( $token ) {
				$args['headers']['Authorization'] = 'Bearer ' . $token;
			}

			$releases = array();

			// GitHub does not order this endpoint by version, and in a monorepo
			// the list holds every plugin's releases at once. One page of thirty
			// was enough on day one and is not a property of the repository: once
			// the newest release for this plugin falls past the end of the page
			// it stops being seen, and the symptom is "no update available",
			// which is a plausible answer and so the wrong one to give.
			for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
				$response = wp_remote_get(
					sprintf( 'https://api.github.com/repos/%s/releases?per_page=%d&page=%d', $this->repo, self::PER_PAGE, $page ),
					$args
				);

				if ( is_wp_error( $response ) ) {
					$this->remember_list_miss( $response->get_error_message() );

					return null;
				}

				$code = (int) wp_remote_retrieve_response_code( $response );

				if ( 200 !== $code ) {
					break;
				}

				$batch = json_decode( wp_remote_retrieve_body( $response ), true );

				if ( ! is_array( $batch ) || ! $batch ) {
					break;
				}

				$releases = array_merge( $releases, $batch );

				// A short page is the last page.
				if ( count( $batch ) < self::PER_PAGE ) {
					break;
				}
			}

			$code = isset( $response ) && ! is_wp_error( $response ) ? (int) wp_remote_retrieve_response_code( $response ) : 0;

			if ( ! $releases && 200 !== $code ) {
				$this->remember_list_miss( sprintf(
					/* translators: %d: HTTP status code returned by the GitHub API */
					__( 'GitHub returned HTTP %d. A 403 usually means the request was rate limited.', 'dos-github-updater' ),
					$code
				) );

				return null;
			}

			if ( ! $releases ) {
				$this->remember_list_miss( __( 'GitHub returned no releases for this repository.', 'dos-github-updater' ) );

				return null;
			}

			$releases = array_map( array( $this, 'trim_release' ), $releases );

			set_site_transient( $this->list_key(), $releases, self::TTL );

			return $releases;
		}

		/**
		 * Keep only what this class reads. The API returns an uploader, author,
		 * reactions and URLs for every release and asset, and up to 500 of
		 * them are stored in one option row shared by every DoS plugin on the
		 * site. Notes are capped too: they only feed the View details tab.
		 */
		private function trim_release( $entry ) {
			if ( ! is_array( $entry ) ) {
				return array();
			}

			$keep = array();

			foreach ( array( 'tag_name', 'draft', 'prerelease', 'published_at', 'html_url', 'zipball_url' ) as $field ) {
				if ( isset( $entry[ $field ] ) ) {
					$keep[ $field ] = $entry[ $field ];
				}
			}

			if ( isset( $entry['body'] ) ) {
				$body = (string) $entry['body'];

				// mb_strcut cuts on a byte limit without splitting a character.
				$keep['body'] = strlen( $body ) > self::MAX_NOTES_BYTES
					? ( function_exists( 'mb_strcut' ) ? mb_strcut( $body, 0, self::MAX_NOTES_BYTES, 'UTF-8' ) : substr( $body, 0, self::MAX_NOTES_BYTES ) )
					: $body;
			}

			if ( isset( $entry['assets'] ) && is_array( $entry['assets'] ) ) {
				$keep['assets'] = array();

				foreach ( $entry['assets'] as $asset ) {
					if ( ! is_array( $asset ) ) {
						continue;
					}

					$keep['assets'][] = array(
						'name'                 => isset( $asset['name'] ) ? $asset['name'] : '',
						'browser_download_url' => isset( $asset['browser_download_url'] ) ? $asset['browser_download_url'] : '',
					);
				}
			}

			return $keep;
		}

		/**
		 * Newest release for this plugin, cached. Returns null when
		 * unconfigured or unreachable — a GitHub outage must never break the
		 * Plugins screen.
		 */
		private function release( $force = false ) {
			if ( ! $this->repo ) {
				$this->remember_miss( __( 'No update repository is configured.', 'dos-github-updater' ) );

				return null;
			}

			if ( ! $force ) {
				$cached = get_site_transient( $this->release_key() );

				if ( is_array( $cached ) ) {
					return empty( $cached['version'] ) ? null : $cached;
				}
			}

			$releases = $this->release_list( $force );

			if ( null === $releases ) {
				return null;
			}

			$best = $this->best_release( $releases );

			if ( ! $best ) {
				$this->remember_miss( sprintf(
					/* translators: %s: expected release tag prefix */
					__( 'No release matched the tag prefix %s with a matching ZIP asset.', 'dos-github-updater' ),
					$this->tag_prefix()
				) );

				return null;
			}

			set_site_transient( $this->release_key(), $best, self::TTL );

			return $best;
		}

		private function best_release( array $releases ) {
			$best = null;

			foreach ( $releases as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['tag_name'] ) ) {
					continue;
				}

				if ( ! empty( $entry['draft'] ) || ! empty( $entry['prerelease'] ) ) {
					continue;
				}

				$version = $this->version_from_tag( $entry['tag_name'] );

				if ( null === $version ) {
					continue;
				}

				if ( $best && ! version_compare( $version, $best['version'], '>' ) ) {
					continue;
				}

				$package = $this->package_url( $entry );

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

			return $best;
		}

		/**
		 * Record why a lookup came back empty, briefly, and in a form a
		 * settings screen can show. Silent failure is what made this hard to
		 * diagnose the first time. This one is about this plugin only: the
		 * list was fetched fine, it just held nothing for us.
		 */
		private function remember_miss( $reason ) {
			set_site_transient(
				$this->release_key(),
				array( 'miss' => true, 'reason' => (string) $reason, 'at' => time() ),
				self::MISS_TTL
			);
		}

		/**
		 * The list itself could not be fetched, which is every DoS plugin's
		 * problem, so it is remembered in the shared slot.
		 */
		private function remember_list_miss( $reason ) {
			set_site_transient(
				$this->list_key(),
				array( 'miss' => true, 'reason' => (string) $reason, 'at' => time() ),
				self::MISS_TTL
			);
		}

		/**
		 * The last failure, or null if the most recent lookup worked.
		 */
		public function last_miss() {
			foreach ( array( $this->release_key(), $this->list_key() ) as $key ) {
				$cached = get_site_transient( $key );

				if ( is_array( $cached ) && ! empty( $cached['miss'] ) ) {
					return $cached;
				}
			}

			return null;
		}

		/**
		 * Current state: the newest release found, or why none was.
		 */
		public function status( $force = false ) {
			if ( $force ) {
				$this->flush();
			}

			$release = $this->release( $force );

			return array(
				'installed' => $this->version,
				'latest'    => $release ? $release['version'] : '',
				'update'    => $release && version_compare( $release['version'], $this->version, '>' ),
				'miss'      => $this->last_miss(),
			);
		}

		/**
		 * `dos-toolkit-v0.2.0` => `0.2.0`. Anything else, including another
		 * plugin's tag, returns null so it is skipped.
		 */
		private function version_from_tag( $tag ) {
			$tag = (string) $tag;

			if ( 0 !== strpos( $tag, $this->tag_prefix() ) ) {
				return null;
			}

			$version = substr( $tag, strlen( $this->tag_prefix() ) );

			return preg_match( '/^\d+(\.\d+)*/', $version ) ? $version : null;
		}

		/**
		 * The asset named for this plugin. A monorepo release may carry several
		 * ZIPs, so the zipball fallback only applies when the release has no
		 * assets at all.
		 */
		private function package_url( array $entry ) {
			if ( ! empty( $entry['assets'] ) && is_array( $entry['assets'] ) ) {
				foreach ( $entry['assets'] as $asset ) {
					if ( empty( $asset['name'] ) || empty( $asset['browser_download_url'] ) ) {
						continue;
					}

					if ( 0 === strpos( $asset['name'], $this->slug ) && '.zip' === substr( $asset['name'], -4 ) ) {
						return $asset['browser_download_url'];
					}
				}

				return '';
			}

			return isset( $entry['zipball_url'] ) ? $entry['zipball_url'] : '';
		}

		public function inject_update( $transient ) {
			if ( ! is_object( $transient ) ) {
				return $transient;
			}

			$release = $this->release();

			if ( ! $release || empty( $release['version'] ) ) {
				return $transient;
			}

			$item = (object) array(
				'id'               => $this->slug,
				'slug'             => $this->slug,
				'plugin'           => $this->basename,
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

			if ( version_compare( $release['version'], $this->version, '>' ) ) {
				$transient->response[ $this->basename ] = $item;

				unset( $transient->no_update[ $this->basename ] );

				return $transient;
			}

			// Up to date. This has to be recorded too: a plugin that appears in
			// neither list reads to WordPress as one with no update mechanism at
			// all, and the Plugins screen then hides the auto-update toggle and
			// says auto-updates are unavailable for it.
			$item->new_version = $this->version;

			$transient->no_update[ $this->basename ] = $item;

			unset( $transient->response[ $this->basename ] );

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
		public function plugin_info( $result, $action, $args ) {
			if ( 'plugin_information' !== $action ) {
				return $result;
			}

			$slug = is_object( $args ) && isset( $args->slug ) ? $args->slug : '';
			$slug = ( '' === $slug && is_array( $args ) && isset( $args['slug'] ) ) ? $args['slug'] : $slug;

			// Callers are supposed to pass the slug. Some pass the plugin file.
			if ( $this->slug !== $slug && $this->basename !== $slug ) {
				return $result;
			}

			$release = $this->release();
			$notes   = '';

			if ( $release && ! empty( $release['notes'] ) ) {
				$notes = wpautop( esc_html( $release['notes'] ) );
			} elseif ( $miss = $this->last_miss() ) {
				$notes = wpautop( esc_html( sprintf(
					/* translators: %s: why the release could not be read */
					__( 'The release notes could not be read just now: %s', 'dos-github-updater' ),
					$miss['reason']
				) ) );
			}

			$info = array(
				'name'              => $this->name,
				'slug'              => $this->slug,
				'plugin'            => $this->basename,
				'version'           => $release ? $release['version'] : $this->version,
				'author'            => '<a href="https://departmentofsearch.com">Department of Search</a>',
				'author_profile'    => 'https://departmentofsearch.com',
				'homepage'          => 'https://github.com/' . $this->repo,
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
					'description' => wpautop( esc_html( $this->description ) ),
					'changelog'   => $notes ? $notes : wpautop( esc_html__( 'No release notes were published for this version.', 'dos-github-updater' ) ),
				),
			);

			if ( $release && ! empty( $release['url'] ) ) {
				$info['sections']['changelog'] .= sprintf(
					'<p><a href="%s" target="_blank" rel="noopener">%s</a></p>',
					esc_url( $release['url'] ),
					esc_html__( 'Read this release on GitHub', 'dos-github-updater' )
				);
			}

			return (object) $info;
		}

		/**
		 * A ZIP asset built by the release workflow already unpacks to
		 * `<slug>/`. A GitHub source archive does not — it unpacks to
		 * `owner-repo-abc1234`, which WordPress would install as a second,
		 * differently-named plugin. Rename it back before install.
		 */
		public function fix_source_dir( $source, $remote_source, $upgrader, $extra = array() ) {
			global $wp_filesystem;

			if ( empty( $extra['plugin'] ) || $this->basename !== $extra['plugin'] ) {
				return $source;
			}

			if ( ! $wp_filesystem ) {
				return $source;
			}

			$desired = trailingslashit( $remote_source ) . $this->slug;

			if ( trailingslashit( $source ) === trailingslashit( $desired ) ) {
				return $source;
			}

			if ( ! $wp_filesystem->move( $source, $desired, true ) ) {
				return new WP_Error( 'dos_rename_failed', __( 'Could not rename the update folder.', 'dos-github-updater' ) );
			}

			return trailingslashit( $desired );
		}
	}
}
