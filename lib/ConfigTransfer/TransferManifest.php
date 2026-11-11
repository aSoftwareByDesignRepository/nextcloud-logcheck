<?php

declare(strict_types=1);

/**
 * LogCheck transfer manifest — every appconfig key and every lck_* table
 * classified exactly once
 * (gate: scripts/check-config-transfer-manifest.py).
 *
 * Real config lives in the `lck_settings` singleton JSON document
 * (id=1, column `payload`, CAS-versioned behind SettingsService) — NOT in
 * appconfig. Each transferable top-level field of that document transfers
 * as a virtual store-backed key (`'store' => [json spec]`, manifest key
 * name IS the json_key): the engine reads values from the doc row, writes
 * route through TransferHooks::writeMirrored → SettingsService::save so
 * mergeAndValidate() + CAS revision apply natively. `lck_settings` itself
 * is declared `internal` so raw rows never move.
 *
 * Document fields deliberately WITHOUT a key (never transferred):
 *   - runtime.*              job-owned state (last run, watcher pin) — the
 *                            target pins its own node on watch enable.
 *   - digest_window_seconds  derived alias of coalesce_seconds — rewritten
 *                            by every load()/save() round-trip.
 *   - health_https_probe_url / health_https_probe_ip
 *                            admin pins of THIS instance's address —
 *                            instance-bound (env) by nature; store-backed
 *                            keys cannot be class 'env', so no key exists.
 *   - excerpt_max_chars / max_lines_per_digest / max_fingerprints_in_payload
 *                            defaults that the settings API never writes —
 *                            frozen values, nothing to transfer.
 *   - channels.*_cipher      ICrypto-wrapped webhook URLs — ciphertext is
 *                            bound to this instance's secret (dead bytes on
 *                            any target). They ride inside the encrypted
 *                            container only because the engine exports the
 *                            whole json field; normalizeValue strips them
 *                            and writeMirrored never sends url fields to
 *                            save(), so the target's own ciphers and proof
 *                            state are preserved and foreign cipher bytes
 *                            are never persisted.
 *   - channels.*.{verified_at,fail_count,last_error}
 *                            per-instance test-proof state — same field,
 *                            same strip-and-preserve treatment.
 */

namespace OCA\LogCheck\ConfigTransfer;

final class TransferManifest extends AbstractTransferManifest
{
	private const SCHEMA_VERSION = 1;

	private const SETTINGS_STORE = [
		'table' => 'lck_settings',
		'kind' => 'json',
		'row_id' => 1,
		'json_col' => 'payload',
	];

	public function schemaVersion(): int
	{
		return self::SCHEMA_VERSION;
	}

	/** @return array<string,array> */
	public function keys(): array
	{
		return self::normaliseKeys([
			// ── access door — restricted mode + app-admin allowlist ──
			// unresolved 'skip' (default): a ghost admin uid skips the whole
			// doc — the target door stays closed rather than half-open.
			'access' => ['class' => 'mirrored', 'security' => true, 'section' => 'people',
				'refs' => ['kind' => 'json_paths', 'uid_paths' => ['$.app_admins[*]']],
				'store' => self::SETTINGS_STORE + ['json_key' => 'access']],
			// ── watch + alert rules ──
			'watch_enabled' => ['class' => 'mirrored', 'security' => true, 'section' => 'rules',
				'store' => self::SETTINGS_STORE + ['json_key' => 'watch_enabled']],
			'min_level' => ['class' => 'mirrored', 'section' => 'rules',
				'store' => self::SETTINGS_STORE + ['json_key' => 'min_level']],
			'coalesce_seconds' => ['class' => 'mirrored', 'section' => 'rules',
				'store' => self::SETTINGS_STORE + ['json_key' => 'coalesce_seconds']],
			'app_mode' => ['class' => 'mirrored', 'section' => 'rules',
				'store' => self::SETTINGS_STORE + ['json_key' => 'app_mode']],
			'app_list' => ['class' => 'mirrored', 'section' => 'rules',
				'store' => self::SETTINGS_STORE + ['json_key' => 'app_list']],
			'mutes' => ['class' => 'mirrored', 'section' => 'rules',
				'store' => self::SETTINGS_STORE + ['json_key' => 'mutes']],
			// Widening log excerpts / private webhook egress is a
			// security-relevant grant → highlighted in the report.
			'include_message_excerpts' => ['class' => 'mirrored', 'security' => true, 'section' => 'alerts',
				'store' => self::SETTINGS_STORE + ['json_key' => 'include_message_excerpts']],
			'allow_private_webhooks' => ['class' => 'mirrored', 'security' => true, 'section' => 'alerts',
				'store' => self::SETTINGS_STORE + ['json_key' => 'allow_private_webhooks']],
			'health_disabled_probes' => ['class' => 'mirrored', 'section' => 'rules',
				'store' => self::SETTINGS_STORE + ['json_key' => 'health_disabled_probes']],
			// ── channels — recipients + enabled transfer; ciphers and
			// proof state are stripped on normalize and preserved on
			// write (see header comment). unresolved 'keep': ghost
			// recipient uids are filtered from the applied list and still
			// named in the report — one stale uid must not sink the whole
			// channel block the way it must sink the access door. ──
			'channels' => ['class' => 'mirrored', 'security' => true, 'section' => 'alerts',
				'refs' => ['kind' => 'json_paths', 'uid_paths' => ['$.notification.recipient_uids[*]']],
				'unresolved' => 'keep',
				'store' => self::SETTINGS_STORE + ['json_key' => 'channels']],

			// ── foreign core appconfig — read-only health probes
			// (backgroundjobs_mode/lastcron/cronErrors/lastupdatedat/
			// lastupdateResult); never written by this app, never ours
			// to transfer ──
			'backgroundjobs_mode' => 'internal',
			'lastcron' => 'internal',
			'cronErrors' => 'internal',
			'lastupdatedat' => 'internal',
			'lastupdateResult' => 'internal',

			// ── internal bookkeeping — never transferred ──
			'uninstall_repair_pass' => 'internal',
			'upgrade_backup_last_snapshot_id' => 'internal',
			'upgrade_backup_max_snapshots' => 'internal',
			'transfer_receipts' => 'internal',
			'transfer_job_state' => 'internal',
			'transfer_last_import_at' => 'internal',
			// Dynamic/prefix key families — prefixes, not keys; members are
			// dynamic and never transferred by definition:
			//   'upgrade_backup_*'   snapshot-restore rewrites restored keys
			//   'logcheck-upgrade-backup'  ILockingProvider lock name, not appconfig
		]);
	}

	/** @return array<string,array> */
	public function tables(): array
	{
		return self::normaliseTables([
			// ══ settings doc backing — moves via the virtual keys above ══
			'lck_settings' => ['class' => 'internal'],

			// ══ runtime state — instance-bound, never transferred ══
			// File-tail cursor: local path + offset + inode of THIS node's
			// log file.
			'lck_cursor' => ['class' => 'internal'],
			// Digest accumulation buffer between watch runs.
			'lck_accumulator' => ['class' => 'internal'],
			// Undelivered alert queue (rows are in-flight work items, not config).
			'lck_pending' => ['class' => 'internal'],
			// Channel fail/verification state — per-instance test proof.
			'lck_chan_state' => ['class' => 'internal'],
			// Distributed watch leases — live coordination, dead on arrival.
			'lck_locks' => ['class' => 'internal'],

			// ══ delivery journal — a log, never transferred ══
			'lck_delivery' => ['class' => 'log'],
		]);
	}
}
