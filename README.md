# Spamtroll Anti-Spam for WordPress

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![WordPress](https://img.shields.io/badge/WordPress-5.6%2B-blue.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net)

Real-time spam detection for WordPress comments and user registrations, powered by the [Spamtroll API](https://spamtroll.io).

## Features

- **Comment scanning** — automatically checks incoming comments for spam using the Spamtroll API
- **Registration scanning** — blocks spam bots from creating accounts on your site
- **Configurable thresholds** — set separate spam and suspicious score thresholds (0–100%)
- **Flexible actions** — choose to block or send to moderation for spam and suspicious content
- **Role-based bypass** — skip scanning for trusted roles (administrators, editors, etc.)
- **Fail-open architecture** — API errors never block legitimate content
- **Detailed logging** — view scan results with status, scores, and threat categories in the admin panel
- **Automatic log cleanup** — daily cron job removes old log entries based on configurable retention period
- **Full i18n support** — translation-ready with included `.pot` template

## Requirements

- WordPress 5.6 or higher
- PHP 7.4 or higher
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
| API URL | Spamtroll API endpoint | `https://api.spamtroll.io/api/v1` |
| Timeout | API request timeout in seconds | 5 |

Use the **Test Connection** button to verify your API key is valid.

### 2. Detection Settings

| Setting | Description | Default |
|---|---|---|
| Check Comments | Enable comment spam scanning | Enabled |
| Check Registrations | Enable registration spam scanning | Enabled |
| Spam Threshold | Score above which content is treated as spam (0.0–1.0) | 0.70 |
| Suspicious Threshold | Score above which content is treated as suspicious (0.0–1.0) | 0.40 |

### 3. Actions

| Setting | Description | Default |
|---|---|---|
| Spam Action | What to do with spam content: **Block** or **Send to moderation** | Block |
| Suspicious Action | What to do with suspicious content: **Send to moderation** or **Allow** | Send to moderation |

### 4. Bypass Settings

Select which WordPress user roles should bypass spam scanning entirely. By default, **Administrator** and **Editor** roles are bypassed.

### 5. Maintenance

| Setting | Description | Default |
|---|---|---|
| Log Retention | Number of days to keep log entries (1–365) | 30 |

## Viewing Logs

Navigate to **Spamtroll → Logs** to see all scan results. You can filter by status:

- **All** — every scanned item
- **Blocked** — items identified as spam
- **Suspicious** — items flagged for review
- **Safe** — items that passed scanning

Each log entry shows the date, content type, IP address, status, spam score, action taken, and a content preview.

## How It Works

1. When a comment is submitted or a user registers, the plugin sends the content, IP address, username, and email to the Spamtroll API
2. The API returns a spam score (normalized to 0–1) along with detection symbols and threat categories
3. Based on your configured thresholds, the plugin categorizes the content as **blocked**, **suspicious**, or **safe**
4. The configured action is taken automatically (block, moderate, or allow)
5. The result is logged to the database for review

If the API is unreachable or returns an error, the content is **always allowed through** (fail-open), ensuring legitimate users are never blocked by connectivity issues.

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
