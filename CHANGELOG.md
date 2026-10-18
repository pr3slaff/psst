# Changelog

## 1.0.0 — 2026-10-18

Initial standalone release for Laravel 12/13 and PHP 8.3+.

- Four event levels, immediate fail-silent delivery, explicit asynchronous queue mode and force delivery.
- Stable event identifiers, bounded/redacted payloads, transaction-aware enqueue and transient retries capped at 24 hours.
- One authenticated `/psst-health` endpoint with scalar metrics, configurable caching and last-good values on metric errors.
- Automatic Laravel provider/facade discovery, publishable configuration and an MIT license.
