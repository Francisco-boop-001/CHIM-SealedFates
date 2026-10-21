<?php
declare(strict_types=1);
require_once __DIR__.'/support/recovery_pg_fixture.php';
require_once dirname(__DIR__).'/server/lib/bootstrap.php';

function restoreOwnershipPgRequire(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function restoreOwnershipPgSql(PgSql\Connection $connection,string $sql,array $args=[]):array
{
    $result=@pg_query_params($connection,$sql,$args);if($result===false)throw new RuntimeException('restore_ownership_fixture_sql_failed:'.$sql);
    try{return pg_num_fields($result)>0?(pg_fetch_all($result)?:[]):[];}finally{pg_free_result($result);}
}
function restoreOwnershipPgMarker(string $path):?array
{
    if(!is_file($path))return null;$marker=json_decode((string)file_get_contents($path),true,8,JSON_THROW_ON_ERROR);
    return is_array($marker)?['schema'=>$marker['schema']??null,'state'=>$marker['state']??null,'epoch'=>$marker['epoch']??null]:null;
}
function restoreOwnershipPgStore(PgSql\Connection $connection,string $sentinel,bool $ensureSchema=true):CorrespondencePgStore
{
    $store=new CorrespondencePgStore($connection,static fn($unused)=>null,static fn($title,$body)=>true,$sentinel);if($ensureSchema)$store->ensureSchema();return $store;
}
function restoreOwnershipPgChild(string $mode,string $sentinel,string $signal,string $ready='',string $go=''):int
{
    $connection=correspondenceRecoveryFixture('chim_corr_recovery_store');
    $GLOBALS['ptr_runtime_generation']='pg-fixture';
    try{
        if($mode==='schema_seed_a_wait'){
            restoreOwnershipPgRequire($ready!==''&&$go!=='','schema_seed_barrier_paths_required');
            $store=new CorrespondencePgStore($connection,static fn($unused)=>null,static fn($title,$body)=>true,$sentinel);
            file_put_contents($ready,(string)pg_fetch_result(pg_query($connection,'SELECT pg_backend_pid()'),0,0),LOCK_EX);
            $deadline=microtime(true)+10.0;while(!is_file($go)&&microtime(true)<$deadline)usleep(10000);
            restoreOwnershipPgRequire(is_file($go),'schema_seed_parent_go_timeout');
            file_put_contents($signal,(string)pg_fetch_result(pg_query($connection,'SELECT pg_backend_pid()'),0,0),LOCK_EX);
            $store->ensureSchema();
            $row=restoreOwnershipPgSql($connection,'SELECT epoch::text,pending FROM plugins.correspondence_restore_state WHERE singleton IS TRUE')[0]??[];
            $generation=$store->restoreGeneration();
            if(($row['epoch']??null)==='bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'&&($row['pending']??null)==='f'&&restoreOwnershipPgMarker($sentinel)===['schema'=>'correspondence.restore.v1','state'=>'clear','epoch'=>'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb']&&$generation==='bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb:pg-fixture'){echo "PASS delayed schema seed preserved B completed authority\n";return 0;}
            echo "FAIL delayed schema seed changed B completed authority\n";return 2;
        }
        $store=restoreOwnershipPgStore($connection,$sentinel);
        if($mode==='begin_b_timeout'){
            restoreOwnershipPgRequire($ready!==''&&$go!=='','child_B_barrier_paths_required');
            file_put_contents($ready,(string)pg_fetch_result(pg_query($connection,'SELECT pg_backend_pid()'),0,0),LOCK_EX);
            $deadline=microtime(true)+10.0;while(!is_file($go)&&microtime(true)<$deadline)usleep(10000);
            restoreOwnershipPgRequire(is_file($go),'child_B_parent_go_timeout');
            restoreOwnershipPgRequire(CorrespondencePgStore::writeRestoreSentinelFile($sentinel,'pending','22222222-2222-4222-8222-222222222222'),'child_B_marker_write_failed');
            file_put_contents($signal,(string)pg_fetch_result(pg_query($connection,'SELECT pg_backend_pid()'),0,0),LOCK_EX);
            try{$store->beginRestoreEpoch('22222222-2222-4222-8222-222222222222','20');}
            catch(RuntimeException $error){
                if($error->getMessage()==='storage_failed'&&restoreOwnershipPgMarker($sentinel)===['schema'=>'correspondence.restore.v1','state'=>'pending','epoch'=>'22222222-2222-4222-8222-222222222222']){echo "PASS child B timed out with its pending marker intact\n";return 0;}
                throw $error;
            }
            echo "FAIL child B should time out behind A's session lock\n";return 2;
        }
        if($mode==='complete_a_postdb'){
            file_put_contents($signal,(string)pg_fetch_result(pg_query($connection,'SELECT pg_backend_pid()'),0,0),LOCK_EX);
            try{$store->completeRestoreEpoch('11111111-1111-4111-8111-111111111111');}
            catch(DomainException $error){
                $row=restoreOwnershipPgSql($connection,'SELECT epoch::text,pending FROM plugins.correspondence_restore_state WHERE singleton IS TRUE')[0]??[];
                if($error->getMessage()==='restore_epoch_changed'&&($row['epoch']??null)==='11111111-1111-4111-8111-111111111111'&&($row['pending']??null)==='f'&&restoreOwnershipPgMarker($sentinel)===['schema'=>'correspondence.restore.v1','state'=>'pending','epoch'=>'22222222-2222-4222-8222-222222222222']&&$store->restoreGeneration()===null){echo "PASS A database clear could not clear B marker; authority remains quarantined\n";return 0;}
                throw $error;
            }
            echo "FAIL post-database CAS accepted A after B published\n";return 2;
        }
        if($mode==='delay_begin_a'){
            try{$store->beginRestoreEpoch('11111111-1111-4111-8111-111111111111','10');}
            catch(DomainException $error){
                $row=restoreOwnershipPgSql($connection,'SELECT epoch::text,pending FROM plugins.correspondence_restore_state WHERE singleton IS TRUE')[0]??[];
                if($error->getMessage()==='restore_epoch_changed'&&($row['epoch']??null)==='11111111-1111-4111-8111-111111111111'&&($row['pending']??null)==='t'&&restoreOwnershipPgMarker($sentinel)===['schema'=>'correspondence.restore.v1','state'=>'pending','epoch'=>'22222222-2222-4222-8222-222222222222']){echo "PASS delayed A begin left newer B marker and database untouched\n";return 0;}
                throw $error;
            }
            echo "FAIL delayed A begin overwrote newer B authority\n";return 2;
        }
        if($mode==='begin_a_blocked_before_B'){
            restoreOwnershipPgRequire($ready!==''&&$go!=='','child_A_barrier_paths_required');
            file_put_contents($ready,(string)pg_fetch_result(pg_query($connection,'SELECT pg_backend_pid()'),0,0),LOCK_EX);
            $deadline=microtime(true)+10.0;while(!is_file($go)&&microtime(true)<$deadline)usleep(10000);
            restoreOwnershipPgRequire(is_file($go),'child_A_parent_go_timeout');
            file_put_contents($signal,(string)pg_fetch_result(pg_query($connection,'SELECT pg_backend_pid()'),0,0),LOCK_EX);
            try{$store->beginRestoreEpoch('11111111-1111-4111-8111-111111111111','10');}
            catch(DomainException $error){
                $row=restoreOwnershipPgSql($connection,'SELECT epoch::text,pending FROM plugins.correspondence_restore_state WHERE singleton IS TRUE')[0]??[];
                $generation=$store->restoreGeneration();
                if($error->getMessage()==='restore_epoch_changed'&&($row['epoch']??null)==='22222222-2222-4222-8222-222222222222'&&($row['pending']??null)==='f'&&restoreOwnershipPgMarker($sentinel)===['schema'=>'correspondence.restore.v1','state'=>'clear','epoch'=>'22222222-2222-4222-8222-222222222222']&&$generation==='22222222-2222-4222-8222-222222222222:pg-fixture'){echo "PASS blocked A begin preserved B completed authority\n";return 0;}
                throw $error;
            }
            echo "FAIL blocked A begin overwrote B completed authority\n";return 2;
        }
        throw new InvalidArgumentException('unknown_restore_ownership_child_mode');
    }finally{pg_close($connection);}
}
function restoreOwnershipPgChildCommand(array $arguments):array
{
    $command=[PHP_BINARY,'-n','-d','extension_dir='.ini_get('extension_dir')];
    foreach(['pgsql','mbstring','ctype','tokenizer']as$extension){
        if(!extension_loaded($extension))throw new RuntimeException('child_php_extension_missing:'.$extension);
        $command[]='-d';$command[]='extension='.$extension;
    }
    return [...$command,__FILE__,...$arguments];
}
function restoreOwnershipPgStartChild(string $mode,string $sentinel,string $signal,string $ready='',string $go=''):array
{
    $process=proc_open(restoreOwnershipPgChildCommand([$mode,$sentinel,$signal,$ready,$go]),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('restore_ownership_child_start_failed');fclose($pipes[0]);return [$process,$pipes];
}
function restoreOwnershipPgFinishChild(array $child):array
{
    [$process,$pipes]=$child;$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);return [$exit,$stdout,$stderr];
}
function restoreOwnershipPgWaitForAdvisoryWait(PgSql\Connection $connection,string $signal):void
{
    $deadline=microtime(true)+4.0;
    while(microtime(true)<$deadline){
        if(is_file($signal)){$pid=trim((string)file_get_contents($signal));
            $row=restoreOwnershipPgSql($connection,"SELECT wait_event_type,wait_event FROM pg_stat_activity WHERE pid=$1::integer",[$pid])[0]??[];
            if(($row['wait_event_type']??null)==='Lock'&&($row['wait_event']??null)==='advisory')return;
        }
        usleep(50000);
    }
    throw new RuntimeException('child_B_never_waited_on_A_advisory_lock');
}
function restoreOwnershipPgWaitForFile(string $path,string $failure):void
{
    $deadline=microtime(true)+10.0;while(!is_file($path)&&microtime(true)<$deadline)usleep(10000);
    if(!is_file($path))throw new RuntimeException($failure);
}
function restoreOwnershipPgWaitForActiveClear(PgSql\Connection $connection,string $signal):void
{
    $deadline=microtime(true)+8.0;
    while(microtime(true)<$deadline){
        if(is_file($signal)){$pid=trim((string)file_get_contents($signal));
            $row=restoreOwnershipPgSql($connection,"SELECT state,query,wait_event_type,wait_event FROM pg_stat_activity WHERE pid=$1::integer",[$pid])[0]??[];
            if(($row['state']??null)==='active'&&str_contains((string)($row['query']??''),'UPDATE plugins.correspondence_restore_state SET pending=FALSE')&&($row['wait_event']??null)==='PgSleep')return;
        }
        usleep(50000);
    }
    throw new RuntimeException('A_clear_update_did_not_reach_post_db_trigger');
}

$mode=$argv[1]??'run';
if($mode!=='run'){
    $sentinel=$argv[2]??'';$signal=$argv[3]??'';
    if($sentinel===''||$signal==='')throw new InvalidArgumentException('restore_ownership_child_args_required');
    exit(restoreOwnershipPgChild($mode,$sentinel,$signal,$argv[4]??'',$argv[5]??''));
}

$connection=correspondenceRecoveryFixture('chim_corr_recovery_store');
$GLOBALS['ptr_runtime_generation']='pg-fixture';
$sentinelDir=sys_get_temp_dir().DIRECTORY_SEPARATOR.'correspondence-restore-ownership-pg-'.bin2hex(random_bytes(8));
restoreOwnershipPgRequire(@mkdir($sentinelDir,0700,true),'restore_ownership_tempdir_failed');
$sentinel=$sentinelDir.DIRECTORY_SEPARATOR.'correspondence.restore.json';
try{
    restoreOwnershipPgRequire(restoreOwnershipPgSql($connection,"SELECT table_name FROM information_schema.tables WHERE table_schema IN ('public','plugins','chim_meta')")===[],'fresh_fixture_required');
    restoreOwnershipPgSql($connection,"CREATE TABLE public.eventlog(rowid bigserial PRIMARY KEY,ts bigint NOT NULL,gamets numeric NOT NULL,type text NOT NULL,data text NOT NULL,sess text NOT NULL,localts bigint NOT NULL,people text NOT NULL,location text NOT NULL,party text NOT NULL)");
    restoreOwnershipPgRequire(CorrespondencePgStore::writeRestoreSentinelFile($sentinel,'pending','11111111-1111-4111-8111-111111111111'),'initial_A_marker_write_failed');
    $store=restoreOwnershipPgStore($connection,$sentinel);

    restoreOwnershipPgSql($connection,"UPDATE plugins.correspondence_restore_state SET epoch='11111111-1111-4111-8111-111111111111'::uuid,pending=TRUE,native_ts_floor=0 WHERE singleton IS TRUE");
    restoreOwnershipPgRequire(CorrespondencePgStore::writeRestoreSentinelFile($sentinel,'pending','11111111-1111-4111-8111-111111111111'),'A_pending_marker_restore_failed');

    // Hold the database lock as A while a second PHP process publishes B and times
    // out trying to begin. A's later completion attempt must not erase B's intent.
    $bSignal=$sentinelDir.DIRECTORY_SEPARATOR.'begin-b.pid';$bReady=$sentinelDir.DIRECTORY_SEPARATOR.'begin-b.ready';$bGo=$sentinelDir.DIRECTORY_SEPARATOR.'begin-b.go';
    $bChild=restoreOwnershipPgStartChild('begin_b_timeout',$sentinel,$bSignal,$bReady,$bGo);
    $lockHeld=false;
    try{
        restoreOwnershipPgWaitForFile($bReady,'child_B_schema_readiness_timeout');
        restoreOwnershipPgSql($connection,'SELECT pg_advisory_lock(18459814,1)');$lockHeld=true;
        file_put_contents($bGo,'go',LOCK_EX);
        restoreOwnershipPgWaitForAdvisoryWait($connection,$bSignal);
        [$bExit,$bOut,$bErr]=restoreOwnershipPgFinishChild($bChild);$bChild=null;
        restoreOwnershipPgRequire($bExit===0&&str_contains($bOut,'PASS child B timed out')&&$bErr==='','B_timeout_expectation_failed:'.$bOut.$bErr);
        restoreOwnershipPgRequire(restoreOwnershipPgMarker($sentinel)===['schema'=>'correspondence.restore.v1','state'=>'pending','epoch'=>'22222222-2222-4222-8222-222222222222'],'B_timeout_must_leave_newer_marker_pending');
    }finally{
        if($bChild!==null){try{restoreOwnershipPgFinishChild($bChild);}catch(Throwable){}}
        if($lockHeld)restoreOwnershipPgSql($connection,'SELECT pg_advisory_unlock(18459814,1)');
    }
    $completionRejected=false;try{$store->completeRestoreEpoch('11111111-1111-4111-8111-111111111111');}catch(DomainException $error){$completionRejected=$error->getMessage()==='restore_epoch_changed';}
    $row=restoreOwnershipPgSql($connection,'SELECT epoch::text,pending FROM plugins.correspondence_restore_state WHERE singleton IS TRUE')[0]??[];
    restoreOwnershipPgRequire($completionRejected&&($row['pending']??null)==='t'&&restoreOwnershipPgMarker($sentinel)['epoch']==='22222222-2222-4222-8222-222222222222','A_completion_must_not_clear_B_after_B_times_out');
    echo "PASS two-process B timeout: A completion cannot clear B marker\n";

    // Let A pass its marker check and enter the database update before B publishes.
    // A test trigger pauses the update so the parent can replace the marker at that seam.
    restoreOwnershipPgSql($connection,'SELECT pg_advisory_lock(18459814,1)');
    restoreOwnershipPgSql($connection,"UPDATE plugins.correspondence_restore_state SET epoch='11111111-1111-4111-8111-111111111111'::uuid,pending=TRUE,native_ts_floor=0 WHERE singleton IS TRUE");
    restoreOwnershipPgSql($connection,'SELECT pg_advisory_unlock(18459814,1)');
    restoreOwnershipPgRequire(CorrespondencePgStore::writeRestoreSentinelFile($sentinel,'pending','11111111-1111-4111-8111-111111111111'),'reset_A_marker_write_failed');
    restoreOwnershipPgSql($connection,"CREATE FUNCTION public.restore_ownership_pause_clear() RETURNS trigger LANGUAGE plpgsql AS \$body\$ BEGIN IF OLD.pending IS TRUE AND NEW.pending IS FALSE THEN PERFORM pg_sleep(4); END IF; RETURN NEW; END \$body\$");
    restoreOwnershipPgSql($connection,'CREATE TRIGGER restore_ownership_pause_clear BEFORE UPDATE ON plugins.correspondence_restore_state FOR EACH ROW EXECUTE FUNCTION public.restore_ownership_pause_clear()');
    $aSignal=$sentinelDir.DIRECTORY_SEPARATOR.'complete-a.pid';$aChild=restoreOwnershipPgStartChild('complete_a_postdb',$sentinel,$aSignal);
    try{
        restoreOwnershipPgWaitForActiveClear($connection,$aSignal);
        // The trigger is reached only after A passed its exact pending-marker check.
        usleep(100000);
        restoreOwnershipPgRequire(CorrespondencePgStore::writeRestoreSentinelFile($sentinel,'pending','22222222-2222-4222-8222-222222222222'),'racing_B_marker_write_failed');
        [$aExit,$aOut,$aErr]=restoreOwnershipPgFinishChild($aChild);$aChild=null;
        restoreOwnershipPgRequire($aExit===0&&str_contains($aOut,'PASS A database clear could not clear B marker')&&$aErr==='','post_db_CAS_expectation_failed:'.$aOut.$aErr);
    }finally{if($aChild!==null){try{restoreOwnershipPgFinishChild($aChild);}catch(Throwable){}}}
    restoreOwnershipPgSql($connection,'DROP TRIGGER restore_ownership_pause_clear ON plugins.correspondence_restore_state');
    restoreOwnershipPgSql($connection,'DROP FUNCTION public.restore_ownership_pause_clear()');
    echo "PASS PostgreSQL post-update CAS: A clear leaves B pending and authority quarantined\n";

    // A delayed old initializer must reject B before it touches the database.
    restoreOwnershipPgSql($connection,"UPDATE plugins.correspondence_restore_state SET epoch='11111111-1111-4111-8111-111111111111'::uuid,pending=TRUE,native_ts_floor=0 WHERE singleton IS TRUE");
    $delaySignal=$sentinelDir.DIRECTORY_SEPARATOR.'delay-a.pid';$delayChild=restoreOwnershipPgStartChild('delay_begin_a',$sentinel,$delaySignal);
    [$delayExit,$delayOut,$delayErr]=restoreOwnershipPgFinishChild($delayChild);
    restoreOwnershipPgRequire($delayExit===0&&str_contains($delayOut,'PASS delayed A begin left newer B marker')&&$delayErr==='','delayed_A_begin_expectation_failed:'.$delayOut.$delayErr);
    $row=restoreOwnershipPgSql($connection,'SELECT epoch::text,pending FROM plugins.correspondence_restore_state WHERE singleton IS TRUE')[0]??[];
    restoreOwnershipPgRequire(($row['epoch']??null)==='11111111-1111-4111-8111-111111111111'&&($row['pending']??null)==='t'&&$store->restoreGeneration()===null,'delayed_A_begin_must_leave_mail_quarantined');
    echo "PASS delayed A begin: B marker survives and plugin authority stays pending\n";

    // A passed its marker check and waits on PostgreSQL. Model B winning and fully
    // clearing its own epoch before A acquires that lock; A must recheck after lock.
    restoreOwnershipPgSql($connection,"UPDATE plugins.correspondence_restore_state SET epoch='11111111-1111-4111-8111-111111111111'::uuid,pending=TRUE,native_ts_floor=0 WHERE singleton IS TRUE");
    restoreOwnershipPgRequire(CorrespondencePgStore::writeRestoreSentinelFile($sentinel,'pending','11111111-1111-4111-8111-111111111111'),'blocked_A_marker_write_failed');
    $blockedSignal=$sentinelDir.DIRECTORY_SEPARATOR.'blocked-a.pid';$blockedReady=$sentinelDir.DIRECTORY_SEPARATOR.'blocked-a.ready';$blockedGo=$sentinelDir.DIRECTORY_SEPARATOR.'blocked-a.go';
    $blockedChild=restoreOwnershipPgStartChild('begin_a_blocked_before_B',$sentinel,$blockedSignal,$blockedReady,$blockedGo);
    $lockHeld=false;
    try{
        restoreOwnershipPgWaitForFile($blockedReady,'blocked_A_schema_readiness_timeout');
        restoreOwnershipPgSql($connection,'SELECT pg_advisory_lock(18459814,1)');$lockHeld=true;
        file_put_contents($blockedGo,'go',LOCK_EX);
        restoreOwnershipPgWaitForAdvisoryWait($connection,$blockedSignal);
        restoreOwnershipPgRequire(CorrespondencePgStore::writeRestoreSentinelFile($sentinel,'pending','22222222-2222-4222-8222-222222222222'),'B_pending_before_A_unlock_failed');
        restoreOwnershipPgSql($connection,"UPDATE plugins.correspondence_restore_state SET epoch='22222222-2222-4222-8222-222222222222'::uuid,pending=FALSE,native_ts_floor=0 WHERE singleton IS TRUE");
        restoreOwnershipPgRequire(CorrespondencePgStore::writeRestoreSentinelFile($sentinel,'clear','22222222-2222-4222-8222-222222222222'),'B_clear_before_A_unlock_failed');
        restoreOwnershipPgSql($connection,'SELECT pg_advisory_unlock(18459814,1)');$lockHeld=false;
        [$blockedExit,$blockedOut,$blockedErr]=restoreOwnershipPgFinishChild($blockedChild);$blockedChild=null;
        restoreOwnershipPgRequire($blockedExit===0&&str_contains($blockedOut,'PASS blocked A begin preserved B completed authority')&&$blockedErr==='','blocked_A_post_lock_recheck_failed:'.$blockedOut.$blockedErr);
    }finally{
        if($blockedChild!==null){try{restoreOwnershipPgFinishChild($blockedChild);}catch(Throwable){}}
        if($lockHeld)restoreOwnershipPgSql($connection,'SELECT pg_advisory_unlock(18459814,1)');
    }
    echo "PASS blocked A begin: B can complete before A's PG lock, and A cannot restore stale authority\n";

    // A observes the missing restore row before its writer transaction, then B
    // creates and completes a row while A waits. A must preserve B on lock entry.
    restoreOwnershipPgSql($connection,'DELETE FROM plugins.correspondence_restore_state WHERE singleton IS TRUE');
    restoreOwnershipPgRequire(CorrespondencePgStore::writeRestoreSentinelFile($sentinel,'clear','aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'),'schema_seed_A_clear_marker_failed');
    $seedSignal=$sentinelDir.DIRECTORY_SEPARATOR.'schema-seed-a.pid';$seedReady=$sentinelDir.DIRECTORY_SEPARATOR.'schema-seed-a.ready';$seedGo=$sentinelDir.DIRECTORY_SEPARATOR.'schema-seed-a.go';
    $seedChild=restoreOwnershipPgStartChild('schema_seed_a_wait',$sentinel,$seedSignal,$seedReady,$seedGo);
    $lockHeld=false;
    try{
        restoreOwnershipPgWaitForFile($seedReady,'schema_seed_A_readiness_timeout');
        restoreOwnershipPgSql($connection,'SELECT pg_advisory_lock(18459814,1)');$lockHeld=true;
        file_put_contents($seedGo,'go',LOCK_EX);
        restoreOwnershipPgWaitForAdvisoryWait($connection,$seedSignal);
        restoreOwnershipPgRequire(CorrespondencePgStore::writeRestoreSentinelFile($sentinel,'pending','bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'),'schema_seed_B_pending_marker_failed');
        restoreOwnershipPgSql($connection,"INSERT INTO plugins.correspondence_restore_state(singleton,epoch,pending,native_ts_floor) VALUES(TRUE,'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'::uuid,FALSE,0)");
        restoreOwnershipPgRequire(CorrespondencePgStore::writeRestoreSentinelFile($sentinel,'clear','bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'),'schema_seed_B_clear_marker_failed');
        restoreOwnershipPgSql($connection,'SELECT pg_advisory_unlock(18459814,1)');$lockHeld=false;
        [$seedExit,$seedOut,$seedErr]=restoreOwnershipPgFinishChild($seedChild);$seedChild=null;
        restoreOwnershipPgRequire($seedExit===0&&str_contains($seedOut,'PASS delayed schema seed preserved B completed authority')&&$seedErr==='','delayed_schema_seed_preservation_failed:'.$seedOut.$seedErr);
    }finally{
        if($seedChild!==null){try{restoreOwnershipPgFinishChild($seedChild);}catch(Throwable){}}
        if($lockHeld)restoreOwnershipPgSql($connection,'SELECT pg_advisory_unlock(18459814,1)');
    }
    echo "PASS delayed schema seed: B completion survives A's waiting ensureSchema\n";

    // Keep the independent JSONB close invariant after F1 races so the first RED
    // on the baseline remains the restore ownership failure.
    $documentStore=new CorrespondencePgStore($connection,static fn($unused)=>null,static fn($title,$body)=>true);$documentStore->ensureSchema();
    $documentStore->bindRequestClock(['inputtext','1800000000000000200','100']);
    $document=['document_id'=>'33333333-3333-4333-8333-333333333333','playthrough_id'=>'restore-owner-fixture','request_id'=>'44444444-4444-4444-8444-444444444444','operation_id'=>'55555555-5555-4555-8555-555555555555','body'=>'sealed body'];
    $documentStore->transaction(fn()=>$documentStore->saveDocument($document));
    $closedDocument=array_replace($document,['operator_close'=>['event_rowid'=>1,'event_type'=>'correspondence_dispatch','operation_id'=>$document['operation_id']]]);
    $documentStore->transaction(fn()=>$documentStore->saveDocument($closedDocument));
    foreach([
        array_diff_key($closedDocument,['operator_close'=>true]),
        array_replace($closedDocument,['operator_close'=>['event_rowid'=>2,'event_type'=>'correspondence_dispatch','operation_id'=>$document['operation_id']]]),
        array_replace($closedDocument,['operation_id'=>'66666666-6666-4666-8666-666666666666']),
        array_replace($closedDocument,['body'=>'changed body']),
    ]as$mutation){
        $conflict=false;try{$documentStore->transaction(fn()=>$documentStore->saveDocument($mutation));}catch(DomainException $error){$conflict=$error->getMessage()==='immutable_document_conflict';}
        restoreOwnershipPgRequire($conflict,'closed_document_mutation_must_be_rejected');
    }
    $queuedDocument=array_replace($document,['document_id'=>'77777777-7777-4777-8777-777777777777','request_id'=>'88888888-8888-4888-8888-888888888888','operation_id'=>null,'body'=>'queued sealed body']);
    $documentStore->transaction(fn()=>$documentStore->saveDocument($queuedDocument));
    $queuedClosed=array_replace($queuedDocument,['operator_close'=>['event_rowid'=>3,'event_type'=>'correspondence_operator_closed','operation_id'=>null]]);
    $documentStore->transaction(fn()=>$documentStore->saveDocument($queuedClosed));
    foreach([
        array_diff_key($queuedClosed,['operator_close'=>true]),
        array_replace($queuedClosed,['operation_id'=>'99999999-9999-4999-8999-999999999999']),
        array_replace($queuedClosed,['operator_close'=>['event_rowid'=>4,'event_type'=>'correspondence_operator_closed','operation_id'=>null]]),
    ]as$mutation){
        $conflict=false;try{$documentStore->transaction(fn()=>$documentStore->saveDocument($mutation));}catch(DomainException $error){$conflict=$error->getMessage()==='immutable_document_conflict';}
        restoreOwnershipPgRequire($conflict,'queued_closed_document_mutation_must_be_rejected');
    }
    echo "PASS real JSONB close monotonicity: queued close accepts null operation_id and cannot be removed, reassigned, or rewritten\n";
}finally{
    try{restoreOwnershipPgSql($connection,'DROP TRIGGER IF EXISTS restore_ownership_pause_clear ON plugins.correspondence_restore_state');}catch(Throwable){}
    try{restoreOwnershipPgSql($connection,'DROP FUNCTION IF EXISTS public.restore_ownership_pause_clear()');}catch(Throwable){}
    try{restoreOwnershipPgSql($connection,'DROP SCHEMA IF EXISTS plugins CASCADE');}catch(Throwable){}
    try{restoreOwnershipPgSql($connection,'DROP TABLE IF EXISTS public.eventlog CASCADE');}catch(Throwable){}
    foreach(glob($sentinelDir.DIRECTORY_SEPARATOR.'*')?:[]as$file)if(is_file($file))@unlink($file);@rmdir($sentinelDir);pg_close($connection);
}
