<?php
/**
 * Writes a static preview of [breanm_agents city="boise-id"] to tests/preview/index.html, using the
 * plugin's real code on the Boise fixture, with the real CSS and JS linked by relative path so the
 * file opens straight from disk in a browser.
 *
 *   /opt/homebrew/bin/php plugins/dos-agents/tests/render-preview.php
 *
 * The fixture goes through the same upsert callback the REST route uses, and the page comes from the
 * registered shortcode, so what you see is what the site would print. Nothing here talks to a site.
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( "Run this from the command line.\n" );
}

$plugin = dirname( __DIR__ );

// The same minimal WordPress stubs the repo's tests use.
require dirname( $plugin, 2 ) . '/tests/wp-stubs-agents.php';
require $plugin . '/dos-agents.php';

dos_agents_register_shortcode();
dos_agents_register_routes();

$fixture = json_decode( file_get_contents( __DIR__ . '/fixtures/boise-payload.json' ), true );
// The fixture predates summary_parts and the old-reviews label: give the first agents both, so the preview shows them.
$fixture['agents'][0]['summary_parts'] = array(
	'buyers'        => 'First-time buyers say she explains each step and never rushes a decision.',
	'sellers'       => 'Sellers mention accurate pricing and a quick sale.',
	'working_style' => 'Calm, responsive and well prepared.',
);
$fixture['agents'][1]['summary_parts'] = array( 'working_style' => 'Direct and organised.' );
$fixture['agents'][2]['last_review']   = '2022-05-09';
$res     = dos_agents_rest_upsert( new WP_REST_Request( $fixture ) );
if ( is_wp_error( $res ) ) {
	fwrite( STDERR, 'Upsert failed: ' . $res->message . "\n" );
	exit( 1 );
}

$body = call_user_func( $GLOBALS['shortcodes']['breanm_agents'], array( 'city' => $res->data['slug'], 'initial' => '12' ) );

$ver  = rawurlencode( DOS_AGENTS_VERSION );
$page = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DoS - Agents preview: Boise, ID</title>
<link rel="stylesheet" href="../../assets/breanm-agents.css?ver={$ver}">
<style>
/* Preview page only: a plain white theme around the plugin output. */
body { margin: 0; padding: 24px 16px 96px; background: #fff; color: #0f1115; font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; line-height: 1.5; }
.preview-wrap { max-width: 1120px; margin: 0 auto; }
.preview-wrap h1 { margin: 0 0 8px; font-size: clamp(28px, 5vw, 44px); line-height: 1.1; }
.preview-wrap > p { margin: 0 0 24px; color: #5a606b; max-width: 65ch; }
</style>
</head>
<body>
<div class="preview-wrap">
<h1>Real Estate Agents in Boise, ID</h1>
<p>Static preview of the plugin output on the Boise fixture ({$res->data['count']} agents). Search, filters, sort and "Show more agents" run from the real breanm-agents.js.</p>
{$body}
</div>
<script src="../../assets/breanm-agents.js?ver={$ver}" defer></script>
</body>
</html>
HTML;

$dir = __DIR__ . '/preview';
if ( ! is_dir( $dir ) ) {
	mkdir( $dir, 0755, true );
}
file_put_contents( $dir . '/index.html', $page );
echo 'Wrote ' . $dir . '/index.html (' . number_format( strlen( $page ) ) . " bytes, {$res->data['count']} agents)\n";
