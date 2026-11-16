/** Browser-backed GEDCOM reviews. Server requests still validate every selected change. */
(function () {
	'use strict';
	var form = document.querySelector('[data-gedcom-comparison]');
	if (!form) {
		document.querySelectorAll('[data-gedcom-recovery]').forEach(function (recovery) {
			try {
				var key = localStorage.getItem(recovery.dataset.draftPrefix + 'latest');
				if (!key || !key.startsWith(recovery.dataset.draftPrefix)) { return; }
				var saved = JSON.parse(localStorage.getItem(key));
				if (!saved || !saved.snapshot) { return; }
				recovery.querySelector('[name="comparison_snapshot"]').value = JSON.stringify(saved.snapshot);
				recovery.hidden = false;
			} catch (error) { /* The upload form remains available when storage is unavailable. */ }
		});
		return;
	}
	var snapshotInput = form.querySelector('[name="comparison_snapshot"]');
	var snapshot = JSON.parse(snapshotInput.value);
	snapshot.changes = Object.assign({}, snapshot.changes);
	var entries = Array.from(form.querySelectorAll('[data-gedcom-person]'));
	var tabs = Array.from(form.querySelectorAll('[data-gedcom-tab]'));
	var search = form.querySelector('[data-gedcom-search]');
	var panel = form.querySelector('[data-gedcom-panel]');
	var draftStatus = form.querySelector('[data-gedcom-draft-status]');
	var saveStatus = form.querySelector('[data-gedcom-save-status]');
	var touched = new Set();
	var loadedEditors = new Set();
	var draft = { rows: {}, imports: {}, editors: [] };
	var tableIndex = 0;
	var saveTimer;
	var active = entries.some(function (entry) { return entry.dataset.gedcomPerson === 'existing'; }) ? 'existing' : 'missing';
	if (!entries.some(function (entry) { return entry.dataset.gedcomPerson === active; })) { active = 'skipped'; }

	try {
		var stored = JSON.parse(localStorage.getItem(form.dataset.draftKey));
		if (stored && stored.version === 1 && stored.rows && stored.imports && Array.isArray(stored.editors)) { draft = stored; }
	} catch (error) { /* Saving below will report unavailable storage. */ }
	loadedEditors = new Set(draft.editors);

	function showStatus(element, message) {
		element.textContent = message;
		element.hidden = false;
	}

	function persist() {
		clearTimeout(saveTimer);
		form.querySelectorAll('[data-gedcom-change]').forEach(function (row) {
			var key = row.dataset.gedcomDraftKey;
			if (touched.has(key)) {
				draft.rows[key] = {
					value: row.querySelector('[data-gedcom-value]').value,
					checked: row.querySelector('input[type="checkbox"]').checked,
					old: row.dataset.gedcomOriginal,
					message: row.querySelector('[data-gedcom-row-error]') ? row.querySelector('[data-gedcom-row-error]').textContent : ''
				};
			}
		});
		form.querySelectorAll('[data-gedcom-import]').forEach(function (checkbox) { draft.imports[checkbox.value] = checkbox.checked; });
		draft.version = 1;
		draft.snapshot = snapshot;
		draft.editors = Array.from(loadedEditors);
		snapshotInput.value = JSON.stringify(snapshot);
		try {
			localStorage.setItem(form.dataset.draftKey, JSON.stringify(draft));
			localStorage.setItem(form.dataset.draftPrefix + 'latest', form.dataset.draftKey);
			draftStatus.hidden = true;
			form.querySelector('[data-gedcom-backup-status]').hidden = false;
		} catch (error) {
			form.querySelector('[data-gedcom-backup-status]').hidden = true;
			showStatus(draftStatus, form.dataset.storageError);
		}
	}

	function rowMessage(row, message) {
		var note = row.querySelector('[data-gedcom-row-error]');
		if (!note) {
			note = document.createElement('p');
			note.dataset.gedcomRowError = '';
			note.setAttribute('role', 'status');
			row.lastElementChild.appendChild(note);
		}
		note.textContent = message;
	}

	function restore(root) {
		root.querySelectorAll('[data-gedcom-change]').forEach(function (row) {
			var saved = draft.rows[row.dataset.gedcomDraftKey];
			if (!saved || typeof saved.value !== 'string' || typeof saved.checked !== 'boolean') { return; }
			touched.add(row.dataset.gedcomDraftKey);
			row.querySelector('[data-gedcom-value]').value = saved.value;
			var unchanged = saved.old === row.dataset.gedcomOriginal;
			row.querySelector('input[type="checkbox"]').checked = unchanged && saved.checked;
			if (!unchanged || saved.message) { rowMessage(row, !unchanged ? form.dataset.conflictLabel : saved.message); }
		});
		root.querySelectorAll('[data-gedcom-import]').forEach(function (checkbox) {
			if (typeof draft.imports[checkbox.value] === 'boolean') { checkbox.checked = draft.imports[checkbox.value]; }
		});
	}

	function initializeRows(root) {
		root.querySelectorAll('[data-gedcom-change]').forEach(function (row) {
			var input = row.querySelector('[data-gedcom-value]');
			var checkbox = row.querySelector('input[type="checkbox"]');
			var button = row.querySelector('[data-gedcom-copy]');
			var original = input.value;
			function updateSelection() {
				checkbox.checked = row.hasAttribute('data-gedcom-new') || input.value !== original;
				checkbox.dispatchEvent(new Event('change', { bubbles: true }));
			}
			input.addEventListener('input', updateSelection);
			input.addEventListener('change', updateSelection);
			button.hidden = false;
			button.addEventListener('click', function () {
				input.value = button.dataset.gedcomCopy;
				updateSelection();
				input.focus();
			});
		});
	}

	function initializeTables(root, expanded) {
		root.querySelectorAll('[data-gedcom-table-toggle]').forEach(function (button) {
			var table = button.nextElementSibling;
			var fieldset = button.closest('fieldset');
			fieldset.classList.add('familypedia-gedcom-clickable');
			table.id = 'gedcom-facts-' + tableIndex++;
			table.hidden = !expanded;
			button.hidden = false;
			button.classList.add('screen-reader-text');
			button.setAttribute('aria-controls', table.id);
			function updateToggle() {
				button.setAttribute('aria-expanded', String(!table.hidden));
				button.textContent = table.hidden ? button.dataset.showLabel : button.dataset.hideLabel;
				fieldset.classList.toggle('familypedia-gedcom-table-open', !table.hidden);
			}
			function toggleTable() { table.hidden = !table.hidden; updateToggle(); }
			updateToggle();
			button.addEventListener('click', toggleTable);
			fieldset.addEventListener('click', function (event) {
				var control = event.target.closest('a, button, input, select, textarea, label, details, table');
				if ((control && fieldset.contains(control)) || window.getSelection().toString()) { return; }
				toggleTable();
			});
		});
	}

	function isSelected(entry) {
		var type = entry.dataset.gedcomPerson;
		if (type === 'missing' || type === 'skipped') {
			var checkbox = entry.querySelector('[data-gedcom-import]');
			return checkbox ? checkbox.checked : false;
		}
		return type === 'existing' && !!entry.querySelector('[data-gedcom-change] input[type="checkbox"]:checked');
	}

	function render() {
		var counts = { existing: 0, missing: 0, selected: 0, skipped: 0 };
		var visible = 0;
		var query = search.value.trim().toLocaleLowerCase();
		entries = entries.filter(function (entry) { return entry.isConnected; });
		entries.forEach(function (entry) {
			var type = entry.dataset.gedcomPerson;
			var selected = isSelected(entry);
			counts[type]++;
			if (selected) { counts.selected++; }
			entry.hidden = !(active === 'selected' ? selected : type === active) || entry.dataset.gedcomName.toLocaleLowerCase().indexOf(query) === -1;
			if (!entry.hidden) { visible++; }
			var status = entry.querySelector('[data-gedcom-person-status]');
			if (status) {
				var facts = entry.querySelectorAll('[data-gedcom-change] input[type="checkbox"]');
				var checked = entry.querySelectorAll('[data-gedcom-change] input[type="checkbox"]:checked');
				status.textContent = ' (' + checked.length + '/' + facts.length + ')';
			}
		});
		tabs.forEach(function (tab) {
			var selected = tab.dataset.gedcomTab === active;
			tab.setAttribute('aria-selected', String(selected));
			tab.tabIndex = selected ? 0 : -1;
			tab.querySelector('[data-gedcom-count]').textContent = counts[tab.dataset.gedcomTab];
			if (selected) { panel.setAttribute('aria-labelledby', tab.id); }
		});
		form.querySelectorAll('[data-gedcom-category]').forEach(function (section) {
			section.hidden = section.dataset.gedcomCategory !== active && !(active === 'selected' && (section.dataset.gedcomCategory === 'missing' || section.dataset.gedcomCategory === 'skipped'));
		});
		var empty = form.querySelector('[data-gedcom-empty]');
		empty.hidden = visible !== 0;
		empty.textContent = empty.getAttribute('data-empty-' + (query ? 'search' : active));
		form.querySelector('[data-gedcom-selected-count]').textContent = counts.selected;
		form.querySelector('[data-gedcom-selection-status]').hidden = counts.selected === 0;
	}

	async function post(endpoint, data) {
		var response = await fetch(endpoint, {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': form.dataset.restNonce },
			body: JSON.stringify(data)
		});
		var result = await response.json();
		if (!response.ok) { throw new Error(result.message || form.dataset.loadError); }
		return result;
	}

	async function loadEditor(button, restoring) {
		var entry = button.closest('[data-gedcom-person]');
		var editor = entry.querySelector('[data-gedcom-skipped-editor]');
		var error = entry.querySelector('[data-gedcom-import-error]');
		button.disabled = true;
		error.hidden = true;
		try {
			var data = await post(form.dataset.personEndpoint, { snapshot: snapshot, xref: button.dataset.gedcomImportSkipped });
			if (typeof data.html !== 'string') { throw new Error(form.dataset.loadError); }
			editor.innerHTML = data.html;
			editor.hidden = false;
			initializeRows(editor);
			initializeTables(editor, true);
			var checkbox = editor.querySelector('[data-gedcom-import]');
			checkbox.checked = true;
			loadedEditors.add(button.dataset.gedcomImportSkipped);
			restore(editor);
			button.hidden = true;
			if (!restoring) { persist(); checkbox.focus(); }
			render();
		} catch (failure) {
			error.textContent = failure.message || form.dataset.loadError;
			error.hidden = false;
			button.disabled = false;
		}
	}

	function selection() {
		var result = { changes: [], values: {}, missing: [], skipped: [], missing_fields: {}, missing_values: {} };
		form.querySelectorAll('[data-gedcom-change]').forEach(function (row) {
			var checkbox = row.querySelector('input[type="checkbox"]');
			var input = row.querySelector('[data-gedcom-value]');
			if (checkbox.name === 'changes[]') {
				result.values[checkbox.value] = input.value;
				if (checkbox.checked) { result.changes.push(checkbox.value); }
			} else {
				var xref = row.closest('fieldset').querySelector('[data-gedcom-import]').value;
				if (!result.missing_fields[xref]) { result.missing_fields[xref] = []; result.missing_values[xref] = {}; }
				result.missing_values[xref][checkbox.value] = input.value;
				if (checkbox.checked) { result.missing_fields[xref].push(checkbox.value); }
			}
		});
		form.querySelectorAll('[data-gedcom-import]:checked').forEach(function (checkbox) {
			result[checkbox.name === 'skipped[]' ? 'skipped' : 'missing'].push(checkbox.value);
		});
		return result;
	}

	form.addEventListener('submit', async function (event) {
		event.preventDefault();
		persist();
		var selected = selection();
		var controls = Array.from(form.querySelectorAll('input, select, textarea, button')).map(function (control) { return { element: control, disabled: control.disabled }; });
		controls.forEach(function (control) { control.element.disabled = true; });
		form.setAttribute('aria-busy', 'true');
		try {
			var result = await post(form.dataset.saveEndpoint, { snapshot: snapshot, selection: selected });
			if (!Array.isArray(result.saved) || !Array.isArray(result.imported) || !result.errors || typeof result.errors !== 'object') { throw new Error(form.dataset.saveError); }
			form.querySelectorAll('[data-gedcom-change]').forEach(function (row) {
				var key = row.dataset.gedcomDraftKey;
				var checkbox = row.querySelector('input[type="checkbox"]');
				if (result.saved.indexOf(key) !== -1) {
					delete draft.rows[key]; touched.delete(key);
					delete snapshot.changes[checkbox.value];
					row.remove();
				} else if (result.errors[key]) {
					var error = result.errors[key];
					if (Object.prototype.hasOwnProperty.call(error, 'current')) {
						snapshot.changes[checkbox.value].old = error.current;
						row.dataset.gedcomOriginal = String(error.current);
						checkbox.checked = false;
					}
					touched.add(key);
					rowMessage(row, error.message + (Object.prototype.hasOwnProperty.call(error, 'current') ? ' (' + String(error.current) + ')' : ''));
				}
			});
			entries.forEach(function (entry) {
				var checkbox = entry.querySelector('[data-gedcom-import]');
				if (checkbox && result.imported.indexOf(checkbox.value) !== -1) {
					var xref = checkbox.value;
					loadedEditors.delete(xref); delete draft.imports[xref];
					entry.querySelectorAll('[data-gedcom-change]').forEach(function (row) { delete draft.rows[row.dataset.gedcomDraftKey]; touched.delete(row.dataset.gedcomDraftKey); });
					entry.remove();
				} else if (checkbox && result.errors['import:' + checkbox.value]) {
					var note = entry.querySelector('[data-gedcom-import-error]') || document.createElement('p');
					note.dataset.gedcomImportError = '';
					note.hidden = false;
					note.textContent = result.errors['import:' + checkbox.value].message;
					if (!note.isConnected) { entry.appendChild(note); }
				} else if (entry.dataset.gedcomPerson === 'existing' && !entry.querySelector('[data-gedcom-change]')) { entry.remove(); }
			});
			showStatus(saveStatus, Object.keys(result.errors).length ? form.dataset.saveError : form.dataset.saveSuccess);
			persist(); render();
		} catch (error) { showStatus(saveStatus, error.message || form.dataset.saveError); }
		finally {
			controls.forEach(function (control) { control.element.disabled = control.disabled; });
			form.removeAttribute('aria-busy');
		}
	});

	initializeRows(form);
	initializeTables(form, false);
	restore(form);
	form.querySelector('[data-gedcom-tabs]').hidden = false;
	form.querySelector('[data-gedcom-tabs]').setAttribute('role', 'tablist');
	form.querySelector('[data-gedcom-search-wrap]').hidden = false;
	panel.setAttribute('role', 'tabpanel');
	tabs.forEach(function (tab, index) {
		tab.setAttribute('role', 'tab');
		tab.setAttribute('aria-controls', panel.id);
		tab.addEventListener('click', function () { active = tab.dataset.gedcomTab; render(); });
		tab.addEventListener('keydown', function (event) {
			var next;
			if (event.key === 'ArrowRight') { next = (index + 1) % tabs.length; }
			if (event.key === 'ArrowLeft') { next = (index + tabs.length - 1) % tabs.length; }
			if (event.key === 'Home') { next = 0; }
			if (event.key === 'End') { next = tabs.length - 1; }
			if (next === undefined) { return; }
			event.preventDefault(); tabs[next].focus(); tabs[next].click();
		});
	});
	form.querySelectorAll('[data-gedcom-import-skipped]').forEach(function (button) {
		button.hidden = false;
		button.addEventListener('click', function () { loadEditor(button, false); });
		if (draft.editors.indexOf(button.dataset.gedcomImportSkipped) !== -1) { loadEditor(button, true); }
	});
	search.addEventListener('input', render);
	form.addEventListener('input', function (event) {
		var row = event.target.closest('[data-gedcom-change]');
		if (row) { touched.add(row.dataset.gedcomDraftKey); clearTimeout(saveTimer); saveTimer = setTimeout(persist, 200); }
	});
	form.addEventListener('change', function (event) {
		var row = event.target.closest('[data-gedcom-change]');
		if (row) { touched.add(row.dataset.gedcomDraftKey); }
		persist(); render();
	});
	window.addEventListener('pagehide', persist);
	persist();
	render();
}());
