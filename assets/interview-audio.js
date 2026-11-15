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
	var stop = panel.querySelector('[data-stop]');
	var save = panel.querySelector('[data-save-recording]');
	var preview = panel.querySelector('[data-recording-preview]');
	var download = panel.querySelector('[data-local-download]');
	var recorder, stream, blob, localUrl, started, elapsed = 0, timer, question, extension;
	var audioContext, source, analyser, meterTimer;
	var level = panel.querySelector('[data-mic-level]');
	function setLevel(value) {
		level.setAttribute('aria-valuenow', String(value));
		Array.from(level.children).forEach(function (block) { block.classList.toggle('is-active', Number(block.dataset.level) <= value); });
	}
	var micHint = panel.querySelector('[data-mic-hint]');
	var lastSound = 0;
	function stopMeter() {
		clearInterval(meterTimer);
		if (source) { source.disconnect(); source = null; }
		if (audioContext) { audioContext.close().catch(function () {}); audioContext = null; }
		setLevel(0); micHint.textContent = '';
	}
	async function startMeter() {
		var AudioContext = window.AudioContext || window.webkitAudioContext;
		if (!AudioContext) { return; }
		try {
			audioContext = new AudioContext();
			source = audioContext.createMediaStreamSource(stream);
			analyser = audioContext.createAnalyser(); analyser.fftSize = 2048;
			source.connect(analyser);
			var samples = new Float32Array(analyser.fftSize);
			await audioContext.resume();
			if (!recorder || recorder.state === 'inactive') { stopMeter(); return; }
			panel.querySelector('[data-audio-level]').hidden = false;
			lastSound = performance.now();
			meterTimer = setInterval(function () {
				var now = performance.now();
				if (recorder.state !== 'recording') { setLevel(0); micHint.textContent = ''; lastSound = now; return; }
				analyser.getFloatTimeDomainData(samples);
				var sum = 0;
				for (var i = 0; i < samples.length; i++) { sum += samples[i] * samples[i]; }
				var db = Math.max(-60, Math.min(0, 20 * Math.log10(Math.sqrt(sum / samples.length) || 0.000001)));
				setLevel(db > -6 ? 4 : db > -18 ? 3 : db > -35 ? 2 : db > -50 ? 1 : 0);
				if (db > -50) { lastSound = now; }
				var hint = now - lastSound > 6000 ? micHint.dataset.silent : '';
				if (micHint.textContent !== hint) { micHint.textContent = hint; }
			}, 100);
		} catch (error) { stopMeter(); panel.querySelector('[data-audio-level]').hidden = true; }
	}
	var pausePreview = panel.querySelector('[data-pause-preview]');
	var pauseAudio = panel.querySelector('[data-pause-audio]');
	var pauseHint = panel.querySelector('[data-pause-hint]');
	var recent = panel.querySelector('[data-play-recent]');
	var checkRecordingLabel = recent.textContent;
	var previewStart = 0, previewLength = 5;
	function resetPlaybackLabel() { recent.textContent = checkRecordingLabel; }
	function updatePlaybackLabel() {
		if (!pauseAudio.paused && !pauseAudio.ended) {
			var played = Math.min(previewLength, Math.max(0, Math.floor(pauseAudio.currentTime - previewStart)));
			recent.textContent = recent.dataset.playing + ' ' + timestamp(played);
		}
	}
	pauseAudio.addEventListener('playing', updatePlaybackLabel);
	pauseAudio.addEventListener('timeupdate', updatePlaybackLabel);
	pauseAudio.addEventListener('pause', resetPlaybackLabel);
	pauseAudio.addEventListener('ended', resetPlaybackLabel);
	pauseAudio.addEventListener('error', resetPlaybackLabel);

	var pauseUrl, previewRequested = false;
	function clearPausePreview() {
		previewRequested = false; pauseAudio.pause(); resetPlaybackLabel(); pauseAudio.removeAttribute('src'); pauseAudio.load();
		if (pauseUrl) { URL.revokeObjectURL(pauseUrl); pauseUrl = null; }
		pausePreview.hidden = true; recent.hidden = true; pauseHint.textContent = '';
	}
	pauseAudio.addEventListener('error', function () { if (!pausePreview.hidden) { pauseHint.textContent = pauseHint.dataset.unavailable; recent.disabled = false; } });
	recent.addEventListener('click', async function () {
		try {
			pauseHint.textContent = '';
			previewStart = Math.max(0, seconds() - 5);
			previewLength = Math.min(5, Math.ceil(seconds()));
			pauseAudio.currentTime = previewStart;
			await pauseAudio.play();
		} catch (error) { pauseHint.textContent = pauseHint.dataset.unavailable; }
	});
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
		if (recorder && recorder.state === 'recording') {
			elapsed += performance.now() - started; started = null; recorder.pause();
			record.textContent = record.dataset.labelResume; stop.hidden = false;
			status.textContent = ''; time();
			clearPausePreview(); pausePreview.hidden = false; recent.hidden = false; recent.disabled = true; previewRequested = true; recorder.requestData();
			return;
		}
		if (recorder && recorder.state === 'paused') {
			clearPausePreview();
			started = performance.now(); recorder.resume();
			if (audioContext) { audioContext.resume().catch(function () {}); }
			lastSound = performance.now(); record.textContent = record.dataset.labelPause; stop.hidden = true;
			status.textContent = panel.dataset.recording; markQuestion();
			return;
		}

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
				if (previewRequested && recorder.state === 'paused') {
					previewRequested = false;
					pauseUrl = URL.createObjectURL(new Blob(chunks, { type: recorder.mimeType }));
					pauseAudio.src = pauseUrl;
					recent.disabled = false;
				}

				if (size >= 20 * 1024 * 1024 && recorder.state !== 'inactive') { recorder.stop(); }
			});
			recorder.addEventListener('stop', function () {
				clearPausePreview();
				if (started != null) { elapsed += performance.now() - started; started = null; } time();
				stopMeter(); clearInterval(timer); stream.getTracks().forEach(function (track) { track.stop(); });
				blob = new Blob(chunks, { type: recorder.mimeType });
				if (localUrl) { URL.revokeObjectURL(localUrl); }
				localUrl = URL.createObjectURL(blob);
				panel.querySelector('[data-local-audio]').src = localUrl;
				markerList(markers, panel.querySelector('[data-live-markers]'), panel.querySelector('[data-local-audio]'));
				download.href = localUrl; download.download = 'interview.' + extension;
				preview.hidden = false; save.disabled = false; record.disabled = true; record.textContent = record.dataset.labelRecord; stop.hidden = true;
				status.textContent = panel.dataset.ready;
			});
			recorder.addEventListener('error', function () { status.textContent = panel.dataset.error; if (recorder.state !== 'inactive') { recorder.stop(); } });
			recorder.start(1000); startMeter(); unsaved = true; elapsed = 0; started = performance.now();
			markers = []; markQuestion();
			panel.querySelector('[data-recording-name]').value = '';
			preview.hidden = true; record.disabled = false; record.textContent = record.dataset.labelPause; stop.hidden = true;
			status.textContent = panel.dataset.recording; timer = setInterval(time, 500);
		} catch (error) { stopMeter(); if (stream) { stream.getTracks().forEach(function (track) { track.stop(); }); } record.disabled = false; status.textContent = panel.dataset.unavailable; }
	});
	stop.addEventListener('click', function () { if (recorder && recorder.state === 'paused') { stop.hidden = true; record.disabled = true; time(); recorder.stop(); } });
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
