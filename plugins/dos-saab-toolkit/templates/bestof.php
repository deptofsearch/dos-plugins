<?php
/**
 * "Best X in Tri-Cities" page in the restaurant-page style. Wraps
 * SAAB_Bestof::render() in the Themify "Magazine" layout markup —
 * #layout > #contentwrap > #content — minus the sidebar. Used instead of
 * the page's own (Builder) template only while the page has a Best-Of config.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<div id="layout" class="pagewidth tf_clearfix">
	<div id="contentwrap">
		<main id="content" class="tf_clearfix">
			<?php echo SAAB_Bestof::render(); // Escaped inside. ?>
		</main>
	</div>
</div>
<?php
get_footer();
