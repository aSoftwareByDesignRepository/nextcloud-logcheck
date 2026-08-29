# Changelog

## 1.3.22 — 2026-08-27

- Fix: in-app notification icon must be an absolute URL (NC 34) — stops InvalidValueException / deprecation spam when the bell loads
- UX: alert notifications open Logs; paused-channel notifications open Alerts; clearer notification body text

## 1.3.21 — 2026-08-27

- UX: People settings explain who can open HealthCheck (Nextcloud admins, app admins, everyone else)

## 1.3.20 — 2026-08-27

- Security: record delivery as sent even if pending markSent loses claim_gen (reclaim duplicate HTTP)
- Reliability: pin watcher_node when watch is turned on; clear on disable (topology gate before first run)
- Reliability: patchRuntime retries with backoff under contended settings saves
- Audit: email recipient list changes (count only, no addresses)
- UX: clearer Watching section heading and help text on Home
- Brand: replace SnackCheck fridge icon with HealthCheck status ring + heartbeat (`app.svg`, `app-dark.svg`)

## 1.3.19 — 2026-08-27

- Brand: App Store listing name and navigation match the UI — **HealthCheck** everywhere users see it (technical app id remains `logcheck`)

## 1.3.18 — 2026-08-27

- Security: full log download is Nextcloud system admin only (App Admins keep in-app viewer/search)
- Reliability: skip outbound channel send when DeliveryStore already recorded `sent` (stale-reclaim dedupe)

## 1.3.17 — 2026-08-27

- A11y: form fields, chips, and buttons use solid `--lck-focus` outlines (WCAG 2.4.11); no `outline:none` with only a diluted halo
- Tokens: form control heights bind to `--lck-touch`

## 1.3.16 — 2026-08-27

- Security: fix AuditService TypeError — `CriticalActionPerformedEvent` now gets `array` parameters (settings audits no longer abort saves)
- Security: re-enable channel no longer clears auto-disable before a successful test (broken channels stay blocked)
- Security: `occ logcheck:test-channel` prints operator-safe errors (no raw webhook hosts)
- Docs: DE App Store blurb no longer claims email turn-on lives on the home page

## 1.3.15 — 2026-08-27

