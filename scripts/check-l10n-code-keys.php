<?php

declare(strict_types=1);

/**
 * Ensures every $l->t / $l->n / t('logcheck', …) msgid exists in en.json.
 *
 * Usage: php scripts/check-l10n-code-keys.php
 */

$root = dirname(__DIR__);
$enPath = $root . '/l10n/en.json';
$en = json_decode((string)file_get_contents($enPath), true, 512, JSON_THROW_ON_ERROR);
$enKeys = array_keys($en['translations'] ?? []);
$enSet = array_fill_keys($enKeys, true);

$used = [];
$missing = [];

$scanDirs = [$root . '/lib', $root . '/templates', $root . '/js'];
foreach ($scanDirs as $dir) {
	if (!is_dir($dir)) {
		continue;
	}
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
	foreach ($it as $file) {
		if (!$file->isFile()) {
			continue;
		}
		$ext = $file->getExtension();
		if (!in_array($ext, ['php', 'js'], true)) {
			continue;
		}
		$src = (string)file_get_contents($file->getPathname());
		$rel = substr($file->getPathname(), strlen($root) + 1);

		if (preg_match_all("/->t\(\s*'((?:\\\\'|[^'])*)'/", $src, $m)) {
			foreach ($m[1] as $raw) {
				$key = str_replace(["\\'", '\\"'], ["'", '"'], $raw);
				$used[$key] = true;
				if (!isset($enSet[$key])) {
					$missing[$key][] = $rel;
				}
			}
		}
		if (preg_match_all("/->n\(\s*'((?:\\\\'|[^'])*)'\s*,\s*'((?:\\\\'|[^'])*)'/", $src, $m, PREG_SET_ORDER)) {
			foreach ($m as $row) {
				$a = str_replace("\\'", "'", $row[1]);
				$b = str_replace("\\'", "'", $row[2]);
				$ck = '_' . $a . '_::_' . $b . '_';
				$used[$ck] = true;
				if (!isset($enSet[$ck])) {
					$missing[$ck][] = $rel . ' (n)';
				}
			}
		}
		if ($ext === 'js' && preg_match_all("/t\(\s*'logcheck'\s*,\s*'((?:\\\\'|[^'])*)'/", $src, $m)) {
			foreach ($m[1] as $raw) {
				$key = str_replace("\\'", "'", $raw);
				$used[$key] = true;
				if (!isset($enSet[$key])) {
					$missing[$key][] = $rel;
				}
			}
		}
	}
}

if ($missing !== []) {
	fwrite(STDERR, "Code msgids missing from en.json:\n");
	foreach ($missing as $key => $files) {
		fwrite(STDERR, '  - ' . $key . "\n");
		foreach (array_unique($files) as $f) {
			fwrite(STDERR, "      {$f}\n");
		}
	}
	exit(1);
}

echo 'l10n code keys OK (' . count($used) . " msgids referenced).\n";
exit(0);
