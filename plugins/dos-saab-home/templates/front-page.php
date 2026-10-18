<?php
/**
 * Front-page template while the SAAB Home takeover is on (Home::template()). The theme's header and footer, with this
 * plugin's layout between them in the same #layout / #content wrappers Themify Magazine uses for a page.
 *
 * @package DOS_SAAB
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<div id="layout" class="pagewidth tf_box clearfix saab-home-layout">
	<main id="content" class="tf_box clearfix">
		<?php echo DOS_SAAB\Home::render(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
	</main>
</div>
<?php
get_footer();
