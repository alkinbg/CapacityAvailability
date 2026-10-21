<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use Doctrine\DBAL\Connection;
use Alkin\CapacityAvailability\AvailabilityCalculator;
use Alkin\CapacityAvailability\Doctrine\CapacitySnapshot;
use Alkin\CapacityAvailability\Doctrine\DoctrineBookingWriter;

$db=connection();
$writer=bookingWriter($db);
$checks=0;
$runId=bin2hex(random_bytes(4));
$runtime=sys_get_temp_dir().'/alkin-capacity-workers-'.$runId;
mkdir($runtime,0700,true);

function ensure(bool $okay,string $message): void {
    global $checks;
    ++$checks;
    if(!$okay)throw new RuntimeException('CONCURRENCY FAIL: '.$message);
}

function seed(Connection $db,string $pool,int $capacity,int $initialUsed): void {
    $db->insert('capacity_pools',['pool_id'=>$pool]);
    $db->insert('capacity_intervals',[
        'pool_id'=>$pool,
        'starts_at'=>instant(0)->getTimestamp(),
        'ends_at'=>instant(60)->getTimestamp(),
        'units'=>$capacity
    ]);
    if($initialUsed>0) {
        $db->insert('capacity_bookings',[
            'booking_id'=>'existing-'.$pool,
            'pool_id'=>$pool,
            'starts_at'=>instant(0)->getTimestamp(),
            'ends_at'=>instant(60)->getTimestamp(),
            'units'=>$initialUsed
        ]);
    }
}

function play(
    Connection $db,
    string $folder,
    string $label,
    string $pool,
    string $mode,
    int $capacity,
    int $preexisting,
    int $perRequest,
    int $workers,
    int $expectedAccepted
): array {
    ensure(mkdir($folder,0700,true),'Create worker synchronization folder.');
    seed($db,$pool,$capacity,$preexisting);
    $gate=$folder.'/gate';
    $running=[];
    for($i=0;$i<$workers;$i++){
        $id=$pool.'-req-'.$i;
        $ready=$folder.'/ready-'.$i.'.json';
        $cmd=[
            PHP_BINARY,__DIR__.'/booking_worker.php',$mode,
            $pool,$id,$ready,$gate,(string)$perRequest,
        ];
        $handle=proc_open($cmd,[
            0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w'],
        ],$pipes,$folder);
        ensure(is_resource($handle),'Spawn independent process for worker '.$i);
        fclose($pipes[0]);
        $running[]=['handle'=>$handle,'stdout'=>$pipes[1],'stderr'=>$pipes[2],'ready'=>$ready,'id'=>$id];
    }

    $deadline=microtime(true)+15;
    $preChecks=[];
    while(true){
        clearstatcache();
        $readyCount=count(array_filter($running,static fn($p)=>is_file($p['ready'])));
        if($readyCount===$workers){
            $preChecks=array_map(static fn($p)=>json_decode(
                file_get_contents($p['ready']),true,flags:JSON_THROW_ON_ERROR
            ),$running);
            break;
        }
        if(microtime(true)>$deadline){
            foreach($running as $p)proc_terminate($p['handle']);
            throw new RuntimeException('Failed to synchronize workers: '.$label.' '.$readyCount.'/'.$workers);
        }
        usleep(3000);
    }
    if($mode==='unsafe'){
        ensure(count(array_filter($preChecks,static fn($row)=>$row['preliminary']===true))===$workers,
            'Every unsafe worker read the same apparently available snapshot.');
    }
    file_put_contents($gate,'start');

    $results=[];
    foreach($running as $p){
        $out=trim(stream_get_contents($p['stdout']));
        $err=trim(stream_get_contents($p['stderr']));
        fclose($p['stdout']);fclose($p['stderr']);
        $exit=proc_close($p['handle']);
        ensure($exit===0,'Worker '.$p['id'].' failed with '.$err);
        $result=json_decode($out,true,flags:JSON_THROW_ON_ERROR);
        ensure($result['id']===$p['id'],'Worker ID returned consistently.');
        $results[]=$result;
    }

    $accepted=count(array_filter($results,static fn($p)=>$p['accepted']===true));
    ensure($accepted===$expectedAccepted,$label.' acceptance count, expected '.$expectedAccepted.', actual '.$accepted);

    $entries=$db->fetchAllAssociative(
        'SELECT booking_id, units FROM capacity_bookings WHERE pool_id = :pool',
        ['pool'=>$pool],
    );
    $occupied=array_sum(array_column($entries,'units'));
    ensure(count($entries)===$accepted+($preexisting>0?1:0),'Committed row count equals accepted requests');
    ensure($occupied===$preexisting+$accepted*$perRequest,'Committed units match accepted quantities');

    $consistent=true;
    try{
        $snapshot=(new CapacitySnapshot($db))->read($pool,instant(0),instant(60));
        (new AvailabilityCalculator())->timeline($snapshot['capacity'],$snapshot['bookings'],instant(0),instant(60));
    }catch(DomainException){$consistent=false;}
    ensure($mode==='safe'?$consistent && $occupied<=$capacity:!$consistent && $occupied>$capacity,
        'Consistent only when appropriate transaction lock is used.');

    $result=[
        'scenario'=>$label,'pool'=>$pool,'mode'=>$mode,
        'capacity'=>$capacity,'preexistingUnits'=>$preexisting,
        'requestUnits'=>$perRequest,'simultaneousWorkers'=>$workers,
        'accepted'=>$accepted,'rejected'=>$workers-$accepted,
        'committedUnits'=>$occupied,'overbooked'=>$occupied>$capacity,
        'consistent'=>$consistent
    ];
    echo sprintf(
        "PASS %-23s mode=%-6s workers=%2d accepted=%2d booked=%2d/%d overbook=%s\n",
        $label,$mode,$workers,$accepted,$occupied,$capacity,$result['overbooked']?'YES':'no',
    );
    return $result;
}

