(function () {
	var media = window.matchMedia('(max-width: 700px)');
	var infoboxes = Array.prototype.slice.call(document.querySelectorAll('.family-wiki-infobox'));

	function setMobileState() {
		infoboxes.forEach(function (infobox) {
			var toggle = infobox.querySelector('.family-wiki-infobox__toggle');
			if (!toggle) {
				return;
			}

			if (media.matches) {
				infobox.classList.add('family-wiki-infobox--collapsible');
				if (!infobox.classList.contains('family-wiki-infobox--opened')) {
					toggle.setAttribute('aria-expanded', 'false');
					toggle.textContent = '+';
				}
			} else {
				infobox.classList.remove('family-wiki-infobox--collapsible');
				toggle.setAttribute('aria-expanded', 'true');
				toggle.textContent = '-';
			}
		});
	}

	infoboxes.forEach(function (infobox) {
		var toggle = infobox.querySelector('.family-wiki-infobox__toggle');
		if (!toggle) {
			return;
		}

		toggle.addEventListener('click', function () {
			infobox.classList.toggle('family-wiki-infobox--opened');
			var open = infobox.classList.contains('family-wiki-infobox--opened');
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
