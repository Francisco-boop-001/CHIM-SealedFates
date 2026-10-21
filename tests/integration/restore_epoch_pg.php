<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/support/recovery_pg_fixture.php';
require_once dirname(__DIR__,2).'/server/lib/bootstrap.php';

function restoreEpochRequire(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$connection=correspondenceRecoveryFixture('chim_corr_recovery_store');
function restoreEpochSql(string $sql,array $args=[]):array{
    global $connection;$r=pg_query_params($connection,$sql,$args);if($r===false)throw new RuntimeException('restore_epoch_fixture_sql_failed');
    try{return pg_num_fields($r)>0?(pg_fetch_all($r)?:[]):[];}finally{pg_free_result($r);}
}
restoreEpochRequire(restoreEpochSql("SELECT table_name FROM information_schema.tables WHERE table_schema IN ('public','plugins','chim_meta')")===[],'fresh_fixture_required');
restoreEpochSql("CREATE TABLE public.eventlog(rowid bigserial PRIMARY KEY,ts bigint NOT NULL,gamets numeric NOT NULL,type text NOT NULL,data text NOT NULL,sess text NOT NULL,localts bigint NOT NULL,people text NOT NULL,location text NOT NULL,party text NOT NULL)");
$store=new CorrespondencePgStore($connection,fn($s)=>null,fn($title,$body)=>true);
$store->bindRequestClock(['inputtext','1800000000000000001','100']);
$store->ensureSchema();
restoreEpochRequire(is_string($store->requestRestoreToken()),'bind_before_ensure_must_capture_after_schema');
$uninitializedStore=new CorrespondencePgStore($connection,fn($s)=>null,fn($title,$body)=>true);
$uninitializedStore->bindRequestClock(['inputtext','1800000000000000001','100']);
$uninitializedReadRejected=false;
try{$uninitializedStore->readTransaction(static fn()=>true);}catch(DomainException $e){$uninitializedReadRejected=$e->getMessage()==='restore_epoch_state_uninitialized';}
restoreEpochRequire($uninitializedReadRejected,'external_constructor_only_store_must_refuse_reads');
$uninitializedWriteRejected=false;
try{$uninitializedStore->transaction(static fn()=>true);}catch(DomainException $e){$uninitializedWriteRejected=$e->getMessage()==='restore_epoch_state_uninitialized';}
restoreEpochRequire($uninitializedWriteRejected,'external_constructor_only_store_must_refuse_writes');
$GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_CAPTURED']=true;
$GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_TOKEN']=null;
try{
    $uninitializedStore->ensureSchema();
    $missingTokenRejected=false;
    try{$uninitializedStore->readTransaction(static fn()=>true);}catch(DomainException $e){$missingTokenRejected=$e->getMessage()==='restore_token_unavailable';}
    restoreEpochRequire($missingTokenRejected,'captured_null_ingress_token_must_refuse_db_only_reads');
    $missingTokenRejected=false;
    try{$uninitializedStore->transaction(static fn()=>true);}catch(DomainException $e){$missingTokenRejected=$e->getMessage()==='restore_token_unavailable';}
    restoreEpochRequire($missingTokenRejected,'captured_null_ingress_token_must_refuse_db_only_writes');
}finally{unset($GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_CAPTURED'],$GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_TOKEN']);}
function restoreEpochEvent(string $ts,string $gamets='100',string $type='inputtext',string $sess='pending'):int{
    $row=restoreEpochSql("INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES($1,$2,$3,'synthetic event',$4,100,'','','') RETURNING rowid",[$ts,$gamets,$type,$sess])[0]??null;
    if(!$row)throw new RuntimeException('restore_epoch_event_insert_failed');return (int)$row['rowid'];
}

// A native request can still be completing when ordinary game events advance.
restoreEpochEvent('1800000000000000000');
$store->bindRequestClock(['inputtext','1800000000000000001','100']);
restoreEpochEvent('1800000000000000002');
$completed=false;
try{$store->transaction(static fn()=>true);$completed=true;}catch(Throwable){}
restoreEpochRequire($completed,'concurrent_event_must_not_reject_valid_completion');

