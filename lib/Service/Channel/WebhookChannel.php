<?php

declare(strict_types=1);

namespace OCA\LogCheck\Service\Channel;

use OCA\LogCheck\Service\SafeHttpClient;

final class WebhookChannel
{
	public function __construct(
		private readonly SafeHttpClient $http,
	) {
	}

	/** @param array<string, mixed> $payload */
	public function send(array $payload, string $url, bool $allowPrivate): void
	{
		$result = $this->http->postJson($url, $payload, $allowPrivate);
		$this->http->assertSuccess($result);
	}
}
