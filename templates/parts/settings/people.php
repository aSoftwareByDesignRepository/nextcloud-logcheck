<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
$settings = $_['settings'] ?? [];
$admins = $settings['access']['app_admins'] ?? [];
$adminPeople = $_['adminPeople'] ?? [];
$isNcAdmin = !empty($_['isNcAdmin']);
?>
<form id="lck-settings-form" class="lck-form" data-section="people">
	<input type="hidden" name="expected_version" value="<?php p((string)($_['settingsVersion'] ?? '1')); ?>">
	<input type="hidden" name="access[mode]" value="restricted">

	<section class="lck-callout lck-callout--info lck-access-explain" aria-labelledby="lck-access-explain-title">
		<h2 id="lck-access-explain-title" class="lck-callout__title"><?php p($l->t('Who can open HealthCheck?')); ?></h2>
		<p><?php p($l->t('Only Nextcloud admins and the people below can open HealthCheck. Open access is not available.')); ?></p>
		<ul class="lck-access-explain__list">
			<li><?php p($l->t('Nextcloud admins always have access. They can also change this list, download the full log file, start a fresh log, and turn on raw excerpts in alerts.')); ?></li>
			<li><?php p($l->t('App admins can open HealthCheck, view and search logs, and manage alerts and rules — but not those Nextcloud-admin-only actions.')); ?></li>
			<li><?php p($l->t('Everyone else cannot open HealthCheck.')); ?></li>
		</ul>
	</section>

	<?php if ($isNcAdmin): ?>
		<label for="lck-people-search"><?php p($l->t('Add app admin')); ?></label>
		<input class="form-input" type="search" id="lck-people-search" autocomplete="off"
			role="combobox" aria-expanded="false" aria-controls="lck-people-results" aria-autocomplete="list"
			aria-haspopup="listbox"
			placeholder="<?php p($l->t('Search by name')); ?>">
		<ul id="lck-people-results" class="lck-people-results" role="listbox" hidden></ul>
	<?php else: ?>
		<p class="lck-muted" role="status"><?php p($l->t('Only a Nextcloud admin can add or remove app admins.')); ?></p>
	<?php endif; ?>

	<ul id="lck-people-chips" class="lck-chip-list" aria-label="<?php p($l->t('App admins')); ?>">
		<?php foreach ($admins as $uid): ?>
			<?php
			$uid = (string)$uid;
			$label = $uid;
			foreach ($adminPeople as $person) {
				if (is_array($person) && ($person['uid'] ?? '') === $uid) {
					$label = (string)($person['displayName'] ?? $uid);
					break;
				}
			}
			?>
			<li class="lck-person-chip" data-uid="<?php p($uid); ?>">
				<span><?php p($label); ?></span>
				<?php if ($isNcAdmin): ?>
					<input type="hidden" name="access[app_admins][]" value="<?php p($uid); ?>">
					<button type="button" class="lck-btn lck-btn--ghost lck-remove-person" aria-label="<?php p($l->t('Remove %s', [$label])); ?>">×</button>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php if ($isNcAdmin): ?>
		<button type="submit" class="lck-btn lck-btn--primary"><?php p($l->t('Save')); ?></button>
	<?php endif; ?>
</form>
