<?php
/**
 * HealthCheck Logs — view, search, copy, start fresh / delete; multi-file picker.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
$meta = $_['logMeta'] ?? [];
$logFiles = $_['logFiles'] ?? ['live' => '', 'files' => []];
$isNcAdmin = !empty($_['isNcAdmin']);
$exists = !empty($meta['exists']);
$readable = !empty($meta['readable']);
$backendOk = !empty($meta['backend_supported']);
$name = (string)($meta['name'] ?? 'nextcloud.log');
$size = (int)($meta['size'] ?? 0);
$path = $meta['path'] ?? null;
$mtime = $meta['mtime'] ?? null;
$fileRows = is_array($logFiles['files'] ?? null) ? $logFiles['files'] : [];

$formatBytes = static function (int $bytes): string {
	if ($bytes < 1024) {
		return $bytes . ' B';
	}
	if ($bytes < 1048576) {
		return round($bytes / 1024, 1) . ' KB';
	}
	return round($bytes / 1048576, 1) . ' MB';
};

$fileRoleTitle = static function (array $row) use ($l): string {
	if (!empty($row['is_live'])) {
		return $l->t('Current log (watched for alerts)');
	}
	if (($row['role'] ?? '') === 'archive') {
		return $l->t('Saved copy');
	}
	return $l->t('Older copy');
};

include __DIR__ . '/common/page-start.php';
?>
<section class="lck-logs" aria-labelledby="lck-logs-heading"
	data-lck-selected-file="<?php p($name); ?>"
	data-lck-live-file="<?php p($name); ?>">
	<h2 id="lck-logs-heading" class="lck-sr-only"><?php p($l->t('Log file')); ?></h2>

	<?php if (!$backendOk): ?>
		<div class="lck-callout lck-callout--warning" role="status">
			<p><strong><?php p($l->t('Can\'t open logs')); ?></strong> — <?php p($l->t('HealthCheck only supports file-based logging.')); ?></p>
		</div>
	<?php else: ?>

	<section class="lck-logs-files" aria-labelledby="lck-logs-files-title">
		<h3 id="lck-logs-files-title"><?php p($l->t('Which log?')); ?></h3>
		<p class="lck-page-lead lck-logs-files__lead">
			<?php p($l->t('Pick a log file to view. Only the current file is watched for alerts.')); ?>
		</p>
		<div class="lck-logs-file-list" id="lck-logs-file-list" role="radiogroup" aria-labelledby="lck-logs-files-title">
			<?php if ($fileRows === []): ?>
				<p class="lck-muted" id="lck-logs-files-empty"><?php p($l->t('No log file yet. Nextcloud creates it when something is logged.')); ?></p>
			<?php else: ?>
				<?php foreach ($fileRows as $idx => $row):
					$id = (string)($row['id'] ?? '');
					if ($id === '') {
						continue;
					}
					$isLive = !empty($row['is_live']);
					$radioId = 'lck-logs-file-' . $idx;
					$rowReadable = !empty($row['readable']) || ($isLive && $readable);
					$rowExists = !empty($row['exists']) || ($isLive && $exists);
					$rowSize = (int)($row['size'] ?? ($isLive ? $size : 0));
					$rowMtime = $row['mtime'] ?? ($isLive ? $mtime : null);
					?>
				<label class="lck-logs-file<?php p($isLive ? ' lck-logs-file--live' : ''); ?><?php p(!$rowReadable ? ' lck-logs-file--disabled' : ''); ?>"
					for="<?php p($radioId); ?>">
					<input type="radio"
						class="lck-logs-file__input"
						name="lck-logs-file"
						id="<?php p($radioId); ?>"
						value="<?php p($id); ?>"
						data-role="<?php p((string)($row['role'] ?? '')); ?>"
						data-live="<?php p($isLive ? '1' : '0'); ?>"
						<?php if ($isLive): ?>checked<?php endif; ?>
						<?php if (!$rowReadable || !$rowExists): ?>disabled<?php endif; ?>>
					<span class="lck-logs-file__body">
						<span class="lck-logs-file__title"><?php p($fileRoleTitle($row)); ?></span>
						<span class="lck-logs-file__name lck-mono"><?php p($id); ?></span>
						<span class="lck-logs-file__meta">
							<span><?php p($formatBytes($rowSize)); ?></span>
							<?php if ($rowMtime): ?>
								<span><?php p(date('Y-m-d H:i', (int)$rowMtime)); ?></span>
							<?php endif; ?>
						</span>
					</span>
				</label>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
	</section>

	<?php if ($isNcAdmin && is_string($path) && $path !== ''): ?>
	<p class="lck-muted lck-logs-path-hint" id="lck-logs-path-wrap">
		<span class="lck-sr-only"><?php p($l->t('Path')); ?></span>
		<span class="lck-mono" id="lck-logs-path"><?php p($path); ?></span>
	</p>
	<?php endif; ?>

	<div id="lck-logs-archive-banner" class="lck-callout lck-callout--info" role="status" hidden>
		<p><?php p($l->t('This is an older copy. Alerts only watch the current log.')); ?></p>
	</div>

	<?php if (!$exists && count($fileRows) <= 1): ?>
		<div class="lck-callout lck-callout--info" role="status" id="lck-logs-missing">
			<p><?php p($l->t('No log file yet. Nextcloud creates it when something is logged.')); ?></p>
		</div>
	<?php elseif (!$readable && $exists): ?>
		<div class="lck-callout lck-callout--warning" role="alert">
			<p><?php p($l->t('Cannot read the log file. Check permissions.')); ?></p>
		</div>
	<?php else: ?>

	<fieldset class="lck-chip-group lck-logs-filter" id="lck-logs-filter-chips" role="radiogroup" aria-labelledby="lck-logs-filter-legend">
		<legend id="lck-logs-filter-legend"><?php p($l->t('Filter what you see')); ?></legend>
		<p class="lck-muted lck-logs-filter__hint"><?php p($l->t('Does not change alert rules.')); ?></p>
		<label class="lck-chip lck-logs-filter-chip">
			<input type="radio" class="lck-sr-only" name="lck-logs-filter" value="0" checked>
			<span><?php p($l->t('All levels')); ?></span>
		</label>
		<label class="lck-chip lck-logs-filter-chip">
			<input type="radio" class="lck-sr-only" name="lck-logs-filter" value="3">
			<span><?php p($l->t('Warnings+')); ?></span>
		</label>
		<label class="lck-chip lck-logs-filter-chip">
			<input type="radio" class="lck-sr-only" name="lck-logs-filter" value="4">
			<span><?php p($l->t('Errors+')); ?></span>
		</label>
	</fieldset>

	<div class="lck-logs-toolbar-search" role="search">
		<label class="lck-sr-only" for="lck-logs-search"><?php p($l->t('Search in log')); ?></label>
		<input class="form-input lck-logs-search" type="search" id="lck-logs-search"
			placeholder="<?php p($l->t('Search in log…')); ?>"
			autocomplete="off" maxlength="200">
		<button type="button" class="lck-btn lck-btn--secondary" id="lck-logs-search-btn">
			<?php p($l->t('Search')); ?>
		</button>
	</div>

	<div class="lck-logs-toolbar-actions">
		<button type="button" class="lck-btn lck-btn--secondary" id="lck-logs-reload">
			<?php p($l->t('Reload')); ?>
		</button>
		<button type="button" class="lck-btn lck-btn--secondary" id="lck-logs-copy">
			<?php p($l->t('Copy shown lines')); ?>
		</button>
		<details class="lck-more lck-logs-more-menu" id="lck-logs-more-menu">
			<summary><?php p($l->t('More')); ?></summary>
			<div class="lck-logs-more-menu__body">
				<?php if ($isNcAdmin): ?>
				<button type="button" class="lck-btn lck-btn--secondary lck-logs-more-menu__item" id="lck-logs-download">
					<?php p($l->t('Download full file')); ?>
				</button>
				<?php endif; ?>
				<button type="button" class="lck-btn lck-btn--secondary lck-logs-more-menu__item" id="lck-logs-raw-toggle" aria-pressed="false">
					<?php p($l->t('Show raw lines')); ?>
				</button>
				<?php if ($isNcAdmin): ?>
				<button type="button" class="lck-btn lck-btn--danger lck-logs-more-menu__item" id="lck-logs-delete-copy" hidden
					data-confirm-word="DELETE_COPY">
					<?php p($l->t('Remove this copy')); ?>
				</button>
				<?php endif; ?>
			</div>
		</details>
	</div>

	<p class="lck-muted lck-logs-hint" id="lck-logs-hint" role="status">
		<?php p($l->t('Showing the newest part of the file. Search looks in the recent part too.')); ?>
	</p>

	<div class="lck-logs-viewer-wrap" id="lck-logs-viewer-region" aria-busy="false">
		<div class="lck-loading lck-logs-loading" id="lck-logs-loading" hidden aria-busy="false">
			<?php p($l->t('Loading log…')); ?>
		</div>
		<div class="lck-empty-state lck-logs-empty" id="lck-logs-empty" hidden>
			<p class="lck-empty-state__text" id="lck-logs-empty-text"></p>
			<div class="lck-logs-empty__actions">
				<button type="button" class="lck-btn lck-btn--secondary" id="lck-logs-show-all">
					<?php p($l->t('Show all levels')); ?>
				</button>
				<button type="button" class="lck-btn lck-btn--secondary" id="lck-logs-empty-load-older" hidden>
					<?php p($l->t('Load older lines')); ?>
				</button>
			</div>
		</div>
		<div class="lck-logs-viewer" id="lck-logs-viewer" role="log" aria-live="polite" aria-relevant="additions" tabindex="0"></div>
		<pre class="lck-logs-viewer-raw lck-mono" id="lck-logs-viewer-raw" hidden tabindex="0"></pre>
	</div>
	<p class="lck-muted lck-logs-status" id="lck-logs-status" role="status" aria-live="polite"></p>

	<div class="lck-logs-load-older-row" id="lck-logs-load-older-row" hidden>
		<button type="button" class="lck-btn lck-btn--secondary" id="lck-logs-load-older">
			<?php p($l->t('Load older lines')); ?>
		</button>
	</div>

	<?php endif; ?>

	<?php if ($isNcAdmin && !empty($meta['can_mutate'])): ?>
	<details class="lck-more lck-logs-actions" id="lck-logs-actions">
		<summary id="lck-logs-actions-title"><?php p($l->t('Danger zone')); ?></summary>
		<p class="lck-page-lead"><?php p($l->t('After you fix errors, rename the old log aside and begin a clean file. Or delete it completely.')); ?></p>
		<div class="lck-logs-actions__row">
			<button type="button" class="lck-btn lck-btn--primary" id="lck-logs-start-fresh"
				data-confirm-word="START_FRESH">
				<?php p($l->t('Start fresh log')); ?>
			</button>
			<button type="button" class="lck-btn lck-btn--danger" id="lck-logs-delete"
				data-confirm-word="DELETE">
				<?php p($l->t('Delete log file')); ?>
			</button>
		</div>
	</details>

	<dialog class="lck-dialog modal modal--sm" id="lck-logs-confirm-dialog" aria-labelledby="lck-logs-confirm-title">
		<form method="dialog" class="lck-dialog__form" id="lck-logs-confirm-form">
			<header class="lck-dialog__header">
				<h2 id="lck-logs-confirm-title" class="lck-dialog__title"></h2>
			</header>
			<div class="lck-dialog__body">
				<p id="lck-logs-confirm-body"></p>
				<label for="lck-logs-confirm-input" id="lck-logs-confirm-label"><?php p($l->t('Confirmation')); ?></label>
				<input class="form-input" type="text" id="lck-logs-confirm-input"
					autocomplete="off" required
					aria-describedby="lck-logs-confirm-body">
			</div>
			<footer class="lck-dialog__footer">
				<button type="button" class="lck-btn lck-btn--secondary" id="lck-logs-confirm-cancel" value="cancel">
					<?php p($l->t('Cancel')); ?>
				</button>
				<button type="submit" class="lck-btn lck-btn--primary" id="lck-logs-confirm-ok" value="ok">
					<?php p($l->t('Confirm')); ?>
				</button>
			</footer>
		</form>
	</dialog>
	<?php elseif ($isNcAdmin): ?>
		<div class="lck-callout lck-callout--info" role="status">
			<p><?php p($l->t('The log file is not writable from here. Ask your host for file permissions.')); ?></p>
		</div>
	<?php endif; ?>

	<?php endif; ?>
</section>
<?php include __DIR__ . '/common/page-end.php'; ?>
