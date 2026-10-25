<?php
declare(strict_types=1);

function companionPgCheck(bool $ok,string $reason):void { if(!$ok)throw new RuntimeException($reason); }
function companionPgSql(PgSql\Connection $db,string $sql,array $args=[]):array
{
    $result=$args===[]?pg_query($db,$sql):pg_query_params($db,$sql,$args);
    companionPgCheck($result instanceof PgSql\Result,'fixture_sql_failed: '.pg_last_error($db));
    return pg_num_fields($result)>0?(pg_fetch_all($result)?:[]):[];
}
function companionPgReject(callable $work,string $why):void
{
    try{$work();}catch(DomainException|InvalidArgumentException){return;}
    throw new RuntimeException($why);
}

companionPgCheck(PHP_SAPI==='cli'&&extension_loaded('pgsql'),'cli_pgsql_required');
companionPgCheck(getenv('WSL_DISTRO_NAME')==='DwemerAI4Skyrim3-test2','owned_test2_only');
foreach(getenv() as $name=>$_)companionPgCheck(!str_starts_with(strtoupper((string)$name),'PG'),'postgres_environment_forbidden');
$dsn='host=/var/tmp/chim-sf-companion-20261024/socket port=55474 dbname=chim_sf_companion_feature user=postgres connect_timeout=3';
companionPgCheck(getenv('CORRESPONDENCE_COMPANION_TEST_DSN')===$dsn,'explicit_owned_dsn_required');
companionPgCheck(getenv('CORRESPONDENCE_COMPANION_TEST_SYSTEM_ID')==='7700219783501103530','explicit_system_id_required');
$db=pg_connect($dsn.' application_name=sf_companion_pg',PGSQL_CONNECT_FORCE_NEW);
companionPgCheck($db instanceof PgSql\Connection,'owned_peer_database_required');
$identity=companionPgSql($db,"SELECT current_database() db,current_user role,current_setting('data_directory') dir,current_setting('listen_addresses') listeners,current_setting('correspondence.disposable_marker',true) marker,(inet_server_addr() IS NULL)::int socket_only")[0]??[];
companionPgCheck($identity===['db'=>'chim_sf_companion_feature','role'=>'postgres','dir'=>'/var/tmp/chim-sf-companion-20261024/pgdata','listeners'=>'','marker'=>'SealedFates-companion-20261024','socket_only'=>'1'],'private_fixture_identity_required');
$system=companionPgSql($db,'SELECT system_identifier::text AS system_id FROM pg_control_system()')[0]['system_id']??null;
companionPgCheck($system===getenv('CORRESPONDENCE_COMPANION_TEST_SYSTEM_ID'),'system_id_mismatch');
$blank=companionPgSql($db,"SELECT count(*)::int AS amount FROM information_schema.tables WHERE table_schema IN ('public','plugins','chim_meta')")[0]['amount']??null;
companionPgCheck($blank==='0','blank_owned_database_required');
$peer=pg_connect($dsn.' application_name=sf_companion_peer',PGSQL_CONNECT_FORCE_NEW);
companionPgCheck($peer instanceof PgSql\Connection,'second_owned_session_required');
companionPgCheck((companionPgSql($peer,"SELECT current_setting('correspondence.disposable_marker',true) AS marker,(inet_server_addr() IS NULL)::int AS socket_only")[0]??[])===['marker'=>'SealedFates-companion-20261024','socket_only'=>'1'],'second_session_identity_required');

$root=dirname(__DIR__,2);
require_once $root.'/server/lib/store.php';
require_once $root.'/server/lib/boundaries.php';
require_once $root.'/server/lib/autonomy_policy.php';
require_once $root.'/server/lib/autonomy_store.php';
require_once $root.'/server/lib/companion.php';

