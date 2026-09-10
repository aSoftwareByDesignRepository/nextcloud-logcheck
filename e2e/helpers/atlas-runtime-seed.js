// @ts-check
/**
 * Seed / clear WatchRunner runtime error for Atlas craft (async Log alerts Try again).
 * Uses docker compose exec into the Nextcloud container — web-only, no AVD.
 */
const { execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const ncRoot = path.resolve(__dirname, '../../../../');
const composeFile = path.join(ncRoot, 'docker-compose.yml');

/**
 * @param {'error' | 'ok'} mode
 */
function patchWatchRuntime(mode) {
	const marker = mode === 'error' ? 'OK_SEEDED_ERROR' : 'OK_CLEARED';
	const phpBody =
		mode === 'error'
			? `require '/var/www/html/lib/base.php';
$svc = \\OC::$server->get(\\OCA\\LogCheck\\Service\\SettingsService::class);
$svc->patchRuntime([
  'last_run_ok' => false,
  'last_error' => 'Something went wrong. Try again.',
  'last_run_at' => time(),
]);
echo "${marker}\\n";
`
			: `require '/var/www/html/lib/base.php';
$svc = \\OC::$server->get(\\OCA\\LogCheck\\Service\\SettingsService::class);
$svc->patchRuntime([
  'last_run_ok' => true,
  'last_error' => null,
  'last_run_at' => time(),
]);
echo "${marker}\\n";
`;

	// Write into the bind-mounted apps tree so the container can read it as www-data.
	const hostScript = path.join(ncRoot, 'apps/logcheck/.atlas-runtime-seed.tmp.php');
	const containerScript = '/var/www/html/custom_apps/logcheck/.atlas-runtime-seed.tmp.php';
	fs.writeFileSync(hostScript, '<?php\n' + phpBody, { mode: 0o644 });
	try {
		const out = execFileSync(
			'docker',
			[
				'compose',
				'-f',
				composeFile,
				'exec',
				'-T',
				'-u',
				'www-data',
				'nextcloud',
				'php',
				containerScript,
			],
			{ cwd: ncRoot, encoding: 'utf8', timeout: 60_000 },
		);
		if (!out.includes(marker)) {
			throw new Error('atlas runtime seed failed: ' + out);
		}
		return out.trim();
	} finally {
		try {
			fs.unlinkSync(hostScript);
		} catch (_) {
			/* ignore */
		}
	}
}

module.exports = { patchWatchRuntime };
