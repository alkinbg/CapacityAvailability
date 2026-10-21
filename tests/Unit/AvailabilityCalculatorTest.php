<?php

declare(strict_types=1);

namespace Alkin\CapacityAvailability\Tests\Unit;

use Alkin\CapacityAvailability\AvailabilityCalculator;
use Alkin\CapacityAvailability\CapacityInterval;
use Alkin\CapacityAvailability\Reservation;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\TestCase;

final class AvailabilityCalculatorTest extends TestCase
{
    private AvailabilityCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new AvailabilityCalculator();
    }

    public function testChangingCapacityReturnsContinuousStartRange(): void
    {
        $windows = $this->calculator->availableWindows(
            [
                $this->capacity('09:00', '10:00', 2),
                $this->capacity('10:00', '11:00', 3),
            ],
            [$this->reservation('09:30', '10:30', 1)],
            $this->at('09:00'),
            $this->at('11:00'),
            2,
            45 * 60,
        );

        self::assertCount(1, $windows);
        self::assertSame('10:00', $windows[0]->start->format('H:i'));
        self::assertSame('11:00', $windows[0]->end->format('H:i'));
        self::assertSame('10:15', $windows[0]->latestStart->format('H:i'));
    }

    public function testNumberOfIntersectingReservationsIsNotPeakOccupancy(): void
    {
        $capacities = [$this->capacity('09:00', '11:00', 3)];
        $reservations = [
            $this->reservation('09:00', '09:30', 1),
            $this->reservation('09:30', '10:00', 1),
            $this->reservation('10:00', '10:30', 1),
        ];

        self::assertTrue($this->calculator->canBook(
            $capacities, $reservations, $this->at('09:00'), $this->at('10:30'), 2,
        ));
    }

    public function testOverlappingBookingsCanExhaustCapacityTemporarily(): void
    {
        self::assertFalse($this->calculator->canBook(
            [$this->capacity('09:00', '11:00', 3)],
            [
                $this->reservation('09:00', '10:00', 1),
                $this->reservation('09:30', '10:30', 2),
            ],
            $this->at('09:00'),
            $this->at('10:30'),
            1,
        ));
    }

    public function testHalfOpenBoundaryAllowsBookingAtPreviousEnd(): void
    {
        $capacity = [$this->capacity('09:00', '11:00', 1)];
        $reservations = [$this->reservation('09:00', '10:00', 1)];

        self::assertTrue($this->calculator->canBook(
            $capacity, $reservations, $this->at('10:00'), $this->at('11:00'), 1,
        ));
        self::assertFalse($this->calculator->canBook(
            $capacity, $reservations, $this->at('09:59'), $this->at('10:01'), 1,
        ));
    }

    public function testMissingOrClosedCapacityCreatesNoAvailability(): void
    {
        self::assertSame([], $this->calculator->availableWindows(
            [], [], $this->at('09:00'), $this->at('11:00'), 1, 60,
        ));
        self::assertSame([], $this->calculator->availableWindows(
            [$this->capacity('09:00', '11:00', 0)],
            [],
            $this->at('09:00'),
            $this->at('11:00'),
            1,
            60,
        ));
    }

    public function testNoCapacityCanBeCarriedAcrossClosure(): void
    {
        self::assertSame([], $this->calculator->availableWindows(
            [
                $this->capacity('09:00', '10:00', 3),
                $this->capacity('10:30', '11:30', 3),
            ],
            [],
            $this->at('09:00'),
            $this->at('11:30'),
            2,
            75 * 60,
        ));
    }

    public function testAdjacentSameStateEventsAreMergedInTimeline(): void
    {
        $segments = $this->calculator->timeline(
            [
                $this->capacity('09:00', '10:00', 3),
                $this->capacity('10:00', '11:00', 3),
            ],
            [
                $this->reservation('09:00', '09:30', 1),
                $this->reservation('09:30', '11:00', 1),
            ],
            $this->at('09:00'),
            $this->at('11:00'),
        );

        self::assertCount(1, $segments);
        self::assertSame(2, $segments[0]->free);
        self::assertSame('09:00', $segments[0]->start->format('H:i'));
        self::assertSame('11:00', $segments[0]->end->format('H:i'));
    }

    public function testExistingOverbookingIsExplicitDataError(): void
    {
        $this->expectException(DomainException::class);
        $this->calculator->timeline(
            [$this->capacity('09:00', '10:00', 1)],
            [$this->reservation('09:15', '09:45', 2)],
            $this->at('09:00'),
            $this->at('10:00'),
        );
    }

    public function testCapacityIntervalsMustNotOverlap(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->timeline(
            [$this->capacity('09:00', '10:00', 2), $this->capacity('09:59', '11:00', 2)],
            [],
            $this->at('09:00'),
            $this->at('11:00'),
        );
    }

    public function testInvalidTimePrecisionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Reservation(
            new DateTimeImmutable('2026-10-22T09:00:00.000001+00:00'),
            $this->at('10:00'),
            1,
        );
    }

    public function testNonpositiveDemandIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->availableWindows(
            [$this->capacity('09:00', '11:00', 3)],
            [],
            $this->at('09:00'),
            $this->at('11:00'),
            0,
            45 * 60,
        );
    }

    public function testInvalidRuntimeInputTypesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->timeline(
            [new \stdClass()],
            [],
            $this->at('09:00'),
            $this->at('10:00'),
        );
    }

    public function testRepeatedLocalHourAcrossDstUsesAbsoluteInstants(): void
    {
        $tz = new DateTimeZone('Europe/Sofia');
        $from = new DateTimeImmutable('2026-10-25T00:30:00Z');
        $until = new DateTimeImmutable('2026-10-25T01:30:00Z');

        $windows = $this->calculator->availableWindows(
            [new CapacityInterval($from, $until, 2)],
            [],
            $from->setTimezone($tz),
            $until->setTimezone($tz),
            2,
            3600,
        );

        self::assertCount(1, $windows);
        self::assertSame($windows[0]->start->format('H:i'), $windows[0]->end->format('H:i'));
        self::assertNotSame($windows[0]->start->format('P'), $windows[0]->end->format('P'));
        self::assertSame($from->getTimestamp(), $windows[0]->start->getTimestamp());
        self::assertSame($until->getTimestamp(), $windows[0]->end->getTimestamp());
    }

    public function testOverflowIsRejectedWithoutProducingAFalseAnswer(): void
    {
        $this->expectException(OverflowException::class);
        $this->calculator->timeline(
            [$this->capacity('09:00', '10:00', PHP_INT_MAX)],
            [
                $this->reservation('09:00', '10:00', PHP_INT_MAX),
                $this->reservation('09:00', '10:00', PHP_INT_MAX),
            ],
            $this->at('09:00'),
            $this->at('10:00'),
        );
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-22 '.$time.':00+00:00');
    }

    private function capacity(string $start, string $end, int $units): CapacityInterval
    {
        return new CapacityInterval($this->at($start), $this->at($end), $units);
    }

    private function reservation(string $start, string $end, int $units): Reservation
    {
        return new Reservation($this->at($start), $this->at($end), $units);
    }
}
