<?php
declare(strict_types=1);

namespace Alkin\CapacityAvailability\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use DomainException;
use InvalidArgumentException;
use Alkin\CapacityAvailability\AvailabilityCalculator;
use Alkin\CapacityAvailability\Reservation;
use Throwable;

/**
 * MariaDB/InnoDB-specific transactional adapter around the framework-free core.
 *
 * Requirements:
 *  - A pre-existing, stable row in capacity_pools for every pool.
 *  - ALL code paths modifying capacity or bookings for this pool acquire
 *    that same row's FOR UPDATE lock in their transaction.
 *  - No surrounding transaction (to avoid stale REPEATABLE READ snapshots).
 *  - One DBAL connection per PHP request / concurrent worker.
 */
final readonly class DoctrineBookingWriter
{
    public function __construct(
        private Connection $db,
        private CapacitySnapshot $snapshots,
        private AvailabilityCalculator $availability,
    ) {}

    /** Read-only advisory result, NEVER use as the sole authorization to insert. */
    public function canBook(
        string $pool, DateTimeImmutable $start, DateTimeImmutable $end, int $units
    ): bool {
        new Reservation($start, $end, $units);
        $this->assertPoolName($pool);
        $snapshot = $this->snapshots->read($pool, $start, $end);
        return $this->availability->canBook(
            $snapshot['capacity'], $snapshot['bookings'], $start, $end, $units
        );
    }

    /**
     * Atomically attempt to book capacity.
     *
     * @return bool true if committed, false if full. Exceptions are propagated.
     */
    public function reserve(
        string $bookingId,
        string $pool,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        int $units,
    ): bool {
        new Reservation($start, $end, $units);
        $this->assertPoolName($pool);
        if ($bookingId === '' || strlen($bookingId) > 80) {
            throw new InvalidArgumentException('bookingId must contain 1–80 characters.');
        }
        if ($this->db->getTransactionNestingLevel() !== 0) {
            throw new InvalidArgumentException('Booking cannot run inside another transaction.');
        }

        $this->db->beginTransaction();
        try {
            // This must be the FIRST read in the transaction.
            // Under MariaDB REPEATABLE READ the subsequent consistent snapshot
            // must be established only AFTER this pool lock is obtained.
            $exists = $this->db->fetchOne(
                'SELECT pool_id FROM capacity_pools WHERE pool_id = :pool FOR UPDATE',
                ['pool' => $pool],
            );
            if ($exists === false) {
                throw new DomainException('Unknown capacity pool.');
            }

            $snapshot = $this->snapshots->read($pool, $start, $end);
            if (!$this->availability->canBook(
                $snapshot['capacity'], $snapshot['bookings'], $start, $end, $units
            )) {
                $this->db->rollBack();
                return false;
            }

            $this->db->insert('capacity_bookings', [
                'booking_id' => $bookingId,
                'pool_id' => $pool,
                'starts_at' => $start->getTimestamp(),
                'ends_at' => $end->getTimestamp(),
                'units' => $units,
            ]);
            $this->db->commit();
            return true;
        } catch (Throwable $failure) {
            if ($this->db->getTransactionNestingLevel() > 0) {
                $this->db->rollBack();
            }
            throw $failure;
        }
    }

    private function assertPoolName(string $pool): void
    {
        if ($pool === '' || strlen($pool) > 80) {
            throw new InvalidArgumentException('Pool ID must contain 1–80 characters.');
        }
    }
}