companionPgSql($db,<<<'SQL'
CREATE TABLE public.eventlog(rowid bigserial PRIMARY KEY,ts bigint,gamets numeric,type text,data text,sess text,localts bigint,people text,location text,party text);
CREATE TABLE public.core_npc_master(id bigint PRIMARY KEY,npc_name text,refid text,profile_id bigint,personality text,extended_data jsonb,metadata jsonb);
CREATE TABLE public.core_player(id text PRIMARY KEY,value text);
CREATE TABLE public.game_plugins(plugin_name text,formid_prefix text);
CREATE TABLE public.books(id bigserial PRIMARY KEY,ts bigint,gamets numeric,content text,sess text,localts bigint,title text);
CREATE TABLE public.responselog(id bigserial PRIMARY KEY,localts bigint,sent integer,actor text,text text,action text,tag text);
CREATE TABLE public.bgl_history(npc text,ts bigint,gamets numeric,localts bigint,data text,category text);
INSERT INTO public.core_player VALUES('player_name','Dragonborn');
INSERT INTO public.game_plugins VALUES('Skyrim.esm','00');
INSERT INTO public.core_npc_master VALUES(900001,'Camilla','00013488',1,'Warm and independent.','{"background_life_enabled":true,"background_life_letters":true,"relationships":{"Dragonborn":{"aff":25}}}','{"stats":{"is_dead":false}}');
INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(100,30000000,'inputtext','synthetic','','100','','','');
SQL);
$store=new CorrespondencePgStore($db,static fn($s)=>$s->observedExecutor(),static fn($title,$body)=>true);
$store->ensureSchema();$service=new CorrespondenceService($store);
$autonomy=new CorrespondenceAutonomyPg($store,$service);$autonomy->ensureSchema();$autonomy->setEnabled(true);
$companion=new CorrespondenceCompanionPg($store,$service);$companion->ensureSchema();
$peerStore=new CorrespondencePgStore($peer,static fn($s)=>$s->observedExecutor(),static fn($title,$body)=>true);
$peerStore->ensureSchema();$peerService=new CorrespondenceService($peerStore);$peerCompanion=new CorrespondenceCompanionPg($peerStore,$peerService);$peerCompanion->ensureSchema();
$presence=base64_encode(correspondenceJson(['source'=>'plugin_player_routing_v2','present_actors'=>[['form_id'=>hexdec('00013488'),'name'=>'Camilla','distance'=>10,'managed'=>true,'creature'=>false]]]));
if(getenv('CORRESPONDENCE_COMPANION_RECOVERY_CASE')!==false){
    require __DIR__.'/companion_recovery_cases.php';
    exit;
}
if(getenv('CORRESPONDENCE_COMPANION_IDLE_MODE')==='1'){
    require __DIR__.'/companion_idle_cases.php';
    exit;
}
if(getenv('CORRESPONDENCE_COMPANION_REAL_MODE')==='1'){
    require __DIR__.'/companion_real_cases.php';
    exit;
}
$episode=null;$first=null;$document=null;$calls=0;
for($hour=0;$hour<=84;$hour++){
    $gamets=30000000+$hour*416667;$ts=100+$hour*2;
    $native=['inputtext',(string)$ts,(string)$gamets,'synthetic',$presence];
    $store->bindRequestClock($native);$store->observeManaged($presence);
    $poll=$companion->poll();companionPgCheck($poll['status']==='queued','hourly poll must queue');
    $action=companionPgSql($db,"SELECT concat(action,chr(124),text) AS action FROM public.responselog WHERE concat(action,chr(124),text) LIKE 'command|ExtCmdCHIMCorrespondenceCompanion_Observe@%' ORDER BY id DESC LIMIT 1")[0]['action']??'';
    $issued=json_decode(substr($action,strpos($action,'@')+1),true,16,JSON_THROW_ON_ERROR);
    companionPgCheck($issued['observation_id']===$poll['observation_id']&&count($issued['actors'])===1,'poll correlation and bounded batch');
    $minute=correspondenceCompanionGameMinute($gamets);
    $episode??=$issued['observation_id'];$first??=$minute;
    $reply=$issued;$reply['actors']=[['id'=>'900001','refid'=>$issued['actors'][0]['refid'],'state'=>'waiting','episode'=>$episode,'first_game_minute'=>$first,'game_minute'=>$minute]];
    $receipt=['correspondence_companion',(string)($ts+1),(string)$gamets,correspondenceJson($reply)];
    $result=$companion->receive($receipt,$reply);companionPgCheck($result['status']==='processed','correlated receipt accepted');
    companionPgReject(fn()=>$companion->receive($receipt,$reply),'replay accepted');
    if($hour===47)companionPgCheck($companion->tick(static function()use(&$calls):string{$calls++;return '{"action":"letter","recipient":"player","body":"I hope you are safe."}';})['status']==='idle','before 48h no model decision');
    if($hour===48){
        companionPgCheck($companion->tick(null)['status']==='idle'&&$calls===0,'no model leaves episode unattempted');
        $peerStore->bindRequestClock($receipt);
        $decision=$companion->tick(static function()use(&$calls,$peerCompanion):string{
            $calls++;
            $racerCalls=0;$racer=$peerCompanion->tick(static function()use(&$racerCalls):string{$racerCalls++;return '{"action":"letter","recipient":"player","body":"Duplicate."}';});
            companionPgCheck($racer['status']==='idle'&&$racerCalls===0,'second PostgreSQL session cannot claim same episode');
            return '{"action":"letter","recipient":"player","body":"I hope you are safe."}';
        });
        companionPgCheck($decision['status']==='queued'&&$calls===1,'48h gives one queued letter');
        $row=companionPgSql($db,"SELECT document_id::text FROM plugins.correspondence_companion_decision WHERE status='queued'")[0]??[];$document=$row['document_id']??null;
        companionPgCheck(is_string($document),'owned document exists');
        companionPgReject(fn()=>$service->dispatch($document),'manual dispatch bypassed owner');
        companionPgReject(fn()=>$service->retry($document),'manual retry bypassed owner');
    }
    if($hour===60){
        $due=$companion->tick(null);companionPgCheck($due['status']==='dispatched'&&$calls===1,'fresh due dispatch uses no model');
        $ops=companionPgSql($db,"SELECT operation_id::text FROM plugins.correspondence_operations WHERE document_id=$1",[$document]);
        companionPgCheck(count($ops)===1,'exactly one physical operation');
        $reconciled=$service->dispatch($document,true);
        companionPgCheck(($reconciled['operation_id']??null)===$ops[0]['operation_id'],'read-only reconcile retains same operation');
        $again=$companion->tick(null);companionPgCheck($again['status']!=='dispatched','no resend after operation');
    }
}

