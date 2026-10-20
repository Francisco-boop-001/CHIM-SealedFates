<?php
declare(strict_types=1);
// Root-only native Pg fixture. Actual producer and public MP persistence; deterministic model/config boundary only.
function mpCrossCheck(bool $condition, string $reason): void
{
    if (!$condition) throw new RuntimeException($reason);
}
function mpCrossQuery(PgSql\Connection $connection, string $sql, array $parameters = []): PgSql\Result
{
    $result = $parameters === [] ? pg_query($connection, $sql) : pg_query_params($connection, $sql, $parameters);
    mpCrossCheck($result instanceof PgSql\Result, 'fixture_sql_failed');
    return $result;
}
function mpCrossOne(PgSql\Connection $connection, string $sql, array $parameters = []): array
{
    $row = pg_fetch_assoc(mpCrossQuery($connection, $sql, $parameters));
    mpCrossCheck(is_array($row), 'fixture_row_missing');
    return $row;
}
function mpCrossJson(mixed $value): string { return json_encode($value, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); }
function mpCrossNpc(PgSql\Connection $connection, int $id): array
{
    return mpCrossOne($connection, 'SELECT extended_data::text extended,plugin_extended_data::text plugins,gamets_last_updated::text gamets FROM core_npc_master WHERE id=$1', [$id]);
}
// Explicit normal model-config seam: no connector class, credentials, model.php request or provider is invoked.
function chimIsGlobalLlmConnectorEnabled(string $field): bool
{
    return $field === 'RELLLM_CONNECTOR' && ($GLOBALS['RELLLM_CONNECTOR'] ?? null) === 1;
}
mpCrossCheck(PHP_SAPI === 'cli', 'cli_only');
mpCrossCheck(getenv('WSL_DISTRO_NAME') === 'DwemerAI4Skyrim3-test', 'clone_only');
mpCrossCheck(extension_loaded('pgsql') && extension_loaded('mbstring') && extension_loaded('sysvsem'), 'native_php_extensions_required');
foreach (getenv() as $name => $_value) mpCrossCheck(!str_starts_with(strtoupper((string)$name), 'PG'), 'postgres_environment_forbidden');
$connection = pg_connect('host=/var/run/postgresql dbname=chim_correspondence_mp_cross_it user=postgres connect_timeout=3 application_name=correspondence_mp_cross_it', PGSQL_CONNECT_FORCE_NEW);
mpCrossCheck($connection instanceof PgSql\Connection, 'isolated_peer_connection_required');
$identity = mpCrossOne($connection, "SELECT current_database() db,current_user role,current_setting('listen_addresses') listeners,(inet_server_addr() IS NULL)::int socket_only");
mpCrossCheck($identity === ['db'=>'chim_correspondence_mp_cross_it','role'=>'postgres','listeners'=>'','socket_only'=>'1'], 'isolated_peer_database_only');
$tables = mpCrossOne($connection, "SELECT count(*)::int amount FROM information_schema.tables WHERE table_schema IN ('public','plugins')");
mpCrossCheck($tables['amount'] === '0', 'blank_owned_fixture_required');
$project = dirname(__DIR__, 2);
$exchange = dirname($project, 2);
$mp = dirname($project).'/CHIM-MindPoisoning-Written-Evidence';
$entry = $mp.'/server/written_evidence.php';
mpCrossCheck(is_file($entry), 'RED_mp_public_written_entry_not_ready_no_fixture_writes');
require_once $entry;
foreach (['writtenEvidenceCapability','enqueueWrittenEvidence','writtenEvidenceStatus','evaluateWrittenEvidence'] as $name) mpCrossCheck(function_exists('ChimMindPoisoning\\'.$name), 'RED_mp_public_api_not_ready_no_fixture_writes');
$capability = ChimMindPoisoning\writtenEvidenceCapability();
mpCrossCheck(($capability['version']??null)===1 && ($capability['input_type']??null)==='written_evidence' && ($capability['source_marker']??null)==='written_evidence_v1' && ($capability['source_event_type']??null)==='info', 'RED_mp_info_carrier_capability_not_ready_no_fixture_writes');
$constructor = new ReflectionMethod(ChimMindPoisoning\PostgresStoreDb::class, '__construct');
mpCrossCheck($constructor->getNumberOfParameters()===2 && $constructor->getParameters()[1]->getName()==='trustedPgConnection', 'RED_mp_trusted_peer_adapter_not_ready_no_fixture_writes');
$core = $exchange.'/artifacts/chim-correspondence-implementation-20261019/mp-core-stage';
mpCrossCheck(realpath($core)===$core && !is_link($core) && is_file($core.'/main.php'), 'private_core_stage_required');
$helpers = [
    'relationship_manager.php'=>'4497443c9480c7d7ff5d5deabcec9ad5ab4cdec6f28f1fd044f89594d71e06ab',
    'logger.php'=>'63b9f6ff8d99335f6c92991120030f89038641435d5f39408721fde1fa988f54',
    'chim_interaction.php'=>'0f85286f47da7f02b8527ba9099e3d93452185399a9c0f66918072b877ccb6de',
    'semaphore_manager.class.php'=>'7e4463adbd42ea4cbaf960ccd5ff55beb623646d17da9e1b063d1a130983b4a6',
];
foreach ($helpers as $file=>$hash) mpCrossCheck(hash_file('sha256',$core.'/lib/'.$file)===$hash, 'pinned_core_helper_mismatch');
$GLOBALS['ENGINE_PATH']=$core.'/';
$GLOBALS['RELLLM_CONNECTOR']=1;
$GLOBALS['PLAYER_NAME']='Dragonborn';
$GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA']=false;
require_once $core.'/lib/logger.php';
Logger::setCustomLog($core.'/log/mp-cross.log');
require_once $core.'/lib/relationship_manager.php';
require_once $core.'/lib/chim_interaction.php';
require_once $core.'/lib/semaphore_manager.class.php';
require_once $project.'/server/lib/store.php';
require_once $project.'/server/lib/boundaries.php';
$globalDbBefore = [array_key_exists('db',$GLOBALS),$GLOBALS['db']??null];
$native = new ChimMindPoisoning\PostgresStoreDb(null,$connection);
mpCrossCheck([array_key_exists('db',$GLOBALS),$GLOBALS['db']??null]===$globalDbBefore,'trusted_peer_adapter_changed_global_db');

