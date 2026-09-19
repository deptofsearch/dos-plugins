( function () {
	'use strict';

	if ( typeof window.dosBatch === 'undefined' ) {
		return;
	}

	var config = window.dosBatch;

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

	function run( panel, job, dryRun, restart ) {
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

		status.textContent = config.strings.running;

		post( params.toString() ).then( function ( payload ) {
			if ( ! payload || ! payload.success ) {
				status.textContent = ( payload && payload.data && payload.data.message ) || config.strings.failed;
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
				status.textContent = config.strings.done + ' ' + data.processed + ' scanned, ' + data.changed + ' changed' + ( data.dryRun ? ' (dry run)' : '' ) + '.';
				button.disabled = false;

				return;
			}

			status.textContent = config.strings.running + ' ' + data.processed + ' / ' + data.total;

			run( panel, job, dryRun, false );
		} ).catch( function () {
			status.textContent = config.strings.failed;
			button.disabled = false;
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '.dos-job-start' );

		if ( ! button ) {
			return;
		}

		var panel  = button.closest( '.dos-job' );
		var job    = panel.getAttribute( 'data-job' );
		var dryRun = panel.querySelector( '.dos-job-dry-run' ).checked;

		// A live run of a destructive job has to be typed out. A misclick
		// should not be able to delete media on a client site.
		if ( ! dryRun && '1' === panel.getAttribute( 'data-destructive' ) ) {
			if ( 'RUN' !== window.prompt( config.strings.confirm ) ) {
				panel.querySelector( '.dos-job-status' ).textContent = config.strings.canceled;

				return;
			}
		}

		button.disabled = true;
		panel.querySelector( '.dos-job-notes' ).innerHTML = '';
		panel.querySelector( '.dos-job-bar' ).style.width = '0%';

		run( panel, job, dryRun, true );
	} );
}() );
