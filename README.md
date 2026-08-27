# HealthCheck

Watch the Nextcloud admin log and get alerted when new ERROR or FATAL lines appear — via email, Slack Incoming Webhook, generic HTTPS webhook, or in-app notifications.

## Requirements

- Nextcloud 32–34
- PHP 8.2–8.5
- File-based logging (`log_type = file`)
- Working background jobs (cron recommended)

## Install

Install from the Nextcloud App Store, or place this app in `custom_apps/logcheck` and enable it as an admin.

## Usage

1. Open **HealthCheck** as a Nextcloud admin (or a HealthCheck app admin).
2. On Home, enter an email and choose **Send test & turn on**.
3. Optionally configure Slack/webhook under Alerts → More ways.
4. Open **Logs** to read the newest lines, pick older copies if present, search, copy, or (as a system admin) start a fresh log after fixing errors.

## Limits

- Syslog / systemd / errorlog backends are not supported in V1.
- The Logs page reads the systemconfig logfile (and allowlisted siblings in the same folder) in bounded chunks — never a user-chosen path.
- Start fresh / delete require a Nextcloud system admin and typed confirmation.
- Raw log excerpts in *alerts* stay off unless a system admin opts in with confirmation.

## Support

- GitHub Sponsors: https://github.com/sponsors/aSoftwareByDesignRepository
- Website: https://nextcloud.software-by-design.de/
- Issues: https://github.com/aSoftwareByDesignRepository/nextcloud-logcheck/issues

## License

AGPL-3.0-or-later
