<?php

declare(strict_types=1);

namespace OCA\LogCheck\Service;

use OCA\LogCheck\AppInfo\Application;
use OCA\LogCheck\Exception\ConflictException;
use OCA\LogCheck\Exception\ForbiddenException;
use OCA\LogCheck\Exception\ValidationException;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Versioned settings in lck_settings with optimistic concurrency.
 */
final class SettingsService
{
	/** Hard cap on app filter list size (matches mute list budget). */
	public const APP_LIST_MAX = 100;

	/** Max length of a single Nextcloud app id in app_list. */
	public const APP_ID_MAX_LEN = 128;

	/** Hard cap on in-app notification recipient UIDs. */
	public const NOTIFICATION_RECIPIENTS_MAX = 100;

	public function __construct(
		private readonly IDBConnection $db,
		private readonly SecretStore $secretStore,
		private readonly MuteRegexValidator $muteRegexValidator,
		private readonly AccessService $accessService,
		private readonly SsrfGuard $ssrfGuard,
		private readonly AuditService $auditService,
		private readonly LoggerInterface $logger,
		private readonly TopologyGuard $topologyGuard,
		private readonly ChannelTestProof $channelTestProof,
	) {
	}

	/** @return array<string, mixed> */
	public static function defaults(): array
	{
		return [
			'access' => [
				'mode' => 'restricted',
				'app_admins' => [],
			],
			'watch_enabled' => false,
			'min_level' => 3,
			'coalesce_seconds' => 300,
			'digest_window_seconds' => 300,
			'app_mode' => 'all',
			'app_list' => [],
			'mutes' => [
				['type' => 'app', 'value' => Application::APP_ID],
			],
			'include_message_excerpts' => false,
			'excerpt_max_chars' => 200,
			'max_lines_per_digest' => 10000,
			'max_fingerprints_in_payload' => 20,
			'allow_private_webhooks' => false,
			'channels' => [
				'notification' => [
					'enabled' => true,
					'recipient_uids' => [],
				],
				'email' => [
					'enabled' => false,
					'recipients' => [],
					'verified_at' => null,
					'fail_count' => 0,
					'last_error' => null,
				],
				'slack' => [
					'enabled' => false,
					'webhook_url_cipher' => null,
					'fail_count' => 0,
					'last_error' => null,
				],
				'webhook' => [
					'enabled' => false,
					'url_cipher' => null,
					'fail_count' => 0,
					'last_error' => null,
				],
			],
			'runtime' => [
				'last_run_at' => null,
				'last_run_ok' => null,
				'last_error' => null,
				'last_digest_sent_at' => null,
				'catchup_requeues_hour' => 0,
				'catchup_hour_bucket' => null,
				'watcher_node' => null,
			],
		];
	}

	/** @return array{version: int, settings: array<string, mixed>} */
	public function load(): array
	{
		$this->ensureRow();
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('lck_settings')->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		$version = (int)($row['version'] ?? 1);
		$payload = json_decode((string)($row['payload'] ?? '{}'), true);
		if (!is_array($payload)) {
			$payload = [];
		}
		$settings = array_replace_recursive(self::defaults(), $payload);
		// Alias sync
		if (isset($payload['digest_window_seconds']) && !isset($payload['coalesce_seconds'])) {
			$settings['coalesce_seconds'] = (int)$payload['digest_window_seconds'];
		}
		$settings['digest_window_seconds'] = (int)$settings['coalesce_seconds'];
		return ['version' => $version, 'settings' => $settings];
	}

	/** @return array<string, mixed> */
	public function getRawSettings(): array
	{
		return $this->load()['settings'];
	}

