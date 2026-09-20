<?php
/**
 * Internal Links module.
 *
 * Rules are written into post content rather than applied as the page
 * renders, which is what makes the two destructive jobs destructive. Both go
 * through the shared batch runner and inherit its dry-run-first requirement.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-dos-links-engine.php';
require_once __DIR__ . '/class-dos-links-rules.php';

final class DOS_Module_Links extends DOS_Module {

	const KEY = 'links';

	/** Items per pass for jobs that walk the content tables. */
	const BATCH = 20;

	public static function init() {
		add_action( 'admin_init', array( 'DOS_Links_Rules', 'maybe_install' ), 4 );
		add_action( 'admin_init', array( __CLASS__, 'handle_post' ), 20 );
	}

	public static function pages() {
		return array(
			array(
				'slug'     => 'dos-links',
				'title'    => __( 'Internal Links', 'dos-toolkit' ),
				'callback' => array( __CLASS__, 'render_page' ),
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * What gets walked
	 * ------------------------------------------------------------------- */

	public static function post_types() {
		$types = get_post_types( array( 'public' => true ), 'names' );

		unset( $types['attachment'] );

		return apply_filters( 'dos_toolkit_link_post_types', array_values( $types ) );
	}

	public static function count_posts() {
		$query = new WP_Query(
			array(
				'post_type'      => self::post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		return (int) $query->found_posts;
	}

	private static function page_of_posts( $offset, $size ) {
		return get_posts(
			array(
				'post_type'              => self::post_types(),
				'post_status'            => 'publish',
				'posts_per_page'         => $size,
				'offset'                 => $offset,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Jobs
	 * ------------------------------------------------------------------- */

	public static function jobs() {
		$jobs = array(
			'links_scan' => array(
				'label'       => __( 'Scan for link opportunities', 'dos-toolkit' ),
				'description' => __( 'Counts where every enabled rule could place a link, and how many links are already in place. Changes nothing. Run it before applying, and again afterwards to see the result.', 'dos-toolkit' ),
				'batch_size'  => self::BATCH,
				'always_live' => true,
				'count'       => array( __CLASS__, 'count_posts' ),
				'step'        => array( __CLASS__, 'scan_step' ),
			),
			'links_apply' => array(
				'label'       => __( 'Apply links', 'dos-toolkit' ),
				'description' => __( 'Writes the links into the content of published posts and pages. The dry run lists every page it would change and how many links it would add. Links are marked so they can be removed again.', 'dos-toolkit' ),
				'batch_size'  => self::BATCH,
				'destructive' => true,
				'count'       => array( __CLASS__, 'count_posts' ),
				'step'        => array( __CLASS__, 'apply_step' ),
			),
			'links_remove' => array(
				'label'       => __( 'Remove all links this module made', 'dos-toolkit' ),
				'description' => __( 'Strips every link carrying this module\'s marker and leaves the text behind. Links added by hand are untouched.', 'dos-toolkit' ),
				'batch_size'  => self::BATCH,
				'destructive' => true,
				'count'       => array( __CLASS__, 'count_posts' ),
				'step'        => array( __CLASS__, 'remove_step' ),
			),
		);

		// One pair for the phrase currently being worked on, and only that
		// one. Registering a pair per rule meant several hundred closures
		// built on every admin request once a site had a real keyword list.
		$only = self::rescan_id();

		if ( $only ) {
			$rule = DOS_Links_Rules::get( $only );
		}

		if ( $only && ! empty( $rule ) ) {
			$id = (int) $rule['id'];

			$jobs[ 'links_scan_' . $id ] = array(
				/* translators: %s: keyword phrase */
				'label'       => sprintf( __( 'Rescan “%s”', 'dos-toolkit' ), $rule['phrase'] ),
				'description' => __( 'Looks for this phrase across the whole site again, so content added since the phrase was set up is counted. Changes nothing.', 'dos-toolkit' ),
				'batch_size'  => self::BATCH,
				'always_live' => true,
				'count'       => array( __CLASS__, 'count_posts' ),
				'step'        => function ( $offset, $size, $dry_run ) use ( $id ) {
					return DOS_Module_Links::scan_step( $offset, $size, $dry_run, $id );
				},
			);

			$jobs[ 'links_apply_' . $id ] = array(
				/* translators: %s: keyword phrase */
				'label'       => sprintf( __( 'Apply links for “%s”', 'dos-toolkit' ), $rule['phrase'] ),
				'description' => __( 'Writes links for this phrase only. Every other phrase is left exactly as it is.', 'dos-toolkit' ),
				'batch_size'  => self::BATCH,
				'destructive' => true,
				'count'       => array( __CLASS__, 'count_posts' ),
				'step'        => function ( $offset, $size, $dry_run ) use ( $id ) {
					return DOS_Module_Links::apply_step( $offset, $size, $dry_run, $id );
				},
			);
		}

		return $jobs;
	}

	/**
	 * Which phrase the per-phrase jobs are for.
	 *
	 * Taken from the screen when one is open, and from the job key when the
	 * runner calls back over AJAX — that request carries no page state, so
	 * without this the job it is asking to run would not be registered.
	 */
	public static function rescan_id() {
		if ( isset( $_GET['rescan'] ) ) {
			return (int) $_GET['rescan'];
		}

		if ( isset( $_POST['job'] ) && preg_match( '/^links_(?:scan|apply)_(\d+)$/', sanitize_key( wp_unslash( $_POST['job'] ) ), $m ) ) {
			return (int) $m[1];
		}

		return 0;
	}

	/**
	 * Every enabled rule, or just one of them.
	 *
	 * Rescanning a single phrase is what a site needs months later, when
	 * content has been added that the phrase now appears in and a full pass
	 * over everything is more than the question deserves.
	 */
	private static function rules_for( $only_rule ) {
		$only_rule = (int) $only_rule;

		if ( ! $only_rule ) {
			return DOS_Links_Rules::all( true );
		}

		$rule = DOS_Links_Rules::get( $only_rule );

		return ( $rule && $rule['enabled'] ) ? array( $rule ) : array();
	}

	public static function scan_step( $offset, $size, $dry_run, $only_rule = 0 ) {
		if ( 0 === $offset ) {
			DOS_Links_Rules::reset_stats( (int) $only_rule );
		}

		$rules = self::rules_for( $only_rule );
		$posts = self::page_of_posts( $offset, $size );
		$notes = array();
		$total = 0;

		foreach ( $posts as $post ) {
			foreach ( $rules as $rule ) {
				$analysis = DOS_Links_Engine::analyse( $post->post_content, $rule, $post->ID );
				$existing = substr_count( $post->post_content, 'data-dos-link="' . (int) $rule['id'] . '"' );

				if ( $analysis['occurrences'] ) {
					DOS_Links_Rules::add_found( $rule['id'], $analysis['occurrences'] );
				}

				// Why a rule produced nothing is worth more than the zero.
				if ( ! $analysis['placements'] && $analysis['reason'] && 'absent' !== $analysis['reason'] ) {
					DOS_Links_Rules::set_reason( $rule['id'], $analysis['reason'] );
				}

				if ( ! $analysis['placements'] && ! $existing ) {
					continue;
				}

				DOS_Links_Rules::add_stats( $rule['id'], $analysis['placements'], $existing, 1 );

				$total += $analysis['placements'];
			}
		}

		if ( $total && count( $notes ) < 50 ) {
			$notes[] = sprintf(
				/* translators: 1: number of opportunities, 2: number of posts */
				__( '%1$d opportunities across %2$d posts in this batch.', 'dos-toolkit' ),
				$total,
				count( $posts )
			);
		}

		return array( 'processed' => count( $posts ), 'changed' => $total, 'notes' => $notes );
	}

	public static function apply_step( $offset, $size, $dry_run, $only_rule = 0 ) {
		$rules = self::rules_for( $only_rule );
		$posts = self::page_of_posts( $offset, $size );
		$notes = array();
		$count = 0;

		foreach ( $posts as $post ) {
			$result = DOS_Links_Engine::apply( $post->post_content, $rules, $post->ID );

			if ( ! $result['added'] ) {
				continue;
			}

			$added  = array_sum( $result['added'] );
			$count += $added;

			if ( count( $notes ) < 60 ) {
				$notes[] = sprintf(
					/* translators: 1: number of links, 2: post title */
					$dry_run ? __( 'Would add %1$d link(s) to "%2$s"', 'dos-toolkit' ) : __( 'Added %1$d link(s) to "%2$s"', 'dos-toolkit' ),
					$added,
					get_the_title( $post->ID )
				);
			}

			if ( ! $dry_run ) {
				self::write_content( $post->ID, $result['html'] );

				DOS_Log::add( 'links', 'links_added', sprintf( '%d added to "%s"', $added, get_the_title( $post->ID ) ), $post->ID );
			}
		}

		return array( 'processed' => count( $posts ), 'changed' => $count, 'notes' => $notes );
	}

	public static function remove_step( $offset, $size, $dry_run ) {
		$posts = self::page_of_posts( $offset, $size );
		$notes = array();
		$count = 0;

		foreach ( $posts as $post ) {
			$result = DOS_Links_Engine::strip( $post->post_content );

			if ( ! $result['removed'] ) {
				continue;
			}

			$count += $result['removed'];

			if ( count( $notes ) < 60 ) {
				$notes[] = sprintf(
					/* translators: 1: number of links, 2: post title */
					$dry_run ? __( 'Would remove %1$d link(s) from "%2$s"', 'dos-toolkit' ) : __( 'Removed %1$d link(s) from "%2$s"', 'dos-toolkit' ),
					$result['removed'],
					get_the_title( $post->ID )
				);
			}

			if ( ! $dry_run ) {
				self::write_content( $post->ID, $result['html'] );

				DOS_Log::add( 'links', 'links_removed', sprintf( '%d removed from "%s"', $result['removed'], get_the_title( $post->ID ) ), $post->ID );
			}
		}

		return array( 'processed' => count( $posts ), 'changed' => $count, 'notes' => $notes );
	}

	/**
	 * Write content without touching post_modified.
	 *
	 * wp_update_post() would stamp every post as modified today. The SEO
	 * module publishes dateModified, and the Utilities module shows a Last
	 * Updated column, so an automated pass over the whole site would tell
	 * search engines and editors alike that every page had just been revised.
	 * Adding a link is not a revision of the article.
	 */
	private static function write_content( $post_id, $html ) {
		global $wpdb;

		$wpdb->update(
			$wpdb->posts,
			array( 'post_content' => $html ),
			array( 'ID' => (int) $post_id ),
			array( '%s' ),
			array( '%d' )
		);

		clean_post_cache( (int) $post_id );
	}

	/* ---------------------------------------------------------------------
	 * Screen
	 * ------------------------------------------------------------------- */

	public static function handle_post() {
		if ( empty( $_POST['dos_action'] ) || ! current_user_can( DOS_Settings::capability() ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['dos_action'] ) );

		if ( 0 !== strpos( $action, 'links_' ) ) {
			return;
		}

		check_admin_referer( 'dos_links' );

		switch ( $action ) {
			case 'links_add':
				$result = DOS_Links_Rules::add(
					isset( $_POST['phrase'] ) ? sanitize_text_field( wp_unslash( $_POST['phrase'] ) ) : '',
					isset( $_POST['target_id'] ) ? (int) $_POST['target_id'] : 0,
					array(
						'max_per_page'   => isset( $_POST['max_per_page'] ) ? (int) $_POST['max_per_page'] : 1,
						'first_instance' => isset( $_POST['first_instance'] ) ? sanitize_key( wp_unslash( $_POST['first_instance'] ) ) : 'link',
						'throttle'       => isset( $_POST['throttle'] ) ? (int) $_POST['throttle'] : 100,
					)
				);

				if ( is_wp_error( $result ) ) {
					set_transient( 'dos_links_error', $result->get_error_message(), 60 );
				} else {
					self::log( 'rule_saved', 'Link rule saved.' );
				}
				break;

			case 'links_delete':
				DOS_Links_Rules::delete( (int) $_POST['id'] );
				self::log( 'rule_deleted', 'Link rule deleted.' );
				break;

			case 'links_toggle':
				DOS_Links_Rules::set_enabled( (int) $_POST['id'], ! empty( $_POST['enabled'] ) );
				break;

			case 'links_import':
				$results = DOS_Links_Rules::add_many( isset( $_POST['import'] ) ? wp_unslash( $_POST['import'] ) : '' );

				set_transient( 'dos_links_import', $results, 120 );

				self::log( 'rules_imported', sprintf( '%d lines processed.', count( $results ) ) );
				break;

			case 'links_bulk':
				$done = DOS_Links_Rules::bulk(
					isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '',
					isset( $_POST['ids'] ) ? (array) wp_unslash( $_POST['ids'] ) : array()
				);

				if ( $done ) {
					self::log( 'rules_bulk', sprintf( '%d phrases changed.', $done ) );
				}
				break;

			case 'links_test':
				set_transient(
					'dos_links_test',
					self::test( isset( $_POST['test_target'] ) ? sanitize_text_field( wp_unslash( $_POST['test_target'] ) ) : '' ),
					120
				);
				break;
		}

		$back = array( 'page' => 'dos-links', 'dos_notice' => 'saved' );

		foreach ( array( 's', 'status', 'orderby', 'order', 'paged' ) as $key ) {
			if ( ! empty( $_POST[ $key ] ) ) {
				$back[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			}
		}

		wp_safe_redirect( add_query_arg( $back, admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Screen helpers
	 * ------------------------------------------------------------------- */

	private static function state() {
		return array(
			'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'status'   => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all',
			'orderby'  => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'phrase',
			'order'    => isset( $_GET['order'] ) && 'desc' === strtolower( $_GET['order'] ) ? 'desc' : 'asc',
			'page'     => isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1,
			'per_page' => 50,
		);
	}

	private static function url( array $args ) {
		$state = self::state();

		$base = array(
			'page'    => 'dos-links',
			's'       => $state['search'],
			'status'  => $state['status'],
			'orderby' => $state['orderby'],
			'order'   => $state['order'],
			'paged'   => $state['page'],
		);

		return add_query_arg( array_filter( array_merge( $base, $args ), 'strlen' ), admin_url( 'admin.php' ) );
	}

	/**
	 * A column heading that sorts, and flips direction when it is already
	 * the one being sorted by.
	 */
	private static function sort_link( $column, $label ) {
		$state = self::state();
		$is    = $state['orderby'] === $column;
		$next  = ( $is && 'asc' === $state['order'] ) ? 'desc' : 'asc';
		$arrow = $is ? ( 'asc' === $state['order'] ? ' ↑' : ' ↓' ) : '';

		printf(
			'<a href="%s">%s%s</a>',
			esc_url( self::url( array( 'orderby' => $column, 'order' => $next, 'paged' => 1 ) ) ),
			esc_html( $label ),
			esc_html( $arrow )
		);
	}

	/**
	 * Run every rule against one page and report exactly what the engine
	 * sees.
	 *
	 * A count of zero has several causes, and inferring which one from the
	 * outside took two rounds of guesswork the first time it happened. This
	 * answers it directly.
	 */
	public static function test( $target ) {
		$target = trim( (string) $target );
		$out    = array( 'target' => $target, 'post' => null, 'rows' => array(), 'error' => '' );

		if ( '' === $target ) {
			$out['error'] = __( 'Enter a post ID or a URL on this site.', 'dos-toolkit' );

			return $out;
		}

		$post_id = ctype_digit( $target ) ? (int) $target : (int) url_to_postid( $target );

		if ( ! $post_id ) {
			$out['error'] = __( 'Nothing on this site matches that. Use the numeric post ID if the URL will not resolve.', 'dos-toolkit' );

			return $out;
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			$out['error'] = __( 'That post does not exist.', 'dos-toolkit' );

			return $out;
		}

		$out['post'] = array(
			'id'     => $post_id,
			'title'  => get_the_title( $post_id ),
			'status' => $post->post_status,
			'type'   => $post->post_type,
			'length' => strlen( $post->post_content ),
		);

		$rules = DOS_Links_Rules::all();

		if ( ! $rules ) {
			$out['error'] = __( 'No phrases are configured yet.', 'dos-toolkit' );

			return $out;
		}

		foreach ( $rules as $rule ) {
			$analysis = DOS_Links_Engine::analyse( $post->post_content, $rule, $post_id );

			// Raw count, before any of the exclusions, so "present but never
			// linkable" is distinguishable from "not present at all".
			$raw = preg_match_all( '/(?<![\w\-])' . preg_quote( $rule['phrase'], '/' ) . '(?![\w\-])/iu', wp_strip_all_tags( $post->post_content ) );

			$out['rows'][] = array(
				'phrase'      => $rule['phrase'],
				'enabled'     => (int) $rule['enabled'],
				'target'      => (int) $rule['target_id'],
				'target_name' => get_the_title( (int) $rule['target_id'] ),
				'raw'         => (int) $raw,
				'linkable'    => (int) $analysis['occurrences'],
				'placements'  => (int) $analysis['placements'],
				'reason'      => $analysis['reason'],
				'limits'      => sprintf( '%d/page, %s, %d%%', (int) $rule['max_per_page'], 'skip' === $rule['first_instance'] ? 'not first' : 'first ok', (int) $rule['throttle'] ),
			);
		}

		return $out;
	}

	private static function reason_text( $row ) {
		if ( ! $row['enabled'] ) {
			return __( 'This phrase is disabled.', 'dos-toolkit' );
		}

		if ( $row['placements'] ) {
			return __( 'Would be linked here.', 'dos-toolkit' );
		}

		switch ( $row['reason'] ) {
			case 'self':
				return __( 'This page is the destination, and a page never links to itself.', 'dos-toolkit' );

			case 'first_only':
				return __( 'Set to leave the first occurrence alone, and there is only one here.', 'dos-toolkit' );

			case 'throttled':
				return __( 'Excluded by the percentage limit. Raise it towards 100%.', 'dos-toolkit' );

			case 'absent':
				return $row['raw']
					? __( 'Present, but every occurrence is inside a heading, bold text, a list, a table, an existing link or a shortcode.', 'dos-toolkit' )
					: __( 'This phrase does not appear on this page.', 'dos-toolkit' );

			default:
				return __( 'Excluded by this rule\'s limits.', 'dos-toolkit' );
		}
	}

	public static function render_page() {
		$error = get_transient( 'dos_links_error' );

		if ( $error ) {
			delete_transient( 'dos_links_error' );
		}

		$targets = get_posts(
			array(
				'post_type'      => self::post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => 300,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Internal Links', 'dos-toolkit' ); ?></h1>

			<?php if ( $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php else : ?>
				<?php DOS_Admin::notice(); ?>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'A phrase, a page it should point at, and limits on how often it is used. Phrases are never linked inside headings, bold text, lists, tables, existing links, code or shortcodes, and a page never links to itself.', 'dos-toolkit' ); ?>
			</p>

			<?php $totals = DOS_Links_Rules::totals(); ?>

			<div class="dos-stats">
				<div class="dos-stat">
					<span class="dos-stat-n"><?php echo (int) $totals['phrases']; ?></span>
					<span class="dos-stat-l"><?php esc_html_e( 'phrases', 'dos-toolkit' ); ?></span>
					<span class="description"><?php printf( esc_html__( '%d switched on', 'dos-toolkit' ), (int) $totals['enabled'] ); ?></span>
				</div>
				<div class="dos-stat">
					<span class="dos-stat-n"><?php echo (int) $totals['links']; ?></span>
					<span class="dos-stat-l"><?php esc_html_e( 'links in place', 'dos-toolkit' ); ?></span>
					<span class="description"><?php printf( esc_html__( '%d more available', 'dos-toolkit' ), (int) $totals['available'] ); ?></span>
				</div>
				<div class="dos-stat">
					<span class="dos-stat-n<?php echo $totals['top_share'] >= 40 && $totals['phrases'] > 1 ? ' dos-media-warning' : ''; ?>"><?php echo esc_html( $totals['top_share'] ); ?>%</span>
					<span class="dos-stat-l"><?php esc_html_e( 'busiest phrase', 'dos-toolkit' ); ?></span>
					<span class="description"><?php esc_html_e( 'share of all links placed', 'dos-toolkit' ); ?></span>
				</div>
				<div class="dos-stat">
					<span class="dos-stat-n"><?php echo (int) $totals['idle']; ?></span>
					<span class="dos-stat-l"><?php esc_html_e( 'doing nothing', 'dos-toolkit' ); ?></span>
					<span class="description">
						<a href="<?php echo esc_url( self::url( array( 'status' => 'idle', 'paged' => 1 ) ) ); ?>"><?php esc_html_e( 'show them', 'dos-toolkit' ); ?></a>
					</span>
				</div>
			</div>

			<details class="dos-panel"<?php echo $totals['phrases'] ? '' : ' open'; ?>>
				<summary><strong><?php esc_html_e( 'Add phrases', 'dos-toolkit' ); ?></strong></summary>

				<h3><?php esc_html_e( 'One at a time', 'dos-toolkit' ); ?></h3>

				<form method="post">
					<?php wp_nonce_field( 'dos_links' ); ?>
					<input type="hidden" name="dos_action" value="links_add" />

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="phrase"><?php esc_html_e( 'Keyword phrase', 'dos-toolkit' ); ?></label></th>
							<td>
								<input type="text" id="phrase" name="phrase" class="regular-text" required placeholder="<?php esc_attr_e( 'open houses in Phoenix', 'dos-toolkit' ); ?>" />
								<p class="description"><?php esc_html_e( 'Two words or more. Matched whole and without regard to case.', 'dos-toolkit' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="target_id"><?php esc_html_e( 'Links to', 'dos-toolkit' ); ?></label></th>
							<td>
								<select id="target_id" name="target_id" required>
									<option value=""><?php esc_html_e( '— choose a page —', 'dos-toolkit' ); ?></option>
									<?php foreach ( $targets as $target ) : ?>
										<option value="<?php echo (int) $target->ID; ?>"><?php echo esc_html( $target->post_title ? $target->post_title : __( '(no title)', 'dos-toolkit' ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Limits', 'dos-toolkit' ); ?></th>
							<td>
								<label>
									<input type="number" name="max_per_page" value="1" min="1" max="20" class="small-text" />
									<?php esc_html_e( 'links per page', 'dos-toolkit' ); ?>
								</label>
								&nbsp;&nbsp;
								<label>
									<input type="number" name="throttle" value="100" min="0" max="100" class="small-text" /> %
									<?php esc_html_e( 'of available places', 'dos-toolkit' ); ?>
								</label>
								<p style="margin-top:.6em">
									<label style="margin-right:1em"><input type="radio" name="first_instance" value="link" checked /> <?php esc_html_e( 'Link the first occurrence', 'dos-toolkit' ); ?></label>
									<label><input type="radio" name="first_instance" value="skip" /> <?php esc_html_e( 'Leave the first alone', 'dos-toolkit' ); ?></label>
								</p>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Save phrase', 'dos-toolkit' ), 'secondary' ); ?>
				</form>

				<h3><?php esc_html_e( 'Many at once', 'dos-toolkit' ); ?></h3>

				<p class="description">
					<?php esc_html_e( 'One phrase per line. Separate the fields with a vertical bar. Only the phrase and its destination are required; the rest fall back to one link per page, first occurrence linked, 100%.', 'dos-toolkit' ); ?>
				</p>

				<p class="description">
					<code><?php echo esc_html( 'phrase | destination | links per page | first | percent' ); ?></code><br>
					<code><?php echo esc_html( 'open houses in Phoenix | 12' ); ?></code><br>
					<code><?php echo esc_html( 'Phoenix real estate agent | /agents/ | 2 | skip | 60' ); ?></code>
				</p>

				<p class="description">
					<?php esc_html_e( 'A destination can be a numeric ID, a URL on this site, or the exact page title. Lines starting with # are ignored.', 'dos-toolkit' ); ?>
				</p>

				<form method="post">
					<?php wp_nonce_field( 'dos_links' ); ?>
					<input type="hidden" name="dos_action" value="links_import" />
					<p><textarea name="import" rows="8" class="large-text code" placeholder="<?php esc_attr_e( 'open houses in Phoenix | 12', 'dos-toolkit' ); ?>"></textarea></p>
					<?php submit_button( __( 'Import phrases', 'dos-toolkit' ), 'secondary' ); ?>
				</form>

				<?php $import = get_transient( 'dos_links_import' ); ?>
				<?php if ( $import ) : ?>
					<?php delete_transient( 'dos_links_import' ); ?>
					<?php
					$ok     = count( array_filter( wp_list_pluck( $import, 'ok' ) ) );
					$failed = count( $import ) - $ok;
					?>
					<div class="notice notice-<?php echo $failed ? 'warning' : 'success'; ?> inline">
						<p>
							<?php
							printf(
								/* translators: 1: number added, 2: number refused */
								esc_html__( '%1$d added, %2$d refused.', 'dos-toolkit' ),
								(int) $ok,
								(int) $failed
							);
							?>
						</p>
					</div>

					<?php if ( $failed ) : ?>
						<table class="widefat striped" style="max-width:60em">
							<thead><tr><th><?php esc_html_e( 'Line', 'dos-toolkit' ); ?></th><th><?php esc_html_e( 'Why it was refused', 'dos-toolkit' ); ?></th></tr></thead>
							<tbody>
							<?php foreach ( $import as $line ) : ?>
								<?php if ( $line['ok'] ) { continue; } ?>
								<tr>
									<td><code><?php echo esc_html( $line['line'] ); ?></code></td>
									<td class="description"><?php echo esc_html( $line['message'] ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				<?php endif; ?>
			</details>

			<?php
			$state  = self::state();
			$result = DOS_Links_Rules::query( $state );
			$rules  = $result['rows'];
			$all    = DOS_Links_Rules::all();
			?>

			<h2><?php esc_html_e( 'Phrases', 'dos-toolkit' ); ?></h2>

			<form method="get" class="dos-filters">
				<input type="hidden" name="page" value="dos-links" />
				<input type="search" name="s" value="<?php echo esc_attr( $state['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search phrases', 'dos-toolkit' ); ?>" />
				<select name="status">
					<?php
					$statuses = array(
						'all'       => __( 'All', 'dos-toolkit' ),
						'enabled'   => __( 'Switched on', 'dos-toolkit' ),
						'disabled'  => __( 'Switched off', 'dos-toolkit' ),
						'idle'      => __( 'No links placed', 'dos-toolkit' ),
						'available' => __( 'Has places available', 'dos-toolkit' ),
					);
					?>
					<?php foreach ( $statuses as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $state['status'], $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'dos-toolkit' ); ?></button>
				<?php if ( $state['search'] || 'all' !== $state['status'] ) : ?>
					<a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=dos-links' ) ); ?>"><?php esc_html_e( 'Clear', 'dos-toolkit' ); ?></a>
				<?php endif; ?>
				<span class="description" style="margin-left:.5em">
					<?php
					printf(
						/* translators: 1: rows shown, 2: rows in total */
						esc_html__( 'showing %1$d of %2$d', 'dos-toolkit' ),
						count( $rules ),
						(int) $result['total']
					);
					?>
				</span>
			</form>

			<form method="post">
				<?php wp_nonce_field( 'dos_links' ); ?>
				<input type="hidden" name="dos_action" value="links_bulk" />
				<input type="hidden" name="s" value="<?php echo esc_attr( $state['search'] ); ?>" />
				<input type="hidden" name="status" value="<?php echo esc_attr( $state['status'] ); ?>" />
				<input type="hidden" name="orderby" value="<?php echo esc_attr( $state['orderby'] ); ?>" />
				<input type="hidden" name="order" value="<?php echo esc_attr( $state['order'] ); ?>" />
				<input type="hidden" name="paged" value="<?php echo (int) $state['page']; ?>" />

				<div class="tablenav top">
					<select name="bulk_action">
						<option value=""><?php esc_html_e( 'Bulk actions', 'dos-toolkit' ); ?></option>
						<option value="enable"><?php esc_html_e( 'Switch on', 'dos-toolkit' ); ?></option>
						<option value="disable"><?php esc_html_e( 'Switch off', 'dos-toolkit' ); ?></option>
						<option value="delete"><?php esc_html_e( 'Delete', 'dos-toolkit' ); ?></option>
					</select>
					<button type="submit" class="button"><?php esc_html_e( 'Apply', 'dos-toolkit' ); ?></button>
				</div>

				<table class="widefat striped">
					<thead>
						<tr>
							<td class="check-column"><input type="checkbox" onclick="this.closest('table').querySelectorAll('input[name=\'ids[]\']').forEach(function(b){b.checked=this.checked}.bind(this))" /></td>
							<th><?php self::sort_link( 'phrase', __( 'Phrase', 'dos-toolkit' ) ); ?></th>
							<th><?php esc_html_e( 'Links to', 'dos-toolkit' ); ?></th>
							<th><?php self::sort_link( 'max_per_page', __( 'Limits', 'dos-toolkit' ) ); ?></th>
							<th><?php self::sort_link( 'links_made', __( 'Links', 'dos-toolkit' ) ); ?></th>
							<th><?php esc_html_e( 'Share', 'dos-toolkit' ); ?></th>
							<th><?php self::sort_link( 'opportunities', __( 'Available', 'dos-toolkit' ) ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
					<?php if ( ! $rules ) : ?>
						<tr><td colspan="8"><?php esc_html_e( 'Nothing matches.', 'dos-toolkit' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rules as $rule ) : ?>
							<?php $share = DOS_Links_Rules::share( $rule, $all ); ?>
							<tr<?php echo $rule['enabled'] ? '' : ' style="opacity:.5"'; ?>>
								<th class="check-column"><input type="checkbox" name="ids[]" value="<?php echo (int) $rule['id']; ?>" /></th>
								<td><strong><?php echo esc_html( $rule['phrase'] ); ?></strong></td>
								<td><?php echo esc_html( get_the_title( (int) $rule['target_id'] ) ); ?></td>
								<td class="description">
									<?php
									printf(
										/* translators: 1: links per page, 2: first-occurrence behaviour, 3: percentage */
										esc_html__( '%1$d/page · %2$s · %3$d%%', 'dos-toolkit' ),
										(int) $rule['max_per_page'],
										'skip' === $rule['first_instance'] ? esc_html__( 'not first', 'dos-toolkit' ) : esc_html__( 'first ok', 'dos-toolkit' ),
										(int) $rule['throttle']
									);
									?>
								</td>
								<td><strong><?php echo (int) $rule['links_made']; ?></strong></td>
								<td<?php echo $share >= 40 && count( $all ) > 1 ? ' class="dos-media-warning"' : ''; ?>><?php echo esc_html( $share ); ?>%</td>
								<td>
									<?php echo (int) $rule['opportunities']; ?>
									<?php $why = DOS_Links_Rules::explain( $rule ); ?>
									<?php if ( $why ) : ?>
										<br><span class="description"><?php echo esc_html( $why ); ?></span>
									<?php endif; ?>
								</td>
								<td style="white-space:nowrap">
									<a href="<?php echo esc_url( self::url( array( 'rescan' => (int) $rule['id'] ) ) ); ?>#rescan"><?php esc_html_e( 'Rescan', 'dos-toolkit' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>

				<?php if ( $result['pages'] > 1 ) : ?>
					<div class="tablenav bottom">
						<span class="description" style="margin-right:1em">
							<?php
							printf(
								/* translators: 1: current page, 2: total pages */
								esc_html__( 'Page %1$d of %2$d', 'dos-toolkit' ),
								(int) $state['page'],
								(int) $result['pages']
							);
							?>
						</span>
						<?php if ( $state['page'] > 1 ) : ?>
							<a class="button" href="<?php echo esc_url( self::url( array( 'paged' => $state['page'] - 1 ) ) ); ?>">&laquo; <?php esc_html_e( 'Previous', 'dos-toolkit' ); ?></a>
						<?php endif; ?>
						<?php if ( $state['page'] < $result['pages'] ) : ?>
							<a class="button" href="<?php echo esc_url( self::url( array( 'paged' => $state['page'] + 1 ) ) ); ?>"><?php esc_html_e( 'Next', 'dos-toolkit' ); ?> &raquo;</a>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</form>

			<?php
			$rescan_id = isset( $_GET['rescan'] ) ? (int) $_GET['rescan'] : 0;
			$rescan    = $rescan_id ? DOS_Links_Rules::get( $rescan_id ) : null;
			?>

			<?php if ( $rescan ) : ?>
				<hr>
				<h2 id="rescan">
					<?php
					printf(
						/* translators: %s: keyword phrase */
						esc_html__( 'Just this phrase: %s', 'dos-toolkit' ),
						esc_html( $rescan['phrase'] )
					);
					?>
				</h2>

				<p class="description">
					<?php esc_html_e( 'Rescan when content has been added since this phrase was set up. Applying writes links for this phrase alone and leaves every other phrase untouched.', 'dos-toolkit' ); ?>
				</p>

				<?php
				DOS_Batch::render_runner( 'links_scan_' . $rescan_id );
				DOS_Batch::render_runner( 'links_apply_' . $rescan_id );
				?>
			<?php endif; ?>

			<hr>
			<h2><?php esc_html_e( 'Check one page', 'dos-toolkit' ); ?></h2>

			<p class="description">
				<?php esc_html_e( 'Run every phrase against a single page and see exactly what the matcher finds. Nothing is changed.', 'dos-toolkit' ); ?>
			</p>

			<form method="post">
				<?php wp_nonce_field( 'dos_links' ); ?>
				<input type="hidden" name="dos_action" value="links_test" />
				<input type="text" name="test_target" class="regular-text code" placeholder="<?php esc_attr_e( 'post ID, or a URL on this site', 'dos-toolkit' ); ?>" />
				<button type="submit" class="button"><?php esc_html_e( 'Check', 'dos-toolkit' ); ?></button>
			</form>

			<?php $test = get_transient( 'dos_links_test' ); ?>
			<?php if ( $test ) : ?>
				<?php delete_transient( 'dos_links_test' ); ?>

				<?php if ( ! empty( $test['error'] ) ) : ?>
					<div class="notice notice-warning inline"><p><?php echo esc_html( $test['error'] ); ?></p></div>
				<?php else : ?>
					<p>
						<strong><?php echo esc_html( $test['post']['title'] ); ?></strong>
						<span class="description">
							<?php
							printf(
								/* translators: 1: post ID, 2: post type, 3: post status, 4: content length */
								esc_html__( 'ID %1$d · %2$s · %3$s · %4$d characters of content', 'dos-toolkit' ),
								(int) $test['post']['id'],
								esc_html( $test['post']['type'] ),
								esc_html( $test['post']['status'] ),
								(int) $test['post']['length']
							);
							?>
						</span>
					</p>

					<table class="widefat striped" style="max-width:70em">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Phrase', 'dos-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Points at', 'dos-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Limits', 'dos-toolkit' ); ?></th>
								<th><?php esc_html_e( 'In the text', 'dos-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Linkable', 'dos-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Would link', 'dos-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Why', 'dos-toolkit' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $test['rows'] as $row ) : ?>
							<tr>
								<td><code><?php echo esc_html( $row['phrase'] ); ?></code></td>
								<td><?php echo esc_html( $row['target_name'] ); ?> <span class="description">#<?php echo (int) $row['target']; ?></span></td>
								<td class="description"><?php echo esc_html( $row['limits'] ); ?></td>
								<td><?php echo (int) $row['raw']; ?></td>
								<td><?php echo (int) $row['linkable']; ?></td>
								<td><strong><?php echo (int) $row['placements']; ?></strong></td>
								<td class="description"><?php echo esc_html( self::reason_text( $row ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php endif; ?>

			<hr>
			<h2><?php esc_html_e( 'Run', 'dos-toolkit' ); ?></h2>

			<p class="description">
				<?php esc_html_e( 'Scan first, read what it found, then apply. Applying writes links into your posts and pages; the dry run names every page it would change before anything is written.', 'dos-toolkit' ); ?>
			</p>

			<?php
			foreach ( array( 'links_scan', 'links_apply', 'links_remove' ) as $job ) {
				DOS_Batch::render_runner( $job );
			}
			?>
		</div>
		<?php
	}
}
