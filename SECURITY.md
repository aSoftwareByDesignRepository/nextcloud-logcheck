# Security Policy

## Supported versions

We support the latest published HealthCheck release on currently supported Nextcloud majors listed in `appinfo/info.xml`.

## Reporting a vulnerability

Please email **info@software-by-design.de** (or use GitHub Security Advisories on this repository) with a description and steps to reproduce. Do not open a public GitHub issue for security reports.

We aim to acknowledge reports within a few business days.

## Operator notes (privacy)

- Log digests default to **counts and summaries only**. Raw log excerpts stay off unless a Nextcloud system admin opts in and types `CONFIRM`.
- The **Logs** page lets entitled operators view a **bounded** recent window of the systemconfig logfile and search with **literal** text (no regex). Absolute path is shown only to Nextcloud system admins.
- **Download full file** (streamed copy of an allowlisted log) requires a Nextcloud **system admin**. App Admins keep the in-app viewer/search for triage without full-file export.
- Older copies next to the live file (Nextcloud `.1`…`.50` and HealthCheck start-fresh archives) can be listed and opened by **basename id only** — never a client-supplied path. Alert watching always stays on the live systemconfig file.
- **Start fresh** (rename aside) and **Delete** (live) require a Nextcloud **system admin**, typed confirmation (`START_FRESH` / `DELETE`), and briefly hold the watch lease so the background job cannot race the file change. Prefer start-fresh over delete when you need evidence. Removing an older copy uses `DELETE_COPY` and does not change the watch cursor.
- Enabling excerpts can send personal data or secrets that other apps wrote into the log (including to email, Slack, or webhooks outside your country). Treat that as a deliberate data-transfer decision for your organisation.
- Slack and generic webhooks can only be **turned on after a successful test** (Home turn-on, or Alerts → Send test → Save). Changing a saved URL clears that proof — you must test the new URL before enabling again.
- Outbound URLs are checked for SSRF (HTTPS-only, no redirects, DNS pin). Cloud metadata endpoints (AWS/Azure/Alibaba/GCP) stay blocked even if “private webhooks” is on.
- Channel failure messages shown in the UI are fixed safe strings; detailed transport errors stay in server logs.
- `occ logcheck:*` commands are available to anyone who can run `occ` on the server (host/admin trust boundary), not App Admins alone.

## Supported topology

HealthCheck is supported on **one** Nextcloud server (or a cluster that shares the **same** log file). Multiple application servers each with their own local log file are **not supported** — digests would miss or duplicate lines.

When watching is turned **on**, this node is pinned in settings immediately. Another app server that then tries to watch will see **Can’t watch** instead of silently sharing the cursor. Turning watching **off** clears the pin so you can move hosts deliberately.
