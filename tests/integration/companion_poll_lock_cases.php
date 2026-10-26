<?php
declare(strict_types=1);
// Included only after companion_pg.php has verified its disposable database identity.
// Default-off and cooled-down heartbeat polls must not take the eventlog-locking writer.
$lockClock=$store->scope();
$lockTs=(int)$lockClock['ts']+1;$lockGamets=(int)$lockClock['gamets']+20*416667;
$holdEventlog=static function(callable $work)use($peer):mixed{
    // SHARE conflicts with the writer's SHARE ROW EXCLUSIVE lock but still allows reads.
    companionPgSql($peer,'BEGIN');companionPgSql($peer,'LOCK TABLE public.eventlog IN SHARE MODE');
    try{return $work();}finally{companionPgSql($peer,'ROLLBACK');}
};
$timedPoll=static function()use($companion,$holdEventlog):array{
    $started=hrtime(true);$result=$holdEventlog(fn()=>$companion->poll());
    companionPgCheck(hrtime(true)-$started<2000000000,'skipped poll must not wait for the eventlog lock');
    return $result;
};

$autonomy->setEnabled(false);
$store->bindRequestClock(['request',(string)$lockTs,(string)$lockGamets,'synthetic']);
companionPgCheck($timedPoll()===['status'=>'paused','reason'=>'disabled'],'default-off poll pauses without the writer lock');

$autonomy->setEnabled(true);
$store->bindRequestClock(['request',(string)($lockTs+1),(string)$lockGamets,'synthetic']);
companionPgCheck($companion->poll()['status']==='queued','enabled poll still issues an observation');
$store->bindRequestClock(['request',(string)($lockTs+2),(string)($lockGamets+1000),'synthetic']);
companionPgCheck($timedPoll()===['status'=>'idle','reason'=>'poll_cooldown'],'cooled-down poll idles without the writer lock');

// The lock above must be effective: a due poll still needs the writer and waits for it.
$store->bindRequestClock(['request',(string)($lockTs+3),(string)($lockGamets+416667),'synthetic']);
$blocked=false;
try{$holdEventlog(fn()=>$companion->poll());}catch(RuntimeException $error){$blocked=$error->getMessage()==='storage_failed';}
companionPgCheck($blocked,'due poll still takes the writer lock');
companionPgCheck($companion->poll()['status']==='queued','due poll issues once the lock is released');
echo "companion_poll_lock_pg PASS: default-off and cooled-down polls skip the eventlog writer\n";
