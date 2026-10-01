<?php
/**
 * Tools → City Search dashboard: index status, rebuild, settings (with color pickers), live preview,
 * and the one-time handoff from the Open Houses In plugin this one replaces.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOS_CITY_SEARCH_PAGE', 'dos-city-search' );
define( 'DOS_CITY_SEARCH_HANDOFF_NOTICE', 'dos_city_search_handoff_notice' );

add_action(
	'admin_menu',
	function () {
		add_management_page( 'DoS - City Search', 'City Search', 'manage_options', DOS_CITY_SEARCH_PAGE, 'dos_city_search_render_page' );
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( dirname( __DIR__ ) . '/dos-city-search.php' ),
	function ( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'tools.php?page=' . DOS_CITY_SEARCH_PAGE ) ) . '">Dashboard</a>' );
		return $links;
	}
);

add_action(
	'admin_init',
	function () {
		register_setting(
			'dos_city_search',
			DOS_CITY_SEARCH_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => 'dos_city_search_sanitize',
			)
		);
	}
);

// Before the settings are registered (10) and before any screen renders, so the page that
// deactivates the old plugin already shows the migrated settings.
add_action( 'admin_init', 'dos_city_search_handoff', 5 );
add_action( 'admin_notices', 'dos_city_search_handoff_notice' );

/**
 * Replaces the Open Houses In City Search plugin: carry its settings over, then switch it off. Its
 * options and files stay, so deactivating this plugin and reactivating that one is a full rollback.
 */
function dos_city_search_handoff() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( ! is_plugin_active( DOS_CITY_SEARCH_OLD_PLUGIN ) ) {
		return;
	}

	dos_city_search_maybe_migrate();
	deactivate_plugins( DOS_CITY_SEARCH_OLD_PLUGIN );
	set_transient( DOS_CITY_SEARCH_HANDOFF_NOTICE, 1, DAY_IN_SECONDS );
}

function dos_city_search_handoff_notice() {
	if ( ! current_user_can( 'activate_plugins' ) || ! get_transient( DOS_CITY_SEARCH_HANDOFF_NOTICE ) ) {
		return;
	}
	delete_transient( DOS_CITY_SEARCH_HANDOFF_NOTICE );
	echo '<div class="notice notice-success is-dismissible"><p>DoS - City Search replaced DoS Open Houses In City Search; its settings were carried over. You can delete the old plugin.</p></div>';
}

/**
 * "a  b a!" -> "a b": a list of CSS class names, each once, with anything that isn't a class-name character dropped.
 */
function dos_city_search_sanitize_classes( $s ) {
	$out = array();
	foreach ( preg_split( '/\s+/', (string) $s, -1, PREG_SPLIT_NO_EMPTY ) as $tok ) {
		$tok = preg_replace( '/[^A-Za-z0-9_-]/', '', $tok );
		if ( '' !== $tok ) {
			$out[] = $tok;
		}
	}
	return implode( ' ', array_unique( $out ) );
}

function dos_city_search_sanitize( $in ) {
	$d   = dos_city_search_defaults();
	$in  = (array) $in;
	$old = (array) get_option( DOS_CITY_SEARCH_OPTION, array() );

	$out = array(
		'schema'            => 2,
		'auto_insert'       => empty( $in['auto_insert'] ) ? 0 : 1,
		'show_all_on_focus' => empty( $in['show_all_on_focus'] ) ? 0 : 1,
	);

	foreach ( array( 'heading', 'placeholder', 'button_label' ) as $k ) {
		$v         = isset( $in[ $k ] ) ? sanitize_text_field( $in[ $k ] ) : '';
		$out[ $k ] = '' === $v ? $d[ $k ] : $v;
	}
	$out['intro'] = isset( $in['intro'] ) ? sanitize_textarea_field( $in['intro'] ) : $d['intro'];

	foreach ( array( 'bg_color', 'accent_color' ) as $k ) {
		$c         = isset( $in[ $k ] ) ? sanitize_hex_color( $in[ $k ] ) : '';
		$out[ $k ] = $c ? $c : $d[ $k ];
	}

	$out['source']       = isset( $in['source'] ) && in_array( $in['source'], array( 'auto', 'slug_state', 'taxonomy' ), true ) ? $in['source'] : $d['source'];
	$out['insert_after'] = isset( $in['insert_after'] ) && in_array( $in['insert_after'], array( 'auto', 'hero_class' ), true ) ? $in['insert_after'] : $d['insert_after'];

	// A taxonomy key and a class name are both a short run of [a-z0-9_-].
	$tax             = isset( $in['taxonomy'] ) ? substr( preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $in['taxonomy'] ) ), 0, 32 ) : '';
	$out['taxonomy'] = '' === $tax ? $d['taxonomy'] : $tax;

	$hero              = isset( $in['hero_class'] ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $in['hero_class'] ) : '';
	$out['hero_class'] = '' === $hero ? $d['hero_class'] : $hero;

	// Empty is meaningful here: no browse link.
	$out['browse_slug'] = isset( $in['browse_slug'] ) ? trim( preg_replace( '/[^a-z0-9\/_-]/', '', strtolower( (string) $in['browse_slug'] ) ), '/' ) : $d['browse_slug'];

	foreach ( array( 'section_class', 'form_class', 'button_class' ) as $k ) {
		$out[ $k ] = isset( $in[ $k ] ) ? dos_city_search_sanitize_classes( $in[ $k ] ) : '';
	}

	// 0 means no cap.
	$out['max_results'] = isset( $in['max_results'] ) ? min( 500, absint( $in['max_results'] ) ) : $d['max_results'];

	// Set by the migration, not by the form: keep it across saves.
	if ( ! empty( $old['migrated_from'] ) ) {
		$out['migrated_from'] = sanitize_text_field( $old['migrated_from'] );
	}

	return $out;
}

