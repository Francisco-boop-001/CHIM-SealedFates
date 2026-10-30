<?php
declare(strict_types=1);

// Only a new, identity-pinned manual-delivery test database is allowed.
function manualDeliveryCheck(bool $ok,string $reason):void{if(!$ok)throw new RuntimeException($reason);}
function manualDeliverySql(mixed $db,string $sql,array $args=[]):array
{
    $result=$args===[]?pg_query($db,$sql):pg_query_params($db,$sql,$args);
    manualDeliveryCheck($result!==false,'fixture_sql_failed');
    try{return pg_num_fields($result)>0?(pg_fetch_all($result)?:[]):[];}finally{pg_free_result($result);}
}
function manualDeliveryEnv(string $name):string
{
    $value=getenv('CORRESPONDENCE_MANUAL_'.$name);
    manualDeliveryCheck(is_string($value)&&$value!=='','explicit_fixture_'.$name.'_required');return $value;
}
function manualRequest():array
{
    $n=++$GLOBALS['manual_request_number'];
    return ['request',(string)(1800000000000000000+$n*1000000),(string)(30000000+$n*416667),'synthetic'];
}
function manualCamilla():array{return ['form_id'=>hexdec('00013488'),'name'=>'Camilla','distance'=>10,'managed'=>true,'creature'=>false];}
function manualSnapshot(array $actors=[]):string{return base64_encode(correspondenceJson(['source'=>'plugin_player_routing_v2','present_actors'=>$actors]));}
function manualReset(mixed $db):void
{
    manualDeliverySql($db,'TRUNCATE TABLE plugins.correspondence_documents,plugins.correspondence_operations,plugins.correspondence_presence,public.eventlog,public.responselog,public.books RESTART IDENTITY');
    if((manualDeliverySql($db,"SELECT to_regclass('chim_meta.playthrough_profiles') AS profiles")[0]['profiles']??null)!==null)manualDeliverySql($db,'DELETE FROM chim_meta.playthrough_profiles');
    manualDeliverySql($db,"UPDATE plugins.correspondence_restore_state SET epoch='11111111-1111-4111-8111-111111111111',pending=FALSE,native_ts_floor=0 WHERE singleton IS TRUE");
}
function manualContext(mixed $db,array $nearby=[]):array
{
    manualReset($db);
    $store=new CorrespondencePgStore($db,static fn(CorrespondencePgStore $s):?string=>$s->observedExecutor(),static fn(string $title,string $body):bool=>true);
    $store->ensureSchema();$request=manualRequest();$store->bindRequestClock($request);
    $raw=manualSnapshot($nearby);$store->observeManaged($raw);
    return ['store'=>$store,'service'=>new CorrespondenceService($store),'request'=>$request,'raw'=>$raw,'scope'=>$store->scope()['playthrough_id']];
}
function manualPresence(mixed $db,string $scope='unprofiled'):array
{
    $row=manualDeliverySql($db,'SELECT payload::text FROM plugins.correspondence_presence WHERE playthrough_id=$1',[$scope])[0]??[];
    if(!isset($row['payload']))return [];
    $payload=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
    return is_array($payload)?$payload:[];
}
function manualSavePresence(mixed $db,string $scope,array $payload):void
{
    manualDeliverySql($db,'UPDATE plugins.correspondence_presence SET payload=$2::jsonb WHERE playthrough_id=$1',[$scope,correspondenceJson($payload)]);
}
function manualSeedActivity(mixed $db,CorrespondencePgStore $store,array $request,bool $active,int $receivedAtShift=0,array $overrides=[]):array
{
    $clock=correspondenceRequestClock($request);$scope=$store->scope()['playthrough_id'];$restore=$store->restoreGeneration();
    manualDeliveryCheck(is_string($restore)&&$restore!=='','test_restore_generation_required');
    $record=[
        'playthrough_id'=>$overrides['playthrough_id']??$scope,
        'native_type'=>'request',
        'native_ts'=>$clock['ts'],
        'gamets'=>$clock['gamets'],
        'request_sha256'=>hash('sha256',correspondenceCanonicalJson(['request',$clock['ts'],$clock['gamets']])),
        'interaction_generation'=>isset($GLOBALS['chim_interaction_generation'])&&is_int($GLOBALS['chim_interaction_generation'])?$GLOBALS['chim_interaction_generation']:null,
        'restore_generation'=>$overrides['restore_generation']??$restore,
        'received_at'=>time()+$receivedAtShift,
        'active'=>$active,
    ];
    $anchorData=['kind'=>'manual_delivery_activity']+$record;
    $anchor=$store->transaction(fn()=>$store->appendEvent('correspondence_presence',correspondenceJson($anchorData),'','correspondence_v1'));
    $record['anchor']=$anchor;
    $payload=manualPresence($db,$scope);$payload['activity']=$record;manualSavePresence($db,$scope,$payload);
    return $record;
}
function manualObserveNative(CorrespondencePgStore $store,array $request):array
{
    manualDeliveryCheck(function_exists('correspondenceManualDeliveryObserveNativeRequest'),'native_request_capture_helper_missing');
    return correspondenceManualDeliveryObserveNativeRequest($request,static fn()=>['store'=>$store]);
}
function manualInput(?string $requestId=null,string $body='Five hundred septims for the saved letter.'):array
{
    return ['request_id'=>$requestId??correspondenceUuid(),'author'=>'player','payer'=>'player','recipient'=>'npc:900001','claimed_sender'=>'npc:900001','body'=>$body,'attachments'=>[['kind'=>'gold','form_id'=>'Skyrim.esm|0000000F','quantity'=>500]]];
}
function manualSafeExecutor(CorrespondencePgStore $store):?string
{
    try{return $store->observedExecutor();}catch(Throwable){return null;}
}
function manualWorkerReady(array &$worker):bool
{
    $stdout=$worker['pipes'][1];$stderr=$worker['pipes'][2];stream_set_blocking($stdout,false);stream_set_blocking($stderr,false);
    $out='';$err='';$deadline=microtime(true)+10;
    while(microtime(true)<$deadline){
        $remaining=$deadline-microtime(true);$read=[$stdout,$stderr];$write=null;$except=null;
        $ready=@stream_select($read,$write,$except,(int)$remaining,(int)(($remaining-(int)$remaining)*1000000));
        if($ready===false||$ready===0)break;
        if(in_array($stdout,$read,true))$out.=stream_get_contents($stdout);
        if(in_array($stderr,$read,true))$err.=stream_get_contents($stderr);
        if(str_starts_with($out,"READY\n")){$worker['ready_output']=substr($out,6);$worker['ready_error']=$err;return true;}
        if(feof($stdout))break;
    }
    $worker['ready_error']=$err;return false;
}
function manualWorkerResult(array &$worker):array
{
    $stdout=$worker['pipes'][1];$stderr=$worker['pipes'][2];$out=$worker['ready_output']??'';$err=$worker['ready_error']??'';$deadline=microtime(true)+30;
    while(!feof($stdout)||!feof($stderr)){
        $remaining=$deadline-microtime(true);if($remaining<=0){if(is_resource($worker['process']))proc_terminate($worker['process']);throw new RuntimeException('dispatch_worker_timeout');}
        $read=[];if(!feof($stdout))$read[]=$stdout;if(!feof($stderr))$read[]=$stderr;if($read===[])break;
        $write=null;$except=null;$ready=@stream_select($read,$write,$except,min(1,(int)$remaining),(int)(($remaining-floor($remaining))*1000000));
        if($ready===false)throw new RuntimeException('dispatch_worker_pipe_failed');
        foreach($read as $stream){$chunk=stream_get_contents($stream);if($stream===$stdout)$out.=$chunk;else$err.=$chunk;}
    }
    $exit=proc_close($worker['process']);$worker['process']=null;
    manualDeliveryCheck($exit===0,'dispatch_worker_failed: '.trim($err));
    $result=json_decode(trim($out),true,32,JSON_THROW_ON_ERROR);manualDeliveryCheck(is_array($result),'dispatch_worker_result_invalid');return $result;
}
function manualRunJsonChild(array $command,array $input):array
{
    $pipes=[];$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
    manualDeliveryCheck(is_resource($process),'core_dequeue_child_launch_failed');
    $out='';$err='';$deadline=microtime(true)+30;
    try{
        fwrite($pipes[0],json_encode($input,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n");fclose($pipes[0]);$pipes[0]=null;
        stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
        while(!feof($pipes[1])||!feof($pipes[2])){
            $remaining=$deadline-microtime(true);if($remaining<=0){proc_terminate($process);throw new RuntimeException('core_dequeue_child_timeout');}
            $read=[];if(!feof($pipes[1]))$read[]=$pipes[1];if(!feof($pipes[2]))$read[]=$pipes[2];if($read===[])break;
            $write=null;$except=null;$ready=@stream_select($read,$write,$except,min(1,(int)$remaining),(int)(($remaining-floor($remaining))*1000000));
            if($ready===false)throw new RuntimeException('core_dequeue_child_pipe_failed');
            foreach($read as $stream){$chunk=stream_get_contents($stream);if($stream===$pipes[1])$out.=$chunk;else$err.=$chunk;}
        }
        $exit=proc_close($process);$process=null;manualDeliveryCheck($exit===0,'core_dequeue_child_failed: '.trim($err));
        $result=json_decode(trim($out),true,64,JSON_THROW_ON_ERROR);manualDeliveryCheck(is_array($result),'core_dequeue_child_result_invalid');return ['result'=>$result,'stdout'=>$out,'stderr'=>$err];
    }finally{
        foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);
        if(is_resource($process)){@proc_terminate($process);@proc_close($process);}
    }
}
function manualStopWorkers(array &$workers):void
{
    foreach($workers as &$worker){
        if(isset($worker['pipes'][0])&&is_resource($worker['pipes'][0]))fclose($worker['pipes'][0]);
        if(isset($worker['process'])&&is_resource($worker['process']))@proc_terminate($worker['process']);
        foreach([1,2]as$index)if(isset($worker['pipes'][$index])&&is_resource($worker['pipes'][$index]))fclose($worker['pipes'][$index]);
        if(isset($worker['process'])&&is_resource($worker['process']))@proc_close($worker['process']);$worker['process']=null;
    }unset($worker);
}

$manualCases=['zero_npc_gold_once','native_request_capture','passive_header','interaction_off','replay_fence','heartbeat_coalescing','activity_ttl','authority_rejections','restore_ingress_fail_closed','presence_merge','legacy_route','expired_inactive_fallback','request_conflict','concurrent_dispatch','actual_core_route'];
$manualSelection=null;$manualWorkerId=null;$manualWorkerTs=null;$manualWorkerGamets=null;$manualWorkerGeneration=null;$manualWorkerGenerationSet=false;
foreach(array_slice($argv,1) as $argument){
    if(str_starts_with($argument,'--tests=')){
        manualDeliveryCheck($manualSelection===null,'one_test_selector_allowed');
        $manualSelection=explode(',',substr($argument,8));
        manualDeliveryCheck($manualSelection!==[]&&!in_array('', $manualSelection,true),'nonempty_test_selector_required');
        foreach($manualSelection as $name)manualDeliveryCheck(in_array($name,$manualCases,true),'unknown_test_selector_'.$name);
        manualDeliveryCheck(count(array_unique($manualSelection))===count($manualSelection),'duplicate_test_selector');
    }elseif(str_starts_with($argument,'--dispatch=')){
        manualDeliveryCheck($manualWorkerId===null,'one_dispatch_worker_allowed');$manualWorkerId=substr($argument,11);
    }elseif(str_starts_with($argument,'--worker-ts='))$manualWorkerTs=substr($argument,12);
    elseif(str_starts_with($argument,'--worker-gamets='))$manualWorkerGamets=substr($argument,16);
    elseif(str_starts_with($argument,'--worker-generation=')){
        $manualWorkerGenerationSet=true;$value=substr($argument,20);
        if($value!=='none')$manualWorkerGeneration=(int)$value;
    }else manualDeliveryCheck(false,'unknown_cli_argument');
}
$GLOBALS['manual_request_number']=0;
$manualDefineInteraction=$manualWorkerId!==null
    ?($manualWorkerGenerationSet&&is_int($manualWorkerGeneration))
    :($manualSelection===null||in_array('interaction_off',$manualSelection,true)||in_array('actual_core_route',$manualSelection,true));
if($manualDefineInteraction){
    $GLOBALS['manual_interaction_enabled']=true;
    $GLOBALS['chim_interaction_generation']=$manualWorkerGeneration??7;
    function chimInteractionAllowed():bool{return ($GLOBALS['manual_interaction_enabled']??false)===true;}
}

manualDeliveryCheck(PHP_SAPI==='cli'&&extension_loaded('pgsql'),'cli_pgsql_required');
manualDeliveryCheck(getenv('WSL_DISTRO_NAME')==='DwemerAI4Skyrim3-test2','owned_test2_only');
foreach(getenv() as $key=>$_)manualDeliveryCheck(!str_starts_with(strtoupper((string)$key),'PG'),'ambient_postgres_environment_forbidden');
$dsn=manualDeliveryEnv('DSN');$database=manualDeliveryEnv('DATABASE');$role=manualDeliveryEnv('ROLE');
$system=manualDeliveryEnv('SYSTEM_ID');$directory=manualDeliveryEnv('DATA_DIRECTORY');$port=manualDeliveryEnv('PORT');
manualDeliveryCheck(preg_match('/\Asf_manual_[a-f0-9]{12}\z/D',$database)===1&&$role===$database,'owned_database_and_role_required');
manualDeliveryCheck(preg_match('#\A/var/tmp/sf-manual-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{8}/postgres/data\z#D',$directory)===1,'owned_data_directory_required');
$socket=dirname(dirname($directory)).'/socket';
manualDeliveryCheck(str_contains($dsn,'host='.$socket.' '),'owned_socket_dsn_required');
manualDeliveryCheck($port==='5432','owned_test2_port_required');
$db=@pg_connect($dsn,PGSQL_CONNECT_FORCE_NEW);
manualDeliveryCheck($db instanceof PgSql\Connection,'owned_database_connection_required');
$identity=manualDeliverySql($db,"SELECT current_database() db,current_user role,current_setting('data_directory') dir,current_setting('port') port,current_setting('listen_addresses') listeners,current_setting('unix_socket_directories') sockets,(inet_server_addr() IS NULL)::int socket_only,system_identifier::text sid FROM pg_control_system()")[0]??[];
manualDeliveryCheck($identity===['db'=>$database,'role'=>$role,'dir'=>$directory,'port'=>$port,'listeners'=>'','sockets'=>$socket,'socket_only'=>'1','sid'=>$system],'fixture_identity_mismatch');

$root=dirname(__DIR__);require_once $root.'/server/lib/bootstrap.php';
if($manualWorkerId!==null){
    manualDeliveryCheck(preg_match('/\A[0-9a-fA-F-]{36}\z/D',$manualWorkerId)===1&&is_string($manualWorkerTs)&&preg_match('/\A[1-9][0-9]{0,18}\z/D',$manualWorkerTs)===1&&is_string($manualWorkerGamets)&&preg_match('/\A[1-9][0-9]{0,18}\z/D',$manualWorkerGamets)===1,'valid_dispatch_worker_args_required');
    $store=new CorrespondencePgStore($db,static fn(CorrespondencePgStore $s):?string=>$s->observedExecutor(),static fn(string $title,string $body):bool=>true);
    $store->ensureSchema();$store->bindRequestClock(['request',$manualWorkerTs,$manualWorkerGamets,'worker']);
    fwrite(STDOUT,"READY\n");if(trim((string)fgets(STDIN))!=='GO')exit(2);
    echo json_encode((new CorrespondenceService($store))->dispatch($manualWorkerId),JSON_THROW_ON_ERROR)."\n";pg_close($db);exit(0);
}
manualDeliveryCheck((manualDeliverySql($db,"SELECT count(*)::int n FROM information_schema.tables WHERE table_schema IN ('public','plugins','chim_meta')")[0]['n']??null)==='0','fresh_database_required');
manualDeliverySql($db,<<<'SQL'
CREATE TABLE public.eventlog(rowid bigserial PRIMARY KEY,ts bigint NOT NULL,gamets numeric NOT NULL,type text NOT NULL,data text NOT NULL,sess text NOT NULL,localts bigint NOT NULL,people text NOT NULL,location text NOT NULL,party text NOT NULL);
CREATE TABLE public.core_npc_master(id bigint PRIMARY KEY,npc_name text,refid text,profile_id bigint,personality text,extended_data jsonb,metadata jsonb);
CREATE TABLE public.core_player(id text PRIMARY KEY,value text);
CREATE TABLE public.game_plugins(plugin_name text,formid_prefix text);
CREATE TABLE public.books(id bigserial PRIMARY KEY,ts bigint,gamets numeric,content text,sess text,localts bigint,title text);
CREATE TABLE public.responselog(id bigserial PRIMARY KEY,rowid bigint GENERATED ALWAYS AS (id) STORED,localts bigint,sent integer,actor text,text text,action text,tag text,interaction_generation integer NOT NULL DEFAULT 0);
INSERT INTO public.core_player VALUES('player_name','Dragonborn');
INSERT INTO public.game_plugins VALUES('Skyrim.esm','00');
INSERT INTO public.core_npc_master VALUES(900001,'Camilla','00013488',1,'A private correspondent.','{}','{}');
SQL);
$schemaStore=new CorrespondencePgStore($db,static fn(CorrespondencePgStore $s):?string=>$s->observedExecutor(),static fn(string $title,string $body):bool=>true);$schemaStore->ensureSchema();
$selected=$manualSelection??$manualCases;
$completed=[];$caseEvidence=[];

foreach($selected as $case){
    if($case==='zero_npc_gold_once'){
        $ctx=manualContext($db);$proof=manualSeedActivity($db,$ctx['store'],$ctx['request'],true);
        $document=$ctx['service']->compose(manualInput());
        $sent=$ctx['service']->dispatch($document['document_id']);
        manualDeliveryCheck(($sent['state']??null)==='dispatched','active_native_request_with_zero_nearby_actors_dispatches_500_gold_once; got '.($sent['state']??'missing_state'));
        $operation=$ctx['store']->loadOperation($sent['operation_id']);
        manualDeliveryCheck(is_array($operation)&&($operation['attachments'][0]['quantity']??null)===500&&($operation['receipt']??null)===null,'saved_operation_keeps_500_gold_without_claiming_receipt');
        $again=$ctx['service']->dispatch($document['document_id']);
        manualDeliveryCheck(($again['operation_id']??null)===$sent['operation_id'],'repeat_dispatch_reuses_saved_operation');
        manualDeliveryCheck((manualDeliverySql($db,'SELECT count(*)::int n FROM plugins.correspondence_operations WHERE document_id=$1::uuid',[$document['document_id']])[0]['n']??null)==='1','one_physical_operation');
        $commands=manualDeliverySql($db,"SELECT actor,text FROM public.responselog WHERE action='command' AND text LIKE 'ExtCmdCHIMCorrespondenceBridge_Deliver@%' AND text LIKE $1",['%'.$document['document_id'].'%']);
        manualDeliveryCheck(count($commands)===1&&$commands[0]['actor']==='The Narrator','one_native_narrator_delivery_command');
        $payload=json_decode(substr($commands[0]['text'],strpos($commands[0]['text'],'@')+1),true,32,JSON_THROW_ON_ERROR);
        manualDeliveryCheck(($payload['attachments'][0]['quantity']??null)===500,'native_command_keeps_exact_500_gold');
        manualDeliveryCheck((manualDeliverySql($db,"SELECT count(*)::int n FROM public.eventlog WHERE type='info' AND sess='written_evidence_v1'")[0]['n']??null)==='0','physical_dispatch_does_not_claim_exposure');
        manualDeliveryCheck($proof['active']===true,'test_seeded_fresh_active_proof');
    }elseif($case==='native_request_capture'){
        $ctx=manualContext($db);$factoryCalls=0;$bad=manualRequest();$bad[0]='bored';
        correspondenceManualDeliveryObserveNativeRequest($bad,static function()use(&$factoryCalls,$ctx):array{$factoryCalls++;return ['store'=>$ctx['store']];});
        manualDeliveryCheck($factoryCalls===0&&!isset(manualPresence($db,$ctx['scope'])['activity']),'non_request_native_events_do_not_create_activity');
        $request=manualRequest();manualObserveNative($ctx['store'],$request);$presence=manualPresence($db,$ctx['scope']);$activity=$presence['activity']??[];
        $fingerprint=hash('sha256',correspondenceCanonicalJson(['request',$request[1],$request[2]]));
        manualDeliveryCheck(($activity['native_type']??null)==='request'&&($activity['native_ts']??null)===$request[1]&&($activity['request_sha256']??null)===$fingerprint,'request_activity_has_reconstructible_native_fingerprint');
        manualDeliveryCheck(($presence['raw']??null)===$ctx['raw']&&$ctx['store']->observedExecutor()==='The Narrator','request_activity_authorizes_narrator_without_nearby_actor');
    }elseif($case==='passive_header'){
        $ctx=manualContext($db,[manualCamilla()]);$before=manualPresence($db,$ctx['scope']);
        manualDeliveryCheck($ctx['store']->observedExecutor()==='Camilla','fresh_managed_actor_is_legacy_executor');
        if($manualSelection===['passive_header'])manualDeliveryCheck(!function_exists('chimInteractionAllowed'),'passive_header_guard_runs_without_host_helper');
        $_SERVER['HTTP_X_CHIM_PASSIVE']='1';
        try{manualObserveNative($ctx['store'],manualRequest());}finally{unset($_SERVER['HTTP_X_CHIM_PASSIVE']);}
        $after=manualPresence($db,$ctx['scope']);
        manualDeliveryCheck(($after['activity']['active']??null)===false&&$ctx['store']->observedExecutor()===null,'current_passive_proof_overrides_fresh_managed_snapshot');
        manualDeliveryCheck(($after['raw']??null)===$before['raw']&&($after['observed_at']??null)===$before['observed_at'],'inactive_activity_preserves_managed_snapshot');
    }elseif($case==='interaction_off'){
        manualDeliveryCheck(function_exists('chimInteractionAllowed'),'host_interaction_guard_fixture_required');
        $ctx=manualContext($db,[manualCamilla()]);$GLOBALS['manual_interaction_enabled']=false;
        try{manualObserveNative($ctx['store'],manualRequest());}finally{$GLOBALS['manual_interaction_enabled']=true;}
        $activity=manualPresence($db,$ctx['scope'])['activity']??[];
        manualDeliveryCheck(($activity['active']??null)===false&&$ctx['store']->observedExecutor()===null,'interaction_off_stores_inactive_proof_and_suppresses_old_actor');
    }elseif($case==='replay_fence'){
        $ctx=manualContext($db);$proof=manualSeedActivity($db,$ctx['store'],$ctx['request'],true,-46);$saved=manualPresence($db,$ctx['scope'])['activity'];
        manualObserveNative($ctx['store'],$ctx['request']);
        $older=$ctx['request'];$older[1]=(string)((int)$older[1]-1);manualObserveNative($ctx['store'],$older);
        $after=manualPresence($db,$ctx['scope'])['activity'];
        manualDeliveryCheck($after['native_ts']===$saved['native_ts']&&$after['received_at']===$saved['received_at']&&$after['anchor']===$saved['anchor'],'equal_and_older_native_counters_do_not_renew_ttl_or_anchor');
        manualDeliveryCheck($ctx['store']->observedExecutor()===null,'expired_replayed_activity_stays_expired');
        $fresh=manualRequest();manualObserveNative($ctx['store'],$fresh);$renewed=manualPresence($db,$ctx['scope'])['activity'];
        manualDeliveryCheck($renewed['native_ts']===$fresh[1]&&$renewed['received_at']>=time()-1&&$ctx['store']->observedExecutor()==='The Narrator','strictly_newer_native_counter_accepts_fresh_activity');
        manualDeliveryCheck($proof['anchor']===$saved['anchor'],'seed_anchor_unchanged');
    }elseif($case==='heartbeat_coalescing'){
        $ctx=manualContext($db);manualSeedActivity($db,$ctx['store'],$ctx['request'],true);
        $before=manualPresence($db,$ctx['scope'])['activity'];$countBefore=manualDeliverySql($db,"SELECT count(*)::int n FROM public.eventlog WHERE type='info' AND sess='correspondence_v1'")[0]['n']??null;
        for($i=0;$i<6;$i++)manualObserveNative($ctx['store'],manualRequest());
        $after=manualPresence($db,$ctx['scope'])['activity'];$countAfter=manualDeliverySql($db,"SELECT count(*)::int n FROM public.eventlog WHERE type='info' AND sess='correspondence_v1'")[0]['n']??null;
        manualDeliveryCheck($after===$before&&$countAfter===$countBefore,'newer_sub15_second_heartbeats_are_skipped_without_checkpoint_or_ttl_renewal');
        $due=manualContext($db);manualSeedActivity($db,$due['store'],$due['request'],true,-15);$dueBefore=manualPresence($db,$due['scope'])['activity'];
        $next=manualRequest();manualObserveNative($due['store'],$next);$dueAfter=manualPresence($db,$due['scope'])['activity'];
        manualDeliveryCheck(($dueAfter['native_ts']??null)===$next[1]&&($dueAfter['received_at']??null)>($dueBefore['received_at']??PHP_INT_MAX)&&($dueAfter['anchor']??null)!==($dueBefore['anchor']??null),'new_counter_after_15_seconds_creates_a_new_anchor');
    }elseif($case==='activity_ttl'){
        $fresh=manualContext($db);manualSeedActivity($db,$fresh['store'],$fresh['request'],true,-44);
        manualDeliveryCheck($fresh['store']->observedExecutor()==='The Narrator','server_receipt_inside_45_seconds_is_fresh');
        $future=manualContext($db);manualSeedActivity($db,$future['store'],$future['request'],true,1);
        manualDeliveryCheck(manualSafeExecutor($future['store'])===null,'future_server_receipt_is_rejected');
        $expired=manualContext($db);manualSeedActivity($db,$expired['store'],$expired['request'],true,-46);
        manualDeliveryCheck(manualSafeExecutor($expired['store'])===null,'server_receipt_older_than_45_seconds_is_rejected');
    }elseif($case==='authority_rejections'){
        $corrupt=manualContext($db);manualSeedActivity($db,$corrupt['store'],$corrupt['request'],true);
        $payload=manualPresence($db,$corrupt['scope']);$payload['activity']['request_sha256']=str_repeat('0',64);manualSavePresence($db,$corrupt['scope'],$payload);
        manualDeliveryCheck(manualSafeExecutor($corrupt['store'])===null,'corrupt_request_fingerprint_cannot_authorize_narrator');
        $scope=manualContext($db);manualSeedActivity($db,$scope['store'],$scope['request'],true,0,['playthrough_id'=>'another-scope']);
        manualDeliveryCheck(manualSafeExecutor($scope['store'])===null,'wrong_playthrough_proof_cannot_authorize_narrator');
        $epoch=manualContext($db);manualSeedActivity($db,$epoch['store'],$epoch['request'],true);
        manualDeliverySql($db,"UPDATE plugins.correspondence_restore_state SET epoch='22222222-2222-4222-8222-222222222222' WHERE singleton IS TRUE");
        $epoch['store']->bindRequestClock(manualRequest());
        manualDeliveryCheck(manualSafeExecutor($epoch['store'])===null,'prior_restore_generation_proof_cannot_authorize_narrator');
        $floor=manualContext($db);$floorProof=manualSeedActivity($db,$floor['store'],$floor['request'],true);
        manualDeliverySql($db,'UPDATE plugins.correspondence_restore_state SET native_ts_floor=$1::bigint WHERE singleton IS TRUE',[$floorProof['native_ts']]);
        $floor['store']->bindRequestClock(manualRequest());
        manualDeliveryCheck(manualSafeExecutor($floor['store'])===null,'activity_at_or_below_native_restore_floor_is_rejected');
        $anchor=manualContext($db);$anchorProof=manualSeedActivity($db,$anchor['store'],$anchor['request'],true);
        manualDeliverySql($db,'DELETE FROM public.eventlog WHERE rowid=$1::bigint',[(string)$anchorProof['anchor']['rowid']]);
        manualDeliveryCheck(manualSafeExecutor($anchor['store'])===null,'missing_owned_activity_anchor_cannot_authorize_narrator');
    }elseif($case==='restore_ingress_fail_closed'){
        $nullToken=manualContext($db);$prior=manualSeedActivity($db,$nullToken['store'],$nullToken['request'],true);$incoming=manualRequest();
        $GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_CAPTURED']=true;$GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_TOKEN']=null;$GLOBALS['CORRESPONDENCE_REQUEST_NATIVE_TS']=$incoming[1];
        try{manualObserveNative($nullToken['store'],$incoming);}finally{unset($GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_CAPTURED'],$GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_TOKEN'],$GLOBALS['CORRESPONDENCE_REQUEST_NATIVE_TS']);}
        manualDeliveryCheck(manualPresence($db,$nullToken['scope'])['activity']['anchor']===$prior['anchor'],'captured_null_restore_token_cannot_replace_proof');
        manualDeliveryCheck(manualSafeExecutor($nullToken['store'])===null,'captured_null_restore_token_fails_closed');
        $pending=manualContext($db);$document=$pending['service']->compose(manualInput());
        manualSeedActivity($db,$pending['store'],$pending['request'],true);
        manualDeliverySql($db,'UPDATE plugins.correspondence_restore_state SET pending=TRUE WHERE singleton IS TRUE');
        manualDeliveryCheck(manualSafeExecutor($pending['store'])===null,'restore_pending_proof_cannot_authorize_narrator');
        $refused=false;try{$pending['service']->dispatch($document['document_id']);}catch(DomainException){$refused=true;}
        manualDeliveryCheck($refused&&(manualDeliverySql($db,'SELECT count(*)::int n FROM plugins.correspondence_operations WHERE document_id=$1::uuid',[$document['document_id']])[0]['n']??null)==='0','restore_pending_dispatch_creates_no_operation');
    }elseif($case==='presence_merge'){
        $ctx=manualContext($db);$fresh=manualRequest();manualObserveNative($ctx['store'],$fresh);
        $activityBefore=manualPresence($db,$ctx['scope'])['activity']??[];$snapshot=manualSnapshot([manualCamilla()]);
        $next=manualRequest();$ctx['store']->bindRequestClock($next);$ctx['store']->observeManaged($snapshot);
        $afterSnapshot=manualPresence($db,$ctx['scope']);
        manualDeliveryCheck(($afterSnapshot['raw']??null)===$snapshot&&($afterSnapshot['activity']['anchor']??null)===($activityBefore['anchor']??null)&&$ctx['store']->observedExecutor()==='The Narrator','managed_snapshot_write_preserves_valid_native_activity');
        $newest=manualRequest();manualObserveNative($ctx['store'],$newest);$afterActivity=manualPresence($db,$ctx['scope']);
        manualDeliveryCheck(($afterActivity['raw']??null)===$snapshot&&($afterActivity['activity']['native_ts']??null)===$newest[1],'activity_write_preserves_latest_managed_snapshot');
    }elseif($case==='legacy_route'){
        $ctx=manualContext($db,[manualCamilla()]);
        manualDeliveryCheck($ctx['store']->observedExecutor()==='Camilla','legacy_managed_executor_unchanged_without_activity');
        $document=$ctx['service']->compose(manualInput());$sent=$ctx['service']->dispatch($document['document_id']);
        $command=manualDeliverySql($db,"SELECT actor FROM public.responselog WHERE action='command' AND text LIKE 'ExtCmdCHIMCorrespondenceBridge_Deliver@%' AND text LIKE $1",['%'.$document['document_id'].'%']);
        manualDeliveryCheck(($sent['state']??null)==='dispatched'&&count($command)===1&&$command[0]['actor']==='Camilla','legacy_managed_actor_still_carries_manual_delivery');
        $empty=manualContext($db);$rogue=new CorrespondencePgStore($db,static fn():string=>'The Narrator',static fn(string $title,string $body):bool=>true);
        $rogue->ensureSchema();$rogue->bindRequestClock($empty['request']);
        manualDeliveryCheck($rogue->managedExecutor()===null,'resolver_cannot_select_narrator_without_validated_activity');
    }elseif($case==='expired_inactive_fallback'){
        $ctx=manualContext($db);manualSeedActivity($db,$ctx['store'],$ctx['request'],false,-46);
        $freshSnapshot=manualSnapshot([manualCamilla()]);$newRequest=manualRequest();$ctx['store']->bindRequestClock($newRequest);$ctx['store']->observeManaged($freshSnapshot);
        manualDeliveryCheck($ctx['store']->observedExecutor()==='Camilla','expired_inactive_proof_allows_only_fresh_legacy_snapshot_fallback');
    }elseif($case==='request_conflict'){
        $ctx=manualContext($db);manualSeedActivity($db,$ctx['store'],$ctx['request'],true);
        $requestId=correspondenceUuid();$input=manualInput($requestId);$document=$ctx['service']->compose($input);$sent=$ctx['service']->dispatch($document['document_id']);
        $input['body']='Changed after save; this must not replace the dispatched letter.';$conflict=false;
        try{$ctx['service']->compose($input);}catch(DomainException $error){$conflict=$error->getMessage()==='request_conflict';}
        manualDeliveryCheck($conflict&&($ctx['service']->get($document['document_id'])['operation_id']??null)===$sent['operation_id'],'changed_request_body_cannot_replace_saved_dispatched_letter');
        manualDeliveryCheck((manualDeliverySql($db,'SELECT count(*)::int n FROM plugins.correspondence_operations WHERE document_id=$1::uuid',[$document['document_id']])[0]['n']??null)==='1','changed_body_does_not_create_second_operation');
    }elseif($case==='concurrent_dispatch'){
        $ctx=manualContext($db);manualSeedActivity($db,$ctx['store'],$ctx['request'],true);
        $document=$ctx['service']->compose(manualInput());$workers=[];
        $activity=manualPresence($db,$ctx['scope'])['activity']??[];$generation=$activity['interaction_generation']??null;
        try{
            for($i=0;$i<2;$i++){
                $request=manualRequest();$command=[PHP_BINARY,'-n','-d','extension=ctype','-d','extension=mbstring','-d','extension=tokenizer','-d','extension=pgsql',__FILE__,'--dispatch='.$document['document_id'],'--worker-ts='.$request[1],'--worker-gamets='.$request[2],'--worker-generation='.(is_int($generation)?(string)$generation:'none')];
                $pipes=[];$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
                manualDeliveryCheck(is_resource($process),'dispatch_worker_launch_failed');$workers[]=['process'=>$process,'pipes'=>$pipes];
                manualDeliveryCheck(manualWorkerReady($workers[count($workers)-1]),'dispatch_worker_not_ready: '.trim((string)($workers[count($workers)-1]['ready_error']??'')));
            }
            foreach($workers as &$worker){fwrite($worker['pipes'][0],"GO\n");fclose($worker['pipes'][0]);$worker['pipes'][0]=null;}unset($worker);
            $results=[];foreach($workers as &$worker)$results[]=manualWorkerResult($worker);unset($worker);
        }finally{
            manualStopWorkers($workers);
        }
        manualDeliveryCheck(count($results)===2&&is_string($results[0]['operation_id']??null)&&$results[0]['operation_id']===$results[1]['operation_id'],'concurrent_dispatches_share_one_operation');
        manualDeliveryCheck((manualDeliverySql($db,'SELECT count(*)::int n FROM plugins.correspondence_operations WHERE document_id=$1::uuid',[$document['document_id']])[0]['n']??null)==='1','concurrent_dispatch_creates_one_operation');
        manualDeliveryCheck((manualDeliverySql($db,"SELECT count(*)::int n FROM public.responselog WHERE action='command' AND text LIKE 'ExtCmdCHIMCorrespondenceBridge_Deliver@%' AND text LIKE $1",['%'.$document['document_id'].'%'])[0]['n']??null)==='1','concurrent_dispatch_queues_one_native_command');
    }elseif($case==='actual_core_route'){
        $ctx=manualContext($db);$native=manualRequest();manualObserveNative($ctx['store'],$native);$proof=manualPresence($db,$ctx['scope'])['activity']??[];
        $document=$ctx['service']->compose(manualInput());$sent=$ctx['service']->dispatch($document['document_id']);
        manualDeliveryCheck(($sent['state']??null)==='dispatched'&&is_int($proof['interaction_generation']??null),'real_core_route_uses_fresh_active_request_proof');
        $command=[PHP_BINARY,'-n','-d','extension=pgsql','-d','extension=tokenizer','-d','extension=ctype','-d','extension=mbstring',__DIR__.'/support/manual_core_dequeue.php'];
        $child=manualRunJsonChild($command,['document_id'=>$document['document_id'],'expected_actor'=>'The Narrator','interaction_generation'=>$proof['interaction_generation']]);
        $core=$child['result'];
        manualDeliveryCheck(($core['document_id']??null)===$document['document_id']&&($core['operation_id']??null)===$sent['operation_id']&&count($core['lines']??[])===2&&($core['second_dequeue']??null)===[]&&($core['marks']['native_game']??null)===false,'actual_core_dequeue_proves_renderer_and_narrator_command_once');
        $caseEvidence[$case]=['stdout'=>$child['stdout'],'stderr'=>$child['stderr'],'result'=>$core];
    }
    $completed[]=$case;echo $case," PASS\n";
}
echo 'server_manual_delivery_pg: ',json_encode(['selected'=>$selected,'completed'=>$completed,'evidence'=>$caseEvidence],JSON_THROW_ON_ERROR)," (owned synthetic PostgreSQL; no game/provider calls)\n";pg_close($db);
