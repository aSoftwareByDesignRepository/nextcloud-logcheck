<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$fail = 0;

function fail(string $msg): void
{
	global $fail;
	echo "FAIL: {$msg}\n";
	$fail++;
}

function ok(string $msg): void
{
	echo "OK: {$msg}\n";
}

$lib = '';
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/lib'));
foreach ($it as $file) {
	if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
		$lib .= file_get_contents($file->getPathname()) . "\n";
	}
}

if (preg_match('/\\\\Process::|new\s+\\\\Symfony\\\\Component\\\\Process\\\\Process|shell_exec\s*\(|proc_open\s*\(/', $lib)) {
	fail('NN-09: Process:: / shell execution must not appear');
} else {
	ok('NN-09: no Process:: / shell execution');
}

if (preg_match('/custom_log_path|logfile_override|userLogPath/', $lib)) {
	fail('NN-17: custom log path field must not exist');
} else {
	ok('NN-17: no custom log path');
}

$logFileSvc = (string)file_get_contents($root . '/lib/Service/LogFileService.php');
if (!str_contains($logFileSvc, 'resolveLogPath()') || preg_match('/getParam\s*\(\s*[\'"]path/', $logFileSvc)) {
	fail('NN-17: LogFileService must use resolveLogPath only (no request path param)');
} else {
	ok('NN-17: LogFileService systemconfig path only');
}
if (!str_contains($logFileSvc, 'isAllowlistedBasename') || !str_contains($logFileSvc, 'resolveAllowlistedFile')) {
	fail('NN-17: LogFileService must allowlist sibling basenames (no arbitrary dir read)');
} else {
	ok('NN-17: LogFileService sibling allowlist');
}
if (!str_contains($logFileSvc, 'assertSafeFileId')) {
	fail('NN-17: basename-safe file id checks missing');
} else {
	ok('NN-17: basename-safe file id checks present');
}

$routesPhp = (string)file_get_contents($root . '/appinfo/routes.php');
if (!preg_match("/api#downloadLog'[^\\n]*'verb'\\s*=>\\s*'POST'/", $routesPhp)
	&& !preg_match("/\\['name'\\s*=>\\s*'api#downloadLog'[\\s\\S]*?'verb'\\s*=>\\s*'POST'\\]/", $routesPhp)) {
	fail('NN-LOG-DL: /api/logs/download must be POST (CSRF-bound; no cross-site GET download)');
} else {
	ok('NN-LOG-DL: download is POST');
}
if (preg_match("/api#downloadLog'[^\\n]*'verb'\\s*=>\\s*'GET'/", $routesPhp)) {
	fail('NN-LOG-DL: download must not remain GET');
}

$apiCtrl = (string)file_get_contents($root . '/lib/Controller/ApiController.php');
if (preg_match('/getParam\s*\(\s*[\'"]path[\'"]/', $apiCtrl)) {
	fail('NN-17: ApiController must not accept path param');
} else {
	ok('NN-17: ApiController has no path param');
}

$access = (string)file_get_contents($root . '/lib/Service/AccessService.php');
if (!str_contains($access, "=== 'open'")) {
	fail('NN-13: AccessService must reject open');
} else {
	ok('NN-13: AccessService rejects open');
}

$backend = (string)file_get_contents($root . '/lib/Service/LogBackendService.php');
if (!str_contains($backend, "getSystemValue('logfile'") && !str_contains($backend, 'getSystemValue("logfile"')) {
	fail('NN-17: LogBackendService must read logfile from system config');
} else {
	ok('NN-17: systemconfig logfile');
}
if (!str_contains($backend, 'absolutizeConfiguredPath') || !str_contains($backend, 'isAbsolutePath')) {
	fail('NN-17: LogBackendService must absolutize relative logfile under datadirectory');
} else {
	ok('NN-17: relative logfile anchored under datadirectory');
}

// Webhook Channel classes must not call file_get_contents on URLs (SafeHttpClient only).
$channelDir = $root . '/lib/Service/Channel';
if (is_dir($channelDir)) {
	$channelHits = [];
	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($channelDir)) as $file) {
		if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
			continue;
		}
		$src = (string)file_get_contents($file->getPathname());
		if (preg_match('/file_get_contents\s*\(\s*\$/', $src)) {
			$channelHits[] = $file->getFilename();
		}
	}
	if ($channelHits !== []) {
		fail('SSRF: file_get_contents($url…) forbidden in Channel/: ' . implode(', ', $channelHits));
	} else {
		ok('SSRF: no file_get_contents($…) in Channel/');
	}
}

