# Privacy

KHSEO collects the minimum data needed and has **no telemetry**.

## What version 0.2.x stores

| Data | Where | Why |
|---|---|---|
| Plugin settings | `wp_options` → `khseo_settings` | Your configuration |
| Optional AI API key | `wp_options` → `khseo_secrets` (encrypted) | Future AI features (PLANNED) |
| Log entries | `wp_options` → `khseo_log` (max 200 entries and your retention setting; one line each; secrets redacted) | Troubleshooting and security events |
| Audit findings | table `{prefix}khseo_issues` | Issues found on your pages: URL, rule, observation (e.g. "No meta description"). Resolved rows are pruned after 30 days; the table is capped at 20,000 rows |
| Last audit summary and current audit job | `wp_options` → `khseo_last_audit`, `khseo_audit_job` | Score, scope and coverage; job progress |
| Change journal and approvals | `wp_options` → `khseo_change_journal` (max 100), `khseo_fix_approvals` (expire after 1 hour) | Before/after values of fixes, for rollback |

Findings contain page data (titles, descriptions, URLs) from **your own site**,
not visitor data. Only users with KHSEO capabilities can read them.

## What version 0.2.x requests over the network

- **Audits fetch pages of this site only**: its home page, `/robots.txt`, its XML
  sitemaps and the pages listed there (up to the limits in Settings). These requests go
  to your own site's address through KHSEO's SSRF-protected fetcher. KHSEO refuses to
  audit any other host.
- **Nothing is sent to any third party.** There is no AI provider, analytics, Google
  API or tracking call.

## Future optional features

AI providers and Google integrations will be **off by default**. Before any
content is sent, KHSEO will say what may be sent and to whom. You will be able
to turn off content transmission entirely, and only the minimum content needed
for the task you start will be sent. This document will be updated before any
such feature ships.

## Removing your data

Tick **KHSEO → Settings → Delete all KHSEO data when the plugin is deleted**,
then delete the plugin. That removes:
- the options listed above;
- the findings table;
- the capabilities;
- the scheduled audit events.

Without that setting, your data is kept.
