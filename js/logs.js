(function () {
	'use strict';

	var FILTER_STORAGE_KEY = 'lck-logs-viewer-min-level';
	var VIEWER_ACCUM_MAX = 2097152;

	function root() {
		return document.getElementById('app-content');
	}

	function logsRoot() {
		return document.querySelector('.lck-logs');
	}

	function urls() {
		var el = root();
		if (!el) {
			return {};
		}
		try {
			return JSON.parse(el.getAttribute('data-lck-urls') || '{}');
		} catch (e) {
			return {};
		}
	}

	function token() {
		return (window.OC && OC.requestToken) ? OC.requestToken : '';
	}

	function t(msg) {
		return (window.t) ? window.t('logcheck', msg) : msg;
	}

	function toastOk(msg) {
		if (window.LogCheckToasts && typeof LogCheckToasts.showSuccess === 'function') {
			LogCheckToasts.showSuccess(msg);
		}
	}

	function toastErr(msg) {
		if (window.LogCheckToasts && typeof LogCheckToasts.showError === 'function') {
			LogCheckToasts.showError(msg);
		}
	}

	function reloadAfterMutate() {
		window.setTimeout(function () {
			try {
				window.location.reload();
			} catch (e) {
				window.location.href = window.location.pathname + window.location.search;
			}
		}, 400);
	}

	function formatBytes(bytes) {
		var n = Number(bytes) || 0;
		if (n < 1024) {
			return n + ' B';
		}
		if (n < 1048576) {
			return (Math.round(n / 102.4) / 10) + ' KB';
		}
		return (Math.round(n / 104857.6) / 10) + ' MB';
	}

	function selectedFile() {
		var checked = document.querySelector('input[name="lck-logs-file"]:checked');
		if (checked && checked.value) {
			return String(checked.value);
		}
		var section = logsRoot();
		return section ? String(section.getAttribute('data-lck-live-file') || '') : '';
	}

	function isLiveSelected() {
		var checked = document.querySelector('input[name="lck-logs-file"]:checked');
		if (checked) {
			return checked.getAttribute('data-live') === '1';
		}
		return true;
	}

	function getViewerMinLevel() {
		try {
			var stored = sessionStorage.getItem(FILTER_STORAGE_KEY);
			if (stored !== null && stored !== '') {
				return clampViewerMinLevel(parseInt(stored, 10));
			}
		} catch (e) { /* private mode */ }
		return 0;
	}

	function setViewerMinLevel(level) {
		level = clampViewerMinLevel(level);
		viewState.viewerMinLevel = level;
		try {
			sessionStorage.setItem(FILTER_STORAGE_KEY, String(level));
		} catch (e) { /* private mode */ }
		syncFilterChips(level);
	}

	function clampViewerMinLevel(level) {
		if (!Number.isFinite(level) || level < 0) {
			return 0;
		}
		if (level > 5) {
			return 5;
		}
		return level;
	}

	function minNcLevelForViewer(viewerMinLevel) {
		switch (viewerMinLevel) {
			case 2: return 1;
			case 3: return 2;
			case 4: return 3;
			case 5: return 4;
			default: return null;
		}
	}

	function ncLevelFromLine(text) {
		var line = String(text || '').trim();
		if (line === '' || line.charAt(0) !== '{') {
			return -1;
		}
		try {
			var data = JSON.parse(line);
			if (data && typeof data.level === 'number') {
				return Math.max(0, Math.min(4, data.level));
			}
			if (data && typeof data.level === 'string') {
				return ncLevelFromName(data.level);
			}
		} catch (e) { /* malformed JSON */ }
		return -1;
	}

	function ncLevelFromName(name) {
		switch (String(name || '').toLowerCase().trim()) {
			case 'debug': return 0;
			case 'info': return 1;
			case 'warning':
			case 'warn': return 2;
			case 'error': return 3;
			case 'fatal': return 4;
			default: return -1;
		}
	}

	function lineMatchesViewer(text, viewerMinLevel) {
		var min = minNcLevelForViewer(viewerMinLevel);
		if (min === null) {
			return true;
		}
		var level = ncLevelFromLine(text);
		if (level < 0) {
			return false;
		}
		return level >= min;
	}

	function levelLabel(level) {
		switch (level) {
			case 0: return t('Debug');
			case 1: return t('Info');
			case 2: return t('Warning');
			case 3: return t('Error');
			case 4: return t('Fatal');
			default: return t('Unknown');
		}
	}

	function levelClass(level) {
		if (level < 0) {
			return 'lck-logs-level--unknown';
		}
		return 'lck-logs-level--' + level;
	}

	function withFileParam(url) {
		var file = selectedFile();
		if (!file || !url) {
			return url;
		}
		var sep = url.indexOf('?') >= 0 ? '&' : '?';
		return url + sep + 'file=' + encodeURIComponent(file);
	}

	function withViewerParam(url) {
		if (!url) {
			return url;
		}
		var sep = url.indexOf('?') >= 0 ? '&' : '?';
		return url + sep + 'viewer_min_level=' + encodeURIComponent(String(viewState.viewerMinLevel));
	}

	function syncChrome() {
		var live = isLiveSelected();
		var banner = document.getElementById('lck-logs-archive-banner');
		var actions = document.getElementById('lck-logs-actions');
		var deleteCopy = document.getElementById('lck-logs-delete-copy');
		var section = logsRoot();
		var file = selectedFile();
		if (section) {
			section.setAttribute('data-lck-selected-file', file);
		}
		if (banner) {
			if (live) {
				banner.setAttribute('hidden', 'hidden');
			} else {
				banner.removeAttribute('hidden');
			}
		}
		if (actions) {
			if (live) {
				actions.removeAttribute('hidden');
				actions.setAttribute('aria-hidden', 'false');
			} else {
				actions.setAttribute('hidden', 'hidden');
				actions.setAttribute('aria-hidden', 'true');
			}
		}
		if (deleteCopy) {
			if (live) {
				deleteCopy.setAttribute('hidden', 'hidden');
			} else {
				deleteCopy.removeAttribute('hidden');
			}
		}
	}

	function syncFilterChips(level) {
		document.querySelectorAll('input[name="lck-logs-filter"]').forEach(function (input) {
			var chip = input.closest('.lck-logs-filter-chip');
			var active = String(input.value) === String(level);
			input.checked = active;
			if (chip) {
				chip.classList.toggle('is-active', active);
			}
		});
	}

	async function getJson(url) {
		var res = await fetch(url, {
			method: 'GET',
			headers: { 'requesttoken': token() },
			credentials: 'same-origin'
		});
		var data = {};
		var ct = (res.headers.get('content-type') || '').toLowerCase();
		if (ct.indexOf('application/json') >= 0) {
			try {
				data = await res.json();
			} catch (e) {}
		} else if (res.status === 404) {
			data = {
				message: t('This log action is not available yet. Reload the page. If it still fails, disable and re-enable HealthCheck, then try again.')
			};
		} else if (res.status === 401 || res.status === 403) {
			data = { message: t('Not authorized.') };
		} else {
			data = { message: t('Cannot read the log file. Check permissions.') };
		}
		return { status: res.status, data: data };
	}

	async function postJson(url, body) {
		var res = await fetch(url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'requesttoken': token()
			},
			credentials: 'same-origin',
			body: JSON.stringify(body || {})
		});
		var data = {};
		var ct = (res.headers.get('content-type') || '').toLowerCase();
		if (ct.indexOf('application/json') >= 0) {
			try {
				data = await res.json();
			} catch (e) {}
		} else if (res.status === 404) {
			data = {
				message: t('This log action is not available yet. Reload the page. If it still fails, disable and re-enable HealthCheck, then try again.')
			};
		}
		return { status: res.status, data: data };
	}

	var viewState = {
		rows: [],
		fromOffset: 0,
		size: 0,
		truncated: false,
		mode: 'tail',
		accumBytes: 0,
		rawMode: false,
		viewerMinLevel: 0,
		filterActive: false
	};

	function linesToText(rows) {
		return (rows || []).map(function (row) {
			return row && typeof row.text === 'string' ? row.text : '';
		}).join('\n');
	}

	function filterRowsClient(rows) {
		if (!viewState.filterActive && minNcLevelForViewer(viewState.viewerMinLevel) === null) {
			return rows || [];
		}
		return (rows || []).filter(function (row) {
			return lineMatchesViewer(row && row.text, viewState.viewerMinLevel);
		});
	}

	function parseLogLine(text) {
		var raw = String(text || '');
		var trimmed = raw.trim();
		if (trimmed === '' || trimmed.charAt(0) !== '{') {
			return {
				time: '',
				level: -1,
				levelLabel: levelLabel(-1),
				app: '',
				message: raw,
				raw: raw
			};
		}
		try {
			var data = JSON.parse(trimmed);
			var level = typeof data.level === 'number'
				? Math.max(0, Math.min(4, data.level))
				: ncLevelFromName(data.level);
			var timeRaw = data.time || data.reqTime || '';
			var time = formatLogTime(timeRaw);
			var app = typeof data.app === 'string' ? data.app : '';
			var message = typeof data.message === 'string'
				? data.message
				: (typeof data.msg === 'string' ? data.msg : trimmed);
			return {
				time: time,
				level: level,
				levelLabel: levelLabel(level),
				app: app,
				message: message,
				raw: raw
			};
		} catch (e) {
			return {
				time: '',
				level: -1,
				levelLabel: levelLabel(-1),
				app: '',
				message: raw,
				raw: raw
			};
		}
	}

	function formatLogTime(value) {
		if (value === null || value === undefined || value === '') {
			return '';
		}
		var d;
		if (typeof value === 'number') {
			d = new Date(value > 1e12 ? value : value * 1000);
		} else {
			var s = String(value);
			if (/^\d+$/.test(s)) {
				var n = parseInt(s, 10);
				d = new Date(n > 1e12 ? n : n * 1000);
			} else {
				d = new Date(s);
			}
		}
		if (isNaN(d.getTime())) {
			return String(value);
		}
		try {
			return d.toLocaleString(undefined, {
				year: 'numeric',
				month: '2-digit',
				day: '2-digit',
				hour: '2-digit',
				minute: '2-digit',
				second: '2-digit'
			});
		} catch (e) {
			var pad = function (n) { return n < 10 ? '0' + n : String(n); };
			return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
				+ ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
		}
	}

	function readableLineText(parsed) {
		var parts = [];
		if (parsed.time) {
			parts.push(parsed.time);
		}
		parts.push(parsed.levelLabel);
		if (parsed.app) {
			parts.push(parsed.app);
		}
		parts.push(parsed.message);
		return parts.join('\t');
	}

	function setLoading(busy, message) {
		var region = document.getElementById('lck-logs-viewer-region');
		var loading = document.getElementById('lck-logs-loading');
		var viewer = document.getElementById('lck-logs-viewer');
		var raw = document.getElementById('lck-logs-viewer-raw');
		var empty = document.getElementById('lck-logs-empty');
		if (region) {
			region.setAttribute('aria-busy', busy ? 'true' : 'false');
		}
		if (loading) {
			if (busy) {
				loading.removeAttribute('hidden');
				loading.setAttribute('aria-busy', 'true');
				if (message) {
					loading.textContent = message;
				}
			} else {
				loading.setAttribute('hidden', 'hidden');
				loading.setAttribute('aria-busy', 'false');
				loading.textContent = t('Loading log…');
			}
		}
		if (busy) {
			if (viewer) {
				viewer.setAttribute('hidden', 'hidden');
			}
			if (raw) {
				raw.setAttribute('hidden', 'hidden');
			}
			if (empty) {
				empty.setAttribute('hidden', 'hidden');
			}
		}
	}

	function buildStructuredRow(parsed) {
		var row = document.createElement('div');
		row.className = 'lck-logs-row';

		if (parsed.time) {
			var timeEl = document.createElement('time');
			timeEl.className = 'lck-logs-row__time';
			timeEl.textContent = parsed.time;
			row.appendChild(timeEl);
		}

		var levelWrap = document.createElement('span');
		levelWrap.className = 'lck-logs-level ' + levelClass(parsed.level);
		var dot = document.createElement('span');
		dot.className = 'lck-logs-level__dot';
		dot.setAttribute('aria-hidden', 'true');
		var levelText = document.createElement('span');
		levelText.className = 'lck-logs-level__label';
		levelText.textContent = parsed.levelLabel;
		levelWrap.appendChild(dot);
		levelWrap.appendChild(levelText);
		row.appendChild(levelWrap);

		if (parsed.app) {
			var appEl = document.createElement('span');
			appEl.className = 'lck-logs-row__app lck-mono';
			appEl.textContent = parsed.app;
			row.appendChild(appEl);
		}

		var msgEl = document.createElement('span');
		msgEl.className = 'lck-logs-row__message';
		msgEl.textContent = parsed.message;
		row.appendChild(msgEl);

		return row;
	}

	function updateMoreChrome() {
		var loadOlderRow = document.getElementById('lck-logs-load-older-row');
		var loadOlder = document.getElementById('lck-logs-load-older');
		var emptyLoadOlder = document.getElementById('lck-logs-empty-load-older');
		var hint = document.getElementById('lck-logs-hint');
		var showLoadOlder = viewState.mode !== 'search' && (viewState.truncated || viewState.fromOffset > 0);
		if (loadOlderRow) {
			if (showLoadOlder) {
				loadOlderRow.removeAttribute('hidden');
			} else {
				loadOlderRow.setAttribute('hidden', 'hidden');
			}
		}
		var canLoad = viewState.truncated && viewState.fromOffset > 0 && viewState.accumBytes < VIEWER_ACCUM_MAX;
		if (loadOlder) {
			loadOlder.disabled = !canLoad;
			loadOlder.setAttribute('aria-disabled', canLoad ? 'false' : 'true');
		}
		if (emptyLoadOlder) {
			if (canLoad) {
				emptyLoadOlder.removeAttribute('hidden');
			} else {
				emptyLoadOlder.setAttribute('hidden', 'hidden');
			}
		}
		if (hint) {
			var canDownload = !!document.getElementById('lck-logs-download');
			if (viewState.mode === 'search') {
				hint.textContent = canDownload
					? t('Search looks in the recent part of the file. Download the full file to search everything offline.')
					: t('Search looks in the recent part of the file.');
			} else if (!viewState.truncated && viewState.fromOffset === 0) {
				hint.textContent = t('Showing the full file.');
			} else if (viewState.accumBytes >= VIEWER_ACCUM_MAX && viewState.truncated) {
				hint.textContent = canDownload
					? t('In-browser view is capped for safety. Download the full file to read or copy everything.')
					: t('In-browser view is capped for safety. Ask a Nextcloud admin to download the full file if you need everything.');
			} else if (viewState.filterActive) {
				hint.textContent = canDownload
					? t('Severity filter is on. Load older lines or download the full file for more.')
					: t('Severity filter is on. Load older lines for more.');
			} else {
				hint.textContent = canDownload
					? t('Showing the newest part of the file. Load older lines, or download the full file to copy everything.')
					: t('Showing the newest part of the file. Load older lines to see more.');
			}
		}
	}

	function filterEmptyMessage() {
		switch (viewState.viewerMinLevel) {
			case 4: return t('No errors in this part of the log');
			case 3: return t('No warnings or errors in this part of the log');
			default: return t('No matching lines in this part of the log');
		}
	}

	function renderViewer(opts) {
		opts = opts || {};
		var viewer = document.getElementById('lck-logs-viewer');
		var rawPre = document.getElementById('lck-logs-viewer-raw');
		var status = document.getElementById('lck-logs-status');
		var empty = document.getElementById('lck-logs-empty');
		var emptyText = document.getElementById('lck-logs-empty-text');
		if (!viewer) {
			return;
		}

		var visibleRows = filterRowsClient(viewState.rows);
		var count = visibleRows.length;

		if (count === 0 && viewState.rows.length > 0 && viewState.filterActive) {
			if (empty) {
				empty.removeAttribute('hidden');
			}
			if (emptyText) {
				emptyText.textContent = filterEmptyMessage();
			}
			viewer.setAttribute('hidden', 'hidden');
			if (rawPre) {
				rawPre.setAttribute('hidden', 'hidden');
			}
		} else {
			if (empty) {
				empty.setAttribute('hidden', 'hidden');
			}
			if (viewState.rawMode) {
				viewer.setAttribute('hidden', 'hidden');
				if (rawPre) {
					rawPre.removeAttribute('hidden');
					rawPre.textContent = linesToText(visibleRows);
				}
			} else {
				if (rawPre) {
					rawPre.setAttribute('hidden', 'hidden');
				}
				viewer.removeAttribute('hidden');
				viewer.textContent = '';
				visibleRows.forEach(function (row) {
					viewer.appendChild(buildStructuredRow(parseLogLine(row.text)));
				});
			}
		}

		if (status) {
			if (viewState.mode === 'search') {
				if (count === 0) {
					status.textContent = t('No matches for this search.');
				} else {
					status.textContent = t('Matches found: %s').replace('%s', String(count));
				}
			} else if (count === 0 && viewState.rows.length === 0) {
				status.textContent = t('Log is empty.');
			} else if (viewState.filterActive) {
				status.textContent = t('Lines shown: %s (filtered)').replace('%s', String(count));
			} else {
				status.textContent = t('Lines shown: %s').replace('%s', String(count));
			}
		}

		var scrollTarget = viewState.rawMode ? rawPre : viewer;
		if (scrollTarget && (!empty || empty.hasAttribute('hidden'))) {
			if (opts.preserveScroll && scrollTarget) {
				/* preserve handled by caller storing scroll metrics */
			} else if (scrollTarget) {
				scrollTarget.scrollTop = scrollTarget.scrollHeight;
			}
		}
		updateMoreChrome();
	}

	function renderLines(rows, mode, opts) {
		opts = opts || {};
		var scrollTarget = viewState.rawMode
			? document.getElementById('lck-logs-viewer-raw')
			: document.getElementById('lck-logs-viewer');
		var prevHeight = scrollTarget ? scrollTarget.scrollHeight : 0;
		var prevTop = scrollTarget ? scrollTarget.scrollTop : 0;

		viewState.rows = rows || [];
		viewState.mode = mode || 'tail';
		renderViewer(opts);

		if (opts.preserveScroll && scrollTarget) {
			scrollTarget.scrollTop = scrollTarget.scrollHeight - prevHeight + prevTop;
		}
	}

	function syncRawToggle() {
		var btn = document.getElementById('lck-logs-raw-toggle');
		if (!btn) {
			return;
		}
		btn.setAttribute('aria-pressed', viewState.rawMode ? 'true' : 'false');
		btn.textContent = viewState.rawMode ? t('Show readable') : t('Show raw lines');
	}

	function roleTitle(row) {
		if (row && row.is_live) {
			return t('Current log (watched for alerts)');
		}
		if (row && row.role === 'archive') {
			return t('Saved copy');
		}
		return t('Older copy');
	}

	function formatMtime(ts) {
		if (!ts) {
			return '';
		}
		var d = new Date(Number(ts) * 1000);
		if (isNaN(d.getTime())) {
			return '';
		}
		var pad = function (n) { return n < 10 ? '0' + n : String(n); };
		return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
			+ ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
	}

	function renderFileList(payload, preferId) {
		var list = document.getElementById('lck-logs-file-list');
		var section = logsRoot();
		if (!list) {
			return;
		}
		var files = (payload && payload.files) || [];
		var live = (payload && payload.live) || (section ? section.getAttribute('data-lck-live-file') : '') || '';
		if (section && live) {
			section.setAttribute('data-lck-live-file', live);
		}
		list.textContent = '';
		if (!files.length) {
			var empty = document.createElement('p');
			empty.className = 'lck-muted';
			empty.id = 'lck-logs-files-empty';
			empty.textContent = t('No other log copies yet.');
			list.appendChild(empty);
			return;
		}
		var selected = preferId || selectedFile() || live;
		files.forEach(function (row, idx) {
			var id = String(row.id || '');
			if (!id) {
				return;
			}
			var radioId = 'lck-logs-file-' + idx;
			var label = document.createElement('label');
			label.className = 'lck-logs-file' + (row.is_live ? ' lck-logs-file--live' : '');
			label.htmlFor = radioId;
			if (!row.readable) {
				label.className += ' lck-logs-file--disabled';
			}

			var input = document.createElement('input');
			input.type = 'radio';
			input.className = 'lck-logs-file__input';
			input.name = 'lck-logs-file';
			input.id = radioId;
			input.value = id;
			input.setAttribute('data-role', String(row.role || ''));
			input.setAttribute('data-live', row.is_live ? '1' : '0');
			if (id === selected) {
				input.checked = true;
			}
			if (!row.readable || !row.exists) {
				input.disabled = true;
			}

			var body = document.createElement('span');
			body.className = 'lck-logs-file__body';

			var title = document.createElement('span');
			title.className = 'lck-logs-file__title';
			title.textContent = roleTitle(row);

			var name = document.createElement('span');
			name.className = 'lck-logs-file__name lck-mono';
			name.textContent = id;

			var meta = document.createElement('span');
			meta.className = 'lck-logs-file__meta';
			var size = document.createElement('span');
			size.textContent = formatBytes(row.size || 0);
			meta.appendChild(size);
			var mt = formatMtime(row.mtime);
			if (mt) {
				var mEl = document.createElement('span');
				mEl.textContent = mt;
				meta.appendChild(mEl);
			}

			body.appendChild(title);
			body.appendChild(name);
			body.appendChild(meta);
			label.appendChild(input);
			label.appendChild(body);
			list.appendChild(label);

			input.addEventListener('change', onFileChange);
		});
		syncChrome();
	}

	async function refreshFileList(preferId) {
		var u = urls();
		if (!u.apiLogFiles) {
			return;
		}
		var res = await getJson(u.apiLogFiles);
		if (res.status >= 200 && res.status < 300) {
			renderFileList(res.data, preferId);
		}
	}

	function applyApiFilterMeta(data) {
		if (typeof data.viewer_min_level === 'number') {
			viewState.viewerMinLevel = data.viewer_min_level;
		}
		viewState.filterActive = !!data.filter_active;
	}

	async function loadTail() {
		syncChrome();
		setLoading(true, t('Loading log…'));
		var u = urls();
		try {
			var res = await getJson(withViewerParam(withFileParam(u.apiLogTail || '')));
			if (res.status < 200 || res.status >= 300) {
				toastErr((res.data && res.data.message) || t('Cannot read the log file. Check permissions.'));
				return;
			}
			var rows = res.data.lines || [];
			viewState.fromOffset = typeof res.data.from_offset === 'number' ? res.data.from_offset : 0;
			viewState.size = typeof res.data.size === 'number' ? res.data.size : 0;
			viewState.truncated = !!res.data.truncated;
			viewState.accumBytes = linesToText(rows).length;
			applyApiFilterMeta(res.data);
			renderLines(rows, 'tail');
		} finally {
			setLoading(false);
		}
	}

	async function loadOlder() {
		if (viewState.mode === 'search') {
			return;
		}
		if (!(viewState.truncated && viewState.fromOffset > 0)) {
			return;
		}
		if (viewState.accumBytes >= VIEWER_ACCUM_MAX) {
			toastErr(t('In-browser view is capped for safety. Download the full file to read or copy everything.'));
			updateMoreChrome();
			return;
		}
		var u = urls();
		if (!u.apiLogBefore) {
			return;
		}
		setLoading(true, t('Loading log…'));
		try {
			var base = withFileParam(u.apiLogBefore);
			var sep = base.indexOf('?') >= 0 ? '&' : '?';
			var url = withViewerParam(base + sep + 'before=' + encodeURIComponent(String(viewState.fromOffset)));
			var res = await getJson(url);
			if (res.status < 200 || res.status >= 300) {
				toastErr((res.data && res.data.message) || t('Cannot read the log file. Check permissions.'));
				return;
			}
			var older = res.data.lines || [];
			var merged = older.concat(viewState.rows || []);
			viewState.fromOffset = typeof res.data.from_offset === 'number' ? res.data.from_offset : 0;
			viewState.size = typeof res.data.size === 'number' ? res.data.size : viewState.size;
			viewState.truncated = !!res.data.truncated;
			viewState.accumBytes = linesToText(merged).length;
			applyApiFilterMeta(res.data);
			renderLines(merged, 'tail', { preserveScroll: true });
		} finally {
			setLoading(false);
		}
	}

	async function runSearch() {
		var input = document.getElementById('lck-logs-search');
		var q = input ? String(input.value || '').trim() : '';
		if (!q) {
			await loadTail();
			return;
		}
		setLoading(true, t('Searching…'));
		var u = urls();
		try {
			var base = withFileParam((u.apiLogSearch || '') + '?q=' + encodeURIComponent(q));
			var res = await getJson(withViewerParam(base));
			if (res.status < 200 || res.status >= 300) {
				toastErr((res.data && res.data.message) || t('Search failed. Try again.'));
				return;
			}
			viewState.fromOffset = 0;
			viewState.truncated = !!res.data.truncated;
			viewState.accumBytes = 0;
			applyApiFilterMeta(res.data);
			renderLines(res.data.matches || [], 'search');
		} finally {
			setLoading(false);
		}
	}

	function visibleCopyText() {
		var visibleRows = filterRowsClient(viewState.rows);
		if (viewState.rawMode) {
			return linesToText(visibleRows);
		}
		return visibleRows.map(function (row) {
			return readableLineText(parseLogLine(row.text));
		}).join('\n');
	}

	async function copyShown() {
		var text = visibleCopyText();
		if (!text) {
			toastErr(t('Nothing to copy yet.'));
			return;
		}
		try {
			if (navigator.clipboard && navigator.clipboard.writeText) {
				await navigator.clipboard.writeText(text);
			} else {
				var ta = document.createElement('textarea');
				ta.value = text;
				ta.setAttribute('readonly', 'readonly');
				ta.style.position = 'absolute';
				ta.style.left = '-9999px';
				document.body.appendChild(ta);
				ta.select();
				document.execCommand('copy');
				ta.remove();
			}
			toastOk(t('Copied to clipboard.'));
		} catch (e) {
			toastErr(t('Could not copy. Select the text and copy manually.'));
		}
	}

	function openConfirm(opts) {
		return new Promise(function (resolve) {
			var dialog = document.getElementById('lck-logs-confirm-dialog');
			var title = document.getElementById('lck-logs-confirm-title');
			var body = document.getElementById('lck-logs-confirm-body');
			var label = document.getElementById('lck-logs-confirm-label');
			var input = document.getElementById('lck-logs-confirm-input');
			var form = document.getElementById('lck-logs-confirm-form');
			var cancel = document.getElementById('lck-logs-confirm-cancel');
			var ok = document.getElementById('lck-logs-confirm-ok');
			if (!dialog || !form || !input) {
				resolve(null);
				return;
			}
			title.textContent = opts.title || '';
			body.textContent = opts.body || '';
			label.textContent = opts.label || '';
			input.value = '';
			input.setAttribute('placeholder', opts.word || '');
			ok.className = 'lck-btn ' + (opts.danger ? 'lck-btn--danger' : 'lck-btn--primary');

			function cleanup(result) {
				form.removeEventListener('submit', onSubmit);
				cancel.removeEventListener('click', onCancel);
				dialog.removeEventListener('cancel', onEsc);
				if (typeof dialog.close === 'function') {
					dialog.close();
				} else {
					dialog.removeAttribute('open');
				}
				resolve(result);
			}

			function onSubmit(ev) {
				ev.preventDefault();
				var typed = String(input.value || '').trim();
				if (typed !== opts.word) {
					toastErr(opts.mismatch || t('Type the confirmation word exactly.'));
					input.focus();
					return;
				}
				cleanup(typed);
			}

			function onCancel(ev) {
				ev.preventDefault();
				cleanup(null);
			}

			function onEsc(ev) {
				ev.preventDefault();
				cleanup(null);
			}

			form.addEventListener('submit', onSubmit);
			cancel.addEventListener('click', onCancel);
			dialog.addEventListener('cancel', onEsc);

			if (typeof dialog.showModal === 'function') {
				dialog.showModal();
			} else {
				dialog.setAttribute('open', 'open');
			}
			input.focus();
		});
	}

	async function downloadFullFile() {
		var u = urls();
		if (!u.apiLogDownload) {
			toastErr(t('This log action is not available yet. Reload the page. If it still fails, disable and re-enable HealthCheck, then try again.'));
			return;
		}
		var file = selectedFile();
		var res = await fetch(u.apiLogDownload, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'requesttoken': token()
			},
			credentials: 'same-origin',
			body: JSON.stringify({ file: file || '' })
		});
		var ct = (res.headers.get('content-type') || '').toLowerCase();
		if (res.status < 200 || res.status >= 300) {
			var errMsg = t('Could not download the log file.');
			if (ct.indexOf('application/json') >= 0) {
				try {
					var errBody = await res.json();
					if (errBody && errBody.message) {
						errMsg = errBody.message;
					}
				} catch (e) {}
			} else if (res.status === 404) {
				errMsg = t('This log action is not available yet. Reload the page. If it still fails, disable and re-enable HealthCheck, then try again.');
			}
			toastErr(errMsg);
			return;
		}
		var blob = await res.blob();
		var disp = res.headers.get('content-disposition') || '';
		var name = selectedFile() || 'nextcloud.log';
		var m = /filename="([^"]+)"/i.exec(disp);
		if (m && m[1]) {
			name = m[1];
		}
		var objectUrl = URL.createObjectURL(blob);
		var a = document.createElement('a');
		a.href = objectUrl;
		a.download = name;
		document.body.appendChild(a);
		a.click();
		a.remove();
		URL.revokeObjectURL(objectUrl);
		toastOk(t('Download started.'));
	}

	async function startFresh() {
		if (!isLiveSelected()) {
			toastErr(t('Switch to the current log first.'));
			return;
		}
		var word = 'START_FRESH';
		var typed = await openConfirm({
			title: t('Start a fresh log?'),
			body: t('The current log is renamed aside. A new empty log is created. Alerts keep working from the new file.'),
			label: t('Type START_FRESH to confirm'),
			word: word,
			mismatch: t('Type START_FRESH to confirm.'),
			danger: false
		});
		if (!typed) {
			return;
		}
		var u = urls();
		var res = await postJson(u.apiLogStartFresh, { confirm: typed });
		if (res.status < 200 || res.status >= 300) {
			toastErr((res.data && res.data.message) || t('Could not start a fresh log.'));
			return;
		}
		try {
			toastOk(t('Fresh log started.'));
		} catch (e) { /* reload is mandatory */ }
		reloadAfterMutate();
	}

	async function deleteLog() {
		if (!isLiveSelected()) {
			toastErr(t('Switch to the current log first.'));
			return;
		}
		var word = 'DELETE';
		var typed = await openConfirm({
			title: t('Delete the log file?'),
			body: t('This permanently deletes the current log. Prefer “Start fresh log” if you want to keep a copy.'),
			label: t('Type DELETE to confirm'),
			word: word,
			mismatch: t('Type DELETE to confirm.'),
			danger: true
		});
		if (!typed) {
			return;
		}
		var u = urls();
		var res = await postJson(u.apiLogDelete, { confirm: typed });
		if (res.status < 200 || res.status >= 300) {
			toastErr((res.data && res.data.message) || t('Could not delete the log file.'));
			return;
		}
		try {
			toastOk(t('Log file deleted.'));
		} catch (e) { /* reload is mandatory */ }
		reloadAfterMutate();
	}

	async function deleteCopy() {
		if (isLiveSelected()) {
			return;
		}
		var file = selectedFile();
		if (!file) {
			return;
		}
		var word = 'DELETE_COPY';
		var typed = await openConfirm({
			title: t('Delete this log copy?'),
			body: t('This permanently deletes this older copy. The current log is not changed.'),
			label: t('Type DELETE_COPY to confirm'),
			word: word,
			mismatch: t('Type DELETE_COPY to confirm.'),
			danger: true
		});
		if (!typed) {
			return;
		}
		var u = urls();
		var res = await postJson(u.apiLogDeleteCopy, { confirm: typed, file: file });
		if (res.status < 200 || res.status >= 300) {
			toastErr((res.data && res.data.message) || t('Could not delete this log copy.'));
			return;
		}
		try {
			toastOk(t('Log copy deleted.'));
		} catch (e) { /* reload is mandatory */ }
		reloadAfterMutate();
	}

	function onFilterChange(ev) {
		var input = ev.target;
		if (!input || input.name !== 'lck-logs-filter') {
			return;
		}
		var level = clampViewerMinLevel(parseInt(String(input.value), 10));
		setViewerMinLevel(level);
		viewState.filterActive = minNcLevelForViewer(level) !== null;
		renderViewer();
	}

	function onShowAllLevels() {
		setViewerMinLevel(0);
		viewState.filterActive = false;
		syncFilterChips(0);
		if (viewState.mode === 'search') {
			runSearch();
		} else {
			loadTail();
		}
	}

	function onRawToggle() {
		viewState.rawMode = !viewState.rawMode;
		syncRawToggle();
		renderViewer();
	}

	function onFileChange() {
		syncChrome();
		var search = document.getElementById('lck-logs-search');
		if (search) {
			search.value = '';
		}
		loadTail();
	}

	function init() {
		var el = root();
		if (!el || el.getAttribute('data-lck-page') !== 'logs') {
			return;
		}

		viewState.viewerMinLevel = getViewerMinLevel();
		viewState.filterActive = minNcLevelForViewer(viewState.viewerMinLevel) !== null;
		syncFilterChips(viewState.viewerMinLevel);
		syncRawToggle();

		document.querySelectorAll('input[name="lck-logs-file"]').forEach(function (input) {
			input.addEventListener('change', onFileChange);
		});
		document.querySelectorAll('input[name="lck-logs-filter"]').forEach(function (input) {
			input.addEventListener('change', onFilterChange);
		});
		syncChrome();

		var viewer = document.getElementById('lck-logs-viewer');
		if (viewer) {
			loadTail();
		}
		var searchBtn = document.getElementById('lck-logs-search-btn');
		var searchInput = document.getElementById('lck-logs-search');
		var reload = document.getElementById('lck-logs-reload');
		var copy = document.getElementById('lck-logs-copy');
		var fresh = document.getElementById('lck-logs-start-fresh');
		var del = document.getElementById('lck-logs-delete');
		var delCopy = document.getElementById('lck-logs-delete-copy');
		var loadOlderBtn = document.getElementById('lck-logs-load-older');
		var emptyLoadOlder = document.getElementById('lck-logs-empty-load-older');
		var downloadBtn = document.getElementById('lck-logs-download');
		var rawToggle = document.getElementById('lck-logs-raw-toggle');
		var showAll = document.getElementById('lck-logs-show-all');

		if (searchBtn) {
			searchBtn.addEventListener('click', function () { runSearch(); });
		}
		if (searchInput) {
			searchInput.addEventListener('keydown', function (ev) {
				if (ev.key === 'Enter') {
					ev.preventDefault();
					runSearch();
				}
			});
		}
		if (reload) {
			reload.addEventListener('click', function () { loadTail(); });
		}
		if (copy) {
			copy.addEventListener('click', function () { copyShown(); });
		}
		if (fresh) {
			fresh.addEventListener('click', function () { startFresh(); });
		}
		if (del) {
			del.addEventListener('click', function () { deleteLog(); });
		}
		if (delCopy) {
			delCopy.addEventListener('click', function () { deleteCopy(); });
		}
		if (loadOlderBtn) {
			loadOlderBtn.addEventListener('click', function () { loadOlder(); });
		}
		if (emptyLoadOlder) {
			emptyLoadOlder.addEventListener('click', function () { loadOlder(); });
		}
		if (downloadBtn) {
			downloadBtn.addEventListener('click', function () { downloadFullFile(); });
		}
		if (rawToggle) {
			rawToggle.addEventListener('click', function () { onRawToggle(); });
		}
		if (showAll) {
			showAll.addEventListener('click', function () { onShowAllLevels(); });
		}
		updateMoreChrome();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
