<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Alkin\CapacityAvailability\AvailabilityCalculator;
use Alkin\CapacityAvailability\CapacityInterval;
use Alkin\CapacityAvailability\Reservation;

$at = static fn (string $time): DateTimeImmutable =>
    new DateTimeImmutable('2026-10-22 '.$time.':00+00:00');

$capacities = [
    new CapacityInterval($at('09:00'), $at('10:00'), 2),
    new CapacityInterval($at('10:00'), $at('11:00'), 3),
];
$reservations = [
    new Reservation($at('09:30'), $at('10:30'), 1),
];

$calculator = new AvailabilityCalculator();

foreach ($calculator->availableWindows(
    $capacities,
    $reservations,
    $at('09:00'),
    $at('11:00'),
    requiredUnits: 2,
    durationSeconds: 45 * 60,
) as $window) {
    printf(
        "Free capacity 2: %s–%s, last possible 45-minute start: %s\n",
        $window->start->format('H:i'),
        $window->end->format('H:i'),
        $window->latestStart->format('H:i'),
    );
}
