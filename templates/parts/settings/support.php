<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
$links = is_array($_['supportLinks'] ?? null) ? $_['supportLinks'] : [];
$newWindow = $l->t('(opens in a new window)');
$sponsorsUrl = trim((string)($links['sponsorsUrl'] ?? ''));
$enterpriseMailto = trim((string)($links['enterpriseMailto'] ?? ''));
$supportPageUrl = trim((string)($links['supportPageUrl'] ?? ''));

$sponsorsOk = $sponsorsUrl !== ''
	&& str_starts_with($sponsorsUrl, 'https://github.com/sponsors/');
$enterpriseOk = $enterpriseMailto !== ''
	&& str_starts_with($enterpriseMailto, 'mailto:');
$supportPageOk = $supportPageUrl !== ''
	&& (str_starts_with($supportPageUrl, 'https://') || str_starts_with($supportPageUrl, 'http://'));
?>
<section class="lck-support" aria-labelledby="lck-support-title" aria-describedby="lck-support-intro">
	<h2 id="lck-support-title"><?php p($l->t('Support us')); ?></h2>
	<p id="lck-support-intro"><?php p($l->t('HealthCheck is free. If it helps you, you can support development.')); ?></p>

	<div class="lck-support__block" role="region" aria-labelledby="lck-support-donate-title">
		<h3 id="lck-support-donate-title"><?php p($l->t('Donate')); ?></h3>
		<p class="lck-muted"><?php p($l->t('Donations go through GitHub Sponsors only.')); ?></p>
		<?php if ($sponsorsOk) { ?>
			<ul class="lck-support-links">
				<li>
					<a class="lck-btn lck-btn--primary" href="<?php p($sponsorsUrl); ?>"
						target="_blank" rel="noopener noreferrer"
						aria-label="<?php p($l->t('GitHub Sponsors') . ' ' . $newWindow); ?>">
						<?php p($l->t('GitHub Sponsors')); ?>
					</a>
				</li>
			</ul>
		<?php } ?>
	</div>

	<div class="lck-support__block" role="region" aria-labelledby="lck-support-enterprise-title">
		<h3 id="lck-support-enterprise-title"><?php p($l->t('Enterprise')); ?></h3>
		<p class="lck-muted"><?php p($l->t('Need booked help or a custom quote? Contact us by email.')); ?></p>
		<?php if ($enterpriseOk) { ?>
			<ul class="lck-support-links">
				<li>
					<a class="lck-btn lck-btn--secondary" href="<?php p($enterpriseMailto); ?>">
						<?php p($l->t('Enterprise inquiry')); ?>
					</a>
				</li>
			</ul>
		<?php } ?>
	</div>

	<?php if ($supportPageOk) { ?>
		<p class="lck-muted lck-support__website"><?php p($l->t('More on our website')); ?>:
			<a class="lck-support__website-link" href="<?php p($supportPageUrl); ?>" target="_blank" rel="noopener noreferrer"
				aria-label="<?php p($l->t('More on our website') . ' ' . $newWindow); ?>">
				<?php p($supportPageUrl); ?>
			</a>
		</p>
	<?php } ?>

</section>