$sample=static function(int $hour,string $state,?string &$episode,?int &$first)use($store,$companion,$db,$presence):array{
    $gamets=30000000+$hour*416667;$ts=100+$hour*2;
    $native=['bored',(string)$ts,(string)$gamets,'synthetic'];
    $store->bindRequestClock($native);$store->observeManaged($presence);
    $poll=$companion->poll();companionPgCheck($poll['status']==='queued','hourly bored event polls with fresh managed executor');
    $action=companionPgSql($db,"SELECT concat(action,chr(124),text) AS action FROM public.responselog WHERE concat(action,chr(124),text) LIKE 'command|ExtCmdCHIMCorrespondenceCompanion_Observe@%' ORDER BY id DESC LIMIT 1")[0]['action']??'';
    $issued=json_decode(substr($action,strpos($action,'@')+1),true,16,JSON_THROW_ON_ERROR);
    $minute=correspondenceCompanionGameMinute($gamets);
    if($state==='waiting'){$episode??=$issued['observation_id'];$first??=$minute;}
    else{$episode=null;$first=null;}
    $reply=$issued;$reply['actors']=[['id'=>'900001','refid'=>$issued['actors'][0]['refid'],'state'=>$state,'episode'=>$episode??'','first_game_minute'=>$first??0,'game_minute'=>$minute]];
    $receipt=['correspondence_companion',(string)($ts+1),(string)$gamets,correspondenceJson($reply)];
    companionPgCheck($companion->receive($receipt,$reply)['status']==='processed','bounded state sample accepted');
    return [$issued,$reply,$receipt];
};

$sample(85,'unknown',$episode,$first);
for($hour=86;$hour<=165;$hour++){
    $sample($hour,'waiting',$episode,$first);
    if($hour===158){
        $gamets=30000000+$hour*416667;
        companionPgSql($db,"INSERT INTO public.bgl_history(npc,ts,gamets,localts,data,category) VALUES('Camilla',1,$1,1,'synthetic player letter','letter')",[(string)$gamets]);
        $r=$companion->tick(static function():string{throw new RuntimeException('model must not be called during BGL suppression');});
        companionPgCheck($r['status']==='idle'&&$r['reason']==='recent_bgl_letter','recent BGL player letter suppresses overlap');
    }
    if($hour===165){
        $attempts=0;
        $failed=$companion->tick(static function()use(&$attempts):string{$attempts++;throw new RuntimeException('synthetic timeout');});
        companionPgCheck($failed['status']==='failed'&&$attempts===1,'model timeout recorded as one attempt');
        $repeat=$companion->tick(static function()use(&$attempts):string{$attempts++;return '{"action":"letter","recipient":"player","body":"Unsafe retry."}';});
        companionPgCheck($repeat['status']==='idle'&&$attempts===1,'attempted failed episode never retries');
    }
}

