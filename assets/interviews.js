/* Guided questions and serialized autosave; ordinary forms also work without JS. */
(function () {
	'use strict';
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
	var navigation = form.querySelector('[data-question-navigation]');
	var previous = form.querySelector('[data-previous]');
	var next = form.querySelector('[data-next]');
	var status = form.querySelector('[data-save-status]');
	var dirty = false;
	var generation = 0;
	var timer;
	var pending = null;
	var conflict = false;
	var navigating = false;

	function displayQuestion() {
		var selected = question.selectedOptions[0];
		if (selected) { topic.value = selected.parentElement.dataset.topic; }
		panels.forEach(function (panel) { panel.hidden = panel.dataset.question !== question.value; });
		var index = options.indexOf(selected);
		previous.disabled = index <= 0;
		next.disabled = index >= options.length - 1;
	}
	topic.addEventListener('change', function () {
		question.value = options.find(function (option) { return option.parentElement.dataset.topic === topic.value; }).value;
		displayQuestion();
	});
	question.addEventListener('change', displayQuestion);
	function move(step) {
		var index = options.indexOf(question.selectedOptions[0]);
		question.value = options[index + step].value;
		displayQuestion();
		question.dispatchEvent(new Event('change', { bubbles: true }));
		var panel = panels.find(function (item) { return !item.hidden; });
		panel.querySelector('textarea').focus();
	}
	previous.addEventListener('click', function () { move(-1); });
	next.addEventListener('click', function () { move(1); });
	navigation.hidden = false;
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
