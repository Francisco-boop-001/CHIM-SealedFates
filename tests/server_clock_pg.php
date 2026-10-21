<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/server/lib/store.php';
require_once dirname(__DIR__).'/server/lib/boundaries.php';
function clockCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
clockCheck(getenv('WSL_DISTRO_NAME')==='DwemerAI4Skyrim3-test','registered disposable clone only');
$clockDsn='host=/var/run/postgresql dbname=chim_correspondence_it user=postgres';
$c=pg_connect($clockDsn,PGSQL_CONNECT_FORCE_NEW);clockCheck($c!==false,'peer fixture connection required');
function clockSql(string $sql,array $args=[]):array{global $c;$r=pg_query_params($c,$sql,$args);if(!$r)throw new RuntimeException('clock_fixture_sql_failed');try{return pg_num_fields($r)>0?(pg_fetch_all($r)?:[]):[];}finally{pg_free_result($r);}}
$guard=clockSql("SELECT current_database() AS db,current_user AS role,current_setting('listen_addresses') AS listeners")[0];clockCheck($guard['db']==='chim_correspondence_it'&&$guard['role']==='postgres'&&$guard['listeners']==='','isolated socket-only fixture required');
clockCheck((int)(clockSql('SELECT MAX(ts) AS ts FROM public.eventlog')[0]['ts']??0)===100,'run immediately after fresh server_pg fixture with core clock100');
$GLOBALS['ENGINE_PATH']=getenv('CORRESPONDENCE_TEST_ENGINE_ROOT');clockCheck(is_string($GLOBALS['ENGINE_PATH'])&&is_file($GLOBALS['ENGINE_PATH'].'/lib/playthrough_runtime.php')&&is_file($GLOBALS['ENGINE_PATH'].'/lib/prompt_injections.php'),'stage exact pinned core runtime barrier and prompt helper first');
function ptp_connect():mixed{return pg_connect('host=/var/run/postgresql dbname=chim_correspondence_it user=postgres',PGSQL_CONNECT_FORCE_NEW);}
final class CorrespondenceClockHookEnd extends RuntimeException{}
function terminate():never{throw new CorrespondenceClockHookEnd();}
function clockHook(array $request):void{$GLOBALS['gameRequest']=$request;try{include dirname(__DIR__).'/server/preprocessing.php';}catch(CorrespondenceClockHookEnd){}}
// The DB-only fixture has no filesystem authority; establish it through a completed native init.
$GLOBALS['MUST_END']=false;
clockHook(['init','90','50','synthetic native bootstrap']);
clockSql("INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(90,50,'init','fixture','pending',1,'','','')");
$GLOBALS['MUST_END']=true;
clockCheck(correspondenceCompletePendingInit(),'native_bootstrap_must_establish_matching_restore_authority');
$GLOBALS['MUST_END']=false;
clockSql("INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(100,100,'inputtext','native clock fixture resumed','',1,'','','')");
$store=new CorrespondencePgStore($c,fn($s)=>'Lydia',fn($title,$body)=>true);$store->ensureSchema();$service=new CorrespondenceService($store);
$input=['request_id'=>correspondenceUuid(),'author'=>'player','payer'=>'player','recipient'=>'npc:900001','claimed_sender'=>'npc:900002','body'=>'Native clock rollback letter.','attachments'=>[]];
$doc=$service->compose($input);$sent=$service->dispatch($doc['document_id']);$op=$store->loadOperation($sent['operation_id']);
$receipt=['protocol_version'=>1,'document_id'=>$doc['document_id'],'operation_id'=>$op['operation_id'],'receipt_id'=>$op['receipt_id'],'playthrough_id'=>'unprofiled','scope_token'=>$op['scope_token'],'nonce'=>$op['nonce'],'status'=>'delivered','reason'=>'physical_only','note_form_id'=>12345,'attachments'=>[]];
clockHook(['correspondence_receipt','200','200',correspondenceJson($receipt)]);
$after=$store->loadOperation($op['operation_id']);clockCheck((int)$after['receipt_anchor']['gamets']===200&&(int)$after['receipt_anchor']['ts']===200,'native receipt200 must not inherit stale logged clock100');
require_once $GLOBALS['ENGINE_PATH'].'/lib/prompt_injections.php';include dirname(__DIR__).'/server/context_pre.php';
$text=chimRenderPromptInjections('character_bottom',['herika_name'=>'Camilla','narrator_name'=>'The Narrator','game_request'=>['inputtext','210','210','Dragonborn: Hello']]);
$after=$store->loadOperation($op['operation_id']);clockCheck(str_contains($text,'Native clock rollback letter.')&&(int)$after['source']['gamets']===210&&(int)$after['source']['ts']===210,'source must use actual scoped context request210');
$snapshot=base64_encode(correspondenceJson(['source'=>'plugin_player_routing_v2','present_actors'=>[['form_id'=>hexdec('000A2C94'),'name'=>'Lydia','distance'=>10,'managed'=>true,'creature'=>false]]]));
// This case checks a renewed anchor's native clock; identical fresh snapshots are coalesced.
clockSql("UPDATE plugins.correspondence_presence SET payload=jsonb_set(payload,'{observed_at}',to_jsonb((extract(epoch from now())::bigint-46)))");
clockHook(['inputtext','220','220','Dragonborn: Hello',$snapshot]);
$presence=clockSql("WITH marked AS MATERIALIZED (SELECT rowid,gamets,data FROM public.eventlog WHERE type='info' AND sess='correspondence_v1') SELECT gamets FROM marked WHERE data::jsonb ? 'token' ORDER BY rowid DESC LIMIT 1");clockCheck((int)$presence[0]['gamets']===220,'managed observation must bind actual native clock220');
$GLOBALS['MUST_END']=false;clockHook(['init','300','150','fixture']);
clockCheck(clockSql('SELECT pg_try_advisory_xact_lock(18459814,1) AS acquired')[0]['acquired']==='f','init must hold session fence against another connection through prune and marker');
clockSql('DELETE FROM public.eventlog WHERE gamets >= $1',[150]);

