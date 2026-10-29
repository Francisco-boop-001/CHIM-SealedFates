<?php
declare(strict_types=1);

// Only a newly owned, identity-pinned database is allowed; never load CHIM configuration.
function availabilityCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function availabilitySql(mixed $db,string $sql,array $args=[]):array
{
    $result=$args===[]?pg_query($db,$sql):pg_query_params($db,$sql,$args);
    availabilityCheck($result!==false,'fixture_sql_failed');
    try{return pg_num_fields($result)>0?(pg_fetch_all($result)?:[]):[];}finally{pg_free_result($result);}
}
function availabilityEnv(string $name):string
{
    $value=getenv('CORRESPONDENCE_AVAILABILITY_'.$name);
    availabilityCheck(is_string($value)&&$value!=='','explicit_fixture_'.$name.'_required');return $value;
}
availabilityCheck(PHP_SAPI==='cli'&&extension_loaded('pgsql'),'cli_pgsql_required');
foreach(getenv() as $key=>$_)availabilityCheck(!str_starts_with(strtoupper($key),'PG'),'ambient_postgres_environment_forbidden');
$dsn=availabilityEnv('DSN');$database=availabilityEnv('DATABASE');$role=availabilityEnv('ROLE');
$system=availabilityEnv('SYSTEM_ID');$directory=availabilityEnv('DATA_DIRECTORY');$port=availabilityEnv('PORT');
availabilityCheck(preg_match('/\Asf_availability_[a-f0-9]{12}\z/D',$database)===1&&$role===$database,'owned_database_and_role_required');
if(PHP_OS_FAMILY==='Windows')availabilityCheck(str_starts_with($dsn,'host=127.0.0.1 '),'local_windows_fixture_required');
else availabilityCheck(getenv('WSL_DISTRO_NAME')==='DwemerAI4Skyrim3-test2'&&str_starts_with($dsn,'host=/var/tmp/sf-availability-'),'owned_test2_socket_fixture_required');
$db=pg_connect($dsn,PGSQL_CONNECT_FORCE_NEW);
availabilityCheck($db!==false,'fixture_connection_required');
$id=availabilitySql($db,"SELECT current_database() db,current_user role,current_setting('data_directory') dir,current_setting('port') port,current_setting('listen_addresses') listeners,system_identifier::text sid FROM pg_control_system()")[0];
$normalize=static fn(string $p):string=>strtolower(str_replace('\\','/',rtrim($p,'\\/')));
availabilityCheck($id['db']===$database&&$id['role']===$role&&$id['sid']===$system&&$id['port']===$port&&$normalize($id['dir'])===$normalize($directory),'fixture_identity_mismatch');
availabilityCheck($id['listeners']===(PHP_OS_FAMILY==='Windows'?'127.0.0.1':''),'fixture_listener_mismatch');
availabilityCheck(availabilitySql($db,"SELECT count(*) n FROM information_schema.tables WHERE table_schema IN ('public','plugins','chim_meta')")[0]['n']==='0','fresh_database_required');
$root=dirname(__DIR__);
require $root.'/server/lib/store.php';require $root.'/server/lib/autonomy_store.php';require $root.'/server/lib/companion.php';
availabilitySql($db,<<<'SQL'
CREATE TABLE public.eventlog(rowid bigserial PRIMARY KEY,ts bigint,gamets numeric,type text,data text,sess text,localts bigint,people text,location text,party text);
CREATE TABLE public.core_npc_master(id bigint PRIMARY KEY,npc_name text,refid text,profile_id bigint,personality text,extended_data jsonb,metadata jsonb);
CREATE TABLE public.core_player(id text PRIMARY KEY,value text);
CREATE TABLE public.game_plugins(plugin_name text,formid_prefix text);
CREATE TABLE public.books(id bigserial PRIMARY KEY,ts bigint,gamets numeric,content text,sess text,localts bigint,title text);
CREATE TABLE public.responselog(id bigserial PRIMARY KEY,localts bigint,sent integer,actor text,text text,action text,tag text);
CREATE TABLE public.bgl_history(npc text,ts bigint,gamets numeric,localts bigint,data text,category text);
INSERT INTO public.core_player VALUES('player_name','Dragonborn');
INSERT INTO public.game_plugins VALUES('Skyrim.esm','00');
INSERT INTO public.core_npc_master VALUES(900001,'Camilla','00013488',1,'Warm and independent.','{"background_life_enabled":true,"background_life_letters":true}','{}');
INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(100,30000000,'inputtext','synthetic','',100,'','','');
SQL);
$store=new CorrespondencePgStore($db,static fn()=>null,static fn()=>true);$store->ensureSchema();
$store->bindRequestClock(['bored','100','30000000','synthetic']);
$service=new CorrespondenceService($store);$autonomy=new CorrespondenceAutonomyPg($store,$service);$autonomy->ensureSchema();$autonomy->setEnabled(true);
$companion=new CorrespondenceCompanionPg($store,$service);$companion->ensureSchema();
$epoch=correspondenceCompanionEpoch($store->restoreGeneration());
$cases=['readable','unreadable_flag_poll','unreadable_flag_due','missing_flag','explicit_optout','confirmed_dead','player_unavailable','player_changed','actor_changed','physical_identity_changed','physical_identity_unavailable','episode_changed','restore_changed'];
$selected=getenv('CORRESPONDENCE_AVAILABILITY_CASE');if(is_string($selected)&&$selected!==''){availabilityCheck(in_array($selected,$cases,true),'unknown_case');$cases=[$selected];}
$checks=0;
foreach($cases as $case){
    availabilitySql($db,'DELETE FROM plugins.correspondence_companion_decision; DELETE FROM plugins.correspondence_companion_episode; DELETE FROM plugins.correspondence_companion_poll');
    availabilitySql($db,"UPDATE public.core_player SET value='Dragonborn'; UPDATE public.core_npc_master SET npc_name='Camilla',refid='00013488',extended_data='{\"background_life_enabled\":true,\"background_life_letters\":true}',metadata='{}'");
    $request=correspondenceUuid();$episode=correspondenceUuid();
    $document=$service->compose(['request_id'=>$request,'author'=>'npc:900001','payer'=>'npc:900001','recipient'=>'player','claimed_sender'=>'npc:900001','body'=>'Please write when you can.','attachments'=>[]])['document_id'];
    $identity=['id'=>'900001']+$store->physicalActor(['kind'=>'npc','id'=>900001]);
    availabilitySql($db,"INSERT INTO plugins.correspondence_companion_episode VALUES('unprofiled',$1::uuid,900001,$2::uuid,100,100,3000,1,$3::jsonb)",[$epoch,$episode,correspondenceJson($identity)]);
    availabilitySql($db,"INSERT INTO plugins.correspondence_companion_decision(playthrough_id,epoch,actor_id,episode_id,request_id,attempted,status,document_id,due_gamets,claimed_gamets,reason_code) VALUES('unprofiled',$1::uuid,900001,$2::uuid,$3::uuid,true,'queued',$4::uuid,29999999,25000000,'queued_for_transit')",[$epoch,$episode,$request,$document]);
    if(str_starts_with($case,'unreadable_flag'))availabilitySql($db,"UPDATE public.core_npc_master SET extended_data=jsonb_set(extended_data,'{background_life_letters}','{}')");
    elseif($case==='missing_flag')availabilitySql($db,"UPDATE public.core_npc_master SET extended_data=extended_data-'background_life_letters'");
    elseif($case==='explicit_optout')availabilitySql($db,"UPDATE public.core_npc_master SET extended_data=jsonb_set(extended_data,'{background_life_letters}','false')");
    elseif($case==='confirmed_dead')availabilitySql($db,"UPDATE public.core_npc_master SET metadata='{\"stats\":{\"is_dead\":true}}'");
    elseif($case==='player_unavailable')availabilitySql($db,"UPDATE public.core_player SET value=''");
    elseif($case==='player_changed')availabilitySql($db,"UPDATE public.core_player SET value='Someone Else'");
    elseif($case==='actor_changed')availabilitySql($db,"UPDATE public.core_npc_master SET npc_name='Someone Else'");
    elseif($case==='physical_identity_changed')availabilitySql($db,"UPDATE public.core_npc_master SET refid='00013489'");
    elseif($case==='physical_identity_unavailable')availabilitySql($db,"DELETE FROM public.game_plugins");
    elseif($case==='episode_changed')availabilitySql($db,'UPDATE plugins.correspondence_companion_episode SET episode_id=$1::uuid',[correspondenceUuid()]);
    elseif($case==='restore_changed')availabilitySql($db,'UPDATE plugins.correspondence_restore_state SET epoch=$1::uuid',[correspondenceUuid()]);
    $poll=in_array($case,['unreadable_flag_poll','missing_flag','explicit_optout','confirmed_dead'],true);
    $restoreRejected=false;
    try{$result=$poll?$companion->poll():$companion->tick(null);}catch(DomainException $error){if($case!=='restore_changed')throw $error;$restoreRejected=true;}
    $row=availabilitySql($db,'SELECT status FROM plugins.correspondence_companion_decision WHERE document_id=$1::uuid',[$document])[0];
    $cancelled=in_array($case,['explicit_optout','confirmed_dead','player_changed','actor_changed','physical_identity_changed','episode_changed'],true);
    availabilityCheck($row['status']===($cancelled?'stale':'queued'),$case.': transient evidence loss must defer; confirmed change must cancel');$checks++;
    if(!$cancelled){availabilityCheck(availabilitySql($db,"SELECT payload ? 'operator_close' AS closed FROM plugins.correspondence_documents WHERE document_id=$1::uuid",[$document])[0]['closed']==='f',$case.': no invented operator closure');$checks++;}
    availabilityCheck(availabilitySql($db,'SELECT count(*) n FROM plugins.correspondence_operations WHERE document_id=$1::uuid',[$document])[0]['n']==='0',$case.': no dispatch without fresh evidence');$checks++;
    if($case==='restore_changed'){
        availabilityCheck($restoreRejected,'changed restore token must reject');$checks++;
        availabilitySql($db,'UPDATE plugins.correspondence_restore_state SET epoch=$1::uuid',[explode(':',$store->requestRestoreToken())[0]]);
    }
    availabilitySql($db,"UPDATE public.core_player SET value='Dragonborn'; UPDATE public.core_npc_master SET extended_data=jsonb_set(extended_data,'{background_life_letters}','true'),metadata='{}'");
    if($case==='physical_identity_unavailable')availabilitySql($db,"INSERT INTO public.game_plugins VALUES('Skyrim.esm','00')");
    // Recovery alone is insufficient; a stale sample must still block delivery.
    $companion->tick(null);
    availabilityCheck(availabilitySql($db,'SELECT count(*) n FROM plugins.correspondence_operations WHERE document_id=$1::uuid',[$document])[0]['n']==='0',$case.': stale sample remains blocked');$checks++;
    availabilitySql($db,'UPDATE plugins.correspondence_companion_episode SET sample_gamets=30000000 WHERE episode_id=$1::uuid',[$episode]);
    $companion->tick(null);$companion->tick(null);
    $wanted=$cancelled?'0':'1';
    availabilityCheck(availabilitySql($db,'SELECT count(*) n FROM plugins.correspondence_operations WHERE document_id=$1::uuid',[$document])[0]['n']===$wanted,$case.': fresh recovery must dispatch exactly once or remain cancelled');$checks++;
    availabilityCheck(availabilitySql($db,"SELECT count(*) n FROM public.responselog WHERE action='command' AND text LIKE 'ExtCmdCHIMCorrespondenceBridge_Deliver@%' AND text LIKE $1",['%'.$document.'%'])[0]['n']===$wanted,$case.': exactly one physical dispatch command');$checks++;
    try{$service->dispatch($document);throw new RuntimeException('manual bypass accepted');}catch(DomainException){}$checks++;
    echo $case," PASS\n";
}
echo "server_companion_availability_pg: $checks checks passed (synthetic database events; no game/provider calls)\n";
