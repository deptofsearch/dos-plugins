/**
 * "Best-Of Layout" box on the page edit screen: one row per pick with its
 * current photo choice and Choose photo / No photo / Use automatic buttons.
 * The JSON textarea stays the single source of truth; the buttons only set or
 * clear picks[i].photo_id / picks[i].no_photo in it, and the page saves it as
 * usual.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var $box = $( '#saab-bestof-photos' );
		var $json = $( '#saab-bestof-json' );
		if ( ! $box.length || ! $json.length ) {
			return;
		}

		var thumbs = $box.data( 'thumbs' ) || {};
		var frame = null;
		var target = -1;

		function read() {
			try {
				var config = JSON.parse( $json.val() || '' );
				return config && Array.isArray( config.picks ) ? config : null;
			} catch ( e ) {
				return null;
			}
		}

		function write( config ) {
			$json.val( JSON.stringify( config, null, 4 ) );
		}

		function render() {
			var config = read();
			$box.empty();
			if ( ! config ) {
				$box.append( $( '<p>' ).text( 'Add picks in the JSON below to choose their photos here.' ) );
				return;
			}

			$box.append( $( '<p>' ).html( '<strong>My Picks photos.</strong> "Automatic" uses the photo of the dish you had there, or of a review post about it. Choose a photo, or remove it with "No photo", for this page only.' ) );

			var $list = $( '<table class="widefat striped" style="max-width:720px">' );
			config.picks.forEach( function ( pick, i ) {
				var none = !! pick.no_photo;
				var id = none ? 0 : ( parseInt( pick.photo_id, 10 ) || 0 );
				var $thumb = none
					? $( '<span>' ).text( 'No photo' ).css( { color: '#b32d2e', fontWeight: 600 } )
					: id && thumbs[ id ]
						? $( '<img alt="">' ).attr( 'src', thumbs[ id ] ).css( { width: 64, height: 64, objectFit: 'cover', borderRadius: 4, display: 'block' } )
						: $( '<span>' ).text( id ? 'Chosen photo' : 'Automatic' ).css( { color: '#646970' } );

				var $choose = $( '<button type="button" class="button">' ).text( id ? 'Change photo' : 'Choose photo' ).on( 'click', function () {
					open( i );
				} );
				var $remove = $( '<button type="button" class="button-link button-link-delete">' ).text( 'No photo' ).css( { marginLeft: 10 } ).on( 'click', function () {
					var c = read();
					if ( c && c.picks[ i ] ) {
						delete c.picks[ i ].photo_id;
						c.picks[ i ].no_photo = true;
						write( c );
						render();
					}
				} );
				var $reset = $( '<button type="button" class="button-link">' ).text( 'Use automatic' ).css( { marginLeft: 10 } ).on( 'click', function () {
					var c = read();
					if ( c && c.picks[ i ] ) {
						delete c.picks[ i ].photo_id;
						delete c.picks[ i ].no_photo;
						write( c );
						render();
					}
				} );

				$list.append(
					$( '<tr>' ).append(
						$( '<td style="width:72px;vertical-align:middle">' ).append( $thumb ),
						$( '<td style="vertical-align:middle">' ).append( $( '<strong>' ).text( pick.name || '(unnamed pick)' ), pick.dish ? $( '<span>' ).text( ' · ' + pick.dish ) : '' ),
						$( '<td style="vertical-align:middle;text-align:right;white-space:nowrap">' ).append( $choose, none ? '' : $remove, ( id || none ) ? $reset : '' )
					)
				);
			} );
			$box.append( $list );
			$box.append( $( '<p class="description">' ).text( 'Photo changes are saved when you click Update.' ) );
		}

		function open( i ) {
			target = i;
			if ( ! frame ) {
				frame = wp.media( {
					title: 'Choose a photo for this pick',
					button: { text: 'Use this photo' },
					library: { type: 'image' },
					multiple: false
				} );
				frame.on( 'select', function () {
					var att = frame.state().get( 'selection' ).first().toJSON();
					var c = read();
					if ( ! c || ! c.picks[ target ] ) {
						return;
					}
					c.picks[ target ].photo_id = att.id;
					delete c.picks[ target ].no_photo;
					thumbs[ att.id ] = ( att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url );
					write( c );
					render();
				} );
			}
			frame.open();
		}

		var timer = null;
		$json.on( 'input', function () {
			clearTimeout( timer );
			timer = setTimeout( render, 400 );
		} );

		render();
	} );
}( jQuery ) );
