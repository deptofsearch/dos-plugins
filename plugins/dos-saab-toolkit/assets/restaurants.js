/**
 * Front-end behaviour for the saab_restaurant singular page: fill in the
 * "open now" spots from the first location's hours, highlight today's row
 * in the hours table, and drive the menu accordion + search. Plain DOM, no
 * jQuery — the theme doesn't guarantee it's loaded.
 */
( function () {
	'use strict';

	var DAYS = [ 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' ];

	document.addEventListener( 'DOMContentLoaded', function () {
		initOpenNow();
		initVisitStatus();
		initHoursHighlight();
		initMenuAccordion();
		initMenuSearch();
		initVisits();
		initPhotoLightbox();
	} );

	/* -----------------------------------------------------------------
	 * Open now / closed
	 * ----------------------------------------------------------------- */

	/**
	 * Reads a saab_locations[0].hours object and today's date, and returns
	 * whether the place is open right now, or null if today's ranges can't
	 * be parsed at all (a header/aside status is hidden rather than guess).
	 */
	function computeStatus( hours ) {
		var now = new Date();
		var today = DAYS[ now.getDay() ];
		var ranges = hours[ today ];

		if ( ! ranges || ! ranges.length ) {
			return null; // No entry for today at all: say nothing rather than guess.
		}

		// A single "Closed" entry for the day.
		if ( 1 === ranges.length && /^closed$/i.test( ranges[ 0 ].trim() ) ) {
			return { open: false, ranges: ranges };
		}

		if ( 1 === ranges.length && /open\s*24\s*hours/i.test( ranges[ 0 ] ) ) {
			return { open: true, ranges: ranges };
		}

		var open = false;
		var sawParsable = false;

		for ( var i = 0; i < ranges.length; i++ ) {
			var parsed = parseRange( ranges[ i ], now );
			if ( ! parsed ) {
				continue;
			}
			sawParsable = true;
			if ( now >= parsed.start && now <= parsed.end ) {
				open = true;
			}
		}

		if ( ! sawParsable ) {
			return null; // Nothing in today's ranges parsed: hide rather than guess.
		}

		return { open: open, ranges: ranges };
	}

	function initOpenNow() {
		var span = document.querySelector( '.saab-restaurant__open-now' );
		if ( ! span ) {
			return;
		}

		var hours = parseJSONAttr( span.getAttribute( 'data-hours' ) );
		if ( ! hours ) {
			return;
		}

		var status = computeStatus( hours );
		if ( ! status ) {
			return;
		}

		span.hidden = false;
		span.setAttribute( 'data-state', status.open ? 'open' : 'closed' );
		span.textContent = status.open ? 'Open now' : 'Closed now';
	}

	/**
	 * The "Visit" panel's Today status line: open/closed plus "Today
	 * <hours>", built client-side for the same time-zone reason as
	 * initOpenNow(). Hidden (via the markup's `hidden` attribute) if today's
	 * hours don't parse.
	 */
	function initVisitStatus() {
		var line = document.querySelector( '.saab-restaurant__visit-status' );
		if ( ! line ) {
			return;
		}

		var hours = parseJSONAttr( line.getAttribute( 'data-hours' ) );
		if ( ! hours ) {
			return;
		}

		var status = computeStatus( hours );
		if ( ! status ) {
			return;
		}

		line.hidden = false;
		line.setAttribute( 'data-state', status.open ? 'open' : 'closed' );
		line.innerHTML = '<span class="saab-restaurant__visit-status-label">' +
			( status.open ? 'Open now' : 'Closed now' ) + '</span> · Today ' +
			escapeHTML( status.ranges.join( ', ' ) );
	}

	/**
	 * "11 AM–9 PM", "5–10:30 PM", "10:30 AM–12 PM". The en dash (–) is what
	 * the source data uses, not a hyphen. Returns {start, end} Date objects
	 * on today, or null if the string doesn't match the expected shape.
	 */
	function parseRange( range, today ) {
		var parts = range.split( '–' ); // en dash
		if ( 2 !== parts.length ) {
			return null;
		}

		var end = parseTime( parts[ 1 ].trim(), null );
		if ( ! end ) {
			return null;
		}

		var start = parseTime( parts[ 0 ].trim(), end.meridiem );
		if ( ! start ) {
			return null;
		}

		var startDate = new Date( today.getFullYear(), today.getMonth(), today.getDate(), start.hour, start.minute );
		var endDate = new Date( today.getFullYear(), today.getMonth(), today.getDate(), end.hour, end.minute );

		// A close time earlier than the open time (e.g. "5 PM–1 AM") runs past midnight.
		if ( endDate <= startDate ) {
			endDate.setDate( endDate.getDate() + 1 );
		}

		return { start: startDate, end: endDate };
	}

	/**
	 * Parses "11", "11 AM", "10:30 PM". When no AM/PM is present (the start
	 * half of a range like "5–10:30 PM"), falls back to the meridiem carried
	 * over from the end half, flipping to the earlier one of the day if the
	 * hour would otherwise be later than the end time.
	 */
	function parseTime( text, fallbackMeridiem ) {
		var match = /^([0-9]{1,2})(?::([0-9]{2}))?\s*(AM|PM)?$/i.exec( text );
		if ( ! match ) {
			return null;
		}

		var hour = parseInt( match[ 1 ], 10 );
		var minute = match[ 2 ] ? parseInt( match[ 2 ], 10 ) : 0;
		var meridiem = match[ 3 ] ? match[ 3 ].toUpperCase() : fallbackMeridiem;

		if ( ! meridiem ) {
			return null; // Nothing to infer from, e.g. an end time with no AM/PM at all.
		}

		if ( 'AM' === meridiem && 12 === hour ) {
			hour = 0;
		} else if ( 'PM' === meridiem && 12 !== hour ) {
			hour += 12;
		}

		return { hour: hour, minute: minute, meridiem: meridiem };
	}

	function parseJSONAttr( value ) {
		if ( ! value ) {
			return null;
		}
		try {
			return JSON.parse( value );
		} catch ( e ) {
			return null;
		}
	}

	function escapeHTML( text ) {
		var div = document.createElement( 'div' );
		div.textContent = text;
		return div.innerHTML;
	}

	/* -----------------------------------------------------------------
	 * Hours table: highlight today's row
	 * ----------------------------------------------------------------- */

	/**
	 * The server can't trust the visitor's time zone, so the hours table is
	 * rendered with every day un-highlighted and this fills in today's row
	 * once the browser's own clock is available.
	 */
	function initHoursHighlight() {
		var table = document.querySelector( '.saab-restaurant__hours' );
		if ( ! table ) {
			return;
		}

		var today = DAYS[ new Date().getDay() ];
		var row = table.querySelector( 'tr[data-day="' + today + '"]' );
		if ( row ) {
			row.classList.add( 'saab-restaurant__hours-row--today' );
		}
	}

	/* -----------------------------------------------------------------
	 * Menu accordion: one section open at a time
	 * ----------------------------------------------------------------- */

	var menuSearching = false;

	function initMenuAccordion() {
		var sections = document.querySelectorAll( '.saab-restaurant__menu-section' );

		sections.forEach( function ( section ) {
			section.addEventListener( 'toggle', function () {
				// While a search is showing results, several sections may be open.
				if ( ! section.open || menuSearching ) {
					return;
				}
				sections.forEach( function ( other ) {
					if ( other !== section ) {
						other.open = false;
					}
				} );
			} );
		} );
	}

	/* -----------------------------------------------------------------
	 * Menu search
	 * ----------------------------------------------------------------- */

	function initMenuSearch() {
		var input = document.querySelector( '.saab-restaurant__menu-search' );
		if ( ! input ) {
			return;
		}

		var sections = document.querySelectorAll( '.saab-restaurant__menu-section' );

		input.addEventListener( 'input', function () {
			var query = input.value.trim().toLowerCase();
			menuSearching = '' !== query;

			sections.forEach( function ( section ) {
				var items = section.querySelectorAll( '.saab-restaurant__menu-item' );
				var visibleCount = 0;

				items.forEach( function ( item ) {
					var text = item.textContent.toLowerCase();
					var matches = '' === query || -1 !== text.indexOf( query );
					item.hidden = ! matches;
					if ( matches ) {
						visibleCount++;
					}
				} );

				section.hidden = 0 === visibleCount;
				if ( '' !== query ) {
					section.open = visibleCount > 0;
				}
			} );

			// Search cleared: back to the first section open, the rest closed.
			if ( '' === query ) {
				sections.forEach( function ( section, i ) {
					section.open = 0 === i;
				} );
			}
		} );
	}

	/* -----------------------------------------------------------------
	 * Visits: show the two most recent posts, fold the rest
	 * ----------------------------------------------------------------- */

	function initVisits() {
		var section = document.querySelector( '.saab-restaurant__visits' );
		var button = section && section.querySelector( '.saab-restaurant__visits-toggle' );
		if ( ! button ) {
			return;
		}

		var VISIBLE = 2;
		var items = section.querySelectorAll( '.saab-restaurant__year li' );
		var years = section.querySelectorAll( '.saab-restaurant__year' );
		if ( items.length <= VISIBLE ) {
			return;
		}

		var moreLabel = 'Show all ' + items.length + ' posts';

		function setExpanded( expanded ) {
			items.forEach( function ( item, i ) {
				item.hidden = ! expanded && i >= VISIBLE;
			} );
			// A year whose posts are all folded away hides with them.
			years.forEach( function ( year ) {
				year.hidden = ! year.querySelector( 'li:not([hidden])' );
			} );
			button.setAttribute( 'aria-expanded', expanded ? 'true' : 'false' );
			button.textContent = expanded ? 'Show fewer' : moreLabel;
		}

		button.hidden = false;
		button.addEventListener( 'click', function () {
			setExpanded( 'true' !== button.getAttribute( 'aria-expanded' ) );
		} );
		setExpanded( false );
	}

	/* -----------------------------------------------------------------
	 * Photo lightbox: strip photos, "See all", and dish card photos all
	 * open the same dialog at a shared index into the JSON photo blob. No
	 * library — a small manual focus trap and touch-swipe instead.
	 * ----------------------------------------------------------------- */

	function initPhotoLightbox() {
		var dialog = document.getElementById( 'saab-restaurant-lightbox' );
		var dataScript = document.querySelector( '.saab-restaurant__photos-data' );
		var triggers = document.querySelectorAll(
			'.saab-restaurant__photo-strip-link, .saab-restaurant__photo-strip-more, .saab-restaurant__card-photo-link'
		);

		if ( ! dialog || ! dataScript || ! triggers.length ) {
			return;
		}

		var photos = parseJSONAttr( dataScript.textContent );
		if ( ! photos || ! photos.length ) {
			return;
		}

		// Themify Builder can put transforms on row/column wrappers, and a
		// transformed ancestor turns position: fixed into position: absolute.
		// Hang the dialog off <body> so it always covers the viewport.
		document.body.appendChild( dialog );

		var image = dialog.querySelector( '.saab-restaurant__lightbox-image' );
		var captionText = dialog.querySelector( '.saab-restaurant__lightbox-caption-text' );
		var link = dialog.querySelector( '.saab-restaurant__lightbox-link' );
		var counter = dialog.querySelector( '.saab-restaurant__lightbox-counter' );
		var closeButton = dialog.querySelector( '.saab-restaurant__lightbox-close' );
		var prevButton = dialog.querySelector( '.saab-restaurant__lightbox-prev' );
		var nextButton = dialog.querySelector( '.saab-restaurant__lightbox-next' );

		var currentIndex = 0;
		var lastTrigger = null;
		var touchStartX = null;
		var preloaded = {};

		function preload( index ) {
			if ( preloaded[ index ] ) {
				return;
			}
			preloaded[ index ] = true;
			var warm = new Image();
			warm.src = photos[ index ].src;
		}

		function render( index ) {
			currentIndex = ( index + photos.length ) % photos.length;
			var photo = photos[ currentIndex ];

			image.src = photo.src;
			image.srcset = photo.srcset || '';
			image.alt = photo.alt || '';
			captionText.textContent = photo.caption || '';
			link.href = photo.url || '#';
			counter.textContent = ( currentIndex + 1 ) + ' / ' + photos.length;

			// Preload only the current and next image, not the whole set.
			preload( currentIndex );
			preload( ( currentIndex + 1 ) % photos.length );
		}

		function open( index, trigger ) {
			lastTrigger = trigger || null;
			render( index );
			dialog.hidden = false;
			document.body.classList.add( 'saab-restaurant-lightbox-open' );
			closeButton.focus();
			document.addEventListener( 'keydown', onKeydown );
			dialog.addEventListener( 'touchstart', onTouchStart, { passive: true } );
			dialog.addEventListener( 'touchend', onTouchEnd, { passive: true } );
		}

		function close() {
			dialog.hidden = true;
			document.body.classList.remove( 'saab-restaurant-lightbox-open' );
			document.removeEventListener( 'keydown', onKeydown );
			dialog.removeEventListener( 'touchstart', onTouchStart );
			dialog.removeEventListener( 'touchend', onTouchEnd );
			if ( lastTrigger ) {
				lastTrigger.focus();
			}
		}

		function onKeydown( e ) {
			if ( 'Escape' === e.key ) {
				close();
			} else if ( 'ArrowLeft' === e.key ) {
				render( currentIndex - 1 );
			} else if ( 'ArrowRight' === e.key ) {
				render( currentIndex + 1 );
			} else if ( 'Tab' === e.key ) {
				trapFocus( e );
			}
		}

		// Keeps Tab/Shift+Tab cycling among the dialog's own controls while it's open.
		function trapFocus( e ) {
			var focusable = dialog.querySelectorAll( 'button, a[href]' );
			if ( ! focusable.length ) {
				return;
			}
			var first = focusable[ 0 ];
			var last = focusable[ focusable.length - 1 ];

			if ( e.shiftKey && document.activeElement === first ) {
				e.preventDefault();
				last.focus();
			} else if ( ! e.shiftKey && document.activeElement === last ) {
				e.preventDefault();
				first.focus();
			}
		}

		function onTouchStart( e ) {
			touchStartX = e.changedTouches[ 0 ].clientX;
		}

		function onTouchEnd( e ) {
			if ( null === touchStartX ) {
				return;
			}
			var deltaX = e.changedTouches[ 0 ].clientX - touchStartX;
			touchStartX = null;

			if ( Math.abs( deltaX ) < 40 ) {
				return; // Too short to count as a swipe.
			}
			render( deltaX > 0 ? currentIndex - 1 : currentIndex + 1 );
		}

		triggers.forEach( function ( trigger ) {
			trigger.addEventListener( 'click', function ( e ) {
				// Without JS these are plain links to the full-size image; once
				// this runs, every click opens the lightbox instead.
				e.preventDefault();
				var index = parseInt( trigger.getAttribute( 'data-photo-index' ), 10 ) || 0;
				open( index, trigger );
			} );
		} );

		closeButton.addEventListener( 'click', close );
		prevButton.addEventListener( 'click', function () { render( currentIndex - 1 ); } );
		nextButton.addEventListener( 'click', function () { render( currentIndex + 1 ); } );
		dialog.addEventListener( 'click', function ( e ) {
			if ( e.target === dialog ) {
				close();
			}
		} );
	}
} )();
