(function () {
	var media = window.matchMedia('(max-width: 700px)');
	var infoboxes = Array.prototype.slice.call(document.querySelectorAll('.familypedia-infobox'));

	function setMobileState() {
		infoboxes.forEach(function (infobox) {
			var toggle = infobox.querySelector('.familypedia-infobox__toggle');
			if (!toggle) {
				return;
			}

			if (media.matches) {
				infobox.classList.add('familypedia-infobox--collapsible');
				if (!infobox.classList.contains('familypedia-infobox--opened')) {
					toggle.setAttribute('aria-expanded', 'false');
					toggle.textContent = '+';
				}
			} else {
				infobox.classList.remove('familypedia-infobox--collapsible');
				toggle.setAttribute('aria-expanded', 'true');
				toggle.textContent = '-';
			}
		});
	}

	infoboxes.forEach(function (infobox) {
		var toggle = infobox.querySelector('.familypedia-infobox__toggle');
		if (!toggle) {
			return;
		}

		toggle.addEventListener('click', function () {
			infobox.classList.toggle('familypedia-infobox--opened');
			var open = infobox.classList.contains('familypedia-infobox--opened');
			toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
			toggle.textContent = open ? '-' : '+';
		});
	});

	setMobileState();
	if (media.addEventListener) {
		media.addEventListener('change', setMobileState);
	} else {
		media.addListener(setMobileState);
	}
}());
