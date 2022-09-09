import { __ } from '@wordpress/i18n';

wp.blocks.registerBlockType(
	'family-wiki/family-calendar',
	{
		title: __( 'Family Calendar', 'family-wiki' ),
		edit: function () {
			return wp.element.createElement(
				wp.serverSideRender,
				{
					block: 'family-wiki/family-calendar'
				}
			);
		}
	}
);
