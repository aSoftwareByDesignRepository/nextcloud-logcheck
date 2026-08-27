<?php
/**
 * LogCheck page chrome — design-system / ArbeitszeitCheck shell parity.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\LogCheck\Service\IconCatalog;

$pageId = (string)($_['pageId'] ?? 'home');
$pageTitle = (string)($_['pageTitle'] ?? $l->t('LogCheck'));
$pageHelp = (string)($_['pageHelp'] ?? '');
$roleLabel = (string)($_['roleLabel'] ?? $l->t('Member'));
$urls = $_['urls'] ?? [];
$clientHints = $_['clientHints'] ?? ['locale' => 'en-US', 'htmlLang' => 'en-US', 'timezone' => 'UTC'];
$htmlLang = (string)($clientHints['htmlLang'] ?? 'en-US');
$locale = (string)($clientHints['locale'] ?? $htmlLang);
$timezone = (string)($clientHints['timezone'] ?? 'UTC');
$settingsSection = (string)($_['settingsSection'] ?? '');
$urlsJson = htmlspecialchars(json_encode($urls, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
$homeUrl = (string)($urls['home'] ?? '#');

$headerIcons = [
	'home' => 'activity',
	'logs' => 'file-text',
	'settings' => 'settings',
	'denied' => 'shield-off',
];
$iconName = $headerIcons[$pageId] ?? 'settings';
include __DIR__ . '/navigation.php';
?>
<div id="app-content" class="lck-app lck-app--<?php p($pageId); ?>"
	lang="<?php p($htmlLang); ?>"
	data-locale="<?php p($locale); ?>"
	data-lck-page="<?php p($pageId); ?>"
	data-lck-timezone="<?php p($timezone); ?>"
	data-lck-settings-section="<?php p($settingsSection); ?>"
	data-lck-urls="<?php print_unescaped($urlsJson); ?>"
	data-lck-settings-version="<?php p((string)($_['settingsVersion'] ?? '')); ?>"
	data-lck-is-nc-admin="<?php p(!empty($_['isNcAdmin']) ? '1' : '0'); ?>">
	<a class="lck-skip-link" href="#lck-main-content"><?php p($l->t('Skip to main content')); ?></a>
	<div id="lck-live-region" class="lck-sr-only" role="status" aria-live="polite" aria-atomic="true"></div>
	<div id="lck-alert-region" class="lck-sr-only" role="alert" aria-live="assertive" aria-atomic="true"></div>
	<div id="app-content-wrapper" class="lck-shell">
		<header class="lck-page-header" aria-labelledby="lck-page-title">
			<nav class="lck-breadcrumb" aria-label="<?php p($l->t('Breadcrumb')); ?>">
				<ol class="lck-breadcrumb__list">
					<li class="lck-breadcrumb__item">
						<a class="lck-breadcrumb__link" href="<?php p($homeUrl); ?>"><?php p($l->t('HealthCheck')); ?></a>
					</li>
					<li class="lck-breadcrumb__item lck-breadcrumb__item--current" aria-current="page">
						<span class="lck-breadcrumb__current"><?php p($pageTitle); ?></span>
					</li>
				</ol>
			</nav>
			<div class="lck-page-header__main">
				<div class="lck-page-header__icon" aria-hidden="true">
					<?php print_unescaped(IconCatalog::render($iconName, 'lck-page-header__icon-svg')); ?>
				</div>
				<div class="lck-page-header__text">
					<h1 id="lck-page-title" class="lck-page-title"><?php p($pageTitle); ?></h1>
					<?php if ($pageHelp !== ''): ?>
						<p class="lck-page-header__lead"><?php p($pageHelp); ?></p>
					<?php endif; ?>
				</div>
				<div id="lck-page-actions" class="lck-page-header__actions" aria-live="polite"></div>
			</div>
			<?php if (empty($_['hideScopeStrip'])): ?>
			<div class="lck-scope-strip" aria-label="<?php p($l->t('Active session context')); ?>">
				<span class="lck-scope-strip__label"><?php p($l->t('Role')); ?></span>
				<span class="lck-badge lck-badge--neutral lck-scope-strip__badge"><?php p($roleLabel); ?></span>
				<span class="lck-scope-strip__sep" aria-hidden="true">·</span>
				<span class="lck-scope-strip__label"><?php p($l->t('Timezone')); ?></span>
				<span class="lck-scope-strip__value"><?php p($timezone); ?></span>
			</div>
			<?php endif; ?>
		</header>
		<main id="lck-main-content" class="lck-main" tabindex="-1" aria-labelledby="lck-page-title">
