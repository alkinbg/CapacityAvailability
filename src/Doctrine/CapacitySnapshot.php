<?php
declare(strict_types=1);
namespace Alkin\CapacityAvailability\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Alkin\CapacityAvailability\CapacityInterval;
use Alkin\CapacityAvailability\Reservation;

/**
 * Reads DB state for a single pool, and is deliberately unaware of the lock.
 * If used for a write decision, acquire the pool row lock BEFORE calling it.
 */
final readonly class CapacitySnapshot
{
    public function __construct(private Connection $db) {}

    /** @return array{capacity:list<CapacityInterval>,bookings:list<Reservation>} */
    public function read(string $pool, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $params = ['pool' => $pool, 'start' => $start->getTimestamp(), 'end' => $end->getTimestamp()];
        $capacities = $this->db->fetchAllAssociative(
            'SELECT starts_at, ends_at, units FROM capacity_intervals
             WHERE pool_id = :pool AND ends_at > :start AND starts_at < :end
             ORDER BY starts_at, ends_at', $params
        );
        $bookings = $this->db->fetchAllAssociative(
            'SELECT starts_at, ends_at, units FROM capacity_bookings
             WHERE pool_id = :pool AND ends_at > :start AND starts_at < :end
             ORDER BY starts_at, ends_at', $params
        );
        $instant = static fn (int $unix): DateTimeImmutable => new DateTimeImmutable('@'.$unix);
        return [
            'capacity' => array_map(static fn (array $r): CapacityInterval => new CapacityInterval(
                $instant((int)$r['starts_at']), $instant((int)$r['ends_at']), (int)$r['units']
            ), $capacities),
            'bookings' => array_map(static fn (array $r): Reservation => new Reservation(
                $instant((int)$r['starts_at']), $instant((int)$r['ends_at']), (int)$r['units']
            ), $bookings),
        ];
    }
}
