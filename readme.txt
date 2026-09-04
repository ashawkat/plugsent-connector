=== Plugsent Connector ===
Contributors: adnanshawkat, betatech
Tags: management, updates, inventory, monitoring
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.11.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects this WordPress site to your self-hosted Plugsent control plane.

== Description ==

Plugsent is an open-source WordPress fleet manager: plugin/theme/core inventory,
safe updates, uptime and vulnerability monitoring, organized by workspace,
project, and team.

This plugin is the site-side connector. It is outbound-only: the site polls your
Plugsent server for signed commands, so it works behind firewalls and staging
auth with no inbound ports. Every request is HMAC-signed (protocol v1) and never
requires your WordPress admin password.

= Getting started =

1. In your Plugsent dashboard open "Connect site" and fill in this site's URL.
2. Install this plugin, then open Settings → Plugsent Connector.
3. Paste the Server URL and the 15-minute pairing code. Done — the plugin checks
   in every minute and reports its full plugin/theme/core inventory.

== Installation ==

1. Upload the `plugsent-connector` folder to the `/wp-content/plugins/`
   directory, or install the ZIP via Plugins → Add New → Upload Plugin.
2. Activate the plugin through the Plugins screen.
3. Go to Settings → Plugsent Connector and paste the Server URL and pairing
   code from your Plugsent dashboard ("Connect site" page).
4. The site checks in within a minute; its status in Plugsent flips to
   Connected automatically.

== Frequently Asked Questions ==

= Does this work behind a firewall or staging authentication? =

Yes. The connector is outbound-only: it calls your Plugsent server, never the
other way around. No inbound ports or firewall rules are needed.

= Does it need my WordPress admin password? =

No. Pairing uses a one-time code, after which every request is signed with a
per-site key pair. Revoke access from the Plugsent dashboard at any time.

= What data does it send? =

Site name and URL, WordPress and PHP versions, and the plugin/theme inventory
(name, version, active state, available updates). No posts, users, or content.

= Where can I get a Plugsent server? =

Plugsent is open source and self-hosted: run it with one command from
https://github.com/plugsent/plugsent

== Screenshots ==

1. The pairing screen under Settings → Plugsent Connector.
2. A connected site reporting its inventory (shown in the Plugsent dashboard).

== Changelog ==

= 0.11.0 =
* New: remote plugin management - the dashboard can activate, deactivate, or delete a plugin (the connector itself can never be managed remotely).
* New: remote theme management - switch the active theme or delete an inactive one.

= 0.10.0 =
* Fix: login tokens are now stored in the database instead of transients - on sites with Redis/Memcached object caches, transient-based tokens could vanish before use (the "link has expired" issue).

= 0.9.1 =
* Login tokens now live for 5 minutes (was 2) - friendlier for slow WP-Cron hosts.

= 0.9.0 =
* New: admin.login command - the Plugsent dashboard can generate a single-use, 120-second magic login into wp-admin for the paired admin user.

= 0.8.0 =
* Instant command pickup: long-polls the server (opt-in wait parameter), so dashboard actions reach the site in seconds.
* Reports its own connector version to the dashboard.

= 0.7.0 =
* New: admin.login command - the Plugsent dashboard can generate a single-use, 120-second magic login into wp-admin (lands on the paired admin user).

= 0.5.0 =
* Simpler pairing: paste a single connection string (server + key combined) - no separate server URL field.

= 0.4.1 =
* New: pair using the site's stable API key from the Plugsent dashboard (no 15-minute rush).

= 0.4.0 =
* Live progress: command results are reported one by one as they finish, so the dashboard shows step-by-step progress during batch updates.

= 0.3.1 =
* Fix: update results are now verified by comparing versions - failed theme updates are no longer reported as successful.

= 0.3.0 =
* Much faster dashboard updates: the connector now long-polls and chains requests, so commands start within seconds instead of up to a minute.
* Modernized settings screen.

= 0.2.3 =
* Fix: theme update data is stored as an array by WordPress - read new_version with array access (theme updates were missed in 0.2.2).

= 0.2.2 =
* Fix: theme update availability was never detected (wrong lookup key in get_theme_updates).

= 0.2.1 =
* Fix: incoming command type was being mangled by sanitize_key, so inventory requests were rejected as unsupported.

= 0.2.0 =
* New: update.run command — update single plugins, themes, or WordPress core from the dashboard.

= 0.1.0 =
* Initial release: pairing, signed poll loop, inventory.get command.

== Upgrade Notice ==

= 0.1.0 =
Initial release.
