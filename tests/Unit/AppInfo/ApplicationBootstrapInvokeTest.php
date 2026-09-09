<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\AppInfo;

use OCA\LogCheck\AppInfo\Application;
use OCA\LogCheck\Middleware\EntitlementMiddleware;
use OCA\LogCheck\Notification\Notifier;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
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

	public function testBootIsInvokableNoOp(): void
	{
		$boot = $this->createMock(IBootContext::class);
		$boot->expects(self::never())->method(self::anything());

		$app = new Application();
		$app->boot($boot);
		self::assertTrue(true);
	}
}