$sample(166,'unknown',$episode,$first);
for($hour=167;$hour<=239;$hour++)$sample($hour,'waiting',$episode,$first);
$queued=$companion->tick(static fn():string=>'{"action":"letter","recipient":"player","body":"I hope you are well."}');
companionPgCheck($queued['status']==='queued','second episode queues independently');
$pending=companionPgSql($db,"SELECT document_id::text FROM plugins.correspondence_companion_decision WHERE episode_id=$1::uuid",[$episode])[0]['document_id']??null;
$sample(240,'unknown',$episode,$first);
$cancelled=companionPgSql($db,"SELECT status FROM plugins.correspondence_companion_decision WHERE document_id=$1",[$pending])[0]['status']??null;
companionPgCheck($cancelled==='stale'&&$service->get($pending)['state']==='queued','unknown sample cancels pending physical dispatch');
companionPgReject(fn()=>$service->dispatch($pending),'cancelled owned document manually dispatched');

$autonomy->setEnabled(false);
$store->bindRequestClock(['bored','1000',(string)(30000000+241*416667),'synthetic']);
companionPgCheck($companion->poll()['status']==='paused','global opt-out pauses polling');
$autonomy->setEnabled(true);

$sample(241,'waiting',$episode,$first);
$gamets=30000000+242*416667;$ts=100+242*2;
$store->bindRequestClock(['bored',(string)$ts,(string)$gamets,'synthetic']);$store->observeManaged($presence);
$poll=$companion->poll();companionPgCheck($poll['status']==='queued','out-of-order test poll queued');
$action=companionPgSql($db,"SELECT concat(action,chr(124),text) AS action FROM public.responselog WHERE concat(action,chr(124),text) LIKE 'command|ExtCmdCHIMCorrespondenceCompanion_Observe@%' ORDER BY id DESC LIMIT 1")[0]['action'];
$issued=json_decode(substr($action,strpos($action,'@')+1),true,16,JSON_THROW_ON_ERROR);
$reply=$issued;$reply['actors']=[['id'=>'900001','refid'=>$issued['actors'][0]['refid'],'state'=>'waiting','episode'=>$episode,'first_game_minute'=>$first,'game_minute'=>correspondenceCompanionGameMinute($gamets)-60]];
$receipt=['correspondence_companion',(string)($ts+1),(string)$gamets,correspondenceJson($reply)];
companionPgReject(fn()=>$companion->receive($receipt,$reply),'out-of-order clock accepted');
$reply['actors'][0]['game_minute']=correspondenceCompanionGameMinute($gamets);
companionPgCheck($companion->receive($receipt,$reply)['status']==='processed','corrected correlated sample accepted');
companionPgReject(fn()=>$companion->receive($receipt,$reply),'corrected sample replay accepted');

$gamets=30000000+245*416667;$ts=100+245*2;
$store->bindRequestClock(['bored',(string)$ts,(string)$gamets,'synthetic']);$store->observeManaged($presence);
$poll=$companion->poll();companionPgCheck($poll['status']==='queued','post-gap poll queued');
$action=companionPgSql($db,"SELECT concat(action,chr(124),text) AS action FROM public.responselog WHERE concat(action,chr(124),text) LIKE 'command|ExtCmdCHIMCorrespondenceCompanion_Observe@%' ORDER BY id DESC LIMIT 1")[0]['action'];
$issued=json_decode(substr($action,strpos($action,'@')+1),true,16,JSON_THROW_ON_ERROR);
$minute=correspondenceCompanionGameMinute($gamets);
$reply=$issued;$reply['actors']=[['id'=>'900001','refid'=>$issued['actors'][0]['refid'],'state'=>'waiting','episode'=>$episode,'first_game_minute'=>$first,'game_minute'=>$minute]];
$receipt=['correspondence_companion',(string)($ts+1),(string)$gamets,correspondenceJson($reply)];
companionPgCheck($companion->receive($receipt,$reply)['status']==='processed','server-only gap reanchors trusted waiting without crediting missed time');
$reanchored=companionPgSql($db,'SELECT first_game_minute::text,native_first_game_minute::text FROM plugins.correspondence_companion_episode WHERE actor_id=900001')[0];
companionPgCheck($reanchored['first_game_minute']===(string)$minute&&$reanchored['native_first_game_minute']===(string)$first,'reanchor keeps native identity but resets elapsed server evidence');
$store->bindRequestClock(['bored',(string)($ts+2),(string)(30000000+244*416667),'synthetic']);
companionPgCheck($companion->poll()['status']==='idle','clock rewind cannot issue stale poll');

