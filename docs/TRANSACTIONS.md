# Booking writes and database locking

**Reading availability is not a booking transaction.** The pure calculator
always consumes a snapshot supplied by its caller. A read-only result is
advisory until a database transaction commits the actual booking.

## What the optional adapter does

The optional `Alkin\CapacityAvailability\Doctrine\DoctrineBookingWriter`
has an intentionally narrow storage contract:

- MariaDB **InnoDB** tables as defined in `mariadb-schema.sql`.
- Every pool has a durable row in `capacity_pools` before any booking begins.
- `capacity_intervals` stores absolute pool capacity by [start,end).
- `capacity_bookings` stores booked usage by [start,end).

It uses the following sequence for every `reserve()` call:

1. Validate the request. **Reject an already active transaction**.
2. Start a new DBAL transaction.
3. **As the first read**, execute
   `SELECT pool_id FROM capacity_pools WHERE pool_id = :pool FOR UPDATE`.
4. Read capacity windows and overlapping reservations after acquiring the lock.
5. Evaluate the request with the pure `AvailabilityCalculator::canBook()`.
6. Return `false` with a rollback if not enough capacity is available.
7. Otherwise, insert the reservation and commit. Return `true`.

The row lock serializes writers **per capacity pool** that use the **same
locking protocol**. It is not automatic protection against arbitrary SQL.

### Critical integration contract

**Every writer** must acquire the lock for the same pool *before* it reads or
changes any capacity data:

- Creating bookings.
- Rescheduling bookings, including changes across pools (lock both pools in
  a deterministic order to reduce deadlock risk).
- Cancelling or deleting bookings.
- Editing capacity values, capacity periods, or capacity pools.
- Importing or repairing existing booking records.

All these paths must use the same protocol; otherwise overbooking is
still possible.

Under MariaDB's **REPEATABLE READ** isolation, an earlier consistent read in
the same transaction may create a stale snapshot. The adapter therefore
refuses a surrounding transaction and takes `FOR UPDATE` **before** its first
snapshot read. Behavior under other engines and isolation settings needs
independent verification.

### Failure and application concerns

- A duplicate `booking_id` produces a DBAL unique-constraint exception; it is
  not silently treated as idempotent success.
- Unknown pool produces a domain exception.
- Existing inconsistent state produces an explicit exception.
- Deadlocks, lock wait timeouts and transient connection errors are **not**
  automatically retried. Retrying safely requires application-specific policy
  and well-defined idempotency.
- Cancellation, updating, multi-pool booking and migration support are **not**
  provided by this adapter.
- The example SQL is not a Doctrine migration or ORM mapping.
- Authentication, authorization, audit logs, quotas, webhook handling,
  reservations expiration, and payment flows are application responsibilities.

## Symfony example

With DoctrineBundle providing a DBAL connection, register:

```yaml
services:
    Alkin\CapacityAvailability\AvailabilityCalculator: ~
    Alkin\CapacityAvailability\Doctrine\CapacitySnapshot: ~
    Alkin\CapacityAvailability\Doctrine\DoctrineBookingWriter: ~
```

Use constructor injection in your controller or application service. Do not
manually use `canBook()`, then `persist()` separately without a lock.

## Run the MariaDB tests safely

These are **destructive to their disposable test database**. They intentionally
reproduce an *unsafe* double-booking to demonstrate the failure mode.

Requirements: Docker, PHP with `pdo_mysql`, and Composer.

Start a fresh, isolated MariaDB container:

```bash
export CAPACITY_DB_ISOLATED=yes
export CAPACITY_DB_PASSWORD="$(openssl rand -hex 18)"
export CAPACITY_TEST_CONTAINER="capacity-test-$$"

docker run --rm -d --name "$CAPACITY_TEST_CONTAINER" \
  -p 127.0.0.1::3306 \
  -e MARIADB_ROOT_PASSWORD="$(openssl rand -hex 18)" \
  -e MARIADB_DATABASE=capacity_lib_test \
  -e MARIADB_USER=capacity_app \
  -e MARIADB_PASSWORD="$CAPACITY_DB_PASSWORD" \
  mariadb:11.3.2

export CAPACITY_DB_PORT="$(docker port "$CAPACITY_TEST_CONTAINER" 3306/tcp | sed -n 's/^127\.0\.0\.1://p')"
```

Wait until the new MariaDB is ready to accept TCP connections. Then run:

```bash
composer install
php tests/Integration/setup.php
php tests/Integration/concurrent_mariadb.php
docker stop "$CAPACITY_TEST_CONTAINER"
```

The test bootstrap refuses to connect unless `CAPACITY_DB_ISOLATED=yes`,
uses **127.0.0.1** and the fixed test database name `capacity_lib_test`.
Never point it at a production or shared development database.

The test suite creates temporary worker synchronization files under the
operating system's temporary directory. It does not write SQLite or MariaDB
credentials into the repository.

These tests were executed with separate PHP processes against an actual
MariaDB **11.3.2**, Doctrine DBAL **4.5.0**, and the MariaDB default
**REPEATABLE READ** transaction isolation level.

## Deployment note

The example SQL is specific to one simple data model. Consider locking,
migration safety, isolation level, idempotency, metrics, authorization and
transaction lifetime **before** adapting this code for a real application.
