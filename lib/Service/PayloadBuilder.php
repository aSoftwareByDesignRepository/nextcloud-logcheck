<?php

declare(strict_types=1);

namespace OCA\LogCheck\Service;

use OCP\IURLGenerator;
use OCP\App\IAppManager;

/**
 * Builds lck.alert.v1 payloads. sample_message is null when excerpts off (NN-01).
 */
final class PayloadBuilder
{
	public function __construct(
		private readonly IURLGenerator $urlGenerator,
		private readonly IAppManager $appManager,
	) {
	}

	/**
	 * @param array<string, mixed> $accumulator
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	public function build(string $eventId, array $accumulator, array $settings, int $windowSeconds): array
	{
		$includeExcerpts = !empty($settings['include_message_excerpts']);
		$maxFp = (int)($settings['max_fingerprints_in_payload'] ?? 20);
		$maxChars = (int)($settings['excerpt_max_chars'] ?? 200);

		$byLevel = $accumulator['by_level'] ?? [];
		$byApp = $accumulator['by_app'] ?? [];
		$fingerprints = $accumulator['fingerprints'] ?? [];
		if (!is_array($byLevel)) {
			$byLevel = [];
		}
		if (!is_array($byApp)) {
			$byApp = [];
		}
		if (!is_array($fingerprints)) {
			$fingerprints = [];
		}

		uasort($fingerprints, static function ($a, $b): int {
			return ((int)($b['count'] ?? 0)) <=> ((int)($a['count'] ?? 0));
		});

		$top = [];
		$i = 0;
		foreach ($fingerprints as $fp => $meta) {
			if ($i >= $maxFp) {
				break;
			}
			$sample = null;
			if ($includeExcerpts) {
				$raw = (string)($meta['sample_message'] ?? '');
				$sample = $this->redact(mb_substr($raw, 0, $maxChars));
			}
			$top[] = [
				'fingerprint' => (string)$fp,
				'count' => (int)($meta['count'] ?? 0),
				'level' => (int)($meta['level'] ?? 0),
				'app' => (string)($meta['app'] ?? ''),
				'sample_message' => $sample,
			];
			$i++;
		}

		$payload = [
			'schema' => 'lck.alert.v1',
			'event_id' => $eventId,
			'instance' => $this->urlGenerator->getAbsoluteURL('/'),
			'generated_at' => gmdate('c'),
			'window_seconds' => $windowSeconds,
			'min_level' => (int)($settings['min_level'] ?? 3),
			'total_matched' => (int)($accumulator['total_matched'] ?? 0),
			'total_muted' => (int)($accumulator['total_muted'] ?? 0),
			'truncated' => !empty($accumulator['truncated']),
			'by_level' => $this->stringifyKeys($byLevel),
			'by_app' => $byApp,
			'top_fingerprints' => $top,
		];

		if ($this->appManager->isEnabledForUser('logreader')) {
			$payload['deep_link'] = $this->urlGenerator->getAbsoluteURL('/settings/admin/logreader');
		}

		return $payload;
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	public function buildTestPayload(string $channel, array $settings): array
	{
		return $this->build(
			'test-' . bin2hex(random_bytes(8)),
			[
				'total_matched' => 1,
				'total_muted' => 0,
				'truncated' => false,
				'by_level' => ['3' => 1],
				'by_app' => ['logcheck' => 1],
				'fingerprints' => [
					hash('sha256', 'test') => [
						'count' => 1,
						'level' => 3,
						'app' => 'logcheck',
						'sample_message' => 'LogCheck test alert',
					],
				],
			],
			array_merge($settings, ['include_message_excerpts' => false]),
			(int)($settings['coalesce_seconds'] ?? 300)
		);
	}

	public function redact(string $text): string
	{
		// Key=value and key: value (logs, headers)
		$text = preg_replace('/password\s*[:=]\s*\S+/i', 'password=***', $text) ?? $text;
		$text = preg_replace('/passwd\s*[:=]\s*\S+/i', 'passwd=***', $text) ?? $text;
		$text = preg_replace('/secret\s*[:=]\s*\S+/i', 'secret=***', $text) ?? $text;
		$text = preg_replace('/private[_-]?key\s*[:=]\s*\S+/i', 'private_key=***', $text) ?? $text;
		$text = preg_replace('/api[_-]?key\s*[:=]\s*\S+/i', 'api_key=***', $text) ?? $text;
		$text = preg_replace('/client[_-]?secret\s*[:=]\s*\S+/i', 'client_secret=***', $text) ?? $text;
		$text = preg_replace('/access[_-]?token\s*[:=]\s*\S+/i', 'access_token=***', $text) ?? $text;
		$text = preg_replace('/refresh[_-]?token\s*[:=]\s*\S+/i', 'refresh_token=***', $text) ?? $text;
		$text = preg_replace('/token\s*[:=]\s*\S+/i', 'token=***', $text) ?? $text;
		$text = preg_replace('/Bearer\s+\S+/i', 'Bearer ***', $text) ?? $text;
		$text = preg_replace('/authorization\s*[:=]\s*\S+/i', 'authorization=***', $text) ?? $text;
		// JSON / PHP-array style: "password":"…" or 'token'=>'…'
		$text = preg_replace('/("password"|\'password\')\s*:\s*("[^"]*"|\'[^\']*\')/i', '$1:***', $text) ?? $text;
		$text = preg_replace('/("passwd"|\'passwd\')\s*:\s*("[^"]*"|\'[^\']*\')/i', '$1:***', $text) ?? $text;
		$text = preg_replace('/("secret"|\'secret\')\s*:\s*("[^"]*"|\'[^\']*\')/i', '$1:***', $text) ?? $text;
		$text = preg_replace('/("client_secret"|\'client_secret\')\s*:\s*("[^"]*"|\'[^\']*\')/i', '$1:***', $text) ?? $text;
		$text = preg_replace('/("access_token"|\'access_token\')\s*:\s*("[^"]*"|\'[^\']*\')/i', '$1:***', $text) ?? $text;
		$text = preg_replace('/("refresh_token"|\'refresh_token\')\s*:\s*("[^"]*"|\'[^\']*\')/i', '$1:***', $text) ?? $text;
		$text = preg_replace('/("api_key"|"api-key"|\'api_key\')\s*:\s*("[^"]*"|\'[^\']*\')/i', '$1:***', $text) ?? $text;
		$text = preg_replace('/("token"|\'token\')\s*:\s*("[^"]*"|\'[^\']*\')/i', '$1:***', $text) ?? $text;
		$text = preg_replace('/("authorization"|\'authorization\')\s*:\s*("[^"]*"|\'[^\']*\')/i', '$1:***', $text) ?? $text;
		$text = preg_replace('/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/', 'jwt=***', $text) ?? $text;
		// Common opaque tokens in logs
		$text = preg_replace('/\bxox[baprs]-[A-Za-z0-9-]{10,}\b/', 'slack_token=***', $text) ?? $text;
		$text = preg_replace('/\b(ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9]{20,}\b/', 'github_token=***', $text) ?? $text;
		$text = preg_replace('/\bAKIA[0-9A-Z]{16}\b/', 'aws_key=***', $text) ?? $text;
		$text = preg_replace('/-----BEGIN [A-Z ]*PRIVATE KEY-----[\s\S]*?-----END [A-Z ]*PRIVATE KEY-----/', 'pem=***', $text) ?? $text;
		return $text;
	}

	/**
	 * @param array<array-key, mixed> $map
	 * @return array<string, int>
	 */
	private function stringifyKeys(array $map): array
	{
		$out = [];
		foreach ($map as $k => $v) {
			$out[(string)$k] = (int)$v;
		}
		return $out;
	}
}