// A malformed/outlier native clock must not poison future honest requests.
restoreEpochEvent('9000000000000000000');
$store->bindRequestClock(['inputtext','1800000000000000003','100']);
$read=false;
try{$read=$store->readTransaction(static fn()=>true);}catch(Throwable){}
restoreEpochRequire($read,'future_outlier_must_not_disable_fresh_request');

// A restore rotates plugin authority even when old event anchors and game time survive.
$anchor=restoreEpochEvent('1800000000000000003','100','info','correspondence_v1');
$store->bindRequestClock(['inputtext','1800000000000000004','100']);
$before=$store->restoreGeneration();$requestToken=$store->requestRestoreToken();
restoreEpochRequire(is_string($before)&&$before!==''&&$requestToken===$before,'ready_restore_token_required');
$epoch=$store->beginRestoreEpoch();
restoreEpochRequire($store->restoreGeneration()===null,'pending_restore_must_have_no_current_generation');
$blocked=false;try{$store->readTransaction(static fn()=>true);}catch(DomainException $e){$blocked=$e->getMessage()==='restore_epoch_pending';}
restoreEpochRequire($blocked,'pending_epoch_must_block_plugin_reads');
$store->completeRestoreEpoch($epoch);
$after=$store->restoreGeneration();
restoreEpochRequire(is_string($after)&&$after!==$before,'completed_init_must_rotate_generation_at_same_game_clock');
restoreEpochRequire((int)(restoreEpochSql('SELECT count(*) AS n FROM public.eventlog WHERE rowid=$1 AND gamets=100',[(string)$anchor])[0]['n']??0)===1,'old_anchor_fixture_must_survive_restore_epoch_rotation');
$oldTokenRejected=false;try{$store->assertRestoreToken($requestToken);}catch(DomainException $e){$oldTokenRejected=$e->getMessage()==='restore_epoch_changed';}
restoreEpochRequire($oldTokenRejected,'pre_restore_request_token_must_not_be_adopted_after_completion');
$store->bindRequestClock(['inputtext','1800000000000000003','100']);
$oldNativeClockRejected=false;try{$store->transaction(static fn()=>true);}catch(DomainException $e){$oldNativeClockRejected=$e->getMessage()==='request_before_restore_epoch';}
restoreEpochRequire($oldNativeClockRejected,'pre_init_native_packet_must_be_rejected_after_restore');
$store->bindRequestClock(['inputtext','1800000000000000005','100']);
$store->transaction(static fn()=>true);

// A clear sentinel can survive a database restore that removes the plugin row.
$sentinelDir=sys_get_temp_dir().DIRECTORY_SEPARATOR.'correspondence-restore-epoch-'.bin2hex(random_bytes(8));
restoreEpochRequire(@mkdir($sentinelDir,0700,true),'restore_epoch_tempdir_failed');
$sentinelPath=$sentinelDir.DIRECTORY_SEPARATOR.'correspondence.restore.json';
try{
    $sentinelEpoch='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    restoreEpochRequire(CorrespondencePgStore::writeRestoreSentinelFile($sentinelPath,'clear',$sentinelEpoch),'clear_restore_sentinel_fixture_failed');
    restoreEpochSql('DELETE FROM plugins.correspondence_restore_state');
    $restoredStore=new CorrespondencePgStore($connection,fn($s)=>null,fn($title,$body)=>true,$sentinelPath);
    $restoredStore->ensureSchema();
    $restoredState=restoreEpochSql('SELECT epoch::text,pending FROM plugins.correspondence_restore_state WHERE singleton IS TRUE')[0]??[];
    restoreEpochRequire(($restoredState['epoch']??null)===$sentinelEpoch&&($restoredState['pending']??null)==='t','missing_db_row_with_old_clear_sentinel_must_reconstruct_pending_epoch');
    restoreEpochRequire($restoredStore->restoreGeneration()===null,'missing_db_row_with_old_clear_sentinel_must_not_restore_authority');
    $sentinel=json_decode((string)@file_get_contents($sentinelPath),true,8,JSON_THROW_ON_ERROR);
    restoreEpochRequire(($sentinel['state']??null)==='pending','old_clear_sentinel_must_be_rewritten_pending');
}finally{
    if(is_file($sentinelPath))@unlink($sentinelPath);
    @rmdir($sentinelDir);
}

echo "PASS restore epoch: advancing/outlier native events, pending fail-closed state, same-clock init rotation, and restored-away DB row recovery\n";
pg_close($connection);
