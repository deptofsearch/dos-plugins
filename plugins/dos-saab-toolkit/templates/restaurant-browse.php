<?php
/**
 * Restaurant browse page (/restaurants/ and region archives). Wraps
 * SAAB_Restaurant_Browse::render() in the Themify "Magazine" layout markup
 * — #layout > #contentwrap > #content — minus the sidebar.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<div id="layout" class="pagewidth tf_clearfix">
	<div id="contentwrap">
		<main id="content" class="tf_clearfix">
			<?php echo SAAB_Restaurant_Browse::render(); // Escaped inside. ?>
		</main>
	</div>
</div>
<?php
get_footer();
