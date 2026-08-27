<?php
/**
 * Access denied — design-system minimal shell (§3.17): skip, live regions, alert, one CTA.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
$homeUrl = (string)($_['homeUrl'] ?? '/');
$clientHints = $_['clientHints'] ?? ['locale' => 'en-US', 'htmlLang' => 'en-US', 'timezone' => 'UTC'];
$htmlLang = (string)($clientHints['htmlLang'] ?? 'en-US');
?>
<div id="app-content" class="lck-app lck-app--denied lck-shell--minimal"
	lang="<?php p($htmlLang); ?>">
	<a class="lck-skip-link" href="#lck-main-content"><?php p($l->t('Skip to main content')); ?></a>
	<div id="lck-live-region" class="lck-sr-only" role="status" aria-live="polite" aria-atomic="true"></div>
	<div id="lck-alert-region" class="lck-sr-only" role="alert" aria-live="assertive" aria-atomic="true"></div>
	<div id="app-content-wrapper" class="lck-shell">
		<header class="lck-page-header" aria-labelledby="lck-page-title">
			<div class="lck-page-header__main">
				<div class="lck-page-header__icon" aria-hidden="true">
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lck-page-header__icon-svg" focusable="false"><circle cx="12" cy="12" r="10"/><path d="M15 9 9 15M9 9l6 6"/></svg>
				</div>
				<div class="lck-page-header__text">
					<h1 id="lck-page-title" class="lck-page-title"><?php p($l->t('Not authorized')); ?></h1>
				</div>
			</div>
		</header>
		<main id="lck-main-content" class="lck-main" tabindex="-1" aria-labelledby="lck-page-title">
			<section class="lck-callout lck-callout--warning" role="alert">
				<p><?php p($l->t('Only Nextcloud admins and HealthCheck app admins can use this app.')); ?></p>
				<p><a class="lck-btn lck-btn--primary" href="<?php p($homeUrl); ?>"><?php p($l->t('Back to Nextcloud')); ?></a></p>
			</section>
		</main>
	</div>
</div>
