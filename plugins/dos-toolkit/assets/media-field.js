( function ( $ ) {
	'use strict';

	var strings = window.dosMediaField || {};

	function sprintf( template, a, b ) {
		return String( template ).replace( '%1$d', a ).replace( '%2$d', b );
	}

	function update( field, attachment ) {
		var input   = field.find( '.dos-media-id' );
		var preview = field.find( '.dos-media-preview' );
		var meta    = field.find( '.dos-media-meta' );
		var remove  = field.find( '.dos-media-remove' );
		var choose  = field.find( '.dos-media-choose' );

		field.find( '.dos-media-warning' ).remove();

		if ( ! attachment ) {
			input.val( '' );
			preview.empty();
			meta.empty();
			remove.attr( 'hidden', 'hidden' );
			choose.text( strings.choose || 'Choose image' );

			return;
		}

		// The medium size is what the preview wants; fall back to whatever
		// the attachment actually has, since not every upload has every size.
		var sizes = attachment.sizes || {};
		var thumb = ( sizes.medium || sizes.thumbnail || sizes.full || {} ).url || attachment.url;

		input.val( attachment.id );
		preview.html( $( '<img>' ).attr( { src: thumb, alt: '' } ) );
		remove.removeAttr( 'hidden' );
		choose.text( strings.change || 'Change image' );

		meta.text( attachment.width + '×' + attachment.height );

		var minWidth  = parseInt( field.attr( 'data-min-width' ), 10 ) || 0;
		var minHeight = parseInt( field.attr( 'data-min-height' ), 10 ) || 0;

		if ( minWidth && attachment.width < minWidth ) {
			meta.append(
				$( '<span>' )
					.addClass( 'dos-media-warning' )
					.text( ' ' + sprintf( strings.small || '', minWidth, minHeight ) )
			);
		}
	}

	$( document ).on( 'click', '.dos-media-choose', function ( event ) {
		event.preventDefault();

		var field = $( this ).closest( '.dos-media-field' );
		var frame = wp.media( {
			title: strings.title || 'Choose an image',
			button: { text: strings.button || 'Use this image' },
			library: { type: 'image' },
			multiple: false
		} );

		frame.on( 'select', function () {
			update( field, frame.state().get( 'selection' ).first().toJSON() );
		} );

		frame.open();
	} );

	$( document ).on( 'click', '.dos-media-remove', function ( event ) {
		event.preventDefault();

		update( $( this ).closest( '.dos-media-field' ), null );
	} );
}( jQuery ) );
