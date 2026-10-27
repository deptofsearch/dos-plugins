<?php
/**
 * The index of cities that have agent cards: what the homepage search and the "Browse by state"
 * counts both read, so the two can never disagree with each other or with the pages.
 *
 * A city is in the index only when all three are true: agents are stored for it, its page is
 * published, and that page's content actually holds [breanm_agents]. The last one is the point of
 * the whole module. Agents are pushed to this plugin before the page is edited, and a city can be
 * held for review (pushed, never published) or rolled back, so "agents are stored" on its own would
 * offer visitors cities whose live pages show nothing.
 *
 * The index is one option, not a transient: it is derived from other options rather than from an
 * expensive query, so there is nothing to recompute when it expires, and an empty index would make
 * the homepage claim the site has no cities at all. It is not autoloaded; only the homepage, the
 * state hubs and the cities route read it.
 *
 * @see dos_agents_index_rebuild() for the repair path when it drifts.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The page slug a city slug lives at: "boise-id" -> "in-boise-id". Every city page on the site is
 * named this way. Filterable so a site that moves them does not need a new release.
 */
function dos_agents_city_page_slug( $slug ) {
	return (string) apply_filters( 'dos_agents_city_page_slug', 'in-' . $slug, $slug );
}

/**
 * The published page for a city slug, or null. Only `publish` counts: a city held for review has a
 * `-preview` draft, and a draft is not something to send a visitor to.
 */
function dos_agents_city_page( $slug ) {
	$page = get_page_by_path( dos_agents_city_page_slug( $slug ), OBJECT, 'page' );
	if ( ! $page instanceof WP_Post || 'publish' !== $page->post_status ) {
		return null;
	}
	return $page;
}

/** One index row for a city slug, or null when it has no cards a visitor can see. */
function dos_agents_index_row( $slug ) {
	$slug = sanitize_title( $slug );
	if ( '' === $slug ) {
		return null;
	}
	$stored = get_option( dos_agents_option_name( $slug ), null );
	if ( ! is_array( $stored ) || empty( $stored['agents'] ) || ! is_array( $stored['agents'] ) ) {
		return null;
	}
	$page = dos_agents_city_page( $slug );
	if ( null === $page || ! has_shortcode( (string) $page->post_content, 'breanm_agents' ) ) {
		return null;
	}
	return array(
		'c' => dos_agents_str( isset( $stored['city'] ) ? $stored['city'] : '', 80 ),
		's' => dos_agents_state( isset( $stored['state'] ) ? $stored['state'] : '' ),
		'u' => get_permalink( $page ),
		'n' => count( $stored['agents'] ),
	);
}

/** The whole index: city slug => {c: city, s: ST, u: url, n: agents}, ordered by state then city. */
function dos_agents_index() {
	$index = get_option( DOS_AGENTS_INDEX_OPTION, array() );
	return is_array( $index ) ? $index : array();
}

/**
 * Bumped on every change and sent as ?v= on the cities route, so a browser that cached the index
 * yesterday asks for today's rather than offering cities that have since been rolled back. It does
 * nothing for a page the server cache is still holding: that page carries the old URL until the
 * cache drops it, which on this site is LiteSpeed's business, not ours.
 */
function dos_agents_index_ver() {
	return (int) get_option( DOS_AGENTS_INDEX_VER_OPTION, 1 );
}

/**
 * Writes the index, ordered, and bumps the version when the content actually changed.
 *
 * Read-modify-write with no lock: two requests touching different cities at the same moment can
 * lose one of the two rows. The sweep works one city at a time, so the window is small, and a
 * rebuild puts it right; a lock would cost more than the fault does.
 */
function dos_agents_index_put( array $index ) {
	uasort(
		$index,
		static function ( $a, $b ) {
			return array( $a['s'], $a['c'] ) <=> array( $b['s'], $b['c'] );
		}
	);
	if ( $index === dos_agents_index() ) {
		return;
	}
	update_option( DOS_AGENTS_INDEX_OPTION, $index, false );
	update_option( DOS_AGENTS_INDEX_VER_OPTION, dos_agents_index_ver() + 1, false );
}

/**
 * Re-reads one city and adds, updates or drops its row. Called after an upsert and after a page is
 * saved, which between them cover the order the sweep works in: push the agents, then edit the page.
 */
function dos_agents_index_touch( $slug ) {
	$slug = sanitize_title( $slug );
	if ( '' === $slug ) {
		return;
	}
	$index = dos_agents_index();
	$row   = dos_agents_index_row( $slug );
	if ( null === $row ) {
		if ( ! isset( $index[ $slug ] ) ) {
			return;
		}
		unset( $index[ $slug ] );
	} else {
		$index[ $slug ] = $row;
	}
	dos_agents_index_put( $index );
}

