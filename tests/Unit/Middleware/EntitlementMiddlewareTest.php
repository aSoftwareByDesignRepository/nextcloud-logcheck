<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Middleware;

use OCA\LogCheck\Controller\ApiController;
use OCA\LogCheck\Controller\PageController;
use OCA\LogCheck\Exception\ForbiddenException;
use OCA\LogCheck\Middleware\EntitlementMiddleware;
use OCA\LogCheck\Service\AccessService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Momos: door checks are not optional — every Page/Api entry must fail closed.
 */
class EntitlementMiddlewareTest extends TestCase
{
	private function middleware(AccessService $access, ?IUser $user, string $pathInfo = '/apps/logcheck/api/status'): EntitlementMiddleware
	{
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn($pathInfo);
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRoute')->willReturn('/apps/files/');
		return new EntitlementMiddleware($session, $access, $url, $request);
	}

	public function testUnauthenticatedApiThrowsForbidden(): void
	{
		$access = $this->createMock(AccessService::class);
		$access->expects(self::never())->method('assertEntitled');
		$api = $this->createMock(ApiController::class);
		$mw = $this->middleware($access, null);
		$this->expectException(ForbiddenException::class);
		$mw->beforeController($api, 'getStatus');
	}

	public function testNonEntitledUserThrowsForbidden(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$access = $this->createMock(AccessService::class);
		$access->expects(self::once())->method('assertEntitled')->with('bob')
			->willThrowException(new ForbiddenException('Not authorized.'));
		$api = $this->createMock(ApiController::class);
		$mw = $this->middleware($access, $user);
		$this->expectException(ForbiddenException::class);
		$mw->beforeController($api, 'getSettings');
	}

	public function testEntitledUserPasses(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$access = $this->createMock(AccessService::class);
		$access->expects(self::once())->method('assertEntitled')->with('admin');
		$page = $this->createMock(PageController::class);
		$mw = $this->middleware($access, $user, '/apps/logcheck/home');
		$mw->beforeController($page, 'home');
		// Entitlement ran once for the entitled UID — that is the real assertion (expectException would fail the negative cases).
		self::assertSame('admin', $user->getUID());
	}

	public function testIgnoresNonHealthCheckControllers(): void
	{
		$access = $this->createMock(AccessService::class);
		$access->expects(self::never())->method('assertEntitled');
		$other = $this->createMock(Controller::class);
		$mw = $this->middleware($access, null);
		$mw->beforeController($other, 'index');
		// Never() on assertEntitled is the assertion; keep a type probe so the test can fail if the controller mock is wrong.
		self::assertInstanceOf(Controller::class, $other);
	}

	public function testAfterExceptionReturnsJsonForApi(): void
	{
		$access = $this->createMock(AccessService::class);
		$api = $this->createMock(ApiController::class);
		$mw = $this->middleware($access, null, '/apps/logcheck/api/status');
		$res = $mw->afterException($api, 'getStatus', new ForbiddenException('Not authorized.'));
		self::assertInstanceOf(JSONResponse::class, $res);
		self::assertSame(403, $res->getStatus());
		self::assertSame('LCK_FORBIDDEN', $res->getData()['error']);
	}

	public function testAfterExceptionReturnsTemplateForPage(): void
	{
		$access = $this->createMock(AccessService::class);
		$page = $this->createMock(PageController::class);
		$mw = $this->middleware($access, null, '/apps/logcheck/home');
		$res = $mw->afterException($page, 'home', new ForbiddenException('Not authorized.'));
		self::assertInstanceOf(TemplateResponse::class, $res);
	}
}
