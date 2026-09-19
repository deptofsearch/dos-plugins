<?php
/**
 * SEO module: descriptions, Open Graph, canonicals, schema.
 *
 * Ported from the standalone SAAB SEO plugin, v1.1.0.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SAAB_SEO {

	const DESC_KEY  = '_saab_seo_description';
	const IMAGE_KEY = '_saab_seo_image';
	const MAX_DESC  = 155;

	public static function init() {
		// If a full SEO plugin is ever installed, get out of its way entirely.
		if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'SEOPRESS_VERSION' ) ) {
			return;
		}

		// Core only emits a canonical on singular views. We handle every view instead.
		remove_action( 'wp_head', 'rel_canonical' );
		add_action( 'wp_head', array( __CLASS__, 'render_head' ), 1 );

		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save_meta_box' ), 10, 2 );

		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/* ---------------------------------------------------------------------
	 * Head output
	 * ------------------------------------------------------------------- */

	public static function render_head() {
		$ctx = self::context();

		echo "\n<!-- SAAB SEO -->\n";

		if ( $ctx['noindex'] ) {
			echo '<meta name="robots" content="noindex, follow">' . "\n";
		} else {
			echo '<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1">' . "\n";
		}

		if ( $ctx['description'] ) {
			printf( '<meta name="description" content="%s">' . "\n", esc_attr( $ctx['description'] ) );
		}

		if ( $ctx['canonical'] ) {
			printf( '<link rel="canonical" href="%s">' . "\n", esc_url( $ctx['canonical'] ) );
		}

		self::render_pagination_links();
		self::render_open_graph( $ctx );
		self::render_schema( $ctx );

		echo "<!-- /SAAB SEO -->\n\n";
	}

	private static function render_open_graph( $ctx ) {
		$tags = array(
			'og:type'       => $ctx['og_type'],
			'og:title'      => $ctx['title'],
			'og:description'=> $ctx['description'],
			'og:url'        => $ctx['canonical'],
			'og:site_name'  => get_bloginfo( 'name' ),
			'og:locale'     => str_replace( '-', '_', get_bloginfo( 'language' ) ),
		);

		foreach ( $tags as $property => $content ) {
			if ( $content ) {
				printf( '<meta property="%s" content="%s">' . "\n", esc_attr( $property ), esc_attr( $content ) );
			}
		}

		if ( $ctx['image'] ) {
			printf( '<meta property="og:image" content="%s">' . "\n", esc_url( $ctx['image']['url'] ) );
			if ( $ctx['image']['width'] ) {
				printf( '<meta property="og:image:width" content="%d">' . "\n", (int) $ctx['image']['width'] );
				printf( '<meta property="og:image:height" content="%d">' . "\n", (int) $ctx['image']['height'] );
			}
			if ( $ctx['image']['alt'] ) {
				printf( '<meta property="og:image:alt" content="%s">' . "\n", esc_attr( $ctx['image']['alt'] ) );
			}
		}

		if ( 'article' === $ctx['og_type'] && $ctx['post_id'] ) {
			printf(
				'<meta property="article:published_time" content="%s">' . "\n",
				esc_attr( get_the_date( DATE_W3C, $ctx['post_id'] ) )
			);
			printf(
				'<meta property="article:modified_time" content="%s">' . "\n",
				esc_attr( get_the_modified_date( DATE_W3C, $ctx['post_id'] ) )
			);
			$terms = get_the_category( $ctx['post_id'] );
			if ( $terms ) {
				printf( '<meta property="article:section" content="%s">' . "\n", esc_attr( $terms[0]->name ) );
			}
		}

		// Twitter reads the og:* tags for everything except the card type.
		$card = $ctx['image'] && $ctx['image']['width'] >= 600 ? 'summary_large_image' : 'summary';
		printf( '<meta name="twitter:card" content="%s">' . "\n", esc_attr( $card ) );

		$handle = trim( (string) get_option( 'saab_seo_twitter' ) );
		if ( $handle ) {
			printf( '<meta name="twitter:site" content="@%s">' . "\n", esc_attr( ltrim( $handle, '@' ) ) );
		}
	}

	private static function render_pagination_links() {
		if ( is_singular() ) {
			return;
		}
		global $wp_query;

		$paged = max( 1, (int) get_query_var( 'paged' ) );
		$max   = (int) $wp_query->max_num_pages;

		if ( $paged > 1 ) {
			printf( '<link rel="prev" href="%s">' . "\n", esc_url( get_pagenum_link( $paged - 1 ) ) );
		}
		if ( $paged < $max ) {
			printf( '<link rel="next" href="%s">' . "\n", esc_url( get_pagenum_link( $paged + 1 ) ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Context: what page is this, and what should it say about itself
	 * ------------------------------------------------------------------- */

	private static function context() {
		$ctx = array(
			'title'       => wp_get_document_title(),
			'description' => '',
			'canonical'   => '',
			'image'       => null,
			'og_type'     => 'website',
			'noindex'     => false,
			'post_id'     => 0,
		);

		// The front page must be tested before is_singular(): a static front
		// page is BOTH, and the singular branch would describe it from page
		// content that a page builder leaves empty.
		if ( is_front_page() ) {
			$front_id           = get_queried_object_id();
			$ctx['post_id']     = $front_id;
			$ctx['canonical']   = self::paged_url( home_url( '/' ) );
			$ctx['description'] = self::clean( get_option( 'saab_seo_home_description' ) )
				?: self::clean( get_bloginfo( 'description' ) );
			$ctx['image']       = $front_id ? self::image_for_post( $front_id ) : self::fallback_image();

		} elseif ( is_home() ) {
			$blog_id            = get_queried_object_id();
			$ctx['canonical']   = self::paged_url( get_permalink( $blog_id ) ?: home_url( '/' ) );
			$ctx['description'] = ( $blog_id ? self::clean( get_post_meta( $blog_id, self::DESC_KEY, true ) ) : '' )
				?: self::clean( get_bloginfo( 'description' ) );
			$ctx['image']       = self::fallback_image();

		} elseif ( is_singular() ) {
			$post_id            = get_queried_object_id();
			$ctx['post_id']     = $post_id;
			$ctx['og_type']     = is_singular( 'post' ) ? 'article' : 'website';
			$ctx['canonical']   = self::paged_url( get_permalink( $post_id ) );
			$ctx['description'] = self::describe_post( $post_id );
			$ctx['image']       = self::image_for_post( $post_id );

		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term               = get_queried_object();
			$ctx['canonical']   = self::paged_url( get_term_link( $term ) );
			$ctx['description'] = self::clean( $term->description )
				?: self::clean( sprintf( '%s — reviews, photos and picks from %s.', $term->name, get_bloginfo( 'name' ) ) );
			$ctx['image']       = self::fallback_image();

		} elseif ( is_author() ) {
			// Single-author site: the author archive is a duplicate of the blog.
			$ctx['noindex']   = true;
			$ctx['canonical'] = self::paged_url( get_author_posts_url( get_queried_object_id() ) );

		} elseif ( is_search() || is_404() ) {
			$ctx['noindex'] = true;

		} elseif ( is_archive() ) {
			$ctx['canonical']   = self::paged_url( self::current_url_base() );
			$ctx['description'] = self::clean( wp_strip_all_tags( get_the_archive_description() ) )
				?: self::clean( get_the_archive_title() . ' — ' . get_bloginfo( 'name' ) );
			$ctx['image']       = self::fallback_image();
		}

		if ( ! $ctx['image'] ) {
			$ctx['image'] = self::fallback_image();
		}

		// Never ship an empty description. Page-builder pages hold their text
		// in post meta, so stripping shortcodes out of post_content can leave
		// nothing at all — the title plus the tagline is the floor.
		if ( ! $ctx['description'] && ! $ctx['noindex'] ) {
			$ctx['description'] = self::floor_description( $ctx['title'] );
		}

		return $ctx;
	}

	private static function floor_description( $title ) {
		$title   = self::clean( preg_replace( '/\s*[|–—-]\s*' . preg_quote( get_bloginfo( 'name' ), '/' ) . '\s*$/u', '', (string) $title ) );
		$tagline = self::clean( get_bloginfo( 'description' ) );

		if ( $title && $tagline ) {
			return self::clean( $title . ' — ' . $tagline );
		}

		return $title ?: $tagline;
	}

	/**
	 * Hand-written description, then excerpt, then the opening of the post body.
	 */
	private static function describe_post( $post_id ) {
		$manual = get_post_meta( $post_id, self::DESC_KEY, true );
		if ( $manual ) {
			return self::clean( $manual );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}

		if ( $post->post_excerpt ) {
			return self::clean( $post->post_excerpt );
		}

		$content = strip_shortcodes( $post->post_content );
		$content = wp_strip_all_tags( $content );

		return self::clean( $content );
	}

	/**
	 * Social image override, then featured image, then the first image in the
	 * body, then the sitewide fallback.
	 */
	private static function image_for_post( $post_id ) {
		$override = (int) get_post_meta( $post_id, self::IMAGE_KEY, true );
		if ( $override ) {
			return self::image_from_attachment( $override );
		}

		$thumb_id = get_post_thumbnail_id( $post_id );
		if ( $thumb_id ) {
			return self::image_from_attachment( $thumb_id );
		}

		$post = get_post( $post_id );
		if ( $post && preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', $post->post_content, $m ) ) {
			$attachment_id = attachment_url_to_postid( $m[1] );
			if ( $attachment_id ) {
				return self::image_from_attachment( $attachment_id );
			}
			return array(
				'url'    => $m[1],
				'width'  => 0,
				'height' => 0,
				'alt'    => get_the_title( $post_id ),
			);
		}

		return self::fallback_image();
	}

	private static function image_from_attachment( $attachment_id ) {
		// "full" is the right choice here: uploads are 1024px+ and Facebook
		// wants at least 1200px on the long edge for a large card.
		$src = wp_get_attachment_image_src( $attachment_id, 'full' );
		if ( ! $src ) {
			return null;
		}

		return array(
			'url'    => $src[0],
			'width'  => $src[1],
			'height' => $src[2],
			'alt'    => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
		);
	}

	private static function fallback_image() {
		$id = (int) get_option( 'saab_seo_fallback_image' );
		return $id ? self::image_from_attachment( $id ) : null;
	}

	/* ---------------------------------------------------------------------
	 * Schema
	 * ------------------------------------------------------------------- */

	private static function render_schema( $ctx ) {
		$site_url = home_url( '/' );
		$org_id   = $site_url . '#organization';
		$site_id  = $site_url . '#website';

		$organization = array(
			'@type' => 'Organization',
			'@id'   => $org_id,
			'name'  => get_bloginfo( 'name' ),
			'url'   => $site_url,
		);

		$logo = self::fallback_image();
		if ( $logo ) {
			$organization['logo'] = array(
				'@type'  => 'ImageObject',
				'url'    => $logo['url'],
				'width'  => (int) $logo['width'],
				'height' => (int) $logo['height'],
			);
		}

		$graph = array(
			$organization,
			array(
				'@type'     => 'WebSite',
				'@id'       => $site_id,
				'url'       => $site_url,
				'name'      => get_bloginfo( 'name' ),
				'publisher' => array( '@id' => $org_id ),
			),
		);

		if ( 'article' === $ctx['og_type'] && $ctx['post_id'] ) {
			$post_id = $ctx['post_id'];

			$article = array(
				'@type'            => 'BlogPosting',
				'@id'              => $ctx['canonical'] . '#article',
				'headline'         => wp_strip_all_tags( get_the_title( $post_id ) ),
				'description'      => $ctx['description'],
				'datePublished'    => get_the_date( DATE_W3C, $post_id ),
				'dateModified'     => get_the_modified_date( DATE_W3C, $post_id ),
				'mainEntityOfPage' => array( '@type' => 'WebPage', '@id' => $ctx['canonical'] ),
				'publisher'        => array( '@id' => $org_id ),
				'isPartOf'         => array( '@id' => $site_id ),
				'author'           => array(
					'@type' => 'Person',
					'name'  => get_the_author_meta( 'display_name', (int) get_post_field( 'post_author', $post_id ) ),
					'url'   => get_author_posts_url( (int) get_post_field( 'post_author', $post_id ) ),
				),
			);

			if ( $ctx['image'] ) {
				$article['image'] = array(
					'@type'  => 'ImageObject',
					'url'    => $ctx['image']['url'],
					'width'  => (int) $ctx['image']['width'],
					'height' => (int) $ctx['image']['height'],
				);
			}

			$graph[] = $article;
		}

		printf(
			'<script type="application/ld+json">%s</script>' . "\n",
			wp_json_encode(
				array(
					'@context' => 'https://schema.org',
					'@graph'   => $graph,
				),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Collapse entities and whitespace, then cut on a word boundary.
	 */
	private static function clean( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );
		$text = trim( $text );

		if ( '' === $text ) {
			return '';
		}

		if ( mb_strlen( $text ) <= self::MAX_DESC ) {
			return $text;
		}

		$cut   = mb_substr( $text, 0, self::MAX_DESC );
		$space = mb_strrpos( $cut, ' ' );

		return rtrim( $space ? mb_substr( $cut, 0, $space ) : $cut, ' ,.;:' ) . '…';
	}

	/**
	 * Page 2 of an archive canonicalises to itself, not to page 1 — pointing
	 * every page at page 1 hides the deeper posts from crawlers.
	 */
	private static function paged_url( $base ) {
		if ( is_wp_error( $base ) || ! $base ) {
			return '';
		}

		$paged = (int) get_query_var( 'paged' );
		if ( $paged > 1 ) {
			return get_pagenum_link( $paged );
		}

		$page = (int) get_query_var( 'page' );
		if ( $page > 1 && is_singular() ) {
			return trailingslashit( $base ) . user_trailingslashit( $page, 'single_paged' );
		}

		return $base;
	}

	private static function current_url_base() {
		global $wp;
		return home_url( add_query_arg( array(), $wp->request ) );
	}

	/* ---------------------------------------------------------------------
	 * Editor UI
	 * ------------------------------------------------------------------- */

	public static function add_meta_box() {
		foreach ( array( 'post', 'page' ) as $screen ) {
			add_meta_box(
				'saab-seo',
				'Search &amp; Social',
				array( __CLASS__, 'render_meta_box' ),
				$screen,
				'normal',
				'high'
			);
		}
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( 'saab_seo_save', 'saab_seo_nonce' );

		$description = get_post_meta( $post->ID, self::DESC_KEY, true );
		$image_id    = (int) get_post_meta( $post->ID, self::IMAGE_KEY, true );
		$auto        = self::describe_post( $post->ID );
		?>
		<p>
			<label for="saab_seo_description"><strong>Meta description</strong></label><br>
			<textarea id="saab_seo_description" name="saab_seo_description" rows="3" style="width:100%"
				maxlength="200"><?php echo esc_textarea( $description ); ?></textarea>
			<span class="description">
				Aim for 120–155 characters. Lead with the dish and the restaurant.
				Leave blank and this is used:<br>
				<em><?php echo esc_html( $auto ); ?></em>
			</span>
		</p>
		<p>
			<label for="saab_seo_image"><strong>Social share image</strong></label><br>
			<input type="number" id="saab_seo_image" name="saab_seo_image" value="<?php echo $image_id ?: ''; ?>"
				style="width:120px" placeholder="Media ID">
			<span class="description">
				Optional. Leave blank to use the featured image, then the first photo in the post.
			</span>
		</p>
		<?php
	}

	public static function save_meta_box( $post_id, $post ) {
		if ( ! isset( $_POST['saab_seo_nonce'] ) || ! wp_verify_nonce( $_POST['saab_seo_nonce'], 'saab_seo_save' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$description = isset( $_POST['saab_seo_description'] )
			? sanitize_text_field( wp_unslash( $_POST['saab_seo_description'] ) )
			: '';

		if ( $description ) {
			update_post_meta( $post_id, self::DESC_KEY, $description );
		} else {
			delete_post_meta( $post_id, self::DESC_KEY );
		}

		$image_id = isset( $_POST['saab_seo_image'] ) ? (int) $_POST['saab_seo_image'] : 0;
		if ( $image_id ) {
			update_post_meta( $post_id, self::IMAGE_KEY, $image_id );
		} else {
			delete_post_meta( $post_id, self::IMAGE_KEY );
		}
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------- */

	public static function register_settings() {
		register_setting( 'saab_seo', 'saab_seo_home_description', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'saab_seo', 'saab_seo_fallback_image', array( 'sanitize_callback' => 'absint' ) );
		register_setting( 'saab_seo', 'saab_seo_twitter', array( 'sanitize_callback' => 'sanitize_text_field' ) );
	}

	public static function render_settings_fields() {
		?>
		<tr>
			<th scope="row"><label for="saab_seo_home_description">Homepage description</label></th>
			<td>
				<textarea id="saab_seo_home_description" name="saab_seo_home_description" rows="3"
					class="large-text"><?php echo esc_textarea( get_option( 'saab_seo_home_description' ) ); ?></textarea>
				<p class="description">Falls back to the site tagline. This is the snippet Google shows for the homepage.</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="saab_seo_fallback_image">Fallback share image</label></th>
			<td>
				<input type="number" id="saab_seo_fallback_image" name="saab_seo_fallback_image"
					value="<?php echo (int) get_option( 'saab_seo_fallback_image' ) ?: ''; ?>" class="small-text">
				<p class="description">
					Media library ID, 1200&times;630 or larger. Used on archives and on posts with no photo,
					and becomes the schema publisher logo.
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="saab_seo_twitter">X / Twitter handle</label></th>
			<td>
				<input type="text" id="saab_seo_twitter" name="saab_seo_twitter"
					value="<?php echo esc_attr( get_option( 'saab_seo_twitter' ) ); ?>" class="regular-text"
					placeholder="shotandabeer">
			</td>
		</tr>
		<?php
	}
}

