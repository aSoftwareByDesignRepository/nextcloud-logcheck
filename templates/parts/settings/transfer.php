<?php
/**
 * Settings sub-page: Transfer configuration (test → production).
 * Two cards — Export (passphrase → .nctransfer download) and Import
 * (mode → file → passphrase → mandatory dry-run → confirm) — plus the
 * "Last transfer" receipt line. The report dialog is a native <dialog>.
 *
 * Synced from apps/_shared/config-transfer — do not edit app-side.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
$isSystemAdmin = !empty($_['isSystemAdmin']);
$lastReceipt = is_array($_['transferLastReceipt'] ?? null) ? $_['transferLastReceipt'] : null;
?>
<section class="lck-card lck-section" aria-labelledby="lck-xfer-export-h" id="lck-transfer-export-card">
	<header class="lck-section__header">
		<div>
			<h2 id="lck-xfer-export-h"><?php p($l->t('Export configuration')); ?></h2>
			<p class="lck-section__sub"><?php p($l->t('Download all settings, catalogues and business data as one encrypted file — then import it on your production instance.')); ?></p>
		</div>
	</header>
	<div class="lck-callout lck-callout--info" role="note">
		<p><strong><?php p($l->t('Secrets are never included.')); ?></strong>
		<?php p($l->t('Passwords, tokens and salts stay on this instance — the file is encrypted and still does not carry them. It does contain user and group names: handle it like personal data.')); ?></p>
	</div>
	<form id="lck-transfer-export-form" class="lck-form" novalidate>
		<div class="lck-form-row">
			<label for="lck-transfer-passphrase"><?php p($l->t('Encryption passphrase')); ?></label>
			<div class="lck-input-group">
				<input type="password" id="lck-transfer-passphrase" name="passphrase" autocomplete="new-password" required minlength="12" aria-describedby="lck-transfer-passphrase-hint lck-transfer-passphrase-strength">
				<button type="button" class="lck-btn lck-btn--ghost" id="lck-transfer-passphrase-toggle" aria-pressed="false"><?php p($l->t('Show')); ?></button>
				<button type="button" class="lck-btn lck-btn--secondary" id="lck-transfer-passphrase-generate"><?php p($l->t('Generate')); ?></button>
			</div>
			<p class="lck-form-row__hint" id="lck-transfer-passphrase-hint"><?php p($l->t('At least 12 characters. Without this passphrase the file cannot be restored — store it safely.')); ?></p>
			<p class="lck-form-row__hint lck-transfer-strength" id="lck-transfer-passphrase-strength" role="status" aria-live="polite"></p>
		</div>
		<div class="lck-actions">
			<button type="submit" class="lck-btn lck-btn--primary" id="lck-transfer-export-btn">
				<?php p($l->t('Download encrypted transfer file')); ?>
			</button>
		</div>
	</form>
</section>

<section class="lck-card lck-section" aria-labelledby="lck-xfer-import-h" id="lck-transfer-import-card">
	<header class="lck-section__header">
		<div>
			<h2 id="lck-xfer-import-h"><?php p($l->t('Import configuration')); ?></h2>
			<p class="lck-section__sub"><?php p($l->t('Pick a transfer file, enter its passphrase, review the preview — then confirm. Nothing is written before you confirm.')); ?></p>
		</div>
	</header>
	<?php if ($lastReceipt !== null): ?>
	<p class="lck-transfer-last" id="lck-transfer-last">
		<?php p($l->t('Last transfer')); ?>:
		<strong><?php p((string)($lastReceipt['at'] ?? '—')); ?></strong>
		· <?php p((string)($lastReceipt['mode'] ?? 'merge')); ?>
		· <?php p(($lastReceipt['result'] ?? '') === 'applied' ? $l->t('applied') : $l->t('failed')); ?>
		<?php if (($lastReceipt['snapshotId'] ?? null) !== null): ?>
			· <?php p($l->t('undo via snapshot')); ?> <code><?php p((string)$lastReceipt['snapshotId']); ?></code>
		<?php endif; ?>
	</p>
	<?php endif; ?>
	<form id="lck-transfer-import-form" class="lck-form" novalidate>
		<fieldset class="lck-form-row lck-fieldset">
			<legend><?php p($l->t('Mode')); ?></legend>
			<label class="lck-radio">
				<input type="radio" name="mode" value="merge" checked>
				<span><strong><?php p($l->t('Merge')); ?></strong> — <?php p($l->t('add and update; nothing is deleted')); ?></span>
			</label>
			<label class="lck-radio lck-radio--danger">
				<input type="radio" name="mode" value="replace" <?php if (!$isSystemAdmin): ?>disabled<?php endif; ?>>
				<span><strong><?php p($l->t('Replace')); ?></strong> — <?php p($l->t('make identical to the file; prod-only rows are deleted')); ?></span>
			</label>
			<p class="lck-form-row__hint" id="lck-transfer-replace-hint">
				<?php if ($isSystemAdmin): ?>
					<?php p($l->t('Replace needs maintenance mode ON (occ maintenance:mode --on) — you will type REPLACE in the preview to confirm.')); ?>
				<?php else: ?>
					<?php p($l->t('Replace is available to Nextcloud system administrators only.')); ?>
				<?php endif; ?>
			</p>
		</fieldset>
		<div class="lck-form-row">
			<label for="lck-transfer-file"><?php p($l->t('Transfer file')); ?></label>
			<input type="file" id="lck-transfer-file" name="file" accept=".nctransfer,application/json" class="lck-file-input">
		</div>
		<div class="lck-form-row">
			<label for="lck-transfer-import-passphrase"><?php p($l->t('File passphrase')); ?></label>
			<div class="lck-input-group">
				<input type="password" id="lck-transfer-import-passphrase" name="import_passphrase" autocomplete="off">
				<button type="button" class="lck-btn lck-btn--ghost" id="lck-transfer-import-passphrase-toggle" aria-pressed="false"><?php p($l->t('Show')); ?></button>
			</div>
		</div>
		<div id="lck-transfer-import-status" class="lck-form-row__hint" role="status" aria-live="polite"></div>
	</form>
</section>

<dialog id="lck-transfer-report" class="lck-dialog lck-transfer-report" aria-labelledby="lck-transfer-report-h">
	<div class="lck-dialog__body">
		<h3 id="lck-transfer-report-h"><?php p($l->t('Import preview')); ?></h3>
		<p id="lck-transfer-report-verdict" class="lck-transfer-report__verdict"></p>
		<p id="lck-transfer-report-file" class="lck-form-row__hint"></p>
		<div id="lck-transfer-report-groups"></div>
	</div>
	<footer class="lck-dialog__footer">
		<div id="lck-transfer-replace-confirm" class="lck-form-row" hidden>
			<label for="lck-transfer-replace-token"><?php p($l->t('Type REPLACE to confirm')); ?></label>
			<input type="text" id="lck-transfer-replace-token" autocomplete="off" spellcheck="false" placeholder="REPLACE">
		</div>
		<div class="lck-actions">
			<button type="button" class="lck-btn lck-btn--secondary" id="lck-transfer-cancel" value="cancel"><?php p($l->t('Cancel')); ?></button>
			<button type="button" class="lck-btn lck-btn--primary" id="lck-transfer-confirm"><?php p($l->t('Apply import')); ?></button>
		</div>
	</footer>
</dialog>

<dialog id="lck-transfer-progress" class="lck-dialog" aria-labelledby="lck-transfer-progress-h" aria-live="polite">
	<div class="lck-dialog__body">
		<h3 id="lck-transfer-progress-h"><?php p($l->t('Applying import')); ?></h3>
		<progress id="lck-transfer-progress-bar" max="100" value="0"></progress>
		<p id="lck-transfer-progress-text"><?php p($l->t('Starting…')); ?></p>
		<p class="lck-form-row__hint"><?php p($l->t('You can close this — the result is saved and shown under “Last transfer”.')); ?></p>
	</div>
</dialog>

<div id="lck-transfer-result-live" class="lck-visually-hidden" role="status" aria-live="polite"></div>
