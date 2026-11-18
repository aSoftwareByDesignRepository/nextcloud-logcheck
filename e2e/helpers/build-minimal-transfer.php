#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Build a minimal but 100% format-honest `checkapp-config-transfer/2`
 * container for e2e fixtures — sealed by the app's own TransferFormat /
 * TransferCrypto, so decrypt + checksum + AAD all validate exactly like a
 * real export. Used when a test needs a file with a precise key set (e.g.
 * a single config key for a count=1 preview verdict), which the real
 * export endpoint cannot produce (it always ships every set key).
 *
 * Usage:
 *   php build-minimal-transfer.php <out.nctransfer> <passphrase>
 *       <sourceInstance> <appVersion> <configJsonObject>
 *
 * <configJsonObject>: JSON object of manifest key => file-string value
 * (e.g. {"health_disabled_probes":"[\"updates\"]"}).
 */

$appRoot = dirname(__DIR__, 2);
foreach (['TransferException', 'TransferFormat', 'TransferCrypto'] as $cls) {
	require $appRoot . '/lib/ConfigTransfer/' . $cls . '.php';
}

use OCA\LogCheck\ConfigTransfer\TransferCrypto;
use OCA\LogCheck\ConfigTransfer\TransferFormat;

[$_s, $out, $passphrase, $sourceInstance, $appVersion, $configJson] = array_pad($argv, 6, null);
if (!$out || !$passphrase || !$sourceInstance || !$appVersion || !$configJson) {
	fwrite(STDERR, "usage: build-minimal-transfer.php <out> <passphrase> <sourceInstance> <appVersion> <configJson>\n");
	exit(2);
}
$config = json_decode($configJson, true, 16, JSON_THROW_ON_ERROR);
if (!is_array($config) || array_is_list($config) || $config === []) {
	fwrite(STDERR, "config must be a non-empty JSON object\n");
	exit(2);
}

$payload = [
	'config' => $config,
	'references' => [],
	'tables' => [],
	'appdata' => [],
	'source_instance' => $sourceInstance,
	'source_url' => 'http://localhost:8081',
	'secrets_included' => false,
];
$payload = ['checksum' => TransferFormat::checksum($payload)] + $payload;

$header = [
	'format' => TransferFormat::FORMAT,
	'app' => 'logcheck',
	'app_version' => $appVersion,
	'schema_version' => 1,
	'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
	'source_instance' => $sourceInstance,
];
$sealed = TransferCrypto::seal(
	TransferFormat::compress((string) json_encode($payload, JSON_THROW_ON_ERROR)),
	$passphrase,
	TransferFormat::aadFor($header),
);
file_put_contents($out, json_encode($header + $sealed, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
echo $out . "\n";
exit(0);
