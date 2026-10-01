<?php
/**
 * Tools → City Search dashboard: settings (with color pickers), the city list, rebuild, live preview.
 *
 * @package OHI
 */

defined( 'ABSPATH' ) || exit;

define( 'DOS_OHI_CS_PAGE', 'dos-ohi-city-search' );

add_action(
	'admin_menu',
	function () {
		add_management_page( 'DoS City Search', 'City Search', 'manage_options', DOS_OHI_CS_PAGE, 'dos_ohi_cs_render_page' );
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( dirname( __DIR__ ) . '/dos-ohi-city-search.php' ),
	function ( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'tools.php?page=' . DOS_OHI_CS_PAGE ) ) . '">Dashboard</a>' );
		return $links;
	}
);

add_action(
	'admin_init',
	function () {
		register_setting(
			'dos_ohi_cs',
			DOS_OHI_CS_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => 'dos_ohi_cs_sanitize',
			)
		);
	}
);

function dos_ohi_cs_sanitize( $in ) {
	$d   = dos_ohi_cs_defaults();
	$in  = (array) $in;
	$out = array( 'auto_insert' => empty( $in['auto_insert'] ) ? 0 : 1 );

	foreach ( array( 'heading', 'placeholder', 'button_label' ) as $k ) {
		$v         = isset( $in[ $k ] ) ? sanitize_text_field( $in[ $k ] ) : '';
		$out[ $k ] = '' === $v ? $d[ $k ] : $v;
	}
	$out['intro'] = isset( $in['intro'] ) ? sanitize_textarea_field( $in['intro'] ) : $d['intro'];

	foreach ( array( 'bg_color', 'accent_color' ) as $k ) {
		$c         = isset( $in[ $k ] ) ? sanitize_hex_color( $in[ $k ] ) : '';
		$out[ $k ] = $c ? $c : $d[ $k ];
	}

	return $out;
}

add_action(
	'admin_post_dos_ohi_cs_rebuild',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'dos_ohi_cs_rebuild' );
		dos_ohi_cs_flush();
		dos_ohi_cs_get_index();
		wp_safe_redirect( admin_url( 'tools.php?page=' . DOS_OHI_CS_PAGE . '&rebuilt=1' ) );
		exit;
	}
);

add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		if ( 'tools_page_' . DOS_OHI_CS_PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_add_inline_script(
			'wp-color-picker',
			"jQuery(function($){\n" .
			"  function paint(input, c){ $('#dos-ohi-cs-preview .ohi-city-search').css($(input).data('var'), c || $(input).data('default-color')); }\n" .
			"  $('.dos-ohi-cs-color').each(function(){ var el = this; $(el).wpColorPicker({\n" .
			"    change: function(e, ui){ paint(el, ui.color.toString()); },\n" .
			"    clear: function(){ paint(el, ''); }\n" .
			"  }); });\n" .
			"});"
		);
		wp_register_style( 'dos-ohi-cs-admin', false, array(), DOS_OHI_CS_VERSION );
		wp_enqueue_style( 'dos-ohi-cs-admin' );
		wp_add_inline_style(
			'dos-ohi-cs-admin',
			'.dos-ohi-cs-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin:16px 0 24px;max-width:960px}'
			. '.dos-ohi-cs-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px 16px}'
			. '.dos-ohi-cs-card b{display:block;font-size:24px;line-height:1.2;margin-bottom:2px}'
			. '.dos-ohi-cs-card span{color:#646970}'
			. '.dos-ohi-cs-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:24px;max-width:1200px;align-items:start}'
			. '@media (max-width:1100px){.dos-ohi-cs-grid{grid-template-columns:1fr}}'
			. '.dos-ohi-cs-box{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:4px 20px 16px}'
			. '.dos-ohi-cs-box .form-table th{width:150px}'
			. '#dos-ohi-cs-preview h2{font-size:1.4em}'
			. '.dos-ohi-cs-cities{columns:3 140px;margin:8px 0 0}'
			. '.dos-ohi-cs-cities li{margin:0 0 4px}'
			. '.dos-ohi-cs-code{font-family:monospace;background:#f0f0f1;padding:2px 6px;border-radius:3px}'
		);
	}
);

function dos_ohi_cs_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$index    = dos_ohi_cs_get_index();
	$meta     = wp_parse_args( (array) get_option( DOS_OHI_CS_META, array() ), array( 'built' => 0, 'skipped' => array() ) );
	$o        = dos_ohi_cs_settings();
	$d        = dos_ohi_cs_defaults();
	$name     = DOS_OHI_CS_OPTION;
	$front_id = (int) get_option( 'page_on_front' );
	$built    = $meta['built'] ? human_time_diff( $meta['built'] ) . ' ago' : 'Not yet';
	?>
<div class="wrap">
<h1>DoS City Search</h1>
<p>The city search box on the homepage. It suggests every <strong>published</strong> page tagged with a City and goes straight to the one a visitor picks.</p>

	<?php if ( ! taxonomy_exists( DOS_OHI_CS_TAXONOMY ) ) : ?>
