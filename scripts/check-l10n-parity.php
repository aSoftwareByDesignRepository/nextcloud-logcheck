<?php

declare(strict_types=1);

/**
 * Verifies every LogCheck locale contains the same msgid set as en.json.
 *
 * Usage: php scripts/check-l10n-parity.php
 */

$base = __DIR__ . '/../l10n';
$localeFiles = ['en', 'de', 'fr', 'es', 'da', 'nl', 'it', 'pl', 'sv', 'nb', 'pt_BR'];
$catalogs = [];

foreach ($localeFiles as $lang) {
	$path = $base . '/' . $lang . '.json';
	if (!is_file($path)) {
		fwrite(STDERR, "Missing locale file: $path\n");
		exit(1);
	}
	$catalogs[$lang] = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

$enKeysOrdered = array_keys($catalogs['en']['translations'] ?? []);
$enKeys = $enKeysOrdered;
sort($enKeys);
$ok = true;

foreach (array_diff($localeFiles, ['en']) as $lang) {
	$langKeys = array_keys($catalogs[$lang]['translations'] ?? []);
	$missing = array_values(array_diff($enKeys, $langKeys));
	$extra = array_values(array_diff($langKeys, $enKeys));
	sort($missing);
	sort($extra);
	if ($missing !== []) {
		$ok = false;
		fwrite(STDERR, "Keys missing in {$lang}.json (" . count($missing) . "):\n");
		foreach ($missing as $key) {
			fwrite(STDERR, "  - {$key}\n");
		}
	}
	if ($extra !== []) {
		$ok = false;
		fwrite(STDERR, "Extra keys in {$lang}.json (" . count($extra) . "):\n");
		foreach ($extra as $key) {
			fwrite(STDERR, "  - {$key}\n");
		}
	}
}

if (!$ok) {
	fwrite(STDERR, "\nl10n parity check FAILED.\n");
	exit(1);
}

echo 'l10n parity OK (' . count($enKeys) . " keys, en/de/fr/es/da/nl/it/pl/sv/nb/pt_BR).\n";
exit(0);
