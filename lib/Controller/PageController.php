<?php

declare(strict_types=1);

namespace OCA\LogCheck\Controller;

use OCA\LogCheck\AppInfo\Application;
use OCA\LogCheck\Service\AccessService;
use OCA\LogCheck\Service\Health\HealthDashboardService;
use OCA\LogCheck\Service\LogFileService;
use OCA\LogCheck\Service\SettingsSectionCatalog;
use OCA\LogCheck\Service\SettingsService;
use OCA\LogCheck\Service\StatusService;
use OCA\LogCheck\Support\SupportUsLinks;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\IL10N;
use OCP\Util;

class PageController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly IURLGenerator $urlGenerator,
		private readonly IUserSession $userSession,
		private readonly IUserManager $userManager,
		private readonly IL10N $l10n,
		private readonly AccessService $accessService,
		private readonly StatusService $statusService,
		private readonly HealthDashboardService $healthDashboard,
		private readonly SettingsService $settingsService,
		private readonly SettingsSectionCatalog $sectionCatalog,
		private readonly SupportUsLinks $supportUsLinks,
		private readonly LogFileService $logFileService,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse
	{
		return $this->home();
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function home(): TemplateResponse
	{
		$this->registerAssets();
		$user = $this->userSession->getUser();
		$uid = $user?->getUID() ?? '';
		$status = $this->statusService->getStatus();
		$dashboard = $this->healthDashboard->dashboard();
		$healthCards = $dashboard['cards'];
		$dto = $this->settingsService->toUiDto();
		$email = $user?->getEMailAddress() ?? '';
		$isNcAdmin = $this->accessService->isNcAdmin($uid);
		$isAppAdmin = $this->accessService->isAppAdmin($uid);

		return $this->render('home', [
			'pageId' => 'home',
			'pageTitle' => $this->l10n->t('Health'),
			'pageHelp' => $this->l10n->t('Instance health at a glance — logs, jobs, disk, and more.'),
			'hideScopeStrip' => true,
			'roleLabel' => $isNcAdmin
				? $this->l10n->t('Nextcloud admin')
				: ($isAppAdmin ? $this->l10n->t('App admin') : $this->l10n->t('Member')),
			'status' => $status,
			'healthCards' => $healthCards,
			'healthSummaryState' => $dashboard['summary_state'],
			'healthSummaryLabel' => $dashboard['summary_label'],
			'settings' => $dto['settings'],
			'settingsVersion' => $dto['version'],
			'prefillEmail' => $email,
			'isNcAdmin' => $isNcAdmin,
			'isAppAdmin' => $isAppAdmin,
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function logs(): TemplateResponse
	{
		$this->registerAssets('logs');
		$user = $this->userSession->getUser();
		$uid = $user?->getUID() ?? '';
		$isNcAdmin = $this->accessService->isNcAdmin($uid);
		$isAppAdmin = $this->accessService->isAppAdmin($uid);
		$meta = $this->logFileService->meta($isNcAdmin);
		$logFiles = ['live' => (string)($meta['name'] ?? ''), 'files' => []];
		try {
			if (!empty($meta['backend_supported'])) {
				$logFiles = $this->logFileService->listFiles();
			}
		} catch (\Throwable) {
			// Meta still renders; file picker fills from API when possible.
		}

		return $this->render('logs', [
			'pageId' => 'logs',
			'pageTitle' => $this->l10n->t('Logs'),
			'pageHelp' => $this->l10n->t('Read the log, search, copy lines, or start a fresh file after you fix errors.'),
			'roleLabel' => $isNcAdmin
				? $this->l10n->t('Nextcloud admin')
				: ($isAppAdmin ? $this->l10n->t('App admin') : $this->l10n->t('Member')),
			'isNcAdmin' => $isNcAdmin,
			'isAppAdmin' => $isAppAdmin,
			'logMeta' => $meta,
			'logFiles' => $logFiles,
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settingsIndex(): RedirectResponse
	{
		return new RedirectResponse(
			$this->urlGenerator->linkToRoute('logcheck.page.settings', ['section' => SettingsSectionCatalog::DEFAULT_SECTION])
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settings(string $section): TemplateResponse
	{
		if (!$this->sectionCatalog->isSection($section)) {
			return new TemplateResponse('core', '404', [], 'guest');
		}
		$this->registerAssets('settings');
		$user = $this->userSession->getUser();
		$uid = $user?->getUID() ?? '';
		$dto = $this->settingsService->toUiDto();
		$status = $this->statusService->getStatus();
		$lang = $this->l10n->getLanguageCode();
		$isNcAdmin = $this->accessService->isNcAdmin($uid);
		$isAppAdmin = $this->accessService->isAppAdmin($uid);
		$adminPeople = [];
		foreach (($dto['settings']['access']['app_admins'] ?? []) as $adminUid) {
			if (!is_string($adminUid) || $adminUid === '') {
				continue;
			}
			$userObj = $this->userManager->get($adminUid);
			$adminPeople[] = [
				'uid' => $adminUid,
				'displayName' => $userObj?->getDisplayName() ?? $adminUid,
			];
		}

		return $this->render('settings', [
			'pageId' => 'settings',
			'pageTitle' => $this->sectionCatalog->label($this->l10n, $section),
			'pageHelp' => $this->sectionCatalog->help($this->l10n, $section),
			'roleLabel' => $isNcAdmin
				? $this->l10n->t('Nextcloud admin')
				: ($isAppAdmin ? $this->l10n->t('App admin') : $this->l10n->t('Member')),
			'settingsSection' => $section,
			'settings' => $dto['settings'],
			'settingsVersion' => $dto['version'],
			'status' => $status,
			'isNcAdmin' => $isNcAdmin,
			'isAppAdmin' => $isAppAdmin,
			'supportLinks' => $this->supportUsLinks->forLocale($lang),
			'sections' => SettingsSectionCatalog::SECTIONS,
			'sectionCatalog' => $this->sectionCatalog,
			'adminPeople' => $adminPeople,
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function legacyStatus(): RedirectResponse
	{
		return new RedirectResponse($this->urlGenerator->linkToRoute('logcheck.page.home'));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function legacyChannels(): RedirectResponse
	{
		return new RedirectResponse($this->urlGenerator->linkToRoute('logcheck.page.settings', ['section' => 'alerts']));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function legacyWatch(): RedirectResponse
	{
		return new RedirectResponse($this->urlGenerator->linkToRoute('logcheck.page.settings', ['section' => 'rules']));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function legacyAccess(): RedirectResponse
	{
		return new RedirectResponse($this->urlGenerator->linkToRoute('logcheck.page.settings', ['section' => 'people']));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function legacyPrivacy(): RedirectResponse
	{
		return new RedirectResponse($this->urlGenerator->linkToRoute('logcheck.page.settings', ['section' => 'alerts']) . '#lck-more-options');
	}

	/** @param array<string, mixed> $params */
	private function render(string $template, array $params): TemplateResponse
	{
		$params['urls'] = $this->urls();
		$params['l'] = $this->l10n;
		$params['clientHints'] = [
			'locale' => $this->l10n->getLocaleCode(),
			'htmlLang' => str_replace('_', '-', $this->l10n->getLanguageCode()),
			'timezone' => date_default_timezone_get() ?: 'UTC',
		];
		return new TemplateResponse(Application::APP_ID, $template, $params);
	}

	/** @return array<string, string> */
	private function urls(): array
	{
		return [
			'home' => $this->urlGenerator->linkToRoute('logcheck.page.home'),
			'logs' => $this->urlGenerator->linkToRoute('logcheck.page.logs'),
			'alerts' => $this->urlGenerator->linkToRoute('logcheck.page.settings', ['section' => 'alerts']),
			'rules' => $this->urlGenerator->linkToRoute('logcheck.page.settings', ['section' => 'rules']),
			'people' => $this->urlGenerator->linkToRoute('logcheck.page.settings', ['section' => 'people']),
			'support' => $this->urlGenerator->linkToRoute('logcheck.page.settings', ['section' => 'support']),
			'apiStatus' => $this->urlGenerator->linkToRoute('logcheck.api.getStatus'),
			'apiSettings' => $this->urlGenerator->linkToRoute('logcheck.api.getSettings'),
			'apiSave' => $this->urlGenerator->linkToRoute('logcheck.api.saveSettings'),
			'apiTurnOn' => $this->urlGenerator->linkToRoute('logcheck.api.turnOnAlerts'),
			'apiRun' => $this->urlGenerator->linkToRoute('logcheck.api.runNow'),
			'apiDirectory' => $this->urlGenerator->linkToRoute('logcheck.api.searchDirectory'),
			'apiLogMeta' => $this->urlGenerator->linkToRoute('logcheck.api.getLogMeta'),
			'apiLogFiles' => $this->urlGenerator->linkToRoute('logcheck.api.listLogFiles'),
			'apiLogTail' => $this->urlGenerator->linkToRoute('logcheck.api.getLogTail'),
			'apiLogBefore' => $this->urlGenerator->linkToRoute('logcheck.api.getLogBefore'),
			'apiLogDownload' => $this->urlGenerator->linkToRoute('logcheck.api.downloadLog'),
			'apiLogSearch' => $this->urlGenerator->linkToRoute('logcheck.api.searchLog'),
			'apiLogStartFresh' => $this->urlGenerator->linkToRoute('logcheck.api.startFreshLog'),
			'apiLogDelete' => $this->urlGenerator->linkToRoute('logcheck.api.deleteLog'),
			'apiLogDeleteCopy' => $this->urlGenerator->linkToRoute('logcheck.api.deleteLogCopy'),
		];
	}

	private function registerAssets(string $extra = ''): void
	{
		Util::addStyle(Application::APP_ID, 'common/tokens');
		Util::addStyle(Application::APP_ID, 'common/shell-chrome');
		Util::addStyle(Application::APP_ID, 'common/app-layout');
		Util::addStyle(Application::APP_ID, 'common/navigation');
		Util::addStyle(Application::APP_ID, 'common/form-controls');
		Util::addStyle(Application::APP_ID, 'common/page-patterns');
		Util::addStyle(Application::APP_ID, 'common/notification-surfaces');
		Util::addStyle(Application::APP_ID, 'common/dialogs');
		Util::addStyle(Application::APP_ID, 'common/switch-field');
		Util::addStyle(Application::APP_ID, 'common/badges');
		Util::addStyle(Application::APP_ID, 'app');
		Util::addScript(Application::APP_ID, 'common/toasts');
		Util::addScript(Application::APP_ID, 'common/app-feedback');
		Util::addScript(Application::APP_ID, 'app');
		if ($extra === 'settings') {
			Util::addScript(Application::APP_ID, 'settings');
		}
		if ($extra === 'logs') {
			Util::addScript(Application::APP_ID, 'logs');
		}
	}
}