<div class="notice notice-error"><p>The City taxonomy (<span class="dos-ohi-cs-code">ohi_city</span>) isn’t registered. Activate DoS Open Houses In Core first.</p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['rebuilt'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
<div class="notice notice-success is-dismissible"><p>City list rebuilt: <?php echo esc_html( number_format_i18n( count( $index ) ) ); ?> cities.</p></div>
	<?php endif; ?>
	<?php settings_errors(); ?>

<div class="dos-ohi-cs-cards">
<div class="dos-ohi-cs-card"><b><?php echo esc_html( number_format_i18n( count( $index ) ) ); ?></b><span>cities searchable</span></div>
<div class="dos-ohi-cs-card"><b><?php echo esc_html( $built ); ?></b><span>list last built</span></div>
<div class="dos-ohi-cs-card"><b><?php echo $o['auto_insert'] ? 'On' : 'Off'; ?></b><span>shown on homepage</span></div>
</div>

<div class="dos-ohi-cs-grid">
<div>
<div class="dos-ohi-cs-box">
<h2>Settings</h2>
<form method="post" action="options.php">
	<?php settings_fields( 'dos_ohi_cs' ); ?>
<table class="form-table" role="presentation">
<tr><th scope="row">Homepage</th><td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[auto_insert]" value="1" <?php checked( $o['auto_insert'] ); ?>> Show as the second section of the homepage</label>
<p class="description">It goes after the opening hero section, or before the first H2 when there isn’t one. Turn this off to place it yourself with the <span class="dos-ohi-cs-code">[ohi_city_search]</span> shortcode.</p></td></tr>
<tr><th scope="row"><label for="dos-ohi-cs-bg">Background color</label></th><td><input type="text" id="dos-ohi-cs-bg" class="dos-ohi-cs-color" data-var="--ohi-cs-bg" name="<?php echo esc_attr( $name ); ?>[bg_color]" value="<?php echo esc_attr( $o['bg_color'] ); ?>" data-default-color="<?php echo esc_attr( $d['bg_color'] ); ?>"></td></tr>
<tr><th scope="row"><label for="dos-ohi-cs-accent">Button color</label></th><td><input type="text" id="dos-ohi-cs-accent" class="dos-ohi-cs-color" data-var="--ohi-cs-accent" name="<?php echo esc_attr( $name ); ?>[accent_color]" value="<?php echo esc_attr( $o['accent_color'] ); ?>" data-default-color="<?php echo esc_attr( $d['accent_color'] ); ?>">
<p class="description">Also used for the focus ring. Pick a shade dark enough for white button text.</p></td></tr>
<tr><th scope="row"><label for="dos-ohi-cs-heading">Heading</label></th><td><input type="text" id="dos-ohi-cs-heading" class="regular-text" name="<?php echo esc_attr( $name ); ?>[heading]" value="<?php echo esc_attr( $o['heading'] ); ?>"></td></tr>
<tr><th scope="row"><label for="dos-ohi-cs-intro">Intro text</label></th><td><textarea id="dos-ohi-cs-intro" class="large-text" rows="3" name="<?php echo esc_attr( $name ); ?>[intro]"><?php echo esc_textarea( $o['intro'] ); ?></textarea>
<p class="description">Leave blank to hide it, which keeps the box shorter on phones.</p></td></tr>
<tr><th scope="row"><label for="dos-ohi-cs-ph">Placeholder</label></th><td><input type="text" id="dos-ohi-cs-ph" class="regular-text" name="<?php echo esc_attr( $name ); ?>[placeholder]" value="<?php echo esc_attr( $o['placeholder'] ); ?>"></td></tr>
<tr><th scope="row"><label for="dos-ohi-cs-btn">Button label</label></th><td><input type="text" id="dos-ohi-cs-btn" class="regular-text" name="<?php echo esc_attr( $name ); ?>[button_label]" value="<?php echo esc_attr( $o['button_label'] ); ?>"></td></tr>
</table>
	<?php submit_button( 'Save Settings' ); ?>
</form>
</div>

<div class="dos-ohi-cs-box" style="margin-top:24px">
<h2>City list</h2>
<p>Rebuilds on its own when a page is published, unpublished, or re-tagged, or a City is renamed, and at least every 12 hours. Use this after bulk changes if you want it refreshed now.</p>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
<input type="hidden" name="action" value="dos_ohi_cs_rebuild">
	<?php wp_nonce_field( 'dos_ohi_cs_rebuild' ); ?>
	<?php submit_button( 'Rebuild City List Now', 'secondary', 'submit', false ); ?>
</form>

	<?php if ( $meta['skipped'] ) : ?>
<details><summary><strong><?php echo esc_html( number_format_i18n( count( $meta['skipped'] ) ) ); ?></strong> City-tagged pages left out</summary>
<ul>
		<?php foreach ( $meta['skipped'] as $s ) : ?>
<li><a href="<?php echo esc_url( get_edit_post_link( $s['id'] ) ); ?>"><?php echo esc_html( get_the_title( $s['id'] ) ); ?></a>: <?php echo esc_html( $s['why'] ); ?></li>
		<?php endforeach; ?>
</ul></details>
	<?php endif; ?>

<details><summary>Searchable cities</summary>
<ul class="dos-ohi-cs-cities">
	<?php foreach ( $index as $c ) : ?>
<li><a href="<?php echo esc_url( $c['u'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $c['n'] ); ?></a></li>
	<?php endforeach; ?>
</ul></details>
</div>
</div>

<div class="dos-ohi-cs-box">
<h2>Preview</h2>
<p>Try it here. Picking a city opens its page. Fonts follow your theme on the live site<?php echo $front_id ? ' (<a href="' . esc_url( get_permalink( $front_id ) ) . '" target="_blank" rel="noopener">view homepage</a>)' : ''; ?>.</p>
<div id="dos-ohi-cs-preview">
	<?php echo dos_ohi_cs_markup(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the markup function ?>
</div>
</div>
</div>
</div>
	<?php
}
