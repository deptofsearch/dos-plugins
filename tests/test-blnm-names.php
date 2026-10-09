<?php
/** Best Lenders: Render::display_name() cleans HMDA legal names. Runs without WordPress. */
define( 'ABSPATH', '/tmp/' );
error_reporting( E_ALL );
set_error_handler( static function ( $no, $str, $file, $line ) { throw new ErrorException( $str, 0, $no, $file, $line ); } );
function __( $s ) { return $s; }
require dirname( __DIR__ ) . '/plugins/dos-best-lenders/includes/class-render.php';

$fail = 0;
function check( $label, $ok ) { global $fail; echo ( $ok ? 'ok   ' : 'FAIL ' ) . $label . "\n"; $fail += $ok ? 0 : 1; }

$cases = array(
	'GUILD MORTGAGE COMPANY'                       => 'Guild Mortgage',
	'PRIMELENDING, A PLAINSCAPITAL COMPANY'        => 'PrimeLending',
	'MORTGAGE EXPRESS, LLC'                        => 'Mortgage Express',
	'GO MORTGAGE, LLC'                             => 'Go Mortgage',
	'Synergy One Lending, Inc.'                    => 'Synergy One Lending',
	'PREMIER MORTGAGE RESOURCES, L.L.C.'           => 'Premier Mortgage Resources',
	'Sierra Pacific Mortgage Company, Inc.'        => 'Sierra Pacific Mortgage',
	'LOANDEPOT.COM, LLC'                           => 'loanDepot.com',
	'NEWREZ LLC'                                   => 'NewRez',
	'JPMORGAN CHASE BANK, NATIONAL ASSOCIATION'    => 'JPMorgan Chase Bank',
	'CROSSCOUNTRY MORTGAGE, LLC'                   => 'CrossCountry Mortgage',
	'AMERISAVE MORTGAGE CORPORATION'               => 'AmeriSave Mortgage',
	'HOMESTREET BANK'                              => 'HomeStreet Bank',
	'BECU'                                         => 'BECU',
	'USAA FEDERAL SAVINGS BANK'                    => 'USAA Federal Savings Bank',
	'PNC BANK, NATIONAL ASSOCIATION'               => 'PNC Bank',
	'UWM, LLC'                                     => 'UWM',
	'FOO LENDING, A DIVISION OF BAR BANK, N.A.'    => 'Foo Lending',
	'ACME HOME LOANS DBA ACME DIRECT'              => 'Acme Home Loans',
	'ZED MORTGAGE, A TEXAS CORPORATION'            => 'Zed Mortgage',
	'A Company Mortgage'                           => 'A Company Mortgage',
);
foreach ( $cases as $in => $want ) {
	$got = BLNM\Render::display_name( $in );
	check( "$in -> $want" . ( $got === $want ? '' : " (got $got)" ), $got === $want );
}
exit( $fail ? 1 : 0 );
