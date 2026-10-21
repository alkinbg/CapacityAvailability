<?php

declare(strict_types=1);

namespace Alkin\CapacityAvailability;

use DateTimeImmutable;
use InvalidArgumentException;

/** @internal */
final class Instant
{
    public static function timestamp(DateTimeImmutable $time): int
    {
        if ($time->format('u') !== '000000') {
            throw new InvalidArgumentException('Second precision only; fractional seconds are not supported.');
        }

        return $time->getTimestamp();
    }

    public static function positiveRange(DateTimeImmutable $start, DateTimeImmutable $end): void
    {
        if (self::timestamp($start) >= self::timestamp($end)) {
            throw new InvalidArgumentException('The interval end must be later than its start.');
        }
    }

    public static function at(DateTimeImmutable $reference, int $timestamp): DateTimeImmutable
    {
        return $reference->setTimestamp($timestamp);
    }

    public static function checkedAdd(int $a, int $b): int
    {
        if (($b > 0 && $a > PHP_INT_MAX - $b)
            || ($b < 0 && $a < PHP_INT_MIN - $b)) {
            throw new \OverflowException('Capacity total exceeds integer range.');
        }

        return $a + $b;
    }
}
