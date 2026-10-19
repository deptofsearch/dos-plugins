/* DoS Shot and a Beer Home: search suggestions. The matching rules mirror Search::match() in includes/class-search.php; keep the two in step. */
( function () {
	'use strict';

	var ORDER = [ 'r', 'c', 'd', 'x' ];
	var PER_GROUP = 5;

	function fold( s ) {
		s = String( s || '' ).replace( /[‘’`]/g, "'" );
		if ( s.normalize ) {
			s = s.normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' );
		}
		return s.toLowerCase().replace( /\s+/g, ' ' ).trim();
	}

	function match( index, q, scope, per ) {
		per = Math.max( 1, per || PER_GROUP );
		q = fold( q );
		if ( q.length < 2 ) {
			return [];
		}
		var by = {};
		index.forEach( function ( e ) {
			if ( scope && e.g !== scope ) {
				return;
			}
			var n = fold( e.n );
			var pos = n.indexOf( q );
			if ( pos < 0 ) {
				return;
			}
			var rank = pos === 0 ? 0 : ( n.charAt( pos - 1 ) === ' ' ? 1 : 2 );
			( by[ e.g ] = by[ e.g ] || [] ).push( { r: rank, e: e } );
		} );
		var out = [];
		ORDER.forEach( function ( g ) {
			if ( ! by[ g ] ) {
				return;
			}
			by[ g ].sort( function ( a, b ) {
				return ( a.r - b.r ) || ( b.e.k - a.e.k ) || ( a.e.n < b.e.n ? -1 : a.e.n > b.e.n ? 1 : 0 );
			} );
			out.push( { key: g, total: by[ g ].length, items: by[ g ].slice( 0, per ).map( function ( x ) { return x.e; } ) } );
		} );
		return out;
	}

	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) {
			n.className = cls;
		}
		if ( text !== undefined ) {
			n.textContent = text;
		}
		return n;
	}

	function init( form ) {
		var dataEl = form.querySelector( '.saab-search__data' );
		var input = form.querySelector( '.saab-search__input' );
		var list = form.querySelector( '.saab-search__list' );
		if ( ! dataEl || ! input || ! list ) {
			return;
		}
		var data;
		try {
			data = JSON.parse( dataEl.textContent );
		} catch ( err ) {
			return;
		}
		var index = data.index || [];
		var labels = data.groups || {};
		var scope = '';
		var options = [];
		var active = -1;
		var root = form.closest( '.saab-hero' ) || document;
		var scopes = root.querySelectorAll( '.saab-scope' );
		var loading = false;

		// The page carries the terms only; every review's dish comes from data.full the first time the box is used.
		function loadFull() {
			if ( loading || ! data.full || ! window.fetch ) {
				return;
			}
			loading = true;
			fetch( data.full, { credentials: 'same-origin' } )
				.then( function ( r ) {
					return r.ok ? r.json() : null;
				} )
				.then( function ( full ) {
					if ( Array.isArray( full ) && full.length ) {
						index = full;
						if ( ! list.hidden ) {
							render();
						}
					}
				} )
				.catch( function () {} );
		}

		function setScope( key ) {
			scope = key || '';
			Array.prototype.forEach.call( scopes, function ( o ) {
				o.setAttribute( 'aria-pressed', ( o.getAttribute( 'data-scope' ) || '' ) === scope ? 'true' : 'false' );
			} );
			if ( data.ph && data.ph[ scope ] ) {
				input.placeholder = data.ph[ scope ];
			}
			render();
			input.focus();
		}

		function close() {
			list.hidden = true;
			input.setAttribute( 'aria-expanded', 'false' );
			input.removeAttribute( 'aria-activedescendant' );
			active = -1;
		}

		function setActive( i ) {
			options.forEach( function ( o, j ) {
				o.setAttribute( 'aria-selected', j === i ? 'true' : 'false' );
			} );
			active = i;
			if ( options[ i ] ) {
				input.setAttribute( 'aria-activedescendant', options[ i ].id );
				options[ i ].scrollIntoView( { block: 'nearest' } );
			}
		}

		function render() {
			var q = input.value;
			list.textContent = '';
			options = [];
			active = -1;
			if ( fold( q ).length < 2 ) {
				close();
				return;
			}
			var groups = match( index, q, scope, scope ? data.perScope : data.per );
			groups.forEach( function ( g ) {
				list.appendChild( el( 'div', 'saab-search__group', labels[ g.key ] || '' ) );
				g.items.forEach( function ( e ) {
					var a = el( 'a', 'saab-search__opt' );
					a.href = e.u;
					a.id = 'saab-opt-' + options.length;
					a.setAttribute( 'role', 'option' );
					a.setAttribute( 'aria-selected', 'false' );
					a.appendChild( el( 'span', 'saab-search__name', e.n ) );
					a.appendChild( el( 'span', 'saab-search__sub', e.s ) );
					list.appendChild( a );
					options.push( a );
				} );
				// More than fit under Everything: a row that switches to just this kind, which lists them all.
				if ( ! scope && g.total > g.items.length ) {
					var more = el( 'a', 'saab-search__opt saab-search__more' );
					more.href = '#';
					more.id = 'saab-opt-' + options.length;
					more.setAttribute( 'role', 'option' );
					more.setAttribute( 'aria-selected', 'false' );
					more.setAttribute( 'data-scope', g.key );
					more.appendChild( el( 'span', 'saab-search__name', String( data.all || '%1$d %2$s' ).replace( '%1$d', g.total ).replace( '%2$s', String( labels[ g.key ] || '' ).toLowerCase() ) + ' →' ) );
					more.addEventListener( 'click', function ( ev ) {
						ev.preventDefault();
						setScope( g.key );
					} );
					list.appendChild( more );
					options.push( more );
				}
			} );
			if ( ! groups.length ) {
				list.appendChild( el( 'div', 'saab-search__empty', String( data.empty || '' ).replace( '%s', q.trim() ) ) );
			}
			list.hidden = false;
			input.setAttribute( 'aria-expanded', 'true' );
		}

		input.addEventListener( 'input', function () {
			loadFull();
			render();
		} );
		input.addEventListener( 'focus', function () {
			loadFull();
			if ( input.value ) {
				render();
			}
		} );
		input.addEventListener( 'keydown', function ( ev ) {
			if ( list.hidden ) {
				return;
			}
			if ( ev.key === 'ArrowDown' && options.length ) {
				ev.preventDefault();
				setActive( ( active + 1 ) % options.length );
			} else if ( ev.key === 'ArrowUp' && options.length ) {
				ev.preventDefault();
				setActive( active <= 0 ? options.length - 1 : active - 1 );
			} else if ( ev.key === 'Enter' && options[ active ] ) {
				ev.preventDefault();
				if ( options[ active ].hasAttribute( 'data-scope' ) ) {
					setScope( options[ active ].getAttribute( 'data-scope' ) );
				} else {
					window.location.href = options[ active ].href;
				}
			} else if ( ev.key === 'Escape' ) {
				close();
			}
		} );
		document.addEventListener( 'click', function ( ev ) {
			if ( ! form.contains( ev.target ) ) {
				close();
			}
		} );

		Array.prototype.forEach.call( scopes, function ( b ) {
			b.addEventListener( 'click', function () {
				loadFull();
				setScope( b.getAttribute( 'data-scope' ) );
			} );
		} );
	}

	/* "Pick a category": fold the cards past the first few behind a Show all / Show fewer button. */
	function foldCats( section ) {
		var btn = section.querySelector( '.saab-fold' );
		var label = btn && btn.querySelector( '.saab-fold__label' );
		if ( ! btn || ! label ) {
			return;
		}
		function set( open ) {
			section.classList.toggle( 'is-folded', ! open );
			btn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			label.textContent = btn.getAttribute( open ? 'data-less' : 'data-more' );
		}
		set( false );
		btn.hidden = false;
		btn.addEventListener( 'click', function () {
			var open = btn.getAttribute( 'aria-expanded' ) !== 'true';
			set( open );
			if ( open ) {
				// Move focus to the first card that just appeared, so keyboard users land on the new content.
				var first = section.querySelector( '.saab-cats__extra a' );
				if ( first ) {
					first.focus( { preventScroll: true } );
				}
			} else if ( section.getBoundingClientRect().top < 0 ) {
				section.scrollIntoView( { block: 'start' } );
			}
		} );
	}

	function boot() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-saab-search]' ), init );
		Array.prototype.forEach.call( document.querySelectorAll( '[data-saab-fold]' ), foldCats );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
