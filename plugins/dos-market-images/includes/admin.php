<?php
/**
 * Tools → Market Images: settings, and every city/state image with show/hide and "Change image".
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOS_MI_PAGE', 'dos-market-images' );

add_action(
	'admin_menu',
	function () {
		add_management_page( 'DoS Market Images', 'Market Images', 'manage_options', DOS_MI_PAGE, 'dos_mi_render_page' );
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( dirname( __DIR__ ) . '/dos-market-images.php' ),
	function ( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'tools.php?page=' . DOS_MI_PAGE ) ) . '">Dashboard</a>' );
		return $links;
	}
);

add_action(
	'admin_post_dos_mi_save',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'dos_mi_save' );
		$in = wp_unslash( $_POST );
		$d  = dos_mi_defaults();

		update_option(
			DOS_MI_OPTION,
			array(
				'carousel_on'  => empty( $in['carousel_on'] ) ? 0 : 1,
				'carousel_max' => max( 1, min( 200, absint( isset( $in['carousel_max'] ) ? $in['carousel_max'] : $d['carousel_max'] ) ) ),
				'states_on'    => empty( $in['states_on'] ) ? 0 : 1,
				'states_page'  => sanitize_title( ! empty( $in['states_page'] ) ? $in['states_page'] : $d['states_page'] ),
				'states_title' => sanitize_text_field( isset( $in['states_title'] ) ? $in['states_title'] : '' ),
				'states_filter'    => empty( $in['states_filter'] ) ? 0 : 1,
				'states_hide_list' => empty( $in['states_hide_list'] ) ? 0 : 1,
				'state_hero_on'    => empty( $in['state_hero_on'] ) ? 0 : 1,
				'topics_on'        => empty( $in['topics_on'] ) ? 0 : 1,
				'topics_map'       => sanitize_textarea_field( isset( $in['topics_map'] ) ? $in['topics_map'] : $d['topics_map'] ),
				'buttons_map'      => sanitize_textarea_field( isset( $in['buttons_map'] ) ? $in['buttons_map'] : $d['buttons_map'] ),
			)
		);

		$data = dos_mi_data();
		foreach ( array( 'cities', 'states' ) as $kind ) {
			foreach ( $data[ $kind ] as $i => $it ) {
				$k                              = $it['key'];
				$data[ $kind ][ $i ]['hidden']  = empty( $in['show'][ $kind ][ $k ] ) ? 1 : 0;
				$att                            = isset( $in['att'][ $kind ][ $k ] ) ? absint( $in['att'][ $kind ][ $k ] ) : 0;
				if ( $att && wp_attachment_is_image( $att ) ) {
					$data[ $kind ][ $i ]['attachment_id'] = $att;
				}
			}
		}
		update_option( DOS_MI_DATA, $data, false );

		wp_safe_redirect( admin_url( 'tools.php?page=' . DOS_MI_PAGE . '&saved=1' ) );
		exit;
	}
);

add_action(
	'admin_post_dos_mi_palettes',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'dos_mi_palettes' );
		$in  = isset( $_POST['pal'] ) ? wp_unslash( (array) $_POST['pal'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$all = dos_mi_palettes();
		foreach ( $in as $slug => $list ) {
			$slug  = sanitize_title( $slug );
			$clean = array();
			foreach ( (array) $list as $pl ) {
				if ( ! empty( $pl['remove'] ) ) {
					continue;
				}
				$pl = dos_mi_clean_palette( $pl );
				if ( $pl['colors'] ) {
					$clean[] = $pl;
				}
			}
			$all[ $slug ] = $clean;
		}
		update_option( DOS_MI_PALETTES, $all, false );
		wp_safe_redirect( admin_url( 'tools.php?page=' . DOS_MI_PAGE . '&saved=1#dos-mi-palettes' ) );
		exit;
	}
);

add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		if ( 'tools_page_' . DOS_MI_PAGE !== $hook ) {
			return;
		}
		wp_enqueue_media();
		wp_register_script( 'dos-mi-admin', false, array( 'jquery' ), DOS_MI_VERSION, true );
		wp_enqueue_script( 'dos-mi-admin' );
		wp_add_inline_script(
			'dos-mi-admin',
			<<<'JS'
jQuery(function ($) {
  $(document).on('click', '.dos-mi-change', function (e) {
    e.preventDefault();
    var card = $(this).closest('.dos-mi-item');
    var frame = wp.media({ title: 'Choose an image for ' + card.data('label'), library: { type: 'image' }, button: { text: 'Use this image' }, multiple: false });
    frame.on('select', function () {
      var a = frame.state().get('selection').first().toJSON();
      var src = (a.sizes && (a.sizes.medium || a.sizes.large) || a).url;
      card.find('input.dos-mi-att').val(a.id);
      card.find('img').attr({ src: src, srcset: '' });
      card.addClass('is-changed');
    });
    frame.open();
  });
  $(document).on('click', '.dos-mi-addpal', function (e) {
    e.preventDefault();
    var box = $(this).closest('.dos-mi-pals'), last = box.find('.dos-mi-pal').last();
    var idx = box.find('.dos-mi-pal').length, slug = box.data('slug');
    var row = last.clone();
    row.find('input,select').each(function () {
      this.name = this.name.replace(/^pal\[[^\]]+\]\[\d+\]/, 'pal[' + slug + '][' + idx + ']');
      if (this.type === 'checkbox') this.checked = false;
    });
    row.find('.dos-mi-pal-label').val('New palette');
    row.insertAfter(last);
  });
  $('#dos-mi-filter').on('input', function () {
    var q = this.value.toLowerCase();
    $('.dos-mi-item').each(function () { $(this).toggle(String($(this).data('label')).toLowerCase().indexOf(q) !== -1); });
  });
});
JS
		);
		wp_register_style( 'dos-mi-admin', false, array(), DOS_MI_VERSION );
		wp_enqueue_style( 'dos-mi-admin' );
		wp_add_inline_style(
			'dos-mi-admin',
			'.dos-mi-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin:16px 0 24px;max-width:960px}'
			. '.dos-mi-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px 16px}'
			. '.dos-mi-card b{display:block;font-size:24px;line-height:1.2;margin-bottom:2px}.dos-mi-card span{color:#646970}'
			. '.dos-mi-box{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:4px 20px 16px;margin:0 0 24px;max-width:1400px}'
			. '.dos-mi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:12px;margin:12px 0}'
			. '.dos-mi-item{border:1px solid #dcdcde;border-radius:6px;overflow:hidden;background:#fafafa;display:flex;flex-direction:column}'
			. '.dos-mi-item.is-hidden img{opacity:.35}.dos-mi-item.is-changed{outline:2px solid #2271b1}'
			. '.dos-mi-item img{display:block;width:100%;aspect-ratio:4/3;object-fit:cover;background:#eee}'
			. '.dos-mi-item .dos-mi-missing{aspect-ratio:4/3;display:flex;align-items:center;justify-content:center;color:#8c8f94;background:#f0f0f1;font-size:12px}'
			. '.dos-mi-meta{padding:8px 10px;display:flex;flex-direction:column;gap:6px;font-size:13px}'
			. '.dos-mi-meta strong{line-height:1.3}.dos-mi-warn{color:#b32d2e;font-size:12px}'
			. '.dos-mi-row{display:flex;justify-content:space-between;align-items:center;gap:6px}'
			. '.dos-mi-toolbar{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:8px 0 0}'
			. '.dos-mi-pals{display:grid;grid-template-columns:132px 1fr;gap:12px;padding:12px 0;border-top:1px solid #f0f0f1}'
			. '.dos-mi-pals img{width:120px;height:90px;object-fit:cover;border-radius:4px;display:block}'
			. '.dos-mi-pal{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 8px}'
			. '.dos-mi-pal .dos-mi-pal-label{width:170px}.dos-mi-pal .dos-mi-pal-names{flex:1 1 320px}'
			. '.dos-mi-pal input[type=color]{width:38px;height:30px;padding:0;border:1px solid #c3c4c7;border-radius:4px;cursor:pointer}'
			. '.dos-mi-save{position:sticky;bottom:0;background:#f0f0f1;padding:12px 0;border-top:1px solid #dcdcde;z-index:5}'
		);
	}
);

function dos_mi_item_card( $kind, $it, $rank = 0 ) {
	$id   = (int) $it['attachment_id'];
	$img  = $id ? wp_get_attachment_image( $id, 'medium', false, array( 'alt' => '' ) ) : '';
	$url  = 'cities' === $kind ? dos_mi_city_url( $it ) : dos_mi_state_url( $it );
	$warn = ! $url ? ( 'cities' === $kind ? 'Page not published: hidden on site' : 'State page not found' ) : ( ! $img ? 'Image missing' : '' );
	$name = esc_attr( $kind ) . '][' . esc_attr( $it['key'] );
	ob_start();
	?>
<div class="dos-mi-item<?php echo ! empty( $it['hidden'] ) ? ' is-hidden' : ''; ?>" data-label="<?php echo esc_attr( $it['label'] ); ?>">
	<?php echo $img ? $img : '<div class="dos-mi-missing">No image</div>'; // phpcs:ignore WordPress.Security.EscapeOutput ?>
<div class="dos-mi-meta">
<strong><?php echo $rank ? esc_html( $rank . '. ' ) : ''; ?><?php echo $url ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $it['label'] ) . '</a>' : esc_html( $it['label'] ); ?></strong>
	<?php if ( $warn ) : ?><span class="dos-mi-warn"><?php echo esc_html( $warn ); ?></span><?php endif; ?>
<div class="dos-mi-row">
<label><input type="checkbox" name="show[<?php echo $name; // phpcs:ignore ?>]" value="1" <?php checked( empty( $it['hidden'] ) ); ?>> Show</label>
<a href="#" class="dos-mi-change">Change image</a>
</div>
<input type="hidden" class="dos-mi-att" name="att[<?php echo $name; // phpcs:ignore ?>]" value="<?php echo esc_attr( $id ); ?>">
</div>
</div>
	<?php
	return ob_get_clean();
}

function dos_mi_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s    = dos_mi_settings();
	$data = dos_mi_data();

	$states = $data['states'];
	usort(
		$states,
		function ( $a, $b ) {
			return strcasecmp( $a['label'], $b['label'] );
		}
	);
	$live = function ( $items, $kind ) {
		$n = 0;
		foreach ( $items as $it ) {
			$url = 'cities' === $kind ? dos_mi_city_url( $it ) : dos_mi_state_url( $it );
			if ( empty( $it['hidden'] ) && $url && $it['attachment_id'] ) {
				$n++;
			}
		}
		return $n;
	};
	$front = (int) get_option( 'page_on_front' );
	$sp    = get_page_by_path( $s['states_page'] );
	?>
<div class="wrap">
<h1>DoS Market Images</h1>
<p>Illustrated images for the homepage <strong>Popular Real Estate Markets</strong> carousel and the <strong>By State</strong> page buttons.
	<?php echo $front ? '<a href="' . esc_url( get_permalink( $front ) ) . '" target="_blank" rel="noopener">View homepage</a>' : ''; ?>
	<?php echo $sp ? ' · <a href="' . esc_url( get_permalink( $sp ) ) . '" target="_blank" rel="noopener">View By State page</a>' : ''; ?></p>

	<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
<div class="notice notice-success is-dismissible"><p>Saved.</p></div>
	<?php endif; ?>

<div class="dos-mi-cards">
<div class="dos-mi-card"><b><?php echo esc_html( $live( $data['cities'], 'cities' ) ); ?> / <?php echo esc_html( count( $data['cities'] ) ); ?></b><span>cities showing in carousel</span></div>
<div class="dos-mi-card"><b><?php echo esc_html( $live( $data['states'], 'states' ) ); ?> / <?php echo esc_html( count( $data['states'] ) ); ?></b><span>state buttons showing</span></div>
<div class="dos-mi-card"><b><?php echo $s['carousel_on'] ? 'On' : 'Off'; ?></b><span>homepage carousel</span></div>
<div class="dos-mi-card"><b><?php echo $s['states_on'] ? 'On' : 'Off'; ?></b><span>By State buttons</span></div>
</div>

	<?php if ( ! $data['cities'] && ! $data['states'] ) : ?>
<div class="notice notice-info inline"><p>No images loaded yet. They're added by the image upload script; this page fills in once that runs.</p></div>
	<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
<input type="hidden" name="action" value="dos_mi_save">
	<?php wp_nonce_field( 'dos_mi_save' ); ?>

<div class="dos-mi-box">
<h2>Settings</h2>
<table class="form-table" role="presentation">
<tr><th scope="row">Homepage carousel</th><td><label><input type="checkbox" name="carousel_on" value="1" <?php checked( $s['carousel_on'] ); ?>> Replace the “Popular Real Estate Markets” links with the image carousel</label>
<p class="description">Or place it anywhere with <code>[revnm_market_carousel]</code>.</p></td></tr>
<tr><th scope="row"><label for="dos-mi-max">Cities in carousel</label></th><td><input type="number" id="dos-mi-max" name="carousel_max" min="1" max="200" value="<?php echo esc_attr( $s['carousel_max'] ); ?>" class="small-text"> <span class="description">Busiest markets first.</span></td></tr>
<tr><th scope="row">State buttons</th><td><label><input type="checkbox" name="states_on" value="1" <?php checked( $s['states_on'] ); ?>> Show image buttons above the state list</label>
<p class="description">Or place them anywhere with <code>[revnm_state_buttons]</code>.</p></td></tr>
<tr><th scope="row">By State options</th><td><label><input type="checkbox" name="states_filter" value="1" <?php checked( $s['states_filter'] ); ?>> Filter box above the state images</label><br>
<label><input type="checkbox" name="states_hide_list" value="1" <?php checked( $s['states_hide_list'] ); ?>> Remove the old text list of states (the images replace it)</label></td></tr>
<tr><th scope="row">Homepage topic grids</th><td><label><input type="checkbox" name="topics_on" value="1" <?php checked( $s['topics_on'] ); ?>> Show 2 related blog posts at the end of homepage sections</label>
<p><label for="dos-mi-topics">One line per section: <code>Heading | category-slug, category-slug</code>. Shows the newest posts from those categories; a post is never repeated on the page.</label></p>
<textarea id="dos-mi-topics" name="topics_map" rows="6" class="large-text code"><?php echo esc_textarea( $s['topics_map'] ); ?></textarea>
<p class="description">Category slugs: <?php
	$cats = get_categories( array( 'hide_empty' => true ) );
	echo esc_html( implode( ', ', wp_list_pluck( $cats, 'slug' ) ) );
?></p></td></tr>
<tr><th scope="row"><label for="dos-mi-buttons">Homepage section buttons</label></th><td>
<p>One line per section: <code>Heading | Button text | link</code>. A button is added only to sections that don’t already have one.</p>
<textarea id="dos-mi-buttons" name="buttons_map" rows="6" class="large-text code"><?php echo esc_textarea( $s['buttons_map'] ); ?></textarea></td></tr>
<tr><th scope="row">State pages</th><td><label><input type="checkbox" name="state_hero_on" value="1" <?php checked( $s['state_hero_on'] ); ?>> Show the state’s image at the top of each state page, with the city filter right below it</label></td></tr>
<tr><th scope="row"><label for="dos-mi-sp">By State page slug</label></th><td><input type="text" id="dos-mi-sp" name="states_page" value="<?php echo esc_attr( $s['states_page'] ); ?>" class="regular-text"></td></tr>
<tr><th scope="row"><label for="dos-mi-st">State buttons heading</label></th><td><input type="text" id="dos-mi-st" name="states_title" value="<?php echo esc_attr( $s['states_title'] ); ?>" class="regular-text"> <span class="description">Leave blank for none.</span></td></tr>
</table>
</div>

	<?php if ( $data['cities'] || $data['states'] ) : ?>
<div class="dos-mi-toolbar"><label for="dos-mi-filter">Find:</label> <input type="search" id="dos-mi-filter" placeholder="City or state…" class="regular-text"></div>
	<?php endif; ?>

	<?php if ( $data['cities'] ) : ?>
<div class="dos-mi-box">
<h2>Cities (carousel order)</h2>
<p>Uncheck <strong>Show</strong> to drop a city from the carousel. <strong>Change image</strong> opens the Media Library.</p>
<div class="dos-mi-grid">
		<?php
		foreach ( $data['cities'] as $i => $it ) {
			echo dos_mi_item_card( 'cities', $it, $i + 1 ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		?>
</div>
</div>
	<?php endif; ?>

	<?php if ( $states ) : ?>
<div class="dos-mi-box">
<h2>States &amp; territories</h2>
<div class="dos-mi-grid">
		<?php
		foreach ( $states as $it ) {
			echo dos_mi_item_card( 'states', $it ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		?>
</div>
</div>
	<?php endif; ?>

<div class="dos-mi-save"><?php submit_button( 'Save Changes', 'primary', 'submit', false ); ?></div>
</form>

	<?php
	$pals = dos_mi_palettes();
	if ( $states ) :
		?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="dos-mi-palettes">
<input type="hidden" name="action" value="dos_mi_palettes">
		<?php wp_nonce_field( 'dos_mi_palettes' ); ?>
<div class="dos-mi-box">
<h2>Color palettes</h2>
<p>The reference for every new illustration: images about a state use that state’s palettes, rotating through them when there’s more than one. <strong>Color names</strong> go into the image prompt; the <strong>swatches</strong> are the ink colors (starting values were sampled from each state’s illustration).</p>
		<?php
		foreach ( $states as $st ) :
			$slug = $st['slug'];
			$list = ! empty( $pals[ $slug ] ) ? $pals[ $slug ] : array( array( 'label' => 'Default', 'names' => '', 'colors' => array( '#1f2a44', '#3b6ea5', '#8fb3d9', '#e8b04b', '#b5523b', '#f3ecd9' ) ) );
			?>
<div class="dos-mi-pals dos-mi-item" data-label="<?php echo esc_attr( $st['label'] ); ?>" data-slug="<?php echo esc_attr( $slug ); ?>">
<div><?php echo $st['attachment_id'] ? wp_get_attachment_image( (int) $st['attachment_id'], 'medium', false, array( 'alt' => '' ) ) : ''; // phpcs:ignore ?><strong><?php echo esc_html( $st['label'] ); ?></strong></div>
<div>
			<?php foreach ( $list as $i => $pl ) : $n = 'pal[' . esc_attr( $slug ) . '][' . (int) $i . ']'; ?>
<div class="dos-mi-pal">
<input type="text" class="dos-mi-pal-label" name="<?php echo $n; // phpcs:ignore ?>[label]" value="<?php echo esc_attr( $pl['label'] ); ?>" aria-label="Palette name">
				<?php for ( $k = 0; $k < 6; $k++ ) : ?>
<input type="color" name="<?php echo $n; // phpcs:ignore ?>[colors][]" value="<?php echo esc_attr( isset( $pl['colors'][ $k ] ) ? $pl['colors'][ $k ] : '#ffffff' ); ?>" aria-label="Color <?php echo (int) $k + 1; ?>" title="<?php echo esc_attr( isset( $pl['colors'][ $k ] ) ? $pl['colors'][ $k ] : '' ); ?>">
				<?php endfor; ?>
<input type="text" class="dos-mi-pal-names" name="<?php echo $n; // phpcs:ignore ?>[names]" value="<?php echo esc_attr( $pl['names'] ); ?>" placeholder="Color names, comma separated" aria-label="Color names">
<label><input type="checkbox" name="<?php echo $n; // phpcs:ignore ?>[remove]" value="1"> Remove</label>
</div>
			<?php endforeach; ?>
<a href="#" class="dos-mi-addpal">+ Add palette</a>
</div>
</div>
		<?php endforeach; ?>
<div class="dos-mi-save"><?php submit_button( 'Save Palettes', 'primary', 'submit', false ); ?></div>
</div>
</form>
	<?php endif; ?>
</div>
	<?php
}
