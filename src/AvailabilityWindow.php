<?php

declare(strict_types=1);

namespace Alkin\CapacityAvailability;

use DateTimeImmutable;

/**
 * A continuous range satisfying a demand.
 *
 * - start/end: full interval with enough free units; end is exclusive.
 * - latestStart: last permitted start instant for the requested duration,
 *   inclusive (if starting there, the booking ends at "end").
 */
final readonly class AvailabilityWindow
{
    public function __construct(
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
        public DateTimeImmutable $latestStart,
    ) {
    }
}
