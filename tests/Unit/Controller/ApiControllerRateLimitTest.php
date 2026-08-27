<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Controller;

use OCA\LogCheck\Controller\ApiController;
use OCA\LogCheck\Exception\ValidationException;
use OCA\LogCheck\Service\AccessService;
use OCA\LogCheck\Service\AuditService;
use OCA\LogCheck\Service\ChannelDispatcher;
use OCA\LogCheck\Service\ChannelStateStore;
use OCA\LogCheck\Service\ChannelTestProof;
use OCA\LogCheck\Service\CursorStore;
use OCA\LogCheck\Service\LeaseService;
use OCA\LogCheck\Service\LogBackendService;
use OCA\LogCheck\Service\LogFileService;
use OCA\LogCheck\Service\PayloadBuilder;
use OCA\LogCheck\Service\SettingsService;
use OCA\LogCheck\Service\StatusService;
use OCA\LogCheck\Service\WatchRunner;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IMemcache;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Argus MF: test-send budget is consumed before outbound I/O (atomic add only).
 */
class ApiControllerRateLimitTest extends TestCase
{
	/** @return IMemcache */
	private function atomicCache(): IMemcache
	{
		$cache = $this->createMock(IMemcache::class);
		$store = [];
		$cache->method('add')->willReturnCallback(static function (string $key, $value, $ttl = 0) use (&$store) {
			unset($ttl, $value);
			if (array_key_exists($key, $store)) {
				return false;
			}
			$store[$key] = '1';
			return true;
		});
		return $cache;
	}

	private function controller(ICache $cache): ApiController
	{
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		return new ApiController(
			$this->createMock(IRequest::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IUserManager::class),
			$this->createMock(AccessService::class),
			$this->createMock(StatusService::class),
			$this->createMock(SettingsService::class),
			$this->createMock(ChannelDispatcher::class),
			$this->createMock(ChannelStateStore::class),
			$this->createMock(PayloadBuilder::class),
			$this->createMock(LogBackendService::class),
			$this->createMock(CursorStore::class),
			$this->createMock(LeaseService::class),
			$this->createMock(WatchRunner::class),
			$factory,
			$this->createMock(ChannelTestProof::class),
			$this->createMock(\OCP\IL10N::class),
			$this->createMock(LogFileService::class),
			$this->createMock(AuditService::class),
		);
	}

	public function testConsumeTestRateBlocksSecondCall(): void
	{
		$ctrl = $this->controller($this->atomicCache());
		$method = new ReflectionMethod(ApiController::class, 'consumeTestRate');
		$method->setAccessible(true);
		$method->invoke($ctrl, 'admin');
		$this->expectException(ValidationException::class);
		$method->invoke($ctrl, 'admin');
	}

	public function testConsumeRunRateIsSeparateFromTestRate(): void
	{
		$ctrl = $this->controller($this->atomicCache());
		$consume = new ReflectionMethod(ApiController::class, 'consumeRate');
		$consume->setAccessible(true);
		$consume->invoke($ctrl, 'admin', 'test:', 30);
		$consume->invoke($ctrl, 'admin', 'run:', 10);
		$this->addToAssertionCount(1);
		$this->expectException(ValidationException::class);
		$consume->invoke($ctrl, 'admin', 'run:', 10);
	}

	public function testConsumeRateFailsClosedWithoutAtomicAdd(): void
	{
		$cache = $this->createMock(ICache::class);
		$ctrl = $this->controller($cache);
		$consume = new ReflectionMethod(ApiController::class, 'consumeRate');
		$consume->setAccessible(true);
		$this->expectException(ValidationException::class);
		$consume->invoke($ctrl, 'admin', 'test:', 30);
	}
}
