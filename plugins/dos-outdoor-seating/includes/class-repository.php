<?php
/**
 * Venue upsert: dedupe on source + source_id (then google place id, then maps cid), per-pass hash skip, city/term creation.
 *
 * Merge semantics: fields absent from the payload are left alone, so a seed pass and a later
 * enrichment pass can each send only what they know. Send null to clear a field.
 *
 * @package OSN
 */

namespace OSN;

defined( 'ABSPATH' ) || exit;

final class Repository {

	const STATUSES = array( 'publish', 'draft', 'pending', 'private' );

	/**
	 * @param mixed $raw        One venue object.
	 * @param bool  $dry_run    Report what would happen without writing.
	 * @param bool  $can_publish Whether the caller may publish (else created or explicitly-sent publish status becomes pending).
	 * @return array { source_id, action, id, link, message?, _terms: int[] city term IDs touched, _dirty: bool city index needs a flush }
	 */
	public static function upsert( $raw, $dry_run = false, $can_publish = true ) {
		$res = array(
			'source_id' => is_array( $raw ) && isset( $raw['source_id'] ) && is_scalar( $raw['source_id'] ) ? (string) $raw['source_id'] : null,
			'action'    => 'error',
			'id'        => null,
			'link'      => null,
		);
		if ( ! is_array( $raw ) ) {
			$res['message'] = 'Each venue must be an object.';
			return $res;
		}

		$fields   = array();
		$warnings = array();
		foreach ( Fields::payload_names() as $name ) {
			$key = null;
			if ( array_key_exists( $name, $raw ) ) {
				$key = $name;
			} elseif ( array_key_exists( Fields::key( $name ), $raw ) ) {
				$key = Fields::key( $name );
			}
			if ( null === $key ) {
				continue;
			}
			$value = $raw[ $key ];
			if ( null === $value ) {
				$fields[ $name ] = null;
				continue;
			}
			if ( ! is_scalar( $value ) && ! is_array( $value ) ) {
				continue;
			}
			$clean = Fields::sanitize( $name, $value );
			if ( '' === $clean ) {
				if ( '' !== $value && array() !== $value ) {
					$warnings[] = sprintf( 'Ignored invalid %s.', $name );
				} else {
					$fields[ $name ] = null; // Explicit empty clears the field.
				}
				continue;
			}
			$fields[ $name ] = $clean;
		}

		$source = isset( $fields['source'] ) ? (string) $fields['source'] : '';
		$sid    = isset( $fields['source_id'] ) ? (string) $fields['source_id'] : '';
		$place  = isset( $fields['google_place_id'] ) ? (string) $fields['google_place_id'] : '';
		$cid    = isset( $fields['maps_cid'] ) ? (string) $fields['maps_cid'] : '';
		if ( '' === $source || '' === $sid ) {
			$res['message'] = 'source and source_id are required.';
			return $res;
		}
		$res['source_id'] = $sid;

		$pass = isset( $raw['pass'] ) && is_scalar( $raw['pass'] ) ? substr( preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $raw['pass'] ) ), 0, 40 ) : '';
		if ( '' === $pass ) {
			$pass = substr( preg_replace( '/[^a-z0-9_-]/', '', strtolower( $source ) ), 0, 40 );
		}
		if ( '' === $pass ) {
			$pass = 'default';
		}
		$hash_key = Fields::HASH_PREFIX . $pass;

		$existing = self::find( $source, $sid, $place, $cid );
		$post_id  = $existing ? (int) $existing : 0;

		$res['id'] = $post_id ? $post_id : null;
		if ( $post_id && 'trash' === get_post_status( $post_id ) ) {
			$res['action']  = 'skipped';
			$res['message'] = 'In the trash; not recreated.';
			return $res;
		}

		// City.
		$city_raw = isset( $raw['city'] ) && is_scalar( $raw['city'] ) ? trim( (string) $raw['city'] ) : '';
		$parsed   = $city_raw ? Util::parse_city( $city_raw ) : null;
		$city     = $parsed ? $parsed[0] . ', ' . $parsed[1] : '';
		if ( $city_raw && ! $parsed ) {
			$res['message'] = 'city must look like "City, ST".';
			return $res;
		}
		if ( ! $post_id && ! $parsed ) {
			$res['message'] = 'city is required to create a venue.';
			return $res;
		}
		if ( $parsed ) {
			if ( ! isset( $fields['city_name'] ) ) {
				$fields['city_name'] = $parsed[0];
			}
			if ( ! isset( $fields['state'] ) ) {
				$fields['state'] = $parsed[1];
			}
		}

		$title = isset( $raw['title'] ) && is_scalar( $raw['title'] ) ? sanitize_text_field( (string) $raw['title'] ) : '';
		if ( ! $post_id && '' === $title ) {
			$res['message'] = 'title is required to create a venue.';
			return $res;
		}

		$sent   = isset( $raw['status'] ) && in_array( $raw['status'], self::STATUSES, true ) ? $raw['status'] : null;
		$status = $sent ? $sent : ( $post_id ? get_post_status( $post_id ) : 'publish' );
		// Downgrade only on create, or when a caller without publish_posts explicitly asks for publish.
		if ( 'publish' === $status && ! $can_publish && ( ! $post_id || $sent ) ) {
			$status = 'pending';
		}

		$category = isset( $raw['category'] ) && is_scalar( $raw['category'] ) ? sanitize_text_field( (string) $raw['category'] ) : null;
		$amenities = null;
		if ( isset( $raw['amenities'] ) && is_array( $raw['amenities'] ) ) {
			$amenities = array();
			foreach ( $raw['amenities'] as $slug ) {
				if ( is_scalar( $slug ) && '' !== sanitize_title( (string) $slug ) ) {
					$amenities[] = sanitize_title( (string) $slug );
				}
			}
			$amenities = array_values( array_unique( $amenities ) );
			sort( $amenities );
		}
		$content = array_key_exists( 'content', $raw ) && is_scalar( $raw['content'] ) ? wp_kses_post( (string) $raw['content'] ) : null;
		$excerpt = array_key_exists( 'excerpt', $raw ) && is_scalar( $raw['excerpt'] ) ? sanitize_textarea_field( (string) $raw['excerpt'] ) : null;

		$landing_id   = isset( $raw['landing_page_id'] ) && is_numeric( $raw['landing_page_id'] ) ? (int) $raw['landing_page_id'] : 0;
		$landing_slug = isset( $raw['landing_slug'] ) && is_scalar( $raw['landing_slug'] ) ? sanitize_title( (string) $raw['landing_slug'] ) : '';

		ksort( $fields );
		$hash = md5(
			wp_json_encode(
				array( $title, $content, $excerpt, $city, $category, $amenities, $fields, $status, $landing_id, $landing_slug )
			)
		);

		if ( $post_id && (string) get_post_meta( $post_id, $hash_key, true ) === $hash ) {
			$res['action'] = 'skipped';
			$res['link']   = get_permalink( $post_id );
			return $res;
		}

		if ( $post_id ) {
			// Matched by place id or cid under another source: keep the original identity.
			unset( $fields['source'], $fields['source_id'] );
		}

		if ( $dry_run ) {
			$res['action'] = $post_id ? 'updated' : 'created';
			$res['link']   = $post_id ? get_permalink( $post_id ) : null;
			return $res;
		}

		$old_status = $post_id ? get_post_status( $post_id ) : '';
		$old_term   = $post_id ? Util::first_term( $post_id, Data_Model::CITY ) : null;

		$postarr = array(
			'post_type'   => Data_Model::POST_TYPE,
			'post_status' => $status,
		);
		if ( '' !== $title ) {
			$postarr['post_title'] = $title;
		}
		if ( null !== $content ) {
			$postarr['post_content'] = $content;
		}
		if ( null !== $excerpt ) {
			$postarr['post_excerpt'] = $excerpt;
		}
		if ( $post_id ) {
			$postarr['ID'] = $post_id;
		} else {
			$postarr['post_name'] = sanitize_title( $title . ' ' . $city );
		}
		// wp_update_post merges the stored title/content, so an update may omit them (per the contract).
		$saved = $post_id ? wp_update_post( wp_slash( $postarr ), true ) : wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $saved ) ) {
			$res['message'] = $saved->get_error_message();
			return $res;
		}
		$saved = (int) $saved;

		foreach ( $fields as $name => $value ) {
			$key = Fields::key( $name );
			if ( null === $value ) {
				delete_post_meta( $saved, $key );
			} else {
				update_post_meta( $saved, $key, wp_slash( $value ) );
			}
		}

		$terms_touched = array();
		$dirty         = ! $post_id || get_post_status( $saved ) !== $old_status;
		if ( $old_term ) {
			$terms_touched[] = (int) $old_term->term_id;
		}
		if ( $parsed ) {
			$term = self::ensure_city( $city, $parsed[1], $landing_id, $landing_slug, $warnings );
			if ( $term ) {
				wp_set_object_terms( $saved, array( $term->term_id ), Data_Model::CITY );
				$terms_touched[] = (int) $term->term_id;
				if ( ! $old_term || (int) $old_term->term_id !== (int) $term->term_id ) {
					$dirty = true;
				}
			}
		}
		if ( null !== $category ) {
			wp_set_object_terms( $saved, '' === $category ? array() : array( $category ), Data_Model::CATEGORY );
		}
		if ( null !== $amenities ) {
			$ids = array();
			foreach ( $amenities as $slug ) {
				$t = get_term_by( 'slug', $slug, Data_Model::AMENITY );
				if ( ! $t ) {
					$made = wp_insert_term( ucwords( str_replace( '-', ' ', $slug ) ), Data_Model::AMENITY, array( 'slug' => $slug ) );
					$ids[] = is_wp_error( $made ) ? 0 : (int) $made['term_id'];
				} else {
					$ids[] = (int) $t->term_id;
				}
			}
			wp_set_object_terms( $saved, array_filter( $ids ), Data_Model::AMENITY );
		}
		update_post_meta( $saved, $hash_key, $hash );
		self::sync_excerpt( $saved );
		Seo::sync_thin( $saved ); // Sitemap flag: noindexed thin venues stay out of wp-sitemap.

		$res['action']  = $post_id ? 'updated' : 'created';
		$res['id']      = $saved;
		$res['link']    = get_permalink( $saved );
		$res['_terms']  = array_values( array_unique( $terms_touched ) );
		$res['_dirty']  = $dirty;
		if ( $warnings ) {
			$res['message'] = implode( ' ', $warnings );
		}
		return $res;
	}

	/** Post ID for source+source_id, else google place id, else maps cid, across all statuses. */
	public static function find( $source, $source_id, $place_id = '', $cid = '' ) {
		$ids = self::query_meta(
			array(
				array( 'key' => Fields::key( 'source' ), 'value' => $source ),
				array( 'key' => Fields::key( 'source_id' ), 'value' => $source_id ),
			)
		);
		if ( ! $ids && '' !== $place_id ) {
			$ids = self::query_meta( array( array( 'key' => Fields::key( 'google_place_id' ), 'value' => $place_id ) ) );
		}
		if ( ! $ids && '' !== $cid ) {
			$ids = self::query_meta( array( array( 'key' => Fields::key( 'maps_cid' ), 'value' => $cid ) ) );
		}
		return $ids ? (int) min( $ids ) : 0;
	}

	private static function query_meta( $clauses ) {
		$q = new \WP_Query(
			array(
				'post_type'              => Data_Model::POST_TYPE,
				'post_status'            => array( 'publish', 'private', 'draft', 'pending', 'future', 'trash' ),
				'posts_per_page'         => 5,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => array_merge( array( 'relation' => 'AND' ), $clauses ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
		return array_map( 'intval', $q->posts );
	}

	/**
	 * Find or create the "City, ST" term; fill state and landing page meta.
	 * An explicit landing_page_id / landing_slug sets the link; otherwise a page whose slug matches
	 * the city slug (phoenix-az) is used when the term has none yet.
	 */
	public static function ensure_city( $city, $state, $landing_id = 0, $landing_slug = '', &$warnings = array() ) {
		$term = Util::find_city( $city );
		if ( ! $term ) {
			$made = wp_insert_term( $city, Data_Model::CITY, array( 'slug' => sanitize_title( $city ) ) );
			if ( is_wp_error( $made ) ) {
				$term = ( 'term_exists' === $made->get_error_code() && ! empty( $made->get_error_data() ) ) ? get_term( (int) $made->get_error_data(), Data_Model::CITY ) : null;
			} else {
				$term = get_term( (int) $made['term_id'], Data_Model::CITY );
			}
			if ( ! $term instanceof \WP_Term ) {
				return null;
			}
		}
		if ( '' === (string) get_term_meta( $term->term_id, 'osn_state', true ) ) {
			update_term_meta( $term->term_id, 'osn_state', $state );
		}

		$page = null;
		if ( $landing_id ) {
			$page = get_post( $landing_id );
		} elseif ( '' !== $landing_slug ) {
			$page = get_page_by_path( $landing_slug, OBJECT, 'page' );
		}
		if ( $landing_id || '' !== $landing_slug ) {
			if ( $page && 'page' === $page->post_type ) {
				if ( (int) get_term_meta( $term->term_id, 'osn_landing_page_id', true ) !== (int) $page->ID ) {
					update_term_meta( $term->term_id, 'osn_landing_page_id', (int) $page->ID );
				}
				return $term;
			}
			$warnings[] = 'landing page not found; used the slug guess instead.';
		}
		if ( ! (int) get_term_meta( $term->term_id, 'osn_landing_page_id', true ) ) {
			$guess = get_page_by_path( sanitize_title( str_replace( ',', '-', $city ) ), OBJECT, 'page' );
			if ( $guess && 'publish' === $guess->post_status ) {
				update_term_meta( $term->term_id, 'osn_landing_page_id', (int) $guess->ID );
			}
		}
		return $term;
	}

	/**
	 * Cache purge for cities whose venue list changed: drop the sorted-ID transient, fire
	 * osn_city_venues_changed( $term_id, $landing_page_id ) for page caches, and clear the landing page's post cache.
	 *
	 * @param int[] $term_ids City term IDs.
	 */
	public static function purge_cities( array $term_ids ) {
		foreach ( array_unique( array_map( 'intval', $term_ids ) ) as $term_id ) {
			if ( $term_id < 1 ) {
				continue;
			}
			Util::purge_sorted( $term_id );
			$landing = (int) get_term_meta( $term_id, 'osn_landing_page_id', true );
			do_action( 'osn_city_venues_changed', $term_id, $landing );
			if ( $landing ) {
				clean_post_cache( $landing );
			}
		}
	}

	/**
	 * Keep post_excerpt filled from the venue summary so SEO plugins (DoS Toolkit reads
	 * hand-written description -> excerpt -> body) get a real meta description. Only
	 * touches excerpts that are empty or that this method wrote (osn_excerpt_auto), never
	 * a hand-written one. Writes via $wpdb so post_modified isn't bumped.
	 */
	public static function sync_excerpt( $post_id ) {
		global $wpdb;
		$post = get_post( $post_id );
		if ( ! $post || Data_Model::POST_TYPE !== $post->post_type ) {
			return false;
		}
		$auto = get_post_meta( $post_id, '_osn_excerpt_auto', true );
		if ( '' !== $post->post_excerpt && ! $auto ) {
			return false;
		}
		$summary = trim( (string) Util::meta( $post_id, 'summary' ) );
		if ( '' === $summary ) {
			$term     = Util::first_term( $post_id, Data_Model::CITY );
			$category = Util::first_term( $post_id, Data_Model::CATEGORY );
			$summary  = sprintf(
				/* translators: 1: venue, 2: category, 3: City, ST */
				__( '%1$s, %2$s in %3$s. Hours, location, and outdoor seating details.', 'dos-outdoor-seating' ),
				get_the_title( $post_id ),
				$category ? strtolower( $category->name ) : __( 'restaurant', 'dos-outdoor-seating' ),
				$term ? $term->name : ''
			);
		}
		$summary = (string) apply_filters( 'osn_venue_excerpt', $summary, $post_id );
		if ( $summary === $post->post_excerpt ) {
			return false;
		}
		$wpdb->update( $wpdb->posts, array( 'post_excerpt' => $summary ), array( 'ID' => $post_id ) );
		update_post_meta( $post_id, '_osn_excerpt_auto', '1' );
		clean_post_cache( $post_id );
		return true;
	}
}
