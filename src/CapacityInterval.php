<?php

declare(strict_types=1);

namespace Alkin\CapacityAvailability;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Absolute capacity during [start, end): 0 is a valid planned closure.
 * Capacity intervals of the same pool must not overlap.
 */
final readonly class CapacityInterval
{
    public function __construct(
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
        public int $units,
    ) {
        Instant::positiveRange($start, $end);
        if ($units < 0) {
            throw new InvalidArgumentException('Capacity cannot be negative.');
        }
    }
}
