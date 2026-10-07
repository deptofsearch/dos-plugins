/* Tools > Outdoor Seating: batches POST /osn/v1/seo/seed until every page has been handled. */
( function () {
	'use strict';
	var box = document.getElementById( 'osn-seo-seed' );
	if ( ! box || ! window.osnSeo ) {
		return;
	}
	var btn = box.querySelector( '[data-osn-seo-run]' );
	var out = box.querySelector( '[data-osn-seo-out]' );
	var scope = box.querySelector( '[data-osn-seo-scope]' );
	var dry = box.querySelector( '[data-osn-seo-dry]' );

	function log( line ) {
		out.hidden = false;
		out.textContent += line + '\n';
		out.scrollTop = out.scrollHeight;
	}

	function run( offset, tally ) {
		return fetch( window.osnSeo.url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.osnSeo.nonce },
			body: JSON.stringify( { scope: scope.value, dry_run: dry.checked, offset: offset, limit: 50 } )
		} ).then( function ( res ) {
			return res.json().then( function ( data ) {
				if ( ! res.ok ) {
					throw new Error( data && data.message ? data.message : 'HTTP ' + res.status );
				}
				return data;
			} );
		} ).then( function ( data ) {
			[ 'set', 'kept', 'skipped' ].forEach( function ( k ) {
				tally[ k ] += data.counts[ k ] || 0;
			} );
			data.results.forEach( function ( r ) {
				log( r.action + '  /' + r.slug + '/  ' + r.title );
			} );
			if ( data.next_offset !== null ) {
				return run( data.next_offset, tally );
			}
			log( ( data.dry_run ? 'Dry run done. ' : 'Done. ' ) + tally.set + ' set, ' + tally.kept + ' kept, ' + tally.skipped + ' skipped (of ' + data.total + ').' );
		} );
	}

	btn.addEventListener( 'click', function () {
		btn.disabled = true;
		out.textContent = '';
		run( 0, { set: 0, kept: 0, skipped: 0 } ).catch( function ( e ) {
			log( 'Error: ' + e.message );
		} ).then( function () {
			btn.disabled = false;
		} );
	} );
}() );
