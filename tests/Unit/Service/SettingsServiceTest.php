<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Service;

use OCA\LogCheck\Exception\ForbiddenException;
use OCA\LogCheck\Service\AccessService;
use OCA\LogCheck\Service\AuditService;
use OCA\LogCheck\Service\MuteRegexValidator;
use OCA\LogCheck\Service\SecretStore;
use OCA\LogCheck\Service\SettingsService;
use OCA\LogCheck\Service\SsrfGuard;
use OCA\LogCheck\Service\ChannelTestProof;
use OCA\LogCheck\Service\TopologyGuard;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class SettingsServiceTest extends TestCase
{
	public function testAllowPrivateWebhooksForbiddenForNonAdmin(): void
	{
		$svc = new SettingsService(
			$this->createMock(IDBConnection::class),
			$this->createMock(SecretStore::class),
			$this->createMock(MuteRegexValidator::class),
			$this->createMock(AccessService::class),
			$this->createMock(SsrfGuard::class),
			$this->createMock(AuditService::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
			$this->createMock(TopologyGuard::class),
			$this->createMock(ChannelTestProof::class),
		);

		$method = new ReflectionMethod(SettingsService::class, 'mergeAndValidate');
		$method->setAccessible(true);

		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('Only Nextcloud admins can allow private webhook addresses.');
		$method->invoke($svc, SettingsService::defaults(), [
			'allow_private_webhooks' => true,
		], 'alice', false);
	}

	public function testAllowPrivateWebhooksAllowedForNcAdmin(): void
	{
		$svc = new SettingsService(
			$this->createMock(IDBConnection::class),
			$this->createMock(SecretStore::class),
			$this->createMock(MuteRegexValidator::class),
			$this->createMock(AccessService::class),
			$this->createMock(SsrfGuard::class),
			$this->createMock(AuditService::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
			$this->createMock(TopologyGuard::class),
			$this->createMock(ChannelTestProof::class),
		);

		$method = new ReflectionMethod(SettingsService::class, 'mergeAndValidate');
		$method->setAccessible(true);

		$out = $method->invoke($svc, SettingsService::defaults(), [
			'allow_private_webhooks' => true,
		], 'admin', true);
		self::assertTrue($out['allow_private_webhooks']);
	}

	public function testNotificationRecipientsMustBeEntitled(): void
	{
		$access = $this->createMock(AccessService::class);
		$access->method('entitledUids')->willReturn(['admin', 'ops']);
		$svc = new SettingsService(
			$this->createMock(IDBConnection::class),
			$this->createMock(SecretStore::class),
			$this->createMock(MuteRegexValidator::class),
			$access,
			$this->createMock(SsrfGuard::class),
			$this->createMock(AuditService::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
			$this->createMock(TopologyGuard::class),
			$this->createMock(ChannelTestProof::class),
		);
		$method = new ReflectionMethod(SettingsService::class, 'mergeAndValidate');
		$method->setAccessible(true);

		$this->expectException(\OCA\LogCheck\Exception\ValidationException::class);
		$method->invoke($svc, SettingsService::defaults(), [
			'channels' => [
				'notification' => [
					'enabled' => true,
					'recipient_uids' => ['stranger'],
				],
			],
		], 'admin', true);
	}

	public function testNotificationRecipientsAcceptEntitled(): void
	{
		$access = $this->createMock(AccessService::class);
		$access->method('entitledUids')->willReturn(['admin', 'ops']);
		$svc = new SettingsService(
			$this->createMock(IDBConnection::class),
			$this->createMock(SecretStore::class),
			$this->createMock(MuteRegexValidator::class),
			$access,
			$this->createMock(SsrfGuard::class),
			$this->createMock(AuditService::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
			$this->createMock(TopologyGuard::class),
			$this->createMock(ChannelTestProof::class),
		);
		$method = new ReflectionMethod(SettingsService::class, 'mergeAndValidate');
		$method->setAccessible(true);

		$out = $method->invoke($svc, SettingsService::defaults(), [
			'channels' => [
				'notification' => [
					'enabled' => true,
					'recipient_uids' => ['ops'],
				],
			],
		], 'admin', true);
		self::assertSame(['ops'], $out['channels']['notification']['recipient_uids']);
	}

	public function testCoalesceRejectsNonChipValues(): void
	{
		$svc = new SettingsService(
			$this->createMock(IDBConnection::class),
			$this->createMock(SecretStore::class),
			$this->createMock(MuteRegexValidator::class),
			$this->createMock(AccessService::class),
			$this->createMock(SsrfGuard::class),
			$this->createMock(AuditService::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
			$this->createMock(TopologyGuard::class),
			$this->createMock(ChannelTestProof::class),
		);
		$method = new ReflectionMethod(SettingsService::class, 'mergeAndValidate');
		$method->setAccessible(true);

		$this->expectException(\OCA\LogCheck\Exception\ValidationException::class);
		$method->invoke($svc, SettingsService::defaults(), [
			'coalesce_seconds' => 61,
		], 'admin', true);
	}

	public function testCoalesceAcceptsChipValues(): void
	{
		$svc = new SettingsService(
			$this->createMock(IDBConnection::class),
			$this->createMock(SecretStore::class),
			$this->createMock(MuteRegexValidator::class),
			$this->createMock(AccessService::class),
			$this->createMock(SsrfGuard::class),
			$this->createMock(AuditService::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
			$this->createMock(TopologyGuard::class),
			$this->createMock(ChannelTestProof::class),
		);
		$method = new ReflectionMethod(SettingsService::class, 'mergeAndValidate');
		$method->setAccessible(true);

		$out = $method->invoke($svc, SettingsService::defaults(), [
			'coalesce_seconds' => 3600,
		], 'admin', true);
		self::assertSame(3600, $out['coalesce_seconds']);
		self::assertSame(3600, $out['digest_window_seconds']);
	}

	public function testAccessChangeForbiddenForAppAdmin(): void
	{
		$svc = new SettingsService(
			$this->createMock(IDBConnection::class),
			$this->createMock(SecretStore::class),
			$this->createMock(MuteRegexValidator::class),
			$this->createMock(AccessService::class),
			$this->createMock(SsrfGuard::class),
			$this->createMock(AuditService::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
			$this->createMock(TopologyGuard::class),
			$this->createMock(ChannelTestProof::class),
		);
		$method = new ReflectionMethod(SettingsService::class, 'mergeAndValidate');
		$method->setAccessible(true);

		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('Only Nextcloud admins can change who can open LogCheck.');
		$method->invoke($svc, SettingsService::defaults(), [
			'access' => [
				'mode' => 'restricted',
				'app_admins' => ['eve'],
			],
		], 'alice', false);
	}

	public function testEnableSlackWithoutTestRejected(): void
	{
		$proof = $this->createMock(ChannelTestProof::class);
		$proof->method('isStateVerified')->willReturn(false);
		$proof->method('consumeUrl')->willReturn(false);
		$svc = new SettingsService(
			$this->createMock(IDBConnection::class),
			$this->createMock(SecretStore::class),
			$this->createMock(MuteRegexValidator::class),
			$this->createMock(AccessService::class),
			$this->createMock(SsrfGuard::class),
			$this->createMock(AuditService::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
			$this->createMock(TopologyGuard::class),
			$proof,
		);
		$method = new ReflectionMethod(SettingsService::class, 'mergeAndValidate');
		$method->setAccessible(true);
		$current = SettingsService::defaults();
		$current['channels']['slack']['webhook_url_cipher'] = 'cipher';

		$this->expectException(\OCA\LogCheck\Exception\ValidationException::class);
		$method->invoke($svc, $current, [
			'channels' => [
				'slack' => ['enabled' => true],
			],
		], 'admin', true);
	}

	public function testEnableSlackAllowedWhenPreVerified(): void
	{
		$secret = $this->createMock(SecretStore::class);
		$secret->method('encrypt')->willReturn('cipher');
		$ssrf = $this->createMock(SsrfGuard::class);
		$proof = $this->createMock(ChannelTestProof::class);
		$svc = new SettingsService(
			$this->createMock(IDBConnection::class),
			$secret,
			$this->createMock(MuteRegexValidator::class),
			$this->createMock(AccessService::class),
			$ssrf,
			$this->createMock(AuditService::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
			$this->createMock(TopologyGuard::class),
			$proof,
		);
		$method = new ReflectionMethod(SettingsService::class, 'mergeAndValidate');
		$method->setAccessible(true);
		$out = $method->invoke(
			$svc,
			SettingsService::defaults(),
			[
				'channels' => [
					'slack' => [
						'enabled' => true,
						'webhook_url' => 'https://1.1.1.1/hooks/x',
					],
				],
			],
			'admin',
			true,
			['slack']
		);
		self::assertTrue($out['channels']['slack']['enabled']);
		self::assertSame('cipher', $out['channels']['slack']['webhook_url_cipher']);
	}

	/**
	 * H1: changing URL while disabled must invalidate verified_at so re-enable
	 * without a new test is rejected.
	 */
	public function testEnableRejectedAfterUrlChangeWhileDisabled(): void
	{
		$secret = $this->createMock(SecretStore::class);
		$secret->method('encrypt')->willReturn('new-cipher');
		$proof = $this->createMock(ChannelTestProof::class);
		$proof->expects(self::once())->method('invalidateChannel')->with('slack');
		$proof->method('isStateVerified')->willReturn(false);
		$proof->method('consumeUrl')->willReturn(false);
		$svc = new SettingsService(
			$this->createMock(IDBConnection::class),
			$secret,
			$this->createMock(MuteRegexValidator::class),
			$this->createMock(AccessService::class),
			$this->createMock(SsrfGuard::class),
			$this->createMock(AuditService::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
			$this->createMock(TopologyGuard::class),
			$proof,
		);
		$method = new ReflectionMethod(SettingsService::class, 'mergeAndValidate');
		$method->setAccessible(true);
		$current = SettingsService::defaults();
		$current['channels']['slack']['webhook_url_cipher'] = 'old-cipher';

		// Step 1: change URL while disabled — invalidates proof, stays disabled
		$afterChange = $method->invoke($svc, $current, [
			'channels' => [
				'slack' => [
					'enabled' => false,
					'webhook_url' => 'https://1.1.1.1/hooks/new',
				],
			],
		], 'admin', true);
		self::assertFalse($afterChange['channels']['slack']['enabled']);
		self::assertSame('new-cipher', $afterChange['channels']['slack']['webhook_url_cipher']);

		// Step 2: enable without URL / without proof — must fail
		$proof2 = $this->createMock(ChannelTestProof::class);
		$proof2->method('isStateVerified')->willReturn(false);
		$proof2->method('consumeUrl')->willReturn(false);
		$svc2 = new SettingsService(
			$this->createMock(IDBConnection::class),
			$this->createMock(SecretStore::class),
			$this->createMock(MuteRegexValidator::class),
			$this->createMock(AccessService::class),
			$this->createMock(SsrfGuard::class),
			$this->createMock(AuditService::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
			$this->createMock(TopologyGuard::class),
			$proof2,
		);
		$method2 = new ReflectionMethod(SettingsService::class, 'mergeAndValidate');
		$method2->setAccessible(true);
		$this->expectException(\OCA\LogCheck\Exception\ValidationException::class);
		$method2->invoke($svc2, $afterChange, [
			'channels' => [
				'slack' => ['enabled' => true],
			],
		], 'admin', true);
	}

	public function testUrlChangeInvalidatesEvenWhenStaleVerifiedWouldHavePassed(): void
	{
		$secret = $this->createMock(SecretStore::class);
		$secret->method('encrypt')->willReturn('new-cipher');
		$proof = $this->createMock(ChannelTestProof::class);
		$proof->expects(self::once())->method('invalidateChannel')->with('webhook');
		// Stale verified would allow enable if we did not invalidate + require consumeUrl
		$proof->method('isStateVerified')->willReturn(true);
		$proof->method('consumeUrl')->willReturn(false);
		$svc = new SettingsService(
			$this->createMock(IDBConnection::class),
			$secret,
			$this->createMock(MuteRegexValidator::class),
			$this->createMock(AccessService::class),
			$this->createMock(SsrfGuard::class),
			$this->createMock(AuditService::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
			$this->createMock(TopologyGuard::class),
			$proof,
		);
		$method = new ReflectionMethod(SettingsService::class, 'mergeAndValidate');
		$method->setAccessible(true);
		$current = SettingsService::defaults();
		$current['channels']['webhook']['url_cipher'] = 'old';

		$this->expectException(\OCA\LogCheck\Exception\ValidationException::class);
		$method->invoke($svc, $current, [
			'channels' => [
				'webhook' => [
					'enabled' => true,
					'url' => 'https://1.1.1.1/hooks/evil',
				],
			],
		], 'admin', true);
	}

	public function testAppListRejectsTooManyEntries(): void
	{
		$svc = $this->svcForMerge();
		$method = new ReflectionMethod(SettingsService::class, 'mergeAndValidate');
		$method->setAccessible(true);
		$huge = [];
		for ($i = 0; $i < SettingsService::APP_LIST_MAX + 1; $i++) {
			$huge[] = 'app' . $i;
		}
		$this->expectException(\OCA\LogCheck\Exception\ValidationException::class);
		$this->expectExceptionMessage('Too many apps in the filter list.');
		$method->invoke($svc, SettingsService::defaults(), [
			'app_list' => $huge,
		], 'admin', true);
	}

	public function testAppListRejectsOversizedAppId(): void
	{
		$svc = $this->svcForMerge();
		$method = new ReflectionMethod(SettingsService::class, 'mergeAndValidate');
		$method->setAccessible(true);
		$this->expectException(\OCA\LogCheck\Exception\ValidationException::class);
		$method->invoke($svc, SettingsService::defaults(), [
			'app_list' => [str_repeat('a', SettingsService::APP_ID_MAX_LEN + 1)],
		], 'admin', true);
	}

	public function testExcerptsEnableForbiddenForAppAdmin(): void
	{
		$svc = $this->svcForMerge();
		$method = new ReflectionMethod(SettingsService::class, 'mergeAndValidate');
		$method->setAccessible(true);
		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('Only Nextcloud admins can change log excerpt settings.');
		$method->invoke($svc, SettingsService::defaults(), [
			'include_message_excerpts' => true,
			'excerpt_confirm' => 'CONFIRM',
		], 'alice', false);
	}

	public function testExcerptsDisableForbiddenForAppAdmin(): void
	{
		$svc = $this->svcForMerge();
		$method = new ReflectionMethod(SettingsService::class, 'mergeAndValidate');
		$method->setAccessible(true);
		$current = SettingsService::defaults();
		$current['include_message_excerpts'] = true;
		$this->expectException(ForbiddenException::class);
		$method->invoke($svc, $current, [
			'include_message_excerpts' => false,
		], 'alice', false);
	}

	public function testExcerptsEnableRequiresConfirmForNcAdmin(): void
	{
		$svc = $this->svcForMerge();
		$method = new ReflectionMethod(SettingsService::class, 'mergeAndValidate');
		$method->setAccessible(true);
		$this->expectException(\OCA\LogCheck\Exception\ValidationException::class);
		$method->invoke($svc, SettingsService::defaults(), [
			'include_message_excerpts' => true,
			'excerpt_confirm' => 'nope',
		], 'admin', true);
	}

	public function testEmailEnableRejectedWithoutTestProof(): void
	{
		$proof = $this->createMock(ChannelTestProof::class);
		$proof->method('isStateVerified')->willReturn(false);
		$proof->method('consumeUrl')->willReturn(false);
		$svc = new SettingsService(
			$this->createMock(IDBConnection::class),
			$this->createMock(SecretStore::class),
			$this->createMock(MuteRegexValidator::class),
			$this->createMock(AccessService::class),
			$this->createMock(SsrfGuard::class),
			$this->createMock(AuditService::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
			$this->createMock(TopologyGuard::class),
			$proof,
		);
		$method = new ReflectionMethod(SettingsService::class, 'mergeAndValidate');
		$method->setAccessible(true);
		$this->expectException(\OCA\LogCheck\Exception\ValidationException::class);
		$this->expectExceptionMessage('Send a successful test before turning this channel on.');
		$method->invoke($svc, SettingsService::defaults(), [
			'channels' => [
				'email' => [
					'enabled' => true,
					'recipients' => ['ops@example.com'],
				],
			],
		], 'admin', true);
	}

	public function testEmailEnableAllowedWithPreVerified(): void
	{
		$svc = $this->svcForMerge();
		$method = new ReflectionMethod(SettingsService::class, 'mergeAndValidate');
		$method->setAccessible(true);
		$out = $method->invoke($svc, SettingsService::defaults(), [
			'channels' => [
				'email' => [
					'enabled' => true,
					'recipients' => ['ops@example.com'],
				],
			],
		], 'admin', true, ['email']);
		self::assertTrue($out['channels']['email']['enabled']);
		self::assertSame(['ops@example.com'], $out['channels']['email']['recipients']);
	}

	public function testNotificationRecipientsRejectTooManyEntries(): void
	{
		$access = $this->createMock(AccessService::class);
		$access->method('entitledUids')->willReturn(array_map(static fn (int $i): string => 'u' . $i, range(0, 200)));
		$svc = new SettingsService(
			$this->createMock(IDBConnection::class),
			$this->createMock(SecretStore::class),
			$this->createMock(MuteRegexValidator::class),
			$access,
			$this->createMock(SsrfGuard::class),
			$this->createMock(AuditService::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
			$this->createMock(TopologyGuard::class),
			$this->createMock(ChannelTestProof::class),
		);
		$method = new ReflectionMethod(SettingsService::class, 'mergeAndValidate');
		$method->setAccessible(true);
		$uids = array_map(static fn (int $i): string => 'u' . $i, range(0, SettingsService::NOTIFICATION_RECIPIENTS_MAX + 1));
		$this->expectException(\OCA\LogCheck\Exception\ValidationException::class);
		$this->expectExceptionMessage('Too many notification recipients.');
		$method->invoke($svc, SettingsService::defaults(), [
			'channels' => [
				'notification' => [
					'enabled' => true,
					'recipient_uids' => $uids,
				],
			],
		], 'admin', true);
	}

	private function svcForMerge(): SettingsService
	{
		return new SettingsService(
			$this->createMock(IDBConnection::class),
			$this->createMock(SecretStore::class),
			$this->createMock(MuteRegexValidator::class),
			$this->createMock(AccessService::class),
			$this->createMock(SsrfGuard::class),
			$this->createMock(AuditService::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
			$this->createMock(TopologyGuard::class),
			$this->createMock(ChannelTestProof::class),
		);
	}
}