add_action(
	'admin_post_dos_city_search_rebuild',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'dos_city_search_rebuild' );
		dos_city_search_flush();
		dos_city_search_get_index();
		wp_safe_redirect( admin_url( 'tools.php?page=' . DOS_CITY_SEARCH_PAGE . '&rebuilt=1' ) );
		exit;
	}
);

add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		if ( 'tools_page_' . DOS_CITY_SEARCH_PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_add_inline_script(
			'wp-color-picker',
			"jQuery(function($){\n" .
			"  function paint(input, c){ $('#dos-cs-preview .dos-city-search').css($(input).data('var'), c || $(input).data('default-color')); }\n" .
			"  $('.dos-cs-color').each(function(){ var el = this; $(el).wpColorPicker({\n" .
			"    change: function(e, ui){ paint(el, ui.color.toString()); },\n" .
			"    clear: function(){ paint(el, ''); }\n" .
			"  }); });\n" .
			"});"
		);
		wp_register_style( 'dos-city-search-admin', false, array(), DOS_CITY_SEARCH_VERSION );
		wp_enqueue_style( 'dos-city-search-admin' );
		wp_add_inline_style(
			'dos-city-search-admin',
			'.dos-cs-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin:16px 0 24px;max-width:960px}'
			. '.dos-cs-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px 16px}'
			. '.dos-cs-card b{display:block;font-size:24px;line-height:1.2;margin-bottom:2px}'
			. '.dos-cs-card b.dos-cs-small{font-size:15px;line-height:1.4}'
			. '.dos-cs-card span{color:#646970}'
			. '.dos-cs-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:24px;max-width:1200px;align-items:start}'
			. '@media (max-width:1100px){.dos-cs-grid{grid-template-columns:1fr}}'
			. '.dos-cs-box{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:4px 20px 16px}'
			. '.dos-cs-box .form-table th{width:150px}'
			. '#dos-cs-preview{padding:4px 0}'
			// The site's own stylesheet isn't loaded here, so the button needs a look of its own in the preview.
			. '#dos-cs-preview .dos-cs-btn{padding:12px 22px;background:var(--dos-cs-accent);color:#fff;border:0;border-radius:6px;font:inherit;font-weight:600;cursor:pointer}'
			. '#dos-cs-preview h2{font-size:1.4em}'
			. '.dos-cs-states{columns:4 110px;margin:8px 0 0}'
			. '.dos-cs-states li{margin:0 0 4px}'
			. '.dos-cs-code{font-family:monospace;background:#f0f0f1;padding:2px 6px;border-radius:3px}'
		);
	}
);

