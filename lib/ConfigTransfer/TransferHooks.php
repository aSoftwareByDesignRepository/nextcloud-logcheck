<?php

declare(strict_types=1);

/**
 * LogCheck wiring for the shared transfer engine.
 *
 * All real config lives in the `lck_settings` singleton JSON doc, so EVERY
 * transferred key is store-backed class 'mirrored' and the whole batch is
 * written through SettingsService::save() — the app's own CAS + validator
 * (mergeAndValidate), never a raw doc rewrite (MH-12 / NN-08).
 *
 * Channels: the exported `channels` field carries ICrypto ciphertext and
 * per-instance test-proof state. normalize() strips both; the write path
 * never sends url fields to save(), so the target's own ciphers and proof
 * state are preserved. Channel `enabled` is honestly degraded: it survives
 * only where the target can satisfy the channel's own proof gates — a
 * transfer can never smuggle in a channel the operator couldn't enable.
 */

namespace OCA\LogCheck\ConfigTransfer;

use OCA\LogCheck\Exception\ValidationException;
use OCA\LogCheck\Service\AccessService;
use OCA\LogCheck\Service\AuditService;
use OCA\LogCheck\Service\ChannelTestProof;
use OCA\LogCheck\Service\Health\HealthDashboardService;
use OCA\LogCheck\Service\InputCoercion;
use OCA\LogCheck\Service\MuteRegexValidator;
use OCA\LogCheck\Service\SettingsService;
use OCA\LogCheck\Service\TopologyGuard;
use OCA\LogCheck\Service\UpgradeBackupService;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

final class TransferHooks implements ConfigTransferHooks
{
	private const CHANNEL_NAMES = ['notification', 'email', 'slack', 'webhook'];

	public function __construct(
		private readonly SettingsService $settings,
		private readonly AccessService $access,
		private readonly MuteRegexValidator $muteRegexValidator,
		private readonly TopologyGuard $topologyGuard,
		private readonly ChannelTestProof $channelTestProof,
		private readonly UpgradeBackupService $backups,
		private readonly AuditService $audit,
		private readonly IUserManager $userManager,
		private readonly LoggerInterface $logger,
	) {
	}

	public function requireTransferAdmin(string $uid): void
	{
		// Door admins = Nextcloud admins + app admins — the app's real gate.
		$this->access->assertEntitled($uid);
	}

	public function validateValue(string $key, string $value): ?string
	{
		try {
			$this->normalize($key, $value);
			return null;
		} catch (ValidationException $e) {
			return $e->getErrorCode();
		} catch (\JsonException) {
			return 'LCK_INVALID_JSON';
		} catch (\Throwable) {
			return 'LCK_VALIDATION';
		}
	}

	public function normalizeValue(string $key, string $value): string
	{
		try {
			return $this->normalize($key, $value);
		} catch (\Throwable) {
			return $value;
		}
	}

	public function validateMirrored(array $keyValues): ?string
	{
		// The batch is the unit — one settings doc, all-or-nothing. Every
		// key already passed validateValue; re-run the batch so cross-key
		// drift between plan and apply still fails loudly, never silently.
		foreach ($keyValues as $key => $value) {
			$err = $this->validateValue((string)$key, (string)$value);
			if ($err !== null) {
				return $err;
			}
		}
		return null;
	}

	public function writeMirrored(array $keyValues): void
	{
		// Two saves: scalar/doc keys first (incl. the access door), channels
		// second — the recipient-entitlement check inside mergeChannels must
		// see the imported app_admins already persisted, and both saves live
		// inside the engine's single apply transaction (all-or-nothing).
		$main = [];
		$channelsDoc = null;
		foreach ($keyValues as $key => $value) {
			$normalized = $this->normalize((string)$key, (string)$value);
			switch ($key) {
				case 'access':
					$main['access'] = $this->decodeJsonObject($normalized);
					break;
				case 'watch_enabled':
				case 'include_message_excerpts':
				case 'allow_private_webhooks':
					$main[$key] = $normalized === 'true';
					break;
				case 'min_level':
				case 'coalesce_seconds':
					$main[$key] = (int)$normalized;
					break;
				case 'app_mode':
					$main[$key] = $normalized;
					break;
				case 'app_list':
				case 'mutes':
				case 'health_disabled_probes':
					$main[$key] = json_decode($normalized, true, 512, JSON_THROW_ON_ERROR);
					break;
				case 'channels':
					$channelsDoc = $this->decodeJsonObject($normalized);
					break;
				default:
					throw new ValidationException('Unknown transfer key.', [], 'LCK_UNKNOWN_KEY');
			}
		}
		if (($main['include_message_excerpts'] ?? false) === true) {
			// A reviewed dry-run + confirmed apply IS the explicit privacy
			// confirmation the settings API demands (security-flagged in
			// the report — the operator signed off on this exact value).
			$main['excerpt_confirm'] = 'CONFIRM';
		}
		if ($main !== []) {
			$this->saveSettings($main);
		}
		if ($channelsDoc !== null) {
			$this->saveChannels($channelsDoc);
		}
	}

