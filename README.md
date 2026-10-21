# CapacityAvailability

A small, framework-independent PHP library for **capacity-aware availability**.

It answers a question that is harder than counting overlapping reservations:

> A resource pool can accommodate a changing number of simultaneous units.
> Existing bookings overlap and consume different numbers of units.
> When can a new request reserve **N units for D minutes**, with enough
> capacity for its **entire** duration?

Includes an **optional Doctrine DBAL adapter** for transactional bookings on
MariaDB/InnoDB. No database, framework or external library is needed for the
calculation itself.

**Status: stable v1.0.0.** The public calculation API follows semantic versioning.
The Doctrine adapter is optional and deliberately tied to the documented MariaDB schema.
See [release scope and compatibility contract](docs/RELEASE.md).

## Example: find bookable times

```php
use Alkin\CapacityAvailability\AvailabilityCalculator;
use Alkin\CapacityAvailability\CapacityInterval;
use Alkin\CapacityAvailability\Reservation;

$at = static fn (string $time): DateTimeImmutable =>
    new DateTimeImmutable("2026-10-22 $time:00+00:00");

$capacity = [
    new CapacityInterval($at('09:00'), $at('10:00'), 2),
    new CapacityInterval($at('10:00'), $at('11:00'), 3),
];

$bookings = [
    new Reservation($at('09:30'), $at('10:30'), 1),
];

$calculator = new AvailabilityCalculator();

$windows = $calculator->availableWindows(
    capacity: $capacity,
    reservations: $bookings,
    from: $at('09:00'),
    until: $at('11:00'),
    requiredUnits: 2,
    durationSeconds: 45 * 60,
);

foreach ($windows as $window) {
    printf(
        "Available %s–%s; latest start: %s\n",
        $window->start->format('H:i'),
        $window->end->format('H:i'),
        $window->latestStart->format('H:i'),
    );
}
// Available 10:00–11:00; latest start: 10:15
```

The result is a **continuous interval of possible starts**, not a preselected
15- or 30-minute grid. The consumer decides how to display or sample starting
times. The last valid start is **inclusive**, while period ends are exclusive.

You can also check a proposed interval or inspect utilization:

```php
$canFit = $calculator->canBook(
    $capacity, $bookings, $at('10:00'), $at('10:45'), 2,
); // true

$segments = $calculator->timeline(
    $capacity, $bookings, $at('09:00'), $at('11:00'),
);
// Each CapacitySegment has start, end, capacity, occupied and free.
```

## Why the sweep-line algorithm?

Counting all reservations that *intersect* a query is not enough. Three
back-to-back reservations may all intersect a large query without ever being
active simultaneously. Capacity can also change while a booking runs.

The calculator sorts each start and end as an event, computes the capacity
and usage between consecutive events and merges eligible adjacent periods.
Its complexity is **O((C + R) log(C + R))** for C capacity intervals and R
reservations within a query, with O(C + R) memory.

## PHP requirements and installation

- PHP **8.2 or newer**.
- Pure calculation: **zero runtime Composer dependencies**.
- Optional transactional adapter: **Doctrine DBAL 4.3+** and **MariaDB/InnoDB**.
- Symfony is **not** required.

After Packagist registration, install with:

```bash
composer require alkin/capacity-availability:^1.0
```

Until then, install from the Git repository using a Composer VCS repository.
The v1.0.0 Git tag is the canonical release source; Packagist publication is separate.

## Optional Doctrine / MariaDB booking writer

Computing availability is **not** the same as saving a reservation. Two
concurrent processes can both see availability and overbook unless the
read-check-insert operation is protected.

For applications following the bundled example schema
(`docs/mariadb-schema.sql`), use:

```php
use Alkin\CapacityAvailability\Doctrine\CapacitySnapshot;
use Alkin\CapacityAvailability\Doctrine\DoctrineBookingWriter;

$writer = new DoctrineBookingWriter(
    $doctrineDbaConnection,
    new CapacitySnapshot($doctrineDbaConnection),
    new AvailabilityCalculator(),
);

$booked = $writer->reserve(
    bookingId: 'shipment-123',
    pool: 'dock-a',
    start: $at('10:00'),
    end: $at('10:45'),
    units: 2,
);
// true: committed, false: insufficient capacity.
```

**IMPORTANT:** This adapter is deliberately specific to the bundled schema
and to MariaDB/InnoDB. All other code paths modifying reservations or capacity
for the same pool **must use the same pool-row locking protocol**. See
[Transactional booking contract](docs/TRANSACTIONS.md) before integrating it.

The calculation API does not know about transactions, database state,
Doctrine entities, Symfony, or HTTP requests.

### Symfony integration without a bundle

When using DoctrineBundle, register ordinary autowired services:

```yaml
services:
    Alkin\CapacityAvailability\AvailabilityCalculator: ~
    Alkin\CapacityAvailability\Doctrine\CapacitySnapshot: ~
    Alkin\CapacityAvailability\Doctrine\DoctrineBookingWriter: ~
```

No Symfony bundle is needed merely to register three services. A future
integration package should exist only if it reduces **real persistence
complexity**, not as a decorative wrapper.

## Correctness and boundaries

- All intervals are **[start, end)**: booking until 10:00 does not block one
  starting at exactly 10:00.
- Each capacity declaration is an **absolute level**, not additive supply.
  Capacity intervals **may be adjacent but must not overlap**.
- Missing capacity means **zero** available units.
- Existing reservations consume **positive integer units** and may overlap.
- A period already overbooked in the queried range throws `DomainException`
  rather than returning misleading windows.
- Inputs use `DateTimeImmutable` with **second precision**; microseconds are
  rejected. Timezone offsets and DST repeated hours compare as real instants.
- No recurring schedules, holidays, per-unit equipment identity, multi-resource
  optimization, setup buffers, quotas, priorities, idempotent API or payment rules.

See [algorithm and model details](docs/ALGORITHM.md).

## Testing

```bash
composer install
composer test
composer test:reference
composer analyse
```

The extended reference test checks **300 randomly generated minute calendars**
and **80 second-resolution calendars** against a deliberately independent,
simple occupancy oracle. Unit tests exercise edge cases and public API types.

MariaDB integration tests use **multiple actual PHP processes**. They
intentionally include an **unsafe race demonstration** that overbooks a disposable
database, as well as safe tests based on row locking. **Never run integration
tests against a real application database.** Setup and environment instructions
are in [docs/TRANSACTIONS.md](docs/TRANSACTIONS.md).

## Project direction

- Keep the core usable from any PHP application.
- Make Doctrine persistence optional and its locking requirements explicit.
- Verify functionality in a real integration before declaring a stable public API.
- Prefer clear failures to silent approximate answers.

MIT license. Copyright (c) 2026 Alkin Fehim.
