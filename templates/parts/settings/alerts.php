<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
$settings = $_['settings'] ?? [];
$channels = $settings['channels'] ?? [];
$status = $_['status'] ?? [];
$isNcAdmin = !empty($_['isNcAdmin']);
?>
<form id="lck-settings-form" class="lck-form" data-section="alerts" data-nc-admin="<?php p($isNcAdmin ? '1' : '0'); ?>">
	<input type="hidden" name="expected_version" value="<?php p((string)($_['settingsVersion'] ?? '1')); ?>">

	<?php if (empty($status['secrets_readable']) && $status !== []): ?>
		<div class="lck-callout lck-callout--warning" role="alert">
			<p><?php p($l->t('Stored channel secrets cannot be read. Re-enter webhook URLs.')); ?></p>
		</div>
	<?php endif; ?>

	<section class="lck-channel-card" aria-labelledby="lck-email-title">
		<h2 id="lck-email-title"><?php p($l->t('Email')); ?></h2>
		<div class="lck-switch-field">
			<input type="hidden" name="channels[email][enabled]" value="0">
			<input class="lck-switch-field__input" type="checkbox" id="lck-email-enabled" name="channels[email][enabled]" value="1" role="switch"
				<?php if (!empty($channels['email']['enabled'])): ?>checked<?php endif; ?>>
			<label class="lck-switch-field__label" for="lck-email-enabled">
				<span class="lck-switch-field__track" aria-hidden="true"></span>
				<span class="lck-switch-field__text"><?php p($l->t('Send email alerts')); ?></span>
			</label>
		</div>
		<label for="lck-email-recipients"><?php p($l->t('Recipients')); ?></label>
		<input class="form-input" type="text" id="lck-email-recipients" name="email_recipients"
			value="<?php p(implode(', ', $channels['email']['recipients'] ?? [])); ?>"
			autocomplete="email" inputmode="email"
			aria-describedby="lck-email-recipients-hint">
		<p id="lck-email-recipients-hint" class="lck-muted"><?php p($l->t('Recipients (comma-separated)')); ?></p>
		<button type="button" class="lck-btn lck-btn--primary lck-test-turn-on" data-channel="email"><?php p($l->t('Send test & turn on')); ?></button>
		<p class="lck-channel-status lck-muted" id="lck-email-status" role="status" hidden></p>
		<?php if (!empty($status['channels']['email']['disabled'])): ?>
			<p class="lck-callout lck-callout--warning"><?php p($l->t('Channel disabled after repeated failures.')); ?>
				<button type="button" class="lck-btn lck-btn--primary lck-reenable-channel" data-channel="email"><?php p($l->t('Re-enable & test')); ?></button>
			</p>
		<?php endif; ?>
	</section>

	<?php
	$slackOn = !empty($channels['slack']['enabled']) || !empty($channels['slack']['webhook_url_set']);
	$webhookOn = !empty($channels['webhook']['enabled']) || !empty($channels['webhook']['url_set']);
	$outboundOpen = $slackOn || $webhookOn;
	?>
	<details class="lck-more"<?php if ($outboundOpen): ?> open<?php endif; ?>>
		<summary><?php p($l->t('Slack & webhook')); ?></summary>
		<section class="lck-channel-card" aria-labelledby="lck-slack-title">
			<h2 id="lck-slack-title"><?php p($l->t('Slack')); ?></h2>
			<div class="lck-switch-field">
				<input type="hidden" name="channels[slack][enabled]" value="0">
				<input class="lck-switch-field__input" type="checkbox" id="lck-slack-enabled" name="channels[slack][enabled]" value="1" role="switch"
					<?php if (!empty($channels['slack']['enabled'])): ?>checked<?php endif; ?>>
				<label class="lck-switch-field__label" for="lck-slack-enabled">
					<span class="lck-switch-field__track" aria-hidden="true"></span>
					<span class="lck-switch-field__text"><?php p($l->t('Send Slack alerts')); ?></span>
				</label>
			</div>
			<?php if (!empty($channels['slack']['webhook_url_set'])): ?>
				<p class="lck-muted"><?php p($l->t('Saved URL')); ?>: <?php p((string)($channels['slack']['webhook_url_masked'] ?? '')); ?></p>
				<div class="lck-switch-field">
					<input type="hidden" name="channels[slack][clear_url]" value="0">
					<input class="lck-switch-field__input" type="checkbox" id="lck-slack-clear" name="channels[slack][clear_url]" value="1" role="switch">
					<label class="lck-switch-field__label" for="lck-slack-clear">
						<span class="lck-switch-field__track" aria-hidden="true"></span>
						<span class="lck-switch-field__text"><?php p($l->t('Clear saved URL')); ?></span>
					</label>
				</div>
			<?php endif; ?>
			<label for="lck-slack-url"><?php p($l->t('Slack webhook URL')); ?></label>
			<input class="form-input" type="url" id="lck-slack-url" name="channels[slack][webhook_url]" value="" autocomplete="off">
			<button type="button" class="lck-btn lck-btn--primary lck-test-turn-on" data-channel="slack"><?php p($l->t('Send test & turn on')); ?></button>
			<p class="lck-channel-status lck-muted" id="lck-slack-status" role="status" hidden></p>
			<?php if (!empty($status['channels']['slack']['disabled'])): ?>
				<p class="lck-callout lck-callout--warning"><?php p($l->t('Channel disabled after repeated failures.')); ?>
					<button type="button" class="lck-btn lck-btn--primary lck-reenable-channel" data-channel="slack"><?php p($l->t('Re-enable & test')); ?></button>
				</p>
			<?php endif; ?>
		</section>
		<section class="lck-channel-card" aria-labelledby="lck-webhook-title">
			<h2 id="lck-webhook-title"><?php p($l->t('Webhook')); ?></h2>
			<div class="lck-switch-field">
				<input type="hidden" name="channels[webhook][enabled]" value="0">
				<input class="lck-switch-field__input" type="checkbox" id="lck-webhook-enabled" name="channels[webhook][enabled]" value="1" role="switch"
					<?php if (!empty($channels['webhook']['enabled'])): ?>checked<?php endif; ?>>
				<label class="lck-switch-field__label" for="lck-webhook-enabled">
					<span class="lck-switch-field__track" aria-hidden="true"></span>
					<span class="lck-switch-field__text"><?php p($l->t('Send webhook alerts')); ?></span>
				</label>
			</div>
			<?php if (!empty($channels['webhook']['url_set'])): ?>
				<p class="lck-muted"><?php p($l->t('Saved URL')); ?>: <?php p((string)($channels['webhook']['url_masked'] ?? '')); ?></p>
				<div class="lck-switch-field">
					<input type="hidden" name="channels[webhook][clear_url]" value="0">
					<input class="lck-switch-field__input" type="checkbox" id="lck-webhook-clear" name="channels[webhook][clear_url]" value="1" role="switch">
					<label class="lck-switch-field__label" for="lck-webhook-clear">
						<span class="lck-switch-field__track" aria-hidden="true"></span>
						<span class="lck-switch-field__text"><?php p($l->t('Clear saved URL')); ?></span>
					</label>
				</div>
			<?php endif; ?>
			<label for="lck-webhook-url"><?php p($l->t('Webhook URL')); ?></label>
			<input class="form-input" type="url" id="lck-webhook-url" name="channels[webhook][url]" value="" autocomplete="off">
			<button type="button" class="lck-btn lck-btn--primary lck-test-turn-on" data-channel="webhook"><?php p($l->t('Send test & turn on')); ?></button>
			<p class="lck-channel-status lck-muted" id="lck-webhook-status" role="status" hidden></p>
			<?php if (!empty($status['channels']['webhook']['disabled'])): ?>
				<p class="lck-callout lck-callout--warning"><?php p($l->t('Channel disabled after repeated failures.')); ?>
					<button type="button" class="lck-btn lck-btn--primary lck-reenable-channel" data-channel="webhook"><?php p($l->t('Re-enable & test')); ?></button>
				</p>
			<?php endif; ?>
		</section>
	</details>

	<details class="lck-more" id="lck-more-options">
		<summary><?php p($l->t('More options')); ?></summary>
		<div class="lck-switch-field">
			<input type="hidden" name="channels[notification][enabled]" value="0">
			<input class="lck-switch-field__input" type="checkbox" id="lck-notification-enabled" name="channels[notification][enabled]" value="1" role="switch"
				<?php if (!empty($channels['notification']['enabled'])): ?>checked<?php endif; ?>>
			<label class="lck-switch-field__label" for="lck-notification-enabled">
				<span class="lck-switch-field__track" aria-hidden="true"></span>
				<span class="lck-switch-field__text"><?php p($l->t('In-app notifications')); ?></span>
			</label>
		</div>
		<button type="button" class="lck-btn lck-btn--primary lck-test-turn-on" data-channel="notification"><?php p($l->t('Send test & turn on')); ?></button>
		<p class="lck-channel-status lck-muted" id="lck-notification-status" role="status" hidden></p>
		<?php if ($isNcAdmin): ?>
			<div class="lck-callout lck-callout--warning" id="lck-excerpts-help">
				<p><?php p($l->t('Including log text can expose passwords, tokens, and personal data — including to services outside your country. Only enable if you accept that risk.')); ?></p>
			</div>
			<div class="lck-switch-field">
				<input type="hidden" name="include_message_excerpts" value="0">
				<input class="lck-switch-field__input" type="checkbox" name="include_message_excerpts" value="1" id="lck-excerpts" role="switch"
					aria-describedby="lck-excerpts-help"
					<?php if (!empty($settings['include_message_excerpts'])): ?>checked<?php endif; ?>>
				<label class="lck-switch-field__label" for="lck-excerpts">
					<span class="lck-switch-field__track" aria-hidden="true"></span>
					<span class="lck-switch-field__text"><?php p($l->t('Include short log excerpts')); ?></span>
				</label>
			</div>
			<label for="lck-excerpt-confirm"><?php p($l->t('Type CONFIRM to enable excerpts')); ?></label>
			<input class="form-input" type="text" id="lck-excerpt-confirm" name="excerpt_confirm" value="" autocomplete="off">

			<div class="lck-callout lck-callout--info" id="lck-private-webhooks-help">
				<p><?php p($l->t('Private network webhooks can reach devices on your local network. Leave this off unless you know you need it.')); ?></p>
			</div>
			<div class="lck-switch-field">
				<input type="hidden" name="allow_private_webhooks" value="0">
				<input class="lck-switch-field__input" type="checkbox" name="allow_private_webhooks" value="1" id="lck-private-webhooks" role="switch"
					aria-describedby="lck-private-webhooks-help"
					<?php if (!empty($settings['allow_private_webhooks'])): ?>checked<?php endif; ?>>
				<label class="lck-switch-field__label" for="lck-private-webhooks">
					<span class="lck-switch-field__track" aria-hidden="true"></span>
					<span class="lck-switch-field__text"><?php p($l->t('Allow private network webhook addresses')); ?></span>
				</label>
			</div>
		<?php endif; ?>
	</details>

	<button type="submit" class="lck-btn lck-btn--primary"><?php p($l->t('Save')); ?></button>
</form>
