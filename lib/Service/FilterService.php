<?php

declare(strict_types=1);

namespace OCA\LogCheck\Service;

use OCA\LogCheck\AppInfo\Application;

/**
 * Level / app allow-deny / mute pipeline with self-mute and regex budget.
 */
final class FilterService
{
	public function __construct(
		private readonly FingerprintService $fingerprintService,
	) {
	}

	/**
	 * @param array<string, mixed> $line Parsed JSON log line
	 * @param array<string, mixed> $settings
	 * @return array{matched: bool, muted: bool, fingerprint?: string, level?: int, app?: string, message?: string}
	 */
	public function evaluate(array $line, array $settings): array
	{
		$level = isset($line['level']) ? (int)$line['level'] : -1;
		$app = isset($line['app']) ? (string)$line['app'] : '';
		$message = $line['message'] ?? '';
		if (is_array($message) || is_object($message)) {
			$encoded = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			$messageText = is_string($encoded) ? $encoded : '';
		} else {
			$messageText = (string)$message;
		}

		$minLevel = (int)($settings['min_level'] ?? 3);
		if ($level < $minLevel) {
			return ['matched' => false, 'muted' => false];
		}

		// Always mute LogCheck own noise (NN-04)
		if ($app === Application::APP_ID || $app === 'logcheck') {
			return ['matched' => false, 'muted' => true];
		}

		$appMode = (string)($settings['app_mode'] ?? 'all');
		$appList = $settings['app_list'] ?? [];
		if (!is_array($appList)) {
			$appList = [];
		}
		$appList = array_map('strval', $appList);

		if ($appMode === 'allow' && $appList !== [] && !in_array($app, $appList, true)) {
			return ['matched' => false, 'muted' => false];
		}
		if ($appMode === 'deny' && in_array($app, $appList, true)) {
			return ['matched' => false, 'muted' => true];
		}

		$mutes = $settings['mutes'] ?? [];
		if (!is_array($mutes)) {
			$mutes = [];
		}

		foreach ($mutes as $mute) {
			if (!is_array($mute)) {
				continue;
			}
			$type = (string)($mute['type'] ?? '');
			$value = (string)($mute['value'] ?? '');
			if ($type === 'app' && $value !== '' && $app === $value) {
				return ['matched' => false, 'muted' => true];
			}
			if ($type === 'regex' && $value !== '') {
				$flags = (string)($mute['flags'] ?? 'i');
				$safeFlags = preg_replace('#[^imsxuADSUXJCOP]#', '', $flags);
				if (!is_string($safeFlags) || $safeFlags === '') {
					$safeFlags = 'i';
				}
				if (mb_strlen($value) > 200) {
					continue;
				}
				$delim = '/' . str_replace('/', '\\/', $value) . '/' . $safeFlags;
				$prevBacktrack = ini_get('pcre.backtrack_limit');
				$prevRecursion = ini_get('pcre.recursion_limit');
				ini_set('pcre.backtrack_limit', '100000');
				ini_set('pcre.recursion_limit', '10000');
				$result = @preg_match($delim, $messageText);
				if ($prevBacktrack !== false) {
					ini_set('pcre.backtrack_limit', (string)$prevBacktrack);
				}
				if ($prevRecursion !== false) {
					ini_set('pcre.recursion_limit', (string)$prevRecursion);
				}
				// $result === false → compile/budget failure: skip mute, do not fail job
				if ($result === 1) {
					return ['matched' => false, 'muted' => true];
				}
			}
		}

		$fp = $this->fingerprintService->fingerprint($level, $app, $messageText);
		return [
			'matched' => true,
			'muted' => false,
			'fingerprint' => $fp,
			'level' => $level,
			'app' => $app,
			'message' => $messageText,
		];
	}
}