	/**
	 * UI DTO: secrets masked; never expose ciphertext or plaintext URLs.
	 *
	 * @return array{version: int, settings: array<string, mixed>}
	 */
	public function toUiDto(): array
	{
		$loaded = $this->load();
		$s = $loaded['settings'];
		$channels = $s['channels'];

		$slackCipher = $channels['slack']['webhook_url_cipher'] ?? null;
		$webhookCipher = $channels['webhook']['url_cipher'] ?? null;
		$slackPlain = is_string($slackCipher) ? $this->secretStore->tryDecrypt($slackCipher) : null;
		$webhookPlain = is_string($webhookCipher) ? $this->secretStore->tryDecrypt($webhookCipher) : null;

		$channels['slack']['webhook_url_masked'] = $this->secretStore->mask($slackPlain);
		$channels['slack']['webhook_url_set'] = is_string($slackCipher) && $slackCipher !== '';
		unset($channels['slack']['webhook_url_cipher']);

		$channels['webhook']['url_masked'] = $this->secretStore->mask($webhookPlain);
		$channels['webhook']['url_set'] = is_string($webhookCipher) && $webhookCipher !== '';
		unset($channels['webhook']['url_cipher']);

		$s['channels'] = $channels;
		$s['settings_version'] = $loaded['version'];
		$s['secrets_readable'] = !($slackCipher && $slackPlain === null) && !($webhookCipher && $webhookPlain === null);

		// Never expose topology fingerprint (hostname-derived) to the browser/API.
		if (isset($s['runtime']) && is_array($s['runtime'])) {
			unset($s['runtime']['watcher_node']);
		}

		return ['version' => $loaded['version'], 'settings' => $s];
	}

	/**
	 * @param array<string, mixed> $input
	 * @param list<string> $preVerifiedChannels Channel names already proven in this request (turn-on after successful test)
	 * @return array{version: int, settings: array<string, mixed>}
	 */
	public function save(array $input, int $expectedVersion, string $actorUid, bool $isNcAdmin, array $preVerifiedChannels = []): array
	{
		$loaded = $this->load();
		if ($loaded['version'] !== $expectedVersion) {
			throw new ConflictException();
		}
		$current = $loaded['settings'];
		$merged = $this->mergeAndValidate($current, $input, $actorUid, $isNcAdmin, $preVerifiedChannels);
		$newVersion = $expectedVersion + 1;
		$now = time();
		$json = json_encode($merged, JSON_THROW_ON_ERROR);

		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->update('lck_settings')
				->set('version', $qb->createNamedParameter($newVersion))
				->set('payload', $qb->createNamedParameter($json))
				->set('updated_at', $qb->createNamedParameter($now))
				->where($qb->expr()->eq('version', $qb->createNamedParameter($expectedVersion)));
			$affected = $qb->executeStatement();
			if ($affected !== 1) {
				$this->db->rollBack();
				throw new ConflictException();
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}

		$this->emitAudits($current, $merged, $actorUid);
		return ['version' => $newVersion, 'settings' => $merged];
	}

