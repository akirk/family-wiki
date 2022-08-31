wp.blocks.registerBlockType(
	'family-wiki/family-calendar',
	{
		title: 'Family Calendar',
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
