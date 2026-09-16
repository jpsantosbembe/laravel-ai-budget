# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
