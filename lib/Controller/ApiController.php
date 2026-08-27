<?php

declare(strict_types=1);

namespace OCA\LogCheck\Controller;

use OCA\LogCheck\AppInfo\Application;
use OCA\LogCheck\Exception\ConflictException;
use OCA\LogCheck\Exception\ForbiddenException;
use OCA\LogCheck\Exception\UnsupportedBackendException;
use OCA\LogCheck\Exception\ValidationException;
use OCA\LogCheck\Service\AccessService;
use OCA\LogCheck\Service\AuditService;
use OCA\LogCheck\Service\ChannelDispatcher;
use OCA\LogCheck\Service\ChannelStateStore;
use OCA\LogCheck\Service\ChannelTestProof;
use OCA\LogCheck\Service\CursorStore;
use OCA\LogCheck\Service\LeaseService;
use OCA\LogCheck\Service\LogBackendService;
use OCA\LogCheck\Service\LogFileService;
use OCA\LogCheck\Service\LogLineLevel;
use OCA\LogCheck\Service\PayloadBuilder;
use OCA\LogCheck\Service\SettingsService;
use OCA\LogCheck\Service\StatusService;
use OCA\LogCheck\Service\WatchRunner;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;

class ApiController extends Controller
{
	private const TEST_RATE_SECONDS = 30;
	private const RUN_RATE_SECONDS = 10;
	private const LOG_READ_RATE_SECONDS = 2;
	private const LOG_DOWNLOAD_RATE_SECONDS = 30;
	private const LOG_MUTATE_RATE_SECONDS = 15;
	/** Friendly client errors only — never echo raw exception / transport detail. */
	private const SAFE_HTTP_ERROR = 'Webhook failed. Check the URL and try again.';
	private const SAFE_MAIL_ERROR = 'Email could not be sent. Check mail settings.';
	private const SAFE_RUN_ERROR = 'Could not check the log right now. Try again in a moment.';

	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IUserManager $userManager,
		private readonly AccessService $accessService,
		private readonly StatusService $statusService,
		private readonly SettingsService $settingsService,
		private readonly ChannelDispatcher $channelDispatcher,
		private readonly ChannelStateStore $channelStateStore,
		private readonly PayloadBuilder $payloadBuilder,
		private readonly LogBackendService $logBackendService,
		private readonly CursorStore $cursorStore,
		private readonly LeaseService $leaseService,
		private readonly WatchRunner $watchRunner,
		private readonly ICacheFactory $cacheFactory,
		private readonly ChannelTestProof $channelTestProof,
		private readonly IL10N $l10n,
		private readonly LogFileService $logFileService,
		private readonly AuditService $auditService,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * StatusService returns English msgids; localize for the active user before JSON/UI.
	 *
	 * @return array<string, mixed>
	 */
	private function localizedStatus(): array
	{
		$status = $this->statusService->getStatus();
		$status['label'] = $this->l10n->t((string)($status['label'] ?? 'Off'));
		if (is_string($status['error'] ?? null) && $status['error'] !== '') {
			$status['error'] = $this->l10n->t($status['error']);
		}
		if (isset($status['channels']) && is_array($status['channels'])) {
			foreach ($status['channels'] as $name => $ch) {
				if (!is_array($ch)) {
					continue;
				}
				$err = $ch['last_error'] ?? null;
				if (is_string($err) && $err !== '') {
					$status['channels'][$name]['last_error'] = $this->l10n->t($err);
				}
			}
		}
		return $status;
	}

	private function tSafe(string $msgid): string
	{
		return $this->l10n->t($msgid);
	}

	#[NoAdminRequired]
	public function getStatus(): JSONResponse
	{
		return new JSONResponse($this->localizedStatus());
	}

	#[NoAdminRequired]
	public function getSettings(): JSONResponse
	{
		return new JSONResponse($this->settingsService->toUiDto());
	}

