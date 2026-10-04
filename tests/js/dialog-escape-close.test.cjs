#!/usr/bin/env node
/**
 * Contract test: the confirmation <dialog> must resolve on Escape keydown
 * even when the native `cancel` event is suppressed — NC's notifications
 * app preventDefault()s global Escape (Atlas lesson
 * host_app_escape_preventdefault).
 *
 * Run: node tests/js/dialog-escape-close.test.cjs
 */
'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..', '..');
const SRC = fs.readFileSync(path.join(ROOT, 'js', 'logs.js'), 'utf8');

let failures = 0;
function assert(cond, msg) {
	if (!cond) {
		failures += 1;
		process.stderr.write('FAIL: ' + msg + '\n');
	}
}

// 1. Dialog-level keydown Escape path exists alongside the cancel listener.
assert(/dialog\.addEventListener\('keydown'/.test(SRC),
	'no keydown listener bound on the confirm dialog');
assert(/key\s*===?\s*'Escape'/.test(SRC),
	'keydown listener does not check e.key === Escape');

// 2. Escape must run the shared cleanup path (resolve(null), listeners
// removed exactly once) — not a divergent close.
const escBody = SRC.slice(SRC.indexOf('onEscKey'));
assert(/cleanup\(null\)/.test(escBody),
	'Escape path does not call cleanup(null) — promise could leak unresolved');
assert(/dialog\.removeEventListener\('keydown',\s*onEscKey\)/.test(SRC),
	'cleanup() does not remove the keydown listener — leaks on every open');

// 3. Native cancel still handled (belt & suspenders when cancel does fire).
assert(/dialog\.addEventListener\('cancel',\s*onEsc\)/.test(SRC),
	'native cancel listener missing');

if (failures > 0) {
	process.stderr.write(`dialog-escape-close: ${failures} failure(s)\n`);
	process.exit(1);
}
process.stdout.write('dialog-escape-close OK\n');
