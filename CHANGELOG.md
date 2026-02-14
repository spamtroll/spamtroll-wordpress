# Changelog

All notable changes to the Spamtroll WordPress plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-02-14

### Added
- Initial release of the Spamtroll WordPress plugin
- API client (`Spamtroll_Api_Client`) using WordPress HTTP API (`wp_remote_post`/`wp_remote_get`)
- API response wrapper (`Spamtroll_Api_Response`) with score normalization (0-15 raw → 0-1 normalized)
- API exception class (`Spamtroll_Api_Exception`) with static factory methods for common error types
- Comment spam scanning via `preprocess_comment` and `pre_comment_approved` hooks
- Registration spam scanning via `registration_errors` hook
- Fail-open behavior: all API errors allow content through (never blocks on failure)
- Role-based bypass for administrators and editors (configurable)
- Database logging with `{prefix}spamtroll_logs` table (status, scores, symbols, threat categories)
- Daily cron job for log cleanup with configurable retention period
- Admin settings page with sections: API Configuration, Detection Settings, Actions, Bypass, Maintenance
- AJAX-powered "Test Connection" button with nonce verification
- Logs viewer page with status filters (All/Blocked/Suspicious/Safe) and pagination
- Colored status badges for blocked (red), suspicious (orange), and safe (green)
- Settings link on the plugins list page
- Full i18n support with `.pot` translation template
- Clean uninstall: removes settings, drops logs table, clears cron
