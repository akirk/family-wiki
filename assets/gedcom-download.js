( function () {
	document.querySelectorAll( '.familypedia-download-form' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function () {
			var check = form.querySelector( '.familypedia-download-check' );

			if ( check ) {
				check.hidden = false;
			}
		} );
	} );
} )();
