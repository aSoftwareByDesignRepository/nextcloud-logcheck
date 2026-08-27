<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Service;

use OCA\LogCheck\Service\SecretStore;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

class SecretStoreTest extends TestCase
{
	public function testMaskShowsHostOnlyNeverPathToken(): void
	{
		$crypto = $this->createMock(ICrypto::class);
		$store = new SecretStore($crypto);
		$url = 'https://hooks.slack.com/services/T00/B00/xoxb-secret-token-ABCD';
		$masked = $store->mask($url);
		self::assertSame('Saved (hooks.slack.com)', $masked);
		self::assertStringNotContainsString('ABCD', (string)$masked);
		self::assertStringNotContainsString('xoxb', (string)$masked);
		self::assertStringNotContainsString('services', (string)$masked);
	}

	public function testMaskEmptyReturnsNull(): void
	{
		$crypto = $this->createMock(ICrypto::class);
		$store = new SecretStore($crypto);
		self::assertNull($store->mask(null));
		self::assertNull($store->mask(''));
	}
}