	/**
	 * Persist runtime fields without bumping settings_version (job-owned).
	 * Compare-and-swap on version so a concurrent admin save is not clobbered.
	 *
	 * @param array<string, mixed> $runtimePatch
	 */
	public function patchRuntime(array $runtimePatch): void
	{
		// More attempts + backoff under admin save storms (LCK-03).
		$maxAttempts = 16;
		for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
			$loaded = $this->load();
			$version = $loaded['version'];
			$settings = $loaded['settings'];
			$runtime = is_array($settings['runtime'] ?? null) ? $settings['runtime'] : [];
			$settings['runtime'] = array_merge($runtime, $runtimePatch);
			$json = json_encode($settings, JSON_THROW_ON_ERROR);
			$qb = $this->db->getQueryBuilder();
			$qb->update('lck_settings')
				->set('payload', $qb->createNamedParameter($json))
				->set('updated_at', $qb->createNamedParameter(time()))
				->where($qb->expr()->eq('version', $qb->createNamedParameter($version)));
			if ($qb->executeStatement() === 1) {
				return;
			}
			// Contended with admin save — reload and retry with short exponential backoff.
			usleep(min(80000, 5000 * (1 << min($attempt, 4))));
		}
		// Zeus SF: never fail silently forever — operators need a signal.
		$this->logger->warning('HealthCheck patchRuntime gave up after contended settings writes', [
			'app' => 'logcheck',
			'attempts' => $maxAttempts,
		]);
	}

	public function ensureRow(): void
	{
		if (!$this->db->tableExists('lck_settings')) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'c'))->from('lck_settings');
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		$count = (int)($row['c'] ?? $row['count'] ?? 0);
		if ($count > 0) {
			return;
		}
		$json = json_encode(self::defaults(), JSON_THROW_ON_ERROR);
		$ins = $this->db->getQueryBuilder();
		$ins->insert('lck_settings')
			->values([
				'version' => $ins->createNamedParameter(1),
				'payload' => $ins->createNamedParameter($json),
				'updated_at' => $ins->createNamedParameter(time()),
			])
			->executeStatement();
	}

	/**
	 * @param array<string, mixed> $current
	 * @param array<string, mixed> $input
	 * @param list<string> $preVerifiedChannels
	 * @return array<string, mixed>
	 */
	private function mergeAndValidate(array $current, array $input, string $actorUid, bool $isNcAdmin, array $preVerifiedChannels = []): array
	{
		$out = $current;

		if (isset($input['access']) && is_array($input['access'])) {
			if (!$isNcAdmin) {
				throw new ForbiddenException('Only Nextcloud admins can change who can open HealthCheck.');
			}
			$out['access'] = $this->accessService->normalizeAccess($input['access']);
		}

		if (array_key_exists('watch_enabled', $input)) {
			$wantOn = (bool)$input['watch_enabled'];
			$runtime = is_array($out['runtime'] ?? null) ? $out['runtime'] : [];
			if ($wantOn) {
				if ($this->topologyGuard->isMismatch($runtime)) {
					throw new ValidationException(
						'HealthCheck noticed a different server. Multi-server setups need one shared log file.',
						['watch_enabled' => 'Not supported on this server layout.'],
						'LCK_UNSUPPORTED_TOPOLOGY'
					);
				}
				// Pin this node immediately (not only after first successful run) so other
				// app servers hit Can't watch before sharing the cursor (LCK-02).
				$runtime['watcher_node'] = $this->topologyGuard->currentNodeId();
			} else {
				// Clear pin on disable so a deliberate host move can re-enable cleanly.
				$runtime['watcher_node'] = null;
			}
			$out['runtime'] = $runtime;
			$out['watch_enabled'] = $wantOn;
		}

		if (isset($input['min_level'])) {
			$level = (int)$input['min_level'];
			if ($level < 0 || $level > 4) {
				throw new ValidationException('Invalid level.', ['min_level' => 'Invalid level.']);
			}
			$out['min_level'] = $level;
		}

		if (isset($input['coalesce_seconds']) || isset($input['digest_window_seconds'])) {
			$coalesce = (int)($input['coalesce_seconds'] ?? $input['digest_window_seconds']);
			// UI chips are 300 / 900 / 3600 only — reject anything else (Momos: no silent clamp of arbitrary API values).
			if (!in_array($coalesce, [300, 900, 3600], true)) {
				throw new ValidationException('Invalid alert pace.', ['coalesce_seconds' => 'Invalid alert pace.']);
			}
			$out['coalesce_seconds'] = $coalesce;
			$out['digest_window_seconds'] = $coalesce;
		}

		if (isset($input['app_mode'])) {
			$mode = (string)$input['app_mode'];
			if (!in_array($mode, ['all', 'allow', 'deny'], true)) {
				throw new ValidationException('Invalid app filter.', ['app_mode' => 'Invalid app filter.']);
			}
			$out['app_mode'] = $mode;
		}
		if (isset($input['app_list']) && is_array($input['app_list'])) {
			if (count($input['app_list']) > self::APP_LIST_MAX) {
				throw new ValidationException('Too many apps in the filter list.', ['app_list' => 'Too many apps in the filter list.']);
			}
			$normalized = [];
			foreach ($input['app_list'] as $rawId) {
				$id = trim((string)$rawId);
				if ($id === '') {
					continue;
				}
				if (strlen($id) > self::APP_ID_MAX_LEN) {
					throw new ValidationException('App id is too long.', ['app_list' => 'App id is too long.']);
				}
				$normalized[] = $id;
			}
			$out['app_list'] = array_values(array_unique($normalized));
		}

		if (isset($input['mutes']) && is_array($input['mutes'])) {
			if (count($input['mutes']) > 100) {
				throw new ValidationException('Too many mutes.', ['mutes' => 'Too many mutes.']);
			}
			$mutes = [];
			$hasSelf = false;
			foreach ($input['mutes'] as $mute) {
				if (!is_array($mute)) {
					continue;
				}
				$type = (string)($mute['type'] ?? '');
				$value = (string)($mute['value'] ?? '');
				if ($type === 'app') {
					if ($value === '') {
						continue;
					}
					if ($value === Application::APP_ID) {
						$hasSelf = true;
					}
					$mutes[] = ['type' => 'app', 'value' => $value];
				} elseif ($type === 'regex') {
					$this->muteRegexValidator->assertSafe($value);
					$flags = (string)($mute['flags'] ?? 'i');
					$mutes[] = ['type' => 'regex', 'value' => $value, 'flags' => $flags];
				}
			}
			if (!$hasSelf) {
				array_unshift($mutes, ['type' => 'app', 'value' => Application::APP_ID]);
			}
			$out['mutes'] = $mutes;
		}

		if (array_key_exists('include_message_excerpts', $input)) {
			if (!$isNcAdmin) {
				throw new ForbiddenException('Only Nextcloud admins can change log excerpt settings.');
			}
			$want = (bool)$input['include_message_excerpts'];
			if ($want && strtoupper(trim((string)($input['excerpt_confirm'] ?? ''))) !== 'CONFIRM') {
				throw new ValidationException(
					'Please confirm you understand the privacy risk.',
					['include_message_excerpts' => 'Confirmation required.']
				);
			}
			$out['include_message_excerpts'] = $want;
		}

		if (array_key_exists('allow_private_webhooks', $input)) {
			if (!$isNcAdmin) {
				throw new ForbiddenException('Only Nextcloud admins can allow private webhook addresses.');
			}
			$out['allow_private_webhooks'] = (bool)$input['allow_private_webhooks'];
		}

		if (isset($input['channels']) && is_array($input['channels'])) {
			$out['channels'] = $this->mergeChannels(
				$current['channels'],
				$input['channels'],
				(bool)$out['allow_private_webhooks'],
				$actorUid,
				$preVerifiedChannels
			);
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $current
	 * @param array<string, mixed> $input
	 * @param list<string> $preVerifiedChannels
	 * @return array<string, mixed>
	 */
	private function mergeChannels(
		array $current,
		array $input,
		bool $allowPrivate,
		string $actorUid,
		array $preVerifiedChannels,
	): array {
		$out = $current;
		$pre = array_fill_keys($preVerifiedChannels, true);

		if (isset($input['notification']) && is_array($input['notification'])) {
			$n = $input['notification'];
			$out['notification']['enabled'] = !empty($n['enabled']);
			if (isset($n['recipient_uids']) && is_array($n['recipient_uids'])) {
				if (count($n['recipient_uids']) > self::NOTIFICATION_RECIPIENTS_MAX) {
					throw new ValidationException(
						'Too many notification recipients.',
						['channels.notification.recipient_uids' => 'Too many notification recipients.']
					);
				}
				$entitled = array_fill_keys($this->accessService->entitledUids(), true);
				$uids = [];
				foreach ($n['recipient_uids'] as $uid) {
					if (!is_string($uid) || $uid === '') {
						continue;
					}
					if (!isset($entitled[$uid])) {
						throw new ValidationException(
							'Notification recipients must be HealthCheck operators.',
							['channels.notification.recipient_uids' => 'Only entitled operators can receive notifications.'],
							'LCK_VALIDATION'
						);
					}
					$uids[] = $uid;
				}
				$out['notification']['recipient_uids'] = array_values(array_unique($uids));
			}
		}

		if (isset($input['email']) && is_array($input['email'])) {
			$e = $input['email'];
			$wasEnabled = !empty($current['email']['enabled']);
			$prevRecipients = array_values(array_map('strval', $current['email']['recipients'] ?? []));
			sort($prevRecipients);
			if (isset($e['recipients']) && is_array($e['recipients'])) {
				$recipients = [];
				foreach ($e['recipients'] as $r) {
					if (!is_string($r)) {
						continue;
					}
					$r = trim($r);
					if ($r !== '' && filter_var($r, FILTER_VALIDATE_EMAIL)) {
						$recipients[] = $r;
					}
				}
				if (count($recipients) > 20) {
					throw new ValidationException('Too many email recipients.', ['channels.email.recipients' => 'Too many recipients.']);
				}
				$out['email']['recipients'] = array_values(array_unique($recipients));
			}
			$nextRecipients = array_values(array_map('strval', $out['email']['recipients'] ?? []));
			sort($nextRecipients);
			$recipientsChanged = $prevRecipients !== $nextRecipients;
			if ($recipientsChanged) {
				$this->channelTestProof->invalidateChannel('email');
			}
			$wantEnabled = !empty($e['enabled']);
			$out['email']['enabled'] = $wantEnabled;
			if ($wantEnabled) {
				$this->assertEmailChannelMayEnable(
					$nextRecipients,
					$recipientsChanged,
					$wasEnabled,
					$actorUid,
					$pre
				);
			}
		}

		if (isset($input['slack']) && is_array($input['slack'])) {
			$s = $input['slack'];
			$urlChanging = false;
			$newUrl = null;
			if (!empty($s['clear_url'])) {
				$out['slack']['webhook_url_cipher'] = null;
				$urlChanging = true;
			} elseif (isset($s['webhook_url']) && is_string($s['webhook_url']) && $s['webhook_url'] !== '') {
				$this->assertUrlLength($s['webhook_url']);
				$this->ssrfGuard->assertAllowed($s['webhook_url'], $allowPrivate);
				$out['slack']['webhook_url_cipher'] = $this->secretStore->encrypt($s['webhook_url']);
				$urlChanging = true;
				$newUrl = $s['webhook_url'];
			}
			// H1: never keep verified_at across a cipher change (even when disabled).
			if ($urlChanging) {
				$this->channelTestProof->invalidateChannel('slack');
			}
			$wantEnabled = !empty($s['enabled']);
			$out['slack']['enabled'] = $wantEnabled;
			if ($wantEnabled) {
				$this->assertOutboundChannelMayEnable(
					'slack',
					$out['slack']['webhook_url_cipher'] ?? null,
					$urlChanging,
					$newUrl,
					$actorUid,
					$pre
				);
			}
		}

		if (isset($input['webhook']) && is_array($input['webhook'])) {
			$w = $input['webhook'];
			if (isset($w['headers'])) {
				throw new ValidationException('Custom headers are not supported.', ['channels.webhook.headers' => 'Custom headers are not supported.']);
			}
			$urlChanging = false;
			$newUrl = null;
			if (!empty($w['clear_url'])) {
				$out['webhook']['url_cipher'] = null;
				$urlChanging = true;
			} elseif (isset($w['url']) && is_string($w['url']) && $w['url'] !== '') {
				$this->assertUrlLength($w['url']);
				$this->ssrfGuard->assertAllowed($w['url'], $allowPrivate);
				$out['webhook']['url_cipher'] = $this->secretStore->encrypt($w['url']);
				$urlChanging = true;
				$newUrl = $w['url'];
			}
			if ($urlChanging) {
				$this->channelTestProof->invalidateChannel('webhook');
			}
			$wantEnabled = !empty($w['enabled']);
			$out['webhook']['enabled'] = $wantEnabled;
			if ($wantEnabled) {
				$this->assertOutboundChannelMayEnable(
					'webhook',
					$out['webhook']['url_cipher'] ?? null,
					$urlChanging,
					$newUrl,
					$actorUid,
					$pre
				);
			}
		}

		return $out;
	}

	/**
	 * Email may enable only after a successful test (or same-request turn-on preVerified).
	 * Recipients changes clear durable verification so the new list must be tested.
	 *
	 * @param list<string> $recipients
	 * @param array<string, true> $pre
	 */
	private function assertEmailChannelMayEnable(
		array $recipients,
		bool $recipientsChanged,
		bool $wasEnabled,
		string $actorUid,
		array $pre,
	): void {
		if (!empty($pre['email'])) {
			return;
		}
		$fingerprint = self::emailRecipientsFingerprint($recipients);
		if ($fingerprint !== '' && $this->channelTestProof->consumeUrl($actorUid, 'email', $fingerprint)) {
			return;
		}
		if (!$recipientsChanged && $this->channelTestProof->isStateVerified('email')) {
			return;
		}
		if (!$recipientsChanged && $wasEnabled) {
			return;
		}
		throw new ValidationException(
			'Send a successful test before turning this channel on.',
			['channels.email' => 'Send a test first, then save.'],
			'LCK_CHANNEL_NOT_TESTED'
		);
	}

	/** @param list<string> $recipients */
	public static function emailRecipientsFingerprint(array $recipients): string
	{
		$normalized = array_values(array_unique(array_filter(array_map(
			static fn (string $r): string => strtolower(trim($r)),
			$recipients
		), static fn (string $r): bool => $r !== '')));
		sort($normalized);
		return implode(',', $normalized);
	}

	/**
	 * Slack/webhook may enable only after a successful test (state verified, or
	 * same-request preVerified from turn-on, or one-shot URL proof from Test).
	 *
	 * @param array<string, true> $pre
	 */
	private function assertOutboundChannelMayEnable(
		string $channel,
		mixed $cipher,
		bool $urlChanging,
		?string $newUrl,
		string $actorUid,
		array $pre,
	): void {
		if (!is_string($cipher) || $cipher === '') {
			throw new ValidationException(
				'Send a successful test before turning this channel on.',
				['channels.' . $channel => 'Add a webhook URL and send a test first.'],
				'LCK_CHANNEL_NOT_TESTED'
			);
		}
		if (!empty($pre[$channel])) {
			return;
		}
		if ($urlChanging) {
			if ($newUrl === null || !$this->channelTestProof->consumeUrl($actorUid, $channel, $newUrl)) {
				throw new ValidationException(
					'Send a successful test before turning this channel on.',
					['channels.' . $channel => 'Send a test with this URL, then save.'],
					'LCK_CHANNEL_NOT_TESTED'
				);
			}
			return;
		}
		if (!$this->channelTestProof->isStateVerified($channel)) {
			throw new ValidationException(
				'Send a successful test before turning this channel on.',
				['channels.' . $channel => 'Send a test first, then save.'],
				'LCK_CHANNEL_NOT_TESTED'
			);
		}
	}

	private function assertUrlLength(string $url): void
	{
		if (strlen($url) > 2048) {
			throw new ValidationException('Webhook URL is not allowed.', ['url' => 'Webhook URL is too long.'], 'LCK_INVALID_URL');
		}
	}

	/**
	 * @param array<string, mixed> $before
	 * @param array<string, mixed> $after
	 */
	private function emitAudits(array $before, array $after, string $actorUid): void
	{
		if ((bool)$before['watch_enabled'] !== (bool)$after['watch_enabled']) {
			$this->auditService->log($actorUid, 'watch_toggled', ['enabled' => (bool)$after['watch_enabled']]);
		}
		if ((bool)$before['include_message_excerpts'] !== (bool)$after['include_message_excerpts']) {
			$this->auditService->log($actorUid, 'excerpts_toggled', ['enabled' => (bool)$after['include_message_excerpts']]);
		}
		if ((bool)$before['allow_private_webhooks'] !== (bool)$after['allow_private_webhooks']) {
			$this->auditService->log($actorUid, 'private_webhooks_toggled', ['enabled' => (bool)$after['allow_private_webhooks']]);
		}
		$beforeAdmins = $before['access']['app_admins'] ?? [];
		$afterAdmins = $after['access']['app_admins'] ?? [];
		if ($beforeAdmins !== $afterAdmins) {
			$this->auditService->log($actorUid, 'app_admins_changed', ['count' => count($afterAdmins)]);
		}
		$beforeSlack = $before['channels']['slack']['webhook_url_cipher'] ?? null;
		$afterSlack = $after['channels']['slack']['webhook_url_cipher'] ?? null;
		if ($beforeSlack !== $afterSlack) {
			$this->auditService->log($actorUid, 'slack_url_changed', ['set' => $afterSlack !== null]);
		}
		$beforeWh = $before['channels']['webhook']['url_cipher'] ?? null;
		$afterWh = $after['channels']['webhook']['url_cipher'] ?? null;
		if ($beforeWh !== $afterWh) {
			$this->auditService->log($actorUid, 'webhook_url_changed', ['set' => $afterWh !== null]);
		}
		if (($before['mutes'] ?? null) !== ($after['mutes'] ?? null)) {
			$this->auditService->log($actorUid, 'mutes_changed', ['count' => count($after['mutes'] ?? [])]);
		}
		$beforeRecipients = $before['channels']['email']['recipients'] ?? [];
		$afterRecipients = $after['channels']['email']['recipients'] ?? [];
		if ($beforeRecipients !== $afterRecipients) {
			// Count only — never log addresses (PII).
			$this->auditService->log($actorUid, 'email_recipients_changed', [
				'count' => count(array_values(array_filter($afterRecipients, 'is_string'))),
			]);
		}
	}
}
