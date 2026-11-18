document.addEventListener( 'DOMContentLoaded', function () {
	const search = document.getElementById( 'reunion-attendee-search' );
	if ( ! search ) {
		return;
	}
	const people = document.querySelectorAll( '.familypedia-reunion-attendee' );
	search.addEventListener( 'input', function () {
		const query = search.value.trim().toLocaleLowerCase();
		people.forEach( function ( person ) {
			person.hidden = ! person.textContent.toLocaleLowerCase().includes( query );
		} );
	} );
} );
