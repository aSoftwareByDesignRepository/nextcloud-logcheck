<?php

declare(strict_types=1);

namespace OCA\LogCheck\Support;

use OCA\LogCheck\AppInfo\Application;
use OCP\Util;

/**
 * Single source for the app's shell asset set.
 *
 * Page routes register these via PageController; the access-denied surface is
 * produced by EntitlementMiddleware::afterException — which runs before the
 * controller, so it must register the same styles itself or the denied page
 * renders unstyled (no .lck-sr-only, no callout/button styling).
 */
final class AssetRegistrar {
	public static function registerStyles(): void {
		Util::addStyle(Application::APP_ID, 'common/tokens');
		Util::addStyle(Application::APP_ID, 'common/shell-chrome');
		Util::addStyle(Application::APP_ID, 'common/app-layout');
		Util::addStyle(Application::APP_ID, 'common/navigation');
		Util::addStyle(Application::APP_ID, 'common/mobile-nav');
		Util::addStyle(Application::APP_ID, 'common/form-controls');
		Util::addStyle(Application::APP_ID, 'common/page-patterns');
		Util::addStyle(Application::APP_ID, 'common/notification-surfaces');
		Util::addStyle(Application::APP_ID, 'common/dialogs');
		Util::addStyle(Application::APP_ID, 'common/switch-field');
		Util::addStyle(Application::APP_ID, 'common/badges');
		Util::addStyle(Application::APP_ID, 'common/field-errors');
		Util::addStyle(Application::APP_ID, 'app');
	}

	public static function registerScripts(): void {
		Util::addScript(Application::APP_ID, 'common/toasts');
		Util::addScript(Application::APP_ID, 'common/field-errors');
		Util::addScript(Application::APP_ID, 'common/app-feedback');
		Util::addScript(Application::APP_ID, 'common/mobile-nav');
		Util::addScript(Application::APP_ID, 'app');
	}
}
