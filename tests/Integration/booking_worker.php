<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

[$script,$mode,$pool,$id,$ready,$gate,$units] = $argv;
$db=connection();
$writer=bookingWriter($db);
$start=instant(0);
$end=instant(60);
$units=(int)$units;

try {
    $preliminary=null;
    if($mode==='unsafe') {
        // Two independent DBAL connections read before the barrier.
        $preliminary=$writer->canBook($pool,$start,$end,$units);
    }

    file_put_contents($ready,json_encode([
        'pid'=>getmypid(),'preliminary'=>$preliminary
    ],JSON_THROW_ON_ERROR));

    $deadline=microtime(true)+18;
    while(!is_file($gate)) {
        if(microtime(true)>$deadline) throw new RuntimeException('Gate was not released.');
        clearstatcache(true,$gate);
        usleep(2000);
    }

    if($mode==='safe') {
        $accepted=$writer->reserve($id,$pool,$start,$end,$units);
    }elseif($mode==='unsafe') {
        if($preliminary){
            // Intentionally bypasses the transactional writer.
            $db->insert('capacity_bookings',[
                'booking_id'=>$id,'pool_id'=>$pool,
                'starts_at'=>$start->getTimestamp(),'ends_at'=>$end->getTimestamp(),'units'=>$units
            ]);
        }
        $accepted=$preliminary;
    }else{
        throw new InvalidArgumentException('Unknown worker mode');
    }

    echo json_encode([
        'id'=>$id,'mode'=>$mode,'accepted'=>$accepted,'preliminary'=>$preliminary
    ],JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e) {
    fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n");
    exit(3);
}
