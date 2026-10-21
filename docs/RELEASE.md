# v1.0.0 — stable API and integration contract

The calculation API (`AvailabilityCalculator::timeline()`, `availableWindows()`,
`canBook()` and the documented immutable value objects) is considered stable in
the 1.x series. Breaking API changes require a major version.

## Supported environments

- PHP 8.2 or newer. The core requires no framework or database.
- Optional persistence: Doctrine DBAL 4.3+ with the supplied MariaDB/InnoDB
  schema and the protocol in [TRANSACTIONS.md](TRANSACTIONS.md).
- Symfony applications can register the three ordinary services described in
  the README. No bundle is necessary.

## Semantic contract

- All time intervals are half-open `[start, end)`, in absolute instants.
- `latestStart` is inclusive; all times have whole-second precision.
- Capacity is absolute and nonadditive; overlapping capacity declarations
  are invalid. Missing capacity means zero.
- Reservations consume integer units of an interchangeable pool; inconsistent
  existing reservations throw rather than returning misleading availability.
- The core does not write reservations or protect against concurrent writes.

## Persistence contract

`DoctrineBookingWriter::reserve()` is atomic only when every writer touching
bookings or capacity for a pool first obtains the same pool-row lock within its
transaction. The adapter rejects nested transactions. It intentionally does
not perform deadlock retries, provide idempotency, handle changes/cancellation,
or implement application authorization. The supplied schema is an integration
example and must not be applied blindly to an existing production database.

## Verification

CI validates Composer metadata, PHPUnit, randomized independent reference checks
and PHPStan, across PHP 8.2–8.5. The database integration test uses an isolated
MariaDB instance and independent PHP worker processes; it deliberately demonstrates
an unsafe race in disposable data. See [TRANSACTIONS.md](TRANSACTIONS.md).

Release tags are immutable. Publish the same tagged commit to Packagist only
after the Git repository is intentionally made public. Do not claim that a Git tag
alone makes the package publicly installable.
