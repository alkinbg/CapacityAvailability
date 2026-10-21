<?php

declare(strict_types=1);

namespace Alkin\CapacityAvailability;

use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;

/**
 * A pure, deterministic availability calculator for one fungible capacity pool.
 *
 * The calculator DOES NOT save reservations. Callers must recheck availability
 * under a transaction/lock before committing a booking.
 */
final class AvailabilityCalculator
{
    /**
     * @param list<CapacityInterval> $capacity Absolute, non-overlapping capacity values.
     * @param list<Reservation> $reservations Existing capacity consumption.
     * @return list<CapacitySegment> Contiguous constant-capacity-and-usage segments.
     */
    public function timeline(
        array $capacity,
        array $reservations,
        DateTimeImmutable $from,
        DateTimeImmutable $until,
    ): array {
        Instant::positiveRange($from, $until);
        $queryStart = Instant::timestamp($from);
        $queryEnd = Instant::timestamp($until);

        // All schedule declarations are validated, not just the visible part.
        $previousEnd = null;
        $windows = array_values($capacity);
        foreach ($windows as $entry) {
            if (!$entry instanceof CapacityInterval) {
                throw new InvalidArgumentException('Capacity entries must be CapacityInterval instances.');
            }
        }
        usort(
            $windows,
            static fn (CapacityInterval $a, CapacityInterval $b): int =>
                Instant::timestamp($a->start) <=> Instant::timestamp($b->start),
        );
        foreach ($windows as $entry) {
            $start = Instant::timestamp($entry->start);
            $end = Instant::timestamp($entry->end);
            if ($previousEnd !== null && $start < $previousEnd) {
                throw new InvalidArgumentException(
                    'Absolute capacity intervals cannot overlap. Split them into consecutive windows.',
                );
            }
            $previousEnd = $end;
        }

        /** @var array<int,array{capacity:int,usage:int}> $changes */
        $changes = [
            $queryStart => ['capacity' => 0, 'usage' => 0],
            $queryEnd => ['capacity' => 0, 'usage' => 0],
        ];

        foreach ($windows as $entry) {
            $this->addDeltas(
                $changes,
                Instant::timestamp($entry->start),
                Instant::timestamp($entry->end),
                $entry->units,
                0,
                $queryStart,
                $queryEnd,
            );
        }
        foreach ($reservations as $entry) {
            if (!$entry instanceof Reservation) {
                throw new InvalidArgumentException('Reservation entries must be Reservation instances.');
            }
            $this->addDeltas(
                $changes,
                Instant::timestamp($entry->start),
                Instant::timestamp($entry->end),
                0,
                $entry->units,
                $queryStart,
                $queryEnd,
            );
        }

        ksort($changes, SORT_NUMERIC);
        $times = array_keys($changes);
        $activeCapacity = 0;
        $activeUsage = 0;
        $segments = [];

        foreach ($times as $i => $timestamp) {
            $activeCapacity = Instant::checkedAdd($activeCapacity, $changes[$timestamp]['capacity']);
            $activeUsage = Instant::checkedAdd($activeUsage, $changes[$timestamp]['usage']);
            if (!isset($times[$i + 1])) {
                break;
            }
            $nextTime = $times[$i + 1];
            if ($activeCapacity < 0 || $activeUsage < 0 || $activeUsage > $activeCapacity) {
                throw new DomainException(sprintf(
                    'Inconsistent existing reservations at %s: capacity=%d, occupied=%d.',
                    gmdate('Y-m-d H:i:s', $timestamp),
                    $activeCapacity,
                    $activeUsage,
                ));
            }

            // Adjacent events that leave the same state are not useful boundaries.
            $previous = count($segments) - 1;
            if ($previous >= 0
                && $segments[$previous]->capacity === $activeCapacity
                && $segments[$previous]->occupied === $activeUsage
            ) {
                $segments[$previous] = new CapacitySegment(
                    $segments[$previous]->start,
                    Instant::at($from, $nextTime),
                    $activeCapacity,
                    $activeUsage,
                );
            } else {
                $segments[] = new CapacitySegment(
                    Instant::at($from, $timestamp),
                    Instant::at($from, $nextTime),
                    $activeCapacity,
                    $activeUsage,
                );
            }
        }

        return array_values($segments);
    }

    /**
     * Find all continuous windows with sufficient free units for the full
     * requested duration, not just at one point in time.
     *
     * @param list<CapacityInterval> $capacity
     * @param list<Reservation> $reservations
     * @return list<AvailabilityWindow>
     */
    public function availableWindows(
        array $capacity,
        array $reservations,
        DateTimeImmutable $from,
        DateTimeImmutable $until,
        int $requiredUnits,
        int $durationSeconds,
    ): array {
        if ($requiredUnits < 1 || $durationSeconds < 1) {
            throw new InvalidArgumentException('Required units and duration must both be positive.');
        }

        $openStart = null;
        $windows = [];
        foreach ($this->timeline($capacity, $reservations, $from, $until) as $period) {
            if ($period->free >= $requiredUnits) {
                $openStart ??= $period->start;
                continue;
            }
            if ($openStart !== null) {
                $window = $this->windowIfLongEnough($openStart, $period->start, $durationSeconds);
                if ($window !== null) {
                    $windows[] = $window;
                }
                $openStart = null;
            }
        }
        if ($openStart !== null) {
            $window = $this->windowIfLongEnough($openStart, $until, $durationSeconds);
            if ($window !== null) {
                $windows[] = $window;
            }
        }

        return $windows;
    }

    /**
     * Read-only check for an exact requested interval; not a transactional hold.
     *
     * @param list<CapacityInterval> $capacity
     * @param list<Reservation> $reservations
     */
    public function canBook(
        array $capacity,
        array $reservations,
        DateTimeImmutable $from,
        DateTimeImmutable $until,
        int $requiredUnits,
    ): bool {
        Instant::positiveRange($from, $until);
        if ($requiredUnits < 1) {
            throw new InvalidArgumentException('Required units must be positive.');
        }

        foreach ($this->timeline($capacity, $reservations, $from, $until) as $segment) {
            if ($segment->free < $requiredUnits) {
                return false;
            }
        }

        return true;
    }

    private function windowIfLongEnough(
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        int $durationSeconds,
    ): ?AvailabilityWindow {
        $length = Instant::timestamp($end) - Instant::timestamp($start);
        if ($length < $durationSeconds) {
            return null;
        }
        return new AvailabilityWindow(
            $start,
            $end,
            Instant::at($start, Instant::timestamp($end) - $durationSeconds),
        );
    }

    /**
     * @param array<int,array{capacity:int,usage:int}> $changes
     */
    private function addDeltas(
        array &$changes,
        int $start,
        int $end,
        int $capacityDelta,
        int $usageDelta,
        int $queryStart,
        int $queryEnd,
    ): void {
        $start = max($queryStart, $start);
        $end = min($queryEnd, $end);
        if ($start >= $end) {
            return;
        }

        $startChange = $changes[$start] ?? ['capacity' => 0, 'usage' => 0];
        $startChange['capacity'] = Instant::checkedAdd($startChange['capacity'], $capacityDelta);
        $startChange['usage'] = Instant::checkedAdd($startChange['usage'], $usageDelta);
        $changes[$start] = $startChange;

        $endChange = $changes[$end] ?? ['capacity' => 0, 'usage' => 0];
        $endChange['capacity'] = Instant::checkedAdd($endChange['capacity'], -$capacityDelta);
        $endChange['usage'] = Instant::checkedAdd($endChange['usage'], -$usageDelta);
        $changes[$end] = $endChange;
    }
}
