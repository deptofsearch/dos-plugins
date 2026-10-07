<?php
/**
 * Flat WPA-style poster illustrations (from the approved Works / Dispatches designs).
 * Used as the fallback poster when a post has no featured image.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dos_poster_svgs() {
	$posters = array();
	$posters[] = <<<'SVG'
<svg viewBox="0 0 320 180" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><rect width="320" height="180" style="fill: var(--charcoal);"></rect><path d="M80 90 H110 V60 H140 M110 90 V120 H140 M180 60 H210 V90 H240 M180 120 H210 V90" style="fill: none; stroke: var(--graphite); stroke-width: 6;"></path><rect x="40" y="70" width="40" height="40" style="fill: var(--navy);"></rect><rect x="140" y="40" width="40" height="40" style="fill: var(--graphite);"></rect><rect x="140" y="100" width="40" height="40" style="fill: var(--graphite);"></rect><rect x="240" y="70" width="40" height="40" style="fill: var(--vermilion);"></rect><path d="M168.5 60.0 L168.3 61.8 L170.6 62.9 L169.5 65.5 L167.2 64.6 L166.0 66.0 L164.6 67.2 L165.5 69.5 L162.9 70.6 L161.8 68.3 L160.0 68.5 L158.2 68.3 L157.1 70.6 L154.5 69.5 L155.4 67.2 L154.0 66.0 L152.8 64.6 L150.5 65.5 L149.4 62.9 L151.7 61.8 L151.5 60.0 L151.7 58.2 L149.4 57.1 L150.5 54.5 L152.8 55.4 L154.0 54.0 L155.4 52.8 L154.5 50.5 L157.1 49.4 L158.2 51.7 L160.0 51.5 L161.8 51.7 L162.9 49.4 L165.5 50.5 L164.6 52.8 L166.0 54.0 L167.2 55.4 L169.5 54.5 L170.6 57.1 L168.3 58.2Z" style="fill: var(--on-charcoal);"></path><circle cx="160" cy="60" r="4" style="fill: var(--graphite);"></circle><path d="M168.5 120.0 L168.3 121.8 L170.6 122.9 L169.5 125.5 L167.2 124.6 L166.0 126.0 L164.6 127.2 L165.5 129.5 L162.9 130.6 L161.8 128.3 L160.0 128.5 L158.2 128.3 L157.1 130.6 L154.5 129.5 L155.4 127.2 L154.0 126.0 L152.8 124.6 L150.5 125.5 L149.4 122.9 L151.7 121.8 L151.5 120.0 L151.7 118.2 L149.4 117.1 L150.5 114.5 L152.8 115.4 L154.0 114.0 L155.4 112.8 L154.5 110.5 L157.1 109.4 L158.2 111.7 L160.0 111.5 L161.8 111.7 L162.9 109.4 L165.5 110.5 L164.6 112.8 L166.0 114.0 L167.2 115.4 L169.5 114.5 L170.6 117.1 L168.3 118.2Z" style="fill: var(--on-charcoal);"></path><circle cx="160" cy="120" r="4" style="fill: var(--graphite);"></circle><rect y="164" width="320" height="4" style="fill: var(--black);"></rect></svg>
SVG;
	$posters[] = <<<'SVG'
<svg viewBox="0 0 320 180" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><rect width="320" height="180" style="fill: var(--charcoal);"></rect><rect x="40" y="30" width="60" height="150" style="fill: var(--navy);"></rect><rect x="110" y="60" width="60" height="120" style="fill: var(--graphite);"></rect><rect x="180" y="90" width="60" height="90" style="fill: var(--navy);"></rect><rect x="250" y="120" width="60" height="60" style="fill: var(--graphite);"></rect><rect y="168" width="320" height="4" style="fill: var(--black);"></rect></svg>
SVG;
	$posters[] = <<<'SVG'
<svg viewBox="0 0 320 180" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><rect width="320" height="180" style="fill: var(--charcoal);"></rect><circle cx="160" cy="96" r="58" style="fill: none; stroke: var(--graphite); stroke-width: 14;"></circle><path d="M200 136 L250 186" style="stroke: var(--graphite); stroke-width: 18;"></path><path d="M120 110 A40 40 0 0 1 200 110 Z" style="fill: var(--navy);"></path><rect x="102" y="110" width="116" height="4" style="fill: var(--black);"></rect></svg>
SVG;
	$posters[] = <<<'SVG'
<svg viewBox="0 0 320 180" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><rect width="320" height="180" style="fill: var(--charcoal);"></rect><g style="fill: var(--graphite);"><rect x="30" y="40" width="80" height="100"></rect><rect x="120" y="40" width="80" height="100"></rect><rect x="210" y="40" width="80" height="100"></rect></g><rect x="120" y="40" width="80" height="100" style="fill: var(--navy);"></rect><rect y="160" width="320" height="4" style="fill: var(--black);"></rect></svg>
SVG;
	$posters[] = <<<'SVG'
<svg viewBox="0 0 320 180" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><rect width="320" height="180" style="fill: var(--charcoal);"></rect><path d="M60 180 L60 90 L120 50 L180 90 L180 180Z" style="fill: var(--navy);"></path><path d="M180 180 L180 110 L230 80 L280 110 L280 180Z" style="fill: var(--graphite);"></path><rect x="100" y="120" width="40" height="60" style="fill: var(--black);"></rect></svg>
SVG;
	$posters[] = <<<'SVG'
<svg viewBox="0 0 320 180" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><rect width="320" height="180" style="fill: var(--charcoal);"></rect><path d="M30 140 L90 100 L150 115 L210 60 L290 80" style="fill: none; stroke: var(--navy); stroke-width: 10;"></path><g style="fill: var(--graphite);"><rect x="30" y="150" width="40" height="14"></rect><rect x="80" y="150" width="70" height="14"></rect><rect x="160" y="150" width="50" height="14"></rect></g></svg>
SVG;
	return $posters;
}

function dos_dispatch_poster_svg() {
	return <<<'SVG'
<svg viewBox="0 0 600 360" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><rect width="600" height="360" style="fill: var(--charcoal);"></rect><path d="M170 180 H220 V110 H270 M220 180 V250 H270 M330 110 H380 V180 H430 M330 250 H380 V180" style="fill: none; stroke: var(--graphite); stroke-width: 8;"></path><rect x="100" y="145" width="70" height="70" style="fill: var(--navy);"></rect><rect x="270" y="80" width="60" height="60" style="fill: var(--graphite);"></rect><rect x="270" y="220" width="60" height="60" style="fill: var(--graphite);"></rect><rect x="430" y="145" width="70" height="70" style="fill: var(--vermilion);"></rect><path d="M313.0 110.0 L312.7 112.8 L316.4 114.4 L314.7 118.5 L310.9 117.0 L309.2 119.2 L307.0 120.9 L308.5 124.7 L304.4 126.4 L302.8 122.7 L300.0 123.0 L297.2 122.7 L295.6 126.4 L291.5 124.7 L293.0 120.9 L290.8 119.2 L289.1 117.0 L285.3 118.5 L283.6 114.4 L287.3 112.8 L287.0 110.0 L287.3 107.2 L283.6 105.6 L285.3 101.5 L289.1 103.0 L290.8 100.8 L293.0 99.1 L291.5 95.3 L295.6 93.6 L297.2 97.3 L300.0 97.0 L302.8 97.3 L304.4 93.6 L308.5 95.3 L307.0 99.1 L309.2 100.8 L310.9 103.0 L314.7 101.5 L316.4 105.6 L312.7 107.2Z" style="fill: var(--on-charcoal);"></path><circle cx="300" cy="110" r="6" style="fill: var(--graphite);"></circle><path d="M313.0 250.0 L312.7 252.8 L316.4 254.4 L314.7 258.5 L310.9 257.0 L309.2 259.2 L307.0 260.9 L308.5 264.7 L304.4 266.4 L302.8 262.7 L300.0 263.0 L297.2 262.7 L295.6 266.4 L291.5 264.7 L293.0 260.9 L290.8 259.2 L289.1 257.0 L285.3 258.5 L283.6 254.4 L287.3 252.8 L287.0 250.0 L287.3 247.2 L283.6 245.6 L285.3 241.5 L289.1 243.0 L290.8 240.8 L293.0 239.1 L291.5 235.3 L295.6 233.6 L297.2 237.3 L300.0 237.0 L302.8 237.3 L304.4 233.6 L308.5 235.3 L307.0 239.1 L309.2 240.8 L310.9 243.0 L314.7 241.5 L316.4 245.6 L312.7 247.2Z" style="fill: var(--on-charcoal);"></path><circle cx="300" cy="250" r="6" style="fill: var(--graphite);"></circle><rect y="320" width="600" height="6" style="fill: var(--black);"></rect></svg>
SVG;
}
