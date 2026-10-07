# Privacy

KHSEO collects the minimum data needed and has **no telemetry**.

## What version 0.1.x stores

| Data | Where | Why |
|---|---|---|
| Plugin settings | `wp_options` → `khseo_settings` | Your configuration |
| Optional AI API key | `wp_options` → `khseo_secrets` (encrypted) | Future AI features |
| Log entries | `wp_options` → `khseo_log` (capped at 200 entries and your retention setting, 14 days by default) | Troubleshooting and security events; secrets are redacted |

## What version 0.1.x sends anywhere

**Nothing.** No KHSEO feature makes external requests. The Safe Fetcher exists
for future features, but nothing in the plugin calls it yet.

## Future optional features

AI providers and Google integrations will be **off by default**. Before any
content is sent, KHSEO will say what may be sent and to whom. You will be able
to turn off content transmission entirely, and only the minimum content needed
for the task you start will be sent. This document will be updated before any
such feature ships.

## Removing your data

Tick **KHSEO → Settings → Delete all KHSEO data when the plugin is deleted**,
then delete the plugin. Without that setting, your data is kept.