function dos_city_search_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$index  = dos_city_search_get_index();
	$meta   = wp_parse_args( (array) get_option( DOS_CITY_SEARCH_META, array() ), array( 'built' => 0, 'fallbacks' => array(), 'fallback_n' => 0, 'skipped' => array() ) );
	$o      = dos_city_search_settings();
	$d      = dos_city_search_defaults();
	$name   = DOS_CITY_SEARCH_OPTION;
	$source = dos_city_search_resolve_source();

	$by_state = array();
	if ( 'slug_state' === $source ) {
		foreach ( $index as $row ) {
			$st              = strtoupper( substr( $row['s'], -2 ) );
			$by_state[ $st ] = isset( $by_state[ $st ] ) ? $by_state[ $st ] + 1 : 1;
		}
		ksort( $by_state );
	}

	$front_id = (int) get_option( 'page_on_front' );
	$built    = $meta['built'] ? human_time_diff( $meta['built'] ) . ' ago' : 'Not yet';
	$source_s = 'taxonomy' === $source ? 'Pages tagged with a City (' . $o['taxonomy'] . ')' : 'Pages whose URL ends in a state code';
	if ( 'auto' === $o['source'] ) {
		$source_s .= ', chosen automatically';
	}
	// Site CSS written for REVNM's classes is scoped under this wrapper, so the preview needs it to match.
	$preview_class = false !== strpos( $o['section_class'], 'rv-' ) ? 'revnm-home' : '';
	?>
<div class="wrap">
<h1>DoS - City Search</h1>
<p>The city search box on the homepage. It suggests every <strong>published</strong> city page and goes straight to the one a visitor picks.</p>

	<?php if ( 'taxonomy' === $o['source'] && ! taxonomy_exists( $o['taxonomy'] ) ) : ?>
