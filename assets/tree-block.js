( function ( blocks, element, blockEditor, components, apiFetch, i18n ) {
	var el = element.createElement;
	var __ = i18n.__;
	var sprintf = i18n.sprintf;
	var useState = element.useState;
	var useEffect = element.useEffect;

	function PersonPicker( props ) {
		var state = useState( [] );
		var people = state[0];
		var setPeople = state[1];
		var loadingState = useState( true );
		var loading = loadingState[0];
		var setLoading = loadingState[1];

		useEffect( function () {
			apiFetch( { path: '/familypedia/v1/people' } ).then( function ( result ) {
				setPeople( result );
				setLoading( false );
			} ).catch( function () {
				setLoading( false );
			} );
		}, [] );

		if ( loading ) {
			return el( components.Spinner );
		}

		var options = people.map( function ( person ) {
			var label = person.title;
			if ( person.years ) {
				label += ' (' + person.years + ')';
			}
			if ( person.descendants ) {
				label += ' — ' + sprintf(
					// translators: %d is a number of descendants.
					i18n._n( '%d descendant', '%d descendants', person.descendants, 'family-wiki' ),
					person.descendants
				);
			}

			return { value: person.id, label: label };
		} );

		return el( components.ComboboxControl, {
			label: __( 'Start from', 'family-wiki' ),
			help: props.view === 'cards'
				? __( 'Center the cards on this person, with their parents, spouses, siblings and children.', 'family-wiki' )
				: __( 'The person this branch descends from. Their partner is added automatically.', 'family-wiki' ),
			value: props.value || null,
			options: options,
			onChange: props.onChange,
			__nextHasNoMarginBottom: true
		} );
	}

	blocks.registerBlockType( 'family-wiki/tree', {
		title: __( 'Family Tree', 'family-wiki' ),
		description: __( 'A descendant outline or family cards centered on one person.', 'family-wiki' ),
		icon: 'networking',
		category: 'widgets',

		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = blockEditor.useBlockProps();

			return el( 'div', blockProps,
				el( blockEditor.InspectorControls, {},
					el( components.PanelBody, { title: __( 'Branch', 'family-wiki' ) },
						el( PersonPicker, {
							value: attributes.root,
							view: attributes.view,
							onChange: function ( value ) {
								setAttributes( { root: value ? parseInt( value, 10 ) : 0 } );
							}
						} ),
						el( components.SelectControl, {
							label: __( 'View', 'family-wiki' ),
							value: attributes.view || 'outline',
							options: [
								{ label: __( 'Descendant outline', 'family-wiki' ), value: 'outline' },
								{ label: __( 'Family cards — parents, spouses, siblings and children', 'family-wiki' ), value: 'cards' }
							],
							onChange: function ( value ) { setAttributes( { view: value } ); },
							__nextHasNoMarginBottom: true
						} ),
						attributes.view !== 'cards' && el( components.RangeControl, {
							label: __( 'Maximum depth', 'family-wiki' ),
							help: __( 'Generations to show. 0 shows all of them; deeper people get a link instead.', 'family-wiki' ),
							value: attributes.maxDepth,
							onChange: function ( value ) {
								setAttributes( { maxDepth: value } );
							},
							min: 0,
							max: 12,
							__nextHasNoMarginBottom: true
						} ),
						el( components.ToggleControl, {
							label: __( 'Show life years', 'family-wiki' ),
							checked: !! attributes.showDates,
							onChange: function ( value ) {
								setAttributes( { showDates: value } );
							},
							__nextHasNoMarginBottom: true
						} ),
						attributes.view !== 'cards' && el( components.ToggleControl, {
							label: __( 'Expand fully', 'family-wiki' ),
							help: __( 'Repeat people already shown by an earlier tree on this page instead of linking back to them.', 'family-wiki' ),
							checked: !! attributes.expandFully,
							onChange: function ( value ) {
								setAttributes( { expandFully: value } );
							},
							__nextHasNoMarginBottom: true
						} )
					)
				),
				el( wp.serverSideRender, {
					block: 'family-wiki/tree',
					attributes: attributes
				} )
			);
		},

		save: function () {
			return null;
		}
	} );
}( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.apiFetch, window.wp.i18n ) );