$safeHttp = (string)file_get_contents($root . '/lib/Service/SafeHttpClient.php');
if (preg_match('/file_get_contents\s*\(\s*\$/', $safeHttp)) {
	fail('SafeHttpClient must not use file_get_contents on URLs (curl+RESOLVE only)');
} else {
	ok('SafeHttpClient curl-only (no stream URL fetch)');
}
if (!str_contains($safeHttp, 'CURLOPT_RESOLVE') || !str_contains($safeHttp, 'curl_init')) {
	fail('SafeHttpClient must require curl + CURLOPT_RESOLVE');
} else {
	ok('SafeHttpClient pins via CURLOPT_RESOLVE');
}

$banned = ['cursor', 'inode', 'lease', 'coalesce', 'fingerprint', 'accumulator', 'pending digest'];
foreach (glob($root . '/l10n/*.json') ?: [] as $path) {
	$f = basename($path);
	$data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
	$blob = json_encode($data['translations'] ?? [], JSON_UNESCAPED_UNICODE);
	foreach ($banned as $word) {
		if (preg_match('/\b' . preg_quote($word, '/') . '\b/i', (string)$blob)) {
			fail("UX-L14: banned jargon in $f: $word");
		}
	}
}
ok('UX-L14: no banned ops jargon in l10n (all locales)');

$requiredLocales = ['en', 'de', 'fr', 'es', 'da', 'nl', 'it', 'pl', 'sv', 'nb', 'pt_BR'];
foreach ($requiredLocales as $lang) {
	if (!is_file($root . '/l10n/' . $lang . '.json') || !is_file($root . '/l10n/' . $lang . '.js')) {
		fail('L10N: missing portfolio locale ' . $lang);
	}
}
ok('L10N: portfolio locale set present');

$parity = $root . '/scripts/check-l10n-parity.php';
$codeKeys = $root . '/scripts/check-l10n-code-keys.php';
if (is_file($parity)) {
	passthru('php ' . escapeshellarg($parity), $parityCode);
	if ($parityCode !== 0) {
		fail('L10N: parity script failed');
	} else {
		ok('L10N: parity script');
	}
}
if (is_file($codeKeys)) {
	passthru('php ' . escapeshellarg($codeKeys), $ckCode);
	if ($ckCode !== 0) {
		fail('L10N: code-keys script failed');
	} else {
		ok('L10N: code-keys script');
	}
}

// SF-03: app ships a lockfile so dependency audits are reproducible.
if (!is_file($root . '/composer.lock')) {
	fail('SF-03: composer.lock missing (no reproducible dependency set)');
} else {
	ok('SF-03: composer.lock present');
}
if (!is_file($root . '/composer.json')) {
	fail('SF-03: composer.json missing');
} else {
	$composer = json_decode((string)file_get_contents($root . '/composer.json'), true);
	if (!is_array($composer)) {
		fail('SF-03: composer.json invalid');
	} else {
		ok('SF-03: composer.json readable');
	}
}

$lease = (string)file_get_contents($root . '/lib/Service/LeaseService.php');
if (!str_contains($lease, "getSQL() . ' FOR UPDATE'") || !str_contains($lease, 'renewInTransaction')) {
	fail('SF-Z02: LeaseService must renewInTransaction with FOR UPDATE');
} else {
	ok('SF-Z02: lease FOR UPDATE renewInTransaction');
}

// Support us: no PSP donation CTAs (GitHub Sponsors only).
$supportUs = (string)file_get_contents($root . '/lib/Support/SupportUsLinks.php');
$supportTpl = (string)file_get_contents($root . '/templates/parts/settings/support.php');
$supportBlob = $supportUs . "\n" . $supportTpl;
foreach (['paypalUrl', 'stripeUrl', 'PAYPAL_URL', 'STRIPE_URL', 'Donate via PayPal', 'Donate via Stripe', 'paypal.com', 'stripe.com'] as $needle) {
	if (stripos($supportBlob, $needle) !== false) {
		fail('Support us must not expose PSP donation CTA: ' . $needle);
	}
}
if (!str_contains($supportTpl, "str_starts_with(\$sponsorsUrl, 'https://github.com/sponsors/')")) {
	fail('Support us template must allow only GitHub Sponsors HTTPS donation hrefs');
} else {
	ok('Support us template pins Sponsors host prefix');
}
foreach (glob($root . '/l10n/*.json') ?: [] as $path) {
	$lf = basename($path);
	$data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
	$keys = array_keys($data['translations'] ?? []);
	foreach (['Donate via PayPal', 'Donate via Stripe'] as $bannedKey) {
		if (in_array($bannedKey, $keys, true)) {
			fail("l10n {$lf}: banned key {$bannedKey}");
		}
	}
}
ok('Support us: GitHub Sponsors only (no PSP donation CTAs)');

