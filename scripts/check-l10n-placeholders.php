<?php

declare(strict_types=1);

/**
 * Ensures translated strings keep the same printf placeholders as their msgid.
 * Plural array forms must each preserve %n (and matching %s counts for the singular/plural msgids).
 *
 * Usage: php scripts/check-l10n-placeholders.php
 */

$base = __DIR__ . '/../l10n';
// Discover every shipped catalog — a hardcoded roster goes stale on each new locale.
$localeFiles = [];
foreach (glob($base . '/*.json') ?: [] as $path) {
	$name = basename($path, '.json');
	if ($name === '' || $name[0] === '_') {
		continue;
	}
	$localeFiles[] = $name;
}
sort($localeFiles);
if (!in_array('en', $localeFiles, true)) {
	fwrite(STDERR, "Reference catalog l10n/en.json missing.\n");
	exit(1);
}

/**
 * @return list<string>
 */
function lckPrintfPlaceholders(string $s): array
{
	preg_match_all('/%%|%(?:\d+\$)?[sdn]/', $s, $m);
	return $m[0];
}

$catalogs = [];
foreach ($localeFiles as $lang) {
	$path = $base . '/' . $lang . '.json';
	if (!is_file($path)) {
		fwrite(STDERR, "Missing locale file: $path\n");
		exit(1);
	}
	$catalogs[$lang] = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

$enT = $catalogs['en']['translations'] ?? [];
$failed = false;
// Expected plural form count per locale comes from each catalog's own
// pluralForm header (nplurals=N) — never a hardcoded map.
$expectedPluralCounts = [];
foreach ($localeFiles as $lang) {
	$pf = (string)($catalogs[$lang]['pluralForm'] ?? '');
	if (preg_match('/nplurals=(\d+)/', $pf, $m)) {
		$expectedPluralCounts[$lang] = (int)$m[1];
	}
}

foreach ($enT as $key => $enVal) {
	$keyPrintf = is_string($enVal) ? lckPrintfPlaceholders($key) : lckPrintfPlaceholders((string)$key);
	// For combined plural keys, placeholders come from the key itself (_a_::_b_).
	if (is_array($enVal)) {
		$keyPrintf = [];
		foreach ($enVal as $form) {
			$keyPrintf = array_merge($keyPrintf, lckPrintfPlaceholders((string)$form));
		}
		// Compare per-form against same-index in each locale below.
	}

	foreach ($localeFiles as $lang) {
		$langT = $catalogs[$lang]['translations'] ?? [];
		if (!isset($langT[$key])) {
			$failed = true;
			fwrite(STDERR, "{$lang}.json missing key: {$key}\n");
			continue;
		}
		$val = $langT[$key];
		if (is_array($enVal)) {
			if (!is_array($val)) {
				$failed = true;
				fwrite(STDERR, "{$lang}.json plural key is not an array: {$key}\n");
				continue;
			}
			$want = $expectedPluralCounts[$lang] ?? 2;
			if (count($val) !== $want) {
				$failed = true;
				fwrite(STDERR, "{$lang}.json plural form count for {$key}: want {$want}, got " . count($val) . "\n");
			}
			foreach ($val as $i => $form) {
				$formPh = lckPrintfPlaceholders((string)$form);
				$ref = isset($enVal[$i]) ? lckPrintfPlaceholders((string)$enVal[$i]) : ['%n'];
				// Polish has 3 forms; EN has 2 — each form must contain %n.
				if (!in_array('%n', $formPh, true) && str_contains($key, '%n')) {
					$failed = true;
					fwrite(STDERR, "{$lang}.json plural form {$i} missing %n for {$key}\n");
				}
				$sCount = count(array_filter($formPh, static fn (string $p): bool => str_contains($p, 's')));
				$refS = count(array_filter($ref, static fn (string $p): bool => str_contains($p, 's')));
				if ($sCount !== $refS && isset($enVal[$i])) {
					$failed = true;
					fwrite(STDERR, "{$lang}.json %s count mismatch form {$i} for {$key}\n");
				}
			}
			continue;
		}
		if (!is_string($val)) {
			$failed = true;
			fwrite(STDERR, "{$lang}.json non-string for {$key}\n");
			continue;
		}
		$valPrintf = lckPrintfPlaceholders($val);
		$enPrintf = lckPrintfPlaceholders((string)$enVal);
		if ($enPrintf !== $valPrintf && lckPrintfPlaceholders($key) !== $valPrintf) {
			// Allow translation to match either msgid key or English value placeholder multiset.
			sort($enPrintf);
			$sortedVal = $valPrintf;
			sort($sortedVal);
			$fromKey = lckPrintfPlaceholders($key);
			sort($fromKey);
			if ($sortedVal !== $enPrintf && $sortedVal !== $fromKey) {
				$failed = true;
				fwrite(STDERR, "{$lang}.json printf placeholder mismatch for key: {$key}\n");
				fwrite(STDERR, '  expected: ' . implode(', ', $enPrintf ?: $fromKey) . "\n");
				fwrite(STDERR, '  got:      ' . implode(', ', $valPrintf) . "\n");
			}
		}
	}
}

if ($failed) {
	fwrite(STDERR, "\nl10n placeholder check FAILED.\n");
	exit(1);
}

echo "l10n placeholders OK.\n";
exit(0);
