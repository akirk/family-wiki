(function () {
	'use strict';
	var panel = document.querySelector('[data-recorder]');
	var form = document.querySelector('[data-interview-form]');
	if (!panel || !form) { return; }
	var status = panel.querySelector('[data-recording-status]');
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
	function time() {
		var seconds = Math.floor((elapsed + (recorder && recorder.state === 'recording' ? Date.now() - started : 0)) / 1000);
		panel.querySelector('[data-recording-time]').textContent = Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');
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
				clearInterval(timer); stream.getTracks().forEach(function (track) { track.stop(); });
				blob = new Blob(chunks, { type: recorder.mimeType });
				if (localUrl) { URL.revokeObjectURL(localUrl); }
				localUrl = URL.createObjectURL(blob);
				panel.querySelector('[data-local-audio]').src = localUrl;
				download.href = localUrl; download.download = 'interview.' + extension;
				preview.hidden = false; save.disabled = false; pause.disabled = true; resume.disabled = true; stop.disabled = true;
				status.textContent = save.textContent;
			});
			recorder.addEventListener('error', function () { status.textContent = panel.dataset.error; if (recorder.state !== 'inactive') { recorder.stop(); } });
			recorder.start(1000); unsaved = true; elapsed = 0; started = Date.now();
			preview.hidden = true; pause.disabled = false; stop.disabled = false;
			status.textContent = panel.dataset.recording; timer = setInterval(time, 500);
		} catch (error) { if (stream) { stream.getTracks().forEach(function (track) { track.stop(); }); } record.disabled = false; status.textContent = panel.dataset.unavailable; }
	});
	pause.addEventListener('click', function () { elapsed += Date.now() - started; recorder.pause(); pause.disabled = true; resume.disabled = false; status.textContent = panel.dataset.paused; time(); });
	resume.addEventListener('click', function () { started = Date.now(); recorder.resume(); pause.disabled = false; resume.disabled = true; status.textContent = panel.dataset.recording; });
	stop.addEventListener('click', function () { time(); recorder.stop(); });
	save.addEventListener('click', async function () {
		save.disabled = true; status.textContent = panel.dataset.saving;
		var data = new FormData();
		['_wpnonce', 'person_id', 'session_id'].forEach(function (name) { data.set(name, form.elements[name].value); });
		data.set('familypedia_recording', '1'); data.set('question', question); data.set('recording', blob, 'interview.' + extension);
		try {
			var response = await fetch(form.action, { method: 'POST', credentials: 'same-origin', body: data });
			var result = await response.json();
			if (!response.ok || !result.success) { throw new Error(); }
			var item = document.createElement('li');
			var audio = document.createElement('audio'); audio.controls = true; audio.preload = 'none'; audio.src = result.data.url;
			var link = document.createElement('a'); link.href = result.data.url; link.download = 'interview.' + extension; link.textContent = download.textContent;
			item.appendChild(audio); item.appendChild(link); panel.querySelector('[data-saved-recordings]').appendChild(item);
			unsaved = false; record.disabled = false; status.textContent = panel.dataset.saved;
		} catch (error) { save.disabled = false; status.textContent = panel.dataset.error; }
	});
	window.addEventListener('beforeunload', function (event) { if (unsaved) { event.preventDefault(); event.returnValue = ''; } });
})();