	public function securityRelevantKeys(): array
	{
		return ['access', 'watch_enabled', 'include_message_excerpts', 'allow_private_webhooks', 'channels'];
	}

	public function createSafetySnapshot(string $reason): ?string
	{
		try {
			$result = $this->backups->createSnapshot($reason);
			return (string)($result['id'] ?? '');
		} catch (\Throwable) {
			if ($this->backups->hasDataToBackup()) {
				throw new \RuntimeException('TRANSFER_SNAPSHOT_FAILED');
			}
			return null;
		}
	}

	public function settingsSectionForKey(string $key): ?string
	{
		return match ($key) {
			'access' => 'people',
			'channels', 'include_message_excerpts', 'allow_private_webhooks' => 'alerts',
			'watch_enabled', 'min_level', 'coalesce_seconds', 'app_mode',
			'app_list', 'mutes', 'health_disabled_probes' => 'rules',
			default => null,
		};
	}

	public function auditEvent(string $action, string $actor, array $details): void
	{
		// The app's audit facility: CriticalActionPerformedEvent + durable
		// app-log line (never silent, PII-key obfuscation built in).
		$this->audit->log($actor === '' ? 'cli' : $actor, 'transfer_' . $action, $details);
	}

	// ── per-key normalisation — same rules mergeAndValidate() enforces ──

	private function normalize(string $key, string $value): string
	{
		return match ($key) {
			'access' => $this->normalizeAccessDoc($value),
			'watch_enabled' => $this->normalizeWatchEnabled($value),
			'min_level' => $this->normalizeMinLevel($value),
			'coalesce_seconds' => $this->normalizeCoalesce($value),
			'app_mode' => $this->normalizeAppMode($value),
			'app_list' => $this->normalizeAppList($value),
			'mutes' => $this->normalizeMutes($value),
			'include_message_excerpts', 'allow_private_webhooks' => $this->normalizeBool($value, $key),
			'health_disabled_probes' => $this->normalizeProbeList($value),
			'channels' => $this->normalizeChannels($value),
			default => throw new ValidationException('Unknown transfer key.', [], 'LCK_UNKNOWN_KEY'),
		};
	}

	private function normalizeAccessDoc(string $value): string
	{
		$doc = $this->decodeJsonObject($value);
		// normalizeAccess() is the exact validator save() calls — mode is
		// forced 'restricted' (Open is forbidden), uids must exist.
		$normalized = $this->access->normalizeAccess($doc);
		return $this->encode($normalized);
	}

	private function normalizeWatchEnabled(string $value): string
	{
		$want = InputCoercion::asBool($value, 'watch_enabled');
		if ($want) {
			$runtime = $this->currentRuntime();
			if ($this->topologyGuard->isMismatch($runtime)) {
				throw new ValidationException(
					'Not supported on this server layout.',
					['watch_enabled' => 'Not supported on this server layout.'],
					'LCK_UNSUPPORTED_TOPOLOGY',
				);
			}
		}
		return $want ? 'true' : 'false';
	}

	private function normalizeMinLevel(string $value): string
	{
		$level = InputCoercion::asInt($value, 'min_level');
		if ($level < 0 || $level > 4) {
			throw new ValidationException('Invalid level.', ['min_level' => 'Invalid level.']);
		}
		return (string)$level;
	}

	private function normalizeCoalesce(string $value): string
	{
		$coalesce = InputCoercion::asInt($value, 'coalesce_seconds');
		if (!in_array($coalesce, [300, 900, 3600], true)) {
			throw new ValidationException('Invalid alert pace.', ['coalesce_seconds' => 'Invalid alert pace.']);
		}
		return (string)$coalesce;
	}

