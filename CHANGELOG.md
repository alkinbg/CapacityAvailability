# Changelog

## 1.0.0 — 2026-10-21

First stable release.

- Framework-independent PHP 8.2+ availability calculation for variable capacity and overlapping reservations.
- Half-open time intervals, inclusive latest start, second precision, and explicit validation of inconsistent occupancy.
- Public calculation API: `AvailabilityCalculator`, `CapacityInterval`, `Reservation`, `CapacitySegment`, `AvailabilityWindow`.
- Optional Doctrine DBAL 4.3+ MariaDB/InnoDB transactional booking adapter, using per-pool row locking.
- Randomized independent reference verification, PHPUnit, PHPStan, PHP 8.2–8.5 CI and isolated MariaDB concurrency tests.

See [scope and integration constraints](docs/RELEASE.md).
