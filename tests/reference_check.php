<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Alkin\CapacityAvailability\AvailabilityCalculator;
use Alkin\CapacityAvailability\AvailabilityWindow;
use Alkin\CapacityAvailability\CapacityInterval;
use Alkin\CapacityAvailability\Reservation;

$solver = new AvailabilityCalculator();
$base = new DateTimeImmutable('2026-10-22T09:00:00+00:00');
$at = static fn (int $minute, int $seconds = 0): DateTimeImmutable =>
    $base->modify('+'.($minute * 60 + $seconds).' seconds');
$cap = static fn (int $a, int $b, int $units): CapacityInterval =>
    new CapacityInterval($at($a), $at($b), $units);
$res = static fn (int $a, int $b, int $units): Reservation =>
    new Reservation($at($a), $at($b), $units);
$minutes = static fn (DateTimeImmutable $instant): int =>
    intdiv($instant->getTimestamp() - $base->getTimestamp(), 60);

$checks = 0;
$assert = static function (bool $valid, string $label) use (&$checks): void {
    ++$checks;
    if (!$valid) {
        throw new RuntimeException('FAIL: '.$label);
    }
};
$expect = static function (callable $operation, string $expectedType, string $message) use ($assert): void {
    $caught = null;
    try {
        $operation();
    } catch (Throwable $error) {
        $caught = $error;
    }
    $assert($caught instanceof $expectedType, $message.'; got '.($caught ? get_class($caught).': '.$caught->getMessage() : 'no exception'));
};

$capacity = [$cap(0, 60, 2), $cap(60, 120, 3)];
$reservations = [$res(30, 90, 1)];
$windows = $solver->availableWindows($capacity, $reservations, $at(0), $at(120), 2, 45 * 60);
$assert(count($windows) === 1, 'Variable capacity produces one sufficient window.');
$assert($minutes($windows[0]->start) === 60, 'Availability starts at 10:00.');
$assert($minutes($windows[0]->end) === 120, 'Availability ends at 11:00.');
$assert($minutes($windows[0]->latestStart) === 75, 'Latest valid start 10:15.');
$assert(!$solver->canBook($capacity, $reservations, $at(45), $at(90), 2), 'Overloaded start rejected.');
$assert($solver->canBook($capacity, $reservations, $at(60), $at(105), 2), 'Valid start accepted.');

$sequential = [$res(0,30,1),$res(30,60,1),$res(60,90,1)];
$assert($solver->canBook([$cap(0,120,3)], $sequential, $at(0),$at(90),2),
    'Three reservations intersect query, but peak usage is only one.');
$assert($solver->canBook([$cap(0,120,1)], [$res(0,60,1)], $at(60),$at(120),1),
    'A reservation ending at the exact next start does not block it.');
$assert(!$solver->canBook([$cap(0,120,1)], [$res(0,60,1)], $at(59),$at(61),1),
    'Even one minute of overlap blocks the new booking.');

$segments = $solver->timeline([$cap(60,120,3),$cap(0,60,2)],$reservations,$at(0),$at(120));
$assert(count($segments) === 4, 'Timeline has four constant usage/capacity segments.');
$assert(array_map(static fn ($s) => $s->free, $segments) === [2,1,2,3],
    'Timeline maintains changes in occupancy and capacity.');
$assert($solver->availableWindows([$cap(0,60,2),$cap(90,150,2)],[], $at(0),$at(150),2,75*60) === [],
    'A gap between open windows cannot be bridged.');
$assert($solver->availableWindows([],[],$at(0),$at(60),1,60) === [],
    'Without declared capacity there is no availability.');
$assert($solver->availableWindows([$cap(0,60,0)],[],$at(0),$at(60),1,60) === [],
    'Explicit closure has no availability.');
$assert($solver->availableWindows([$cap(0,45,3)],[],$at(0),$at(60),2,45*60)[0]->latestStart
    == $at(0), 'A window matching duration exactly allows its first start.');

$peak = [$res(0,60,1),$res(30,90,2)];
$assert(!$solver->canBook([$cap(0,180,3)],$peak,$at(0),$at(90),1),
    'Peak occupancy can exhaust capacity temporarily.');
$assert($solver->canBook([$cap(0,180,3)],$peak,$at(90),$at(150),3),
    'Whole capacity available again after both bookings end.');