// First demonstrate the exact race that the core alone cannot prevent.
$definitions=[
    ['unsafe-two','unsafe',3,1,2,2,2],
    ['safe-two','safe',3,1,2,2,1],
    ['safe-five','safe',2,0,1,5,2],
    ['safe-eight','safe',4,1,1,8,3],
    ['safe-twelve','safe',5,0,1,12,5],
];
for($j=0;$j<7;$j++){
    $definitions[]=['repeat-'.str_pad((string)$j,2,'0',STR_PAD_LEFT),'safe',3,1,2,2,1];
}
$results=[];
foreach($definitions as $definition){
    [$label,$mode,$capacity,$initial,$units,$workers,$expect]=$definition;
    $pool=$runId.'-'.$label;
    $results[]=play($db,$runtime.'/'.$label,$label,$pool,$mode,$capacity,$initial,$units,$workers,$expect);
}

// Edge case: missing pool must not cause an INSERT.
$rejected=false;
try{$writer->reserve('unknown-'.$runId,$runId.'-no-such-pool',instant(0),instant(60),1);}
catch(DomainException){$rejected=true;}
ensure($rejected,'Missing pool is rejected with clear domain error.');
ensure($db->getTransactionNestingLevel()===0,'No leaked transaction after missing pool.');

$pool=$runId.'-duplicate';
seed($db,$pool,3,0);
ensure($writer->reserve('duplicate-booking-'.$runId,$pool,instant(0),instant(60),1),'First booking committed.');
$duplicateRejected=false;
try{$writer->reserve('duplicate-booking-'.$runId,$pool,instant(0),instant(60),1);}
catch(\Doctrine\DBAL\Exception\UniqueConstraintViolationException){$duplicateRejected=true;}
ensure($duplicateRejected,'Duplicate reservation ID rejected by database uniqueness constraint.');
ensure($db->getTransactionNestingLevel()===0,'No leaked transaction after duplicate key error.');
ensure($db->fetchOne('SELECT COUNT(*) FROM capacity_bookings WHERE pool_id=:p',['p'=>$pool])==1,
    'A rejected duplicate does not create another row.');
ensure($writer->reserve('new-id-'.$runId,$pool,instant(0),instant(60),1),
    'Connection remains usable after rollback.');

$db->beginTransaction();
$nested=false;
try{$writer->reserve('nested-'.$runId,$pool,instant(0),instant(60),1);}
catch(InvalidArgumentException){$nested=true;}
finally{$db->rollBack();}
ensure($nested,'Nested surrounding transactions explicitly rejected.');

$report=[
    'date'=>'2026-10-21',
    'databaseVersion'=>$db->fetchOne('SELECT VERSION()'),
    'doctrineVersion'=>'4.5.0',
    'note'=>'MariaDB temporary isolated Docker container, using Doctrine DBAL SELECT ... FOR UPDATE on a persistent pool row. Deliberate unsafe scenario demonstrates overbooking.',
    'scenarioCount'=>count($results),
    'workerProcesses'=>array_sum(array_column($results,'simultaneousWorkers')),
    'assertions'=>$checks,
    'results'=>$results,
    'additionalCases'=>['unknownPool','duplicateIdRollback','nestedTransactionRejected'],
];
file_put_contents(sys_get_temp_dir().'/alkin-capacity-mariadb-results.json',
    json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
echo "\nALL MARIA DBAL TESTS PASSED: ".count($results)." scenarios, ".$report['workerProcesses']." independent PHP processes, ".$checks." assertions.\n";
