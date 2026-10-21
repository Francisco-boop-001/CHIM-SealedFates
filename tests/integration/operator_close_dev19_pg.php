<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/support/recovery_pg_fixture.php';
require_once dirname(__DIR__,2).'/server/lib/store.php';
require_once dirname(__DIR__,2).'/server/lib/consumers.php';
$connection=correspondenceRecoveryFixture('chim_corr_recovery_store');
function closePgCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
closePgCheck(pg_fetch_result(pg_query($connection,"SELECT to_regclass('public.eventlog') IS NULL"),0)==='t','close_fixture_must_be_empty');
$ddl=<<<'SQL'
CREATE TABLE public.eventlog(rowid bigserial PRIMARY KEY,ts bigint,gamets bigint,type text,data text,sess text,localts bigint,people text,location text,party text);
CREATE TABLE public.core_npc_master(id bigint PRIMARY KEY,npc_name text,refid text,profile_id bigint);
CREATE TABLE public.core_player(id text PRIMARY KEY,value text);
CREATE TABLE public.game_plugins(plugin_name text,formid_prefix text);
CREATE TABLE public.books(ts bigint,gamets bigint,content text,sess text,localts bigint,title text);
CREATE TABLE public.responselog(localts bigint,sent integer,actor text,text text,action text,tag text);
INSERT INTO public.core_player VALUES('player_name','Dragonborn');
INSERT INTO public.core_npc_master VALUES(900001,'Camilla','00013488',1),(900002,'Jarl Balgruuf','0001A677',1),(900003,'Lydia','000A2C94',1);
INSERT INTO public.game_plugins VALUES('Skyrim.esm','00');
INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(100,100,'inputtext','fixture','',100,'','','');
SQL;
closePgCheck(pg_query($connection,$ddl)!==false,'close_fixture_setup');
$store=new CorrespondencePgStore($connection,fn()=>'Lydia',fn()=>true);$store->ensureSchema();$store->bindRequestClock(['inputtext','101','100']);$service=new CorrespondenceService($store);
$input=['request_id'=>correspondenceUuid(),'author'=>'player','payer'=>'player','recipient'=>'npc:900001','claimed_sender'=>'npc:900002','body'=>'The courier is filing a grievance.','attachments'=>[['kind'=>'gold','form_id'=>'Skyrim.esm|0000000F','quantity'=>50]]];
closePgCheck(method_exists($service,'close'),'RED: operator_close_missing');
$cancelled=$service->compose($input);$queueClosed=$service->close($cancelled['document_id']);
closePgCheck($queueClosed['state']==='queued'&&$queueClosed['operator_closed']===true,'queued_close_accepts_null_operation');
closePgCheck(pg_num_rows(pg_query($connection,'SELECT * FROM public.responselog'))===0,'queued_close_does_not_render_or_dispatch');
$tampered=$store->loadDocument($cancelled['document_id']);$tampered['operation_id']=correspondenceUuid();
$refused=false;try{$store->transaction(fn()=>$store->saveDocument($tampered));}catch(DomainException $e){$refused=$e->getMessage()==='immutable_document_conflict';}closePgCheck($refused,'queued_close_cannot_gain_operation');
$refused=false;try{$service->dispatch($cancelled['document_id']);}catch(DomainException $e){$refused=$e->getMessage()==='operator_closed';}closePgCheck($refused,'closed_queue_cannot_dispatch');
$input['request_id']=correspondenceUuid();
$queued=$service->compose($input);$sent=$service->dispatch($queued['document_id']);$op=$store->loadOperation($sent['operation_id']);
$receipt=array_intersect_key($op,array_flip(['protocol_version','document_id','operation_id','receipt_id','playthrough_id','scope_token','nonce']));
$receipt+=['status'=>'unknown','reason'=>'journal_unavailable','note_form_id'=>0,'attachments'=>[['form_id'=>15,'quantity'=>0,'before_source'=>100,'after_source'=>100,'before_recipient'=>0,'after_recipient'=>0]]];
$service->receive($receipt);$before=pg_num_rows(pg_query($connection,'SELECT * FROM public.responselog'));
$closed=$service->close($queued['document_id']);
closePgCheck($closed['state']==='unknown'&&$service->get($queued['document_id'])['state']==='unknown','closure_preserves_unknown');
$persisted=$store->loadDocument($queued['document_id']);
foreach(['remove','change','reset_operation']as$mutation){
    $tampered=$persisted;
    if($mutation==='remove')unset($tampered['operator_close']);
    elseif($mutation==='change')$tampered['operator_close']['data']='changed';
    else $tampered['operation_id']=null;
    $refused=false;try{$store->transaction(fn()=>$store->saveDocument($tampered));}catch(DomainException $e){$refused=$e->getMessage()==='immutable_document_conflict';}
    closePgCheck($refused,'closure_must_be_monotonic_'.$mutation);
}
closePgCheck(pg_num_rows(pg_query($connection,'SELECT * FROM public.responselog'))===$before,'closure_does_not_send');
foreach(['dispatch','retry']as$action){$refused=false;try{$service->$action($queued['document_id']);}catch(DomainException){$refused=true;}closePgCheck($refused,'closed_'.$action.'_refused');}
$service->dispatch($queued['document_id'],true);
$receipt['status']='delivered';$receipt['reason']='physical_only';$receipt['note_form_id']=12345;$receipt['attachments'][0]['quantity']=50;$receipt['attachments'][0]['after_source']=50;$receipt['attachments'][0]['after_recipient']=50;
closePgCheck($service->receive($receipt)['state']==='delivered','late_proof_resolves_closed_unknown');
$eventCount=pg_num_rows(pg_query($connection,'SELECT * FROM public.eventlog'));closePgCheck($service->close($queued['document_id'])['state']==='delivered'&&pg_num_rows(pg_query($connection,'SELECT * FROM public.eventlog'))===$eventCount,'repeated_close_after_late_proof_is_idempotent');
$listed=array_column($service->list(),null,'document_id');closePgCheck($listed[$queued['document_id']]['operator_closed']===true,'late_proof_not_reopen');
$cap=['version'=>1,'input_type'=>'written_evidence','source_marker'=>'written_evidence_v1','source_event_type'=>'info','recipient_only'=>true,'max_body_codepoints'=>2000];
$calls=0;$request=function()use(&$calls){$calls++;return ['status'=>'processed','reason'=>'no_change'];};
pg_query($connection,'BEGIN');
closePgCheck(correspondenceCallConsumer(42,fn()=>$cap,$request,$connection)['reason']==='consumer_connection_busy'&&$calls===0,'consumer_inherited_transaction_refused');
closePgCheck(pg_transaction_status($connection)===PGSQL_TRANSACTION_INTRANS,'consumer_guard_must_not_commit_or_rollback');pg_query($connection,'ROLLBACK');
$result=correspondenceCallConsumer(42,fn()=>$cap,function()use($connection){pg_query($connection,'BEGIN');return ['status'=>'processed','reason'=>'no_change'];},$connection);
closePgCheck($result['reason']==='consumer_connection_busy','consumer_leaked_transaction_refused');
$refused=false;try{$store->readTransaction(fn()=>true);}catch(Throwable){$refused=true;}closePgCheck($refused&&pg_transaction_status($connection)===PGSQL_TRANSACTION_INTRANS,'store_must_not_commit_consumer_transaction');pg_query($connection,'ROLLBACK');
$close=$store->loadDocument($queued['document_id'])['operator_close'];pg_query_params($connection,'DELETE FROM public.eventlog WHERE rowid=$1',[$close['rowid']]);
$refused=false;try{$service->dispatch($queued['document_id']);}catch(DomainException $e){$refused=$e->getMessage()==='document_unavailable';}closePgCheck($refused,'pruned_closure_must_quarantine');
pg_close($connection);echo "PASS dev19 PG operator close and consumer idle boundaries\n";
