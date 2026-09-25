# Anti-Spam - Forum Fortress

Instant, privacy-friendly spam protection. No endless settings. Just less spam.

Built by and for forum admins, Forum Fortress is designed to start protecting your community with almost no setup.

## Install the plugin

Spam protection starts immediately.

Optionally register in the Forum Fortress portal.

That’s it.

Forum Fortress helps protect registrations, posts and other common abuse points without forcing your users through endless CAPTCHAs or giving administrators another complicated system to babysit.

## Simple pricing

The Forever Free plan is designed to be enough for most small and medium-sized communities.

For larger communities, or admins running multiple forums, optional paid plans are available at $10/year and $40/year, adding higher limits and advanced features.

You do not need a paid plan just to get started.

Install it. Let it run. Get back to running your forum.

**Current version: 1.1.5**

Version 1.1.5 publishes this description in the public GitHub repository so the
SMF Customization catalog can load it.

Version 1.1.1 supports SMF 2.1 on PHP 7.1 and newer and fixes native staff
checks, scheduled moderation loading, HTTPS API-key redirect handling, and
canonical forum-domain detection.

Version 1.1.0 removes health and endpoint-catalogue requests. Global mode uses
`api.ffapi.net`. A regional
selection remains locked unless global emergency fallback is enabled. Standard
heartbeats are limited to hourly; Pro and MultiMod may check in every ten
minutes.

Version 1.0.8 preserves node-bound offline routing when ordinary API
responses omit bootstrap identity, and adds a standalone shared-resilience
contract to prevent cross-platform routing drift.

It is the first release licensed under `GPL-2.0-or-later`; see
`upload/CHANGELOG.md`, `upload/LICENSE`, and `upload/NOTICE`.

Version 1.0.7 repairs partially stored bootstrap identities from authenticated
site status, preserves the last local identity when a recovery attempt is
interrupted, and replaces client-side endpoint ranking with GeoDNS-first
routing plus same-request catalog failover.

Version 1.0.6 requires HTTPS for every service endpoint and uses the public
`/health` contract instead of the internal `/v1/check-ready` route.

Version 1.0.4 removes the configurable control-plane URL and uses the stable
plugin service at `https://fortress.ffapi.net`.

SMF 2.1 modification integrating with the Forum Fortress API (registration, content, profile/signature, moderation bridge, hourly sync).

Public install guide: [forumfortress.com/docs/install/smf/](https://forumfortress.com/docs/install/smf/)

## Repository layout

- `upload/` — package tree for SMF Package Manager
- `upload/package-info.xml` — install hooks and default settings

Release archives are built from the Forum Fortress source monorepo with:

```bash
python3 plugins/smf/build_release.py
```

This produces `plugins/forumfortress-smf-{version}.tar.gz` (and `.sha256`) for SMF Package Manager upload.

## Install on a forum

1. Download `forumfortress-smf-*.tar.gz` from [forumfortress.com/#platforms](https://forumfortress.com/#platforms), or build with `build_release.py` above.
2. SMF Admin → **Configuration → Modification Packages → Upload package**.
3. Install/enable the package.
4. **Configuration → Modification Settings → Forum Fortress**.

Or sync into a dev tree:

```bash
rsync -a /path/to/plugins/smf/upload/ /var/www/html/smf/
```

Then install via Package Manager or register hooks manually.

**Upgrading an existing install (before package reinstall):** copy updated files from `upload/`, then register the admin menu hook (install via Package Manager does this automatically):

```sql
-- Append to integrate_admin_areas if other mods use the same hook (comma-separated list).
UPDATE smf_settings SET value = CONCAT(IFNULL(value, ''), IF(value IS NULL OR value = '', '', ','), '$sourcedir/ForumFortressProtect.php|ffp_integrate_admin_areas')
WHERE variable = 'integrate_admin_areas' AND value NOT LIKE '%ffp_integrate_admin_areas%';
```

If no row exists yet, insert `$sourcedir/ForumFortressProtect.php|ffp_integrate_admin_areas` as the value instead.

## Live dev box

- SMF path: `/var/www/html/smf`
- Settings: Admin → Modification Settings → Forum Fortress

**Endpoint routing:** Global requests use `api.ffapi.net`. Regional requests
use only their selected hostname unless global emergency fallback is enabled,
when they
may also try both global hosts. The plugin does not probe health routes, fetch
an endpoint catalogue, rank hosts, or persist a successful fallback.

## API region lock

API region defaults to Global. UK only, EU only, and US only use their fixed
regional DNS hostname. Global emergency fallback is disabled by default. If
enabled, the plugin retries the regional hostname before trying `api.ffapi.net`;
processing may then occur outside the selected region. API URLs cannot be
entered manually.

## Backups and upgrades

Back up the SMF files and database before installing or upgrading the package,
and confirm that your forum's normal restore procedure is available.

## License and contributions

The Forum Fortress plugin is free and open-source software under the GNU
General Public License, version 2 or later. The hosted service is separate and
governed by its service terms. Contributions use the same project licence,
contributors retain their copyright, and no contributor licence agreement or
copyright assignment is required; see `upload/CONTRIBUTING.md`.
