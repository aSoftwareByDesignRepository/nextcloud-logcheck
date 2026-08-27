<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\LogCheck\Service;

use OCP\ICacheFactory;

/**
 * One-shot proofs that a channel URL (or named channel) was tested successfully
 * before SettingsService will allow enabling Slack/webhook with that URL.
 */
final class ChannelTestProof
{
	private const TTL_SECONDS = 900;

	public function __construct(
		private readonly ICacheFactory $cacheFactory,
		private readonly ChannelStateStore $channelStateStore,
	) {
	}

	public function markUrl(string $uid, string $channel, string $url): void
	{
		$cache = $this->cacheFactory->createDistributed('logcheck');
		$cache->set($this->urlKey($uid, $channel, $url), '1', self::TTL_SECONDS);
	}

	/**
	 * Atomic one-shot consume: ICache::remove returns whether the key existed
	 * (Redis DEL / APCu delete). Avoids get-then-remove double-spend (M1).
	 */
	public function consumeUrl(string $uid, string $channel, string $url): bool
	{
		$cache = $this->cacheFactory->createDistributed('logcheck');
		return $cache->remove($this->urlKey($uid, $channel, $url));
	}

	public function isStateVerified(string $channel): bool
	{
		$state = $this->channelStateStore->get($channel);
		return $state !== null
			&& $state['verified_at'] !== null
			&& $state['disabled_at'] === null;
	}

	/** Invalidate durable verification when the stored URL changes. */
	public function invalidateChannel(string $channel): void
	{
		$this->channelStateStore->clearVerification($channel);
	}

	private function urlKey(string $uid, string $channel, string $url): string
	{
		return 'tested:' . $uid . ':' . $channel . ':' . hash('sha256', $url);
	}
}