$clip = $solver->timeline([$cap(-60,180,2)],[$res(-90,20,1),$res(100,270,1)],$at(0),$at(120));
$assert(count($clip) === 3, 'Reservations outside the query window are clipped.');
$assert(array_map(static fn ($s) => $s->free,$clip) === [1,2,1],
    'Correct free levels after clipping both sides.');

$windowsTwo = $solver->availableWindows(
    [$cap(0,60,3),$cap(60,120,4)],[$res(40,90,1)],$at(0),$at(120),2,110*60,
);
$assert(count($windowsTwo) === 1 && $minutes($windowsTwo[0]->latestStart) === 10,
    'Capacity can change while enough free units remain; the interval is continuous.');

$expect(static fn() => new CapacityInterval($at(30),$at(30),1), InvalidArgumentException::class,
    'Zero-length capacity range must be rejected.');
$expect(static fn() => new Reservation($at(30),$at(30),1), InvalidArgumentException::class,
    'Zero-length booking must be rejected.');
$expect(static fn() => new CapacityInterval($at(0),$at(10),-1), InvalidArgumentException::class,
    'Negative capacity rejected.');
$expect(static fn() => new Reservation($at(0),$at(10),0), InvalidArgumentException::class,
    'Zero units rejected.');
$expect(static fn() => $solver->timeline([$cap(0,60,1),$cap(59,90,2)],[],$at(0),$at(90)),
    InvalidArgumentException::class, 'Overlapping capacity declarations rejected.');
$expect(static fn() => $solver->timeline([$cap(0,60,1)],[$res(10,40,2)],$at(0),$at(60)),
    DomainException::class, 'Existing overbooking is not silently accepted.');
$expect(static fn() => $solver->timeline([$cap(0,30,2)],[$res(20,40,1)],$at(0),$at(60)),
    DomainException::class, 'Booking outside declared capacity causes a data error.');
$expect(static fn() => $solver->availableWindows([$cap(0,60,1)],[],$at(0),$at(60),0,300),
    InvalidArgumentException::class, 'Zero demand rejected.');
$expect(static fn() => $solver->availableWindows([$cap(0,60,1)],[],$at(0),$at(60),1,0),
    InvalidArgumentException::class, 'Zero duration rejected.');
$expect(static fn() => $solver->canBook([$cap(0,60,1)],[],$at(60),$at(0),1),
    InvalidArgumentException::class, 'Reversed query interval rejected.');
$expect(static fn() => $solver->timeline([new stdClass()],[],$at(0),$at(60)),
    InvalidArgumentException::class, 'Capacity input type checked.');
$expect(static fn() => $solver->timeline([], [new stdClass()],$at(0),$at(60)),
    InvalidArgumentException::class, 'Reservation input type checked.');
$expect(static fn() => new Reservation($at(0)->modify('+1 microsecond'),$at(10),1),
    InvalidArgumentException::class, 'Microsecond precision rejected.');
$expect(static fn() => $solver->timeline([$cap(0,60,PHP_INT_MAX)],
    [$res(0,60,PHP_INT_MAX),$res(0,60,PHP_INT_MAX)],$at(0),$at(60)),
    OverflowException::class, 'Capacity arithmetic overflow rejected.');

$sofia = new DateTimeZone('Europe/Sofia');
$utcStart = new DateTimeImmutable('2026-10-25T00:30:00Z');
$utcEnd = new DateTimeImmutable('2026-10-25T01:30:00Z');
$localStart = $utcStart->setTimezone($sofia);
$localEnd = $utcEnd->setTimezone($sofia);
$dstWindows = $solver->availableWindows(
    [new CapacityInterval($utcStart,$utcEnd,2)],[],
    $localStart,$localEnd,2,3600,
);
$assert(count($dstWindows)===1, 'DST repeated hour is correctly handled in absolute time.');
$assert($dstWindows[0]->start->getTimestamp()===$utcStart->getTimestamp(),
    'DST start retains the absolute instant.');
$assert($dstWindows[0]->end->getTimestamp()===$utcEnd->getTimestamp(),
    'DST end retains the absolute instant.');
$assert($localStart->format('H:i')===$localEnd->format('H:i')
    && $localStart->format('P')!==$localEnd->format('P'),
    'DST fall-back case contains two different 03:30 local instants.');

function independentFree(int $instant, array $capacity, array $reservations): int
{
    $available = 0;
    foreach ($capacity as $interval) {
        if ($interval['from'] <= $instant && $instant < $interval['to']) {
            $available = $interval['capacity'];
        }
    }
    $used = 0;
    foreach ($reservations as $reservation) {
        if ($reservation['from'] <= $instant && $instant < $reservation['to']) {
            $used += $reservation['units'];
        }
    }
    return $available - $used;
}

