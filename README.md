# Spamtroll Anti-Spam for WordPress

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-purple.svg)](https://php.net)

Real-time spam detection for WordPress comments and user registrations, powered by the [Spamtroll API](https://spamtroll.io).

## Features

- **Comment scanning** — automatically checks incoming comments for spam using the Spamtroll API
- **Registration scanning** — blocks spam bots from creating accounts on your site
- **The API's verdict, not a local guess** — the plugin acts on the `blocked` / `suspicious` / `safe` status the backend returns, so the thresholds you set for the platform in your Spamtroll dashboard are the thresholds your site enforces
- **One sensitivity preset** — decides only how borderline content is treated
- **Role-based bypass** — skip scanning for trusted roles (administrators, editors, etc.)
- **Fail-open architecture** — API errors never block legitimate content, and every path is covered by a test
- **Latency budget and circuit breaker** — an unreachable API costs a visitor one attempt, not sixteen seconds, and stops being called at all while it stays down
- **Visible failures** — a rejected key or a disabled platform raises a notice in wp-admin instead of a line in `debug.log`
- **Moderator feedback** — marking a comment as spam (or restoring it) teaches the classifier for your platform
- **Privacy tooling** — scan records are wired into WordPress's own personal-data export and erasure, with configurable retention
- **Detailed logging** — view scan results with status, scores, and threat categories in the admin panel
- **Full i18n support** — translation-ready with included `.pot` template

## Requirements

- WordPress 6.0 or higher
- PHP 8.2 or higher (the plugin refuses to load on anything older and says so in wp-admin)
- A Spamtroll API key ([get one at spamtroll.io](https://spamtroll.io))

## Installation

### Manual Installation (ZIP Upload)

1. Download the latest release ZIP from the [Releases page](https://github.com/spamtroll/spamtroll-wordpress/releases)
2. In your WordPress admin panel, go to **Plugins → Add New → Upload Plugin**
3. Upload the ZIP file and click **Install Now**
4. Click **Activate Plugin**

### Manual Installation (FTP/File Manager)

1. Download and extract the latest release
2. Upload the `spamtroll-wordpress` folder to `/wp-content/plugins/`
3. In your WordPress admin panel, go to **Plugins**
4. Find **Spamtroll Anti-Spam** in the list and click **Activate**

### Installation from Source

```bash
cd /path/to/wordpress/wp-content/plugins
git clone https://github.com/spamtroll/spamtroll-wordpress.git
```

Then activate the plugin in your WordPress admin panel under **Plugins**.

## Configuration

After activation, navigate to **Spamtroll → Settings** in your WordPress admin sidebar.

### 1. API Configuration

| Setting | Description | Default |
|---|---|---|
| Enable Plugin | Turn spam scanning on/off | Disabled |
| API Key | Your Spamtroll API key | — |

Once a key is saved the field shows a masked placeholder; leave it alone to
keep the stored key, or type a new one to replace it. The **Test Connection**
button checks the key that is in the box, not the one in the database.

### 2. Detection Settings

| Setting | Description | Default |
|---|---|---|
| Check Comments | Enable comment spam scanning | Enabled |
| Check Registrations | Enable registration spam scanning | Enabled |
| Sensitivity | How borderline content is treated | Balanced |

The API decides whether something is spam. Sensitivity only decides what this
site does about that decision:

| Preset | `blocked` | `suspicious` | `safe` |
|---|---|---|---|
| Lenient | Hold for moderation | Publish | Publish |
| Balanced | Mark as spam | Hold for moderation | Publish |
| Strict | Mark as spam | Mark as spam | Publish |

To make the plugin more or less aggressive about what counts as spam in the
first place, change the spam threshold for the platform in your Spamtroll
dashboard — the plugin follows it.

### 3. Bypass Settings

Select which WordPress user roles should bypass spam scanning entirely. By
default, **Administrator** and **Editor** roles are bypassed. Unticking every
role means exactly that: nobody bypasses the scan.

### 4. Advanced

| Setting | Description | Default |
|---|---|---|
| API URL | Spamtroll API endpoint — change only for a self-hosted instance | `https://api.spamtroll.io/api/v1` |
| Latency budget | Longest a visitor waits for the whole check, retries included | 3 seconds |
| Behind a proxy or CDN | Read the visitor IP from `X-Forwarded-For`, `X-Real-IP` or `CF-Connecting-IP` | Off |
| Send moderator feedback | Report spam/not-spam corrections back to the API | On |
| Log Retention | Number of days to keep log entries (1–365) | 30 |

Only tick **Behind a proxy or CDN** if the site really is behind one. Anyone
can send those headers, so on a directly exposed site it would let a spammer
choose their own reputation.

## Viewing Logs

Navigate to **Spamtroll → Logs** to see all scan results. You can filter by status:

- **All** — every scanned item
- **Blocked** — items identified as spam
- **Suspicious** — items flagged for review
- **Safe** — items that passed scanning

Each log entry shows the date, content type, IP address, status, spam score, action taken, and a content preview.

## How It Works

1. When a comment is submitted or a user registers, the plugin sends the content, IP address, username, and email to the Spamtroll API
2. The API answers with a verdict — **blocked**, **suspicious** or **safe** — plus the raw score, the detection symbols and the threat categories
3. The sensitivity preset turns that verdict into an action: mark as spam, hold for moderation, or publish
4. The result is logged to the database for review, and the identifier of the scan is kept so a moderator's later correction can be fed back
5. Identical resubmissions from the same address reuse the verdict for five minutes, so a bot replaying one payload does not burn the daily quota

If the API is unreachable or returns an error, the content is **always allowed
through** (fail-open), so legitimate users are never blocked by connectivity
issues. Every one of the fourteen failure paths in the audit is covered by a
test that runs on each commit.

While the API stays down, a circuit breaker stops the plugin calling it at all
for a few minutes, so an outage on our side does not become slow comment forms
on yours. When the cause is a rejected key or a disabled platform, wp-admin
says so — silence is not an acceptable way for an anti-spam plugin to fail.

## Privacy

The plugin sends the submitted text, the visitor's IP address, their email
address and their chosen username to `api.spamtroll.io`, and keeps the result
— including the IP, the email and a 500-character excerpt of the text — in its
own table for the configured retention period.

Those records are wired into WordPress's own privacy tools, so
**Tools → Export Personal Data** and **Tools → Erase Personal Data** cover
them, and the plugin contributes suggested wording to
**Settings → Privacy**.

## Plugin Structure

```
spamtroll-wordpress/
├── spamtroll.php              # Main plugin file & bootstrap
├── uninstall.php              # Clean uninstall (removes all data)
├── includes/
│   ├── class-spamtroll-admin.php          # Admin settings & logs UI
│   ├── class-spamtroll-api-client.php     # HTTP client for Spamtroll API
│   ├── class-spamtroll-api-exception.php  # Custom exception handling
│   ├── class-spamtroll-api-response.php   # Response wrapper & score normalization
│   ├── class-spamtroll-logger.php         # Database logging
│   └── class-spamtroll-scanner.php        # Comment & registration scanning logic
├── assets/
│   ├── css/admin.css          # Admin panel styles
│   └── js/admin.js            # Admin panel scripts (test connection)
└── languages/
    └── spamtroll.pot           # Translation template
```

## Uninstallation

When you delete the plugin through the WordPress admin panel, it performs a clean removal:

- Removes all plugin settings from the database
- Drops the `{prefix}spamtroll_logs` table
- Clears any scheduled cron jobs

Simply deactivating the plugin does **not** remove any data — only full deletion does.

## Frequently Asked Questions

### Where do I get an API key?

Visit [spamtroll.io](https://spamtroll.io) to create an account and obtain your API key.

### Will this plugin slow down my site?

The plugin makes a single API call per comment or registration. The default timeout is 5 seconds. If the API is slow or unreachable, the content is allowed through immediately without blocking the user experience.

### Does this work with custom comment forms?

Yes, as long as the form uses WordPress's standard comment submission hooks (`preprocess_comment` and `pre_comment_approved`).

### Can I use this alongside other anti-spam plugins?

Yes, Spamtroll works at the comment preprocessing level and is compatible with other anti-spam solutions. However, running multiple spam plugins may result in redundant checks.

### What happens if I deactivate the plugin?

Your settings and logs are preserved. Comments and registrations will proceed without spam checking. Reactivate the plugin to resume scanning.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a full list of changes.

## License

This project is licensed under the MIT License — see the [LICENSE](LICENSE) file for details.