<div class="notice notice-error"><p>The City taxonomy (<span class="dos-cs-code"><?php echo esc_html( $o['taxonomy'] ); ?></span>) isn’t registered, so the list is empty. Activate the plugin that registers it, or change the list source below.</p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['rebuilt'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
<div class="notice notice-success is-dismissible"><p>City list rebuilt: <?php echo esc_html( number_format_i18n( count( $index ) ) ); ?> cities.</p></div>
	<?php endif; ?>
	<?php settings_errors(); ?>

<div class="dos-cs-cards">
<div class="dos-cs-card"><b><?php echo esc_html( number_format_i18n( count( $index ) ) ); ?></b><span>cities searchable</span></div>
	<?php if ( 'slug_state' === $source ) : ?>
<div class="dos-cs-card"><b><?php echo esc_html( count( $by_state ) ); ?></b><span>states covered</span></div>
	<?php endif; ?>
<div class="dos-cs-card"><b><?php echo esc_html( $built ); ?></b><span>list last built</span></div>
<div class="dos-cs-card"><b><?php echo $o['auto_insert'] ? 'On' : 'Off'; ?></b><span>shown on homepage</span></div>
<div class="dos-cs-card"><b class="dos-cs-small"><?php echo esc_html( $source_s ); ?></b><span>where the list comes from</span></div>
	<?php if ( ! empty( $o['migrated_from'] ) ) : ?>
<div class="dos-cs-card"><b class="dos-cs-small"><?php echo esc_html( $o['migrated_from'] ); ?></b><span>settings carried over from</span></div>
	<?php endif; ?>
</div>

<div class="dos-cs-grid">
<div>
<div class="dos-cs-box">
<h2>Settings</h2>
<form method="post" action="options.php">
	<?php settings_fields( 'dos_city_search' ); ?>
<table class="form-table" role="presentation">
<tr><th scope="row">Homepage</th><td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[auto_insert]" value="1" <?php checked( $o['auto_insert'] ); ?>> Show on the homepage automatically</label>
<p class="description">Turn off to place it yourself with the <span class="dos-cs-code">[dos_city_search]</span> shortcode.</p></td></tr>
<tr><th scope="row"><label for="dos-cs-insert">Where on the homepage</label></th><td><select id="dos-cs-insert" name="<?php echo esc_attr( $name ); ?>[insert_after]">
<option value="auto" <?php selected( $o['insert_after'], 'auto' ); ?>>Automatic: after the opening section, or before the first heading</option>
<option value="hero_class" <?php selected( $o['insert_after'], 'hero_class' ); ?>>Right after the section with the class below</option>
</select>
<p class="description">Automatic goes after the opening section, or before the first H2 when there isn’t one, or after the first paragraph.</p></td></tr>
<tr><th scope="row"><label for="dos-cs-hero">Section class to follow</label></th><td><input type="text" id="dos-cs-hero" class="regular-text" name="<?php echo esc_attr( $name ); ?>[hero_class]" value="<?php echo esc_attr( $o['hero_class'] ); ?>">
<p class="description">Only used by the second option above. If the homepage has no section with this class, nothing is shown.</p></td></tr>
<tr><th scope="row"><label for="dos-cs-source">City list comes from</label></th><td><select id="dos-cs-source" name="<?php echo esc_attr( $name ); ?>[source]">
<option value="auto" <?php selected( $o['source'], 'auto' ); ?>>Detect automatically</option>
<option value="slug_state" <?php selected( $o['source'], 'slug_state' ); ?>>Pages whose URL ends in a state code (like kennewick-wa)</option>
<option value="taxonomy" <?php selected( $o['source'], 'taxonomy' ); ?>>Pages tagged with a City</option>
</select>
<p class="description">Detect automatically uses the City tags when the site has them, and the URLs otherwise.</p></td></tr>
<tr><th scope="row"><label for="dos-cs-tax">City tag name</label></th><td><input type="text" id="dos-cs-tax" class="regular-text" name="<?php echo esc_attr( $name ); ?>[taxonomy]" value="<?php echo esc_attr( $o['taxonomy'] ); ?>">
<p class="description">The taxonomy the city pages are tagged with. Only used when the list comes from City tags.</p></td></tr>
<tr><th scope="row"><label for="dos-cs-browse">“See every city” page</label></th><td><input type="text" id="dos-cs-browse" class="regular-text" name="<?php echo esc_attr( $name ); ?>[browse_slug]" value="<?php echo esc_attr( $o['browse_slug'] ); ?>">
<p class="description">The URL name of the page that lists every city, offered when a search finds nothing. Leave blank for no link.</p></td></tr>
<tr><th scope="row"><label for="dos-cs-bg">Background color</label></th><td><input type="text" id="dos-cs-bg" class="dos-cs-color" data-var="--dos-cs-bg" name="<?php echo esc_attr( $name ); ?>[bg_color]" value="<?php echo esc_attr( $o['bg_color'] ); ?>" data-default-color="<?php echo esc_attr( $d['bg_color'] ); ?>">
<p class="description">The preview updates as you pick. Light shades keep the text readable.</p></td></tr>
<tr><th scope="row"><label for="dos-cs-accent">Button color</label></th><td><input type="text" id="dos-cs-accent" class="dos-cs-color" data-var="--dos-cs-accent" name="<?php echo esc_attr( $name ); ?>[accent_color]" value="<?php echo esc_attr( $o['accent_color'] ); ?>" data-default-color="<?php echo esc_attr( $d['accent_color'] ); ?>">
<p class="description">Also used for the focus ring. Pick a shade dark enough for white button text. Ignored when the button gets its look from a class below.</p></td></tr>
<tr><th scope="row"><label for="dos-cs-heading">Heading</label></th><td><input type="text" id="dos-cs-heading" class="regular-text" name="<?php echo esc_attr( $name ); ?>[heading]" value="<?php echo esc_attr( $o['heading'] ); ?>"></td></tr>
<tr><th scope="row"><label for="dos-cs-intro">Intro text</label></th><td><textarea id="dos-cs-intro" class="large-text" rows="3" name="<?php echo esc_attr( $name ); ?>[intro]"><?php echo esc_textarea( $o['intro'] ); ?></textarea>
<p class="description">Leave blank to hide it.</p></td></tr>
<tr><th scope="row"><label for="dos-cs-ph">Placeholder</label></th><td><input type="text" id="dos-cs-ph" class="regular-text" name="<?php echo esc_attr( $name ); ?>[placeholder]" value="<?php echo esc_attr( $o['placeholder'] ); ?>"></td></tr>
<tr><th scope="row"><label for="dos-cs-btn">Button label</label></th><td><input type="text" id="dos-cs-btn" class="regular-text" name="<?php echo esc_attr( $name ); ?>[button_label]" value="<?php echo esc_attr( $o['button_label'] ); ?>"></td></tr>
<tr><th scope="row"><label for="dos-cs-max">Most suggestions shown</label></th><td><input type="number" id="dos-cs-max" class="small-text" min="0" max="500" name="<?php echo esc_attr( $name ); ?>[max_results]" value="<?php echo esc_attr( $o['max_results'] ); ?>">
<p class="description">0 shows every match.</p></td></tr>
<tr><th scope="row">Empty box</th><td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[show_all_on_focus]" value="1" <?php checked( $o['show_all_on_focus'] ); ?>> List the cities as soon as the box is clicked</label></td></tr>
<tr><th scope="row"><label for="dos-cs-cls-sec">Extra classes: box</label></th><td><input type="text" id="dos-cs-cls-sec" class="regular-text" name="<?php echo esc_attr( $name ); ?>[section_class]" value="<?php echo esc_attr( $o['section_class'] ); ?>">
<p class="description">For a site that already styles its own search box. Each class is added to the markup next to this plugin’s own.</p></td></tr>
<tr><th scope="row"><label for="dos-cs-cls-form">Extra classes: form</label></th><td><input type="text" id="dos-cs-cls-form" class="regular-text" name="<?php echo esc_attr( $name ); ?>[form_class]" value="<?php echo esc_attr( $o['form_class'] ); ?>"></td></tr>
<tr><th scope="row"><label for="dos-cs-cls-btn">Extra classes: button</label></th><td><input type="text" id="dos-cs-cls-btn" class="regular-text" name="<?php echo esc_attr( $name ); ?>[button_class]" value="<?php echo esc_attr( $o['button_class'] ); ?>">
<p class="description">When the button has a class here, this plugin leaves the button’s colors and shape to the site.</p></td></tr>
</table>
	<?php submit_button( 'Save Settings' ); ?>
</form>
</div>

<div class="dos-cs-box" style="margin-top:24px">
<h2>City list</h2>
<p>Rebuilds on its own whenever a page is published, saved or unpublished<?php echo 'taxonomy' === $source ? ', or a City is tagged, renamed or deleted' : ''; ?>, and at least every 12 hours. Use this after bulk changes if you want it refreshed right now.</p>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
<input type="hidden" name="action" value="dos_city_search_rebuild">
	<?php wp_nonce_field( 'dos_city_search_rebuild' ); ?>
	<?php submit_button( 'Rebuild City List Now', 'secondary', 'submit', false ); ?>
</form>
<p>Data feed: <a href="<?php echo esc_url( rest_url( 'dos-city-search/v1/cities' ) ); ?>" target="_blank" rel="noopener"><span class="dos-cs-code">/wp-json/dos-city-search/v1/cities</span></a></p>

	<?php if ( $meta['fallback_n'] && 'slug_state' === $source ) : ?>
<details><summary><strong><?php echo esc_html( number_format_i18n( $meta['fallback_n'] ) ); ?></strong> pages with a title that isn’t “City ST – …” (label built from the URL instead)</summary>
<ul class="dos-cs-states">
		<?php foreach ( $meta['fallbacks'] as $slug ) : ?>
<li><a href="<?php echo esc_url( home_url( '/' . $slug . '/' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $slug ); ?></a></li>
		<?php endforeach; ?>
</ul></details>
	<?php endif; ?>

	<?php if ( $meta['skipped'] && 'taxonomy' === $source ) : ?>
<details><summary><strong><?php echo esc_html( number_format_i18n( count( $meta['skipped'] ) ) ); ?></strong> City-tagged pages left out</summary>
<ul>
		<?php foreach ( $meta['skipped'] as $s ) : ?>
<li><a href="<?php echo esc_url( get_edit_post_link( $s['id'] ) ); ?>"><?php echo esc_html( get_the_title( $s['id'] ) ); ?></a>: <?php echo esc_html( $s['why'] ); ?></li>
		<?php endforeach; ?>
</ul></details>
	<?php endif; ?>

	<?php if ( 'slug_state' === $source ) : ?>
<details><summary>Cities by state</summary>
<ul class="dos-cs-states">
		<?php foreach ( $by_state as $st => $n ) : ?>
<li><strong><?php echo esc_html( $st ); ?></strong> <?php echo esc_html( number_format_i18n( $n ) ); ?></li>
		<?php endforeach; ?>
</ul></details>
	<?php else : ?>
<details><summary>Searchable cities</summary>
<ul class="dos-cs-states">
		<?php foreach ( $index as $c ) : ?>
<li><a href="<?php echo esc_url( $c['u'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $c['n'] ); ?></a></li>
		<?php endforeach; ?>
</ul></details>
	<?php endif; ?>
</div>
</div>

<div class="dos-cs-box">
<h2>Preview</h2>
<p>Try it here. Picking a city opens its page. Buttons and fonts follow your theme on the live site<?php echo $front_id ? ' — <a href="' . esc_url( get_permalink( $front_id ) ) . '" target="_blank" rel="noopener">view homepage</a>' : ''; ?>.</p>
<div id="dos-cs-preview"<?php echo $preview_class ? ' class="' . esc_attr( $preview_class ) . '"' : ''; ?>>
	<?php echo dos_city_search_markup(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the markup function ?>
</div>
</div>
</div>
</div>
	<?php
}
