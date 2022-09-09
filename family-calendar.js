wp.blocks.registerBlockType(
	'family-wiki/family-calendar',
	{
		title: wp.i18n.__( 'Family Calendar', 'family-wiki' ),
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
