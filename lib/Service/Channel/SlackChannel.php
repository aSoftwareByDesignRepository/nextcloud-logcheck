<?php

declare(strict_types=1);

namespace OCA\LogCheck\Service\Channel;

use OCA\LogCheck\Service\SafeHttpClient;

final class SlackChannel
{
	public function __construct(
		private readonly SafeHttpClient $http,
	) {
	}

	/** @param array<string, mixed> $payload */
	public function send(array $payload, string $webhookUrl, bool $allowPrivate): void
	{
		$total = (int)($payload['total_matched'] ?? 0);
		$muted = (int)($payload['total_muted'] ?? 0);
		$text = sprintf(
			'LogCheck: %d matched, %d muted%s',
			$total,
			$muted,
			!empty($payload['truncated']) ? ' (truncated)' : ''
		);
		$blocks = [
			'text' => $text,
			'blocks' => [
				[
					'type' => 'section',
					'text' => [
						'type' => 'mrkdwn',
						'text' => '*' . $text . '*',
					],
				],
			],
		];
		// Optional excerpts only when present (never dump full JSON schema blob).
		$samples = [];
		foreach (($payload['top_fingerprints'] ?? []) as $fp) {
			if (!is_array($fp)) {
				continue;
			}
			$sample = $fp['sample_message'] ?? null;
			if (is_string($sample) && $sample !== '') {
				$app = (string)($fp['app'] ?? '');
				$samples[] = ($app !== '' ? $app . ': ' : '') . mb_substr($sample, 0, 200);
			}
		}
		if ($samples !== []) {
			$escaped = array_map(static function (string $line): string {
				// Neutralize Slack mrkdwn link/mention injection from log excerpts.
				return str_replace(['&', '<', '>', '|'], ['&amp;', '&lt;', '&gt;', '&#124;'], $line);
			}, array_slice($samples, 0, 5));
			$blocks['blocks'][] = [
				'type' => 'section',
				'text' => [
					'type' => 'mrkdwn',
					'text' => "```\n" . implode("\n", $escaped) . "\n```",
				],
			];
		}
		if (!empty($payload['deep_link']) && is_string($payload['deep_link'])) {
			$link = str_replace(['<', '>', '|'], '', $payload['deep_link']);
			$blocks['blocks'][] = [
				'type' => 'section',
				'text' => [
					'type' => 'mrkdwn',
					'text' => '<' . $link . '|Open logging>',
				],
			];
		}
		$result = $this->http->postJson($webhookUrl, $blocks, $allowPrivate);
		$this->http->assertSuccess($result);
	}
}
