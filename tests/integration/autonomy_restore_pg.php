<?php
declare(strict_types=1);
// Root-owned independent native oracle. Fake renderer/physical receipt/model; actual producer, anchors and Pg scheduler.
function anchorCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
anchorCheck(getenv('WSL_DISTRO_NAME')==='DwemerAI4Skyrim3-test'&&PHP_SAPI==='cli','clone_cli_only');
foreach(getenv()as$name=>$_)anchorCheck(!str_starts_with(strtoupper((string)$name),'PG'),'postgres_environment_forbidden');
$c=pg_connect('host=/var/run/postgresql dbname=chim_correspondence_autonomy_restore_it user=postgres',PGSQL_CONNECT_FORCE_NEW);
function anchorSql(string $sql,array $args=[]):array{global $c;$r=pg_query_params($c,$sql,$args);anchorCheck($r!==false,'oracle_sql');return pg_num_fields($r)>0?(pg_fetch_all($r)?:[]):[];}
anchorCheck(anchorSql("SELECT current_database() db,current_user role,current_setting('listen_addresses') listeners,(inet_server_addr() IS NULL)::int local")[0]===['db'=>'chim_correspondence_autonomy_restore_it','role'=>'postgres','listeners'=>'','local'=>'1'],'isolated_peer_db_only');
anchorCheck(anchorSql("SELECT table_name FROM information_schema.tables WHERE table_schema IN ('public','plugins','chim_meta')")===[],'blank_owned_db_required');
$ddl=<<<'SQL'
CREATE TABLE public.eventlog(rowid bigserial PRIMARY KEY,ts bigint,gamets bigint,type text,data text,sess text,localts bigint,people text,location text,party text);
CREATE TABLE public.core_npc_master(id bigint PRIMARY KEY,npc_name text,refid text,profile_id bigint,personality text,extended_data jsonb,metadata jsonb);
CREATE TABLE public.core_player(id text PRIMARY KEY,value text);
CREATE TABLE public.game_plugins(plugin_name text,formid_prefix text);
CREATE TABLE public.books(id bigserial PRIMARY KEY,ts bigint,gamets bigint,content text,sess text,localts bigint,title text);
CREATE TABLE public.responselog(id bigserial PRIMARY KEY,localts bigint,sent integer,actor text,text text,action text,tag text);
INSERT INTO public.core_player VALUES('player_name','Dragonborn');
INSERT INTO public.game_plugins VALUES('Skyrim.esm','00');
INSERT INTO public.core_npc_master VALUES(900001,'Camilla','00013488',1,'Proud and thoughtful.','{"background_life_enabled":true,"background_life_letters":true,"personality":"Proud and thoughtful."}','{"last_coords":[0,0,0,"Riverwood"]}'),(900002,'Jarl Balgruuf','00013BBD',2,'Reserved.','{"background_life_enabled":true,"background_life_letters":true}','{"last_coords":[0,0,0,"Riverwood"]}'),(900003,'Lydia','000A2C94',3,'Practical.','{"background_life_enabled":true,"background_life_letters":true}','{}');
INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(100,100,'inputtext','Dragonborn: fixture','',100,'','','');
SQL;
anchorCheck(pg_query($c,$ddl)!==false,'oracle_ddl');
$p=dirname(__DIR__,2);require_once $p.'/server/lib/store.php';require_once $p.'/server/lib/autonomy_policy.php';
$old=getenv('CORRESPONDENCE_ANCHOR_RED')==='1';
require_once $old?'/mnt/k/ActorwrightExchange/artifacts/chim-correspondence-autonomy-20261019/anchor-red-stage/server/lib/autonomy_store.php':$p.'/server/lib/autonomy_store.php';
$store=new CorrespondencePgStore($c,fn($s)=>'Lydia',fn($title,$body)=>true);$store->ensureSchema();$store->bindRequestClock(['inputtext','100','100']);$service=new CorrespondenceService($store);$a=new CorrespondenceAutonomyPg($store,$service);$a->ensureSchema();$a->setEnabled(true);
for($i=0;$i<2;$i++){
$d=$service->compose(['request_id'=>correspondenceUuid(),'author'=>'player','payer'=>'player','recipient'=>'npc:900001','claimed_sender'=>'npc:900002','body'=>'Camilla, please explain your concern.','attachments'=>[]]);$sent=$service->dispatch($d['document_id']);$op=$store->loadOperation($sent['operation_id']);$receipt=array_intersect_key($op,array_flip(['protocol_version','document_id','operation_id','receipt_id','playthrough_id','scope_token','nonce']));$receipt+=['status'=>'delivered','reason'=>'physical_only','note_form_id'=>12345,'attachments'=>[]];$service->receive($receipt);$service->prompt('Camilla');
}
$future=100+correspondenceAutonomyGamets(4);$restore=100+correspondenceAutonomyGamets(2);$calls=0;
$decide=static function(array $messages)use(&$calls):string{$calls++;return '{"action":"none","recipient":null,"body":"","reason":"I will remain silent."}';};
$store->bindRequestClock(['inputtext','200',(string)$future]);$first=$a->tick($decide);
anchorCheck($first['status']==='silent'&&$calls===1,'future_first_claim_required');
$row=anchorSql('SELECT payload::text p FROM plugins.correspondence_autonomy_decisions')[0];$payload=json_decode($row['p'],true,64,JSON_THROW_ON_ERROR);
anchorCheck($store->eventMatches($payload['claim_anchor'])&&$store->eventMatches($payload['completion_anchor'])&&(int)$payload['claim_anchor']['gamets']>$restore,'actual_future_claim_and_completion_required');
// A native request may have a newer ts but an earlier restored game clock. Retain future rows to prove the time gate independently of pruning.
$store->bindRequestClock(['inputtext','300',(string)$restore]);$second=$a->tick($decide);
anchorCheck($second['status']==='silent'&&$calls===2,'future_orphan_must_not_block_distinct_past_source:'.json_encode($second));
anchorCheck(count(anchorSql('SELECT source_document_id FROM plugins.correspondence_autonomy_decisions'))===2,'two_distinct_sources_claimed_once');
$store->bindRequestClock(['inputtext','400',(string)($restore+1)]);$replay=$a->tick($decide);
anchorCheck($calls===2&&$replay['model_calls']===0,'completed_sources_never_automatically_retried');
echo json_encode(['status'=>'PASS','future_claim_and_completion_retained'=>true,'future_cooldowns_do_not_starve_past_source'=>true,'distinct_sources'=>2,'model_calls'=>$calls,'provider_calls'=>0,'synthetic_clock_and_receipts'=>true],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";