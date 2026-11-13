/**
 * Bridge for the shared transfer page (apps/_shared/config-transfer):
 * exposes the window.HealthCheckApi / HealthCheckMessaging contract the
 * synced js/transfer.js expects, in HealthCheck's own API envelope
 * ({success,data} / {success:false,error:{code}}). App-specific — not synced.
 */
(function () {
	'use strict';
	function requestToken() {
		return (window.OC && OC.requestToken) || '';
	}
	async function parse(resp) {
		const j = await resp.json().catch(() => null);
		if (!resp.ok || (j && (j.ok === false || j.success === false))) {
			const errObj = j && j.error;
			throw {
				code: (errObj && (errObj.code || errObj)) || 'SERVER_ERROR',
				context: (j && j.context) || {},
			};
		}
		return j && Object.prototype.hasOwnProperty.call(j, 'data') ? j.data : j;
	}
	window.HealthCheckApi = {
		url(path) {
			return OC.generateUrl('/apps/logcheck' + path);
		},
		async get(path, params) {
			let u = this.url(path);
			if (params) {
				const q = Object.keys(params)
					.filter((k) => params[k] !== undefined && params[k] !== null)
					.map((k) => encodeURIComponent(k) + '=' + encodeURIComponent(params[k]))
					.join('&');
				if (q) u += '?' + q;
			}
			return parse(await fetch(u, { credentials: 'same-origin' }));
		},
		async post(path, body) {
			return parse(await fetch(this.url(path), {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', requesttoken: requestToken() },
				body: JSON.stringify(body || {}),
			}));
		},
		async upload(path, file, extra) {
			const fd = new FormData();
			fd.append('file', file);
			if (extra) {
				Object.keys(extra).forEach((k) => fd.append(k, extra[k]));
			}
			return parse(await fetch(this.url(path), {
				method: 'POST', credentials: 'same-origin',
				headers: { requesttoken: requestToken() },
				body: fd,
			}));
		},
	};
	// Messaging: the synced transfer.js asks for .alert/.polite — route both
	// to the app's own visible channel (LogCheckToasts, dedup on kind+text
	// with timer reset), and always mirror into the hidden
	// lck-transfer-result-live status region. OC.Notification is only a
	// last-resort fallback — it was removed from NC>=31 bundles, so it can
	// never be the only visible path (dead_platform_toast_api).
	function live(text) {
		const el = document.getElementById('lck-transfer-result-live');
		if (el) el.textContent = text;
	}
	function announce(text, kind) {
		const toasts = window.LogCheckToasts;
		if (toasts && typeof toasts.showError === 'function' && typeof toasts.showSuccess === 'function') {
			if (kind === 'error') {
				toasts.showError(text);
			} else {
				toasts.showSuccess(text);
			}
			return;
		}
		if (window.OC && OC.Notification && OC.Notification.showTemporary) {
			OC.Notification.showTemporary(text);
		}
	}
	window.HealthCheckMessaging = {
		alert(text) { announce(text, 'error'); live(text); },
		polite(text) { announce(text, 'success'); live(text); },
	};
})();
