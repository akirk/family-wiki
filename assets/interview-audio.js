(function () {
	'use strict';
	var panel = document.querySelector('[data-recorder]');
	var form = document.querySelector('[data-interview-form]');
	if (!panel || !form) { return; }
	var status = panel.querySelector('[data-recording-status]');
	var dock = panel.querySelector('[data-audio-dock]');
	document.body.classList.add('familypedia-has-recorder');
	if (window.ResizeObserver) {
		new ResizeObserver(function () { document.documentElement.style.setProperty('--familypedia-recorder-height', dock.offsetHeight + 'px'); }).observe(dock);
	}
	function timestamp(seconds) { seconds = Math.floor(seconds); return Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0'); }
	function markerList(markers, list, audio) {
		list.replaceChildren();
		markers.forEach(function (marker) {
			var item = document.createElement('li');
			var button = document.createElement('button'); button.type = 'button'; button.className = 'familypedia-button';
			button.disabled = !audio;
			button.textContent = timestamp(marker.time) + ' · ' + marker.label;
			button.addEventListener('click', function () { if (audio && audio.src) { audio.currentTime = marker.time; } });
			item.appendChild(button); list.appendChild(item);
		});
	}
	panel.querySelector('[data-saved-recordings]').addEventListener('click', function (event) {
		var button = event.target.closest('[data-seek]');
		if (button) { button.closest('[data-recording-id]').querySelector('audio').currentTime = Number(button.dataset.seek); }
	});

	panel.querySelector('[data-saved-recordings]').addEventListener('click', async function (event) {
		var button = event.target.closest('[data-rename-recording], [data-delete-recording]');
		if (!button) { return; }
		var item = button.closest('[data-recording-id]');
		var deleting = button.hasAttribute('data-delete-recording');
		if (deleting && !window.confirm(panel.dataset.confirmDelete)) { return; }
		var buttons = Array.from(item.querySelectorAll('button'));
		buttons.forEach(function (control) { control.disabled = true; });
		var data = new FormData();
		['_wpnonce', 'person_id', 'session_id'].forEach(function (name) { data.set(name, form.elements[name].value); });
		data.set('familypedia_recording', '1'); data.set('recording_op', deleting ? 'delete' : 'rename');
		data.set('recording_id', item.dataset.recordingId); data.set('recording_name', item.querySelector('[data-clip-name]').value);
		try {
			var response = await fetch(form.action, { method: 'POST', credentials: 'same-origin', body: data });
			var result = await response.json();
			if (!response.ok || !result.success) { throw new Error(result.data && result.data.message ? result.data.message : panel.dataset.manageError); }
			if (deleting) { item.querySelector('audio').pause(); item.remove(); }
			status.textContent = panel.dataset.managed;
		} catch (error) { status.textContent = error.message || panel.dataset.manageError; }
		finally { buttons.forEach(function (control) { control.disabled = false; }); }
	});
	if (!window.MediaRecorder || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { status.textContent = panel.dataset.unavailable; return; }
	panel.querySelector('[data-recording-controls]').hidden = false;
	var record = panel.querySelector('[data-record]');
	var pause = panel.querySelector('[data-pause]');
	var resume = panel.querySelector('[data-resume]');
	var stop = panel.querySelector('[data-stop]');
	var save = panel.querySelector('[data-save-recording]');
	var preview = panel.querySelector('[data-recording-preview]');
	var download = panel.querySelector('[data-local-download]');
	var recorder, stream, blob, localUrl, started, elapsed = 0, timer, question, extension;
	var unsaved = false;
	var markers = [];
	function seconds() { return (elapsed + (started != null ? performance.now() - started : 0)) / 1000; }
	function markQuestion() {
		if (!recorder || recorder.state !== 'recording' || markers.length >= 1000) { return; }
		var option = form.elements.question.selectedOptions[0];
		if (!option || (markers.length && markers[markers.length - 1].question === option.value)) { return; }
		markers.push({ question: option.value, label: option.textContent, time: markers.length ? seconds() : 0 });
		markerList(markers, panel.querySelector('[data-live-markers]'), null);
	}
	form.addEventListener('change', function (event) { if (event.target === form.elements.question || event.target === form.elements.topic) { markQuestion(); } });
	function time() {
		panel.querySelector('[data-recording-time]').textContent = timestamp(seconds());
	}
	record.addEventListener('click', async function () {
		record.disabled = true;
		try {
			stream = await navigator.mediaDevices.getUserMedia({ audio: true });
			var mime = ['audio/webm;codecs=opus', 'audio/mp4', 'audio/ogg;codecs=opus'].find(function (type) { return MediaRecorder.isTypeSupported(type); });
			recorder = mime ? new MediaRecorder(stream, { mimeType: mime }) : new MediaRecorder(stream);
			extension = recorder.mimeType.includes('mp4') ? 'm4a' : (recorder.mimeType.includes('ogg') ? 'ogg' : 'webm');
			var chunks = [], size = 0;
			question = form.elements.question.value;
			recorder.addEventListener('dataavailable', function (event) {
				if (event.data.size) { chunks.push(event.data); size += event.data.size; }
				if (size >= 20 * 1024 * 1024 && recorder.state !== 'inactive') { recorder.stop(); }
			});
			recorder.addEventListener('stop', function () {
				if (started != null) { elapsed += performance.now() - started; started = null; } time();
				clearInterval(timer); stream.getTracks().forEach(function (track) { track.stop(); });
				blob = new Blob(chunks, { type: recorder.mimeType });
				if (localUrl) { URL.revokeObjectURL(localUrl); }
				localUrl = URL.createObjectURL(blob);
				panel.querySelector('[data-local-audio]').src = localUrl;
				markerList(markers, panel.querySelector('[data-live-markers]'), panel.querySelector('[data-local-audio]'));
				download.href = localUrl; download.download = 'interview.' + extension;
				preview.hidden = false; save.disabled = false; pause.disabled = true; resume.disabled = true; stop.disabled = true;
				status.textContent = panel.dataset.ready;
			});
			recorder.addEventListener('error', function () { status.textContent = panel.dataset.error; if (recorder.state !== 'inactive') { recorder.stop(); } });
			recorder.start(1000); unsaved = true; elapsed = 0; started = performance.now();
			markers = []; markQuestion();
			panel.querySelector('[data-recording-name]').value = '';
			preview.hidden = true; pause.disabled = false; stop.disabled = false;
			status.textContent = panel.dataset.recording; timer = setInterval(time, 500);
		} catch (error) { if (stream) { stream.getTracks().forEach(function (track) { track.stop(); }); } record.disabled = false; status.textContent = panel.dataset.unavailable; }
	});
	pause.addEventListener('click', function () { elapsed += performance.now() - started; started = null; recorder.pause(); pause.disabled = true; resume.disabled = false; status.textContent = panel.dataset.paused; time(); });
	resume.addEventListener('click', function () { started = performance.now(); recorder.resume(); pause.disabled = false; resume.disabled = true; status.textContent = panel.dataset.recording; markQuestion(); });
	stop.addEventListener('click', function () { time(); recorder.stop(); });
	save.addEventListener('click', async function () {
		save.disabled = true; status.textContent = panel.dataset.saving;
		var data = new FormData();
		['_wpnonce', 'person_id', 'session_id'].forEach(function (name) { data.set(name, form.elements[name].value); });
		data.set('markers', JSON.stringify(markers));
		data.set('recording_name', panel.querySelector('[data-recording-name]').value);
		data.set('familypedia_recording', '1'); data.set('question', question); data.set('recording', blob, 'interview.' + extension);
		try {
			var response = await fetch(form.action, { method: 'POST', credentials: 'same-origin', body: data });
			var result = await response.json();
			if (!response.ok || !result.success) { throw new Error(); }
			var item = document.createElement('li');
			item.dataset.recordingId = result.data.id;
			var label = document.createElement('label'); label.textContent = panel.dataset.name;
			var name = document.createElement('input'); name.type = 'text'; name.setAttribute('data-clip-name', ''); name.value = result.data.name; label.appendChild(name); item.appendChild(label);
			var rename = document.createElement('button'); rename.type = 'button'; rename.className = 'familypedia-button'; rename.setAttribute('data-rename-recording', ''); rename.textContent = panel.dataset.rename; item.appendChild(rename);
			var remove = document.createElement('button'); remove.type = 'button'; remove.className = 'familypedia-button'; remove.setAttribute('data-delete-recording', ''); remove.textContent = panel.dataset.delete; item.appendChild(remove);
			var audio = document.createElement('audio'); audio.controls = true; audio.preload = 'none'; audio.src = result.data.url;
			var link = document.createElement('a'); link.href = result.data.url; link.download = 'interview.' + extension; link.textContent = download.textContent;
			item.appendChild(audio); item.appendChild(link);
			var timeline = document.createElement('ol'); timeline.className = 'familypedia-recording__markers'; markerList(markers, timeline, audio); item.appendChild(timeline); panel.querySelector('[data-saved-recordings]').appendChild(item);
			unsaved = false; record.disabled = false; status.textContent = panel.dataset.saved;
		} catch (error) { save.disabled = false; status.textContent = panel.dataset.error; }
	});
	window.addEventListener('beforeunload', function (event) { if (unsaved) { event.preventDefault(); event.returnValue = ''; } });
})();
