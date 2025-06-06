/**
 * Json Edit Dialog
 *
 * @class
 * @extends OO.ui.ProcessDialog
 *
 * @constructor
 * @param {Object} config Dialog configuration object
 */
mw.JsonConfig.JsonEditDialog = function MwJsonConfigJsonEditDialog( config ) {
	// Parent constructor
	mw.JsonConfig.JsonEditDialog.super.call( this, config );

	this.$element.addClass( 'mw-jsonConfig-jsonEditDialog' );
};

/* Setup */

OO.inheritClass( mw.JsonConfig.JsonEditDialog, OO.ui.ProcessDialog );

/* Static properties */

/**
 * @static
 * @inheritdoc
 */
mw.JsonConfig.JsonEditDialog.static.name = 'jsonEdit';

/**
 * @static
 * @inheritdoc
 */
mw.JsonConfig.JsonEditDialog.static.title = mw.msg( 'jsonconfig-edit-dialog-title' );

/**
 * @static
 * @inheritdoc
 */
mw.JsonConfig.JsonEditDialog.static.size = 'large';

/**
 * @static
 * @inheritdoc
 */
mw.JsonConfig.JsonEditDialog.static.actions = [
	{
		action: 'apply',
		label: mw.msg( 'jsonconfig-edit-action-apply' ),
		flags: [ 'primary', 'progressive' ]
	},
	{
		label: mw.msg( 'jsonconfig-edit-action-cancel' ),
		flags: 'safe'
	}
];

/**
 * @inheritdoc
 */
mw.JsonConfig.JsonEditDialog.prototype.initialize = function () {
	// Parent method
	mw.JsonConfig.JsonEditDialog.super.prototype.initialize.call( this );

	this.json = null;
	this.data = null;
	this.fieldTypes = null;

	this.sizeWarning = new OO.ui.MessageWidget( {
		type: 'warning',
		showClose: true,
		label: mw.msg( 'jsonconfig-edit-dialog-warning-data-large' )
	} );

	this.tableWidget = new mw.widgets.TableWidget( {
		showRowLabels: false,
		allowRowInsertion: true,
		allowRowDeletion: true
	} );

	this.panel = new OO.ui.PanelLayout( {
		padded: true,
		expanded: true,
		scrollable: true
	} );

	this.panel.$element.append( this.sizeWarning.$element, this.tableWidget.$element );
	this.$body.append( this.panel.$element );
};

/**
 * @inheritdoc
 */
mw.JsonConfig.JsonEditDialog.prototype.getSetupProcess = function ( data ) {
	return mw.JsonConfig.JsonEditDialog.super.prototype.getSetupProcess.call( this, data )
		.next( function () {
			this.json = data;

			try {
				this.validateJson();
			} catch ( error ) {
				// TODO: Return rejected promise after T252398 is fixed
				this.close( { error: error.message } );
				return;
			}

			this.data = this.json.data;

			const fields = this.json.schema.fields;
			const fieldNames = fields.map( ( value ) => value.name );
			this.fieldTypes = fields.map( ( value ) => value.type );

			this.sizeWarning.toggle( this.json.data.length * this.json.schema.fields.length > 5000 );

			// Insert column metadata
			for ( let i = 0; i < fieldNames.length; i++ ) {
				this.tableWidget.insertColumn( null, i, i, fieldNames[ i ] );
			}

			// Insert row data (with no metadata)
			for ( let i = 0; i < this.data.length; i++ ) {
				this.tableWidget.insertRow( this.data[ i ] );
			}
		}, this );
};

/**
 * Validate the tabular JSON object. See also JCTabularContent.php
 *
 * @private
 * @throws {Error}
 */
mw.JsonConfig.JsonEditDialog.prototype.validateJson = function () {
	const json = this.json;

	if ( !Array.isArray( json.data ) ) {
		throw new Error( mw.msg( 'jsonconfig-edit-dialog-error-data-missing' ) );
	}

	if ( !OO.isPlainObject( json.schema ) ) {
		throw new Error( mw.msg( 'jsonconfig-edit-dialog-error-schema-missing' ) );
	}

	if ( !Array.isArray( json.schema.fields ) ) {
		throw new Error( mw.msg( 'jsonconfig-edit-dialog-error-fields-missing' ) );
	}

	if (
		!json.schema.fields.every( ( field ) => typeof field.name === 'string' )
	) {
		throw new Error( mw.msg( 'jsonconfig-edit-dialog-error-field-name-missing' ) );
	}

	// TODO: Handle 'boolean' and 'localized' types
	if (
		!json.schema.fields.every( ( field ) => ( field.type === 'number' || field.type === 'string' ) )
	) {
		throw new Error( mw.msg( 'jsonconfig-edit-dialog-error-field-type-invalid' ) );
	}

	// Each data item is an array with the same length as fields
	if (
		!json.data.every( ( item ) => Array.isArray( item ) &&
				item.length === json.schema.fields.length )
	) {
		throw new Error( mw.msg( 'jsonconfig-edit-dialog-error-data-invalid' ) );
	}
};

/**
 * @inheritdoc
 */
mw.JsonConfig.JsonEditDialog.prototype.getBodyHeight = function () {
	return 500;
};

/**
 * @inheritdoc
 */
mw.JsonConfig.JsonEditDialog.prototype.getActionProcess = function ( action ) {
	switch ( action ) {
		case 'apply':
			return new OO.ui.Process( function () {
				const data = this.tableWidget.model.data;

				// Ensure data values are correct type
				// TODO: Handle 'boolean' and 'localized' types
				for ( let i = 0; i < data.length; i++ ) {
					for ( let j = 0; j < data[ i ].length; j++ ) {
						if ( this.fieldTypes[ j ] === 'number' ) {
							data[ i ][ j ] = +data[ i ][ j ];
						}
					}
				}

				this.json.data = data;

				this.close( {
					action: action,
					json: this.json
				} );
			}, this );

		default:
			return mw.JsonConfig.JsonEditDialog.super.prototype.getActionProcess.call(
				this, action
			);
	}
};

/**
 * @inheritdoc
 */
mw.JsonConfig.JsonEditDialog.prototype.getTeardownProcess = function ( data ) {
	return mw.JsonConfig.JsonEditDialog.super.prototype.getTeardownProcess.call( this, data )
		.first( function () {
			this.tableWidget.clearWithProperties();
		}, this );
};
