import { __ } from '@wordpress/i18n';

wp.blocks.registerBlockType(
	'family-wiki/birthday-calendar',
	{
		title: __( 'Birthday Calendar', 'family-wiki' ),
		edit: function () {
			return wp.element.createElement(
				wp.serverSideRender,
				{
					block: 'family-wiki/birthday-calendar'
				}
			);
		}
	}
);
