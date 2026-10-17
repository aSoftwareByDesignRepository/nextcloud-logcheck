<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\AppInfo;

use OCA\LogCheck\AppInfo\Application;
use OCA\LogCheck\Middleware\EntitlementMiddleware;
use OCA\LogCheck\Notification\Notifier;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\IAppContainer;
use OCP\INavigationManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Critic MF: invoke Application::register and ::boot (not factory source-parse theater).
 */
final class ApplicationBootstrapInvokeTest extends TestCase
{
	public function testRegisterInvokesRegistrationApis(): void
	{
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects(self::once())->method('registerMiddleware')->with(EntitlementMiddleware::class);
		$context->expects(self::once())->method('registerNotifierService')->with(Notifier::class);
		$context->expects(self::atLeast(4))->method('registerService');

		$app = new Application();
		$app->register($context);
	}

	public function testBootRegistersNavGateClosure(): void
	{
		$nav = $this->createMock(INavigationManager::class);
		$nav->expects(self::once())->method('add')->with(self::isInstanceOf(\Closure::class));

		$container = $this->createMock(IAppContainer::class);
		$container->method('get')->willReturnMap([
			[INavigationManager::class, $nav],
		]);

		$boot = $this->createMock(IBootContext::class);
		$boot->expects(self::once())->method('getAppContainer')->willReturn($container);

		(new Application())->boot($boot);
	}

	public function testNavGateClosureHidesEntryForAnonymous(): void
	{
		$captured = null;
		$nav = $this->createMock(INavigationManager::class);
		$nav->method('add')->willReturnCallback(static function (\Closure $entry) use (&$captured): void {
			$captured = $entry;
		});

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$container = $this->createMock(IAppContainer::class);
		$container->method('get')->willReturnMap([
			[INavigationManager::class, $nav],
			[IUserSession::class, $session],
		]);

		$boot = $this->createMock(IBootContext::class);
		$boot->method('getAppContainer')->willReturn($container);
		(new Application())->boot($boot);

		self::assertInstanceOf(\Closure::class, $captured);
		$entry = $captured();
		self::assertSame('logcheck', $entry['id']);
		self::assertSame('hidden', $entry['type']);
	}
}