	#[NoAdminRequired]
	public function saveSettings(): JSONResponse
	{
		try {
			$user = $this->userSession->getUser();
			$uid = $user?->getUID() ?? '';
			$body = $this->requestBody();
			$expected = (int)($body['expected_version'] ?? $body['settings_version'] ?? -1);
			$input = is_array($body['settings'] ?? null) ? $body['settings'] : $body;
			unset($input['expected_version'], $input['settings_version'], $input['runtime']);
			$saved = $this->settingsService->save(
				$input,
				$expected,
				$uid,
				$this->accessService->isNcAdmin($uid)
			);
			return new JSONResponse([
				'version' => $saved['version'],
				'settings' => $this->settingsService->toUiDto()['settings'],
			]);
		} catch (ConflictException $e) {
			return new JSONResponse(['error' => 'LCK_CONFLICT', 'message' => $e->getMessage()], Http::STATUS_CONFLICT);
		} catch (ForbiddenException $e) {
			return new JSONResponse(['error' => 'LCK_FORBIDDEN', 'message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (ValidationException $e) {
			return new JSONResponse([
				'error' => $e->getErrorCode(),
				'message' => $e->getMessage(),
				'fields' => $e->getFieldErrors(),
			], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	/**
	 * Composite setup: test all provided channels first, then ONE save that enables watch.
	 * Never leaves watch on after a failed test; never enables Slack/webhook without a successful test.
	 */
	#[NoAdminRequired]
	public function turnOnAlerts(): JSONResponse
	{
		$user = $this->userSession->getUser();
		$uid = $user?->getUID() ?? '';
		try {
			$this->consumeTestRate($uid);
			$this->logBackendService->assertFileBackend();

			$body = $this->requestBody();
			$expected = (int)($body['expected_version'] ?? -1);
			$email = trim((string)($body['email'] ?? ''));
			if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
				throw new ValidationException('Please enter a valid email address.', ['email' => 'Please enter a valid email address.']);
			}

			$current = $this->settingsService->getRawSettings();
			$allowPrivate = !empty($current['allow_private_webhooks']);
			$slackUrl = trim((string)($body['slack_url'] ?? ''));
			$webhookUrl = trim((string)($body['webhook_url'] ?? ''));
			$this->assertOutboundUrlLength($slackUrl);
			$this->assertOutboundUrlLength($webhookUrl);

			$ephemeral = $current;
			$ephemeral['channels']['email']['enabled'] = true;
			$ephemeral['channels']['email']['recipients'] = [$email];
			$ephemeral['channels']['notification']['enabled'] = true;
			if (isset($body['min_level'])) {
				$ephemeral['min_level'] = (int)$body['min_level'];
			}
			if (isset($body['coalesce_seconds'])) {
				$ephemeral['coalesce_seconds'] = (int)$body['coalesce_seconds'];
				$ephemeral['digest_window_seconds'] = (int)$body['coalesce_seconds'];
			}

			$emailPayload = $this->payloadBuilder->buildTestPayload('email', $ephemeral);
			$this->channelDispatcher->send('email', $emailPayload, $ephemeral);

			if ($slackUrl !== '') {
				$slackPayload = $this->payloadBuilder->buildTestPayload('slack', $ephemeral);
				$this->channelDispatcher->sendPlainUrl('slack', $slackPayload, $slackUrl, $allowPrivate);
			}
			if ($webhookUrl !== '') {
				$whPayload = $this->payloadBuilder->buildTestPayload('webhook', $ephemeral);
				$this->channelDispatcher->sendPlainUrl('webhook', $whPayload, $webhookUrl, $allowPrivate);
			}

			$input = [
				'watch_enabled' => true,
				'channels' => [
					'email' => [
						'enabled' => true,
						'recipients' => [$email],
					],
					'notification' => [
						'enabled' => true,
						'recipient_uids' => $current['channels']['notification']['recipient_uids'] ?? [],
					],
				],
			];
			if (isset($body['min_level'])) {
				$input['min_level'] = (int)$body['min_level'];
			}
			if (isset($body['coalesce_seconds'])) {
				$input['coalesce_seconds'] = (int)$body['coalesce_seconds'];
			}
			if ($slackUrl !== '') {
				$input['channels']['slack'] = [
					'enabled' => true,
					'webhook_url' => $slackUrl,
				];
			}
			if ($webhookUrl !== '') {
				$input['channels']['webhook'] = [
					'enabled' => true,
					'url' => $webhookUrl,
				];
			}

			$wasWatching = !empty($current['watch_enabled']);
			$preVerified = ['email'];
			if ($slackUrl !== '') {
				$preVerified[] = 'slack';
			}
			if ($webhookUrl !== '') {
				$preVerified[] = 'webhook';
			}
			$saved = $this->settingsService->save(
				$input,
				$expected,
				$uid,
				$this->accessService->isNcAdmin($uid),
				$preVerified
			);
			// AC-4: seed EOF only on first enable — never rewind a live cursor (alert loss).
			if (!$wasWatching) {
				$this->initializeCursorAtEofUnderLease();
			}
			$this->channelStateStore->recordSuccess('email');
			if ($slackUrl !== '') {
				$this->channelStateStore->recordSuccess('slack');
			}
			if ($webhookUrl !== '') {
				$this->channelStateStore->recordSuccess('webhook');
			}

			return new JSONResponse([
				'ok' => true,
				'version' => $saved['version'],
				'status' => $this->localizedStatus(),
			]);
		} catch (UnsupportedBackendException $e) {
			return new JSONResponse(['error' => 'LCK_UNSUPPORTED_BACKEND', 'message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (ConflictException $e) {
			return new JSONResponse(['error' => 'LCK_CONFLICT', 'message' => $e->getMessage()], Http::STATUS_CONFLICT);
		} catch (ValidationException $e) {
			return new JSONResponse([
				'error' => $e->getErrorCode(),
				'message' => $e->getMessage(),
				'fields' => $e->getFieldErrors(),
			], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (ForbiddenException $e) {
			return new JSONResponse(['error' => 'LCK_FORBIDDEN', 'message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (\Throwable $e) {
			$code = $this->classifyOutboundFailure($e);
			return new JSONResponse([
				'error' => $code,
				'message' => $code === 'LCK_HTTP_FAILED' ? $this->tSafe(self::SAFE_HTTP_ERROR) : $this->tSafe(self::SAFE_MAIL_ERROR),
			], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	public function testChannel(string $channel): JSONResponse
	{
		try {
			$user = $this->userSession->getUser();
			$uid = $user?->getUID() ?? '';
			$this->consumeTestRate($uid);
			$allowed = ['email', 'slack', 'webhook', 'notification'];
			if (!in_array($channel, $allowed, true)) {
				return new JSONResponse(['error' => 'LCK_VALIDATION', 'message' => $this->tSafe('Unknown channel')], Http::STATUS_BAD_REQUEST);
			}
			$settings = $this->settingsService->getRawSettings();
			$payload = $this->payloadBuilder->buildTestPayload($channel, $settings);
			$body = $this->requestBody();
			$allowPrivate = !empty($settings['allow_private_webhooks']);

			if ($channel === 'slack' || $channel === 'webhook') {
				$urlKey = $channel === 'slack' ? 'webhook_url' : 'url';
				$plain = trim((string)($body[$urlKey] ?? $body['url'] ?? ''));
				if ($plain !== '') {
					$this->assertOutboundUrlLength($plain);
					$this->channelDispatcher->sendPlainUrl($channel, $payload, $plain, $allowPrivate);
					$this->channelTestProof->markUrl($uid, $channel, $plain);
					// Do not mark state verified until URL is persisted — proof is the one-shot token.
					$this->auditService->log($uid, 'channel_tested', ['channel' => $channel, 'ephemeral_url' => 1]);
					return new JSONResponse(['ok' => true, 'url_tested' => true]);
				}
			}

			if ($channel === 'email') {
				$rawRecipients = $body['recipients'] ?? null;
				if (is_array($rawRecipients)) {
					$recipients = [];
					foreach ($rawRecipients as $r) {
						if (!is_string($r)) {
							continue;
						}
						$r = trim($r);
						if ($r !== '' && filter_var($r, FILTER_VALIDATE_EMAIL)) {
							$recipients[] = $r;
						}
					}
					if ($recipients === []) {
						throw new ValidationException(
							'Please enter a valid email address.',
							['email' => 'Please enter a valid email address.']
						);
					}
					$settings['channels']['email']['recipients'] = array_values(array_unique($recipients));
					$payload = $this->payloadBuilder->buildTestPayload($channel, $settings);
					$this->channelDispatcher->send($channel, $payload, $settings);
					$fp = SettingsService::emailRecipientsFingerprint($settings['channels']['email']['recipients']);
					$this->channelTestProof->markUrl($uid, 'email', $fp);
					$this->channelStateStore->recordSuccess('email');
					$this->auditService->log($uid, 'channel_tested', ['channel' => 'email', 'ephemeral_recipients' => 1]);
					return new JSONResponse(['ok' => true, 'recipients_tested' => true]);
				}
			}

			$this->channelDispatcher->send($channel, $payload, $settings);
			$this->channelStateStore->recordSuccess($channel);
			$this->auditService->log($uid, 'channel_tested', ['channel' => $channel]);
			return new JSONResponse(['ok' => true]);
		} catch (ValidationException $e) {
			return new JSONResponse(['error' => $e->getErrorCode(), 'message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (\Throwable $e) {
			$this->channelStateStore->recordFailure($channel, $e->getMessage());
			$code = $this->classifyOutboundFailure($e);
			return new JSONResponse([
				'error' => $code,
				'message' => $code === 'LCK_HTTP_FAILED' ? $this->tSafe(self::SAFE_HTTP_ERROR) : $this->tSafe(self::SAFE_MAIL_ERROR),
			], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	public function reenableChannel(string $channel): JSONResponse
	{
		// Momos H-RE1: never clear auto-disable before a successful test.
		// testChannel → recordSuccess clears fail_count/disabled_at; a failed test must leave the channel disabled.
		return $this->testChannel($channel);
	}

	#[NoAdminRequired]
	public function runNow(): JSONResponse
	{
		try {
			$user = $this->userSession->getUser();
			$uid = $user?->getUID() ?? '';
			$this->consumeRate($uid, 'run:', self::RUN_RATE_SECONDS);
			$result = $this->watchRunner->run();
			$this->auditService->log($uid, 'check_run', ['ok' => !empty($result['ok']) ? 1 : 0]);
			return new JSONResponse([
				'ok' => !empty($result['ok']),
				'status' => $this->localizedStatus(),
				'error' => empty($result['ok']) ? $this->tSafe(self::SAFE_RUN_ERROR) : null,
			]);
		} catch (ValidationException $e) {
			return new JSONResponse([
				'error' => $e->getErrorCode(),
				'message' => $e->getMessage(),
			], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[NoAdminRequired]
	public function searchDirectory(string $search = ''): JSONResponse
	{
		$user = $this->userSession->getUser();
		$uid = $user?->getUID() ?? '';
		// Directory search exists only to add App Admins — NC system admin only (Momos).
		if (!$this->accessService->isNcAdmin($uid)) {
			return new JSONResponse(['error' => 'LCK_FORBIDDEN', 'message' => $this->tSafe('Not authorized.')], Http::STATUS_FORBIDDEN);
		}
		$q = trim($search !== '' ? $search : (string)$this->request->getParam('search', ''));
		if (mb_strlen($q) < 2) {
			return new JSONResponse(['users' => []]);
		}
		$users = [];
		foreach ($this->userManager->searchDisplayName($q, 20) as $user) {
			$users[] = [
				'uid' => $user->getUID(),
				'displayName' => $user->getDisplayName(),
			];
		}
		if ($users === [] && method_exists($this->userManager, 'search')) {
			/** @psalm-suppress UndefinedInterfaceMethod */
			foreach ($this->userManager->search($q, 20) as $user) {
				$users[] = [
					'uid' => $user->getUID(),
					'displayName' => $user->getDisplayName(),
				];
			}
		}
		return new JSONResponse(['users' => $users]);
	}

	#[NoAdminRequired]
	public function getLogMeta(): JSONResponse
	{
		try {
			$user = $this->userSession->getUser();
			$uid = $user?->getUID() ?? '';
			$this->consumeRate($uid, 'log-meta:', self::LOG_READ_RATE_SECONDS);
			$isNcAdmin = $this->accessService->isNcAdmin($uid);
			return new JSONResponse($this->logFileService->meta($isNcAdmin));
		} catch (ValidationException $e) {
			return new JSONResponse([
				'error' => $e->getErrorCode(),
				'message' => $this->tSafe($e->getMessage()),
				'fields' => $e->getFieldErrors(),
			], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (ForbiddenException $e) {
			return new JSONResponse(['error' => 'LCK_FORBIDDEN', 'message' => $this->tSafe('Not authorized.')], Http::STATUS_FORBIDDEN);
		}
	}

	#[NoAdminRequired]
	public function listLogFiles(): JSONResponse
	{
		try {
			$user = $this->userSession->getUser();
			$uid = $user?->getUID() ?? '';
			$this->consumeRate($uid, 'log-files:', self::LOG_READ_RATE_SECONDS);
			return new JSONResponse($this->logFileService->listFiles());
		} catch (UnsupportedBackendException $e) {
			return new JSONResponse(['error' => 'LCK_UNSUPPORTED_BACKEND', 'message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (ValidationException $e) {
			return new JSONResponse([
				'error' => $e->getErrorCode(),
				'message' => $this->tSafe($e->getMessage()),
				'fields' => $e->getFieldErrors(),
			], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (ForbiddenException $e) {
			return new JSONResponse(['error' => 'LCK_FORBIDDEN', 'message' => $this->tSafe('Not authorized.')], Http::STATUS_FORBIDDEN);
		}
	}

	#[NoAdminRequired]
	public function getLogTail(): JSONResponse
	{
		try {
			$user = $this->userSession->getUser();
			$uid = $user?->getUID() ?? '';
			$this->consumeRate($uid, 'log-tail:', self::LOG_READ_RATE_SECONDS);
			$maxBytes = (int)$this->request->getParam('max_bytes', LogFileService::TAIL_MAX_BYTES);
			$maxLines = (int)$this->request->getParam('max_lines', LogFileService::TAIL_MAX_LINES);
			$file = $this->request->getParam('file');
			$fileId = is_string($file) ? $file : null;
			$viewerMinLevel = LogLineLevel::clampViewerMinLevel((int)$this->request->getParam('viewer_min_level', 0));
			return new JSONResponse($this->logFileService->readTail($maxBytes, $maxLines, $fileId, $viewerMinLevel));
		} catch (UnsupportedBackendException $e) {
			return new JSONResponse(['error' => 'LCK_UNSUPPORTED_BACKEND', 'message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (ValidationException $e) {
			return new JSONResponse([
				'error' => $e->getErrorCode(),
				'message' => $this->tSafe($e->getMessage()),
				'fields' => $e->getFieldErrors(),
			], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (ForbiddenException $e) {
			return new JSONResponse(['error' => 'LCK_FORBIDDEN', 'message' => $this->tSafe('Not authorized.')], Http::STATUS_FORBIDDEN);
		}
	}


	#[NoAdminRequired]
	public function getLogBefore(): JSONResponse
	{
		try {
			$user = $this->userSession->getUser();
			$uid = $user?->getUID() ?? '';
			$this->consumeRate($uid, 'log-before:', self::LOG_READ_RATE_SECONDS);
			$before = (int)$this->request->getParam('before', 0);
			$maxBytes = (int)$this->request->getParam('max_bytes', LogFileService::TAIL_MAX_BYTES);
			$maxLines = (int)$this->request->getParam('max_lines', LogFileService::TAIL_MAX_LINES);
			$file = $this->request->getParam('file');
			$fileId = is_string($file) ? $file : null;
			$viewerMinLevel = LogLineLevel::clampViewerMinLevel((int)$this->request->getParam('viewer_min_level', 0));
			return new JSONResponse($this->logFileService->readBefore($before, $maxBytes, $maxLines, $fileId, $viewerMinLevel));
		} catch (UnsupportedBackendException $e) {
			return new JSONResponse(['error' => 'LCK_UNSUPPORTED_BACKEND', 'message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (ValidationException $e) {
			return new JSONResponse([
				'error' => $e->getErrorCode(),
				'message' => $this->tSafe($e->getMessage()),
				'fields' => $e->getFieldErrors(),
			], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (ForbiddenException $e) {
			return new JSONResponse(['error' => 'LCK_FORBIDDEN', 'message' => $this->tSafe('Not authorized.')], Http::STATUS_FORBIDDEN);
		}
	}

	#[NoAdminRequired]
	public function downloadLog(): StreamResponse|JSONResponse
	{
		try {
			$user = $this->userSession->getUser();
			$uid = $user?->getUID() ?? '';
			$this->consumeRate($uid, 'log-download:', self::LOG_DOWNLOAD_RATE_SECONDS);
			// POST body preferred (CSRF-bound); query `file` kept only as legacy fallback.
			$body = $this->requestBody();
			$file = $body['file'] ?? $this->request->getParam('file');
			$fileId = is_string($file) ? $file : null;
			$meta = $this->logFileService->resolveDownload($fileId, $uid);
			$response = new StreamResponse($meta['path']);
			$safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $meta['name']) ?: 'nextcloud.log';
			$response->addHeader('Content-Disposition', 'attachment; filename="' . $safeName . '"');
			$response->addHeader('Content-Type', 'text/plain; charset=UTF-8');
			$response->addHeader('X-Content-Type-Options', 'nosniff');
			$response->addHeader('Cache-Control', 'no-store');
			$response->addHeader('Content-Length', (string)$meta['size']);
			return $response;
		} catch (UnsupportedBackendException $e) {
			return new JSONResponse(['error' => 'LCK_UNSUPPORTED_BACKEND', 'message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (ValidationException $e) {
			return new JSONResponse([
				'error' => $e->getErrorCode(),
				'message' => $this->tSafe($e->getMessage()),
				'fields' => $e->getFieldErrors(),
			], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (ForbiddenException $e) {
			return new JSONResponse(['error' => 'LCK_FORBIDDEN', 'message' => $this->tSafe('Not authorized.')], Http::STATUS_FORBIDDEN);
		}
	}

	#[NoAdminRequired]
	public function searchLog(): JSONResponse
	{
		try {
			$user = $this->userSession->getUser();
			$uid = $user?->getUID() ?? '';
			$this->consumeRate($uid, 'log-search:', self::LOG_READ_RATE_SECONDS);
			$q = (string)$this->request->getParam('q', '');
			$case = (string)$this->request->getParam('case_sensitive', '0') === '1';
			$maxMatches = (int)$this->request->getParam('max_matches', LogFileService::SEARCH_MAX_MATCHES);
			$scanBytes = (int)$this->request->getParam('scan_bytes', LogFileService::SEARCH_MAX_SCAN_BYTES);
			$file = $this->request->getParam('file');
			$fileId = is_string($file) ? $file : null;
			$beforeRaw = $this->request->getParam('before');
			$beforeOffset = is_numeric($beforeRaw) ? (int)$beforeRaw : null;
			$viewerMinLevel = LogLineLevel::clampViewerMinLevel((int)$this->request->getParam('viewer_min_level', 0));
			return new JSONResponse($this->logFileService->search($q, $case, $maxMatches, $scanBytes, $fileId, $beforeOffset, $viewerMinLevel));
		} catch (UnsupportedBackendException $e) {
			return new JSONResponse(['error' => 'LCK_UNSUPPORTED_BACKEND', 'message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (ValidationException $e) {
			return new JSONResponse([
				'error' => $e->getErrorCode(),
				'message' => $this->tSafe($e->getMessage()),
				'fields' => $e->getFieldErrors(),
			], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (ForbiddenException $e) {
			return new JSONResponse(['error' => 'LCK_FORBIDDEN', 'message' => $this->tSafe('Not authorized.')], Http::STATUS_FORBIDDEN);
		}
	}

	#[NoAdminRequired]
	public function startFreshLog(): JSONResponse
	{
		try {
			$user = $this->userSession->getUser();
			$uid = $user?->getUID() ?? '';
			$this->consumeRate($uid, 'log-mutate:', self::LOG_MUTATE_RATE_SECONDS);
			$body = $this->requestBody();
			$confirm = (string)($body['confirm'] ?? '');
			$result = $this->logFileService->startFresh($uid, $confirm);
			return new JSONResponse($result);
		} catch (UnsupportedBackendException $e) {
			return new JSONResponse(['error' => 'LCK_UNSUPPORTED_BACKEND', 'message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (ValidationException $e) {
			$status = $e->getErrorCode() === 'LCK_BUSY' ? Http::STATUS_CONFLICT : Http::STATUS_UNPROCESSABLE_ENTITY;
			return new JSONResponse([
				'error' => $e->getErrorCode(),
				'message' => $this->tSafe($e->getMessage()),
				'fields' => $e->getFieldErrors(),
			], $status);
		} catch (ForbiddenException $e) {
			return new JSONResponse(['error' => 'LCK_FORBIDDEN', 'message' => $this->tSafe('Not authorized.')], Http::STATUS_FORBIDDEN);
		}
	}

	#[NoAdminRequired]
	public function deleteLog(): JSONResponse
	{
		try {
			$user = $this->userSession->getUser();
			$uid = $user?->getUID() ?? '';
			$this->consumeRate($uid, 'log-mutate:', self::LOG_MUTATE_RATE_SECONDS);
			$body = $this->requestBody();
			$confirm = (string)($body['confirm'] ?? '');
			$result = $this->logFileService->deleteLog($uid, $confirm);
			return new JSONResponse($result);
		} catch (UnsupportedBackendException $e) {
			return new JSONResponse(['error' => 'LCK_UNSUPPORTED_BACKEND', 'message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (ValidationException $e) {
			$status = $e->getErrorCode() === 'LCK_BUSY' ? Http::STATUS_CONFLICT : Http::STATUS_UNPROCESSABLE_ENTITY;
			return new JSONResponse([
				'error' => $e->getErrorCode(),
				'message' => $this->tSafe($e->getMessage()),
				'fields' => $e->getFieldErrors(),
			], $status);
		} catch (ForbiddenException $e) {
			return new JSONResponse(['error' => 'LCK_FORBIDDEN', 'message' => $this->tSafe('Not authorized.')], Http::STATUS_FORBIDDEN);
		}
	}

	#[NoAdminRequired]
	public function deleteLogCopy(): JSONResponse
	{
		try {
			$user = $this->userSession->getUser();
			$uid = $user?->getUID() ?? '';
			$this->consumeRate($uid, 'log-mutate:', self::LOG_MUTATE_RATE_SECONDS);
			$body = $this->requestBody();
			$confirm = (string)($body['confirm'] ?? '');
			$file = (string)($body['file'] ?? '');
			$result = $this->logFileService->deleteCopy($uid, $confirm, $file);
			return new JSONResponse($result);
		} catch (UnsupportedBackendException $e) {
			return new JSONResponse(['error' => 'LCK_UNSUPPORTED_BACKEND', 'message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (ValidationException $e) {
			return new JSONResponse([
				'error' => $e->getErrorCode(),
				'message' => $this->tSafe($e->getMessage()),
				'fields' => $e->getFieldErrors(),
			], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (ForbiddenException $e) {
			return new JSONResponse(['error' => 'LCK_FORBIDDEN', 'message' => $this->tSafe('Not authorized.')], Http::STATUS_FORBIDDEN);
		}
	}

	/**
	 * Seed cursor at EOF under the watch lease so a concurrent job cannot race the write (NN-20).
	 * If the lease is busy, leave cursor null — WatchRunner initializes at EOF on first hold.
	 */
	private function initializeCursorAtEofUnderLease(): void
	{
		$path = $this->logBackendService->resolveLogPath();
		$owner = 'turnon-' . bin2hex(random_bytes(8));
		if (!$this->leaseService->acquire($owner)) {
			return;
		}
		try {
			$this->cursorStore->initializeAtEof($path);
		} finally {
			$this->leaseService->release($owner);
		}
	}

	/**
	 * Consume a rate budget *before* expensive work so failed probes cannot bypass limits.
	 */
	private function consumeTestRate(string $uid): void
	{
		$this->consumeRate($uid, 'test:', self::TEST_RATE_SECONDS);
	}

	private function consumeRate(string $uid, string $prefix, int $ttlSeconds): void
	{
		$cache = $this->cacheFactory->createDistributed('logcheck');
		$key = $prefix . $uid;
		// Prefer atomic add. Without it, refuse rather than race get→set (double outbound).
		if (is_object($cache) && method_exists($cache, 'add')) {
			/** @var callable $add */
			$add = [$cache, 'add'];
			if (!$add($key, '1', $ttlSeconds)) {
				throw new ValidationException('Please wait a moment before trying again.', [], 'LCK_RATE_LIMIT');
			}
			return;
		}
		throw new ValidationException('Please wait a moment before trying again.', [], 'LCK_RATE_LIMIT');
	}

	private function assertOutboundUrlLength(string $url): void
	{
		if ($url !== '' && strlen($url) > 2048) {
			throw new ValidationException('Webhook URL is not allowed.', ['url' => 'Webhook URL is too long.'], 'LCK_INVALID_URL');
		}
	}

	private function classifyOutboundFailure(\Throwable $e): string
	{
		$msg = strtolower($e->getMessage());
		if (str_contains($msg, 'webhook') || str_contains($msg, 'http') || str_contains($msg, 'curl') || str_contains($msg, 'ssl')) {
			return 'LCK_HTTP_FAILED';
		}
		return 'LCK_MAIL_FAILED';
	}

	/** @return array<string, mixed> */
	private function requestBody(): array
	{
		$raw = file_get_contents('php://input');
		if (is_string($raw) && $raw !== '') {
			$decoded = json_decode($raw, true);
			if (is_array($decoded)) {
				return $decoded;
			}
		}
		$params = $this->request->getParams();
		return is_array($params) ? $params : [];
	}
}
