<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
$section = (string)($_['settingsSection'] ?? 'alerts');
include __DIR__ . '/common/page-start.php';
include __DIR__ . '/parts/settings-nav.php';
$partial = __DIR__ . '/parts/settings/' . $section . '.php';
if (is_readable($partial)) {
	include $partial;
}
include __DIR__ . '/common/page-end.php';
