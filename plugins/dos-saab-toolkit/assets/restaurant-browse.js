/**
 * Restaurant browse page: filters the server-rendered cards in place by a
 * name/dish search plus one city pill and one cuisine pill. A dish match
 * shows the matching dishes on the card. The search term is kept in ?q= so
 * a dish search can be shared as a link. Plain DOM, no jQuery.
 */
( function () {
	'use strict';

	var MAX_DISHES_SHOWN = 3;

	document.addEventListener( 'DOMContentLoaded', init );

	function init() {
		var root = document.querySelector( '.saab-browse' );
		var dataEl = document.getElementById( 'saab-browse-data' );
		if ( ! root || ! dataEl ) {
			return;
		}

		var data;
		try {
			data = JSON.parse( dataEl.textContent );
		} catch ( e ) {
			return;
		}

		var input = root.querySelector( '.saab-browse__search input' );
		var form = root.querySelector( '.saab-browse__search' );
		var status = root.querySelector( '.saab-browse__status' );
		var empty = root.querySelector( '.saab-browse__empty' );
		var clear = root.querySelector( '.saab-browse__clear' );
		var cards = Array.prototype.slice.call( root.querySelectorAll( '.saab-browse__card' ) );
		var filters = { city: '', cuisine: '' };

		// Pre-normalise every place once.
		var index = {};
		Object.keys( data ).forEach( function ( id ) {
			var place = data[ id ];
			index[ id ] = {
				text: normalise( place.t + ' ' + place.m ),
				dishes: ( place.d || [] ).map( function ( name ) {
					return { name: name, norm: normalise( name ) };
				} )
			};
		} );

		root.querySelectorAll( '.saab-browse__pills' ).forEach( function ( group ) {
			var key = group.getAttribute( 'data-group' );
			group.addEventListener( 'click', function ( event ) {
				var pill = event.target.closest( '.saab-browse__pill' );
				if ( ! pill ) {
					return;
				}
				filters[ key ] = pill.getAttribute( 'data-value' ) || '';
				group.querySelectorAll( '.saab-browse__pill' ).forEach( function ( other ) {
					other.setAttribute( 'aria-pressed', other === pill ? 'true' : 'false' );
				} );
				apply();
			} );
		} );

		if ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				apply();
			} );
		}

		if ( input ) {
			var params = new URLSearchParams( window.location.search );
			if ( params.get( 'q' ) ) {
				input.value = params.get( 'q' );
			}
			input.addEventListener( 'input', apply );
		}

		if ( clear ) {
			clear.addEventListener( 'click', function () {
				if ( input ) {
					input.value = '';
				}
				filters.city = '';
				filters.cuisine = '';
				root.querySelectorAll( '.saab-browse__pills' ).forEach( function ( group ) {
					group.querySelectorAll( '.saab-browse__pill' ).forEach( function ( pill ) {
						pill.setAttribute( 'aria-pressed', pill.getAttribute( 'data-value' ) ? 'false' : 'true' );
					} );
				} );
				apply();
				if ( input ) {
					input.focus();
				}
			} );
		}

		apply();

		function apply() {
			var query = input ? input.value : '';
			var tokens = normalise( query ).split( ' ' ).filter( Boolean );
			var shown = 0;

			cards.forEach( function ( card ) {
				var place = index[ card.getAttribute( 'data-id' ) ];
				var dishBox = card.querySelector( '.saab-browse__card-dishes' );
				var visible = !! place;
				var dishHits = [];

				if ( visible && filters.city && card.getAttribute( 'data-city' ) !== filters.city ) {
					visible = false;
				}
				if ( visible && filters.cuisine && ( ' ' + card.getAttribute( 'data-cuisines' ) + ' ' ).indexOf( ' ' + filters.cuisine + ' ' ) === -1 ) {
					visible = false;
				}

				if ( visible && tokens.length ) {
					// A dish matches when it holds every word ("birria ramen"
					// finds "Birria Ramen Bowl", not a birria taco plus a ramen).
					dishHits = place.dishes.filter( function ( dish ) {
						return hasAll( dish.norm, tokens );
					} );
					visible = hasAll( place.text, tokens ) || dishHits.length > 0;
				}

				card.hidden = ! visible;

				if ( dishBox ) {
					if ( visible && dishHits.length ) {
						renderDishes( dishBox, dishHits );
						dishBox.hidden = false;
					} else {
						dishBox.hidden = true;
						dishBox.textContent = '';
					}
				}

				if ( visible ) {
					shown++;
				}
			} );

			if ( status ) {
				status.textContent = shown === cards.length
					? 'Showing ' + shown + ' ' + ( shown === 1 ? 'place' : 'places' )
					: 'Showing ' + shown + ' of ' + cards.length + ' places';
			}
			if ( empty ) {
				empty.hidden = shown > 0;
			}

			syncUrl( query.trim() );
		}
	}

	function renderDishes( box, hits ) {
		box.textContent = 'On the menu: ';
		hits.slice( 0, MAX_DISHES_SHOWN ).forEach( function ( dish, i ) {
			if ( i > 0 ) {
				box.appendChild( document.createTextNode( ', ' ) );
			}
			var mark = document.createElement( 'mark' );
			mark.textContent = dish.name;
			box.appendChild( mark );
		} );
		if ( hits.length > MAX_DISHES_SHOWN ) {
			box.appendChild( document.createTextNode( ' +' + ( hits.length - MAX_DISHES_SHOWN ) + ' more' ) );
		}
	}

	function hasAll( haystack, tokens ) {
		for ( var i = 0; i < tokens.length; i++ ) {
			if ( haystack.indexOf( tokens[ i ] ) === -1 ) {
				return false;
			}
		}
		return true;
	}

	/** Lowercase, strip accents ("phở" finds "pho") and punctuation. */
	function normalise( text ) {
		return String( text || '' )
			.toLowerCase()
			.normalize( 'NFD' )
			.replace( /[̀-ͯ]/g, '' )
			.replace( /đ/g, 'd' )
			.replace( /[^a-z0-9]+/g, ' ' )
			.trim();
	}

	function syncUrl( query ) {
		if ( ! window.history || ! window.history.replaceState ) {
			return;
		}
		var url = new URL( window.location.href );
		if ( query ) {
			url.searchParams.set( 'q', query );
		} else {
			url.searchParams.delete( 'q' );
		}
		window.history.replaceState( null, '', url.toString() );
	}
}() );
