<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/server/lib/store.php';
require_once dirname(__DIR__).'/server/lib/boundaries.php';
function pgCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
pgCheck(getenv('WSL_DISTRO_NAME')==='DwemerAI4Skyrim3-test','refusing outside the registered disposable clone');
pgCheck(function_exists('pg_connect'),'native PostgreSQL extension required');
$dsn=getenv('CORRESPONDENCE_TEST_DSN')?:'host=/var/run/postgresql dbname=chim_correspondence_it user=postgres';
$c=@pg_connect($dsn,PGSQL_CONNECT_FORCE_NEW);pgCheck($c!==false,'fixture peer connection failed');
function fixtureSql(string $sql,array $args=[]):array{global $c;$r=@pg_query_params($c,$sql,$args);if(!$r)throw new RuntimeException('fixture_sql_failed');try{return pg_num_fields($r)>0?(pg_fetch_all($r)?:[]):[];}finally{pg_free_result($r);}}
$guard=fixtureSql("SELECT current_database() AS name,current_user AS role,current_setting('listen_addresses') AS listeners")[0];
pgCheck($guard['name']==='chim_correspondence_it'&&$guard['role']==='postgres'&&$guard['listeners']==='','refusing non-isolated fixture database');
pgCheck(fixtureSql("SELECT table_name FROM information_schema.tables WHERE table_schema IN ('public','plugins','chim_meta')")===[],'fixture database must begin empty');
$ddl=<<<'SQL'
CREATE TABLE public.eventlog(rowid bigserial PRIMARY KEY,ts bigint,gamets bigint,type text,data text,sess text,localts bigint,people text,location text,party text);
CREATE TABLE public.core_npc_master(id bigint PRIMARY KEY,npc_name text,refid text,profile_id bigint);
CREATE TABLE public.core_player(id text PRIMARY KEY,value text);
CREATE TABLE public.game_plugins(plugin_name text,formid_prefix text);
CREATE TABLE public.books(ts bigint,gamets bigint,content text,sess text,localts bigint,title text);
CREATE TABLE public.responselog(localts bigint,sent integer,actor text,text text,action text,tag text);
INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(100,100,'inputtext','synthetic fixture seed','',100,'','','');
INSERT INTO public.core_player VALUES('player_name','Dragonborn');
INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(100,100,'info','Ordinary non-JSON info telemetry','pending',100,'','','');
INSERT INTO public.core_npc_master VALUES(900001,'Camilla','00013488',1),(900002,'Jarl Balgruuf','0001A677',1),(900003,'Lydia','000A2C94',1);
INSERT INTO public.game_plugins VALUES('Skyrim.esm','00');
SQL;
pgCheck(pg_query($c,$ddl)!==false,'synthetic tables failed');

