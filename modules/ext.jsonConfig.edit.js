( function () {
	function openErrorWindow( error ) {
		const errorMessage = mw.message( 'jsonconfig-edit-dialog-error', error );
		OO.ui.alert(
			new OO.ui.HtmlSnippet( errorMessage.parse() ),
			{
				title: mw.msg( 'jsonconfig-edit-button-label' )
			}
		);
	}

	const editDialog = new mw.JsonConfig.JsonEditDialog();

	const windowManager = OO.ui.getWindowManager();
	windowManager.addWindows( [ editDialog ] );

	const openDialogButton = new OO.ui.ButtonWidget( {
		label: mw.msg( 'jsonconfig-edit-button-label' )
	} );
	openDialogButton.on( 'click', () => {
		// eslint-disable-next-line no-jquery/no-global-selector
		const $textbox = $( '#wpTextbox1' );

		let contents;
		try {
			contents = JSON.parse( $textbox.textSelection( 'getContents' ) );
		} catch ( error ) {
			openErrorWindow( error );
		}

		windowManager.openWindow( 'jsonEdit', contents ).closed.then( ( data ) => {
			if ( data.error ) {
				openErrorWindow( data.error );
			} else if ( data.action === 'apply' ) {
				$textbox.textSelection(
					'setContents',
					JSON.stringify( data.json, null, '    ' )
				);
			}
		} );
	} );

	// eslint-disable-next-line no-jquery/no-global-selector
	$( '#mw-content-text' ).prepend( openDialogButton.$element );
}() );