for($hour=246;$hour<=317;$hour++)$sample($hour,'waiting',$episode,$first);
$queued=$companion->tick(static fn():string=>'{"action":"letter","recipient":"player","body":"Please let me know you are safe."}');
companionPgCheck($queued['status']==='queued','post-gap episode gets fresh opportunity');
$pending=companionPgSql($db,"SELECT document_id::text FROM plugins.correspondence_companion_decision WHERE episode_id=$1::uuid",[$episode])[0]['document_id']??null;
$sample(318,'nearby',$episode,$first);
companionPgCheck((companionPgSql($db,"SELECT status FROM plugins.correspondence_companion_decision WHERE document_id=$1",[$pending])[0]['status']??null)==='stale','reunion cancels pending check-in');
companionPgReject(fn()=>$service->dispatch($pending),'reunion document manually dispatched');

companionPgSql($db,"INSERT INTO public.core_npc_master VALUES(900002,'Lydia','000A2C8E',1,'Reserved and direct.','{\"background_life_enabled\":true,\"background_life_letters\":true}','{\"stats\":{\"is_dead\":false}}')");
$multiPresence=base64_encode(correspondenceJson(['source'=>'plugin_player_routing_v2','present_actors'=>[
    ['form_id'=>hexdec('00013488'),'name'=>'Camilla','distance'=>10,'managed'=>true,'creature'=>false],
    ['form_id'=>hexdec('000A2C8E'),'name'=>'Lydia','distance'=>20,'managed'=>true,'creature'=>false],
]]));
$multiEpisodes=[];$multiFirst=[];$multiDocuments=[];
for($hour=319;$hour<=369;$hour++){
    $gamets=30000000+$hour*416667;$ts=100+$hour*2;
    $store->bindRequestClock(['bored',(string)$ts,(string)$gamets,'synthetic']);$store->observeManaged($multiPresence);
    $poll=$companion->poll();companionPgCheck($poll['status']==='queued','two-actor poll queued');
    $action=companionPgSql($db,"SELECT concat(action,chr(124),text) AS action FROM public.responselog WHERE concat(action,chr(124),text) LIKE 'command|ExtCmdCHIMCorrespondenceCompanion_Observe@%' ORDER BY id DESC LIMIT 1")[0]['action'];
    $issued=json_decode(substr($action,strpos($action,'@')+1),true,16,JSON_THROW_ON_ERROR);
    companionPgCheck(count($issued['actors'])===2,'two actors share one native observation');
    $minute=correspondenceCompanionGameMinute($gamets);$reply=$issued;$reply['actors']=[];
    foreach($issued['actors'] as $actor){
        $id=$actor['id'];$waiting=$hour!==369||$id==='900002';
        if($waiting){$multiEpisodes[$id]??=$issued['observation_id'];$multiFirst[$id]??=$minute;}
        $reply['actors'][]=['id'=>$id,'refid'=>$actor['refid'],'state'=>$waiting?'waiting':'nearby','episode'=>$waiting?$multiEpisodes[$id]:'','first_game_minute'=>$waiting?$multiFirst[$id]:0,'game_minute'=>$minute];
    }
    $receipt=['correspondence_companion',(string)($ts+1),(string)$gamets,correspondenceJson($reply)];
    companionPgCheck($companion->receive($receipt,$reply)['status']==='processed','two-actor correlated sample accepted');
    if($hour===367||$hour===368){
        $decision=$companion->tick(static fn():string=>'{"action":"letter","recipient":"player","body":"I hope you are safe."}');
        companionPgCheck($decision['status']==='queued','each actor can own a decision despite shared native episode UUID');
        $target=$hour===367?'900001':'900002';
        $multiDocuments[$target]=companionPgSql($db,"SELECT document_id::text FROM plugins.correspondence_companion_decision WHERE actor_id=$1 AND episode_id=$2::uuid",[$target,$multiEpisodes[$target]])[0]['document_id']??null;
        companionPgCheck(is_string($multiDocuments[$target]),'actor-specific queued document exists');
    }
}
companionPgCheck($multiEpisodes['900001']===$multiEpisodes['900002'],'same native UUID is shared by distinct actors');
$camillaStatus=companionPgSql($db,"SELECT status FROM plugins.correspondence_companion_decision WHERE document_id=$1",[$multiDocuments['900001']])[0]['status']??null;
$lydiaStatus=companionPgSql($db,"SELECT status FROM plugins.correspondence_companion_decision WHERE document_id=$1",[$multiDocuments['900002']])[0]['status']??null;
companionPgCheck($camillaStatus==='stale'&&$lydiaStatus==='queued','one reunion cancels only the matching actor decision');
$service->close($multiDocuments['900002']);
$store->bindRequestClock(['bored','900',(string)(30000000+380*416667),'synthetic']);
$closedDue=$companion->tick(null);
companionPgCheck($closedDue['status']==='stale'&&$closedDue['reason']==='operator_closed','operator-closed owned document retires before due dispatch');
companionPgCheck((companionPgSql($db,"SELECT count(*)::int AS n FROM plugins.correspondence_operations WHERE document_id=$1",[$multiDocuments['900002']])[0]['n']??null)==='0','closed owned document never creates operation');

