# Changelog

All notable changes to `laravel-db-temporal` will be documented in this file.

## [v0.0.1] – 2026-09-12

### Fixed
- TemporalConnection: schema-builder work and connection identity delegate to the base connection (migrations and `Schema::` facade now work through the temporal-proxy connection); base schema grammar is materialised lazily.
- Microsecond precision for all `known_from`/`known_to` timestamps. Two versions written within the same second (batch processes, tests, rapid admin actions) previously collided on the primary key `(id, known_from, known_to)` because `known_to` used second precision. Versions now close at `transaction time − 1 µs`; version intervals remain contiguous and the `9999-12-31 23:59:59` max-timestamp sentinel is unchanged.

### Added
- Same-second regression test: three consecutive updates produce four distinct, non-overlapping versions.
