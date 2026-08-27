<?php

declare(strict_types=1);

namespace OCA\LogCheck\Service;

/**
 * Human-readable status for Home UI (no ops jargon).
 */
final class StatusService
{
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LogBackendService $logBackendService,
		private readonly ChannelStateStore $channelStateStore,
		private readonly TopologyGuard $topologyGuard,
	) {
	}

	/**
	 * @return array{
	 *   state: string,
	 *   label: string,
	 *   watch_enabled: bool,
	 *   backend_supported: bool,
	 *   topology_ok: bool,
	 *   log_type: string,
	 *   last_check_at: ?int,
	 *   stale: bool,
	 *   error: ?string,
	 *   secrets_readable: bool,
	 *   channels: array<string, array{enabled: bool, disabled: bool, last_error: ?string}>,
	 *   alerts_ready: bool
	 * }
	 */
	public function getStatus(): array
	{
		$dto = $this->settingsService->toUiDto();
		$settings = $dto['settings'];
		// Topology fingerprint lives only in raw settings — never in the UI DTO.
		$raw = $this->settingsService->getRawSettings();
		$runtime = is_array($raw['runtime'] ?? null) ? $raw['runtime'] : [];
		$logType = $this->logBackendService->getLogType();
		$supported = $this->logBackendService->isFileBackend();
		$topologyOk = !$this->topologyGuard->isMismatch($runtime);
		$watch = !empty($settings['watch_enabled']);
		$lastRun = isset($runtime['last_run_at']) ? (int)$runtime['last_run_at'] : null;
		$coalesce = (int)($settings['coalesce_seconds'] ?? 300);
		$staleThreshold = 2 * max(WatchRunner::JOB_INTERVAL, $coalesce);
		$stale = $watch && $supported && $topologyOk && $lastRun !== null && (time() - $lastRun) > $staleThreshold;
		$lastRunOk = array_key_exists('last_run_ok', $runtime) ? $runtime['last_run_ok'] : null;
		$runFailed = $watch && (
			$lastRunOk === false
			|| (is_string($runtime['last_error'] ?? null) && (string)$runtime['last_error'] !== '')
		);

		$channels = [];
		foreach (['notification', 'email', 'slack', 'webhook'] as $name) {
			$cfg = $settings['channels'][$name] ?? [];
			$state = $this->channelStateStore->get($name);
			$raw = $state['last_error'] ?? null;
			$channels[$name] = [
				'enabled' => !empty($cfg['enabled']),
				'disabled' => $state !== null && $state['disabled_at'] !== null,
				// Re-classify legacy rows so old raw diagnostics never reach the UI/API.
				'last_error' => is_string($raw) && $raw !== '' ? ChannelStateStore::safeError($raw) : null,
			];
		}

		$secretsReadable = !empty($settings['secrets_readable']);

		if (!$supported) {
			$state = 'unsupported';
			$label = 'Can\'t watch';
		} elseif (!$topologyOk) {
			$state = 'unsupported';
			$label = 'Can\'t watch';
		} elseif (!$watch) {
			$state = 'off';
			$label = 'Off';
		} elseif ($stale) {
			$state = 'stale';
			$label = 'Needs a check';
		} elseif ($runFailed || !$secretsReadable) {
			// Never present Watching/OK after a failed check or unreadable secrets (NN-H01).
			$state = 'degraded';
			$label = 'Needs attention';
		} else {
			$state = 'watching';
			$label = 'Watching';
		}

		$error = null;
		if (!$supported) {
			$error = 'HealthCheck only supports file-based logging.';
		} elseif (!$topologyOk) {
			$error = 'HealthCheck noticed a different server. Multi-server setups need one shared log file.';
		} elseif (!empty($runtime['last_error'])) {
			$error = ChannelStateStore::safeError((string)$runtime['last_error']);
		} elseif (!$secretsReadable) {
			$error = 'Stored channel secrets cannot be read. Re-enter webhook URLs.';
		}

		$alertsReady = false;
		foreach ($channels as $ch) {
			if (!empty($ch['enabled']) && empty($ch['disabled'])) {
				$alertsReady = true;
				break;
			}
		}

		return [
			'state' => $state,
			'label' => $label,
			'watch_enabled' => $watch,
			'backend_supported' => $supported,
			'topology_ok' => $topologyOk,
			'log_type' => $logType,
			'last_check_at' => $lastRun,
			'stale' => $stale,
			'error' => $error,
			'secrets_readable' => $secretsReadable,
			'channels' => $channels,
			'alerts_ready' => $alertsReady,
			'settings_version' => $dto['version'],
		];
	}
}
