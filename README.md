<p align="center">
  <img src="assets/icon.svg" width="96" alt="Plugsent Connector">
</p>

<h1 align="center">Plugsent Connector</h1>

<p align="center">
  <strong>The site-side half of <a href="https://github.com/ashawkat/plugsent">Plugsent</a> — the self-hosted, open-source fleet manager for WordPress.</strong>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/Version-0.1.0-blue" alt="Version 0.1.0">
  <img src="https://img.shields.io/badge/WordPress-6.0%2B-21759B" alt="WordPress 6.0+">
  <img src="https://img.shields.io/badge/PHP-7.4%2B-777BB4" alt="PHP 7.4+">
  <img src="https://img.shields.io/badge/license-GPL--2.0-green" alt="License: GPL-2.0">
  <img src="https://img.shields.io/badge/Plugin%20Check-audited-brightgreen" alt="Plugin Check audited">
</p>

---

This plugin connects a WordPress site to your self-hosted [Plugsent](https://github.com/ashawkat/plugsent)
control plane. Once paired, the site **checks in every minute over an outbound, HMAC-signed
channel** — it reports its full plugin/theme/core inventory and executes commands your team
sends from the Plugsent dashboard.

No inbound ports. No firewall rules. No WordPress admin passwords — ever.

## How it works

```
Your Plugsent server                This WordPress site
      ▲                                    │
      │  1. paste pairing code (one-time)  │
      │  2. receive site key + secret      │
      │  3. signed poll every minute ──────►
      ◄──────── signed results ────────────┘
```

1. In the Plugsent dashboard, open **Connect site** and register the site — you get a pairing
   code valid for 15 minutes, single use.
2. Install this plugin, open **Settings → Plugsent Connector**, paste the **Server URL** and
   the pairing code.
3. The plugin exchanges the code for a per-site key + secret and starts its check-in loop.
   The site flips to **Connected** in the dashboard automatically.

Every request is signed with HMAC-SHA256 over `"{timestamp}.{body}"`, carries a fresh nonce
(replay protection), and commands expire in 60 seconds. Revoke a site from the dashboard and
its credentials die on the next poll.

## What it reports (and what it never does)

**Sent to your Plugsent server:** site name and URL, WordPress and PHP versions, and the
plugin/theme inventory — name, version, active state, and available updates.

**Never touched:** your posts, users, comments, or content — and your WordPress admin
password is not asked for at any point.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- A reachable [Plugsent](https://github.com/ashawkat/plugsent) server (HTTPS strongly
  recommended — pairing sends the site's secret once, over that connection)

## Installation

1. Download this repository as a ZIP (or `git clone` it).
2. In wp-admin: **Plugins → Add New → Upload Plugin**, choose the ZIP, activate.
3. Go to **Settings → Plugsent Connector** and pair using the dashboard instructions above.

> Not yet listed on wordpress.org — install from source for now. The repo already ships the
> directory assets (`icon.svg`, banners) and a directory-format `readme.txt`, so it's ready
> for submission.

## Commands supported (v0.1)

| Command | Purpose |
|---|---|
| `inventory.get` | Full core/plugin/theme inventory with update availability |
| — anything else | Reported back as `unsupported_command` so the platform knows |

Newer commands (safe updates, maintenance mode, backups) ship as protocol extensions —
this plugin keeps working even when the dashboard is newer than the plugin.

## Development notes

- The signer (`includes/class-plugsent-signer.php`) is a vendored copy of
  [`plugsent/connector-signing`](https://github.com/ashawkat/plugsent/tree/main/packages/connector-signing)
  from the main repo. Both implementations run against the **same test vectors** in the
  platform's CI, so they can never silently drift.
- The static Plugin Check audit (nonces, capability checks, sanitization, escaping, text
  domain, readme sections) runs with the platform's suite in
  `tests/Feature/PluginCheckCompatibilityTest.php`.

## Security

Found a vulnerability? Please use GitHub's **Report a vulnerability** (Security tab) on this
repository rather than a public issue.

## License

- Plugin code: **GPL-2.0-or-later** — as WordPress plugins must be.
- Bundled Google Sans font files: © Google, under the
  [SIL Open Font License 1.1](assets/fonts/OFL.txt).
