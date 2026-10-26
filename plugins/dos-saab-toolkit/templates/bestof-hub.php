<?php
/**
 * The "Best in Tri-Cities" hub. Wraps SAAB_Bestof_Hub::render() in the
 * Themify "Magazine" layout markup, like templates/bestof.php. Used instead
 * of the page's own (Builder) template only while the hub switch is on.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<div id="layout" class="pagewidth tf_clearfix">
	<div id="contentwrap">
		<main id="content" class="tf_clearfix">
			<?php echo SAAB_Bestof_Hub::render(); // Escaped inside. ?>
		</main>
	</div>
</div>
<?php
get_footer();
