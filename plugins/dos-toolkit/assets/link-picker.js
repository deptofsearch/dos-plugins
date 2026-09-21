/**
 * Choosing a destination page by searching for it.
 *
 * Replaces a dropdown that could not hold a real site's worth of pages. The
 * field stays usable with the script switched off: whatever is typed is
 * resolved on the server the same way the import panel resolves a
 * destination, so an ID, a URL, a path or an exact title all work without
 * this file running at all.
 */
( function () {
	'use strict';

	var config = window.dosLinkPicker || {};
	var strings = config.strings || {};

	var MIN_CHARS = 2;
	var DEBOUNCE = 250;

	function request( term, done, fail ) {
		var body = new URLSearchParams();

		body.append( 'action', 'dos_links_search_targets' );
		body.append( 'nonce', config.nonce );
		body.append( 'term', term );

		fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( payload ) {
				if ( ! payload || ! payload.success ) {
					fail();
					return;
				}

				done( payload.data.results || [] );
			} )
			.catch( fail );
	}

	function setup( picker ) {
		var field = picker.querySelector( 'input[type="search"]' );
		var value = picker.querySelector( '[data-dos-picker-value]' );
		var list = picker.querySelector( '[data-dos-picker-results]' );
		var chosen = picker.querySelector( '[data-dos-picker-chosen]' );

		if ( ! field || ! value || ! list || ! chosen ) {
			return;
		}

		var timer = null;
		var results = [];
		var active = -1;

		function close() {
			list.hidden = true;
			list.innerHTML = '';
			active = -1;
		}

		function choose( row ) {
			value.value = row.id;
			field.value = row.title;

			chosen.hidden = false;
			chosen.textContent = strings.chosen + ': ' + row.title + ' (#' + row.id + ')';

			close();
		}

		function highlight( next ) {
			var items = list.querySelectorAll( '.dos-picker-result' );

			if ( ! items.length ) {
				return;
			}

			active = ( next + items.length ) % items.length;

			items.forEach( function ( item, index ) {
				item.classList.toggle( 'is-active', index === active );
			} );

			items[ active ].scrollIntoView( { block: 'nearest' } );
		}

		function render( rows ) {
			results = rows;
			list.innerHTML = '';
			active = -1;

			if ( ! rows.length ) {
				list.innerHTML = '<p class="dos-picker-empty"></p>';
				list.firstChild.textContent = strings.none;
				list.hidden = false;
				return;
			}

			rows.forEach( function ( row, index ) {
				var item = document.createElement( 'button' );

				item.type = 'button';
				item.className = 'dos-picker-result';
				item.dataset.index = index;

				var title = document.createElement( 'strong' );
				title.textContent = row.title;
				item.appendChild( title );

				var meta = document.createElement( 'span' );
				meta.className = 'dos-picker-meta';
				meta.textContent = row.type + ' · ' + row.url + ' · #' + row.id;
				item.appendChild( meta );

				item.addEventListener( 'click', function () {
					choose( row );
				} );

				list.appendChild( item );
			} );

			list.hidden = false;
		}

		function search() {
			var term = field.value.trim();

			if ( term.length < MIN_CHARS ) {
				close();
				return;
			}

			list.innerHTML = '<p class="dos-picker-empty"></p>';
			list.firstChild.textContent = strings.searching;
			list.hidden = false;

			request( term, render, function () {
				list.innerHTML = '<p class="dos-picker-empty"></p>';
				list.firstChild.textContent = strings.failed;
				list.hidden = false;
			} );
		}

		field.addEventListener( 'input', function () {
			// Editing the text after picking must drop the pick. Otherwise the
			// field reads as one page while the form carries another, and the
			// rule is saved pointing somewhere the operator never chose.
			value.value = '';
			chosen.hidden = true;

			window.clearTimeout( timer );
			timer = window.setTimeout( search, DEBOUNCE );
		} );

		field.addEventListener( 'keydown', function ( event ) {
			if ( list.hidden ) {
				return;
			}

			if ( 'ArrowDown' === event.key ) {
				event.preventDefault();
				highlight( active + 1 );
			} else if ( 'ArrowUp' === event.key ) {
				event.preventDefault();
				highlight( active - 1 );
			} else if ( 'Enter' === event.key && active > -1 ) {
				// Only swallow Enter when a result is actually highlighted, so
				// a pasted URL still submits the form as it would otherwise.
				event.preventDefault();
				choose( results[ active ] );
			} else if ( 'Escape' === event.key ) {
				close();
			}
		} );

		document.addEventListener( 'click', function ( event ) {
			if ( ! picker.contains( event.target ) ) {
				close();
			}
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '[data-dos-picker]' ).forEach( setup );
	} );
}() );
