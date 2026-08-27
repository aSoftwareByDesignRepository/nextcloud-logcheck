<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Service;

use OCA\LogCheck\Service\SafeHttpClient;
use OCA\LogCheck\Service\SsrfGuard;
use PHPUnit\Framework\TestCase;

class SafeHttpClientInstanceStatusTest extends TestCase
{
	public function testRejectsOffHostUrl(): void
	{
		$client = new SafeHttpClient(new SsrfGuard());
		$this->expectException(\InvalidArgumentException::class);
		$client->getInstanceStatus('https://evil.example/status.php', 'cloud.example');
	}

	public function testRejectsNonStatusPath(): void
	{
		$client = new SafeHttpClient(new SsrfGuard());
		$this->expectException(\InvalidArgumentException::class);
		$client->getInstanceStatus('https://cloud.example/index.php', 'cloud.example');
	}

	public function testRejectsQueryString(): void
	{
		$client = new SafeHttpClient(new SsrfGuard());
		$this->expectException(\InvalidArgumentException::class);
		$client->getInstanceStatus('https://cloud.example/status.php?x=1', 'cloud.example');
	}

	public function testRejectsHttpScheme(): void
	{
		$client = new SafeHttpClient(new SsrfGuard());
		$this->expectException(\InvalidArgumentException::class);
		$client->getInstanceStatus('http://cloud.example/status.php', 'cloud.example');
	}

	public function testAllowsWebrootStatusPath(): void
	{
		self::assertTrue(SafeHttpClient::isAllowedStatusPath('/status.php'));
		self::assertTrue(SafeHttpClient::isAllowedStatusPath('/nextcloud/status.php'));
		self::assertFalse(SafeHttpClient::isAllowedStatusPath('/../status.php'));
		self::assertFalse(SafeHttpClient::isAllowedStatusPath('/a/b/status.php'));
		self::assertFalse(SafeHttpClient::isAllowedStatusPath('/index.php'));
	}

	public function testHostForUrlBracketsIpv6(): void
	{
		self::assertSame('[2001:db8::1]', SafeHttpClient::hostForUrl('2001:db8::1'));
		self::assertSame('cloud.example', SafeHttpClient::hostForUrl('cloud.example'));
	}
}
