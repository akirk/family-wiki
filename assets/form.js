/**
 * Repeating rows on the person form: children, and marriages.
 *
 * A new row is a copy of the last one with its values cleared, so the markup
 * only has to exist once, in PHP.
 */
(function () {
	var photo = document.querySelector('[data-familypedia-photo]');
	if (photo && window.wp && wp.media) {
		var photoId = photo.querySelector('[name="photo_id"]');
		var preview = photo.querySelector('[data-photo-preview]');
		var choose = photo.querySelector('[data-photo-choose]');
		var remove = photo.querySelector('[data-photo-remove]');
		var frame;

		choose.addEventListener('click', function () {
			if (!frame) {
				frame = wp.media({
					title: choose.dataset.title,
					button: { text: choose.dataset.select },
					library: { type: 'image' },
					multiple: false
				});
				frame.on('open', function () {
					var selection = frame.state().get('selection');
					selection.reset();
					if (photoId.value) {
						selection.add(wp.media.attachment(photoId.value));
					}
				});
				frame.on('select', function () {
					var attachment = frame.state().get('selection').first().toJSON();
					var image = document.createElement('img');
					image.src = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
					image.alt = attachment.alt || '';
					preview.replaceChildren(image);
					photoId.value = attachment.id;
					choose.textContent = choose.dataset.change;
					remove.hidden = false;
				});
			}
			frame.open();
		});

		remove.addEventListener('click', function () {
			photoId.value = '0';
			preview.replaceChildren();
			choose.textContent = choose.dataset.title;
			remove.hidden = true;
			choose.focus();
		});
	}

	var groups = Array.prototype.slice.call(document.querySelectorAll('[data-familypedia-repeat]'));

	function renumber(group) {
		var name = group.getAttribute('data-familypedia-repeat');
		var rows = group.querySelectorAll('[data-familypedia-row]');

		Array.prototype.forEach.call(rows, function (row, index) {
			Array.prototype.forEach.call(row.querySelectorAll('[name]'), function (field) {
				field.name = field.name.replace(
					new RegExp('^' + name + '\\[[^\\]]*\\]'),
					name + '[' + index + ']'
				);
			});
		});
	}

	function clear(row) {
		Array.prototype.forEach.call(row.querySelectorAll('input, select, textarea'), function (field) {
			if (field.type === 'checkbox' || field.type === 'radio') {
				field.checked = false;
			} else if (field.tagName === 'SELECT') {
				field.selectedIndex = 0;
			} else {
				field.value = '';
			}
		});
	}

	groups.forEach(function (group) {
		var container = group.querySelector('[data-familypedia-rows]');
		var add = group.querySelector('[data-familypedia-add]');

		if (!container || !add) {
			return;
		}

		add.addEventListener('click', function () {
			var rows = container.querySelectorAll('[data-familypedia-row]');
			if (!rows.length) {
				return;
			}

			var copy = rows[rows.length - 1].cloneNode(true);
			clear(copy);
			container.appendChild(copy);
			renumber(group);

			var first = copy.querySelector('input, select, textarea');
			if (first) {
				first.focus();
			}
		});

		container.addEventListener('click', function (event) {
			var remove = event.target.closest('[data-familypedia-remove]');
			if (!remove) {
				return;
			}

			var row = remove.closest('[data-familypedia-row]');
			var rows = container.querySelectorAll('[data-familypedia-row]');

			// Keep one row so there is always something to copy from.
			if (rows.length > 1) {
				row.parentNode.removeChild(row);
			} else {
				clear(row);
			}

			renumber(group);
		});
	});
}());
