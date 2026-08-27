<?php

declare(strict_types=1);

/**
 * Real critical-path mutation runner for HealthCheck.
 *
 * 1) Static ban checks (Process::, Channel file_get_contents, Access open, jargon).
 * 2) Apply known-bad source mutations in place, run targeted PHPUnit, EXPECT FAILURE, restore.
 * Exit non-zero if any mutant survives or restore fails.
 */

$root = dirname(__DIR__, 2);
$phpunit = $root . '/vendor/bin/phpunit';
if (!is_executable($phpunit) && !is_file($phpunit)) {
	fwrite(STDERR, "phpunit not found at {$phpunit}\n");
	exit(1);
}

$fail = 0;

function say(string $msg): void
{
	echo $msg . "\n";
}

function fail(string $msg): void
{
	global $fail;
	say('FAIL: ' . $msg);
	$fail++;
}

function ok(string $msg): void
{
	say('OK: ' . $msg);
}

// ——— Static bans ———
$libBlob = '';
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/lib'));
foreach ($it as $f) {
	if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
		$libBlob .= file_get_contents($f->getPathname());
	}
}
foreach ([
	'Process::' => 'no Process::',
	'shell_exec(' => 'no shell_exec',
	'custom_log_path' => 'no custom_log_path',
] as $needle => $label) {
	if (str_contains($libBlob, $needle)) {
		fail($label);
	} else {
		ok($label);
	}
}

$access = (string)file_get_contents($root . '/lib/Service/AccessService.php');
if (!str_contains($access, "=== 'open'")) {
	fail('AccessService must forbid open mode');
} else {
	ok('Access open forbidden');
}

$channelDir = $root . '/lib/Service/Channel';
$channelHit = false;
if (is_dir($channelDir)) {
	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($channelDir)) as $f) {
		if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
			if (preg_match('/file_get_contents\s*\(\s*\$/', (string)file_get_contents($f->getPathname()))) {
				$channelHit = true;
				fail('file_get_contents($…) in Channel/' . $f->getFilename());
			}
		}
	}
}
if (!$channelHit) {
	ok('no file_get_contents($…) in Channel/');
}

$banned = ['cursor', 'inode', 'lease', 'coalesce', 'fingerprint', 'accumulator', 'pending digest'];
foreach (glob($root . '/l10n/*.json') ?: [] as $path) {
	$lf = basename($path);
	$data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
	$blob = json_encode($data['translations'] ?? [], JSON_UNESCAPED_UNICODE);
	foreach ($banned as $word) {
		if (preg_match('/\b' . preg_quote($word, '/') . '\b/i', (string)$blob)) {
			fail("jargon in {$lf}: {$word}");
		}
	}
}
ok('no banned jargon in l10n (all locales)');

passthru('php ' . escapeshellarg($root . '/scripts/check-l10n-parity.php'), $parityCode);
if ($parityCode !== 0) {
	fail('l10n parity');
} else {
	ok('l10n parity');
}
passthru('php ' . escapeshellarg($root . '/scripts/check-l10n-code-keys.php'), $ckCode);
if ($ckCode !== 0) {
	fail('l10n code-keys');
} else {
	ok('l10n code-keys');
}

$supportUs = (string)file_get_contents($root . '/lib/Support/SupportUsLinks.php');
$supportTpl = (string)file_get_contents($root . '/templates/parts/settings/support.php');
$supportBlob = $supportUs . "\n" . $supportTpl;
foreach (['paypalUrl', 'stripeUrl', 'PAYPAL_URL', 'STRIPE_URL', 'Donate via PayPal', 'Donate via Stripe'] as $needle) {
	if (stripos($supportBlob, $needle) !== false) {
		fail('Support us PSP CTA: ' . $needle);
	}
}
ok('Support us GitHub Sponsors only');

// ——— Live mutations ———
/**
 * @return list<array{name: string, file: string, search: string, replace: string, filter: string}>
 */
