(function () {
	function escapeRegExp(value) {
		return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
	}

	function reindexRows(field) {
		var name = field.getAttribute('data-name');
		var pattern = new RegExp(escapeRegExp(name) + '\\[(?:__index__|\\d+)\\]');
		Array.prototype.forEach.call(field.querySelectorAll('tbody tr'), function (row, index) {
			Array.prototype.forEach.call(row.querySelectorAll('[name]'), function (input) {
				input.name = input.name.replace(pattern, name + '[' + index + ']');
			});
		});
	}

	document.addEventListener('click', function (event) {
		var addButton = event.target.closest('.family-wiki-marriages-field__add');
		if (addButton) {
			var field = addButton.closest('.family-wiki-marriages-field');
			var tbody = field.querySelector('tbody');
			var template = field.querySelector('.family-wiki-marriages-field__template').innerHTML;
			tbody.insertAdjacentHTML('beforeend', template.replace(/__index__/g, tbody.children.length));
			return;
		}

		var removeButton = event.target.closest('.family-wiki-marriages-field__remove');
		if (removeButton) {
			var field = removeButton.closest('.family-wiki-marriages-field');
			var row = removeButton.closest('tr');
			if (field.querySelectorAll('tbody tr').length > 1) {
				row.parentNode.removeChild(row);
				reindexRows(field);
			} else {
				Array.prototype.forEach.call(row.querySelectorAll('input, select'), function (input) {
					input.value = '';
				});
			}
		}
	});
}());
