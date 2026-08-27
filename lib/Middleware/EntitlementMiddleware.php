<?php

declare(strict_types=1);

namespace OCA\LogCheck\Middleware;

use OCA\LogCheck\Controller\ApiController;
use OCA\LogCheck\Controller\PageController;
use OCA\LogCheck\Exception\ForbiddenException;
use OCA\LogCheck\Service\AccessService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Middleware;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

final class EntitlementMiddleware extends Middleware
{
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly AccessService $accessService,
		private readonly IURLGenerator $urlGenerator,
		private readonly IRequest $request,
	) {
	}

	public function beforeController($controller, string $methodName): void
	{
		if (!$controller instanceof PageController && !$controller instanceof ApiController) {
			return;
		}
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new ForbiddenException('Not authorized.');
		}
		$this->accessService->assertEntitled($user->getUID());
	}

	public function afterException($controller, string $methodName, \Exception $exception): Response
	{
		if (!$exception instanceof ForbiddenException) {
			throw $exception;
		}
		if ($controller instanceof ApiController || str_starts_with($this->request->getPathInfo() ?? '', '/apps/logcheck/api')) {
			return new JSONResponse(['error' => 'LCK_FORBIDDEN', 'message' => 'Not authorized.'], Http::STATUS_FORBIDDEN);
		}
		return new TemplateResponse('logcheck', 'access-denied', [
			'homeUrl' => $this->urlGenerator->linkToRoute('files.view.index'),
		], 'user');
	}
}
