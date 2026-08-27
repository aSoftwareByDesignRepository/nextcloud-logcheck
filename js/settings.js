(function () {
	'use strict';

	function formToSettings(form) {
		var fd = new FormData(form);
		// Last-wins for switches (hidden 0 then checkbox 1)
		var map = {};
		fd.forEach(function (value, key) {
			map[key] = value;
		});

		var settings = {
			expected_version: parseInt(map.expected_version || '1', 10)
		};
		var section = form.getAttribute('data-section');

		if (section === 'alerts') {
			settings.settings = {
				channels: {
					email: {
						enabled: map['channels[email][enabled]'] === '1',
						recipients: String(map.email_recipients || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean)
					},
					slack: {
						enabled: map['channels[slack][enabled]'] === '1',
						webhook_url: map['channels[slack][webhook_url]'] || '',
						clear_url: map['channels[slack][clear_url]'] === '1'
					},
					webhook: {
						enabled: map['channels[webhook][enabled]'] === '1',
						url: map['channels[webhook][url]'] || '',
						clear_url: map['channels[webhook][clear_url]'] === '1'
					},
					notification: {
						enabled: map['channels[notification][enabled]'] === '1'
					}
				}
			};
			// NC-admin-only flags — omit for App Admins (server 403s if present).
			if (form.getAttribute('data-nc-admin') === '1') {
				settings.settings.include_message_excerpts = map.include_message_excerpts === '1';
				settings.settings.excerpt_confirm = map.excerpt_confirm || '';
				settings.settings.allow_private_webhooks = map.allow_private_webhooks === '1';
			}
		} else if (section === 'rules') {
			var mutes = [];
			String(map.mute_apps || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean).forEach(function (app) {
				mutes.push({ type: 'app', value: app });
			});
			String(map.mute_regexes || '').split('\n').map(function (s) { return s.trim(); }).filter(Boolean).forEach(function (rx) {
				mutes.push({ type: 'regex', value: rx, flags: 'i' });
			});
			settings.settings = {
				min_level: parseInt(map.min_level || '3', 10),
				coalesce_seconds: parseInt(map.pace_seconds || map.coalesce_seconds || '900', 10),
				app_mode: map.app_mode || 'all',
				app_list: String(map.app_list || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean),
				mutes: mutes
			};
		} else if (section === 'people') {
			var admins = [];
			form.querySelectorAll('input[name="access[app_admins][]"]').forEach(function (inp) {
				admins.push(inp.value);
			});
			settings.settings = {
				access: {
					mode: 'restricted',
					app_admins: admins
				}
			};
		}
		return settings;
	}

	document.addEventListener('DOMContentLoaded', function () {
		var form = document.getElementById('lck-settings-form');
		if (!form || !window.LogCheckApp) {
			return;
		}
		var App = window.LogCheckApp;
		App.chipGroup(document.getElementById('lck-level-chips'), document.getElementById('lck-min-level'));
		App.chipGroup(document.getElementById('lck-pace-chips'), document.getElementById('lck-pace-seconds'));

		form.addEventListener('submit', async function (ev) {
			ev.preventDefault();
			var submit = form.querySelector('button[type="submit"]');
			if (submit) {
				submit.disabled = true;
				submit.setAttribute('aria-busy', 'true');
			}
			var body = formToSettings(form);
			var res = await App.putJson(App.urls().apiSave, body);
			if (App.handleConflict(res)) {
				return;
			}
			if (res.status >= 200 && res.status < 300) {
				LogCheckToasts.showSuccess(t('logcheck', 'Saved.'));
				if (res.data && res.data.version) {
					App.setSettingsVersion(res.data.version);
				}
				if (submit) {
					submit.disabled = false;
					submit.removeAttribute('aria-busy');
				}
			} else {
				LogCheckToasts.showError((res.data && res.data.message) || t('logcheck', 'Save failed.'));
				if (submit) {
					submit.disabled = false;
					submit.removeAttribute('aria-busy');
				}
			}
		});

		document.querySelectorAll('.lck-test-turn-on').forEach(function (btn) {
			btn.addEventListener('click', async function () {
				await testAndTurnOn(btn);
			});
		});

		async function runChannelTest(channel, btn) {
			var base = App.urls().home.replace(/\/home$/, '');
			var url = base + '/api/channels/' + encodeURIComponent(channel) + '/test';
			var body = {};
			if (channel === 'slack') {
				var slackUrl = document.getElementById('lck-slack-url');
				if (slackUrl && slackUrl.value.trim()) {
					body.webhook_url = slackUrl.value.trim();
				}
			}
			if (channel === 'webhook') {
				var whUrl = document.getElementById('lck-webhook-url');
				if (whUrl && whUrl.value.trim()) {
					body.url = whUrl.value.trim();
				}
			}
			if (channel === 'email') {
				var emailField = document.getElementById('lck-email-recipients');
				if (emailField) {
					body.recipients = String(emailField.value || '').split(',').map(function (s) {
						return s.trim();
					}).filter(Boolean);
				}
			}
			if (btn) {
				btn.disabled = true;
				btn.setAttribute('aria-busy', 'true');
			}
			var res = await App.postJson(url, body);
			if (btn) {
				btn.disabled = false;
				btn.removeAttribute('aria-busy');
			}
			return res;
		}

		function channelEnableId(channel) {
			return {
				email: 'lck-email-enabled',
				slack: 'lck-slack-enabled',
				webhook: 'lck-webhook-enabled',
				notification: 'lck-notification-enabled'
			}[channel] || '';
		}

		function channelStatusId(channel) {
			return {
				email: 'lck-email-status',
				slack: 'lck-slack-status',
				webhook: 'lck-webhook-status',
				notification: 'lck-notification-status'
			}[channel] || '';
		}

		function showChannelStatus(channel, message, ok) {
			var el = document.getElementById(channelStatusId(channel));
			if (!el) {
				return;
			}
			el.textContent = message;
			el.hidden = false;
			el.classList.toggle('lck-channel-status--ok', !!ok);
			el.classList.toggle('lck-channel-status--err', !ok);
		}

		async function testAndTurnOn(btn) {
			var channel = btn.getAttribute('data-channel');
			if (!channel || !form) {
				return;
			}
			var testRes = await runChannelTest(channel, btn);
			if (testRes.status < 200 || testRes.status >= 300) {
				showChannelStatus(channel, (testRes.data && testRes.data.message) || t('logcheck', 'Test failed.'), false);
				LogCheckToasts.showError((testRes.data && testRes.data.message) || t('logcheck', 'Test failed.'));
				return;
			}
			var enableId = channelEnableId(channel);
			var enableEl = enableId ? document.getElementById(enableId) : null;
			if (enableEl) {
				enableEl.checked = true;
			}
			var saveBody = formToSettings(form);
			var saveRes = await App.putJson(App.urls().apiSave, saveBody);
			if (App.handleConflict(saveRes)) {
				return;
			}
			if (saveRes.status >= 200 && saveRes.status < 300) {
				showChannelStatus(channel, t('logcheck', 'Alerts are on.'), true);
				LogCheckToasts.showSuccess(t('logcheck', 'Test sent.'));
				if (saveRes.data && saveRes.data.version) {
					App.setSettingsVersion(saveRes.data.version);
				}
			} else {
				showChannelStatus(channel, (saveRes.data && saveRes.data.message) || t('logcheck', 'Save failed.'), false);
				LogCheckToasts.showError((saveRes.data && saveRes.data.message) || t('logcheck', 'Save failed.'));
			}
		}

		document.querySelectorAll('.lck-reenable-channel').forEach(function (btn) {
			btn.addEventListener('click', async function () {
				var channel = btn.getAttribute('data-channel');
				var base = App.urls().home.replace(/\/home$/, '');
				var url = base + '/api/channels/' + encodeURIComponent(channel) + '/reenable';
				var res = await App.postJson(url, {});
				if (res.status >= 200 && res.status < 300) {
					LogCheckToasts.showSuccess(t('logcheck', 'Channel re-enabled.'));
					window.location.reload();
				} else {
					LogCheckToasts.showError((res.data && res.data.message) || t('logcheck', 'Test failed.'));
				}
			});
		});

		var search = document.getElementById('lck-people-search');
		var results = document.getElementById('lck-people-results');
		var chips = document.getElementById('lck-people-chips');
		if (search && results && chips) {
			var timer = null;
			var inflight = 0;
			var abortCtrl = null;
			var activeIdx = -1;

			function optionButtons() {
				return Array.prototype.slice.call(results.querySelectorAll('button'));
			}

			function setActive(idx) {
				var buttons = optionButtons();
				buttons.forEach(function (b, i) {
					var on = i === idx;
					b.classList.toggle('is-active', on);
					b.setAttribute('aria-selected', on ? 'true' : 'false');
					if (on) {
						var id = 'lck-people-opt-' + i;
						b.id = id;
						search.setAttribute('aria-activedescendant', id);
					}
				});
				activeIdx = idx;
				if (idx < 0) {
					search.removeAttribute('aria-activedescendant');
				}
			}

			function addPerson(uid, displayName) {
				if (chips.querySelector('[data-uid="' + CSS.escape(uid) + '"]')) {
					return;
				}
				var item = document.createElement('li');
				item.className = 'lck-person-chip';
				item.setAttribute('data-uid', uid);
				var span = document.createElement('span');
				span.textContent = displayName;
				var hidden = document.createElement('input');
				hidden.type = 'hidden';
				hidden.name = 'access[app_admins][]';
				hidden.value = uid;
				var remove = document.createElement('button');
				remove.type = 'button';
				remove.className = 'lck-btn lck-btn--ghost lck-remove-person';
				remove.setAttribute('aria-label', t('logcheck', 'Remove %s', [displayName]));
				remove.textContent = '×';
				item.appendChild(span);
				item.appendChild(hidden);
				item.appendChild(remove);
				chips.appendChild(item);
				results.hidden = true;
				results.innerHTML = '';
				search.setAttribute('aria-expanded', 'false');
				search.removeAttribute('aria-activedescendant');
				search.value = '';
				activeIdx = -1;
				search.focus();
			}

			search.addEventListener('input', function () {
				window.clearTimeout(timer);
				timer = window.setTimeout(async function () {
					var q = search.value.trim();
					activeIdx = -1;
					search.removeAttribute('aria-activedescendant');
					if (q.length < 2) {
						results.hidden = true;
						results.innerHTML = '';
						search.setAttribute('aria-expanded', 'false');
						return;
					}
					results.hidden = false;
					results.innerHTML = '';
					var loading = document.createElement('li');
					loading.className = 'lck-muted';
					loading.textContent = t('logcheck', 'Searching…');
					results.appendChild(loading);
					search.setAttribute('aria-expanded', 'true');
					search.setAttribute('aria-busy', 'true');
					if (abortCtrl) {
						abortCtrl.abort();
					}
					abortCtrl = new AbortController();
					var reqId = ++inflight;
					try {
						var res = await fetch(App.urls().apiDirectory + '?search=' + encodeURIComponent(q), {
							headers: { 'requesttoken': App.token() },
							signal: abortCtrl.signal
						});
						var data = await res.json();
						if (reqId !== inflight) {
							return;
						}
						search.removeAttribute('aria-busy');
						results.innerHTML = '';
						var users = data.users || [];
						if (!users.length) {
							var empty = document.createElement('li');
							empty.className = 'lck-muted';
							empty.textContent = t('logcheck', 'No people found.');
							results.appendChild(empty);
							results.hidden = false;
							search.setAttribute('aria-expanded', 'true');
							return;
						}
						users.forEach(function (u, i) {
							var li = document.createElement('li');
							var b = document.createElement('button');
							b.type = 'button';
							b.className = 'lck-btn lck-btn--ghost';
							b.id = 'lck-people-opt-' + i;
							b.setAttribute('role', 'option');
							b.setAttribute('aria-selected', 'false');
							b.textContent = u.displayName;
							b.addEventListener('click', function () {
								addPerson(u.uid, u.displayName);
							});
							li.appendChild(b);
							results.appendChild(li);
						});
						results.hidden = results.children.length === 0;
						search.setAttribute('aria-expanded', results.hidden ? 'false' : 'true');
					} catch (e) {
						if (e && e.name === 'AbortError') {
							return;
						}
						search.removeAttribute('aria-busy');
						results.innerHTML = '';
						var errLi = document.createElement('li');
						errLi.className = 'lck-muted';
						errLi.textContent = t('logcheck', 'Search failed. Try again.');
						results.appendChild(errLi);
						results.hidden = false;
					}
				}, 250);
			});

			search.addEventListener('keydown', function (ev) {
				var buttons = optionButtons();
				if (results.hidden || buttons.length === 0) {
					if (ev.key === 'Escape') {
						search.value = '';
						results.hidden = true;
						search.setAttribute('aria-expanded', 'false');
					}
					return;
				}
				if (ev.key === 'ArrowDown') {
					ev.preventDefault();
					setActive(Math.min(activeIdx + 1, buttons.length - 1));
				} else if (ev.key === 'ArrowUp') {
					ev.preventDefault();
					setActive(Math.max(activeIdx - 1, 0));
				} else if (ev.key === 'Enter' && activeIdx >= 0) {
					ev.preventDefault();
					buttons[activeIdx].click();
				} else if (ev.key === 'Escape') {
					ev.preventDefault();
					results.hidden = true;
					results.innerHTML = '';
					search.setAttribute('aria-expanded', 'false');
					search.removeAttribute('aria-activedescendant');
					activeIdx = -1;
				}
			});

			chips.addEventListener('click', function (ev) {
				var btn = ev.target.closest('.lck-remove-person');
				if (btn) {
					btn.closest('.lck-person-chip').remove();
				}
			});
		}
	});
})();
