<?php

declare(strict_types=1);

namespace Alkin\CapacityAvailability;

use DateTimeImmutable;

/** Immutable constant-occupancy portion of a requested query window. */
final readonly class CapacitySegment
{
    public int $free;

    public function __construct(
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
        public int $capacity,
        public int $occupied,
    ) {
        $this->free = $capacity - $occupied;
    }
}
