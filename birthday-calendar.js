wp.blocks.registerBlockType(
	'family-wiki/birthday-calendar',
	{
		title: 'Birthday Calendar',
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