$preparedNotes=[];$store=new CorrespondencePgStore($c,fn($s)=>$s->observedExecutor(),function($title,$body)use(&$preparedNotes){$preparedNotes[]=['title'=>$title,'text'=>$body];return true;});
pgCheck(method_exists($store,'ensureSchema'),'fresh installation schema initialization missing');
$store->ensureSchema();$store->ensureSchema();
$service=new CorrespondenceService($store);
fixtureSql("INSERT INTO plugins.correspondence_documents(document_id,playthrough_id,request_id,payload) SELECT ('aaaaaaaa-aaaa-4aaa-8aaa-'||lpad(i::text,12,'0'))::uuid,'mailbox_limit_fixture',('bbbbbbbb-bbbb-4bbb-8bbb-'||lpad(i::text,12,'0'))::uuid,'{}'::jsonb FROM generate_series(1,1001) AS i");
pgCheck(count($store->documents('mailbox_limit_fixture'))===1001,'persisted mailbox must not hide older documents behind a query cap');
fixtureSql("DELETE FROM plugins.correspondence_documents WHERE playthrough_id='mailbox_limit_fixture'");
pgCheck(array_column($store->itemChoices(),'form_id')===['Skyrim.esm|0000000F'],'older core without optional market cache must retain Gold fallback');
fixtureSql('CREATE TABLE public.market_cache(baseid text,plugin text,name text,price integer)');
fixtureSql("INSERT INTO public.game_plugins VALUES('Other.esp','01'),('Light.ESL','FE123')");
fixtureSql("INSERT INTO public.market_cache VALUES('000139B5','Skyrim.esm','Steel Gift',10),('01000801','Other.esp','Modded Gift',20),('FE123801','Light.ESL','Light Gift',30),('0x000139B6','Skyrim.esm','Legacy Prefix Gift',5),('nonsense','Skyrim.esm','Malformed',1),('FF000123','Skyrim.esm','Dynamic',1),('01000802','Skyrim.esm','Stale Prefix',1),('02000801','Missing.esp','Unmapped',1),('00000000','Skyrim.esm','Zero Form',1),('01000000','Other.esp','Zero Local Form',1)");
$choices=$store->itemChoices();$keys=array_column($choices,'form_id');
pgCheck(in_array('Skyrim.esm|000139B5',$keys,true),'inventory-only cached item absent from nearby infoitems must be selectable');
pgCheck(in_array('Other.esp|00000801',$keys,true)&&in_array('Light.ESL|00000801',$keys,true)&&in_array('Skyrim.esm|000139B6',$keys,true),'cached full/light/optional0x encodings must resolve strictly');
pgCheck(count($choices)===5&&!in_array('Other.esp|00000802',$keys,true)&&!in_array('Skyrim.esm|00000802',$keys,true),'malformed/dynamic/unmapped/zero/stale-prefix cache entries must be rejected');
fixtureSql("INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(100,100,'infoitems','(items in range:0x00001234:0x000139B5:Nearby Steel Gift,0x00001235:0x000139B7:Nearby New Gift)','',100,'','','')");
$choices=$store->itemChoices();$keys=array_column($choices,'form_id');pgCheck(count($choices)===6&&count(array_filter($keys,fn($key)=>$key==='Skyrim.esm|000139B5'))===1&&in_array('Skyrim.esm|000139B7',$keys,true),'nearby fallback must remain and duplicate stable forms must be deduped');
fixtureSql("INSERT INTO public.game_plugins VALUES('Ambiguous.esp','01')");$keys=array_column($store->itemChoices(),'form_id');pgCheck(!in_array('Other.esp|00000801',$keys,true),'ambiguous load-order prefix must not identify a cached attachment');fixtureSql("DELETE FROM public.game_plugins WHERE plugin_name='Ambiguous.esp'");
$snapshot=base64_encode(correspondenceJson(['source'=>'plugin_player_routing_v2','present_actors'=>[['form_id'=>hexdec('000A2C94'),'name'=>'Lydia','distance'=>10,'managed'=>true,'creature'=>false]]]));
$store->bindRequestClock(['inputtext','100','100']);$store->observeManaged($snapshot);
$input=['request_id'=>correspondenceUuid(),'author'=>'player','payer'=>'player','recipient'=>'npc:900001','claimed_sender'=>'npc:900002','body'=>"Camilla, I enclose 50 septims.\nSigned, Jarl Balgruuf",'attachments'=>[['kind'=>'gold','form_id'=>'Skyrim.esm|0000000F','quantity'=>50]]];
$doc=$service->compose($input);pgCheck($service->compose($input)['document_id']===$doc['document_id'],'native idempotent submission failed');
pgCheck($store->loadDocument($doc['document_id'])['anchor']['type']==='info'&&$store->loadDocument($doc['document_id'])['anchor']['sess']==='correspondence_v1','private document anchor must use log-only physical carrier');
$sent=$service->dispatch($doc['document_id']);pgCheck($sent['state']==='dispatched','native JSONB document load/dispatch failed');
$books=fixtureSql('SELECT * FROM public.books');$expectedNote="From: Jarl Balgruuf\nTo: Camilla\n\n".$input['body'];
pgCheck(count($books)===1&&$books[0]['content']===$expectedNote&&$preparedNotes===[['title'=>'letter '.$doc['document_id'],'text'=>$expectedNote]],'books and actual renderer callback must receive exact public header plus original body without author/payer');
pgCheck($store->loadDocument($doc['document_id'])['body']===$input['body'],'physical projection must preserve immutable original body');
$commands=fixtureSql('SELECT actor,concat(action,chr(124),text) AS action FROM public.responselog ORDER BY localts,action');pgCheck(count($commands)===2,'render download plus actor transport required');
$deliver=array_values(array_filter($commands,fn($r)=>$r['actor']==='Lydia'))[0]['action'];$originalWire=explode('@',$deliver,2)[1];
$service->dispatch($doc['document_id'],true);$commands=fixtureSql("SELECT actor,concat(action,chr(124),text) AS action FROM public.responselog WHERE actor='Lydia'");pgCheck(count($commands)===2,'readonly reconcile queue missing');
$reconcile=array_values(array_filter($commands,fn($r)=>str_contains($r['action'],'_Reconcile@')))[0]['action'];pgCheck(explode('@',$reconcile,2)[1]===$originalWire,'native JSONB reordered replay wire');
$op=$store->loadOperation($sent['operation_id']);
$receipt=['protocol_version'=>1,'document_id'=>$doc['document_id'],'operation_id'=>$op['operation_id'],'receipt_id'=>$op['receipt_id'],'playthrough_id'=>'unprofiled','scope_token'=>$op['scope_token'],'nonce'=>$op['nonce'],'status'=>'delivered','reason'=>'physical_only','note_form_id'=>12345,'attachments'=>[['form_id'=>15,'quantity'=>50,'before_source'=>100,'after_source'=>50,'before_recipient'=>0,'after_recipient'=>50]]];
pgCheck($service->receive($receipt)['state']==='delivered','native receipt failed');pgCheck($service->receive($receipt)['state']==='delivered','native JSONB receipt replay failed');
pgCheck(fixtureSql("SELECT rowid FROM public.eventlog WHERE type='info' AND sess='written_evidence_v1'")===[],'physical receipt exposed prematurely');
pgCheck($service->prompt('Faendal')==='','unrelated prompt leaked');$text=$service->prompt('Camilla');pgCheck(str_contains($text,'Signed, Jarl Balgruuf')&&!str_contains($text,'Dragonborn'),'recipient prompt/privacy failed');
pgCheck(fixtureSql("SELECT rowid FROM public.eventlog WHERE type IN ('written_evidence','correspondence_anchor','correspondence_dispatch','correspondence_receipt_confirmed','correspondence_presence','correspondence_receipt')")===[],'raw custom types must never enter generic history');
$source=fixtureSql("SELECT * FROM public.eventlog WHERE type='info' AND sess='written_evidence_v1'");pgCheck(count($source)===1,'one neutral source required');$event=json_decode($source[0]['data'],true,512,JSON_THROW_ON_ERROR);
pgCheck($event['body']===$input['body'],'neutral written source body must remain exact original without physical header');
pgCheck($source[0]['sess']==='written_evidence_v1'&&$source[0]['people']==='|Camilla|'&&$event['receipt']['exposure']==='context','neutral source marker wrong');
$service->prompt('Camilla');pgCheck(count(fixtureSql("SELECT rowid FROM public.eventlog WHERE type='info' AND sess='written_evidence_v1'"))===1,'native source replay duplicated');
$anchor=$store->loadOperation($sent['operation_id'])['receipt_anchor']['rowid'];fixtureSql('DELETE FROM public.eventlog WHERE rowid >= $1',[$anchor]);
pgCheck($service->get($doc['document_id'])['state']==='unknown'&&$service->prompt('Camilla')==='','restored receipt absence trusted future mutable state');
$before=count(fixtureSql('SELECT * FROM public.responselog'));$service->dispatch($doc['document_id']);pgCheck(count(fixtureSql('SELECT * FROM public.responselog'))===$before,'restore resent physical mutation');
$anchor=$store->loadDocument($doc['document_id'])['anchor']['rowid'];fixtureSql('DELETE FROM public.eventlog WHERE rowid >= $1',[$anchor]);
try{$service->get($doc['document_id']);throw new RuntimeException('missing restored document accepted');}catch(DomainException $e){pgCheck($e->getMessage()==='document_unavailable','wrong restore rejection');}
echo "PASS native server PgStore: migration, actual inserts, JSONB roundtrip, books, actor wire, receipt/source replay, privacy and restore\n";
pg_close($c);




