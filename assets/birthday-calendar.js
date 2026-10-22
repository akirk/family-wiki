wp.blocks.registerBlockType(
	'family-wiki/birthday-calendar',
	{
		title: wp.i18n.__( 'Birthday Calendar', 'family-wiki' ),
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