// Only fixture-owned tables are created in this exact dedicated synthetic database.
$ddl=<<<'SQL'
CREATE TABLE eventlog(rowid bigserial PRIMARY KEY,ts bigint,gamets numeric,type text,data text,sess text,localts bigint,people text,location text,party text,delivery_state text,utterance_id text);
CREATE TABLE core_npc_master(id bigint PRIMARY KEY,npc_name text,refid text,profile_id bigint,lock_profile integer DEFAULT 0,extended_data jsonb DEFAULT '{}',plugin_extended_data jsonb DEFAULT '{}',gamets_last_updated numeric DEFAULT 0);
CREATE TABLE core_npc_master_history(history_id bigserial PRIMARY KEY,npc_id bigint,npc_name text,refid text,profile_id bigint,lock_profile integer,extended_data jsonb,plugin_extended_data jsonb,gamets_last_updated numeric,created timestamp);
CREATE TABLE core_player(id text PRIMARY KEY,value text);
CREATE TABLE game_plugins(plugin_name text,formid_prefix text);
CREATE TABLE books(ts bigint,gamets numeric,content text,sess text,localts bigint,title text);
CREATE TABLE responselog(localts bigint,sent integer,actor text,text text,action text,tag text);
INSERT INTO eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(100,100,'inputtext','synthetic seed','',100,'','','');
INSERT INTO core_player VALUES('player_name','Dragonborn');
INSERT INTO core_npc_master(id,npc_name,refid,profile_id) VALUES(900001,'Camilla','00013488',1),(900002,'Jarl Balgruuf','0001A677',1),(900003,'Faendal','0001348C',1),(900004,'Lydia','000A2C94',1);
INSERT INTO game_plugins VALUES('Skyrim.esm','00');
SQL;
mpCrossQuery($connection,$ddl);
mpCrossQuery($connection,file_get_contents($project.'/server/migrations/001_initial.sql'));
$arrangements=['npc:900004'=>['owner_id'=>900001,'target'=>'npc:900004','playthrough_id'=>'unprofiled','kind'=>'casual','basis'=>'registered_reflection','gamets'=>10,'sources'=>[['event_id'=>1,'utterance_id'=>'utt_prior_arrangement_fixture','speech_hash'=>str_repeat('a',64)]]]];
$extended=['fixture_untouched'=>['marker'=>'recipient-sentinel'],'relationships'=>[
    'Jarl Balgruuf'=>['aff'=>10,'type'=>'neutral','note'=>'preserve sender note','custom_marker'=>'sender'],
    'Faendal'=>['aff'=>4,'type'=>'neutral','note'=>'preserve third-party note','custom_marker'=>'third'],
    'Lydia'=>['aff'=>7,'type'=>'ally','note'=>'unrelated relationship'],
]];
$plugins=['sworn_and_scorned'=>['fixture_sentinel'=>['preserve'=>'sas']],'private_conversation'=>['fixture_sentinel'=>['preserve'=>'pcv']],
    'mind_poisoning'=>['playthrough_id'=>'unprofiled','floor_event_id'=>0,'events'=>[],'arrangements'=>$arrangements]];
