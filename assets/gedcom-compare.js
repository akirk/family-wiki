/** Select edited facts; saving still requires submitting the comparison form. */
(function () {
	'use strict';
	document.querySelectorAll('[data-gedcom-change]').forEach(function (row) {
		var input = row.querySelector('[data-gedcom-value]');
		var checkbox = row.querySelector('input[type="checkbox"]');
		var button = row.querySelector('[data-gedcom-copy]');
		var original = input.value;
		function updateSelection() {
			checkbox.checked = input.value !== original;
		}
		input.addEventListener('input', updateSelection);
		input.addEventListener('change', updateSelection);
		button.hidden = false;
		button.addEventListener('click', function () {
			input.value = button.getAttribute('data-gedcom-copy');
			updateSelection();
			input.focus();
		});
	});
}());
