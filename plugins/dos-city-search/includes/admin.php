<?php
/**
 * Tools → City Search dashboard: index status, rebuild, settings (with color picker), live preview.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOS_CITY_SEARCH_PAGE', 'dos-city-search' );

add_action(
	'admin_menu',
	function () {
		add_management_page( 'DoS City Search', 'City Search', 'manage_options', DOS_CITY_SEARCH_PAGE, 'dos_city_search_render_page' );
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

function dos_city_search_sanitize( $in ) {
	$d   = dos_city_search_defaults();
	$in  = (array) $in;
	$out = array( 'auto_insert' => empty( $in['auto_insert'] ) ? 0 : 1 );

	foreach ( array( 'heading', 'placeholder', 'button_label' ) as $k ) {
		$v         = isset( $in[ $k ] ) ? sanitize_text_field( $in[ $k ] ) : '';
		$out[ $k ] = '' === $v ? $d[ $k ] : $v;
	}
	$out['intro'] = isset( $in['intro'] ) ? sanitize_textarea_field( $in['intro'] ) : $d['intro'];

	$bg              = isset( $in['bg_color'] ) ? sanitize_hex_color( $in['bg_color'] ) : '';
	$out['bg_color'] = $bg ? $bg : $d['bg_color'];

	return $out;
}

add_action(
	'admin_post_dos_city_search_rebuild',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'dos_city_search_rebuild' );
		delete_transient( DOS_CITY_SEARCH_TRANSIENT );
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
			"  function paint(c){ $('#dos-cs-preview .rv-city-search').css('background', c || ''); }\n" .
			"  $('.dos-cs-color').wpColorPicker({\n" .
			"    change: function(e, ui){ paint(ui.color.toString()); },\n" .
			"    clear: function(){ paint($(this).closest('.wp-picker-container').find('.dos-cs-color').data('default-color')); }\n" .
			"  });\n" .
			"});"
		);
		wp_register_style( 'dos-city-search-admin', false, array(), DOS_CITY_SEARCH_VERSION );
		wp_enqueue_style( 'dos-city-search-admin' );
		wp_add_inline_style(
			'dos-city-search-admin',
			'.dos-cs-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin:16px 0 24px;max-width:960px}'
			. '.dos-cs-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px 16px}'
			. '.dos-cs-card b{display:block;font-size:24px;line-height:1.2;margin-bottom:2px}'
			. '.dos-cs-card span{color:#646970}'
			. '.dos-cs-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:24px;max-width:1200px;align-items:start}'
			. '@media (max-width:1100px){.dos-cs-grid{grid-template-columns:1fr}}'
			. '.dos-cs-box{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:4px 20px 16px}'
			. '.dos-cs-box .form-table th{width:150px}'
			. '#dos-cs-preview{padding:4px 0}'
			. '#dos-cs-preview .rv-btn{display:inline-block;padding:12px 22px;min-height:48px;background:#1a5fb4;color:#fff;border:0;border-radius:6px;font:inherit;font-weight:600;cursor:pointer}'
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

	$index = dos_city_search_get_index();
	$meta  = wp_parse_args( (array) get_option( DOS_CITY_SEARCH_META, array() ), array( 'built' => 0, 'fallbacks' => array(), 'fallback_n' => 0 ) );
	$o     = dos_city_search_settings();
	$d     = dos_city_search_defaults();
	$name  = DOS_CITY_SEARCH_OPTION;

	$by_state = array();
	foreach ( $index as $row ) {
		$st              = substr( $row, strrpos( $row, '|' ) - 2, 2 );
		$by_state[ $st ] = isset( $by_state[ $st ] ) ? $by_state[ $st ] + 1 : 1;
	}
	ksort( $by_state );

	$front_id = (int) get_option( 'page_on_front' );
	$built    = $meta['built'] ? human_time_diff( $meta['built'] ) . ' ago' : 'Not yet';
	?>
<div class="wrap">
<h1>DoS City Search</h1>
<p>The City, State search box on the homepage. It suggests every <strong>published</strong> city page and goes straight to the one a visitor picks.</p>

	<?php if ( isset( $_GET['rebuilt'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
<div class="notice notice-success is-dismissible"><p>City list rebuilt: <?php echo esc_html( number_format_i18n( count( $index ) ) ); ?> cities.</p></div>
	<?php endif; ?>
	<?php settings_errors(); ?>

<div class="dos-cs-cards">
<div class="dos-cs-card"><b><?php echo esc_html( number_format_i18n( count( $index ) ) ); ?></b><span>cities searchable</span></div>
<div class="dos-cs-card"><b><?php echo esc_html( count( $by_state ) ); ?></b><span>states covered</span></div>
<div class="dos-cs-card"><b><?php echo esc_html( $built ); ?></b><span>list last built</span></div>
<div class="dos-cs-card"><b><?php echo $o['auto_insert'] ? 'On' : 'Off'; ?></b><span>shown on homepage</span></div>
</div>

<div class="dos-cs-grid">
<div>
<div class="dos-cs-box">
<h2>Settings</h2>
<form method="post" action="options.php">
	<?php settings_fields( 'dos_city_search' ); ?>
<table class="form-table" role="presentation">
<tr><th scope="row">Homepage</th><td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[auto_insert]" value="1" <?php checked( $o['auto_insert'] ); ?>> Show below the “What’s My Home Worth?” section</label>
<p class="description">Turn off to place it yourself with the <span class="dos-cs-code">[revnm_city_search]</span> shortcode.</p></td></tr>
<tr><th scope="row"><label for="dos-cs-bg">Background color</label></th><td><input type="text" id="dos-cs-bg" class="dos-cs-color" name="<?php echo esc_attr( $name ); ?>[bg_color]" value="<?php echo esc_attr( $o['bg_color'] ); ?>" data-default-color="<?php echo esc_attr( $d['bg_color'] ); ?>">
<p class="description">The preview updates as you pick. Light shades keep the text readable.</p></td></tr>
<tr><th scope="row"><label for="dos-cs-heading">Heading</label></th><td><input type="text" id="dos-cs-heading" class="regular-text" name="<?php echo esc_attr( $name ); ?>[heading]" value="<?php echo esc_attr( $o['heading'] ); ?>"></td></tr>
<tr><th scope="row"><label for="dos-cs-intro">Intro text</label></th><td><textarea id="dos-cs-intro" class="large-text" rows="3" name="<?php echo esc_attr( $name ); ?>[intro]"><?php echo esc_textarea( $o['intro'] ); ?></textarea>
<p class="description">Leave blank to hide it.</p></td></tr>
<tr><th scope="row"><label for="dos-cs-ph">Placeholder</label></th><td><input type="text" id="dos-cs-ph" class="regular-text" name="<?php echo esc_attr( $name ); ?>[placeholder]" value="<?php echo esc_attr( $o['placeholder'] ); ?>"></td></tr>
<tr><th scope="row"><label for="dos-cs-btn">Button label</label></th><td><input type="text" id="dos-cs-btn" class="regular-text" name="<?php echo esc_attr( $name ); ?>[button_label]" value="<?php echo esc_attr( $o['button_label'] ); ?>"></td></tr>
</table>
	<?php submit_button( 'Save Settings' ); ?>
</form>
</div>

<div class="dos-cs-box" style="margin-top:24px">
<h2>City list</h2>
<p>Rebuilds on its own whenever a page is published or moved to draft, and at least every 12 hours. Use this after bulk changes if you want it refreshed right now.</p>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
<input type="hidden" name="action" value="dos_city_search_rebuild">
	<?php wp_nonce_field( 'dos_city_search_rebuild' ); ?>
	<?php submit_button( 'Rebuild City List Now', 'secondary', 'submit', false ); ?>
</form>
<p>Data feed: <a href="<?php echo esc_url( rest_url( 'revnm/v1/cities' ) ); ?>" target="_blank" rel="noopener"><span class="dos-cs-code">/wp-json/revnm/v1/cities</span></a></p>

	<?php if ( $meta['fallback_n'] ) : ?>
<details><summary><strong><?php echo esc_html( number_format_i18n( $meta['fallback_n'] ) ); ?></strong> pages with a title that isn’t “City ST – …” (label built from the URL instead)</summary>
<ul class="dos-cs-states">
		<?php foreach ( $meta['fallbacks'] as $slug ) : ?>
<li><a href="<?php echo esc_url( home_url( '/' . $slug . '/' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $slug ); ?></a></li>
		<?php endforeach; ?>
</ul></details>
	<?php endif; ?>

<details><summary>Cities by state</summary>
<ul class="dos-cs-states">
	<?php foreach ( $by_state as $st => $n ) : ?>
<li><strong><?php echo esc_html( $st ); ?></strong> <?php echo esc_html( number_format_i18n( $n ) ); ?></li>
	<?php endforeach; ?>
</ul></details>
</div>
</div>

<div class="dos-cs-box">
<h2>Preview</h2>
<p>Try it here. Picking a city opens its page. Buttons use your theme’s styling on the live site<?php echo $front_id ? ' — <a href="' . esc_url( get_permalink( $front_id ) ) . '" target="_blank" rel="noopener">view homepage</a>' : ''; ?>.</p>
<div id="dos-cs-preview" class="revnm-home">
	<?php echo dos_city_search_markup(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the markup function ?>
</div>
</div>
</div>
</div>
	<?php
}