mpCrossQuery($connection,'UPDATE core_npc_master SET extended_data=$1::jsonb,plugin_extended_data=$2::jsonb WHERE id=900001',[mpCrossJson($extended),mpCrossJson($plugins)]);
foreach([900002,900003,900004] as $id) mpCrossQuery($connection,'UPDATE core_npc_master SET extended_data=$1::jsonb,plugin_extended_data=$2::jsonb WHERE id=$3',[mpCrossJson(['absent_owner_sentinel'=>$id]),mpCrossJson(['private_conversation'=>['keep'=>$id]]),$id]);
$ownerBefore=mpCrossNpc($connection,900001);$absentBefore=[];
foreach([900002,900003,900004] as $id) $absentBefore[$id]=mpCrossNpc($connection,$id);
$store=new CorrespondencePgStore($connection,fn($s)=>$s->observedExecutor(),fn($title,$body)=>true);
$store->bindRequestClock(['inputtext','100','100','synthetic native fixture']);
$service=new CorrespondenceService($store);
$store->observeManaged(base64_encode(correspondenceJson(['source'=>'plugin_player_routing_v2','present_actors'=>[['form_id'=>hexdec('000A2C94'),'name'=>'Lydia','distance'=>10,'managed'=>true,'creature'=>false]]])));
$body="Camilla, I enclose 50 septims. Faendal lied to you and mocked you.\nSigned, Jarl Balgruuf.";
$doc=$service->compose(['request_id'=>correspondenceUuid(),'author'=>'player','payer'=>'player','recipient'=>'npc:900001','claimed_sender'=>'npc:900002','body'=>$body,'attachments'=>[['kind'=>'gold','form_id'=>'Skyrim.esm|0000000F','quantity'=>50]]]);
$sent=$service->dispatch($doc['document_id']);$op=$store->loadOperation($sent['operation_id']);
$receipt=array_intersect_key($op,array_flip(['protocol_version','document_id','operation_id','receipt_id','playthrough_id','scope_token','nonce']));
$receipt+=['status'=>'delivered','reason'=>'physical_only','note_form_id'=>12345,'attachments'=>[['form_id'=>15,'quantity'=>50,'before_source'=>100,'after_source'=>50,'before_recipient'=>0,'after_recipient'=>50]]];
$service->receive($receipt);
mpCrossCheck(mpCrossOne($connection,"SELECT count(*) amount FROM eventlog WHERE type='info' AND sess='written_evidence_v1'")['amount']==='0','possession_is_not_exposure');
$context=$service->prompt('Camilla');
mpCrossCheck(str_contains($context,'Jarl Balgruuf'),'real_recipient_context_missing');
mpCrossCheck(count($service->sourceReferences)===1,'exact_source_reference_required');
$eventId=$service->sourceReferences[0];
$event=mpCrossOne($connection,'SELECT type,sess,data,people FROM eventlog WHERE rowid=$1',[$eventId]);
mpCrossCheck($event['type']==='info' && $event['sess']==='written_evidence_v1','neutral_info_marker_carrier_required');
$source=json_decode($event['data'],true,64,JSON_THROW_ON_ERROR);
mpCrossCheck($source['body']===$body && $source['attachments'][0]['quantity']===50 && $event['people']==='|Camilla|','exact_real_producer_source_required');
mpCrossCheck(array_intersect(['payer','author','nonce'],array_keys($source))===[],'private_provenance_leaked_to_source');
$anchors=mpCrossOne($connection,"SELECT count(*) amount,count(*) FILTER (WHERE type='info') safe FROM eventlog WHERE sess='correspondence_v1'");
mpCrossCheck((int)$anchors['amount']>0 && $anchors['amount']===$anchors['safe'],'private_anchor_info_carrier_required');
// Catalog noise never becomes accepted written evidence, even if its JSON copies a valid envelope.
$ordinary=mpCrossOne($connection,"INSERT INTO eventlog(ts,gamets,type,data,sess,localts,people) VALUES(100,100,'info','ordinary non-JSON catalog noise','',100,'') RETURNING rowid");
$legacy=mpCrossOne($connection,"INSERT INTO eventlog(ts,gamets,type,data,sess,localts,people) VALUES(100,100,'written_evidence',$1,'written_evidence_v1',100,'|Camilla|') RETURNING rowid",[$event['data']]);
$ordinaryStatus=ChimMindPoisoning\writtenEvidenceStatus((int)$ordinary['rowid'],$native);
mpCrossCheck(($ordinaryStatus['status']??null)==='failed' && ($ordinaryStatus['reason']??null)==='source_unavailable','ordinary_info_must_be_ignored');
$legacyStatus=ChimMindPoisoning\writtenEvidenceStatus((int)$legacy['rowid'],$native);
mpCrossCheck(($legacyStatus['status']??null)==='failed' && ($legacyStatus['reason']??null)==='source_unavailable','raw_custom_carrier_must_be_rejected');
$pending=ChimMindPoisoning\writtenEvidenceStatus($eventId,$native);
mpCrossCheck(($pending['status']??null)==='pending','fresh_source_not_pending:'.mpCrossJson($pending));
$queued=ChimMindPoisoning\enqueueWrittenEvidence($eventId,$native);
mpCrossCheck(($queued['status']??null)==='pending','public_enqueue_not_pending:'.mpCrossJson($queued));
mpCrossCheck(mpCrossNpc($connection,900001)===$ownerBefore && mpCrossOne($connection,'SELECT count(*) amount FROM core_npc_master_history')['amount']==='0','status_enqueue_must_not_write');
$modelCalls=0;
$model=static function(array $messages)use(&$modelCalls,$body):string{
    $modelCalls++;
    $payload=json_decode($messages[1]['content']??'',true,64,JSON_THROW_ON_ERROR)['untrusted_data']??[];
    mpCrossCheck(($payload['written_evidence']['body']??null)===$body,'model_original_body_changed');
    mpCrossCheck(($payload['written_evidence']['attachments'][0]['quantity']??null)===50,'model_confirmed_attachment_changed');
    mpCrossCheck(array_keys($payload['candidates']??[])===['npc:900002','npc:900003'],'unexpected_candidate_or_hidden_payer');
    mpCrossCheck(array_intersect(['payer','author','speaker','listener','utterance'],array_keys($payload))===[],'model_private_or_speech_metadata_leak');
    mpCrossCheck(!str_contains(mpCrossJson($payload),'Dragonborn'),'hidden_actual_author_in_model_data');
    return mpCrossJson(['judgments'=>[
        ['subject'=>'npc:900002','subject_mentioned'=>true,'delta'=>2,'reason'=>'Recipient appreciates the attributed gift.','evidence'=>'I enclose 50 septims.','type_change'=>['from'=>'neutral','to'=>'grateful']],
        ['subject'=>'npc:900003','subject_mentioned'=>true,'delta'=>-1,'reason'=>'Recipient is suspicious of the written allegation.','evidence'=>'Faendal lied to you','type_change'=>['from'=>'neutral','to'=>'suspicious']],
    ]]);
};
$log=new ChimMindPoisoning\RequestLog(static function(string $record,string $level):void{},false);
$result=ChimMindPoisoning\evaluateWrittenEvidence($eventId,$native,$model,$log);
mpCrossCheck(($result['status']??null)==='processed' && ($result['outcome']??null)==='committed','normal_mp_commit_not_confirmed:'.mpCrossJson($result));
mpCrossCheck($modelCalls===1,'one_deterministic_model_call_required');
$ownerAfter=mpCrossNpc($connection,900001);$afterExtended=json_decode($ownerAfter['extended'],true,64,JSON_THROW_ON_ERROR);$afterPlugins=json_decode($ownerAfter['plugins'],true,64,JSON_THROW_ON_ERROR);
mpCrossCheck($afterExtended['relationships']['Jarl Balgruuf']['aff']===12 && $afterExtended['relationships']['Jarl Balgruuf']['type']==='grateful','claimed_sender_affinity_type_not_persisted');
mpCrossCheck($afterExtended['relationships']['Faendal']['aff']===3 && $afterExtended['relationships']['Faendal']['type']==='suspicious','third_party_opinion_not_persisted');
mpCrossCheck(correspondenceCanonicalJson($afterExtended['relationships']['Lydia'])===correspondenceCanonicalJson($extended['relationships']['Lydia']) && correspondenceCanonicalJson($afterExtended['fixture_untouched'])===correspondenceCanonicalJson($extended['fixture_untouched']),'unrelated_extended_state_changed');
mpCrossCheck(correspondenceCanonicalJson($afterPlugins['sworn_and_scorned'])===correspondenceCanonicalJson($plugins['sworn_and_scorned']) && correspondenceCanonicalJson($afterPlugins['private_conversation'])===correspondenceCanonicalJson($plugins['private_conversation']),'other_plugin_namespaces_changed');
mpCrossCheck(correspondenceCanonicalJson($afterPlugins['mind_poisoning']['arrangements'])===correspondenceCanonicalJson($arrangements),'existing_arrangement_changed');
$written=$afterPlugins['mind_poisoning']['written_receipts'][$doc['document_id']]??null;
mpCrossCheck(is_array($written)&&$written['event_id']===$eventId&&$written['receipt_id']===$source['receipt']['receipt_id']&&$written['operation_id']===$source['receipt']['operation_id']&&$written['outcome']==='committed','actual_written_receipt_not_persisted');
foreach($absentBefore as $id=>$before) mpCrossCheck(mpCrossNpc($connection,$id)===$before,'absent_actor_gained_knowledge_or_state');
$history=mpCrossOne($connection,"SELECT count(*) amount,count(*) FILTER(WHERE npc_id=900001 AND extended_data->>'_chim_history_source'='relationship') correct FROM core_npc_master_history");
mpCrossCheck($history===['amount'=>'1','correct'=>'1'],'normal_recipient_history_snapshot_required');
$snapshot=mpCrossOne($connection,'SELECT extended_data::text extended,plugin_extended_data::text plugins FROM core_npc_master_history WHERE npc_id=900001');
$snapshotExtended=json_decode($snapshot['extended'],true,64,JSON_THROW_ON_ERROR);unset($snapshotExtended['_chim_history_source']);
mpCrossCheck($snapshotExtended===$afterExtended && json_decode($snapshot['plugins'],true,64,JSON_THROW_ON_ERROR)===$afterPlugins,'normal_history_snapshot_mismatch');
$processed=ChimMindPoisoning\writtenEvidenceStatus($eventId,$native);
mpCrossCheck(($processed['status']??null)==='processed','public_processed_readback_failed');
$replay=ChimMindPoisoning\enqueueWrittenEvidence($eventId,$native);
$replayEvaluation=ChimMindPoisoning\evaluateWrittenEvidence($eventId,$native,$model,$log);
mpCrossCheck(($replay['status']??null)==='processed'&&($replayEvaluation['status']??null)==='processed'&&$modelCalls===1,'replay_did_not_reuse_actual_receipt');
mpCrossCheck(mpCrossNpc($connection,900001)===$ownerAfter&&mpCrossOne($connection,'SELECT count(*) amount FROM core_npc_master_history')['amount']==='1','replay_changed_opinions_namespace_or_history');
mpCrossCheck([array_key_exists('db',$GLOBALS),$GLOBALS['db']??null]===$globalDbBefore,'supplied_connection_path_mutated_global_db');
echo mpCrossJson(['status'=>'PASS','evidence'=>'real producer/store -> info/written_evidence_v1 -> public native MP -> deterministic model seam -> actual recipient affinity/types/third-party/MP receipt/history -> unchanged replay and coexisting namespaces','source_event_id'=>$eventId,'document_id'=>$doc['document_id'],'model_calls'=>$modelCalls,'provider_calls'=>0,'native_gameplay'=>'NOT RUN'])."\n";

