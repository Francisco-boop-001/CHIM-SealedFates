<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/support/recovery_pg_fixture.php';
require_once dirname(__DIR__,2).'/server/lib/bootstrap.php';
final class Logger{
    public static array $rows=[];
    public static function info(string $message):void{self::$rows[]=['info',$message];}
    public static function warn(string $message):void{self::$rows[]=['warn',$message];}
    public static function error(string $message):void{self::$rows[]=['error',$message];}
}
function intakePgCheck(bool $ok,string $reason):void{if(!$ok)throw new RuntimeException($reason);}
$connection=correspondenceRecoveryFixture('chim_corr_recovery_store');
if(($argv[1]??null)==='--hold-fence'){
    intakePgCheck(pg_query($connection,'SELECT pg_advisory_lock(18459814,1)')!==false,'peer_fence_failed');
    echo "LOCKED\n";fflush(STDOUT);usleep(4500000);
    intakePgCheck(pg_query($connection,'SELECT pg_advisory_unlock(18459814,1)')!==false,'peer_unlock_failed');pg_close($connection);return;
}
function intakePgSql(string $sql,array $args=[]):array{
    global $connection;$result=pg_query_params($connection,$sql,$args);if($result===false)throw new RuntimeException('intake_fixture_sql_failed');
    try{return pg_num_fields($result)>0?(pg_fetch_all($result)?:[]):[];}finally{pg_free_result($result);}
}
intakePgCheck(intakePgSql("SELECT table_name FROM information_schema.tables WHERE table_schema IN ('public','plugins','chim_meta')")===[],'fresh_intake_fixture_required');
$ddl=<<<'SQL'
CREATE SCHEMA chim_meta;
CREATE TABLE chim_meta.playthrough_profiles(id bigint PRIMARY KEY,is_active boolean NOT NULL);
CREATE TABLE public.eventlog(rowid bigserial PRIMARY KEY,ts bigint NOT NULL,gamets numeric NOT NULL,type text NOT NULL,data text NOT NULL,sess text NOT NULL,localts bigint NOT NULL,people text NOT NULL,location text NOT NULL,party text NOT NULL);
CREATE TABLE public.core_npc_master(id bigint PRIMARY KEY,npc_name text NOT NULL,refid text NOT NULL,profile_id text);
CREATE TABLE public.core_player(id text PRIMARY KEY,value text NOT NULL);
CREATE TABLE public.game_plugins(plugin_name text,formid_prefix text);
CREATE TABLE public.books(ts bigint,gamets numeric,content text,sess text,localts bigint,title text);
CREATE TABLE public.responselog(localts bigint,sent integer,actor text,text text,action text,tag text);
INSERT INTO public.core_player VALUES('player_name','Dragonborn');
INSERT INTO public.core_npc_master VALUES(900001,'Camilla','00013488',NULL),(900002,'Jarl Balgruuf','0001A677',NULL),(900003,'Lydia','000A2C94',NULL);
INSERT INTO public.game_plugins VALUES('Skyrim.esm','00');
INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(1000,1000,'inputtext','synthetic receipt recovery','pending',1000,'','','');
SQL;
intakePgCheck(pg_query($connection,$ddl)!==false,'intake_fixture_schema_failed');
$store=new CorrespondencePgStore($connection,fn($s)=>'Lydia',fn($title,$body)=>true);$store->ensureSchema();
$store->bindRequestClock(['inputtext','1000','1000']);$service=new CorrespondenceService($store);
$document=$service->compose(['request_id'=>correspondenceUuid(),'author'=>'player','payer'=>'player','recipient'=>'npc:900001','claimed_sender'=>'npc:900002','body'=>'A synthetic letter surviving a temporarily busy writer.','attachments'=>[]]);
$dispatched=$service->dispatch($document['document_id']);$operation=$store->loadOperation($dispatched['operation_id']);
$receipt=array_intersect_key($operation,array_flip(['protocol_version','document_id','operation_id','receipt_id','playthrough_id','scope_token','nonce']));
$receipt+=['status'=>'delivered','reason'=>'physical_only','note_form_id'=>24680,'attachments'=>[]];
$child=proc_open([PHP_BINARY,'-n','-d','extension=pgsql','-d','extension=ctype',__FILE__,'--hold-fence'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
intakePgCheck(is_resource($child),'fence_child_start_failed');fclose($pipes[0]);
try{
    intakePgCheck(trim((string)fgets($pipes[1]))==='LOCKED','fence_child_not_ready');
    $started=microtime(true);
    $result=correspondenceReceiveWithRetry(['correspondence_receipt','1001','1001','synthetic'],$receipt,fn()=>['store'=>$store,'service'=>$service]);
    intakePgCheck($result['state']==='delivered'&&microtime(true)-$started>=3.0,'receipt_must_recover_after_first_writer_timeout');
    intakePgCheck(count(Logger::$rows)===2&&Logger::$rows[0][0]==='warn'&&Logger::$rows[1][0]==='info','real_retry_and_recovery_must_be_logged');
    intakePgCheck((int)intakePgSql("WITH rows AS MATERIALIZED (SELECT data FROM public.eventlog WHERE type='info' AND sess='correspondence_v1') SELECT count(*) AS n FROM rows WHERE data::jsonb ? 'receipt_hash'")[0]['n']===1,'recovered_intake_must_commit_one_receipt');
    intakePgCheck((int)intakePgSql("SELECT count(*) AS n FROM public.responselog WHERE concat(action,chr(124),text) LIKE 'command|ExtCmdCHIMCorrespondenceBridge_Deliver@%'")[0]['n']===1,'intake_retry_must_not_queue_a_second_inventory_transfer');
}finally{
    $childError=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($child);
    intakePgCheck($code===0,'fence_child_failed:'.$childError);
}
pg_close($connection);
echo "PASS actual receipt intake after writer timeout: one stored receipt, one original delivery command, bounded retry and correlated recovery logs\n";
