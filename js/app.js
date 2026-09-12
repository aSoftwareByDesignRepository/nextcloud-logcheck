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

	/**
	 * Keep Log alerts health card honest with LogHealthProbe after watch toggle (no SSR stale Watching).
	 * @param {Record<string, unknown>} status
	 */
	function applyLogHealthCard(status) {
		var card = document.querySelector('.lck-health-card[data-probe="log"]');
		if (!card) {
			return;
		}
		var supported = status.backend_supported !== false;
		var topologyOk = status.topology_ok !== false;
		var watch = !!status.watch_enabled;
		var lastCheck = typeof status.last_check_at === 'number' ? status.last_check_at : 0;
		var stale = !!status.stale;
		var error = typeof status.error === 'string' ? status.error : '';
		var statusState = typeof status.state === 'string' ? status.state : '';
		var u = urls();
		var alertsHref = u.alerts || '#';
		var logsHref = u.logs || '#';

		var cardState = 'unknown';
		var label = typeof status.label === 'string' ? status.label : '';
		var detail = '';
		/** @type {{ label: string, href: string|null, action?: string }[]} */
		var actions = [];

		if (!supported || !topologyOk) {
			cardState = 'critical';
			label = t('logcheck', 'Can\'t watch');
			detail = error || t('logcheck', 'Log watching is not available on this server.');
		} else if (!watch) {
			cardState = 'degraded';
			label = t('logcheck', 'Off');
			detail = t('logcheck', 'Set up alerts to get notified about new errors.');
			actions = [{ label: t('logcheck', 'Set up alerts'), href: alertsHref }];
		} else if (lastCheck <= 0) {
			cardState = 'degraded';
			label = t('logcheck', 'Not checked yet');
			detail = t('logcheck', 'Watching is on, but no background check has finished yet.');
		} else if (stale) {
			cardState = 'degraded';
			label = t('logcheck', 'Needs a check');
			detail = error || t('logcheck', 'Background checks look stuck.');
		} else if (error || statusState === 'degraded') {
			cardState = 'degraded';
			label = t('logcheck', 'Needs attention');
			detail = error || t('logcheck', 'The last background check did not finish cleanly.');
			var errLower = error.toLowerCase();
			if (errLower.indexOf('secret') !== -1 || errLower.indexOf('webhook') !== -1
				|| errLower.indexOf('email') !== -1 || errLower.indexOf('mail') !== -1) {
				actions = [{ label: t('logcheck', 'Set up alerts'), href: alertsHref }];
			} else if (errLower.indexOf('permission') !== -1 || errLower.indexOf('cannot read the log') !== -1) {
				actions = [{ label: t('logcheck', 'View logs'), href: logsHref }];
			} else {
				actions = [{ label: t('logcheck', 'Try again'), href: null, action: 'check-again' }];
			}
		} else {
			cardState = 'ok';
			label = t('logcheck', 'Watching');
			detail = t('logcheck', 'Last check: %s', [formatLastCheck(lastCheck)]);
		}

		card.setAttribute('data-state', cardState);
		var badge = card.querySelector('.lck-badge');
		if (badge) {
			badge.setAttribute('data-state', cardState);
			var labelEl = badge.querySelector('.lck-badge__label');
			if (labelEl) {
				var sr = labelEl.querySelector('.lck-sr-only');
				var srText = sr ? sr.textContent : '';
				labelEl.textContent = '';
				if (sr && srText) {
					var srSpan = document.createElement('span');
					srSpan.className = 'lck-sr-only';
					srSpan.textContent = srText;
					labelEl.appendChild(srSpan);
				}
				labelEl.appendChild(document.createTextNode(label));
			}
		}
		var detailEl = card.querySelector('.lck-health-card__detail');
		if (detail) {
			if (!detailEl) {
				detailEl = document.createElement('p');
				detailEl.className = 'lck-health-card__detail lck-muted';
				var header = card.querySelector('.lck-health-card__header');
				if (header && header.parentNode) {
					header.parentNode.insertBefore(detailEl, header.nextSibling);
				}
			}
			detailEl.textContent = detail;
		} else if (detailEl) {
			detailEl.remove();
		}

		var actionsEl = card.querySelector('.lck-health-card__actions');
		if (actions.length === 0) {
			if (actionsEl) {
				actionsEl.remove();
			}
		} else {
			if (!actionsEl) {
				actionsEl = document.createElement('p');
				actionsEl.className = 'lck-health-card__actions';
				var inner = card.querySelector('.lck-health-card__inner');
				if (inner) {
					inner.appendChild(actionsEl);
				}
			}
			actionsEl.textContent = '';
			actions.forEach(function (a) {
				if (a.href) {
					var link = document.createElement('a');
					link.className = 'lck-btn lck-btn--secondary';
					link.href = a.href;
					link.textContent = a.label;
					actionsEl.appendChild(link);
				} else {
					var btn = document.createElement('button');
					btn.type = 'button';
					btn.className = 'lck-btn lck-btn--secondary lck-health-card__action';
					btn.setAttribute('data-lck-action', a.action || 'check-again');
					btn.textContent = a.label;
					btn.addEventListener('click', function () {
						var again = document.getElementById('lck-check-again');
						if (again) {
							again.click();
						}
					});
					actionsEl.appendChild(btn);
				}
			});
		}
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
				desc.textContent = t('logcheck', 'When on, HealthCheck checks for new errors in the background.');
			}
		}
		// One alert CTA owner: Set up (checklist) XOR Manage (actions). Sync after toggle without reload.
		var errActions = document.getElementById('lck-watching-actions-error');
		var readyActions = document.getElementById('lck-watching-actions-ready');
		var setupActions = document.getElementById('lck-watching-actions-setup');
		var showErr = !!(status.watch_enabled && status.error);
		var showReady = !!(status.watch_enabled && status.alerts_ready && !status.error);
		var showSetup = !!(status.watch_enabled && !status.alerts_ready && !status.error);
		if (errActions) {
			if (showErr) { errActions.removeAttribute('hidden'); } else { errActions.setAttribute('hidden', 'hidden'); }
		}
		if (readyActions) {
			if (showReady) { readyActions.removeAttribute('hidden'); } else { readyActions.setAttribute('hidden', 'hidden'); }
		}
		if (setupActions) {
			if (showSetup) { setupActions.removeAttribute('hidden'); } else { setupActions.setAttribute('hidden', 'hidden'); }
		}
		applyLogHealthCard(status);
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
		var watchingTryAgain = document.getElementById('lck-watching-try-again');
		if (watchingTryAgain) {
			watchingTryAgain.addEventListener('click', function () {
				runCheckAgain(watchingTryAgain);
			});
		}
		document.querySelectorAll('.lck-health-card__action[data-lck-action="check-again"]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				runCheckAgain(btn);
			});
		});
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
