<?php
/**
 * Title: Footer
 * Slug: dos-department/footer-content
 * Categories: dos-department
 * Inserter: no
 */
?>
<!-- wp:html -->
<div class="dos-footer">
<img src="<?php echo esc_url( get_theme_file_uri( 'assets/logos/dos-lockup-reversed.svg' ) ); ?>" alt="Department of Search" style="height: 80px;">
<div style="display: flex; gap: 24px; align-items: center; flex-wrap: wrap;">
<a class="dos-kicker" href="/works/" style="text-decoration: none;">Works</a>
<a class="dos-kicker" href="/dispatches/" style="text-decoration: none;">Dispatches</a>
<a class="dos-kicker" href="/personnel-file/" style="text-decoration: none;">Personnel File</a>
<span class="dos-kicker">Answers · Skills · Workflows</span>
<span class="dos-kicker">© <?php echo esc_html( gmdate( 'Y' ) ); ?> Ryan Rose</span>
</div>
</div>
<!-- /wp:html -->
