<?php
/**
 * Best Lenders city search: name matching, state disambiguation, and the
 * zero-lender header line. Pure functions, no WordPress needed.
 */

define( 'ABSPATH', '/tmp/' );
require dirname( __DIR__ ) . '/plugins/dos-best-lenders/includes/class-search.php';

use BLNM\Search;

$pass = 0;
$fail = 0;
function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS  $label\n"; } else { $fail++; echo "  FAIL  $label\n"; }
}

$rows = array(
	array( 'n' => 'Seattle',     's' => 'WA', 'u' => '/seattle-wa/' ),
	array( 'n' => 'Portland',    's' => 'OR', 'u' => '/portland-or/' ),
	array( 'n' => 'Portland',    's' => 'ME', 'u' => '/portland-me/' ),
	array( 'n' => 'St. Helens',  's' => 'OR', 'u' => '/st-helens-or/' ),
	array( 'n' => 'Kennewick',   's' => 'WA', 'u' => '/kennewick-wa/' ),
);

$u = function ( $q ) use ( $rows ) { $r = Search::resolve_unique( $rows, $q ); return $r ? $r['u'] : null; };

check( 'plain name', '/seattle-wa/' === $u( 'Seattle' ) );
check( 'extra spaces and case', '/seattle-wa/' === $u( '  sEATTLE  ' ) );
check( 'name and state code', '/seattle-wa/' === $u( 'seattle wa' ) );
check( 'name, full state name', '/seattle-wa/' === $u( 'Seattle, Washington' ) );
check( 'Saint folds to St', '/st-helens-or/' === $u( 'Saint Helens' ) );
check( 'St. with period', '/st-helens-or/' === $u( 'St. Helens' ) );
check( 'unique prefix', '/kennewick-wa/' === $u( 'Kenne' ) );
check( 'ambiguous name is not resolved', null === $u( 'Portland' ) );
check( 'ambiguous name lists both states', 2 === count( Search::match( $rows, 'Portland' ) ) );
check( 'state disambiguates', '/portland-me/' === $u( 'Portland, ME' ) );
check( 'no match', null === $u( 'Nowhereville' ) );
check( 'empty query', array() === Search::match( $rows, '   ' ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
