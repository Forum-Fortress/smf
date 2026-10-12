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

**Current version: 1.2.1**

## Optional repeat-spammer bans

In **Configuration → Modification Settings → Forum Fortress**, enable
**Automatically ban confirmed repeat spammers for seven days** only when you
want account enforcement. Fresh installs and upgrades default Off.

Two distinct confirmed content BLOCKs within 24 hours must carry the server's
strict `escalation_eligible: true` certificate for original scores above 80.
Submission bodies and stable decision references/IDs are independently deduplicated.
Evidence is committed after native posting validation at request shutdown; a
separate one-minute SMF scheduled task creates a native, member-only seven-day
access ban and moderation log. It adds no synchronous API requests. Registration,
profile/signature checks, invalid forms, outages, policy-only decisions and legacy
responses without the certificate cannot contribute.

Only young ordinary accounts (registered within seven days, at most ten posts)
qualify. Guests, user ID 1, administrators, moderators, custom/secondary groups,
staff permissions, warned/inactive/banned accounts, established members and edits
belonging to someone else are protected. Roles, ownership, settings and evidence
are checked again before enforcement. Existing member/email/IP restrictions are
protected; any native hostname restriction conservatively disables automatic
eligibility rather than performing DNS queries.

The settings page shows availability and the latest 15 account receipts. Native
MySQL/MariaDB transaction state must be readable, required tables must use InnoDB,
and the task must be present and enabled. An unavailable dependency leaves
ordinary spam decisions working while automatic enforcement does nothing.

Use SMF **Members → Ban List** to review, reverse or expire a ban. A durable
account receipt prevents automatic re-banning after reversal or expiry. Switching
the feature or protection Off cancels pending evidence. Jobs process at most 50
accounts; evidence is capped at 128 events per account, 50,000 total events and
100,000 state rows. Saturation skips new evidence. Tasks prune up to 500 events
older than seven days and 500 obsolete states; receipts persist for existing
accounts. Uninstall removes owned tables/tasks without deleting accounts,
content, held-post metadata or native bans. Spam-clean and account deletion are
outside this feature.
