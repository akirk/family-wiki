( function () {
	var sites = document.querySelector( '[data-familypedia-sites]' );
	var siteTemplate = document.querySelector( '[data-familypedia-site-template]' );
	var mappingTemplate = document.querySelector( '[data-familypedia-mapping-template]' );

	if ( ! sites || ! siteTemplate || ! mappingTemplate ) {
		return;
	}

	document.addEventListener( 'click', function ( event ) {
		var addSite = event.target.closest( '[data-familypedia-add-site]' );
		if ( addSite ) {
			var index = String( Date.now() );
			var wrapper = document.createElement( 'div' );
			wrapper.innerHTML = siteTemplate.innerHTML.replace( /__site__/g, index );
			sites.appendChild( wrapper.firstElementChild );

			var empty = document.querySelector( '[data-familypedia-empty]' );
			if ( empty ) {
				empty.remove();
			}
			return;
		}

		var removeSite = event.target.closest( '[data-familypedia-remove-site]' );
		if ( removeSite ) {
			removeSite.closest( '[data-familypedia-site]' ).remove();
			return;
		}

		var addMapping = event.target.closest( '[data-familypedia-add-mapping]' );
		if ( addMapping ) {
			var site = addMapping.closest( '[data-familypedia-site]' );
			var siteIndex = site.getAttribute( 'data-familypedia-site' );
			var mappingIndex = String( Date.now() );
			var remoteList = 'familypedia-remote-people-' + siteIndex;
			var wrapper = document.createElement( 'div' );

			wrapper.innerHTML = mappingTemplate.innerHTML
				.replace( /__site__/g, siteIndex )
				.replace( /__mapping__/g, mappingIndex )
				.replace( /familypedia-remote-people-__site__/g, remoteList );

			site.querySelector( '[data-familypedia-mappings]' ).appendChild( wrapper.firstElementChild );
			return;
		}

		var removeMapping = event.target.closest( '[data-familypedia-remove-mapping]' );
		if ( removeMapping ) {
			removeMapping.closest( '[data-familypedia-mapping]' ).remove();
		}
	} );
} )();
