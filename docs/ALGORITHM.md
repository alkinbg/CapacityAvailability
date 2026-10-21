# Model and algorithm

## Inputs

The calculator operates on a **single pool of interchangeable capacity**.
For example, 3 loading stations, 5 seats, or 12 shared storage positions.
It does not assign a particular station or seat.

- `CapacityInterval(start, end, units)` declares the **absolute** capacity
  in [start,end). Capacity intervals cannot overlap, and there is no implicit
  capacity outside them.
- `Reservation(start, end, units)` consumes an integer number of units.
- `requiredUnits` is the minimum free capacity needed by a new request.
- `durationSeconds` is the exact positive requested duration.

All boundaries are **half-open**. When one booking ends at the exact instant
another begins, these bookings do not overlap.

## Sweep-line computation

1. Clip the capacity and reservation intervals to the query window.
2. Add delta events at all starts and ends:
   capacity additions/removals and reservation usage additions/removals.
3. Sort unique event instants and calculate active capacity/usage after
   applying all changes at each instant.
4. Reject negative state, use overflow-safe additions, and reject any
   existing usage exceeding capacity.
5. Emit the half-open slices of constant capacity and consumption.
6. Merge adjacent slices with the same state in `timeline()`.
7. Merge adjacent slices where `capacity - occupied >= requiredUnits` in
   `availableWindows()`, even if the utilization differs.
8. Reject any merged interval shorter than the required duration.
9. Compute `latestStart = end - durationSeconds`. The latest start is
   inclusive, and a booking may end exactly at `end`.

The sorting dominates **O((C + R) log(C + R))** for C capacity declarations and
R reservations, using **O(C + R)** additional space.

## Why not count overlapping bookings?

Consider capacity 3 and three reservations each using 1 unit:

- 09:00–09:30
- 09:30–10:00
- 10:00–10:30

All three intersect the queried interval 09:00–10:30, but only **one** is
active at any instant. The request for 2 units should be allowed.

With another reservation 09:45–10:15 for 2 units, the result is different:
the peak usage matters and only a subrange may be available.

## Supported and unsupported behavior

- The core is *deterministic* for its complete supplied data snapshot.
- It does **not** read from a DB or persist reservations.
- It does **not** predict future availability or calculate holidays.
- It does **not** provide locking or protect concurrent booking writes.
- It does **not** accept fractional consumption or non-fungible resources.
- Capacity declarations are **absolute**, not additive sources: representing
  two concurrently available teams of 2 requires a single capacity level 4.
- Intervals that overlap in the same capacity array are rejected.
- Datetimes have **second precision**; timezones are interpreted as absolute
  instants, including during daylight-saving clock changes.
- Long-range queries load the supplied intervals into memory and recompute
  from scratch; the application is responsible for bounding query size and
  obtaining only relevant bookings.

The API returns **continuous windows** rather than thousands of start
candidates. Applications may sample their preferred grid (for example, every
5, 10 or 15 minutes) between the returned window start and latest start.

## Testing philosophy

The independent reference script (`tests/reference_check.php`) does not reuse
the sweep-line algorithm. It calculates free capacity at every minute/second
in bounded random calendars, and compares all valid starts against the
calculator's output.

The independent PHP test suite and real MariaDB integration tests each target
different failure modes. Passing one suite does not imply that the other type
of correctness is automatic.