/**
 * Rebuilds from every stored city. The repair path: a page trashed or edited outside WordPress, or
 * a restore from backup, leaves rows the per-city hooks never hear about.
 */
function dos_agents_index_rebuild() {
	global $wpdb;

	$prefix = DOS_AGENTS_OPTION_PREFIX;
	// Our own options share the prefix. Derived from the constants so renaming one cannot leave a
	// stale literal here that quietly starts being read as a city.
	$reserved = array(
		substr( DOS_AGENTS_INDEX_OPTION, strlen( $prefix ) ),
		substr( DOS_AGENTS_INDEX_VER_OPTION, strlen( $prefix ) ),
		substr( DOS_AGENTS_DISCLOSURE_OPTION, strlen( $prefix ) ),
	);
	$names  = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one options scan, admin-triggered; there is no API for "options with this prefix".
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( $prefix ) . '%'
		)
	);

	$index = array();
	foreach ( (array) $names as $name ) {
		$slug = substr( (string) $name, strlen( $prefix ) );
		if ( '' === $slug || in_array( $slug, $reserved, true ) || sanitize_title( $slug ) !== $slug ) {
			continue;
		}
		$row = dos_agents_index_row( $slug );
		if ( null !== $row ) {
			$index[ $slug ] = $row;
		}
		// Let that city's agents go again. WordPress keeps every option value it has read for the
		// rest of the request, and a city holds up to 500 agents, so without this a rebuild over the
		// whole queue accumulates all of them and runs out of memory before it finishes.
		wp_cache_delete( $name, 'options' );
	}
	dos_agents_index_put( $index );
	return count( $index );
}

/** The index as the browser wants it: a plain list of {c, s, u}, agent counts left out. */
function dos_agents_index_public() {
	$out = array();
	foreach ( dos_agents_index() as $row ) {
		$out[] = array(
			'c' => $row['c'],
			's' => $row['s'],
			'u' => $row['u'],
		);
	}
	return $out;
}

/** Cities per state across the index: {ST: count}, most cities first then state name. */
function dos_agents_index_states() {
	$counts = array();
	foreach ( dos_agents_index() as $row ) {
		if ( '' === $row['s'] ) {
			continue;
		}
		$counts[ $row['s'] ] = isset( $counts[ $row['s'] ] ) ? $counts[ $row['s'] ] + 1 : 1;
	}
	$names = dos_agents_state_names();
	uksort(
		$counts,
		static function ( $a, $b ) use ( $counts, $names ) {
			$an = isset( $names[ $a ] ) ? $names[ $a ] : $a;
			$bn = isset( $names[ $b ] ) ? $names[ $b ] : $b;
			return array( -$counts[ $a ], $an ) <=> array( -$counts[ $b ], $bn );
		}
	);
	return $counts;
}

/** The index rows for one state, in city order. */
function dos_agents_index_for_state( $state ) {
	$state = dos_agents_state( $state );
	if ( '' === $state ) {
		return array();
	}
	$rows = array();
	foreach ( dos_agents_index() as $slug => $row ) {
		if ( $row['s'] === $state ) {
			$rows[ $slug ] = $row;
		}
	}
	return $rows;
}

/** The city slug a page slug belongs to ("in-boise-id" -> "boise-id"), or '' when it is not one. */
function dos_agents_city_slug_from_page( $page_slug ) {
	$prefix = dos_agents_city_page_slug( '' );
	if ( '' === $prefix || 0 !== strpos( (string) $page_slug, $prefix ) ) {
		return '';
	}
	return substr( (string) $page_slug, strlen( $prefix ) );
}

/**
 * save_post: keeps the index current when the sweep adds the shortcode to a page, when a page is
 * unpublished, and when a rollback strips the shortcode back out.
 */
function dos_agents_index_on_save( $post_id, $post ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'page' !== $post->post_type ) {
		return;
	}
	$slug = dos_agents_city_slug_from_page( $post->post_name );
	if ( '' !== $slug ) {
		dos_agents_index_touch( $slug );
	}
}

/**
 * deleted_post: a page can also leave without ever being saved — a REST DELETE with force=true, or
 * wp_delete_post(). Trashing fires save_post, but those two do not, and the index would go on
 * offering a city whose page is a 404 until somebody rebuilt it.
 */
function dos_agents_index_on_delete( $post_id, $post = null ) {
	if ( ! $post instanceof WP_Post ) {
		$post = get_post( $post_id );
	}
	if ( ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
		return;
	}
	$slug = dos_agents_city_slug_from_page( $post->post_name );
	if ( '' !== $slug ) {
		dos_agents_index_touch( $slug );
	}
}
