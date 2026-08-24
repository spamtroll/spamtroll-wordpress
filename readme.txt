=== Spamtroll Anti-Spam ===
Contributors: spamtroll
Tags: spam, antispam, comments, registration, moderation
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.2
Stable tag: 0.2.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Real-time spam detection for comments and user registrations, powered by the Spamtroll API.

== Description ==

Spamtroll checks incoming comments and registrations against the Spamtroll API and acts on the verdict it gets back: mark as spam, hold for moderation, or publish. The scoring happens on the server side, where it can see traffic across every site using the service, so the thresholds you set for your platform in the Spamtroll dashboard are the thresholds this plugin enforces.

= What it does =

* Scans comments and user registrations
* Acts on the API's verdict — `blocked`, `suspicious` or `safe` — rather than re-deriving one locally
* One sensitivity preset decides only how borderline content is treated
* Skips scanning for the user roles you trust
* Feeds a moderator's "Spam" and "Not spam" corrections back to the API, so detection improves for your platform
* Keeps a searchable log of every scan, pruned automatically
* Ships an admin notice when scanning has stopped working, instead of failing quietly

= Fail-open by design =

If the API cannot answer — a timeout, an outage, a rejected key, an exhausted quota — the content is allowed through unscanned. An anti-spam plugin that blocks legitimate comments during its own outage is worse than one that misses spam.

A latency budget caps how long a visitor can be kept waiting, and a circuit breaker stops the plugin calling an API it already knows is down, so an outage on our side does not turn into slow comment forms on yours.

== External services ==

This plugin sends data to the Spamtroll API at `https://api.spamtroll.io` (or to a self-hosted instance, if you configure one), which is required for it to work at all.

**What is sent, and when:** every time a visitor submits a comment or registers an account, the plugin sends the submitted text, the visitor's IP address, their email address, their chosen username, and a label saying whether this was a comment or a registration.

If you leave "Send moderator feedback" enabled, then when a moderator marks a comment as spam or restores it, the plugin also sends the identifier of the earlier scan and the moderator's verdict. The comment text is not re-sent.

Spamtroll is operated by Spamtroll: [terms of service](https://spamtroll.io/terms), [privacy policy](https://spamtroll.io/privacy).

**What is stored locally:** the result of each scan — the score, the reason codes, the visitor's IP address, their email address and a 500-character excerpt of the submitted text — is kept in a table on your own site for the retention period you configure (30 days by default) and then deleted automatically. Those records are covered by WordPress's own Tools → Export Personal Data and Tools → Erase Personal Data.

== Installation ==

1. Upload the plugin ZIP through **Plugins → Add New → Upload Plugin**, or extract it into `wp-content/plugins/`.
2. Activate it through the **Plugins** screen.
3. Go to **Spamtroll → Settings**, paste your API key, press **Test Connection**, and tick **Enable Plugin**.

You need a Spamtroll API key. [Get one at spamtroll.io](https://spamtroll.io).

If you install from a git checkout rather than a release ZIP, run `composer install --no-dev` in the plugin directory first — the release ZIP already contains its dependencies.

== Frequently Asked Questions ==

= Does it work alongside Akismet? =

Yes. Spamtroll runs after Akismet and never softens Akismet's verdict: if Akismet has already called a comment spam, Spamtroll leaves it there.

= What happens when the API is down? =

Content is allowed through unscanned. Every failure path is covered by an automated test on every commit.

= My site is behind Cloudflare. Will it see the right IP? =

Only if you tick **Behind a proxy or CDN** under Advanced. Leave it off otherwise: those headers can be sent by anyone, so trusting them on a directly exposed site would let a spammer choose their own reputation.

= Can I make it more aggressive? =

Change the spam threshold for your platform in the Spamtroll dashboard. The **Sensitivity** setting here only decides what this site does with the verdict — whether borderline content is published, moderated, or treated as spam.

== Changelog ==

= 0.2.0 =
* Act on the API's verdict instead of re-deriving one from the score
* Require PHP 8.2, which is what the code has needed all along
* Cap scan latency and add a circuit breaker
* Put the visitor's IP in the verdict cache key
* Show an admin notice when the API rejects the key or the platform
* Send moderator spam/not-spam corrections back to the API
* Wire the scan log into WordPress's personal-data export and erasure
* Make log retention, the API URL and the latency budget configurable again

= 0.1.1 =
* Fail open on HTTP 402 quota exhaustion, with an admin notice

= 0.1.0 =
* Initial release

== Upgrade Notice ==

= 0.2.0 =
Requires PHP 8.2. Spam verdicts now come from the API rather than a local score calculation, so content the backend calls spam is treated as spam.
