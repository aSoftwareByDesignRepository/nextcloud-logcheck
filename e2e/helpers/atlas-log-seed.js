// @ts-check
/**
 * Seed an older log copy for Atlas delete-copy e2e (web-only, no AVD).
 * Writes a sibling `*.lck-rotated-YYYYMMDD-HHMMSS` next to the live log via docker.
 */
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const ncRoot = path.resolve(__dirname, '../../../../');
const composeFile = path.join(ncRoot, 'docker-compose.yml');

/**
 * @returns {string} archive basename
 */
function patchOlderLogCopy() {
	const marker = 'OK_SEEDED_COPY';
	const phpBody = `require '/var/www/html/lib/base.php';
$svc = \\OC::$server->get(\\OCA\\LogCheck\\Service\\LogBackendService::class);
$path = $svc->resolveLogPath();
$dir = dirname($path);
$base = basename($path);
$archive = $base . '.lck-rotated-' . gmdate('Ymd-His');
$dest = $dir . '/' . $archive;
if (!is_dir($dir) || !is_writable($dir)) {
  fwrite(STDERR, "log dir not writable\\n");
  exit(1);
}
file_put_contents($dest, json_encode(['atlas_seed' => true, 'ts' => time()]) . "\\n");
@chmod($dest, 0640);
echo "${marker}:" . $archive . "\\n";
`;

	const hostScript = path.join(ncRoot, 'apps/logcheck/.atlas-log-seed.tmp.php');
	const containerScript = '/var/www/html/custom_apps/logcheck/.atlas-log-seed.tmp.php';
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
			throw new Error('atlas log seed failed: ' + out);
		}
		const m = out.match(new RegExp(marker + ':([^\\s]+)'));
		return m ? m[1] : '';
	} finally {
		try {
			fs.unlinkSync(hostScript);
		} catch (_) {
			/* ignore */
		}
	}
}

module.exports = { patchOlderLogCopy };