function mutants(string $root): array
{
	return [
		[
			'name' => 'FileTailer always advances full bytesRead',
			'file' => $root . '/lib/Service/FileTailer.php',
			'search' => '// Advance only through the last newline (re-read trailing partial next time).
				$newOffset = $offset + $lastNl + 1;',
			'replace' => '// MUTANT: always advance full bytesRead (skips incomplete trailing line).
				$newOffset = $offset + $bytesRead;',
			'filter' => 'FileTailerTest::testIncompleteTrailingLineMidChunkCompletedExactlyOnce',
		],
		[
			'name' => 'LeaseService renew always true without owner check',
			'file' => $root . '/lib/Service/LeaseService.php',
			'search' => 'public function renew(string $owner): bool
	{
		$until = time() + self::TTL_SECONDS;
		$qb = $this->db->getQueryBuilder();
		$qb->update(\'lck_locks\')
			->set(\'lease_until\', $qb->createNamedParameter($until))
			->where($qb->expr()->eq(\'lock_name\', $qb->createNamedParameter(self::LOCK_NAME)))
			->andWhere($qb->expr()->eq(\'owner\', $qb->createNamedParameter($owner)));
		return $qb->executeStatement() === 1;
	}',
			'replace' => 'public function renew(string $owner): bool
	{
		// MUTANT: always succeed without owner check
		return true;
	}',
			'filter' => 'LeaseServiceTest::testRenewReturnsFalseWhenExecuteAffectsZero',
		],
		[
			'name' => 'FilterService min_level check always true',
			'file' => $root . '/lib/Service/FilterService.php',
			'search' => '$minLevel = (int)($settings[\'min_level\'] ?? 3);
		if ($level < $minLevel) {
			return [\'matched\' => false, \'muted\' => false];
		}',
			'replace' => '$minLevel = (int)($settings[\'min_level\'] ?? 3);
		if (false && $level < $minLevel) {
			return [\'matched\' => false, \'muted\' => false];
		}',
			'filter' => 'FilterServiceTest::testMinLevelFiltersWarnWhenDefaultError',
		],
		[
			'name' => 'SsrfGuard https check allows http',
			'file' => $root . '/lib/Service/SsrfGuard.php',
			'search' => 'if ($scheme !== \'https\') {
			throw new ValidationException(\'Webhook URL is not allowed.\', [\'url\' => \'Webhook URL is not allowed.\'], \'LCK_INVALID_URL\');
		}',
			'replace' => 'if ($scheme !== \'https\' && $scheme !== \'http\') {
			throw new ValidationException(\'Webhook URL is not allowed.\', [\'url\' => \'Webhook URL is not allowed.\'], \'LCK_INVALID_URL\');
		}',
			'filter' => 'SsrfGuardTest::testPinRejectsHttp',
		],
		[
			'name' => 'SecretStore mask leaks last 4 chars',
			'file' => $root . '/lib/Service/SecretStore.php',
			'search' => '$host = parse_url($plaintext, PHP_URL_HOST);
		if (is_string($host) && $host !== \'\') {
			return \'Saved (\' . $host . \')\';
		}
		return \'Saved\';',
			'replace' => '$host = parse_url($plaintext, PHP_URL_HOST);
		$len = strlen($plaintext);
		$last = $len >= 4 ? substr($plaintext, -4) : $plaintext;
		return \'••••\' . $last;',
			'filter' => 'SecretStoreTest::testMaskShowsHostOnlyNeverPathToken',
		],
		[
			'name' => 'SafeHttpClient reconnects by hostname via file_get_contents',
			'file' => $root . '/lib/Service/SafeHttpClient.php',
			'search' => '$pin = $this->ssrfGuard->pin($url, $allowPrivate);
		$body = json_encode($payload, JSON_THROW_ON_ERROR);
		return $this->requestViaCurl($pin, \'POST\', $body, 10, 5);',
			'replace' => '$pin = $this->ssrfGuard->pin($url, $allowPrivate);
		$body = json_encode($payload, JSON_THROW_ON_ERROR);
		// MUTANT: hostname reconnect (DNS rebinding)
		@file_get_contents($url);
		return $this->requestViaCurl($pin, \'POST\', $body, 10, 5);',
			'filter' => 'AbsoluteNoGosTest::testArchitectureScriptPasses',
		],
		[
			'name' => 'MuteRegexValidator accepts nested quantifier ReDoS',
			'file' => $root . '/lib/Service/MuteRegexValidator.php',
			'search' => '// Nested quantifiers: (…+)+, (…*)*, (?:…+){2,}, etc.
		if (preg_match(\'/\\([^)]*[+*][^)]*\\)[+*{]/\', $pattern) === 1) {
			throw new ValidationException(\'Invalid mute pattern.\', [\'value\' => \'Mute pattern is not allowed.\'], \'LCK_INVALID_REGEX\');
		}',
			'replace' => '// MUTANT: skip nested quantifier rejection
		if (false && preg_match(\'/\\([^)]*[+*][^)]*\\)[+*{]/\', $pattern) === 1) {
			throw new ValidationException(\'Invalid mute pattern.\', [\'value\' => \'Mute pattern is not allowed.\'], \'LCK_INVALID_REGEX\');
		}',
			'filter' => 'MuteRegexValidatorTest::testRejectsDangerousNested',
		],
		[
			'name' => 'WatchRunner persists without lease renew in TXN',
			'file' => $root . '/lib/Service/WatchRunner.php',
			'search' => '// NN-20 / SF-Z02: FOR UPDATE + renew inside the same TXN as cursor/pending writes.
			if (!$this->leaseService->renewInTransaction($owner)) {
				$this->db->rollBack();
				return false;
			}
			$this->pendingStore->insertForChannels($eventId, $channels, $payload);',
			'replace' => '// MUTANT: skip lease renew inside TXN
			$this->pendingStore->insertForChannels($eventId, $channels, $payload);',
			'filter' => 'LeaseGatedWritesTest::testAtomicHelpersRenewLeaseInsideTransaction',
		],
		[
			'name' => 'LeaseService renewInTransaction skips FOR UPDATE',
			'file' => $root . '/lib/Service/LeaseService.php',
			'search' => '$sql = $qb->getSQL() . \' FOR UPDATE\';
		$result = $this->db->executeQuery($sql, $qb->getParameters(), $qb->getParameterTypes());',
			'replace' => '$sql = $qb->getSQL();
		$result = $this->db->executeQuery($sql, $qb->getParameters(), $qb->getParameterTypes());',
			'filter' => 'LeaseGatedWritesTest::testAtomicHelpersRenewLeaseInsideTransaction',
		],
		[
			'name' => 'SettingsService skips invalidateChannel on URL change',
			'file' => $root . '/lib/Service/SettingsService.php',
			'search' => '// H1: never keep verified_at across a cipher change (even when disabled).
			if ($urlChanging) {
				$this->channelTestProof->invalidateChannel(\'slack\');
			}',
			'replace' => '// MUTANT: skip invalidate on slack URL change
			if (false && $urlChanging) {
				$this->channelTestProof->invalidateChannel(\'slack\');
			}',
			'filter' => 'SettingsServiceTest::testEnableRejectedAfterUrlChangeWhileDisabled',
		],
		[
			'name' => 'SettingsService drops app_list size cap',
			'file' => $root . '/lib/Service/SettingsService.php',
			'search' => 'if (count($input[\'app_list\']) > self::APP_LIST_MAX) {
				throw new ValidationException(\'Too many apps in the filter list.\', [\'app_list\' => \'Too many apps in the filter list.\']);
			}',
			'replace' => 'if (false && count($input[\'app_list\']) > self::APP_LIST_MAX) {
				throw new ValidationException(\'Too many apps in the filter list.\', [\'app_list\' => \'Too many apps in the filter list.\']);
			}',
			'filter' => 'SettingsServiceTest::testAppListRejectsTooManyEntries',
		],
		[
			'name' => 'SettingsService allows App Admin to change excerpts',
			'file' => $root . '/lib/Service/SettingsService.php',
			'search' => 'if (array_key_exists(\'include_message_excerpts\', $input)) {
			if (!$isNcAdmin) {
				throw new ForbiddenException(\'Only Nextcloud admins can change log excerpt settings.\');
			}',
			'replace' => 'if (array_key_exists(\'include_message_excerpts\', $input)) {
			if (false && !$isNcAdmin) {
				throw new ForbiddenException(\'Only Nextcloud admins can change log excerpt settings.\');
			}',
			'filter' => 'SettingsServiceTest::testExcerptsDisableForbiddenForAppAdmin',
		],
		[
			'name' => 'SettingsService drops notification recipient cap',
			'file' => $root . '/lib/Service/SettingsService.php',
			'search' => 'if (count($n[\'recipient_uids\']) > self::NOTIFICATION_RECIPIENTS_MAX) {
					throw new ValidationException(
						\'Too many notification recipients.\',
						[\'channels.notification.recipient_uids\' => \'Too many notification recipients.\']
					);
				}',
			'replace' => 'if (false && count($n[\'recipient_uids\']) > self::NOTIFICATION_RECIPIENTS_MAX) {
					throw new ValidationException(
						\'Too many notification recipients.\',
						[\'channels.notification.recipient_uids\' => \'Too many notification recipients.\']
					);
				}',
			'filter' => 'SettingsServiceTest::testNotificationRecipientsRejectTooManyEntries',
		],
		[
			'name' => 'SettingsService skips email enable proof',
			'file' => $root . '/lib/Service/SettingsService.php',
			'search' => 'if ($wantEnabled) {
				$this->assertEmailChannelMayEnable(
					$nextRecipients,
					$recipientsChanged,
					$wasEnabled,
					$actorUid,
					$pre
				);
			}',
			'replace' => 'if (false && $wantEnabled) {
				$this->assertEmailChannelMayEnable(
					$nextRecipients,
					$recipientsChanged,
					$wasEnabled,
					$actorUid,
					$pre
				);
			}',
			'filter' => 'SettingsServiceTest::testEmailEnableRejectedWithoutTestProof',
		],
		[
			'name' => 'AccessService drops app admin cap',
			'file' => $root . '/lib/Service/AccessService.php',
			'search' => 'if (count($raw) > self::APP_ADMINS_MAX) {
			throw new ValidationException(
				\'Too many app admins.\',
				[\'access.app_admins\' => \'Too many app admins.\'],
				\'LCK_VALIDATION\'
			);
		}',
			'replace' => 'if (false && count($raw) > self::APP_ADMINS_MAX) {
			throw new ValidationException(
				\'Too many app admins.\',
				[\'access.app_admins\' => \'Too many app admins.\'],
				\'LCK_VALIDATION\'
			);
		}',
			'filter' => 'AccessServiceTest::testTooManyAppAdminsRejected',
		],
		[
			'name' => 'SsrfGuard drops Azure IMDS always-block',
			'file' => $root . '/lib/Service/SsrfGuard.php',
			'search' => '\'168.63.129.16\', // Azure IMDS / wire server
			\'::ffff:168.63.129.16\',',
			'replace' => '// MUTANT: Azure IMDS not always-blocked
			// \'168.63.129.16\',
			// \'::ffff:168.63.129.16\',',
			'filter' => 'SsrfGuardTest::testRejectsAzureImdsEvenWithAllowPrivate',
		],
		[
			'name' => 'ChannelStateStore safeError returns raw',
			'file' => $root . '/lib/Service/ChannelStateStore.php',
			'search' => 'public static function safeError(string $error): string
	{
		$msg = strtolower($error);
		if (str_contains($msg, \'secret\') || str_contains($msg, \'re-enter\') || str_contains($msg, \'decrypt\')) {
			return self::ERR_SECRETS;
		}
		if (str_contains($msg, \'mail\') || str_contains($msg, \'email\') || str_contains($msg, \'smtp\')) {
			return self::ERR_MAIL;
		}
		if (str_contains($msg, \'webhook\') || str_contains($msg, \'http\') || str_contains($msg, \'curl\')
			|| str_contains($msg, \'ssl\') || str_contains($msg, \'tls\')) {
			return self::ERR_HTTP;
		}
		// Curated watch-run copy (and close variants) — never invent Watching after a failed check.
		if (str_contains($msg, \'cannot read the log\') || str_contains($msg, \'check permissions\')) {
			return \'Cannot read the log file. Check permissions.\';
		}
		if (str_contains($msg, \'checking the log\') || str_contains($msg, \'unsupported\')
			|| str_contains($msg, \'file-based\') || str_contains($msg, \'syslog\')) {
			return \'Something went wrong while checking the log.\';
		}
		return self::ERR_GENERIC;
	}',
			'replace' => 'public static function safeError(string $error): string
	{
		// MUTANT: leak raw diagnostics
		return $error;
	}',
			'filter' => 'ChannelStateStoreTest::testSafeErrorNeverReturnsRawDiagnostics',
		],
		[
			'name' => 'LogFileService startFresh skips NC admin check',
			'file' => $root . '/lib/Service/LogFileService.php',
			'search' => 'private function assertNcAdmin(string $uid): void
	{
		if ($uid === \'\' || !$this->accessService->isNcAdmin($uid)) {
			throw new ForbiddenException(\'Not authorized.\');
		}
	}',
			'replace' => 'private function assertNcAdmin(string $uid): void
	{
		// MUTANT: any non-empty uid may mutate the logfile
		if ($uid === \'\') {
			throw new ForbiddenException(\'Not authorized.\');
		}
	}',
			'filter' => 'LogFileServiceTest::testStartFreshRequiresNcAdmin',
		],
		[
			'name' => 'LogFileService search accepts empty needle',
			'file' => $root . '/lib/Service/LogFileService.php',
			'search' => '$needle = trim($needle);
		if ($needle === \'\') {
			throw new ValidationException(
				\'Enter something to search for.\',
				[\'q\' => \'Enter something to search for.\'],
			);
		}',
			'replace' => '$needle = trim($needle);
		if (false && $needle === \'\') {
			throw new ValidationException(
				\'Enter something to search for.\',
				[\'q\' => \'Enter something to search for.\'],
			);
		}',
			'filter' => 'LogFileServiceTest::testSearchRejectsEmptyNeedle',
		],
		[
			'name' => 'LogFileService allowlist accepts any basename',
			'file' => $root . '/lib/Service/LogFileService.php',
			'search' => 'public function isAllowlistedBasename(string $liveBase, string $candidate): bool
	{
		if ($candidate === $liveBase) {
			return true;
		}
		$quoted = preg_quote($liveBase, \'/\');
		$max = self::ROTATE_INDEX_MAX;
		if (preg_match(\'/^\' . $quoted . \'\.([1-9]|[1-4][0-9]|\' . $max . \')$/\', $candidate) === 1) {
			return true;
		}
		return preg_match(\'/^\' . $quoted . \'\.lck-rotated-\d{8}-\d{6}$/\', $candidate) === 1;
	}',
			'replace' => 'public function isAllowlistedBasename(string $liveBase, string $candidate): bool
	{
		// MUTANT: any basename in the directory is readable
		return $candidate !== \'\';
	}',
			'filter' => 'LogFileServiceTest::testAllowlistHelperAcceptsBoundedRotateIndexes',
		],
		[
			'name' => 'logs.js toastOk calls missing show API (blocks reload)',
			'file' => $root . '/js/logs.js',
			'search' => "function toastOk(msg) {
		if (window.LogCheckToasts && typeof LogCheckToasts.showSuccess === 'function') {
			LogCheckToasts.showSuccess(msg);
		}
	}",
			'replace' => "function toastOk(msg) {
		// MUTANT: call non-existent show() so success path throws before reload
		if (window.LogCheckToasts) {
			LogCheckToasts.show(msg);
		}
	}",
			'filter' => 'DesignSystemChromeTest::testLogsMutatePathsAlwaysReload',
		],
		[
			'name' => 'LogBackendService leaves relative logfile as CWD-relative',
			'file' => $root . '/lib/Service/LogBackendService.php',
			'search' => 'if ($configured !== \'\') {
			return $this->absolutizeConfiguredPath($configured, $dataDir);
		}',
			'replace' => 'if ($configured !== \'\') {
			// MUTANT: return relative path (sibling scan inherits PHP CWD)
			return $configured;
		}',
			'filter' => 'LogBackendServiceTest::testRelativeLogfileAnchoredUnderDataDirectory',
		],
		[
			'name' => 'SupportUsLinks reintroduces paypalUrl in forLocale',
			'file' => $root . '/lib/Support/SupportUsLinks.php',
			'search' => "'sponsorsUrl' => self::SPONSORS_URL,
			'supportPageUrl' => \$this->supportPageUrl(\$languageCode),
			'vendorName' => self::VENDOR_NAME,
			'isGerman' => \$this->isGermanLocale(\$languageCode),
		];",
			'replace' => "'sponsorsUrl' => self::SPONSORS_URL,
			'paypalUrl' => 'https://paypal.example/donate',
			'supportPageUrl' => \$this->supportPageUrl(\$languageCode),
			'vendorName' => self::VENDOR_NAME,
			'isGerman' => \$this->isGermanLocale(\$languageCode),
		];",
			'filter' => 'SupportUsLinksTest::testForLocaleOmitsPaymentPspsAndExposesRequiredKeys',
		],
		[
			'name' => 'SupportUsLinks spoofs sponsors host',
			'file' => $root . '/lib/Support/SupportUsLinks.php',
			'search' => "public const SPONSORS_URL = 'https://github.com/sponsors/aSoftwareByDesignRepository';",
			'replace' => "public const SPONSORS_URL = 'https://evil.example/phish';",
			'filter' => 'SupportUsLinksTest::testSponsorsUrlIsGitHubOnly',
		],
		[
			'name' => 'SupportUsLinks drops subject encoding',
			'file' => $root . '/lib/Support/SupportUsLinks.php',
			'search' => "return 'mailto:' . self::CONTACT_EMAIL . '?subject=' . rawurlencode(\$subject);",
			'replace' => "return 'mailto:' . self::CONTACT_EMAIL . '?subject=' . \$subject;",
			'filter' => 'SupportUsLinksTest::testEnterpriseMailtoEncodesSubjectAndUsesLocale',
		],
		[
			'name' => 'SupportUsLinks forces English locale for German',
			'file' => $root . '/lib/Support/SupportUsLinks.php',
			'search' => "return \$lang === 'de' || str_starts_with(\$lang, 'de-');",
			'replace' => 'return false;',
			'filter' => 'SupportUsLinksTest::testGermanLocaleDetectionRejectsFalseFriendsAndEmpty',
		],
		[
			'name' => 'Support template allows any https sponsors URL',
			'file' => $root . '/templates/parts/settings/support.php',
			'search' => "\$sponsorsOk = \$sponsorsUrl !== ''
	&& str_starts_with(\$sponsorsUrl, 'https://github.com/sponsors/');",
			'replace' => "\$sponsorsOk = \$sponsorsUrl !== ''
	&& str_starts_with(\$sponsorsUrl, 'https://');",
			'filter' => 'SupportUsSectionRenderTest::testRenderRejectsNonSponsorsHttpsAsDonationCta',
		],
		[
			'name' => 'LogHealthProbe always ok',
			'file' => $root . '/lib/Service/Health/LogHealthProbe.php',
			'search' => 'if (!$supported || !$topologyOk) {
			return new HealthCard(
				\'log\',
				\'Log alerts\',
				HealthCardState::CRITICAL,',
			'replace' => 'if (!$supported || !$topologyOk) {
			return new HealthCard(
				\'log\',
				\'Log alerts\',
				HealthCardState::OK,',
			'filter' => 'LogHealthProbeTest::testUnsupportedIsNeverOk',
		],
		[
			'name' => 'getInstanceStatus skips host equality',
			'file' => $root . '/lib/Service/SafeHttpClient.php',
			'search' => 'if (!hash_equals(strtolower($expectedAscii), strtolower($hostAscii))) {
			throw new \InvalidArgumentException(\'Instance status URL is not allowed.\');
		}',
			'replace' => 'if (false && !hash_equals(strtolower($expectedAscii), strtolower($hostAscii))) {
			throw new \InvalidArgumentException(\'Instance status URL is not allowed.\');
		}',
			'filter' => 'SafeHttpClientInstanceStatusTest::testRejectsOffHostUrl',
		],
		[
			'name' => 'DiskHealthProbe free ratio always ok',
			'file' => $root . '/lib/Service/Health/DiskHealthProbe.php',
			'search' => 'if ($ratio < self::FREE_DEGRADED) {
			return HealthCardState::CRITICAL;
		}
		if ($ratio < self::FREE_OK) {
			return HealthCardState::DEGRADED;
		}
		return HealthCardState::OK;',
			'replace' => 'if (false && $ratio < self::FREE_DEGRADED) {
			return HealthCardState::CRITICAL;
		}
		if (false && $ratio < self::FREE_OK) {
			return HealthCardState::DEGRADED;
		}
		return HealthCardState::OK;',
			'filter' => 'DiskHealthProbeTest::testStateForRatioThresholds',
		],
		[
			'name' => 'UpdatesHealthProbe empty cache reports ok',
			'file' => $root . '/lib/Service/Health/UpdatesHealthProbe.php',
			'search' => 'if ($lastAt <= 0 || $raw === \'\') {
			return new HealthCard(
				\'updates\',
				\'Updates\',
				HealthCardState::UNKNOWN,',
			'replace' => 'if ($lastAt <= 0 || $raw === \'\') {
			return new HealthCard(
				\'updates\',
				\'Updates\',
				HealthCardState::OK,',
			'filter' => 'UpdatesHealthProbeTest::testEmptyCacheIsUnknownNotOk',
		],
		[
			'name' => 'LogHealthProbe never-ran is ok',
			'file' => $root . '/lib/Service/Health/LogHealthProbe.php',
			'search' => 'if ($lastCheck <= 0) {
			return new HealthCard(
				\'log\',
				\'Log alerts\',
				HealthCardState::DEGRADED,',
			'replace' => 'if ($lastCheck <= 0) {
			return new HealthCard(
				\'log\',
				\'Log alerts\',
				HealthCardState::OK,',
			'filter' => 'LogHealthProbeTest::testWatchingWithoutLastCheckIsNotOk',
		],
		[
			'name' => 'HttpsHealthProbe accepts empty body as healthy',
			'file' => $root . '/lib/Service/Health/HttpsHealthProbe.php',
			'search' => 'if ($code < 200 || $code >= 300) {
			return false;
		}
		$data = json_decode($body, true);
		return is_array($data) && array_key_exists(\'installed\', $data);',
			'replace' => 'if ($code < 200 || $code >= 500) {
			return false;
		}
		return $body === \'\' || str_contains($body, \'{\');',
			'filter' => 'HttpsHealthProbeTest::testHealthyStatusResponseRequiresInstalledJson',
		],
		[
			'name' => 'JobsHealthProbe ignores cronErrors',
			'file' => $root . '/lib/Service/Health/JobsHealthProbe.php',
			'search' => '$hasErrors = $errorsRaw !== \'\' && $errorsRaw !== \'[]\' && $errorsRaw !== \'null\';

		if ($hasErrors) {',
			'replace' => '$hasErrors = false;

		if ($hasErrors) {',
			'filter' => 'JobsHealthProbeTest::testCronErrorsAreCritical',
		],
		[
			'name' => 'SafeHttpClient rejects webroot status path',
			'file' => $root . '/lib/Service/SafeHttpClient.php',
			'search' => 'if ($path === \'/status.php\') {
			return true;
		}
		// e.g. /nextcloud/status.php — one non-dot path segment, no traversal.
		if (preg_match(\'#^/([A-Za-z0-9_-]+)/status\\.php$#\', $path, $m) !== 1) {
			return false;
		}
		$segment = $m[1];
		return $segment !== \'\' && $segment !== \'.\' && $segment !== \'..\';',
			'replace' => 'if ($path === \'/status.php\') {
			return true;
		}
		return false;',
			'filter' => 'SafeHttpClientInstanceStatusTest::testAllowsWebrootStatusPath',
		],
		[
			'name' => 'LogHealthProbe failed run still OK',
			'file' => $root . '/lib/Service/Health/LogHealthProbe.php',
			'search' => '$statusState = isset($status[\'state\']) && is_string($status[\'state\']) ? $status[\'state\'] : \'\';
		if ($error !== \'\' || $statusState === \'degraded\') {
			$attentionAction = $setupAlertsAction;
			if ($error !== \'\' && (stripos($error, \'log\') !== false || stripos($error, \'read\') !== false || stripos($error, \'permission\') !== false)) {
				$attentionAction = $viewLogsAction !== [] ? $viewLogsAction : $setupAlertsAction;
			}
			return new HealthCard(
				\'log\',
				\'Log alerts\',
				HealthCardState::DEGRADED,',
			'replace' => '$statusState = isset($status[\'state\']) && is_string($status[\'state\']) ? $status[\'state\'] : \'\';
		if (false && ($error !== \'\' || $statusState === \'degraded\')) {
			$attentionAction = $setupAlertsAction;
			if ($error !== \'\' && (stripos($error, \'log\') !== false || stripos($error, \'read\') !== false || stripos($error, \'permission\') !== false)) {
				$attentionAction = $viewLogsAction !== [] ? $viewLogsAction : $setupAlertsAction;
			}
			return new HealthCard(
				\'log\',
				\'Log alerts\',
				HealthCardState::DEGRADED,',
			'filter' => 'LogHealthProbeTest::testFailedRunIsNeverOk',
		],
		[
			'name' => 'StatusService failed run still Watching',
			'file' => $root . '/lib/Service/StatusService.php',
			'search' => '} elseif ($runFailed || !$secretsReadable) {
			// Never present Watching/OK after a failed check or unreadable secrets (NN-H01).
			$state = \'degraded\';
			$label = \'Needs attention\';
		} else {
			$state = \'watching\';
			$label = \'Watching\';
		}',
			'replace' => '} else {
			$state = \'watching\';
			$label = \'Watching\';
		}',
			'filter' => 'StatusServiceTest::testFailedRunIsNeverWatching',
		],
		[
			'name' => 'PendingStore markSent drops claim generation pin',
			'file' => $root . '/lib/Service/PendingStore.php',
			'search' => '->andWhere($qb->expr()->eq(\'status\', $qb->createNamedParameter(\'sending\')))
			->andWhere($qb->expr()->eq(\'claim_gen\', $qb->createNamedParameter($claimGen)));
		return $qb->executeStatement() === 1;
	}

	/**
	 * Fail/retry a claim only for this claim generation.
	 */
	public function markFailed(string $eventId, string $channel, int $attempts, int $claimGen): bool',
			'replace' => ';
		return $qb->executeStatement() === 1;
	}

	/**
	 * Fail/retry a claim only for this claim generation.
	 */
	public function markFailed(string $eventId, string $channel, int $attempts, int $claimGen): bool',
			'filter' => 'PendingStoreClaimGenerationTest::testMarkSentRequiresSendingAndUpdatedAt',
		],
		[
			'name' => 'ChannelDispatcher skips hasSent dedupe gate',
			'file' => $root . '/lib/Service/ChannelDispatcher.php',
			'search' => 'if ($this->deliveryStore->hasSent($row[\'event_id\'], $channel)) {
				if (!$this->pendingStore->markSent($row[\'event_id\'], $channel, $claimGen)) {
					$this->logger->warning(\'HealthCheck markSent lost claim generation (already delivered)\', [
						\'app\' => \'logcheck\',
						\'channel\' => $channel,
						\'event_id\' => $row[\'event_id\'],
					]);
				}
				continue;
			}',
			'replace' => 'if (false && $this->deliveryStore->hasSent($row[\'event_id\'], $channel)) {
				continue;
			}',
			'filter' => 'ChannelDispatcherTest::testAlreadySentSkipsOutboundAndCompletesPending',
		],
		[
			'name' => 'ChannelDispatcher skips delivery record when markSent loses',
			'file' => $root . '/lib/Service/ChannelDispatcher.php',
			'search' => '$this->send($channel, $row[\'payload\'], $settings);
				// Always record outbound success first so a reclaimer cannot HTTP again
				// even if this claim_gen lost the race for pending markSent (LCK-01).
				$this->deliveryStore->record($row[\'event_id\'], $channel, \'sent\');
				if (!$this->pendingStore->markSent($row[\'event_id\'], $channel, $claimGen)) {
					$this->logger->warning(\'HealthCheck markSent lost claim generation (delivery already recorded)\', [
						\'app\' => \'logcheck\',
						\'channel\' => $channel,
						\'event_id\' => $row[\'event_id\'],
					]);
				}
				$this->channelStateStore->recordSuccess($channel);',
			'replace' => '$this->send($channel, $row[\'payload\'], $settings);
				if (!$this->pendingStore->markSent($row[\'event_id\'], $channel, $claimGen)) {
					continue;
				}
				$this->deliveryStore->record($row[\'event_id\'], $channel, \'sent\');
				$this->channelStateStore->recordSuccess($channel);',
			'filter' => 'ChannelDispatcherTest::testSuccessfulSendRecordsDeliveryEvenIfMarkSentLost',
		],
		[
			'name' => 'LogFileService resolveDownload skips NC admin check',
			'file' => $root . '/lib/Service/LogFileService.php',
			'search' => 'public function resolveDownload(?string $fileId, string $actorUid): array {
		// Full-file download is NC system admin only (App Admins keep in-app viewer/search).
		$this->assertNcAdmin($actorUid);
		$resolved = $this->resolveReadableFile($fileId);',
			'replace' => 'public function resolveDownload(?string $fileId, string $actorUid): array {
		$resolved = $this->resolveReadableFile($fileId);',
			'filter' => 'LogFileServiceTest::testResolveDownloadForbiddenForAppAdmin',
		],
	];
}

/**
 * @param array{name: string, file: string, search: string, replace: string, filter: string} $m
 */
function runMutant(array $m, string $phpunit, string $root): bool
{
	$original = file_get_contents($m['file']);
	if ($original === false) {
		fail('cannot read ' . $m['file']);
		return false;
	}
	if (!str_contains($original, $m['search'])) {
		fail('mutation search string not found for ' . $m['name']);
		return false;
	}
	$mutated = str_replace($m['search'], $m['replace'], $original, $count);
	if ($count !== 1) {
		fail('expected 1 replacement for ' . $m['name'] . ', got ' . $count);
		return false;
	}

	$backup = $m['file'] . '.mutation-bak';
	if (file_put_contents($backup, $original) === false) {
		fail('cannot write backup for ' . $m['name']);
		return false;
	}
	if (file_put_contents($m['file'], $mutated) === false) {
		@unlink($backup);
		fail('cannot write mutant for ' . $m['name']);
		return false;
	}

	$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phpunit)
		. ' --testsuite unit --filter ' . escapeshellarg($m['filter'])
		. ' 2>&1';
	$cwd = getcwd();
	chdir($root);
	exec($cmd, $out, $code);
	chdir($cwd !== false ? $cwd : $root);

	$restored = file_put_contents($m['file'], $original);
	@unlink($backup);
	if ($restored === false) {
		fail('CRITICAL: failed to restore ' . $m['file'] . ' after ' . $m['name']);
		return false;
	}

	// Mutant must be killed: PHPUnit must fail (non-zero).
	if ($code === 0) {
		fail('SURVIVED: ' . $m['name'] . ' (phpunit exit 0)');
		say(implode("\n", array_slice($out, -20)));
		return false;
	}
	ok('killed: ' . $m['name'] . ' (phpunit exit ' . $code . ')');
	return true;
}

foreach (mutants($root) as $m) {
	runMutant($m, $phpunit, $root);
}

exit($fail > 0 ? 1 : 0);
