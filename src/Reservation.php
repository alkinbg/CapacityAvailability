<?php

declare(strict_types=1);

namespace Alkin\CapacityAvailability;

use DateTimeImmutable;
use InvalidArgumentException;

/** Existing consumption of units over [start, end). */
final readonly class Reservation
{
    public function __construct(
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
        public int $units,
    ) {
        Instant::positiveRange($start, $end);
        if ($units < 1) {
            throw new InvalidArgumentException('Reservation units must be positive.');
        }
    }
}
