<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Service;

use OCA\LogCheck\Exception\ValidationException;
use OCA\LogCheck\Service\SsrfGuard;
use PHPUnit\Framework\TestCase;

class SsrfGuardTest extends TestCase
{
	private SsrfGuard $guard;

	protected function setUp(): void
	{
		$this->guard = new SsrfGuard();
	}

	public function testPinReturnsHostAndIpForPublicLiteral(): void
	{
		$pin = $this->guard->pin('https://1.1.1.1/hooks/abc');
		self::assertSame('1.1.1.1', $pin['host']);
		self::assertSame('1.1.1.1', $pin['ip']);
		self::assertSame(443, $pin['port']);
		self::assertSame('/hooks/abc', $pin['path']);
		self::assertSame('https://1.1.1.1/hooks/abc', $pin['url']);
	}

	public function testRejectsHttp(): void
	{
		$this->expectException(ValidationException::class);
		$this->guard->assertAllowed('http://example.com/hook');
	}

	public function testPinRejectsHttp(): void
	{
		$this->expectException(ValidationException::class);
		$this->guard->pin('http://1.1.1.1/hook');
	}

	public function testRejectsPrivateIpLiteral(): void
	{
		$this->expectException(ValidationException::class);
		$this->guard->assertAllowed('https://127.0.0.1/hook');
	}

	public function testRejectsRfc1918(): void
	{
		$this->expectException(ValidationException::class);
		$this->guard->pin('https://10.0.0.5/hook');
	}

	public function testRejectsMetadataIp(): void
	{
		$this->expectException(ValidationException::class);
		$this->guard->assertAllowed('https://169.254.169.254/latest/meta-data');
	}

	public function testRejectsUserinfo(): void
	{
		$this->expectException(ValidationException::class);
		$this->guard->pin('https://user:pass@1.1.1.1/hook');
	}

	public function testRejectsIpv6LoopbackLiteral(): void
	{
		$this->expectException(ValidationException::class);
		$this->guard->assertAllowed('https://[::1]/hook');
	}

	public function testRejectsIpv4MappedLoopbackLiteral(): void
	{
		$this->expectException(ValidationException::class);
		$this->guard->pin('https://[::ffff:127.0.0.1]/hook');
	}

	public function testRejectsFileScheme(): void
	{
		$this->expectException(ValidationException::class);
		$this->guard->assertAllowed('file:///etc/passwd');
	}

	/**
	 * Momos: global unicast IPv6 must be allowed — otherwise dual-stack /
	 * IPv6-only webhook hosts (Slack, etc.) are falsely rejected as “private”.
	 */
	public function testAllowsPublicIpv6Literal(): void
	{
		$pin = $this->guard->pin('https://[2606:4700:4700::1111]/hooks/x');
		self::assertSame('2606:4700:4700::1111', $pin['ip']);
		self::assertSame(443, $pin['port']);
	}

	public function testRejectsUniqueLocalIpv6Literal(): void
	{
		$this->expectException(ValidationException::class);
		$this->guard->assertAllowed('https://[fd12:3456:789a::1]/hook');
	}

	public function testRejectsLinkLocalIpv6Literal(): void
	{
		$this->expectException(ValidationException::class);
		$this->guard->assertAllowed('https://[fe80::1]/hook');
	}

	/** fe80::/10 includes fe90–febf — must not rely on str_starts_with('fe80:') */
	public function testRejectsLinkLocalIpv6OutsideFe80StringPrefix(): void
	{
		$this->expectException(ValidationException::class);
		$this->guard->assertAllowed('https://[fe90::1]/hook');
	}

	/** Deprecated site-local fec0::/10 must be blocked (fail closed). */
	public function testRejectsDeprecatedSiteLocalIpv6(): void
	{
		$this->expectException(ValidationException::class);
		$this->guard->assertAllowed('https://[fec0::1]/hook');
	}

	public function testRejectsAzureImdsEvenWithAllowPrivate(): void
	{
		$this->expectException(ValidationException::class);
		$this->guard->assertAllowed('https://168.63.129.16/metadata', true);
	}

	public function testRejectsAlibabaMetadata(): void
	{
		$this->expectException(ValidationException::class);
		$this->guard->assertAllowed('https://100.100.100.200/latest/meta-data');
	}

	public function testRejectsMulticastIpv4(): void
	{
		$this->expectException(ValidationException::class);
		$this->guard->assertAllowed('https://224.0.0.1/hook');
	}

	public function testRejectsNat64EmbeddedPrivate(): void
	{
		// 64:ff9b::0a00:0001 embeds 10.0.0.1
		$this->expectException(ValidationException::class);
		$this->guard->assertAllowed('https://[64:ff9b::a00:1]/hook');
	}
}
