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
manualDeliveryCheck((manualDeliverySql($db,"SELECT count(*)::int n FROM information_schema.tables WHERE table_schema IN ('public','plugins','chim_meta')")[0]['n']??null)==='0','fresh_database_required');

$root=dirname(__DIR__);
require_once $root.'/server/lib/bootstrap.php';
manualDeliverySql($db,<<<'SQL'
CREATE TABLE public.eventlog(rowid bigserial PRIMARY KEY,ts bigint NOT NULL,gamets numeric NOT NULL,type text NOT NULL,data text NOT NULL,sess text NOT NULL,localts bigint NOT NULL,people text NOT NULL,location text NOT NULL,party text NOT NULL);
CREATE TABLE public.core_npc_master(id bigint PRIMARY KEY,npc_name text,refid text,profile_id bigint,personality text,extended_data jsonb,metadata jsonb);
CREATE TABLE public.core_player(id text PRIMARY KEY,value text);
CREATE TABLE public.game_plugins(plugin_name text,formid_prefix text);
CREATE TABLE public.books(id bigserial PRIMARY KEY,ts bigint,gamets numeric,content text,sess text,localts bigint,title text);
CREATE TABLE public.responselog(id bigserial PRIMARY KEY,localts bigint,sent integer,actor text,text text,action text,tag text);
INSERT INTO public.core_player VALUES('player_name','Dragonborn');
INSERT INTO public.game_plugins VALUES('Skyrim.esm','00');
INSERT INTO public.core_npc_master VALUES(900001,'Camilla','00013488',1,'A private correspondent.','{}','{}');
SQL);
$store=new CorrespondencePgStore($db,static fn(CorrespondencePgStore $s):?string=>$s->observedExecutor(),static fn(string $title,string $body):bool=>true);
$store->ensureSchema();
$request=['request','1800000000000000123','30000000','synthetic'];
$store->bindRequestClock($request);
$emptySnapshot=base64_encode(correspondenceJson(['source'=>'plugin_player_routing_v2','present_actors'=>[]]));
$store->observeManaged($emptySnapshot);
$scope=$store->scope()['playthrough_id'];
$restore=$store->restoreGeneration();
$fingerprint=hash('sha256',correspondenceCanonicalJson(['request',$request[1],$request[2]]));
$receivedAt=time();
$activity=[
    'playthrough_id'=>$scope,
    'native_type'=>'request',
    'native_ts'=>$request[1],
    'gamets'=>$request[2],
    'request_sha256'=>$fingerprint,
    'restore_generation'=>$restore,
    'received_at'=>$receivedAt,
    'active'=>true,
];
$anchorData=['kind'=>'manual_delivery_activity']+$activity;
$anchor=$store->transaction(fn()=>$store->appendEvent('correspondence_presence',correspondenceJson($anchorData),'','correspondence_v1'));
$activity['anchor']=$anchor;
$payload=json_decode((string)(manualDeliverySql($db,'SELECT payload::text FROM plugins.correspondence_presence WHERE playthrough_id=$1',[$scope])[0]['payload']??''),true,64,JSON_THROW_ON_ERROR);
$payload['activity']=$activity;
manualDeliverySql($db,'UPDATE plugins.correspondence_presence SET payload=$2::jsonb WHERE playthrough_id=$1',[$scope,correspondenceJson($payload)]);

$service=new CorrespondenceService($store);
$requestId=correspondenceUuid();
$document=$service->compose([
    'request_id'=>$requestId,
    'author'=>'player',
    'payer'=>'player',
    'recipient'=>'npc:900001',
    'claimed_sender'=>'npc:900001',
    'body'=>'Five hundred septims for the saved letter.',
    'attachments'=>[['kind'=>'gold','form_id'=>'Skyrim.esm|0000000F','quantity'=>500]],
]);
$sent=$service->dispatch($document['document_id']);
manualDeliveryCheck(($sent['state']??null)==='dispatched','active_native_request_with_zero_nearby_actors_dispatches_500_gold_once; got '.($sent['state']??'missing_state'));
$operation=$store->loadOperation($sent['operation_id']);
manualDeliveryCheck(is_array($operation)&&($operation['attachments'][0]['quantity']??null)===500,'saved_operation_keeps_exact_500_gold_attachment');
$again=$service->dispatch($document['document_id']);
manualDeliveryCheck(($again['operation_id']??null)===$sent['operation_id'],'repeat_dispatch_reuses_saved_operation');
manualDeliveryCheck((manualDeliverySql($db,'SELECT count(*)::int n FROM plugins.correspondence_operations WHERE document_id=$1::uuid',[$document['document_id']])[0]['n']??null)==='1','one_physical_operation');
manualDeliveryCheck((manualDeliverySql($db,"SELECT count(*)::int n FROM public.responselog WHERE action='command' AND text LIKE 'ExtCmdCHIMCorrespondenceBridge_Deliver@%' AND text LIKE $1",['%'.$document['document_id'].'%'])[0]['n']??null)==='1','one_native_delivery_command');
manualDeliveryCheck((manualDeliverySql($db,"SELECT count(*)::int n FROM public.eventlog WHERE type='info' AND sess='written_evidence_v1'")[0]['n']??null)==='0','physical_dispatch_does_not_claim_receipt_or_exposure');
echo "PASS manual 500-gold delivery from native activity with no nearby actors\n";
pg_close($db);
