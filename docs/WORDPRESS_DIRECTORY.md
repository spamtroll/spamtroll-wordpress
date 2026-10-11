# WordPress.org submission package

The installable artifact is `build/spamtroll.zip`, built with
`bash dev/build-zip.sh` and checked with `bash dev/verify-zip.sh`.
Version 0.2.1 has a matching plugin header and readme stable tag, a PHP 8.2
minimum, and compatibility verified on WordPress 7.1.3 (readme: `Tested up to: 7.1`).
Runtime Composer dependencies, their MIT licenses, and the original dependency
manifests are bundled. Developer tools and Plugin Check are excluded.
The build preserves checkout dependencies and normalizes timestamps and ZIP
entry ordering; `SOURCE_DATE_EPOCH` can pin the timestamp for exported checkouts.

## Listing

- Name: Spamtroll Anti-Spam.
- Requested slug and text domain: `spamtroll` (availability/approval is determined by WordPress.org).
- Description, installation, FAQ, external services, privacy links, changelog:
  `readme.txt` is the authoritative ready-to-upload listing.
- Service: an API key from https://spamtroll.io is required; scanning is disabled
  on installation until an administrator explicitly enables it.
- External requests: comment text or registration username, visitor IP, email,
  author/username, and source are sent for scanning. Optional moderator feedback
  sends the scan identifier and moderator verdict. No analytics, remote assets,
  or visitor requests are sent solely by activating the plugin.
- Account terms: https://spamtroll.io/terms; privacy: https://spamtroll.io/privacy.
- `Contributors: spamtroll` is the intended maintainer handle. The submitting
  owner must use a WordPress.org account authorized to represent this brand
  and confirm that handle before upload; no account ownership has been assumed.

## Verification

The QA workflow runs the existing PHP 8.2–8.4 unit/regression and quality gates,
archive checks, and a real disposable WordPress/MariaDB fixture. The fixture
tests activation, disabled-by-default settings, schema upgrade without settings
loss, comment verdict hooks, persistent scan logs, privacy export/erasure,
network activation including a newly created site, network deactivation, and
uninstall across all sites. Synthetic HTTP responses prevent outbound scans;
no service credentials or customer data are used.

To repeat the database checks, provide a dedicated disposable MariaDB database
and `wp` CLI with PHP 8.2+, then run:

```sh
WP_TEST_DB_HOST=127.0.0.1:3306 WP_TEST_DB_NAME=spamtroll_qa \
WP_TEST_DB_USER=root WP_TEST_DB_PASSWORD=qa-only bash dev/verify-wordpress.sh
```

WordPress Plugin Check 2.1.0 runs on the actual ZIP under WordPress 7.1.3.
Zero errors are required. Remaining warnings mostly concern custom-table
queries, read-only admin URL parameters, and conditional `WP_DEBUG` diagnostics;
they require reviewer context, not a claim of directory approval. The internal
SDK transport exceptions are intentionally unescaped until the HTML output
boundary and include targeted comments explaining why they are never page output.
`load_plugin_textdomain()` remains for downloadable/self-hosted distributions.

## Submission and remaining external step

1. Review the public [directory guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/).
2. Sign in to the authorized owner account at the [submission page](https://wordpress.org/plugins/developers/add/).
3. Confirm the contributor handle and requested `spamtroll` slug, then upload the verified ZIP.
4. Record the review identifier and respond to the Plugins Team's specific findings.
5. After approval, publish the same reviewed files to the assigned SVN repository
   and record the public listing and version.

No directory submission has been sent and no WordPress.org owner session is
available to this task. Package verification does not constitute directory
approval. The account-based submission remains the only external prerequisite.