	private function normalizeAppMode(string $value): string
	{
		$mode = InputCoercion::asString($value, 'app_mode');
		if (!in_array($mode, ['all', 'allow', 'deny'], true)) {
			throw new ValidationException('Invalid app filter.', ['app_mode' => 'Invalid app filter.']);
		}
		return $mode;
	}

	private function normalizeAppList(string $value): string
	{
		$list = $this->decodeJsonList($value, 'app_list');
		if (count($list) > SettingsService::APP_LIST_MAX) {
			throw new ValidationException('Too many apps in the filter list.', ['app_list' => 'Too many apps in the filter list.']);
		}
		$normalized = [];
		foreach ($list as $rawId) {
			if (!is_scalar($rawId)) {
				throw new ValidationException('Invalid app id.', ['app_list' => 'Invalid app id.']);
			}
			$id = trim((string)$rawId);
			if ($id === '') {
				continue;
			}
			if (strlen($id) > SettingsService::APP_ID_MAX_LEN) {
				throw new ValidationException('App id is too long.', ['app_list' => 'App id is too long.']);
			}
			$normalized[] = $id;
		}
		return $this->encode(array_values(array_unique($normalized)));
	}

	private function normalizeMutes(string $value): string
	{
		$list = $this->decodeJsonList($value, 'mutes');
		if (count($list) > 100) {
			throw new ValidationException('Too many mutes.', ['mutes' => 'Too many mutes.']);
		}
		$mutes = [];
		$hasSelf = false;
		foreach ($list as $mute) {
			if (!is_array($mute)) {
				continue;
			}
			$type = (string)($mute['type'] ?? '');
			$rawMuteValue = $mute['value'] ?? '';
			if (!is_scalar($rawMuteValue) && $rawMuteValue !== '') {
				throw new ValidationException('Invalid mute value.', ['mutes' => 'Invalid mute value.']);
			}
			$muteValue = (string)$rawMuteValue;
			if ($type === 'app') {
				if ($muteValue === '') {
					continue;
				}
				if ($muteValue === \OCA\LogCheck\AppInfo\Application::APP_ID) {
					$hasSelf = true;
				}
				$mutes[] = ['type' => 'app', 'value' => $muteValue];
			} elseif ($type === 'regex') {
				$this->muteRegexValidator->assertSafe($muteValue);
				$flags = (string)($mute['flags'] ?? 'i');
				$mutes[] = ['type' => 'regex', 'value' => $muteValue, 'flags' => $flags];
			}
		}
		if (!$hasSelf) {
			array_unshift($mutes, ['type' => 'app', 'value' => \OCA\LogCheck\AppInfo\Application::APP_ID]);
		}
		return $this->encode($mutes);
	}

	private function normalizeBool(string $value, string $key): string
	{
		return InputCoercion::asBool($value, $key) ? 'true' : 'false';
	}

	private function normalizeProbeList(string $value): string
	{
		$list = $this->decodeJsonList($value, 'health_disabled_probes');
		$ids = [];
		foreach ($list as $rawId) {
			if (!is_string($rawId)) {
				throw new ValidationException('Invalid check.', ['health_disabled_probes' => 'Invalid check.']);
			}
			$id = strtolower(trim($rawId));
			if ($id === '') {
				continue;
			}
			if (!in_array($id, HealthDashboardService::PROBE_IDS, true)) {
				throw new ValidationException('Unknown check.', ['health_disabled_probes' => 'Unknown check.']);
			}
			$ids[] = $id;
		}
		return $this->encode(array_values(array_unique($ids)));
	}

