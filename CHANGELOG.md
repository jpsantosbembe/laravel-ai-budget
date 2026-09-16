# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.1] - 2026-09-16

### Changed

- Require Laravel 12 (`^12.0`). The 0.1.0 tag still advertised `^11.0|^12.0`,
  which was wrong the moment it was published: every Laravel 11.x release is
  under a security advisory, so Composer refuses to install that leg under its
  default policy. The constraint now matches what the package can actually be
  installed against.

## [0.1.0] - 2026-09-16

First public release. Extracted from a production Laravel application where
this layer has been running since April 2026.

### Added

- `AiRunner`: executes an AI profile with a spend ceiling enforced **before**
  the call, per-model retries, fallback model, JSON Schema validation with a
  single re-ask, and real cost written to `ai_usage_logs`.
- Usage accounting in USD and a configurable local currency, with the FX rate
  resolved from a value or a callable.
- `ChatTransport` interface with an OpenRouter implementation, selected per
  credential through the `provider` column.
- Pipeline engine: ordered `map`/`single` stages over queued job batches,
  cost estimated for the whole pipeline before dispatch, per-item failure
  isolation, resumable retry of only the failed items, and atomic cost
  accumulation.
- `SubsetSchemaValidator` covering type, required, properties, items and enum,
  swappable through configuration.

### Notes

- Published with a `^11.0|^12.0` constraint. Corrected in 0.1.1 — use that
  version instead.
