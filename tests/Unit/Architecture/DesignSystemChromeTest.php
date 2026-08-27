<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Design-system chrome contract — HealthCheck must match AZC/DeskCheck shell patterns.
 */
class DesignSystemChromeTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname(__DIR__, 3);
	}

	public function testPageStartHasHeaderIconScopeStripAndMainLabel(): void
	{
		$src = (string)file_get_contents($this->root . '/templates/common/page-start.php');
		self::assertStringContainsString('lck-page-header__main', $src);
		self::assertStringContainsString('lck-page-header__icon', $src);
		self::assertStringContainsString('lck-scope-strip', $src);
		self::assertStringContainsString('aria-labelledby="lck-page-title"', $src);
		self::assertStringContainsString('lck-breadcrumb__item--current', $src);
		self::assertStringContainsString('aria-current="page"', $src);
	}

	public function testNavigationHasSkipLinkAndFeedbackFooter(): void
	{
		$nav = (string)file_get_contents($this->root . '/templates/common/navigation.php');
		self::assertStringContainsString('lck-skip-link--nav', $nav);
		self::assertStringContainsString('feedback-nav-footer.php', $nav);
		self::assertStringContainsString('lck-brand', $nav);
		self::assertStringContainsString('lck-nav__link', $nav);
		self::assertStringContainsString('IconCatalog::render', $nav);
		self::assertFileExists($this->root . '/templates/parts/feedback-nav-footer.php');
		self::assertFileExists($this->root . '/js/common/app-feedback.js');
		self::assertFileExists($this->root . '/lib/Support/AppFeedbackLinks.php');
		self::assertFileExists($this->root . '/lib/Service/IconCatalog.php');
		self::assertFileExists($this->root . '/css/common/navigation.css');
	}

	public function testAccessDeniedIsMinimalShellWithAlert(): void
	{
		$src = (string)file_get_contents($this->root . '/templates/access-denied.php');
		self::assertStringContainsString('role="alert"', $src);
		self::assertStringContainsString('Back to Nextcloud', $src);
		self::assertStringContainsString('lck-skip-link', $src);
		self::assertStringContainsString('lck-live-region', $src);
		self::assertStringNotContainsString('app-navigation', $src);
	}

	public function testAlertsUsesMoreOptionsNotAdvancedSummary(): void
	{
		$src = (string)file_get_contents($this->root . '/templates/parts/settings/alerts.php');
		self::assertStringContainsString("\$l->t('More options')", $src);
		self::assertStringNotContainsString("\$l->t('Advanced')", $src);
		self::assertStringContainsString('lck-switch-field__track', $src);
		self::assertStringContainsString('role="switch"', $src);
	}

	public function testHomeWatchingUsesTrackSwitch(): void
	{
		$src = (string)file_get_contents($this->root . '/templates/home.php');
		self::assertStringContainsString('lck-switch-field__track', $src);
		self::assertStringContainsString('role="switch"', $src);
		self::assertStringContainsString('lck-status-card', $src);
		self::assertStringContainsString('id="lck-check-again"', $src);
		self::assertStringNotContainsString('data-lck-action="check-again"', $src);
		self::assertStringContainsString('Watch log file', $src);
		self::assertStringContainsString('lck-alerts-checklist', $src);
		self::assertStringContainsString('id="lck-alerts-checklist"', $src);
		self::assertStringNotContainsString('lck-topology-note', $src);
		self::assertStringNotContainsString('lck-summary-title', $src);
		self::assertStringNotContainsString('lck-setup-title', $src);
		$js = (string)file_get_contents($this->root . '/js/app.js');
		self::assertStringContainsString('lck-check-again', $js);
		self::assertStringContainsString('refreshHomeStatus', $js);
		self::assertStringContainsString('applyHomeStatus', $js);
		$feedback = (string)file_get_contents($this->root . '/js/common/app-feedback.js');
		self::assertStringContainsString('LogCheckToasts', $feedback);
	}

	public function testWatchToggleAndSaveAvoidFullReload(): void
	{
		$app = (string)file_get_contents($this->root . '/js/app.js');
		self::assertStringContainsString('await refreshHomeStatus()', $app);
		self::assertStringNotContainsString("LogCheckToasts.showSuccess(toggle.checked ? t('logcheck', 'Watching') : t('logcheck', 'Off'));\n\t\t\t\t\twindow.location.reload();", $app);

		$settings = (string)file_get_contents($this->root . '/js/settings.js');
		self::assertStringNotContainsString("LogCheckToasts.showSuccess(t('logcheck', 'Saved.'));\n\t\t\t\twindow.location.reload();", $settings);
		self::assertStringContainsString('App.setSettingsVersion', $settings);

		$support = (string)file_get_contents($this->root . '/templates/parts/settings/support.php');
		self::assertStringNotContainsString('Several servers each with their own log file are not supported', $support);
	}

	public function testAlertsReenableForAllOutboundChannels(): void
	{
		$src = (string)file_get_contents($this->root . '/templates/parts/settings/alerts.php');
		self::assertStringContainsString('data-channel="email"', $src);
		self::assertStringContainsString('data-channel="slack"', $src);
		self::assertStringContainsString('data-channel="webhook"', $src);
		self::assertSame(3, substr_count($src, 'lck-reenable-channel'));
		self::assertStringContainsString('lck-test-turn-on', $src);
		self::assertStringContainsString('Send test & turn on', $src);
	}

	public function testRadiusTokensBindToNextcloudWithDesignSystemFallbacks(): void
	{
		$tokens = (string)file_get_contents($this->root . '/css/common/tokens.css');
		// Theme-native radii + design-system numeric fallbacks (6 / 12 / 16).
		self::assertStringContainsString('--lck-radius-sm: var(--border-radius, 6px)', $tokens);
		self::assertStringContainsString('--lck-radius-md: var(--border-radius-element, var(--border-radius-large, 12px))', $tokens);
		self::assertStringContainsString('--lck-radius-lg: var(--border-radius-large, 16px)', $tokens);
		self::assertStringContainsString('--lck-radius-pill: var(--border-radius-pill, 999px)', $tokens);
	}

	public function testNoDeskCheckMainIdLeftovers(): void
	{
		$files = array_merge(
			glob($this->root . '/css/*.css') ?: [],
			glob($this->root . '/css/common/*.css') ?: []
		);
		self::assertNotEmpty($files);
		foreach ($files as $path) {
			$blob = (string)file_get_contents($path);
			self::assertStringNotContainsString('#dc-main-content', $blob, basename($path));
		}
	}

	public function testPageControllerRegistersFeedbackAndSwitchAssets(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Controller/PageController.php');
		self::assertStringContainsString("addScript(Application::APP_ID, 'common/app-feedback')", $src);
		self::assertStringContainsString("addStyle(Application::APP_ID, 'common/switch-field')", $src);
		self::assertStringContainsString("addStyle(Application::APP_ID, 'common/badges')", $src);
		self::assertStringContainsString("addStyle(Application::APP_ID, 'common/navigation')", $src);
		self::assertStringContainsString('roleLabel', $src);
	}

	public function testSwitchFieldCssBeatsFormCheckboxAndLabelRules(): void
	{
		$switch = (string)file_get_contents($this->root . '/css/common/switch-field.css');
		$form = (string)file_get_contents($this->root . '/css/common/form-controls.css');
		$app = (string)file_get_contents($this->root . '/css/app.css');
		// Two-ID scope so track-switch rules win over form-controls checkbox sizing.
		self::assertStringContainsString(
			'#content.app-logcheck #app-content.lck-app .lck-switch-field__track',
			$switch
		);
		self::assertStringContainsString(
			'input[type="checkbox"]:not(.lck-switch-field__input)',
			$form
		);
		self::assertStringContainsString(
			'.lck-form label:not(.lck-switch-field__label)',
			$app
		);
		// Stale class names must not reappear (they never matched markup).
		self::assertStringNotContainsString('.lck-switch__input', $form);
	}

	public function testAppShellUsesGridSideBySideNotStackedFlex(): void
	{
		$layout = (string)file_get_contents($this->root . '/css/common/app-layout.css');
		self::assertStringContainsString('display: grid !important', $layout);
		self::assertStringContainsString('grid-template-columns: var(--lck-nav-width) minmax(0, 1fr)', $layout);
		self::assertStringContainsString('flex-basis: 0% !important', $layout);
		self::assertStringContainsString('#content.app-logcheck > #app-navigation.lck-nav', $layout);
		self::assertStringContainsString('grid-column: 2', $layout);
		// Must not regress to column flex on #content (stacks main under sidebar).
		self::assertDoesNotMatchRegularExpression(
			'/#content\.app-logcheck\s*\{[^}]*flex-direction:\s*column/s',
			$layout
		);
	}

	public function testNavigationDefersToNextcloudSnapDrawer(): void
	{
		$nav = (string)file_get_contents($this->root . '/css/common/navigation.css');
		// Custom open class / forced translate fights NC body.snapjs-left.
		self::assertStringNotContainsString('lck-nav--open', $nav);
		self::assertStringNotContainsString('translateX(-105%)', $nav);
		self::assertStringContainsString('snapjs-left', $nav);
		self::assertStringContainsString('@media (min-width: 1024px)', $nav);
	}

	public function testLogsNavAndPageExist(): void
	{
		$nav = (string)file_get_contents($this->root . '/templates/common/navigation.php');
		self::assertStringContainsString("'logs'", $nav);
		self::assertStringContainsString("\$l->t('Logs')", $nav);
		self::assertFileExists($this->root . '/templates/logs.php');
		self::assertFileExists($this->root . '/js/logs.js');
		$logs = (string)file_get_contents($this->root . '/templates/logs.php');
		self::assertStringContainsString('lck-logs-viewer', $logs);
		self::assertStringContainsString('role="log"', $logs);
		self::assertStringContainsString('lck-logs-filter-chips', $logs);
		self::assertStringContainsString('lck-logs-loading', $logs);
		self::assertStringContainsString('lck-logs-more-menu', $logs);
		self::assertStringContainsString('lck-logs-viewer-raw', $logs);
		self::assertStringContainsString('lck-logs-load-older-row', $logs);
		self::assertStringContainsString("\$l->t('Danger zone')", $logs);
		self::assertStringContainsString('lck-logs-download', $logs);
		self::assertStringNotContainsString('id="lck-logs-download" href=', $logs);
		// Full-file download is NC-admin only (same gate as remove-copy / start-fresh).
		self::assertMatchesRegularExpression(
			'/if\s*\(\s*\$isNcAdmin\s*\)\s*:\s*.*?id="lck-logs-download"/s',
			$logs,
			'Download full file must be wrapped in isNcAdmin'
		);
		self::assertStringContainsString('lck-logs-file-list', $logs);
		self::assertStringContainsString('Which log?', $logs);
		self::assertStringContainsString('role="radiogroup"', $logs);
		self::assertStringNotContainsString('lck-logs-meta', $logs);
		$page = (string)file_get_contents($this->root . '/lib/Controller/PageController.php');
		self::assertStringContainsString("addScript(Application::APP_ID, 'logs')", $page);
		self::assertStringContainsString('apiLogFiles', $page);
		self::assertStringContainsString('apiLogBefore', $page);
		self::assertStringContainsString('apiLogDownload', $page);
		self::assertStringContainsString('apiLogDeleteCopy', $page);
		$routes = (string)file_get_contents($this->root . '/appinfo/routes.php');
		self::assertStringContainsString('page#logs', $routes);
		self::assertStringContainsString('/api/logs/tail', $routes);
		self::assertStringContainsString('/api/logs/files', $routes);
		self::assertStringContainsString('/api/logs/before', $routes);
		self::assertStringContainsString('/api/logs/download', $routes);
		self::assertMatchesRegularExpression(
			"/\\['name'\\s*=>\\s*'api#downloadLog'[^\\]]*'verb'\\s*=>\\s*'POST'\\]/s",
			$routes,
			'download must be CSRF-bound POST',
		);
		self::assertStringContainsString('/api/logs/delete-copy', $routes);
		$js = (string)file_get_contents($this->root . '/js/logs.js');
		self::assertStringContainsString('withFileParam', $js);
		self::assertStringContainsString('apiLogDeleteCopy', $js);
		self::assertStringContainsString('apiLogBefore', $js);
		self::assertStringContainsString('lck-logs-load-older', $js);
		self::assertStringContainsString('viewer_min_level', $js);
		self::assertStringContainsString('lck-logs-viewer-min-level', $js);
		self::assertStringContainsString('buildStructuredRow', $js);
		self::assertStringContainsString('lck-logs-raw-toggle', $js);
		self::assertStringContainsString('setLoading', $js);
		self::assertStringContainsString('downloadFullFile', $js);
		self::assertStringContainsString("method: 'POST'", $js);
		self::assertStringContainsString('showSuccess', $js);
		self::assertStringContainsString('reloadAfterMutate', $js);
		self::assertStringNotContainsString('LogCheckToasts.show(', $js);
		self::assertStringContainsString('related_count', (string)file_get_contents($this->root . '/lib/Service/LogFileService.php'));
		// Must not collide with page chrome $roleLabel string from page-start.php
		self::assertStringContainsString('$fileRoleTitle', $logs);
		self::assertStringNotContainsString('p($roleLabel($row))', $logs);
	}

	public function testLogsMutatePathsAlwaysReload(): void
	{
		$js = (string)file_get_contents($this->root . '/js/logs.js');
		// Each successful mutate must schedule reloadAfterMutate (not soft-refresh only).
		self::assertSame(3, substr_count($js, 'reloadAfterMutate();'));
		self::assertStringContainsString('function reloadAfterMutate', $js);
		self::assertStringContainsString('location.reload', $js);
		// toastOk must use showSuccess — calling missing show() throws and skips reload.
		self::assertStringContainsString("LogCheckToasts.showSuccess(msg)", $js);
		self::assertStringNotContainsString('LogCheckToasts.show(', $js);
	}
}