mt_srand(20261021);
$randomCalendars=300;
for ($trial=0;$trial<$randomCalendars;$trial++) {
    $definition=[];$capacity=[];
    for ($i=0;$i<4;$i++) {
        $level=mt_rand(1,4);
        $definition[]=['from'=>60*$i,'to'=>60*($i+1),'capacity'=>$level];
        $capacity[]=$cap(60*$i,60*($i+1),$level);
    }
    $rawReservations=[];
    for ($n=mt_rand(0,8);$n>0;$n--) {
        $start=mt_rand(0,239);
        $end=mt_rand($start+1,min($start+70,240));
        $units=mt_rand(1,2);
        $proposal=[...$rawReservations,['from'=>$start,'to'=>$end,'units'=>$units]];
        $feasible=true;
        for($t=$start;$t<$end;$t++){
            if(independentFree($t,$definition,$proposal)<0){$feasible=false;break;}
        }
        if($feasible)$rawReservations=$proposal;
    }
    $reservations=array_map(static fn ($r) => $res($r['from'],$r['to'],$r['units']),$rawReservations);
    $need=mt_rand(1,4);$duration=mt_rand(1,80);
    $windows=$solver->availableWindows($capacity,$reservations,$at(0),$at(240),$need,$duration*60);

    for ($start=0;$start+$duration<=240;$start++) {
        $oracle=true;
        for($minute=$start;$minute<$start+$duration;$minute++){
            if(independentFree($minute,$definition,$rawReservations)<$need){
                $oracle=false;break;
            }
        }
        $actual=false;
        foreach($windows as $window){
            if($window->start->getTimestamp()<=$at($start)->getTimestamp()
               && $at($start)->getTimestamp()<=$window->latestStart->getTimestamp()){
                $actual=true;break;
            }
        }
        $assert($actual===$oracle,'Random minute oracle trial '.$trial.' start '.$start);
    }
}
// Independent second-by-second oracle for non-minute boundaries.
$secondTrials=80;
for ($trial=0;$trial<$secondTrials;$trial++) {
    $width=mt_rand(60,500);
    $allowed=mt_rand(1,3);
    $raw=[];$bookings=[];
    for ($b=0;$b<4;$b++) {
        $a=mt_rand(0,$width-1);
        $end=mt_rand($a+1,$width);
        $raw[]=['from'=>$a,'to'=>$end,'units'=>1];
    }
    $capacity=[new CapacityInterval($at(0),$at(0,$width),4)];
    $bookings=array_map(
        static fn($r) => new Reservation($at(0,$r['from']),$at(0,$r['to']),1),$raw,
    );
    $duration=mt_rand(1,min(80,$width));
    $windows=$solver->availableWindows($capacity,$bookings,$at(0),$at(0,$width),$allowed,$duration);
    for($s=0;$s+$duration<=$width;$s++){
        $oracle=true;
        for($t=$s;$t<$s+$duration;$t++){
            if(independentFree($t,[['from'=>0,'to'=>$width,'capacity'=>4]],$raw)<$allowed){
                $oracle=false;break;
            }
        }
        $actual=false;
        foreach($windows as $window){
            $baseSec=$at(0)->getTimestamp();
            if($window->start->getTimestamp()-$baseSec<=$s &&
                $s<=$window->latestStart->getTimestamp()-$baseSec){
                $actual=true;break;
            }
        }
        $assert($actual===$oracle,'Second-boundary oracle '.$trial.' start '.$s);
    }
}

$many=[];
for ($i=0;$i<10000;$i++) {
    $many[]=new Reservation($at($i),$at($i+1),1);
}
$t0=hrtime(true);
$bench=$solver->availableWindows([$cap(0,10010,3)],$many,$at(0),$at(10010),2,60);
$milliseconds=round((hrtime(true)-$t0)/1e6,2);
$assert(count($bench)===1,'10k adjacent reservations leave two free units throughout.');

echo "Core assertions: ".$checks.PHP_EOL;
echo "Independent reference checks: ".$randomCalendars." minute calendars, ".$secondTrials." second calendars".PHP_EOL;
echo "10,000 reservations local microbenchmark: ".$milliseconds." ms (not a speed guarantee)".PHP_EOL;
echo "PASS".PHP_EOL;