clockCheck(clockSql("SELECT rowid FROM public.eventlog WHERE type='info' AND sess='written_evidence_v1' AND data::jsonb->>'document_id'=$1",[$doc['document_id']])===[],'rollback150 must remove future source210');
clockSql("INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(300,150,'init','fixture','pending',1,'','','')");
clockCheck(!correspondenceCompletePendingInit(),'init_marker_without_completed_core_phase_must_remain_quarantined');
clockCheck(clockSql('SELECT pending FROM plugins.correspondence_restore_state WHERE singleton IS TRUE')[0]['pending']==='t','incomplete_core_restore_must_keep_durable_pending');
$GLOBALS['MUST_END']=true;
clockCheck(correspondenceCompletePendingInit(),'completed_core_init_must_release_restore_quarantine');
$oldHandleRejected=false;
try{$service->get($doc['document_id']);}catch(DomainException $error){$oldHandleRejected=$error->getMessage()==='restore_epoch_changed';}
clockCheck($oldHandleRejected,'pre_init_store_handle_must_not_adopt_completed_restore');
$postInitRequest=['inputtext','301','150','Synthetic post-init inspection'];
correspondenceBeginRequestIngress($postInitRequest);correspondenceCaptureRequestIngress($store);$store->bindRequestClock($postInitRequest);
clockCheck($service->get($doc['document_id'])['state']==='unknown','rollback150 must remove physical receipt200 authority');
$before=(int)clockSql('SELECT count(*) AS n FROM public.eventlog')[0]['n'];clockHook(['correspondence_receipt','200','200',correspondenceJson($receipt)]);clockHook(['inputtext','220','220','Dragonborn: Old',$snapshot]);clockHook(['correspondence_receipt','300','200',correspondenceJson($receipt)]);clockHook(['inputtext','300','220','Dragonborn: Same-clock stale',$snapshot]);
clockCheck((int)clockSql('SELECT count(*) AS n FROM public.eventlog')[0]['n']===$before&&$service->get($doc['document_id'])['state']==='unknown','old in-flight receipt/presence must not republish after init300');
// Losing the optional session fence must preserve core init and durable quarantine.
$clockFenceBlocker=pg_connect($clockDsn,PGSQL_CONNECT_FORCE_NEW);clockCheck($clockFenceBlocker!==false,'fence_failure_peer_required');
clockCheck(pg_query($clockFenceBlocker,'SELECT pg_advisory_lock(18459814,1)')!==false,'fence_failure_lock_required');
$GLOBALS['MUST_END']=false;
clockHook(['init','400','150','synthetic fence failure']);
clockCheck(pg_query($clockFenceBlocker,'SELECT pg_advisory_unlock(18459814,1)')!==false,'fence_failure_unlock_required');pg_close($clockFenceBlocker);
clockSql("INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(400,150,'init','fixture','pending',1,'','','')");
$GLOBALS['MUST_END']=true;
clockCheck(!correspondenceCompletePendingInit(),'failed_fence_must_not_clear_restore_quarantine');
$GLOBALS['MUST_END']=false;
clockHook(['init','450','150','synthetic aborted restore']);
$GLOBALS['pgr_skip_rollback']=true;$GLOBALS['MUST_END']=true;
clockCheck(!correspondenceCompletePendingInit(),'aborted_core_restore_must_not_clear_quarantine_as_noop');
$GLOBALS['pgr_skip_rollback']=false;$GLOBALS['MUST_END']=false;
clockHook(['init','460','150','synthetic core SQL failure']);
clockSql("INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(460,150,'init','fixture','pending',1,'','','')");
$GLOBALS['pgr_sql_failed']=true;$GLOBALS['MUST_END']=true;
clockCheck(!correspondenceCompletePendingInit(),'failed_core_sql_with_marker_and_end_must_remain_quarantined');
$GLOBALS['pgr_sql_failed']=false;
$GLOBALS['MUST_END']=false;
clockHook(['init','500','150','synthetic successful recovery init']);
clockSql("INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(500,150,'init','fixture','pending',1,'','','')");
$GLOBALS['MUST_END']=true;
clockCheck(correspondenceCompletePendingInit(),'next_successful_init_must_recover_failed_fence_quarantine');
$runtime=correspondenceProduction();try{$runtime['store']->bindRequestClock(['inputtext','not-a-clock','250']);throw new RuntimeException('invalid clock accepted');}catch(InvalidArgumentException){}
echo "PASS native correspondence clocks: actual receipt/context/presence anchors, core rollback predicate, two-connection init fence and stale request rejection\n";
pg_close($c);

