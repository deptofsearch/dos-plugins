( function () {
	'use strict';

	if ( typeof window.dosBatch === 'undefined' ) {
		return;
	}

	var config = window.dosBatch;

	function format( template, a, b ) {
		return String( template ).replace( '%1$d', a ).replace( '%2$d', b );
	}

	function post( body ) {
		return fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	function run( panel, job, dryRun, restart, confirm ) {
		var status = panel.querySelector( '.dos-job-status' );
		var bar    = panel.querySelector( '.dos-job-bar' );
		var notes  = panel.querySelector( '.dos-job-notes' );
		var button = panel.querySelector( '.dos-job-start' );

		var params = new URLSearchParams();

		params.set( 'action', config.action );
		params.set( 'nonce', config.nonce );
		params.set( 'job', job );

		if ( dryRun ) {
			params.set( 'dry_run', '1' );
		}

		if ( restart ) {
			params.set( 'restart', '1' );
		}

		// The server re-checks this; sending it is not what makes the run
		// safe, it is what lets the server tell a deliberate run from a
		// forged one.
		if ( confirm ) {
			params.set( 'confirm', confirm );
		}

		status.textContent = config.strings.running;

		post( params.toString() ).then( function ( payload ) {
			if ( ! payload || ! payload.success ) {
				status.textContent = ( payload && payload.data && payload.data.message ) || config.strings.failed;
				status.classList.add( 'dos-job-refused' );
				button.disabled = false;

				return;
			}

			var data    = payload.data;
			var percent = data.total > 0 ? Math.min( 100, Math.round( ( data.processed / data.total ) * 100 ) ) : 100;

			bar.style.width = percent + '%';

			( data.notes || [] ).forEach( function ( note ) {
				var item = document.createElement( 'li' );

				item.textContent = note;
				notes.appendChild( item );
			} );

			if ( data.done ) {
				// A dry run changed nothing, and saying "changed" invited
				// people to believe it had.
				if ( data.dryRun ) {
					status.textContent = data.changed
						? format( config.strings.doneDry, data.processed, data.changed )
						: config.strings.dryNone;
				} else {
					status.textContent = format( config.strings.doneLive, data.processed, data.changed );
				}

				button.disabled = false;

				var live = panel.querySelector( '.dos-job-live' );
				var note = panel.querySelector( '.dos-job-pending' );

				if ( live ) {
					if ( data.dryRun && data.changed > 0 ) {
						live.removeAttribute( 'hidden' );

						if ( note ) {
							note.textContent = format( config.strings.doneDry, data.processed, data.changed );
							note.removeAttribute( 'hidden' );
						}
					} else {
						live.setAttribute( 'hidden', 'hidden' );

						if ( note ) {
							note.setAttribute( 'hidden', 'hidden' );
						}
					}
				}

				// These two lines were written when the page loaded. Leaving
				// them describing the previous run is how somebody confirms a
				// deletion they did not mean.
				var last = panel.querySelector( '.dos-job-last' );

				if ( last ) {
					last.textContent = format( config.strings.lastRun, data.processed, data.changed );
				}

				var guard = panel.querySelector( '.dos-job-guard' );

				if ( guard && data.destructive ) {
					if ( ! data.dryRun ) {
						guard.textContent = config.strings.stale;
					} else if ( data.changed > 0 ) {
						guard.textContent = format( config.strings.cleared, data.processed, data.changed );
					} else {
						guard.textContent = config.strings.nothing;
					}
				}

				return;
			}

			status.textContent = config.strings.running + ' ' + data.processed + ' / ' + data.total;

			run( panel, job, dryRun, false, '' );
		} ).catch( function () {
			status.textContent = config.strings.failed;
			button.disabled = false;
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '.dos-job-start, .dos-job-live' );

		if ( ! button ) {
			return;
		}

		var panel  = button.closest( '.dos-job' );
		var job    = panel.getAttribute( 'data-job' );
		var dryBox = panel.querySelector( '.dos-job-dry-run' );

		// The second button always means live, whatever the checkbox says.
		var forceLive = button.classList.contains( 'dos-job-live' );
		var dryRun    = forceLive ? false : ( dryBox ? dryBox.checked : false );

		if ( forceLive && dryBox ) {
			dryBox.checked = false;
		}

		// A live run of a destructive job has to be typed out. A misclick
		// should not be able to delete media on a client site. The server
		// enforces this again, along with requiring a recent dry run.
		var confirm = '';

		if ( ! dryRun && '1' === panel.getAttribute( 'data-destructive' ) ) {
			var phrase = panel.getAttribute( 'data-confirm-phrase' ) || 'RUN';
			var typed  = window.prompt( config.strings.confirm );

			if ( typed !== phrase ) {
				panel.querySelector( '.dos-job-status' ).textContent = config.strings.canceled;

				return;
			}

			confirm = typed;
		}

		button.disabled = true;
		panel.querySelector( '.dos-job-notes' ).innerHTML = '';
		panel.querySelector( '.dos-job-bar' ).style.width = '0%';

		var refused = panel.querySelector( '.dos-job-status' );

		if ( refused ) {
			refused.classList.remove( 'dos-job-refused' );
		}

		run( panel, job, dryRun, true, confirm );
	} );
}() );

/* Select all / none on any list that uses data-dos-check buttons. */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-dos-check]' );

		if ( ! button ) {
			return;
		}

		var form = button.closest( 'form' );

		if ( ! form ) {
			return;
		}

		var wanted = 'all' === button.getAttribute( 'data-dos-check' );

		form.querySelectorAll( '.dos-tag-check' ).forEach( function ( box ) {
			box.checked = wanted;
		} );
	} );
}() );
