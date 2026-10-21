<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/support/recovery_pg_fixture.php';
require_once dirname(__DIR__,2).'/server/lib/bootstrap.php';

const F04_E1='11111111-1111-4111-8111-111111111111';
const F04_E2='22222222-2222-4222-8222-222222222222';

function f04Require(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function f04Sql(PgSql\Connection $connection,string $sql,array $args=[]):array
{
    $result=@pg_query_params($connection,$sql,$args);if($result===false)throw new RuntimeException('f04_fixture_sql_failed');
    try{return pg_num_fields($result)>0?(pg_fetch_all($result)?:[]):[];}finally{pg_free_result($result);}
}
function f04Store(PgSql\Connection $connection,string $sentinel):CorrespondencePgStore
{
    return new CorrespondencePgStore($connection,static fn($unused)=>null,static fn($title,$body)=>true,$sentinel);
}
function f04Marker(string $path):?array
{
    if(!is_file($path))return null;$value=json_decode((string)file_get_contents($path),true,8,JSON_THROW_ON_ERROR);
    return ['schema'=>$value['schema']??null,'state'=>$value['state']??null,'epoch'=>$value['epoch']??null];
}
function f04Connect():PgSql\Connection{return correspondenceRecoveryFixture('chim_corr_recovery_store');}
function ptp_connect():mixed
{
    $connection=f04Connect();$signal=$GLOBALS['F04_CHILD_PID_SIGNAL']??null;
    if(is_string($signal)&&!is_file($signal)){
        $result=pg_query($connection,'SELECT pg_backend_pid()');if($result===false)throw new RuntimeException('f04_child_pid_query_failed');
        file_put_contents($signal,(string)pg_fetch_result($result,0,0),LOCK_EX);pg_free_result($result);
    }
    return $connection;
}
function ptr_runtime_enter():void{}

function f04ChildCommand(array $arguments):array
{
    $command=[PHP_BINARY,'-n','-d','extension_dir='.ini_get('extension_dir')];
    foreach(['pgsql','mbstring','ctype','tokenizer']as$extension){
        if(!extension_loaded($extension))throw new RuntimeException('f04_child_php_extension_missing');
        $command[]='-d';$command[]='extension='.$extension;
    }
    return [...$command,__FILE__,...$arguments];
}
function f04StartChild(string $mode,string $root,string $signal=''):array
{
    $process=proc_open(f04ChildCommand([$mode,$root,$signal]),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('f04_child_start_failed');fclose($pipes[0]);return [$process,$pipes];
}
function f04FinishChild(array $child):array
{
    [$process,$pipes]=$child;stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$out='';$err='';$status=null;$deadline=microtime(true)+15.0;
    do{$out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);$status=proc_get_status($process);if(!$status['running'])break;usleep(20000);}while(microtime(true)<$deadline);
    if(is_array($status)&&$status['running']){@proc_terminate($process);$killDeadline=microtime(true)+2.0;do{$status=proc_get_status($process);if(!$status['running'])break;usleep(20000);}while(microtime(true)<$killDeadline);if(is_array($status)&&$status['running'])@proc_terminate($process,9);}
    $out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
    if($exit===-1&&is_array($status)&&is_int($status['exitcode']??null))$exit=$status['exitcode'];return [$exit,$out,$err];
}
function f04ExpectedMarkerLog(string $error):bool{return in_array(trim($error),['','[CORRESPONDENCE] restore_init_marker_unavailable'],true);}
function f04WaitFile(string $path,string $reason):void
{
    $deadline=microtime(true)+10.0;while(!is_file($path)&&microtime(true)<$deadline)usleep(10000);
    if(!is_file($path))throw new RuntimeException($reason);
}
function f04WaitActivity(PgSql\Connection $monitor,string $pid,string $event,string $queryPart):void
{
    $deadline=microtime(true)+8.0;
    while(microtime(true)<$deadline){
        $row=f04Sql($monitor,'SELECT state,query,wait_event_type,wait_event FROM pg_stat_activity WHERE pid=$1::integer',[$pid])[0]??[];
        if(($row['wait_event_type']??null)==='Lock'&&($row['wait_event']??null)===$event&&str_contains((string)($row['query']??''),$queryPart))return;
        usleep(20000);
    }
    throw new RuntimeException('f04_child_wait_not_observed');
}
function f04ExpectFailure(callable $call):Throwable
{
    try{$call();}catch(Throwable $error){return $error;}throw new RuntimeException('f04_expected_failure_missing');
}

$mode=$argv[1]??'run';
if($mode!=='run'){
    $root=$argv[2]??'';$signal=$argv[3]??'';
    if($root===''||!is_dir($root))throw new InvalidArgumentException('f04_child_root_required');
    $GLOBALS['ENGINE_PATH']=$root;$GLOBALS['ptr_runtime_generation']='f04-pg-fixture';
    if($signal!=='')$GLOBALS['F04_CHILD_PID_SIGNAL']=$signal;
    $request=['init','1800000000000000100','42','f04-pg-fixture'];
    if($mode==='recover_init'){
        correspondenceBeginRequestIngress($request);correspondencePrepareRestoreInit($request);
        f04Require(empty($GLOBALS['CORRESPONDENCE_RESTORE_INIT_UNSAFE']),'f04_recovery_init_marked_unsafe');
        $eventConnection=f04Connect();
        try{f04Sql($eventConnection,"INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES('1800000000000000101','42','init','f04 fixture','fixture',1,'','','')");}
        finally{pg_close($eventConnection);}
        $GLOBALS['TRIGGER_NARRATOR_WELCOME']=true;
        f04Require(correspondenceCompletePendingInit(),'f04_supported_init_did_not_complete');
        $store=$GLOBALS['CORRESPONDENCE_RESTORE_INIT_STORE']??null;
        f04Require($store instanceof CorrespondencePgStore&&empty($GLOBALS['CORRESPONDENCE_RESTORE_INIT_ACTIVE']),'f04_supported_init_state_missing');
        echo "PASS supported init recovered quarantined epoch\n";exit(0);
    }
    if(!in_array($mode,['failed_init','failed_init_race'],true))throw new InvalidArgumentException('f04_unknown_child_mode');
    correspondenceBeginRequestIngress($request);correspondencePrepareRestoreInit($request);
    $store=$GLOBALS['CORRESPONDENCE_RESTORE_INIT_STORE']??null;
    f04Require($store instanceof CorrespondencePgStore,'f04_failed_init_store_missing');
    f04Require(($GLOBALS['CORRESPONDENCE_RESTORE_INIT_UNSAFE']??false)===true,'f04_failed_init_must_be_locally_unsafe');
    if($mode==='failed_init_race')f04Require($store->requestRestoreToken()===F04_E1.':f04-pg-fixture','f04_request_token_was_not_captured_before_B');
    correspondenceReleaseInitFence();$GLOBALS['CORRESPONDENCE_RESTORE_INIT_ACTIVE']=false;
    echo $mode==='failed_init_race'?"PASS stale A returned unsafe after B completion\n":"PASS failed marker init returned unsafe\n";exit(0);
}

$connection=f04Connect();$monitor=f04Connect();
$GLOBALS['ptr_runtime_generation']='f04-pg-fixture';
$root=sys_get_temp_dir().DIRECTORY_SEPARATOR.'correspondence-f04-pg-'.bin2hex(random_bytes(8));
$runtimeDir=$root.DIRECTORY_SEPARATOR.'log'.DIRECTORY_SEPARATOR.'playthrough_runtime';
f04Require(@mkdir($runtimeDir,0700,true),'f04_tempdir_failed');
$marker=$runtimeDir.DIRECTORY_SEPARATOR.'correspondence.restore.json';
$lockPath=$marker.'.lock';$child=null;$markerLock=null;$advisoryHeld=false;$relationTransaction=false;
try{
    f04Require(f04Sql($connection,"SELECT table_name FROM information_schema.tables WHERE table_schema IN ('public','plugins','chim_meta')")===[],'f04_fresh_fixture_required');
    f04Sql($connection,"CREATE TABLE public.eventlog(rowid bigserial PRIMARY KEY,ts bigint NOT NULL,gamets numeric NOT NULL,type text NOT NULL,data text NOT NULL,sess text NOT NULL,localts bigint NOT NULL,people text NOT NULL,location text NOT NULL,party text NOT NULL)");
    $seed=new CorrespondencePgStore($connection,static fn($unused)=>null,static fn($title,$body)=>true);$seed->ensureSchema();
    f04Sql($connection,"UPDATE plugins.correspondence_restore_state SET epoch=$1::uuid,pending=FALSE,native_ts_floor=77 WHERE singleton IS TRUE",[F04_E1]);
    f04Require(CorrespondencePgStore::writeRestoreSentinelFile($marker,'clear',F04_E1),'f04_initial_clear_marker_failed');

    // Keep the same-epoch fallback first so the frozen baseline produces a direct
    // persistence assertion RED before any overlap barrier can time out.
    $markerLock=@fopen($lockPath,'c+b');f04Require(is_resource($markerLock)&&flock($markerLock,LOCK_EX|LOCK_NB),'f04_persist_marker_lock_failed');
    $child=f04StartChild('failed_init',$root);[$exit,$out,$err]=f04FinishChild($child);$child=null;
    f04Require($exit===0&&str_contains($out,'PASS failed marker')&&f04ExpectedMarkerLog($err),'f04_persistent_quarantine_child_failed:'.$out.$err);
    flock($markerLock,LOCK_UN);fclose($markerLock);$markerLock=null;
    $row=f04Sql($monitor,'SELECT epoch::text,pending,native_ts_floor::text FROM plugins.correspondence_restore_state WHERE singleton IS TRUE')[0]??[];
    f04Require($row===['epoch'=>F04_E1,'pending'=>'t','native_ts_floor'=>'77']&&f04Marker($marker)===['schema'=>'correspondence.restore.v1','state'=>'clear','epoch'=>F04_E1],'f04_failed_marker_did_not_persist_exact_E1_quarantine');
    echo "PASS failed marker write persists E1 quarantine across init-fence release\n";

    $writerConnection=f04Connect();$writer=f04Store($writerConnection,$marker);$writer->ensureSchema();
    $writer->bindRequestClock(['inputtext','1800000000000000300','42']);
    $writerError=f04ExpectFailure(static fn()=>$writer->transaction(static fn()=>true));
    f04Require($writerError instanceof DomainException,'f04_independent_writer_did_not_reject_pending');
    pg_close($writerConnection);echo "PASS independent writer rejects persisted E1 quarantine\n";

    $ownerConnection=f04Connect();$owner=f04Store($ownerConnection,$marker);$owner->ensureSchema();
    $ownerError=f04ExpectFailure(static fn()=>$owner->completeRestoreEpoch(F04_E1));
    $row=f04Sql($monitor,'SELECT epoch::text,pending,native_ts_floor::text FROM plugins.correspondence_restore_state WHERE singleton IS TRUE')[0]??[];
    f04Require($ownerError instanceof DomainException&&($row['pending']??null)==='t','f04_old_owner_cleared_quarantine');
    pg_close($ownerConnection);

    $txConnection=f04Connect();$GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_CAPTURED']=true;$GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_TOKEN']=F04_E1.':f04-pg-fixture';
    $txStore=f04Store($txConnection,$marker);$txStore->ensureSchema();$txStore->captureRequestRestoreToken();
    unset($GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_CAPTURED'],$GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_TOKEN']);
    f04Sql($txConnection,'BEGIN');$txError=f04ExpectFailure(static fn()=>$txStore->quarantineRequestRestoreEpoch(F04_E1.':f04-pg-fixture',F04_E1));
    f04Require($txError instanceof DomainException&&$txError->getMessage()==='connection_transaction_active'&&pg_transaction_status($txConnection)===PGSQL_TRANSACTION_INTRANS,'f04_inherited_transaction_was_touched');
    f04Sql($txConnection,'ROLLBACK');pg_close($txConnection);echo "PASS inherited transaction remains untouched\n";

    $recoverChild=f04StartChild('recover_init',$root);[$exit,$out,$err]=f04FinishChild($recoverChild);
    f04Require($exit===0&&str_contains($out,'PASS supported init')&&$err==='','f04_supported_recovery_child_failed:'.$out.$err);
    $row=f04Sql($monitor,'SELECT epoch::text,pending,native_ts_floor::text FROM plugins.correspondence_restore_state WHERE singleton IS TRUE')[0]??[];
    f04Require(($row['epoch']??null)!==F04_E1&&($row['pending']??null)==='f'&&f04Marker($marker)===['schema'=>'correspondence.restore.v1','state'=>'clear','epoch'=>$row['epoch']??null],'f04_supported_init_did_not_recover_quarantine');
    echo "PASS later supported init completes and clears quarantine\n";

    // Pause A after request-token capture at initMarkerStart. B then completes E2
    // under the writer lock while A's exact-E1 quarantine CAS waits behind it.
    f04Sql($connection,"UPDATE plugins.correspondence_restore_state SET epoch=$1::uuid,pending=FALSE,native_ts_floor=77 WHERE singleton IS TRUE",[F04_E1]);
    f04Require(CorrespondencePgStore::writeRestoreSentinelFile($marker,'clear',F04_E1),'f04_reset_E1_marker_failed');
    $markerLock=@fopen($lockPath,'c+b');f04Require(is_resource($markerLock)&&flock($markerLock,LOCK_EX|LOCK_NB),'f04_marker_lock_failed');
    f04Sql($connection,'BEGIN');$relationTransaction=true;f04Sql($connection,'LOCK TABLE public.eventlog IN ACCESS EXCLUSIVE MODE');
    $signal=$root.DIRECTORY_SEPARATOR.'race.pid';$child=f04StartChild('failed_init_race',$root,$signal);
    f04WaitFile($signal,'f04_race_child_connection_timeout');$pid=trim((string)file_get_contents($signal));
    f04WaitActivity($monitor,$pid,'relation','COALESCE(MAX(rowid),0)');
    f04Sql($connection,'SELECT pg_advisory_lock(18459814,1)');$advisoryHeld=true;
    f04Sql($connection,'COMMIT');$relationTransaction=false;
    f04WaitActivity($monitor,$pid,'advisory','pg_advisory_xact_lock(18459814, 1)');
    flock($markerLock,LOCK_UN);fclose($markerLock);$markerLock=null;
    f04Require(CorrespondencePgStore::writeRestoreSentinelFile($marker,'pending',F04_E2),'f04_B_pending_marker_failed');
    $bStore=f04Store($connection,$marker);$bStore->ensureSchema();
    $bStore->beginRestoreEpoch(F04_E2,'1800000000000000200');$bStore->completeRestoreEpoch(F04_E2);
    f04Sql($connection,'SELECT pg_advisory_unlock(18459814,1)');$advisoryHeld=false;
    [$exit,$out,$err]=f04FinishChild($child);$child=null;
    f04Require($exit===0&&str_contains($out,'PASS stale A')&&f04ExpectedMarkerLog($err),'f04_A_after_B_expectation_failed:'.$out.$err);
    $row=f04Sql($monitor,'SELECT epoch::text,pending,native_ts_floor::text FROM plugins.correspondence_restore_state WHERE singleton IS TRUE')[0]??[];
    f04Require($row===['epoch'=>F04_E2,'pending'=>'f','native_ts_floor'=>'1800000000000000200']&&f04Marker($marker)===['schema'=>'correspondence.restore.v1','state'=>'clear','epoch'=>F04_E2],'f04_waiting_A_changed_B_authority');
    echo "PASS waiting A exact-epoch CAS preserves completed B\n";
    $newWriterConnection=f04Connect();$newWriter=f04Store($newWriterConnection,$marker);$newWriter->ensureSchema();$newWriter->bindRequestClock(['inputtext','1800000000000000300','42']);
    f04Require($newWriter->transaction(static fn()=>true)===true,'f04_new_B_writer_rejected');pg_close($newWriterConnection);
    echo "PASS independent B writer accepts completed authority\n";
}finally{
    if(is_resource($markerLock)){@flock($markerLock,LOCK_UN);fclose($markerLock);}
    if($relationTransaction)try{f04Sql($connection,'ROLLBACK');}catch(Throwable){}
    if($advisoryHeld)try{f04Sql($connection,'SELECT pg_advisory_unlock(18459814,1)');}catch(Throwable){}
    if($child!==null){try{f04FinishChild($child);}catch(Throwable){}}
    try{f04Sql($connection,'DROP SCHEMA IF EXISTS plugins CASCADE');}catch(Throwable){}
    try{f04Sql($connection,'DROP TABLE IF EXISTS public.eventlog CASCADE');}catch(Throwable){}
    foreach(glob($runtimeDir.DIRECTORY_SEPARATOR.'*')?:[]as$file)if(is_file($file))@unlink($file);
    @rmdir($runtimeDir);@rmdir(dirname($runtimeDir));@rmdir($root);
    pg_close($monitor);pg_close($connection);
}
