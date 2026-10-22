/** Visual family entry: stage cards locally, then save them in one request. */
(function () {
	'use strict';
	document.querySelectorAll('[data-family-entry]').forEach(function (form) {
		var nextKey = 0;
		var busy = false;
		var status = form.querySelector('[data-family-status]');
		form.addEventListener('click', function (event) {
			var add = event.target.closest('[data-family-add]');
			var remove = event.target.closest('[data-family-remove]');
			if (busy) { return; }
			if (remove) {
				remove.closest('[data-family-row]').remove();
			}
			if (!add) { return; }
			var group = add.closest('[data-family-group]');
			var row = group.querySelector('template').content.firstElementChild.cloneNode(true);
			row.dataset.familyKey = String(nextKey++);
			row.querySelectorAll('[data-family-field]').forEach(function (field) {
				field.name = 'relatives[' + row.dataset.familyKey + '][' + field.dataset.familyField + ']';
			});
			// Offer the unrecorded parent slot first; the user can still choose.
			if (group.dataset.familyGroup === 'parents') {
				var select = row.querySelector('select');
				var used = group.querySelectorAll('[data-family-row] select');
				if (used.length && used[used.length - 1].value === 'father') { select.value = 'mother'; }
			}
			group.querySelector('[data-family-cards]').appendChild(row);
			row.querySelector('input').focus();
		});

		form.addEventListener('submit', async function (event) {
			event.preventDefault();
			if (busy) { return; }
			if (!form.querySelector('[data-family-row]')) {
				status.textContent = form.dataset.empty;
				return;
			}
			var firstFailure = null;
			var data = new FormData(form);
			data.set('familypedia_ajax', '1');
			busy = true;
			form.setAttribute('aria-busy', 'true');
			form.querySelectorAll('button, input, select').forEach(function (control) { control.disabled = true; });
			status.textContent = form.dataset.saving;
			try {
				var response = await fetch(form.action || window.location.href, {
					method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json' }
				});
				if (!response.ok) { throw new Error('Save failed'); }
				var result = await response.json();
				if (!result.success || !result.data || !Array.isArray(result.data.results) || !result.data.graph) {
					throw new Error('Unexpected response');
				}
				var failures = [];
				var saved = 0;
				result.data.results.forEach(function (entry) {
					var row = Array.from(form.querySelectorAll('[data-family-row]')).find(function (candidate) {
						return candidate.dataset.familyKey === entry.key;
					});
					if (!row) { return; }
					if (entry.saved) { saved++; return; }
					row.querySelector('[data-family-error]').textContent = entry.message;
					failures.push({ group: row.closest('[data-family-group]').dataset.familyGroup, row: row });
				});
				var container = document.createElement('div');
				container.innerHTML = result.data.graph;
				var graph = container.querySelector('.familypedia-family-graph');
				if (!graph) { throw new Error('Missing family diagram'); }
				failures.forEach(function (failure) {
					graph.querySelector('[data-family-group="' + failure.group + '"] [data-family-cards]').appendChild(failure.row);
				});
				form.querySelector('.familypedia-family-graph').replaceWith(graph);
				var descendants = document.querySelector('[data-family-descendants]');
				if (descendants && typeof result.data.tree === 'string') {
					descendants.innerHTML = result.data.tree;
				}
				status.textContent = failures.length ? form.dataset.partial : (saved ? form.dataset.saved : form.dataset.empty);
				if (failures.length) { firstFailure = failures[0].row.querySelector('input'); }
			} catch (error) {
				status.textContent = form.dataset.failed;
			} finally {
				busy = false;
				form.removeAttribute('aria-busy');
				form.querySelectorAll('button, input, select').forEach(function (control) { control.disabled = false; });
				if (firstFailure) { firstFailure.focus(); }
			}
		});
	});

	// Close the action dropdown when opening the family builder on this page.
	document.querySelectorAll('.familypedia-action-menu a[href="#add-family-member"]').forEach(function (link) {
		link.addEventListener('click', function () { link.closest('details').open = false; });
	});
}());
