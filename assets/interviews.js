/* Guided questions and serialized autosave; ordinary forms also work without JS. */
(function () {
	'use strict';
	var library = document.querySelector('[data-question-library]');
	if (library) {
		if (window.location.hash === '#customize-questions') { library.open = true; }
		var json = library.querySelector('[name="question_library"]');
		var rows = library.querySelector('[data-library-rows]');
		var message = library.querySelector('[data-library-status]');
		var add = library.querySelector('[data-add-question]');
		var exporting = library.querySelector('[data-export-questions]');
		var questions = JSON.parse(json.value);
		var defaults = JSON.parse(library.querySelector('[data-question-defaults]').textContent);
		function syncLibrary() { json.value = JSON.stringify(questions, null, 2); }
		function renderLibrary() {
			rows.replaceChildren();
			questions.forEach(function (item, index) {
				var card = document.createElement('fieldset');
				['topic', 'question', 'hint'].forEach(function (key) {
					var label = document.createElement('label');
					label.textContent = library.dataset[key];
					var field = document.createElement(key === 'topic' ? 'input' : 'textarea');
					var baseline = defaults[item.starter] || {};
					field.value = Object.prototype.hasOwnProperty.call(item, key) ? item[key] : (baseline[key] || '');
					field.required = key !== 'hint';
					field.addEventListener('input', function () { if (item.starter && field.value === baseline[key]) { delete item[key]; } else { item[key] = field.value; } syncLibrary(); });
					label.appendChild(field);
					card.appendChild(label);
				});
				['up', 'down', 'remove'].forEach(function (action) {
					var button = document.createElement('button');
					button.type = 'button';
					button.textContent = library.dataset[action];
					button.disabled = (action === 'up' && index === 0) || (action === 'down' && index === questions.length - 1);
					button.addEventListener('click', function () {
						if (action === 'remove') { questions.splice(index, 1); }
						else { var target = index + (action === 'up' ? -1 : 1); questions.splice(target, 0, questions.splice(index, 1)[0]); }
						syncLibrary(); renderLibrary();
					});
					card.appendChild(button);
				});
				rows.appendChild(card);
			});
		}
		json.hidden = true;
		json.required = false;
		library.querySelector('label[for="question-library"]').hidden = true;
		add.hidden = false;
		exporting.hidden = false;
		add.addEventListener('click', function () { questions.push({ topic: '', question: '', hint: '' }); syncLibrary(); renderLibrary(); rows.lastChild.querySelector('input').focus(); });
		library.querySelector('[data-import-questions]').addEventListener('change', async function (event) {
			try {
				var file = event.target.files[0];
				if (!file) { return; }
				if (file.size > 1048576) { throw new Error(); }
				var imported = JSON.parse(await file.text());
				if (!Array.isArray(imported) || !imported.length || imported.length + questions.length > 200 || imported.some(function (item) {
					if (!item || typeof item !== 'object' || Array.isArray(item) || (item.starter !== undefined && (typeof item.starter !== 'string' || !Object.prototype.hasOwnProperty.call(defaults, item.starter)))) { return true; }
					var resolved = Object.assign({}, defaults[item.starter] || {}, item);
					return typeof resolved.topic !== 'string' || !resolved.topic.trim() || typeof resolved.question !== 'string' || !resolved.question.trim() || (resolved.hint !== undefined && typeof resolved.hint !== 'string');
				})) { throw new Error(); }
				questions = questions.concat(imported.map(function (item) {
					var row = {};
					['starter', 'topic', 'question', 'hint'].forEach(function (key) { if (Object.prototype.hasOwnProperty.call(item, key)) { row[key] = item[key]; } });
					return row;
				}));
				syncLibrary(); renderLibrary(); message.textContent = library.dataset.imported;
			} catch (error) { message.textContent = library.dataset.invalid; }
			event.target.value = '';
		});
		exporting.addEventListener('click', function () {
			var url = URL.createObjectURL(new Blob([json.value], { type: 'application/json' }));
			var link = document.createElement('a'); link.href = url; link.download = 'interview-questions.json'; link.click();
			setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
		});
		renderLibrary();
	}
	var form = document.querySelector('[data-interview-form]');
	var draft = document.querySelector('[data-draft-form]');
	if (draft) {
		var changed = false;
		draft.addEventListener('input', function () { changed = true; });
		draft.addEventListener('submit', function () { changed = false; });
		window.addEventListener('beforeunload', function (event) {
			if (changed) { event.preventDefault(); event.returnValue = ''; }
		});
	}
	if (!form) { return; }
	var topic = form.elements.topic;
	var question = form.elements.question;
	var panels = Array.from(form.querySelectorAll('[data-question]'));
	var options = Array.from(question.options);
	var status = form.querySelector('[data-save-status]');
	var dirty = false;
	var generation = 0;
	var timer;
	var pending = null;
	var conflict = false;
	var navigating = false;

	var picker = form.querySelector('[data-question-picker]');
	var chips = picker.querySelector('[data-topic-chips]');
	var cards = picker.querySelector('[data-question-cards]');
	var browsing = topic.value;
	var visited = new Set(options.filter(function (option) { return form.elements['answers[' + option.value + ']'].value.trim(); }).map(function (option) { return option.value; }));
	var cardButtons = [];
	Array.from(topic.options).forEach(function (option) {
		var chip = document.createElement('button');
		chip.type = 'button'; chip.className = 'familypedia-button'; chip.textContent = option.textContent; chip.dataset.topicChip = option.value;
		chip.addEventListener('click', function () { browsing = option.value; displayQuestion(); });
		chips.appendChild(chip);
	});
	options.forEach(function (option) {
		var panel = panels.find(function (item) { return item.dataset.question === option.value; });
		var card = document.createElement('article'); card.className = 'familypedia-interview__card'; card.dataset.topic = option.parentElement.dataset.topic;
		var button = document.createElement('button'); button.type = 'button'; button.className = 'familypedia-interview__pick';
		button.setAttribute('aria-controls', 'question-panel-' + option.value);
		panel.id = 'question-panel-' + option.value;
		var title = document.createElement('span'); title.textContent = option.textContent; button.appendChild(title);
		var badge = document.createElement('span'); badge.className = 'familypedia-interview__visited'; badge.textContent = picker.dataset.visited; button.appendChild(badge);
		button.addEventListener('click', function () {
			question.value = option.value;
			visited.add(option.value);
			question.dispatchEvent(new Event('change', { bubbles: true }));
		});
		card.appendChild(button); card.appendChild(panel); cards.appendChild(card);
		panel.querySelector('h2').hidden = true;
		cardButtons.push({ card: card, button: button, badge: badge, value: option.value });
	});
	function displayQuestion() {
		var selected = question.selectedOptions[0];
		if (selected) { topic.value = selected.parentElement.dataset.topic; }
		Array.from(chips.children).forEach(function (chip) { chip.setAttribute('aria-pressed', String(chip.dataset.topicChip === browsing)); });
		cardButtons.forEach(function (item) {
			item.card.hidden = item.card.dataset.topic !== browsing;
			var active = item.value === question.value;
			item.button.setAttribute('aria-expanded', String(active));
			item.badge.hidden = !visited.has(item.value);
			item.card.classList.toggle('is-selected', active);
		});
		panels.forEach(function (panel) { panel.hidden = panel.dataset.question !== question.value; });
	}
	question.addEventListener('change', function () { browsing = question.selectedOptions[0].parentElement.dataset.topic; displayQuestion(); });
	form.querySelector('.familypedia-interview__toolbar').hidden = true;
	picker.hidden = false;
	displayQuestion();
	if (window.location.hash === '#fact-review') { document.getElementById('fact-review').open = true; }

	function markDirty() {
		dirty = true;
		generation++;
		if (!conflict) {
			status.textContent = form.dataset.unsaved;
			clearTimeout(timer);
			timer = setTimeout(save, 1200);
		}
	}
	form.addEventListener('input', markDirty);
	form.addEventListener('change', markDirty);

	async function save() {
		clearTimeout(timer);
		if (pending) {
			var completed = await pending;
			if (completed && dirty && !conflict) { return save(); }
			return !dirty;
		}
		if (conflict) { return false; }
		if (!dirty) { return true; }
		var snapshot = generation;
		var data = new FormData(form);
		data.set('op', 'save');
		data.set('autosave', '1');
		status.textContent = form.dataset.saving;
		pending = (async function () {
			try {
				var response = await fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin' });
				var result = await response.json();
				if (!response.ok || !result.success) {
					conflict = response.status === 409;
					throw new Error(result.data && result.data.message ? result.data.message : form.dataset.error);
				}
				form.elements.version.value = result.data.version;
				dirty = generation !== snapshot;
				status.textContent = dirty ? form.dataset.unsaved : form.dataset.saved;
				return true;
			} catch (error) {
				status.textContent = (error instanceof SyntaxError || error instanceof TypeError) ? form.dataset.error : (error.message || form.dataset.error);
				return false;
			} finally {
				pending = null;
			}
		})();
		var success = await pending;
		if (success && dirty) { return save(); }
		return success;
	}
	form.addEventListener('submit', async function (event) {
		if (navigating) { return; }
		event.preventDefault();
		var button = event.submitter;
		var op = button ? button.value : 'save';
		if (!(await save())) { return; }
		if (op !== 'save') {
			navigating = true;
			form.requestSubmit(button);
		}
	});
	window.addEventListener('beforeunload', function (event) {
		if (!navigating && (dirty || pending)) { event.preventDefault(); event.returnValue = ''; }
	});
})();
