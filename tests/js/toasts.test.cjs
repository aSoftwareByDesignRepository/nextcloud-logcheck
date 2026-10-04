#!/usr/bin/env node
/**
 * Behavioural tests for LogCheck toasts.js (kind+text dedup with timer reset,
 * a11y roles, translated dismiss, error feedback link).
 *
 * Run: node tests/js/toasts.test.cjs
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..', '..');
const SRC = fs.readFileSync(path.join(ROOT, 'js', 'common', 'toasts.js'), 'utf8');

let failures = 0;
function assert(cond, msg) {
	if (!cond) {
		failures += 1;
		process.stderr.write('FAIL: ' + msg + '\n');
	}
}

function makeEl(tag) {
	const el = {
		tagName: (tag || 'div').toUpperCase(),
		className: '',
		children: [],
		attrs: {},
		parentNode: null,
		textContent: '',
		id: '',
		setAttribute(k, v) { this.attrs[k] = String(v); },
		getAttribute(k) { return this.attrs[k] !== undefined ? this.attrs[k] : null; },
		appendChild(c) { c.parentNode = this; this.children.push(c); return c; },
		removeChild(c) {
			const i = this.children.indexOf(c);
			if (i !== -1) { this.children.splice(i, 1); c.parentNode = null; }
		},
		remove() { if (this.parentNode) { this.parentNode.removeChild(this); } },
		addEventListener(type, fn) { (this._listeners ||= {})[type] = fn; },
		querySelectorAll(sel) {
			const wantClass = sel.match(/\.([a-z0-9_-]+)/i);
			const wantAttr = sel.match(/\[([a-z0-9_-]+)\]/i);
			const out = [];
			const walk = (n) => {
				for (const c of (n.children || [])) {
					const clsOk = !wantClass || (c.className || '').split(/\s+/).includes(wantClass[1]);
					const attrOk = !wantAttr || c.getAttribute(wantAttr[1]) !== null;
					if (clsOk && attrOk) { out.push(c); }
					walk(c);
				}
			};
			walk(this);
			return out;
		},
	};
	return el;
}

function boot() {
	const nodes = new Map();
	const body = makeEl('body');
	const timers = new Map();
	let seq = 0;
	const scheduled = [];
	const doc = {
		readyState: 'complete',
		documentElement: { lang: 'en' },
		body,
		getElementById(id) {
			if (nodes.has(id)) { return nodes.get(id); }
			let found = null;
			const walk = (n) => {
				for (const c of (n.children || [])) {
					if (c.id === id) { found = c; }
					walk(c);
				}
			};
			walk(body);
			return found;
		},
		createElement(tag) { return makeEl(tag); },
	};
	const sandbox = {
		document: doc,
		console,
		t(app, msg) { return app + '::' + msg; },
	};
	sandbox.window = {
		setTimeout(fn, ms) { const id = ++seq; timers.set(id, { fn, ms }); scheduled.push({ id, ms }); return id; },
		clearTimeout(id) { timers.delete(id); },
	};
	sandbox.window.document = doc;
	sandbox.setTimeout = sandbox.window.setTimeout;
	sandbox.clearTimeout = sandbox.window.clearTimeout;
	vm.createContext(sandbox);
	vm.runInContext(SRC, sandbox, { filename: 'toasts.js' });
	return { sandbox, doc, nodes, timers, scheduled };
}

// 1. identical kind+text must not stack; the existing toast's timer is reset.
{
	const { sandbox, doc, timers, scheduled } = boot();
	sandbox.window.LogCheckToasts.showError('Save failed.');
	sandbox.window.LogCheckToasts.showError('Save failed.');
	sandbox.window.LogCheckToasts.showError('Save failed.');
	const toasts = doc.body.children[0].querySelectorAll('.lck-toast');
	assert(toasts.length === 1, 'three identical error toasts stacked (got ' + toasts.length + ')');
	const toastTimers = scheduled.filter((s) => timers.has(s.id));
	assert(toastTimers.length === 1, 'duplicate toast did not reset to a single live timer (live=' + toastTimers.length + ')');
}

// 2. different kind or different text may coexist.
{
	const { sandbox, doc } = boot();
	sandbox.window.LogCheckToasts.showError('Save failed.');
	sandbox.window.LogCheckToasts.showSuccess('Save failed.');
	sandbox.window.LogCheckToasts.showError('Different failure.');
	const toasts = doc.body.children[0].querySelectorAll('.lck-toast');
	assert(toasts.length === 3, 'distinct kind/text toasts must not dedup (got ' + toasts.length + ')');
}

// 3. a11y contract: role + translated dismiss + live regions updated.
{
	const { sandbox, doc, nodes } = boot();
	nodes.set('lck-live-region', makeEl('div'));
	nodes.set('lck-alert-region', makeEl('div'));
	sandbox.window.LogCheckToasts.showError('Boom');
	const toast = doc.body.children[0].querySelectorAll('.lck-toast')[0];
	assert(toast.getAttribute('role') === 'alert', 'error toast missing role=alert');
	const close = toast.children.find((c) => (c.className || '').includes('lck-toast__close'));
	assert(close && close.getAttribute('aria-label') === 'logcheck::Dismiss', 'dismiss button aria-label not translated');
	assert(nodes.get('lck-alert-region').textContent === 'Boom', 'alert live region not updated');
	sandbox.window.LogCheckToasts.showSuccess('Yay');
	const ok = doc.body.children[0].querySelectorAll('.lck-toast')[1];
	assert(ok.getAttribute('role') === 'status', 'success toast missing role=status');
	assert(nodes.get('lck-live-region').textContent === 'Yay', 'status live region not updated');
}

// 4. error toasts carry the "Report this problem" feedback mailto when the
// shared feedback helper is present; absence must never break the toast.
{
	const { sandbox, doc, timers } = boot();
	sandbox.window.SbdAppFeedback = { buildMailto() { return 'mailto:dev@example?subject=x'; } };
	sandbox.window.LogCheckToasts.showError('Boom');
	const errToast = doc.body.children[0].querySelectorAll('.lck-toast')[0];
	const link = errToast.children.find((c) => (c.className || '').includes('lck-toast__feedback'));
	assert(link && link.href === 'mailto:dev@example?subject=x', 'error toast missing feedback mailto');
	assert(link.textContent === 'logcheck::Report this problem', 'feedback link text not translated');
	sandbox.window.LogCheckToasts.showSuccess('Fine');
	const okToast = doc.body.children[0].querySelectorAll('.lck-toast')[1];
	assert(!okToast.children.find((c) => (c.className || '').includes('lck-toast__feedback')), 'success toast must not carry feedback link');

	const solo = boot(); // no SbdAppFeedback at all
	solo.sandbox.window.LogCheckToasts.showError('Still works');
	assert(solo.doc.body.children[0].querySelectorAll('.lck-toast').length === 1, 'toast broke without SbdAppFeedback');
	void timers;
}

if (failures === 0) {
	process.stdout.write('toasts.test.cjs: all assertions passed\n');
}
process.exit(failures === 0 ? 0 : 1);
