<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
use OCA\LogCheck\Service\Health\HealthDashboardService;

$settings = $_['settings'] ?? [];
$minLevel = (int)($settings['min_level'] ?? 3);
$coalesce = (int)($settings['coalesce_seconds'] ?? 900);
$mutes = $settings['mutes'] ?? [];
$isNcAdmin = !empty($_['isNcAdmin']);
$disabledProbes = is_array($settings['health_disabled_probes'] ?? null)
	? array_map('strval', $settings['health_disabled_probes'])
	: [];
?>
<form id="lck-settings-form" class="lck-form" data-section="rules" data-nc-admin="<?php p($isNcAdmin ? '1' : '0'); ?>">
	<input type="hidden" name="expected_version" value="<?php p((string)($_['settingsVersion'] ?? '1')); ?>">
	<input type="hidden" name="min_level" id="lck-min-level" value="<?php p((string)$minLevel); ?>">
	<input type="hidden" name="coalesce_seconds" id="lck-pace-seconds" value="<?php p((string)$coalesce); ?>">

	<fieldset class="lck-chip-group" id="lck-level-chips">
		<legend><?php p($l->t('How serious?')); ?></legend>
		<button type="button" class="lck-chip<?php p($minLevel >= 3 ? ' is-active' : ''); ?>" data-value="3" aria-pressed="<?php p($minLevel >= 3 ? 'true' : 'false'); ?>"><?php p($l->t('Errors')); ?></button>
		<button type="button" class="lck-chip<?php p($minLevel <= 2 ? ' is-active' : ''); ?>" data-value="2" aria-pressed="<?php p($minLevel <= 2 ? 'true' : 'false'); ?>"><?php p($l->t('Warnings too')); ?></button>
	</fieldset>

	<fieldset class="lck-chip-group" id="lck-pace-chips">
		<legend><?php p($l->t('How often?')); ?></legend>
		<button type="button" class="lck-chip<?php p($coalesce === 300 ? ' is-active' : ''); ?>" data-value="300" aria-pressed="<?php p($coalesce === 300 ? 'true' : 'false'); ?>"><?php p($l->t('Fast (5 min)')); ?></button>
		<button type="button" class="lck-chip<?php p($coalesce === 900 ? ' is-active' : ''); ?>" data-value="900" aria-pressed="<?php p($coalesce === 900 ? 'true' : 'false'); ?>"><?php p($l->t('Normal (15 min)')); ?></button>
		<button type="button" class="lck-chip<?php p($coalesce === 3600 ? ' is-active' : ''); ?>" data-value="3600" aria-pressed="<?php p($coalesce === 3600 ? 'true' : 'false'); ?>"><?php p($l->t('Quiet (60 min)')); ?></button>
	</fieldset>

	<details class="lck-more">
		<summary><?php p($l->t('Advanced filters')); ?></summary>
		<p class="lck-muted"><?php p($l->t('Choose which apps to watch first. Mute patterns hide noisy lines after that.')); ?></p>
		<label for="lck-app-mode"><?php p($l->t('Which apps to watch')); ?></label>
		<select class="form-select" id="lck-app-mode" name="app_mode">
			<option value="all" <?php if (($settings['app_mode'] ?? '') === 'all'): ?>selected<?php endif; ?>><?php p($l->t('All apps')); ?></option>
			<option value="allow" <?php if (($settings['app_mode'] ?? '') === 'allow'): ?>selected<?php endif; ?>><?php p($l->t('Only these apps')); ?></option>
			<option value="deny" <?php if (($settings['app_mode'] ?? '') === 'deny'): ?>selected<?php endif; ?>><?php p($l->t('All except these apps')); ?></option>
		</select>
		<label for="lck-app-list"><?php p($l->t('App names for the filter above (comma-separated)')); ?></label>
		<input class="form-input" type="text" id="lck-app-list" name="app_list"
			value="<?php p(implode(', ', $settings['app_list'] ?? [])); ?>">
		<label for="lck-mutes"><?php p($l->t('Ignore messages that match (one pattern per line)')); ?></label>
		<textarea class="form-input" id="lck-mutes" name="mute_regexes" rows="4"><?php
			$regexLines = [];
			foreach ($mutes as $m) {
				if (is_array($m) && ($m['type'] ?? '') === 'regex') {
					$regexLines[] = (string)($m['value'] ?? '');
				}
			}
			p(implode("\n", $regexLines));
		?></textarea>
		<label for="lck-mute-apps"><?php p($l->t('Always mute these apps (comma-separated)')); ?></label>
		<input class="form-input" type="text" id="lck-mute-apps" name="mute_apps" value="<?php
			$apps = [];
			foreach ($mutes as $m) {
				if (is_array($m) && ($m['type'] ?? '') === 'app' && ($m['value'] ?? '') !== 'logcheck') {
					$apps[] = (string)$m['value'];
				}
			}
			p(implode(', ', $apps));
		?>">
	</details>

	<?php if ($isNcAdmin): ?>
	<fieldset class="lck-checks" id="lck-health-probes" data-field="health_disabled_probes">
		<legend><?php p($l->t('Checks on the Health page')); ?></legend>
		<p class="lck-muted"><?php p($l->t('Turn off checks that cannot run on this server.')); ?></p>
		<?php foreach (HealthDashboardService::cardTitles($l) as $probeId => $probeLabel): ?>
		<div class="lck-switch-field">
			<input type="checkbox" class="lck-switch-field__input" role="switch"
				id="lck-probe-<?php p($probeId); ?>"
				name="health_probes[]" value="<?php p($probeId); ?>"
				<?php if (!in_array($probeId, $disabledProbes, true)): ?>checked<?php endif; ?>>
			<label class="lck-switch-field__label" for="lck-probe-<?php p($probeId); ?>">
				<span class="lck-switch-field__track" aria-hidden="true"></span>
				<span class="lck-switch-field__text"><?php p($probeLabel); ?></span>
			</label>
		</div>
		<?php endforeach; ?>
		<?php
		$probeTargetUrl = is_string($settings['health_https_probe_url'] ?? null) ? $settings['health_https_probe_url'] : '';
		$probeTargetIp = is_string($settings['health_https_probe_ip'] ?? null) ? $settings['health_https_probe_ip'] : '';
		?>
		<div class="lck-field" data-field="health_https_probe_url">
			<label for="lck-probe-target-url"><?php p($l->t('HTTPS check address')); ?></label>
			<input class="form-input" type="text" id="lck-probe-target-url" name="health_https_probe_url"
				value="<?php p($probeTargetUrl); ?>"
				placeholder="https://cloud.example.eu"
				spellcheck="false" autocapitalize="none" autocomplete="off"
				aria-describedby="lck-probe-target-url-hint">
			<p class="lck-muted" id="lck-probe-target-url-hint"><?php
				p($l->t('Address the HTTPS card checks. Leave empty to use overwrite.cli.url or the address this browser uses.'));
			?></p>
		</div>
		<div class="lck-field" data-field="health_https_probe_ip">
			<label for="lck-probe-target-ip"><?php p($l->t('Connect to this address instead (optional)')); ?></label>
			<input class="form-input" type="text" id="lck-probe-target-ip" name="health_https_probe_ip"
				value="<?php p($probeTargetIp); ?>"
				placeholder="203.0.113.10"
				spellcheck="false" autocapitalize="none" autocomplete="off" inputmode="decimal"
				aria-describedby="lck-probe-target-ip-hint">
			<p class="lck-muted" id="lck-probe-target-ip-hint"><?php
				p($l->t('Dial this IP while keeping the name above — for example the public address while internal DNS resolves the name privately.'));
			?></p>
		</div>
	</fieldset>
	<?php endif; ?>

	<div class="lck-form-actions">
		<button type="submit" class="lck-btn lck-btn--primary"><?php p($l->t('Save')); ?></button>
	</div>
</form>