// HCK NN-H08 / NN-H09 / NN-H20 — Health probes
$diskProbe = (string)file_get_contents($root . '/lib/Service/Health/DiskHealthProbe.php');
if (!str_contains($diskProbe, "getSystemValue('datadirectory'") || preg_match('/getParam\s*\(|\$_GET|\$_POST/', $diskProbe)) {
	fail('NN-H08: DiskHealthProbe must use datadirectory only (no request path)');
} else {
	ok('NN-H08: DiskHealthProbe datadirectory jail');
}

$httpsProbe = (string)file_get_contents($root . '/lib/Service/Health/HttpsHealthProbe.php');
if (!str_contains($httpsProbe, 'getInstanceStatus') || preg_match('/getParam\s*\(\s*[\'"]url/', $httpsProbe)) {
	fail('NN-H09: HttpsHealthProbe must use getInstanceStatus only');
} else {
	ok('NN-H09: HttpsHealthProbe instance URL only');
}
if (!str_contains($safeHttp, 'getInstanceStatus') || !str_contains($safeHttp, 'hash_equals') || !str_contains($safeHttp, 'isAllowedStatusPath')) {
	fail('NN-H09: SafeHttpClient::getInstanceStatus must host-allowlist + status path allowlist');
} else {
	ok('NN-H09: SafeHttpClient getInstanceStatus host allowlist');
}
if (!str_contains($safeHttp, 'hostForUrl') || !str_contains($httpsProbe, 'isHealthyStatusResponse')) {
	fail('NN-H09: IPv6 hostForUrl + HTTPS installed JSON gate required');
} else {
	ok('NN-H09: IPv6 + HTTPS body gate');
}

$updatesProbe = (string)file_get_contents($root . '/lib/Service/Health/UpdatesHealthProbe.php');
if (preg_match('/\\\\OC\\\\Updater\\\\VersionCheck|IClientService|updater\.server\.url/', $updatesProbe)) {
	fail('NN-H20: UpdatesHealthProbe must be read-only cache (no updater network)');
} else {
	ok('NN-H20: UpdatesHealthProbe read-only cache');
}

$logMapper = (string)file_get_contents($root . '/lib/Service/Health/LogHealthProbe.php');
if (!str_contains($logMapper, 'HealthCardState::CRITICAL') || !str_contains($logMapper, 'topology_ok')) {
	fail('NN-H01: LogHealthProbe must never-ok on topology/unsupported');
} else {
	ok('NN-H01: LogHealthProbe never-ok mapper present');
}

$watchRunner = (string)file_get_contents($root . '/lib/Service/WatchRunner.php');
if (!str_contains($watchRunner, 'isMismatch($runtime)') || !str_contains($watchRunner, 'Multi-server setups need one shared log file')) {
	fail('NG-Z-T1: WatchRunner must no-op on topology mismatch (Can\'t watch ⇒ no processing)');
} else {
	ok('NG-Z-T1: WatchRunner gates on topology mismatch');
}

$pendingStore = (string)file_get_contents($root . '/lib/Service/PendingStore.php');
if (!str_contains($pendingStore, "createNamedParameter('sent')") || !str_contains($pendingStore, 'purgeExpired')) {
	fail('NG-Z-P1: PendingStore::purgeExpired must delete aged sent rows');
} else {
	ok('NG-Z-P1: sent pending rows purged');
}

if (!str_contains($pendingStore, 'CLAIM_BATCH_LIMIT')
	|| !preg_match('/setMaxResults\s*\(\s*self::CLAIM_BATCH_LIMIT\s*\)/', $pendingStore)
	|| !preg_match('/orderBy\s*\(\s*[\'"]created_at[\'"]/', $pendingStore)
) {
	fail('NG-M-MEM01: listByStatus must ORDER BY created_at and setMaxResults(CLAIM_BATCH_LIMIT)');
} else {
	ok('NG-M-MEM01: pending claim scan is batch-limited');
}

