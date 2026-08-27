<?php

declare(strict_types=1);

namespace OCA\LogCheck\Service\Channel;

use OCP\IL10N;
use OCP\Mail\IMailer;

final class EmailChannel
{
	public function __construct(
		private readonly IMailer $mailer,
		private readonly IL10N $l10n,
	) {
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param list<string> $recipients
	 */
	public function send(array $payload, array $recipients): void
	{
		if ($recipients === []) {
			throw new \RuntimeException('Email could not be sent. Check mail settings.');
		}
		$total = (int)($payload['total_matched'] ?? 0);
		$subject = $this->l10n->n(
			'LogCheck: %n new error',
			'LogCheck: %n new errors',
			$total
		);
		$body = $this->formatBody($payload);
		$message = $this->mailer->createMessage();
		$message->setTo(array_combine($recipients, $recipients) ?: $recipients);
		$message->setSubject($subject);
		$message->setPlainBody($body);
		$this->mailer->send($message);
	}

	/** @param array<string, mixed> $payload */
	private function formatBody(array $payload): string
	{
		$lines = [];
		$lines[] = 'LogCheck alert';
		$lines[] = 'Total matched: ' . (int)($payload['total_matched'] ?? 0);
		$lines[] = 'Total muted: ' . (int)($payload['total_muted'] ?? 0);
		if (!empty($payload['truncated'])) {
			$lines[] = 'Note: results were truncated.';
		}
		$byLevel = $payload['by_level'] ?? [];
		if (is_array($byLevel)) {
			foreach ($byLevel as $level => $count) {
				$lines[] = 'Level ' . $level . ': ' . $count;
			}
		}
		$byApp = $payload['by_app'] ?? [];
		if (is_array($byApp)) {
			foreach ($byApp as $app => $count) {
				$lines[] = 'App ' . $app . ': ' . $count;
			}
		}
		if (!empty($payload['deep_link'])) {
			$lines[] = 'Details: ' . (string)$payload['deep_link'];
		}
		foreach (($payload['top_fingerprints'] ?? []) as $fp) {
			if (!is_array($fp)) {
				continue;
			}
			$sample = $fp['sample_message'] ?? null;
			if (is_string($sample) && $sample !== '') {
				$app = (string)($fp['app'] ?? '');
				$lines[] = 'Sample' . ($app !== '' ? " ($app)" : '') . ': ' . mb_substr($sample, 0, 200);
			}
		}
		return implode("\n", $lines);
	}
}