	/**
	 * Canonical sanitized channels doc: ciphers + per-instance proof state
	 * stripped, unknown channel names rejected (a doc the target cannot
	 * understand must not silently degrade), ghost recipient uids filtered
	 * (the report still names them via the 'keep' unresolved policy), and
	 * `enabled` degraded to what the target can prove RIGHT NOW — the same
	 * gates mergeChannels asserts inside save().
	 *
	 * @param array<string,mixed> $doc decoded channels field
	 * @param array<string,mixed> $current target's current settings doc
	 * @return array<string,mixed>
	 */
	private function sanitizeChannels(array $doc, array $current): array
	{
		foreach (array_keys($doc) as $name) {
			if (!in_array((string)$name, self::CHANNEL_NAMES, true)) {
				throw new ValidationException('Unknown channel.', ['channels' => 'Unknown channel.'], 'LCK_UNKNOWN_CHANNEL');
			}
		}
		$curChannels = is_array($current['channels'] ?? null) ? $current['channels'] : [];

		$inN = is_array($doc['notification'] ?? null) ? $doc['notification'] : [];
		$uids = [];
		foreach ((array)($inN['recipient_uids'] ?? []) as $uid) {
			if (!is_string($uid) || $uid === '') {
				continue;
			}
			// Ghost uids are dropped here AND named in the report.
			if (!$this->userManager->userExists($uid)) {
				continue;
			}
			$uids[] = $uid;
		}
		$uids = array_values(array_unique($uids));
		if (count($uids) > SettingsService::NOTIFICATION_RECIPIENTS_MAX) {
			throw new ValidationException('Too many notification recipients.', ['channels' => 'Too many notification recipients.']);
		}

		$inE = is_array($doc['email'] ?? null) ? $doc['email'] : [];
		$recipients = [];
		foreach ((array)($inE['recipients'] ?? []) as $r) {
			if (!is_string($r)) {
				continue;
			}
			$r = trim($r);
			if ($r !== '' && filter_var($r, FILTER_VALIDATE_EMAIL)) {
				$recipients[] = $r;
			}
		}
		$recipients = array_values(array_unique($recipients));
		if (count($recipients) > 20) {
			throw new ValidationException('Too many email recipients.', ['channels' => 'Too many recipients.']);
		}

		$built = [
			'notification' => [
				'enabled' => $this->asChannelBool($inN['enabled'] ?? false),
				'recipient_uids' => $uids,
			],
			'email' => [
				'enabled' => $this->asChannelBool($inE['enabled'] ?? false),
				'recipients' => $recipients,
				'verified_at' => null,
				'fail_count' => 0,
				'last_error' => null,
			],
			'slack' => [
				'enabled' => $this->asChannelBool(is_array($doc['slack'] ?? null) ? ($doc['slack']['enabled'] ?? false) : false),
				'webhook_url_cipher' => null,
				'fail_count' => 0,
				'last_error' => null,
			],
			'webhook' => [
				'enabled' => $this->asChannelBool(is_array($doc['webhook'] ?? null) ? ($doc['webhook']['enabled'] ?? false) : false),
				'url_cipher' => null,
				'fail_count' => 0,
				'last_error' => null,
			],
		];

		$enables = $this->channelEnables($built, $curChannels);
		foreach (self::CHANNEL_NAMES as $name) {
			$built[$name]['enabled'] = $enables[$name];
		}
		return $built;
	}

	/**
	 * Honest `enabled` for a sanitized channels doc against TARGET state —
	 * replicates exactly what mergeChannels asserts, so a preview never
	 * promises an enable the apply could not keep:
	 *  - notification: no proof gate — file's flag applies.
	 *  - email:        enabled stays only when recipients are unchanged AND
	 *                  (already enabled OR state-verified). A changed list
	 *                  needs a fresh test — impossible to prove via import.
	 *  - slack/webhook: enabled stays only when the target already holds a
	 *                  cipher (imports never carry URLs) AND the channel is
	 *                  state-verified.
	 *
	 * @param array<string,mixed> $doc sanitized doc (enabled = file's wish)
	 * @param array<string,mixed> $curChannels target's current channels field
	 * @return array{notification:bool,email:bool,slack:bool,webhook:bool}
	 */
	private function channelEnables(array $doc, array $curChannels): array
	{
		$curE = is_array($curChannels['email'] ?? null) ? $curChannels['email'] : [];
		$curS = is_array($curChannels['slack'] ?? null) ? $curChannels['slack'] : [];
		$curW = is_array($curChannels['webhook'] ?? null) ? $curChannels['webhook'] : [];

		$curRecipients = array_values(array_map('strval', (array)($curE['recipients'] ?? [])));
		$newRecipients = array_values(array_map('strval', (array)($doc['email']['recipients'] ?? [])));
		sort($curRecipients);
		sort($newRecipients);
		$recipientsChanged = $curRecipients !== $newRecipients;

		$slackCipher = $curS['webhook_url_cipher'] ?? null;
		$webhookCipher = $curW['url_cipher'] ?? null;

		return [
			'notification' => !empty($doc['notification']['enabled']),
			'email' => !empty($doc['email']['enabled'])
				&& !$recipientsChanged
				&& (!empty($curE['enabled']) || $this->channelTestProof->isStateVerified('email')),
			'slack' => !empty($doc['slack']['enabled'])
				&& is_string($slackCipher) && $slackCipher !== ''
				&& $this->channelTestProof->isStateVerified('slack'),
			'webhook' => !empty($doc['webhook']['enabled'])
				&& is_string($webhookCipher) && $webhookCipher !== ''
				&& $this->channelTestProof->isStateVerified('webhook'),
		];
	}