$settingsSvc = (string)file_get_contents($root . '/lib/Service/SettingsService.php');
if (!str_contains($settingsSvc, "unset(\$s['runtime']['watcher_node'])")) {
	fail('NN-RUNTIME: toUiDto must redact runtime.watcher_node');
} else {
	ok('NN-RUNTIME: watcher_node redacted from UI DTO');
}

$ssrf = (string)file_get_contents($root . '/lib/Service/SsrfGuard.php');
if (!str_contains($ssrf, '0xfec0')) {
	fail('NN-SSRF: SsrfGuard must block deprecated IPv6 site-local fec0::/10');
} else {
	ok('NN-SSRF: fec0::/10 site-local blocked');
}

$dispatcher = (string)file_get_contents($root . '/lib/Service/ChannelDispatcher.php');
if (!str_contains($dispatcher, 'ChannelStateStore::safeError($e->getMessage())')) {
	fail('NN-LOG: ChannelDispatcher must log safeError, not raw exception messages');
} else {
	ok('NN-LOG: ChannelDispatcher uses safeError in app logs');
}

$apiCtrlSrc = (string)file_get_contents($root . '/lib/Controller/ApiController.php');
if (!str_contains($apiCtrlSrc, "channel_tested") || !str_contains($apiCtrlSrc, "check_run")) {
	fail('NN-AUDIT: channel test and check_run must emit audit events');
} else {
	ok('NN-AUDIT: channel_tested + check_run audited');
}

// Momos H-RE1: reenableChannel must not clear auto-disable before testChannel succeeds.
if (!preg_match('/function\s+reenableChannel\s*\([^)]*\)\s*:\s*JSONResponse\s*\{(.*?)\n\t\}/s', $apiCtrlSrc, $reMatch)) {
	fail('NG-M-RE1: could not locate ApiController::reenableChannel');
} elseif (str_contains($reMatch[1], '->reenable(')) {
	fail('NG-M-RE1: reenableChannel must not call channelStateStore->reenable before a successful test');
} elseif (!str_contains($reMatch[1], 'testChannel(')) {
	fail('NG-M-RE1: reenableChannel must delegate to testChannel (recordSuccess clears disable)');
} else {
	ok('NG-M-RE1: reenable does not clear disable before test');
}

$testChannelCmd = (string)file_get_contents($root . '/lib/Command/TestChannelCommand.php');
if (!str_contains($testChannelCmd, 'ChannelStateStore::safeError')) {
	fail('NG-M-OCC1: occ logcheck:test-channel must print safeError, not raw exception text');
} else {
	ok('NG-M-OCC1: test-channel uses safeError');
}

$auditSvc = (string)file_get_contents($root . '/lib/Service/AuditService.php');
if (preg_match('/new\s+CriticalActionPerformedEvent\s*\(\s*\$message\s*,\s*false\s*\)/', $auditSvc) === 1
	|| !str_contains($auditSvc, 'new CriticalActionPerformedEvent($message, [])')
) {
	fail('NG-M-AUD1: AuditService must pass array $parameters (not bool) to CriticalActionPerformedEvent');
} else {
	ok('NG-M-AUD1: CriticalActionPerformedEvent parameters are an array');
}

$deliveryStore = (string)file_get_contents($root . '/lib/Service/DeliveryStore.php');
$channelDispatcher = (string)file_get_contents($root . '/lib/Service/ChannelDispatcher.php');
if (!str_contains($deliveryStore, 'function hasSent')
	|| !str_contains($channelDispatcher, 'hasSent(')
	|| !str_contains($channelDispatcher, 'already delivered')
) {
	fail('NG-M-DEDUP: ChannelDispatcher must skip outbound when DeliveryStore::hasSent');
} else {
	ok('NG-M-DEDUP: delivery hasSent gate before send');
}

$logFileSvc = (string)file_get_contents($root . '/lib/Service/LogFileService.php');
if (!preg_match('/function\s+resolveDownload\s*\([^)]*\)[^{]*\{[^}]*assertNcAdmin/s', $logFileSvc)) {
	fail('NG-M-DL1: resolveDownload must assertNcAdmin (full-file download NC-only)');
} else {
	ok('NG-M-DL1: download requires NC admin');
}

exit($fail > 0 ? 1 : 0);
