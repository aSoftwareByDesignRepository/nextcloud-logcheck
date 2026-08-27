<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Service;

use OCA\LogCheck\Exception\ValidationException;
use OCA\LogCheck\Service\AccessService;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class AccessServiceTest extends TestCase
{
	public function testOpenAccessRejected(): void
	{
		$svc = new AccessService(
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IDBConnection::class),
		);
		$this->expectException(ValidationException::class);
		$svc->normalizeAccess(['mode' => 'open', 'app_admins' => []]);
	}

	public function testRestrictedAccepted(): void
	{
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturn(true);
		$svc = new AccessService(
			$this->createMock(IGroupManager::class),
			$users,
			$this->createMock(IDBConnection::class),
		);
		$out = $svc->normalizeAccess(['mode' => 'restricted', 'app_admins' => ['alice']]);
		self::assertSame('restricted', $out['mode']);
		self::assertSame(['alice'], $out['app_admins']);
	}

	public function testTooManyAppAdminsRejected(): void
	{
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturn(true);
		$svc = new AccessService(
			$this->createMock(IGroupManager::class),
			$users,
			$this->createMock(IDBConnection::class),
		);
		$admins = [];
		for ($i = 0; $i < AccessService::APP_ADMINS_MAX + 1; $i++) {
			$admins[] = 'u' . $i;
		}
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('Too many app admins.');
		$svc->normalizeAccess(['mode' => 'restricted', 'app_admins' => $admins]);
	}
}