- Architecture: WatchRunner no-ops on topology mismatch (Can't watch ⇒ no log processing)
- Architecture: purge aged `sent` rows from `lck_pending` (delivery audit retains history)

## 1.3.14 — 2026-08-27

- Security: redact `runtime.watcher_node` from UI/API DTO (topology fingerprint)
- Security: block deprecated IPv6 site-local `fec0::/10` in SSRF guard
- Security: audit `channel_tested` / `check_run`; never log raw channel exception text

## 1.3.13 — 2026-08-27

- E2E: WCAG 2.4.11 focus-ring contrast ≥3:1 across all 4 NC themes
- npm `e2e:theme` script for theme/responsive/contrast gauntlet

## 1.3.12 — 2026-08-27

- E2E: programmatic WCAG 1.4.3 contrast checks across all 4 NC themes
- E2E: visual layout regression (geometry fingerprints @ 320/768/1440/2560 + modal axe)

## 1.3.11 — 2026-08-27

- Theme/responsive: solid `--lck-focus` rings (no diluted color-mix) for WCAG 2.4.7 / 2.4.11
- E2E theme gauntlet expanded to all 6 routes × 4 NC themes × 6 viewports + custom CSS override test

## 1.3.10 — 2026-08-27

- Bachus F-05 / NH-B01: watch toggle and settings Save refresh status in-place (no full reload; reload only on 409 conflict or Check again)
- F-11: remove redundant multi-server callout from Support page
- E2E: **J-BACH-01…06** journey suite (Bachus acceptance)

## 1.3.9 — 2026-08-27

- Health cards: one recovery action on every non-ok probe (MH-B05) — security settings, admin overview, cron, logs, alerts
- E2E: align Alerts/Logs tests with **Test & turn on** and danger-zone `<details>`

## 1.3.8 — 2026-08-27

- Bachus UX: per-channel **Send test & turn on** on Alerts (MH-B01)
- Home: watch vs alerts checklist; single **Check again**; **Watch log file** label (MH-B02, MH-B06)
- Log health card: **Set up alerts** (honest navigation label)
- Access denied + People search loading/empty states; HealthCheck branding (MH-B03, MH-B07)


## 1.3.7 — 2026-08-27

- Logs viewer: readable structured rows (time, level badge, app, message) with text-safe rendering
- Severity filter chips (All / Warnings+ / Errors+) — viewer-only; session persistence; server-side tail/before/search filtering
- Bachus toolbar: search row; Reload · Copy · More (download, raw toggle, remove copy); Load older below viewer
- Loading and empty-filter states with Show all levels + Load older CTAs
- Danger zone (start fresh / delete) moved under progressive disclosure for NC admins

## 1.3.6 — 2026-08-26

- Fix dead “Check again” on the watch status card (bind all `data-lck-action="check-again"`)
- Re-enable & test for Slack and webhook when auto-disabled (not only email)
- Hook HealthCheck error toasts into “Report this problem”
- E2E: exhaustive shipped-control smoke (`e2e/controls.spec.js`)


## 1.3.5 — 2026-08-26

- Fix Logs “Load older” / “Download” when new API routes were not registered (stale app route cache): bump forces refresh; clearer reload toast on 404
- Log download is CSRF-bound POST (no cross-site GET download via `<a href>`)
- Separate per-action rate limits so Load older is not blocked by an immediate prior Tail
- OpenAPI covers `/api/logs/before` and `/api/logs/download`


## 1.3.4 — 2026-08-26

- Home no longer hosts email/Slack/webhook setup — one CTA to **Alerts** (channel config lives there)
- Log health “Turn on alerts” links to Alerts settings

## 1.3.3 — 2026-08-26

- Never show Watching/OK after a failed watch run or unreadable channel secrets (Health + Home)
- Pending delivery: `claim_gen` pin on markSent/markFailed so late senders cannot complete after reclaim (same-second safe)
- Channel test rate limit: atomic `add` only (fail closed without it)
- Home shows safe error copy + Check again when the last run failed

## 1.3.2 — 2026-08-26

- Consolidate HealthCheck + HealthCheck planning into one CORE (`planning/app-ideas/logcheck` v0.8.0)
- Jobs health card: Open admin settings CTA when unhealthy
- Health l10n coverage for summary / free-space / admin CTA strings

## 1.3.1 — 2026-08-26

- Harden Health probes: IPv6 + subdirectory HTTPS paths; require `installed` JSON for HTTPS OK
- Log card never green before first check; Updates refuse empty/`{}` cache as “up to date”
- Health summary strip + card CTAs (turn on / check again); shorter HTTPS soft timeout
- Disk thresholds via shared `stateForRatio`; more unit/mutation coverage

## 1.3.0 — 2026-08-26

- **Health dashboard:** instance health cards (Log, Jobs, PHP, Database, Disk, HTTPS, Updates)
- Nav: Health · Logs · Alerts · Rules · People · Support; UI brand **HealthCheck** (app id stays `logcheck`)
- Disk probe: datadirectory only; HTTPS: instance `/status.php` only; Updates: read-only core cache (no outbound)
- Argus FULL gate on ops-heavy probes; Absolute No-Gos + critical mutants for NN-H08/H09/H20

## 1.2.0 — 2026-08-26

- **Multiple log files:** pick the current log or older copies (`.1` … `.50`, HealthCheck start-fresh archives) in the same folder
- View/search older copies without changing alert watching (still the current file only)
- System admins can remove an older copy with typed `DELETE_COPY` confirmation
- Basename allowlist + realpath jail (NN-17); no client-supplied paths

## 1.1.0 — 2026-08-26

- **Logs** page: view newest log lines, literal search, copy shown lines
- **Start fresh log** (rename aside + empty file) and **Delete log** for Nextcloud system admins with typed confirmation
- Chunked I/O only (no full-file load); watch lease held during mutate; cursor re-seeded
- Nav chrome: Logs under Watch; SECURITY / OpenAPI / l10n updated

## 1.0.0 — 2026-08-26

- Initial release: file-log watch, digests, email / Slack / webhook / notifications
- Home-first “Turn on alerts” setup; Alerts / Rules / People / Support settings
- Restricted-only access with App Admins; excerpts default off (system-admin gate)
- Durable cursor, lease lock, pending per-channel delivery, optimistic settings versioning
