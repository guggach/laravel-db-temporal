# Changelog

All notable changes to `laravel-db-temporal` will be documented in this file.

## [Unreleased]

### Added
- Temporal pivot relations: `HasTemporalPivotRelations` makes `belongsToMany`/`morphToMany` version their pivot table (uni- and bi-temporal, optional soft delete) while keeping the usual `attach`/`detach`/`sync`/`updateExistingPivot` API. Bi-temporal pivots support `asOf()`, `validAsOf()` and `knownAsOf()`.
- Marker traits `IsUniTemporalPivot` / `IsBiTemporalPivot` for custom pivot models (`->using()`).
- `php artisan temporal:make-pivot` scaffolds a temporal pivot migration (composite key or surrogate id, uni/bi, soft delete, unique index).
- Documentation: `docs/pivot-relations.md` and `docs/pivot-relations_de.md`.
- Behaviour tests for uni-/bi-temporal and morph pivots, plus a composite-key characterization test.

## [v0.0.1] – 2026-09-12

### Fixed
- TemporalConnection: schema-builder work and connection identity delegate to the base connection (migrations and `Schema::` facade now work through the temporal-proxy connection); base schema grammar is materialised lazily.
- Microsecond precision for all `known_from`/`known_to` timestamps. Two versions written within the same second (batch processes, tests, rapid admin actions) previously collided on the primary key `(id, known_from, known_to)` because `known_to` used second precision. Versions now close at `transaction time − 1 µs`; version intervals remain contiguous and the `9999-12-31 23:59:59` max-timestamp sentinel is unchanged.

### Added
- Same-second regression test: three consecutive updates produce four distinct, non-overlapping versions.
