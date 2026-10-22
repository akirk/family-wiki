( function ( blocks, element, blockEditor, components, i18n ) {
	var el = element.createElement;
	var __ = i18n.__;

	blocks.registerBlockType( 'familypedia/highlights', {
		title: __( 'Family Highlights', 'family-wiki' ),
		description: __( 'The person of the hour, and the dates coming up next.', 'family-wiki' ),
		icon: 'star-filled',
		category: 'widgets',

		edit: function () {
			return el( 'div', blockEditor.useBlockProps(),
				el( wp.serverSideRender, {
					block: 'familypedia/highlights'
				} )
			);
		},

		save: function () {
			return null;
		}
	} );

	blocks.registerBlockType( 'familypedia/recent', {
		title: __( 'Recently Updated', 'family-wiki' ),
		description: __( 'The pages that changed last.', 'family-wiki' ),
		icon: 'backup',
		category: 'widgets',

		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			return el( 'div', blockEditor.useBlockProps(),
				el( blockEditor.InspectorControls, {},
					el( components.PanelBody, { title: __( 'List', 'family-wiki' ) },
						el( components.RangeControl, {
							label: __( 'Pages to show', 'family-wiki' ),
							value: attributes.count,
							onChange: function ( value ) {
								setAttributes( { count: value } );
							},
							min: 1,
							max: 50,
							__nextHasNoMarginBottom: true
						} )
					)
				),
				el( wp.serverSideRender, {
					block: 'familypedia/recent',
					attributes: attributes
				} )
			);
		},

		save: function () {
			return null;
		}
	} );
}( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n ) );
