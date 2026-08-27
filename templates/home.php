<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
$status = $_['status'] ?? [];
$cards = $_['healthCards'] ?? [];
$summaryState = (string)($_['healthSummaryState'] ?? 'unknown');
$summaryLabel = (string)($_['healthSummaryLabel'] ?? '');
$urls = $_['urls'] ?? [];
$watch = !empty($status['watch_enabled']);
$alertsReady = !empty($status['alerts_ready']);
$supported = !empty($status['backend_supported']);
$topologyOk = ($status['topology_ok'] ?? true) !== false;
$state = (string)($status['state'] ?? 'off');

$stateLabels = [
	'ok' => $l->t('OK'),
	'degraded' => $l->t('Needs attention'),
	'critical' => $l->t('Critical'),
	'unknown' => $l->t('Unknown'),
];
$cardTitles = [
	'log' => $l->t('Log alerts'),
	'jobs' => $l->t('Background jobs'),
	'php' => $l->t('PHP'),
	'db' => $l->t('Database'),
	'disk' => $l->t('Data disk'),
	'https' => $l->t('HTTPS'),
	'updates' => $l->t('Updates'),
];
if (!isset($stateLabels[$summaryState])) {
	$summaryState = 'unknown';
}

include __DIR__ . '/common/page-start.php';
?>
<section class="lck-home" aria-labelledby="lck-health-heading">
	<h2 id="lck-health-heading" class="lck-sr-only"><?php p($l->t('Instance health')); ?></h2>

	<?php if ($cards !== []): ?>
		<div class="lck-health-summary" role="status" data-state="<?php p($summaryState); ?>" aria-live="polite">
			<span class="lck-badge" data-state="<?php p($summaryState); ?>">
				<span class="lck-badge__dot" aria-hidden="true"></span>
				<span class="lck-badge__label">
					<span class="lck-sr-only"><?php p($stateLabels[$summaryState]); ?>: </span>
					<?php p($summaryLabel !== '' ? $summaryLabel : $stateLabels[$summaryState]); ?>
				</span>
			</span>
			<?php if ($supported && $topologyOk): ?>
			<button type="button" class="lck-btn lck-btn--secondary lck-health-summary__check" id="lck-check-again">
				<?php p($l->t('Check again')); ?>
			</button>
			<?php endif; ?>
		</div>
		<ul class="lck-health-grid" role="list">
			<?php foreach ($cards as $card): ?>
				<?php
				$cid = (string)($card['id'] ?? '');
				$cstate = (string)($card['state'] ?? 'unknown');
				if (!isset($stateLabels[$cstate])) {
					$cstate = 'unknown';
				}
				$ctitle = $cardTitles[$cid] ?? (string)($card['title'] ?? $cid);
				$clabel = (string)($card['label'] ?? '');
				$cdetail = (string)($card['detail'] ?? '');
				$actions = is_array($card['actions'] ?? null) ? $card['actions'] : [];
				?>
				<li class="lck-health-card" data-probe="<?php p($cid); ?>" data-state="<?php p($cstate); ?>">
					<article class="lck-health-card__inner" aria-labelledby="lck-health-<?php p($cid); ?>-title">
						<header class="lck-health-card__header">
							<h3 id="lck-health-<?php p($cid); ?>-title" class="lck-health-card__title"><?php p($ctitle); ?></h3>
							<span class="lck-badge" data-state="<?php p($cstate); ?>">
								<span class="lck-badge__dot" aria-hidden="true"></span>
								<span class="lck-badge__label">
									<span class="lck-sr-only"><?php p($stateLabels[$cstate]); ?>: </span>
									<?php p($clabel); ?>
								</span>
							</span>
						</header>
						<?php if ($cdetail !== ''): ?>
							<p class="lck-health-card__detail lck-muted"><?php p($cdetail); ?></p>
						<?php endif; ?>
						<?php if ($actions !== []): ?>
							<p class="lck-health-card__actions">
								<?php foreach ($actions as $action): ?>
									<?php
									$aid = (string)($action['id'] ?? '');
									$alabel = (string)($action['label'] ?? '');
									$ahref = $action['href'] ?? null;
									if ($alabel === '') {
										continue;
									}
									?>
									<?php if (is_string($ahref) && $ahref !== ''): ?>
										<a class="lck-btn lck-btn--secondary" href="<?php p($ahref); ?>"><?php p($alabel); ?></a>
									<?php else: ?>
										<button type="button"
											class="lck-btn lck-btn--secondary lck-health-card__action"
											data-lck-action="<?php p($aid); ?>">
											<?php p($alabel); ?>
										</button>
									<?php endif; ?>
								<?php endforeach; ?>
							</p>
						<?php endif; ?>
					</article>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if (!$supported): ?>
		<div class="lck-callout lck-callout--warning" role="status">
			<p><strong><?php p($l->t('Can\'t watch')); ?></strong> — <?php p($l->t('HealthCheck only supports file-based logging.')); ?></p>
			<p><?php p($l->t('Detected log type: %s', [(string)($status['log_type'] ?? '')])); ?></p>
		</div>
	<?php endif; ?>

	<?php if ($supported && !$topologyOk): ?>
		<div class="lck-callout lck-callout--warning" role="alert">
			<p><strong><?php p($l->t('Can\'t watch')); ?></strong> — <?php p($l->t('HealthCheck noticed a different server. Multi-server setups need one shared log file.')); ?></p>
			<p><?php p($l->t('Turn watching off, or ask your host to use one shared log file for all servers.')); ?></p>
		</div>
	<?php endif; ?>

	<?php if (!empty($status['stale'])): ?>
		<div class="lck-callout lck-callout--warning" role="status">
			<p><?php p($l->t('Background checks look stuck. Try “Check again”, or ask your host about Nextcloud cron.')); ?></p>
		</div>
	<?php endif; ?>

	<?php if ($supported && $topologyOk && empty($status['secrets_readable'])): ?>
		<div class="lck-callout lck-callout--warning" role="alert">
			<p><?php p($l->t('Stored channel secrets cannot be read. Re-enter webhook URLs.')); ?></p>
			<p><a class="lck-btn lck-btn--secondary" href="<?php p((string)($urls['alerts'] ?? '#')); ?>"><?php p($l->t('Manage alerts')); ?></a></p>
		</div>
	<?php endif; ?>

	<?php if ($supported && $topologyOk): ?>
		<section class="lck-status-card" aria-labelledby="lck-watching-title">
			<h3 id="lck-watching-title" class="lck-status-card__title"><?php p($l->t('Watching')); ?></h3>
			<p class="lck-muted lck-status-card__lead"><?php p($l->t('Turn this on so HealthCheck looks for new errors in the background.')); ?></p>
			<div class="lck-watching-row">
				<span class="lck-badge" data-state="<?php p($state); ?>">
					<span class="lck-badge__dot" aria-hidden="true"></span>
					<span class="lck-badge__label"><?php p($l->t((string)($status['label'] ?? 'Off'))); ?></span>
				</span>
				<div class="lck-switch-field">
					<input type="hidden" name="watch_enabled" value="0" form="lck-watch-form">
					<input type="checkbox" class="lck-switch-field__input lck-watching-switch" id="lck-watch-toggle"
						name="watch_enabled" value="1" form="lck-watch-form" role="switch"
						<?php if ($watch): ?>checked<?php endif; ?>
						aria-describedby="lck-watching-desc">
					<label class="lck-switch-field__label" for="lck-watch-toggle">
						<span class="lck-switch-field__track" aria-hidden="true"></span>
						<span class="lck-switch-field__text"><?php p($l->t('Watch log file')); ?></span>
					</label>
				</div>
			</div>
			<?php if ($watch && !$alertsReady): ?>
			<div class="lck-callout lck-callout--info lck-alerts-checklist" id="lck-alerts-checklist" role="status">
			<?php else: ?>
			<div class="lck-callout lck-callout--info lck-alerts-checklist" id="lck-alerts-checklist" role="status" hidden>
			<?php endif; ?>
				<p><?php p($l->t('Watching the log file does not send alerts by itself.')); ?></p>
				<ul class="lck-alerts-checklist__list">
					<li><?php p($l->t('Set up at least one alert channel')); ?></li>
					<li><?php p($l->t('Watch log file is on')); ?></li>
				</ul>
				<p><a class="lck-btn lck-btn--secondary" href="<?php p((string)($urls['alerts'] ?? '#')); ?>"><?php p($l->t('Set up alerts')); ?></a></p>
			</div>
			<p id="lck-watching-desc" class="lck-muted">
				<?php if (!empty($status['error'])): ?>
					<?php p((string)$status['error']); ?>
				<?php elseif ($watch && !empty($status['last_check_at'])): ?>
					<?php p($l->t('Last check')); ?>: <?php p(date('Y-m-d H:i', (int)$status['last_check_at'])); ?>
				<?php else: ?>
					<?php p($l->t('When on, HealthCheck checks for new errors in the background.')); ?>
				<?php endif; ?>
			</p>
			<?php if (!empty($status['error']) && $watch): ?>
				<p class="lck-status-card__actions">
					<a class="lck-btn lck-btn--ghost" href="<?php p((string)($urls['alerts'] ?? '#')); ?>"><?php p($l->t('Manage alerts')); ?></a>
				</p>
			<?php elseif ($watch): ?>
				<p class="lck-status-card__actions">
					<a class="lck-btn lck-btn--secondary" href="<?php p((string)($urls['alerts'] ?? '#')); ?>"><?php p($l->t('Manage alerts')); ?></a>
					<a class="lck-btn lck-btn--ghost" href="<?php p((string)($urls['logs'] ?? '#')); ?>"><?php p($l->t('Logs')); ?></a>
				</p>
			<?php endif; ?>
			<form id="lck-watch-form" hidden></form>
		</section>
	<?php endif; ?>
</section>
<?php include __DIR__ . '/common/page-end.php'; ?>