companionPgSql($db,"INSERT INTO public.core_npc_master VALUES(900003,'Iona','000A2C8F',1,'Patient and direct.','{\"background_life_enabled\":true,\"background_life_letters\":true}','{\"stats\":{\"is_dead\":false}}')");
$store->bindRequestClock(['bored','902',(string)(30000000+381*416667),'synthetic']);$store->observeManaged($multiPresence);
companionPgCheck($companion->poll()['status']==='queued','lost-first-receipt poll queued');
$action=companionPgSql($db,"SELECT concat(action,chr(124),text) AS action FROM public.responselog WHERE concat(action,chr(124),text) LIKE 'command|ExtCmdCHIMCorrespondenceCompanion_Observe@%' ORDER BY id DESC LIMIT 1")[0]['action'];
$lostIssued=json_decode(substr($action,strpos($action,'@')+1),true,16,JSON_THROW_ON_ERROR);
companionPgCheck(in_array('900003',array_column($lostIssued['actors'],'id'),true),'new actor included in lost initial poll');
$lostFirst=correspondenceCompanionGameMinute(30000000+381*416667);
$store->bindRequestClock(['bored','904',(string)(30000000+382*416667),'synthetic']);$store->observeManaged($multiPresence);
companionPgCheck($companion->poll()['status']==='queued','poll recovers after lost native receipt');
$action=companionPgSql($db,"SELECT concat(action,chr(124),text) AS action FROM public.responselog WHERE concat(action,chr(124),text) LIKE 'command|ExtCmdCHIMCorrespondenceCompanion_Observe@%' ORDER BY id DESC LIMIT 1")[0]['action'];
$issued=json_decode(substr($action,strpos($action,'@')+1),true,16,JSON_THROW_ON_ERROR);
$minute=correspondenceCompanionGameMinute(30000000+382*416667);$reply=$issued;$reply['actors']=[];
foreach($issued['actors'] as $actor)$reply['actors'][]=['id'=>$actor['id'],'refid'=>$actor['refid'],'state'=>$actor['id']==='900003'?'waiting':'nearby','episode'=>$actor['id']==='900003'?$lostIssued['observation_id']:'','first_game_minute'=>$actor['id']==='900003'?$lostFirst:0,'game_minute'=>$minute];
$receipt=['correspondence_companion','905',(string)(30000000+382*416667),correspondenceJson($reply)];
companionPgCheck($companion->receive($receipt,$reply)['status']==='processed','old native episode accepted at first received waiting sample');
$iona=companionPgSql($db,'SELECT first_game_minute::text,native_first_game_minute::text FROM plugins.correspondence_companion_episode WHERE actor_id=900003')[0]??[];
companionPgCheck(($iona['first_game_minute']??null)===(string)$minute&&($iona['native_first_game_minute']??null)===(string)$lostFirst,'only received sample starts server evidence clock');
companionPgCheck($companion->tick(static fn():string=>'{"action":"letter","recipient":"player","body":"Too early."}')['status']==='idle','lost native sample gives no elapsed-time credit');
$camillaEpisode=null;$camillaFirst=null;
$observeAfterLoss=static function(int $hour,string $camillaState)use($store,$companion,$db,$multiPresence,$lostIssued,$lostFirst,&$camillaEpisode,&$camillaFirst):void{
    $gamets=30000000+$hour*416667;$ts=100+$hour*2;
    $store->bindRequestClock(['bored',(string)$ts,(string)$gamets,'synthetic']);$store->observeManaged($multiPresence);
    companionPgCheck($companion->poll()['status']==='queued','post-loss follow-up poll queued');
    $action=companionPgSql($db,"SELECT concat(action,chr(124),text) AS action FROM public.responselog WHERE concat(action,chr(124),text) LIKE 'command|ExtCmdCHIMCorrespondenceCompanion_Observe@%' ORDER BY id DESC LIMIT 1")[0]['action'];
    $issued=json_decode(substr($action,strpos($action,'@')+1),true,16,JSON_THROW_ON_ERROR);
    $minute=correspondenceCompanionGameMinute($gamets);$reply=$issued;$reply['actors']=[];
    foreach($issued['actors'] as $actor){
        $id=$actor['id'];$waiting=$id==='900003'||$id==='900001'&&$camillaState==='waiting';
        if($id==='900001'&&$waiting){$camillaEpisode??=$issued['observation_id'];$camillaFirst??=$minute;}
        $reply['actors'][]=['id'=>$id,'refid'=>$actor['refid'],'state'=>$waiting?'waiting':'nearby','episode'=>$waiting?($id==='900003'?$lostIssued['observation_id']:$camillaEpisode):'','first_game_minute'=>$waiting?($id==='900003'?$lostFirst:$camillaFirst):0,'game_minute'=>$minute];
    }
    $receipt=['correspondence_companion',(string)($ts+1),(string)$gamets,correspondenceJson($reply)];
    companionPgCheck($companion->receive($receipt,$reply)['status']==='processed','post-loss native state accepted');
};
for($hour=383;$hour<=430;$hour++)$observeAfterLoss($hour,'waiting');
$ionaQueued=$companion->tick(static fn():string=>'{"action":"letter","recipient":"player","body":"I hope you are safe."}');
companionPgCheck($ionaQueued['status']==='queued','48 received hours after lost first receipt can queue');
$ionaDocument=companionPgSql($db,"SELECT document_id::text FROM plugins.correspondence_companion_decision WHERE actor_id=900003 AND status='queued'")[0]['document_id']??null;
companionPgCheck(is_string($ionaDocument),'Iona owns pending document');
$observeAfterLoss(431,'waiting');
$camillaInFlight=$companion->tick(static function()use($observeAfterLoss):string{$observeAfterLoss(432,'nearby');return '{"action":"letter","recipient":"player","body":"I hope you are safe."}';});
companionPgCheck($camillaInFlight['status']==='stale','reunion during model cancels completion before compose');
companionPgCheck((companionPgSql($db,"SELECT count(*)::int AS n FROM plugins.correspondence_companion_decision WHERE actor_id=900001 AND status='stale' AND document_id IS NULL")[0]['n']??null)!=='0','in-flight reunion leaves no document');
companionPgSql($db,"UPDATE public.core_npc_master SET npc_name='Iona Renamed' WHERE id=900003");
$store->bindRequestClock(['bored','1000',(string)(30000000+442*416667),'synthetic']);
$renamedDue=$companion->tick(null);
companionPgCheck($renamedDue['status']==='stale'&&$renamedDue['reason']==='dispatch_state_changed','identity-change cancellation survives optional close validation failure');
companionPgCheck((companionPgSql($db,"SELECT status FROM plugins.correspondence_companion_decision WHERE document_id=$1",[$ionaDocument])[0]['status']??null)==='stale','identity-change stale retirement committed');
companionPgCheck((companionPgSql($db,"SELECT count(*)::int AS n FROM plugins.correspondence_operations WHERE document_id=$1",[$ionaDocument])[0]['n']??null)==='0','renamed actor never dispatches owned note');
require __DIR__.'/companion_cooldown_cases.php';
echo "companion_pg PASS\n";
