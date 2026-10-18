<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Service;

use OCA\LogCheck\Service\SafeHttpClient;
use OCA\LogCheck\Service\SsrfGuard;
use OCP\ICertificateManager;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class SafeHttpClientInstanceStatusTest extends TestCase
{
	private function client(): SafeHttpClient
	{
		return new SafeHttpClient(new SsrfGuard(), $this->createMock(ICertificateManager::class));
	}

	public function testRejectsOffHostUrl(): void
	{
		$client = $this->client();
		$this->expectException(\InvalidArgumentException::class);
		$client->getInstanceStatus('https://evil.example/status.php', 'cloud.example');
	}

	public function testRejectsNonStatusPath(): void
	{
		$client = $this->client();
		$this->expectException(\InvalidArgumentException::class);
		$client->getInstanceStatus('https://cloud.example/index.php', 'cloud.example');
	}

	public function testRejectsQueryString(): void
	{
		$client = $this->client();
		$this->expectException(\InvalidArgumentException::class);
		$client->getInstanceStatus('https://cloud.example/status.php?x=1', 'cloud.example');
	}

	public function testRejectsHttpScheme(): void
	{
		$client = $this->client();
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

	/** The forced connect IP routes through pinTo() — name/Host/SNI untouched. */
	public function testForceIpPinsToLiteralNotDns(): void
	{
		$guard = $this->createMock(SsrfGuard::class);
		$guard->expects(self::never())->method('pin');
		$guard->expects(self::once())
			->method('pinTo')
			->with('https://cloud.example/status.php', '203.0.113.10', true)
			->willThrowException(new \OCA\LogCheck\Exception\ValidationException('stop before curl', [], 'LCK_STOP'));
		$client = new SafeHttpClient($guard, $this->createMock(ICertificateManager::class));

		try {
			$client->getInstanceStatus('https://cloud.example/status.php', 'cloud.example', '203.0.113.10');
			self::fail('expected ValidationException from pinTo');
		} catch (\OCA\LogCheck\Exception\ValidationException $e) {
			self::assertSame('LCK_STOP', $e->getErrorCode());
		}
	}

	/** Without a pin the self-check keeps resolving the canonical name. */
	public function testNoForceIpResolvesViaPin(): void
	{
		$guard = $this->createMock(SsrfGuard::class);
		$guard->expects(self::never())->method('pinTo');
		$guard->expects(self::once())
			->method('pin')
			->with('https://cloud.example/status.php', true)
			->willThrowException(new \OCA\LogCheck\Exception\ValidationException('stop before curl', [], 'LCK_STOP'));
		$client = new SafeHttpClient($guard, $this->createMock(ICertificateManager::class));

		try {
			$client->getInstanceStatus('https://cloud.example/status.php', 'cloud.example');
			self::fail('expected ValidationException from pin');
		} catch (\OCA\LogCheck\Exception\ValidationException $e) {
			self::assertSame('LCK_STOP', $e->getErrorCode());
		}
	}

	/** A metadata literal as pinned IP is rejected before any dial. */
	public function testForceIpCannotPointAtMetadata(): void
	{
		$client = $this->client();
		$this->expectException(\OCA\LogCheck\Exception\ValidationException::class);
		$client->getInstanceStatus('https://cloud.example/status.php', 'cloud.example', '169.254.169.254');
	}

	/** CURLOPT_CAINFO must mirror core's HTTP client: the ICertificateManager bundle. */
	public function testCaBundlePathUsesCertificateManagerBundle(): void
	{
		$bundle = tempnam(sys_get_temp_dir(), 'lck-ca-');
		self::assertIsString($bundle);
		$certs = $this->createMock(ICertificateManager::class);
		$certs->method('getAbsoluteBundlePath')->willReturn($bundle);
		try {
			$method = new ReflectionMethod(SafeHttpClient::class, 'caBundlePath');
			$method->setAccessible(true);
			self::assertSame($bundle, $method->invoke(new SafeHttpClient(new SsrfGuard(), $certs)));
		} finally {
			@unlink($bundle);
		}
	}

	public function testCaBundlePathFallsBackToCurlDefaultWhenManagerFails(): void
	{
		$method = new ReflectionMethod(SafeHttpClient::class, 'caBundlePath');
		$method->setAccessible(true);

		$throwing = $this->createMock(ICertificateManager::class);
		$throwing->method('getAbsoluteBundlePath')->willThrowException(new \RuntimeException('not installed'));
		self::assertNull($method->invoke(new SafeHttpClient(new SsrfGuard(), $throwing)));

		$missing = $this->createMock(ICertificateManager::class);
		$missing->method('getAbsoluteBundlePath')->willReturn('/nonexistent/lck-rootcerts.crt');
		self::assertNull($method->invoke(new SafeHttpClient(new SsrfGuard(), $missing)));
	}
}
