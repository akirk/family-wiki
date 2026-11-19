document.addEventListener( 'DOMContentLoaded', function () {
	const personInput = document.getElementById( 'reunion-person' );
	if ( personInput ) {
		const options = Array.from( document.getElementById( 'reunion-people' ).options );
		const filter = function ( event ) {
			const value = personInput.value.trim();
			const person = options.find( function ( option ) {
				return option.value.toLocaleLowerCase() === value.toLocaleLowerCase();
			} );
			if ( person || ! value ) {
				if ( event ) {
					event.preventDefault();
				}
				const url = new URL( personInput.form.action );
				if ( person ) {
					url.searchParams.set( 'person', person.dataset.personId );
				}
				window.location.assign( url.href );
			} else if ( event ) {
				event.preventDefault();
				personInput.reportValidity();
			}
		};
		personInput.addEventListener( 'input', function () {
			const value = personInput.value.trim().toLocaleLowerCase();
			personInput.setCustomValidity( value && ! options.some( function ( option ) {
				return option.value.toLocaleLowerCase() === value;
			} ) ? personInput.dataset.invalidPerson : '' );
		} );
		personInput.addEventListener( 'change', function () { filter(); } );
		personInput.form.addEventListener( 'submit', filter );
	}
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
