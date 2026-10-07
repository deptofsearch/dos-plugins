/* Register the server-rendered dos/* blocks on the client so the editors preview them via ServerSideRender. */
( function ( wp, config ) {
	if ( ! wp || ! config || ! config.names ) {
		return;
	}
	var el = wp.element.createElement;
	var ServerSideRender = wp.serverSideRender;

	config.names.forEach( function ( name ) {
		wp.blocks.registerBlockType( name, {
			edit: function ( props ) {
				return el( ServerSideRender, { block: name, attributes: props.attributes } );
			},
			save: function () {
				return null;
			},
		} );
	} );
} )( window.wp, window.dosBlocks );