	private function normalizeChannels(string $value): string
	{
		$doc = $this->decodeJsonObject($value);
		$current = $this->currentSettings();
		return $this->encode($this->sanitizeChannels($doc, $current));
	}

	// ── write path ─────────────────────────────────────────────────────

	/**
	 * One CAS write through the app's own settings API. Runs inside the
	 * engine's apply transaction: save()'s inner begin/commit nest to a
	 * no-op while a CAS-conflict rollBack aborts the whole import —
	 * fail-closed: the receipt says "nothing was changed", never a
	 * half-written settings doc. No blind retry: a conflict means a
	 * concurrent admin save won, and the transaction is already dead.
	 *
	 * @param array<string,mixed> $input
	 */
	private function saveSettings(array $input): void
	{
		$loaded = $this->settings->load();
		$this->settings->save($input, $loaded['version'], 'config-transfer', true);
	}

	/**
	 * Map the sanitized channels doc onto mergeChannels' input shape:
	 * enabled + recipients only — never url/clear_url (target ciphers are
	 * preserved by construction) — and re-degrade `enabled` against the
	 * freshest state (proof may have moved between preview and apply).
	 * Non-entitled recipients are dropped: mergeChannels would reject the
	 * whole save for them.
	 *
	 * @param array<string,mixed> $doc sanitized channels doc
	 */
	private function saveChannels(array $doc): void
	{
		$current = $this->currentSettings();
		$curChannels = is_array($current['channels'] ?? null) ? $current['channels'] : [];
		$enables = $this->channelEnables($doc, $curChannels);

		$entitled = array_fill_keys($this->access->entitledUids(), true);
		$recipients = [];
		foreach ((array)($doc['notification']['recipient_uids'] ?? []) as $uid) {
			if (is_string($uid) && $uid !== '' && isset($entitled[$uid])) {
				$recipients[] = $uid;
			}
		}

		$input = ['channels' => [
			'notification' => [
				'enabled' => $enables['notification'],
				'recipient_uids' => array_values(array_unique($recipients)),
			],
			'email' => [
				'enabled' => $enables['email'],
				'recipients' => array_values(array_map('strval', (array)($doc['email']['recipients'] ?? []))),
			],
			'slack' => ['enabled' => $enables['slack']],
			'webhook' => ['enabled' => $enables['webhook']],
		]];
		$this->saveSettings($input);
	}

	// ── small helpers ──────────────────────────────────────────────────

	/** @return array<string,mixed> */
	private function currentSettings(): array
	{
		try {
			return $this->settings->load()['settings'];
		} catch (\Throwable) {
			return SettingsService::defaults();
		}
	}

	/** @return array<string,mixed> */
	private function currentRuntime(): array
	{
		$runtime = $this->currentSettings()['runtime'] ?? [];
		return is_array($runtime) ? $runtime : [];
	}

	/** @return array<string,mixed> */
	private function decodeJsonObject(string $value): array
	{
		$decoded = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
		if (!is_array($decoded) || array_is_list($decoded)) {
			throw new ValidationException('Invalid JSON object.', [], 'LCK_INVALID_JSON');
		}
		return $decoded;
	}

	/** @return list<mixed> */
	private function decodeJsonList(string $value, string $field): array
	{
		$decoded = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
		if (!is_array($decoded) || !array_is_list($decoded)) {
			throw new ValidationException('Invalid list.', [$field => 'Invalid list.']);
		}
		return $decoded;
	}

	private function asChannelBool(mixed $value): bool
	{
		return InputCoercion::asBool($value, 'channels');
	}

	private function encode(mixed $value): string
	{
		return (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
	}
}
