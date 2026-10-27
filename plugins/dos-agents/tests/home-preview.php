<?php
/**
 * Writes a static preview of the homepage to tests/preview/home.html, using the plugin's real
 * shortcodes and the real CSS and JS, over a sample of the live city index.
 *
 *   /opt/homebrew/bin/php plugins/dos-agents/tests/home-preview.php [cities.json]
 *
 * The optional argument is a JSON array of {"c": city, "s": ST, "n": agents} — an export of the real
 * index — so the carousel counts and the typeahead are the site's own, not invented. Without it a
 * small built-in sample is used. Nothing here talks to a site.
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( "Run this from the command line.\n" );
}

$plugin = dirname( __DIR__ );

require dirname( $plugin, 2 ) . '/tests/wp-stubs-agents.php';
require $plugin . '/dos-agents.php';

dos_agents_register_home_shortcodes();

$sample = array(
	array( 'c' => 'Boise', 's' => 'ID', 'n' => 48 ),
	array( 'c' => 'Meridian', 's' => 'ID', 'n' => 15 ),
	array( 'c' => 'Seattle', 's' => 'WA', 'n' => 24 ),
	array( 'c' => 'Tacoma', 's' => 'WA', 'n' => 18 ),
	array( 'c' => 'Spokane', 's' => 'WA', 'n' => 15 ),
	array( 'c' => 'Portland', 's' => 'OR', 'n' => 24 ),
	array( 'c' => 'Austin', 's' => 'TX', 'n' => 24 ),
);
if ( isset( $argv[1] ) ) {
	$loaded = json_decode( (string) file_get_contents( $argv[1] ), true );
	if ( ! is_array( $loaded ) || ! $loaded ) {
		fwrite( STDERR, "Could not read a city list from {$argv[1]}\n" );
		exit( 1 );
	}
	$sample = $loaded;
}

// Build the "site": one stored city and one published page carrying the shortcode, per row.
foreach ( $sample as $row ) {
	$slug   = dos_agents_city_slug( $row['c'], $row['s'] );
	$agents = array();
	for ( $i = 0, $n = max( 1, (int) $row['n'] ); $i < $n; $i++ ) {
		$agents[] = array( 'name' => 'Agent ' . $i, 'profile_url' => 'https://www.zillow.com/profile/a' . $i );
	}
	update_option( dos_agents_option_name( $slug ), array( 'city' => $row['c'], 'state' => $row['s'], 'agents' => $agents ), false );
	agents_add_page( 'in-' . $slug, '[breanm_agents]' );
}
// The state hubs the carousel links to.
foreach ( array_unique( array_column( $sample, 's' ) ) as $code ) {
	agents_add_page( 'in-' . sanitize_title( dos_agents_state_name( $code ) ), 'Hub page.' );
}
$cities = dos_agents_index_rebuild();

$search   = call_user_func( $GLOBALS['shortcodes']['breanm_city_search'], array() );
$carousel = call_user_func( $GLOBALS['shortcodes']['breanm_state_index'], array() );
$hub      = call_user_func( $GLOBALS['shortcodes']['breanm_state_index'], array( 'state' => $sample[0]['s'] ) );

$ver  = rawurlencode( DOS_AGENTS_VERSION );
$page = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DoS - Agents preview: the homepage</title>
<link rel="stylesheet" href="../../assets/breanm-brand.css?ver={$ver}">
<link rel="stylesheet" href="../../assets/breanm-home.css?ver={$ver}">
<style>
/* Preview page only: stands in for the theme's own wrapper and header band. */
body { margin: 0; }
.preview-head { border-bottom: 1px solid #E1E4E9; }
.preview-head > div, .preview-wrap { max-width: 1120px; margin: 0 auto; padding-inline: 20px; }
.preview-head > div { padding-block: 14px; }
.preview-wrap { padding-block: 40px 80px; }
.entry-content h1 { font-size: clamp(32px, 5.2vw, 52px); }
</style>
</head>
<body class="breanm-brand home">
<header class="preview-head"><div id="site-logo"><a href="#"><span>Best Real Estate Agents Near Me</span></a></div></header>
<div class="preview-wrap entry-content">
<h1>Find a Real Estate Agent in Your City</h1>
<p class="breanm-dek">We read public agent profiles and recent client reviews to show who is actually working in your city &mdash; where their office is, which areas they say they serve, and what their clients kept mentioning.</p>
{$search}
<h2>What you will find here</h2>
<p>Each city page lists the agents with recorded sales there, drawn from their public profiles. Every card shows where the agent&rsquo;s office is, which areas they say they serve, how long they have been licensed, and what recent clients mentioned in their reviews.</p>
{$carousel}
<h2>One state&rsquo;s hub block</h2>
<p>This is what sits at the top of a state page, so the count on the card above leads somewhere that matches it.</p>
{$hub}
</div>
<script src="../../assets/breanm-home.js?ver={$ver}"></script>
</body>
</html>
HTML;

// The header swap is an output-buffer filter on the finished page; run it the same way here.
$page = dos_agents_brand_swap_logo( $page );

$dir = __DIR__ . '/preview';
if ( ! is_dir( $dir ) ) {
	mkdir( $dir, 0755, true );
}
file_put_contents( $dir . '/home.html', $page );
echo 'Wrote ' . $dir . '/home.html (' . number_format( strlen( $page ) ) . " bytes, {$cities} cities)\n";
