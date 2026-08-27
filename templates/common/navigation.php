<?php
/**
 * LogCheck sidebar — Check-family chrome (SnackCheck / DeskCheck parity).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\LogCheck\Service\IconCatalog;

$urls = $_['urls'] ?? [];
$pageId = (string)($_['pageId'] ?? '');
$settingsSection = (string)($_['settingsSection'] ?? '');
$roleLabel = (string)($_['roleLabel'] ?? $l->t('Member'));
$appFeedbackCssPrefix = 'lck';
$appFeedbackLanguageCode = method_exists($l, 'getLanguageCode') ? (string)$l->getLanguageCode() : 'en';

$watchItems = [
	[
		'id' => 'home',
		'label' => $l->t('Health'),
		'hint' => $l->t('Instance health'),
		'url' => (string)($urls['home'] ?? '#'),
		'icon' => 'activity',
		'active' => $pageId === 'home',
	],
	[
		'id' => 'logs',
		'label' => $l->t('Logs'),
		'hint' => $l->t('View, search, start fresh'),
		'url' => (string)($urls['logs'] ?? '#'),
		'icon' => 'file-text',
		'active' => $pageId === 'logs',
	],
];
$settingsItems = [
	[
		'id' => 'alerts',
		'label' => $l->t('Alerts'),
		'hint' => $l->t('Email, Slack, webhook'),
		'url' => (string)($urls['alerts'] ?? '#'),
		'icon' => 'bell',
		'active' => $settingsSection === 'alerts',
	],
	[
		'id' => 'rules',
		'label' => $l->t('Rules'),
		'hint' => $l->t('Severity & filters'),
		'url' => (string)($urls['rules'] ?? '#'),
		'icon' => 'sliders',
		'active' => $settingsSection === 'rules',
	],
	[
		'id' => 'people',
		'label' => $l->t('People'),
		'hint' => $l->t('App admins'),
		'url' => (string)($urls['people'] ?? '#'),
		'icon' => 'users',
		'active' => $settingsSection === 'people',
	],
	[
		'id' => 'support',
		'label' => $l->t('Support us'),
		'hint' => $l->t('Donations & enterprise'),
		'url' => (string)($urls['support'] ?? '#'),
		'icon' => 'heart-handshake',
		'active' => $settingsSection === 'support',
	],
];
?>
<a class="skip-link lck-skip-link--nav" href="#app-navigation"><?php p($l->t('Skip to navigation')); ?></a>
<nav id="app-navigation" class="lck-nav" role="navigation" aria-label="<?php p($l->t('HealthCheck')); ?>">
	<div class="lck-brand">
		<span class="lck-brand__icon" aria-hidden="true">
			<?php print_unescaped(IconCatalog::render('activity', 'lck-brand__icon-svg')); ?>
		</span>
		<div class="lck-brand__text">
			<h2 class="lck-brand__title"><?php p($l->t('HealthCheck')); ?></h2>
			<p class="lck-brand__subtitle"><?php p($l->t('Instance health & log alerts')); ?></p>
			<span class="lck-badge lck-badge--neutral"><?php p($roleLabel); ?></span>
		</div>
	</div>
	<div class="lck-nav__body">
		<section class="lck-nav__group" aria-labelledby="lck-nav-watch">
			<h3 class="lck-nav__group-label" id="lck-nav-watch"><?php p($l->t('Watch')); ?></h3>
			<ul class="lck-nav__list">
				<?php foreach ($watchItems as $item): ?>
					<li class="lck-nav__item<?php if (!empty($item['active'])): ?> is-active<?php endif; ?>">
						<a class="lck-nav__link<?php if (!empty($item['active'])): ?> is-active<?php endif; ?>"
							href="<?php p($item['url']); ?>"
							<?php if (!empty($item['active'])): ?>aria-current="page"<?php endif; ?>>
							<span class="lck-nav__icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render($item['icon'])); ?></span>
							<span class="lck-nav__label">
								<span class="lck-nav__name"><?php p($item['label']); ?></span>
								<span class="lck-nav__hint"><?php p($item['hint']); ?></span>
							</span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<section class="lck-nav__group" aria-labelledby="lck-nav-settings">
			<h3 class="lck-nav__group-label" id="lck-nav-settings"><?php p($l->t('Settings')); ?></h3>
			<ul class="lck-nav__list">
				<?php foreach ($settingsItems as $item): ?>
					<li class="lck-nav__item<?php if (!empty($item['active'])): ?> is-active<?php endif; ?>">
						<a class="lck-nav__link<?php if (!empty($item['active'])): ?> is-active<?php endif; ?>"
							href="<?php p($item['url']); ?>"
							<?php if (!empty($item['active'])): ?>aria-current="page"<?php endif; ?>>
							<span class="lck-nav__icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render($item['icon'])); ?></span>
							<span class="lck-nav__label">
								<span class="lck-nav__name"><?php p($item['label']); ?></span>
								<span class="lck-nav__hint"><?php p($item['hint']); ?></span>
							</span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	</div>
	<?php include __DIR__ . '/../parts/feedback-nav-footer.php'; ?>
</nav>
