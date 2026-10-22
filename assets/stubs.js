/** Select only the people visible on the current stub management page. */
(function () {
	'use strict';
	document.querySelectorAll('[data-stubs-form]').forEach(function (form) {
		var all = form.querySelector('[data-stubs-select-all]');
		var people = Array.from(form.querySelectorAll('input[name="people[]"]'));
		if (!all || !people.length) { return; }
		form.querySelector('[data-stubs-select-all-label]').hidden = false;
		all.addEventListener('change', function () {
			people.forEach(function (person) { person.checked = all.checked; });
		});
		form.addEventListener('change', function () {
			var count = people.filter(function (person) { return person.checked; }).length;
			all.checked = count === people.length;
			all.indeterminate = count > 0 && count < people.length;
		});
	});
}());
