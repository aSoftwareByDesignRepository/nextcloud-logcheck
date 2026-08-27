(function () {
	'use strict';

	function urls() {
		var root = document.getElementById('app-content');
		if (!root) {
			return {};
		}
		try {
			return JSON.parse(root.getAttribute('data-lck-urls') || '{}');
		} catch (e) {
			return {};
		}
	}

	function token() {
		return (window.OC && OC.requestToken) ? OC.requestToken : '';
	}

	function settingsVersion() {
		var root = document.getElementById('app-content');
		return root ? parseInt(root.getAttribute('data-lck-settings-version') || '1', 10) : 1;
	}

	function setSettingsVersion(version) {
		var root = document.getElementById('app-content');
		if (root && version) {
			root.setAttribute('data-lck-settings-version', String(version));
		}
		var hidden = document.querySelector('input[name="expected_version"]');
		if (hidden && version) {
			hidden.value = String(version);
		}
	}

	function handleConflict(res) {
		if (res.status === 409) {
			if (window.LogCheckToasts) {
				LogCheckToasts.showError(t('logcheck', 'Settings changed elsewhere — reload and try again.'));
			}
			window.setTimeout(function () { window.location.reload(); }, 800);
			return true;
		}
		return false;
	}

	function chipGroup(el, hiddenInput) {
		if (!el) {
			return;
		}
		el.addEventListener('click', function (ev) {
			var btn = ev.target.closest('.lck-chip');
			if (!btn) {
				return;
			}
			el.querySelectorAll('.lck-chip').forEach(function (c) {
				c.classList.remove('is-active');
				c.setAttribute('aria-pressed', 'false');
			});
			btn.classList.add('is-active');
			btn.setAttribute('aria-pressed', 'true');
			if (hiddenInput) {
				hiddenInput.value = btn.getAttribute('data-value') || '';
			}
		});
	}

	async function postJson(url, body) {
		var res = await fetch(url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'requesttoken': token()
			},
			body: JSON.stringify(body)
		});
		var data = {};
		try {
			data = await res.json();
		} catch (e) {}
		return { status: res.status, data: data };
	}

	async function putJson(url, body) {
		var res = await fetch(url, {
			method: 'PUT',
			headers: {
				'Content-Type': 'application/json',
				'requesttoken': token()
			},
			body: JSON.stringify(body)
		});
		var data = {};
		try {
			data = await res.json();
		} catch (e) {}
		return { status: res.status, data: data };
	}

	function formatLastCheck(ts) {
		if (!ts) {
			return '';
		}
		var d = new Date(ts * 1000);
		var pad = function (n) { return n < 10 ? '0' + n : String(n); };
		return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
	}

	function applyHomeStatus(status) {
		if (!status || typeof status !== 'object') {
			return;
		}
		var badge = document.querySelector('.lck-status-card .lck-badge');
		if (badge) {
			badge.setAttribute('data-state', status.state || 'off');
			var labelEl = badge.querySelector('.lck-badge__label');
			if (labelEl && status.label) {
				labelEl.textContent = status.label;
			}
		}
		var checklist = document.getElementById('lck-alerts-checklist');
		if (checklist) {
			if (status.watch_enabled && !status.alerts_ready) {
				checklist.removeAttribute('hidden');
			} else {
				checklist.setAttribute('hidden', 'hidden');
			}
		}
		var desc = document.getElementById('lck-watching-desc');
		if (desc) {
			if (status.error) {
				desc.textContent = status.error;
			} else if (status.watch_enabled && status.last_check_at) {
				desc.textContent = t('logcheck', 'Last check') + ': ' + formatLastCheck(status.last_check_at);
			} else {
				desc.textContent = t('logcheck', 'When on, LogCheck checks for new errors in the background.');
			}
		}
		if (status.settings_version) {
			setSettingsVersion(status.settings_version);
		}
	}

	async function refreshHomeStatus() {
		var u = urls();
		if (!u.apiStatus) {
			return null;
		}
		try {
			var res = await fetch(u.apiStatus, { headers: { requesttoken: token() } });
			if (!res.ok) {
				return null;
			}
			var status = await res.json();
			applyHomeStatus(status);
			return status;
		} catch (e) {
			return null;
		}
	}

	function initHome() {
		var toggle = document.getElementById('lck-watch-toggle');
		if (toggle) {
			toggle.addEventListener('change', async function () {
				var u = urls();
				var res = await putJson(u.apiSave, {
					expected_version: settingsVersion(),
					settings: { watch_enabled: toggle.checked }
				});
				if (handleConflict(res)) {
					return;
				}
				if (res.status >= 200 && res.status < 300) {
					if (res.data && res.data.version) {
						setSettingsVersion(res.data.version);
					}
					LogCheckToasts.showSuccess(toggle.checked ? t('logcheck', 'Watching') : t('logcheck', 'Off'));
					await refreshHomeStatus();
				} else {
					LogCheckToasts.showError((res.data && res.data.message) || t('logcheck', 'Save failed.'));
					toggle.checked = !toggle.checked;
				}
			});
		}

		var checkAgain = document.getElementById('lck-check-again');
		async function runCheckAgain(btn) {
			if (btn) {
				btn.disabled = true;
				btn.setAttribute('aria-busy', 'true');
			}
			var u = urls();
			var res = await postJson(u.apiRun || (u.home.replace(/\/home$/, '') + '/api/run'), {});
			if (res.status >= 200 && res.status < 300) {
				LogCheckToasts.showSuccess(t('logcheck', 'Checked again.'));
				await refreshHomeStatus();
				window.location.reload();
			} else {
				LogCheckToasts.showError((res.data && res.data.message) || t('logcheck', 'Check failed. Try again.'));
				if (btn) {
					btn.disabled = false;
					btn.removeAttribute('aria-busy');
				}
			}
		}
		if (checkAgain) {
			checkAgain.addEventListener('click', function () {
				runCheckAgain(checkAgain);
			});
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		var root = document.getElementById('app-content');
		if (!root) {
			return;
		}
		if (root.getAttribute('data-lck-page') === 'home') {
			initHome();
		}
	});

	window.LogCheckApp = {
		urls: urls,
		token: token,
		putJson: putJson,
		postJson: postJson,
		handleConflict: handleConflict,
		settingsVersion: settingsVersion,
		setSettingsVersion: setSettingsVersion,
		chipGroup: chipGroup,
		applyHomeStatus: applyHomeStatus,
		refreshHomeStatus: refreshHomeStatus
	};
})();
