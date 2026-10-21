<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/support/recovery_pg_fixture.php';
require_once dirname(__DIR__,2).'/server/lib/bootstrap.php';
function initClockRequire(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$connection=correspondenceRecoveryFixture('chim_corr_recovery_store');
$peer=correspondenceRecoveryFixture('chim_corr_recovery_store');
function initClockSql(string $sql,array $args=[]):array{
    global $connection;$r=pg_query_params($connection,$sql,$args);if($r===false)throw new RuntimeException('init_clock_fixture_sql_failed');
    try{return pg_num_fields($r)>0?(pg_fetch_all($r)?:[]):[];}finally{pg_free_result($r);}
}
initClockRequire(initClockSql("SELECT table_name FROM information_schema.tables WHERE table_schema IN ('public','plugins','chim_meta')")===[],'fresh_fixture_required');
initClockSql('CREATE SCHEMA chim_meta');
initClockSql('CREATE TABLE chim_meta.playthrough_profiles(id bigint PRIMARY KEY,is_active boolean NOT NULL)');
initClockSql("CREATE TABLE public.eventlog(rowid bigserial PRIMARY KEY,ts bigint NOT NULL,gamets numeric NOT NULL,type text NOT NULL,data text NOT NULL,sess text NOT NULL,localts bigint NOT NULL,people text NOT NULL,location text NOT NULL,party text NOT NULL)");
$store=new CorrespondencePgStore($connection,fn($s)=>null,fn($title,$body)=>true);$store->ensureSchema();
function initClockEvent(string $ts,string $type='inputtext'):void{
    initClockSql("INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES($1,100,$2,'synthetic clock','pending',100,'','','')",[$ts,$type]);
}
// Actual pinned client emits nanosecond counts; core BGL also writes time() seconds.
initClockEvent('1800000000000000000');initClockEvent('1800000001','infoaction');
$store->bindRequestClock(['inputtext','1800000000000000001','100']);
initClockRequire($store->readTransaction(fn()=>true),'seconds_row_must_not_invalidate_fresh_native_clock');
initClockEvent('1800000000000000003');
// Last inserted row is deliberately NOT the maximum native clock.
initClockEvent('1800000000000000002');
$stale=false;try{$store->transaction(fn()=>true);}catch(DomainException $e){$stale=$e->getMessage()==='stale_request_clock';}
initClockRequire($stale,'newer_nonmonotonic_native_event_must_reject_stale_completion');

if(($argv[1]??null)!=='--fence-only'){
$store->bindRequestClock(['inputtext','1800000000000000004','100']);
$snapshot=base64_encode(correspondenceJson(['source'=>'plugin_player_routing_v2','present_actors'=>[]]));
$store->observeManaged($snapshot);
$before=(int)initClockSql("SELECT count(*) AS n FROM public.eventlog WHERE sess='correspondence_v1'")[0]['n'];
$store->observeManaged($snapshot);
initClockRequire((int)initClockSql("SELECT count(*) AS n FROM public.eventlog WHERE sess='correspondence_v1'")[0]['n']===$before,'unchanged_fresh_presence_must_not_append_or_take_writer_lock');
initClockSql("UPDATE plugins.correspondence_presence SET payload=jsonb_set(payload,'{observed_at}',to_jsonb((extract(epoch from now())::bigint-46)))");
$store->observeManaged($snapshot);
initClockRequire((int)initClockSql("SELECT count(*) AS n FROM public.eventlog WHERE sess='correspondence_v1'")[0]['n']===$before+1,'expired_presence_must_refresh_even_when_snapshot_unchanged');
initClockSql("DELETE FROM public.eventlog WHERE sess='correspondence_v1'");
$store->observeManaged($snapshot);
initClockRequire(count(initClockSql("SELECT rowid FROM public.eventlog WHERE sess='correspondence_v1'"))===1,'pruned_presence_anchor_must_refresh');
}

// This connection is held as the init request; peer stands for receipt intake.
$GLOBALS['ENGINE_PATH']=dirname(__DIR__);
function ptp_connect():mixed{return correspondenceRecoveryFixture('chim_corr_recovery_store');}
function ptr_runtime_enter():void{}
function chimRegisterPromptInjection(string $slot,string $key,callable $callback,int $priority):void{}
correspondenceHoldInitFence();
$probe=pg_query($peer,'SELECT pg_try_advisory_xact_lock(18459814,1) AS acquired');
initClockRequire(pg_fetch_assoc($probe)['acquired']==='f','init_must_fence_restore_window');pg_free_result($probe);
// Simulate core restore prune and final init marker before supported context hook.
initClockSql('DELETE FROM public.eventlog WHERE gamets >= 100');initClockEvent('1800000000000000004','init');
include dirname(__DIR__,2).'/server/context_pre.php';
$started=microtime(true);
$receiptStore=new CorrespondencePgStore($peer,fn($s)=>null,fn($title,$body)=>true);
$receiptStore->bindRequestClock(['correspondence_receipt','1800000000000000005','100']);
try{$receiptStore->transaction(fn()=>$receiptStore->appendEvent('correspondence_receipt_confirmed','{"synthetic":true}','','correspondence_v1'));}
catch(Throwable $e){throw new RuntimeException('receipt_writer_blocked_by_init_fence',0,$e);}
initClockRequire(microtime(true)-$started<1.0,'receipt_writer_must_not_wait_for_init_model_phase');
initClockRequire(count(initClockSql("SELECT rowid FROM public.eventlog WHERE sess='correspondence_v1'"))===1,'receipt_evidence_must_commit_during_open_init_request');
echo "PASS init/clock recovery: mixed native nanoseconds/core seconds, nonmonotonic stale clock, presence coalescing, restore fence and receipt commit before init shutdown\n";
pg_close($peer);
