<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/support/recovery_pg_fixture.php';
require_once dirname(__DIR__,2).'/server/lib/store.php';
$connection=correspondenceRecoveryFixture('chim_corr_recovery_store');
function concurrentClockSql(string $sql,array $args=[]):array{
    global $connection;$result=pg_query_params($connection,$sql,$args);
    if($result===false)throw new RuntimeException('concurrent_clock_fixture_sql_failed');
    try{return pg_num_fields($result)>0?(pg_fetch_all($result)?:[]):[];}finally{pg_free_result($result);}
}
function concurrentClockRequire(bool $ok,string $reason):void{if(!$ok)throw new RuntimeException($reason);}
concurrentClockRequire(concurrentClockSql("SELECT table_name FROM information_schema.tables WHERE table_schema IN ('public','plugins','chim_meta')")===[],'fresh_owned_fixture_required');
concurrentClockSql('CREATE SCHEMA chim_meta');
concurrentClockSql('CREATE TABLE chim_meta.playthrough_profiles(id bigint PRIMARY KEY,is_active boolean NOT NULL)');
concurrentClockSql("CREATE TABLE public.eventlog(rowid bigserial PRIMARY KEY,ts bigint,gamets numeric,type text,data text,sess text,localts bigint,people text,location text,party text)");
concurrentClockSql("INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(9000000000000000000,1,'info','synthetic outlier','',1,'','',''),(100,100,'inputtext','current native request','',100,'','','')");
$store=new CorrespondencePgStore($connection,fn($s)=>null,fn($title,$body)=>true);
$store->ensureSchema();$store->bindRequestClock(['inputtext','100','100']);
try{$read=$store->readTransaction(fn()=>true);$write=$store->transaction(fn()=>true);}
catch(Throwable $e){throw new RuntimeException('unrelated_future_ts_must_not_poison_bound_reads_or_writes',0,$e);}
concurrentClockRequire($read===true&&$write===true,'unrelated_future_ts_must_not_poison_bound_reads_or_writes');
concurrentClockSql("INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(101,101,'infoaction','ordinary concurrent event','',101,'','','')");
concurrentClockRequire($store->transaction(fn()=>true)===true,'ordinary_newer_event_must_not_invalidate_bound_work');
echo "PASS concurrent clock: outlier and ordinary advancement preserve bound read/write progress\n";
