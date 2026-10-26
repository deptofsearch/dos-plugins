<?php
/**
 * The "Best in Tri-Cities" hub (/best-in-tri-cities/): an H1, an intro and
 * one tile per "Best X in Tri-Cities" page, drawn by the plugin instead of
 * the Themify Builder grid of hand-placed PNG tiles (2 of 20 linked, 10 MB).
 *
 * The tiles build themselves: every published page whose slug is
 * best-…-tri-cities, or that has a Best-Of config, gets one, linked, with
 * its hero or featured image at tile size. A new Best-Of page shows up on
 * the hub without touching it.
 *
 * Opt-in, like the Best-Of pages: the hub page's "Best in Tri-Cities Hub"
 * box has a switch. Off (or no saved settings) leaves the Builder page as
 * it was; its content is never changed.
 *
 * Tile size is one variable in bestof.css (--saab-hub-tile), so it can be
 * tuned without touching this file.
 *
 * Loaded and started by SAAB_Restaurants::init(), after SAAB_Bestof.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'SAAB_Bestof_Hub', false ) ) {
	return;
}

class SAAB_Bestof_Hub {

	const META_KEY = 'saab_bestof_hub';

	/** Tiles shown before the rest load lazily (the first row or two). */
	const EAGER_TILES = 4;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_meta' ), 11 );
		add_filter( 'template_include', array( __CLASS__, 'template_include' ), 99 );
		add_filter( 'body_class', array( __CLASS__, 'filter_body_class' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'add_meta_boxes_page', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_page', array( __CLASS__, 'save_meta_box' ) );
	}

	/* ---------------------------------------------------------------------
	 * Config
	 * ------------------------------------------------------------------- */

	public static function register_meta() {
		register_post_meta( 'page', self::META_KEY, array(
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => array( __CLASS__, 'sanitize_meta' ),
			'auth_callback'     => function () {
				return current_user_can( 'edit_pages' );
			},
		) );
	}

	/**
	 * Stored shape:
	 * {
	 *   "on":    true,                 // false: the page renders as before
	 *   "intro": "...",                // paragraphs split on blank lines
	 *   "hide":  [123, 456]            // page IDs left off the hub
	 * }
	 */
	public static function sanitize_meta( $value ) {
		$decoded = json_decode( (string) $value, true );
		if ( ! is_array( $decoded ) ) {
			return '';
		}
		return wp_json_encode( self::sanitize_config( $decoded ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	public static function sanitize_config( $c ) {
		$hide = array();
		foreach ( (array) ( isset( $c['hide'] ) ? $c['hide'] : array() ) as $id ) {
			if ( absint( $id ) ) {
				$hide[] = absint( $id );
			}
		}
		return array(
			'on'    => ! empty( $c['on'] ),
			'intro' => trim( wp_strip_all_tags( (string) ( isset( $c['intro'] ) ? $c['intro'] : '' ) ) ),
			'hide'  => array_values( array_unique( $hide ) ),
		);
	}

	public static function get_config( $post_id ) {
		$raw     = get_post_meta( $post_id, self::META_KEY, true );
		$decoded = $raw ? json_decode( $raw, true ) : null;
		return is_array( $decoded ) ? self::sanitize_config( $decoded ) : null;
	}

	/** True for the page at SAAB_Bestof::INDEX_PATH. */
	public static function is_hub_page( $post_id ) {
		$hub = get_page_by_path( trim( SAAB_Bestof::INDEX_PATH, '/' ) );
		return $hub && (int) $hub->ID === (int) $post_id;
	}

	public static function is_hub() {
		if ( ! is_page() ) {
			return false;
		}
		$id     = get_queried_object_id();
		$config = self::get_config( $id );
		return $config && $config['on'] && self::is_hub_page( $id );
	}

	/* ---------------------------------------------------------------------
	 * Template, body class, assets
	 * ------------------------------------------------------------------- */

	public static function template_include( $template ) {
		if ( ! self::is_hub() ) {
			return $template;
		}
		$ours = SAAB_TOOLKIT_DIR . 'templates/bestof-hub.php';
		return file_exists( $ours ) ? $ours : $template;
	}

	/** Same full-width, no-sidebar body as the Best-Of pages. */
	public static function filter_body_class( $classes ) {
		if ( ! self::is_hub() ) {
			return $classes;
		}
		$classes = array_filter( $classes, function ( $class ) {
			return ! preg_match( '/^sidebar(1|2)/', $class ) && 'sidebar-left' !== $class;
		} );
		$classes[] = 'sidebar-none';
		$classes[] = 'saab-bestof-page';
		return $classes;
	}

	public static function enqueue_assets() {
		if ( ! self::is_hub() ) {
			return;
		}
		$css = SAAB_TOOLKIT_DIR . 'assets/bestof.css';
		wp_enqueue_style( 'saab-bestof', SAAB_TOOLKIT_URL . 'assets/bestof.css', array(), file_exists( $css ) ? (string) filemtime( $css ) : SAAB_TOOLKIT_VERSION );
	}

	/* ---------------------------------------------------------------------
	 * Tiles
	 * ------------------------------------------------------------------- */

	/** "best-tacos-in-tri-cities", "best-date-night-spot-in-the-tri-cities". */
	public static function is_bestof_slug( $slug ) {
		return (bool) preg_match( '/^best-.+-tri-cities$/', (string) $slug );
	}

	/** "Best Biscuits & Gravy in the Tri-Cities" → "Biscuits & Gravy": the hub's H1 already says the rest. */
	public static function tile_label( $title ) {
		$label = preg_replace( '/\s+in\s+(?:the\s+)?Tri[\s-]Cities\b.*$/iu', '', trim( (string) $title ) );
		$label = trim( preg_replace( '/^(?:the\s+)?best\b\s*/iu', '', $label ) );
		return '' !== $label ? $label : trim( (string) $title );
	}

	/** Every published page the hub should link, hidden ones included (the meta box lists those too). */
	public static function candidate_pages( $hub_id ) {
		$pages = get_posts( array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
			'no_found_rows'  => true,
		) );
		$out = array();
		foreach ( $pages as $page ) {
			if ( (int) $page->ID === (int) $hub_id ) {
				continue;
			}
			if ( self::is_bestof_slug( $page->post_name ) || null !== SAAB_Bestof::get_config( $page->ID ) ) {
				$out[] = $page;
			}
		}
		return $out;
	}

	/** One tile per page not hidden: label, URL, and the image the page itself leads with. */
	public static function tiles( $hub_id, $config ) {
		$tiles = array();
		foreach ( self::candidate_pages( $hub_id ) as $page ) {
			if ( in_array( (int) $page->ID, $config['hide'], true ) ) {
				continue;
			}
			$bestof   = SAAB_Bestof::get_config( $page->ID );
			$image_id = $bestof && $bestof['hero_id'] ? $bestof['hero_id'] : (int) get_post_thumbnail_id( $page->ID );
			$tiles[]  = array(
				'label'    => self::tile_label( $page->post_title ),
				'title'    => $page->post_title,
				'url'      => get_permalink( $page ),
				'image_id' => $image_id && wp_attachment_is_image( $image_id ) ? $image_id : 0,
				'eyebrow'  => $bestof ? $bestof['eyebrow'] : '',
			);
		}
		return $tiles;
	}

	/* ---------------------------------------------------------------------
	 * Page
	 * ------------------------------------------------------------------- */

	public static function render() {
		$page_id = get_queried_object_id();
		$config  = self::get_config( $page_id );
		if ( ! $config ) {
			return '';
		}
		$tiles = self::tiles( $page_id, $config );
		$paras = array_filter( array_map( 'trim', preg_split( '/\R\s*\R/', $config['intro'] ) ) );

		ob_start();
		?>
		<div class="saab-bestof saab-bestof--hub">
			<header class="saab-bestof__header">
				<h1 class="saab-bestof__title"><?php echo esc_html( get_the_title( $page_id ) ); ?></h1>
				<?php foreach ( $paras as $i => $p ) : ?>
					<p class="<?php echo 0 === $i ? 'saab-bestof__lede' : 'saab-bestof__sub'; ?>"><?php echo esc_html( $p ); ?></p>
				<?php endforeach; ?>
			</header>

			<?php if ( $tiles ) : ?>
				<nav class="saab-hub" aria-label="Best in Tri-Cities lists">
					<ul class="saab-hub__grid">
						<?php foreach ( $tiles as $i => $t ) : ?>
							<li>
								<a class="saab-hub__tile<?php echo $t['image_id'] ? ' saab-hub__tile--photo' : ''; ?>" href="<?php echo esc_url( $t['url'] ); ?>">
									<?php
									if ( $t['image_id'] ) {
										// Decorative: the label is the link text.
										echo wp_get_attachment_image( $t['image_id'], 'medium_large', false, array(
											'class'   => 'saab-hub__img',
											'alt'     => '',
											'sizes'   => '(max-width: 600px) 50vw, 300px',
											'loading' => $i < self::EAGER_TILES ? 'eager' : 'lazy',
										) );
									}
									?>
									<span class="saab-hub__text">
										<?php if ( $t['eyebrow'] ) : ?>
											<span class="saab-hub__eyebrow"><?php echo esc_html( $t['eyebrow'] ); ?></span>
										<?php endif; ?>
										<span class="saab-hub__label"><?php echo esc_html( $t['label'] ); ?></span>
									</span>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</nav>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * Admin: on/off, intro, and which pages show. Only on the hub page.
	 * ------------------------------------------------------------------- */

	public static function add_meta_box( $post ) {
		if ( ! self::is_hub_page( $post->ID ) ) {
			return;
		}
		add_meta_box( 'saab-bestof-hub', 'Best in Tri-Cities Hub', array( __CLASS__, 'render_meta_box' ), 'page', 'normal', 'high' );
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( 'saab_bestof_hub_save', 'saab_bestof_hub_nonce' );
		$config = self::get_config( $post->ID );
		if ( ! $config ) {
			// First visit: offer the page's current wording as the intro, to edit rather than retype.
			$text   = trim( preg_replace( '/[ \t]+/', ' ', wp_strip_all_tags( strip_shortcodes( $post->post_content ) ) ) );
			$config = array( 'on' => false, 'intro' => wp_trim_words( $text, 220, '' ), 'hide' => array() );
		}
		?>
		<p><label><input type="checkbox" name="saab_bestof_hub[on]" value="1" <?php checked( $config['on'] ); ?>> <strong>Use the plugin's hub layout</strong></label></p>
		<p class="description">On: this page shows its title as the H1, the intro below, and a linked tile for every "Best … in Tri-Cities" page. Off: the Builder page shows exactly as before (it is never changed).</p>
		<p><label for="saab-bestof-hub-intro"><strong>Intro</strong> (blank line between paragraphs)</label></p>
		<textarea id="saab-bestof-hub-intro" name="saab_bestof_hub[intro]" rows="6" style="width:100%"><?php echo esc_textarea( $config['intro'] ); ?></textarea>
		<p><strong>Tiles</strong> (published pages named best-…-tri-cities or with a Best-Of layout; untick to leave one off)</p>
		<ul style="columns:2;margin:0">
			<?php foreach ( self::candidate_pages( $post->ID ) as $page ) : ?>
				<li><label><input type="checkbox" name="saab_bestof_hub[show][]" value="<?php echo (int) $page->ID; ?>" <?php checked( ! in_array( (int) $page->ID, $config['hide'], true ) ); ?>> <?php echo esc_html( $page->post_title ); ?></label></li>
			<?php endforeach; ?>
		</ul>
		<input type="hidden" name="saab_bestof_hub[present]" value="1">
		<?php
	}

	public static function save_meta_box( $post_id ) {
		if ( ! isset( $_POST['saab_bestof_hub_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['saab_bestof_hub_nonce'] ), 'saab_bestof_hub_save' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_page', $post_id ) ) {
			return;
		}
		$in   = isset( $_POST['saab_bestof_hub'] ) && is_array( $_POST['saab_bestof_hub'] ) ? wp_unslash( $_POST['saab_bestof_hub'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in sanitize_config().
		$show = array_map( 'absint', isset( $in['show'] ) ? (array) $in['show'] : array() );
		$hide = array();
		foreach ( self::candidate_pages( $post_id ) as $page ) {
			if ( ! in_array( (int) $page->ID, $show, true ) ) {
				$hide[] = (int) $page->ID;
			}
		}
		$config = array(
			'on'    => ! empty( $in['on'] ),
			'intro' => isset( $in['intro'] ) ? (string) $in['intro'] : '',
			'hide'  => $hide,
		);
		// update_post_meta() runs sanitize_meta(), which re-encodes cleanly.
		update_post_meta( $post_id, self::META_KEY, wp_slash( wp_json_encode( $config ) ) );
	}
}
