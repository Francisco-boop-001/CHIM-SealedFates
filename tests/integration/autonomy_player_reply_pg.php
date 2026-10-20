<?php
declare(strict_types=1);

// Guarded native regression for due dispatch of an NPC reply addressed to the player.
function autonomyPlayerReplyCheck(bool $ok, string $reason): void
{
    if (!$ok) throw new RuntimeException($reason);
}

function autonomyPlayerReplyQuery(PgSql\Connection $connection, string $sql, array $args = []): array
{
    $result = $args === [] ? @pg_query($connection, $sql) : @pg_query_params($connection, $sql, $args);
    autonomyPlayerReplyCheck($result instanceof PgSql\Result, 'fixture_sql_failed');
    try {
        return pg_num_fields($result) > 0 ? (pg_fetch_all($result) ?: []) : [];
    } finally {
        pg_free_result($result);
    }
}

ini_set('display_errors', '0');
ini_set('log_errors', '0');
set_exception_handler(static function (Throwable $ignored): void {
    fwrite(STDERR, "{\"status\":\"failed\",\"reason\":\"fixture_failed\"}\n");
    exit(1);
});

autonomyPlayerReplyCheck(PHP_SAPI === 'cli' && extension_loaded('pgsql'), 'native_cli_pgsql_required');
autonomyPlayerReplyCheck(getenv('WSL_DISTRO_NAME') === 'DwemerAI4Skyrim3-test', 'clone_only');
foreach (getenv() as $name => $_value) {
    autonomyPlayerReplyCheck(!str_starts_with(strtoupper((string)$name), 'PG'), 'postgres_environment_forbidden');
}

$databaseName = 'chim_correspondence_player_reply_it';
$connection = @pg_connect('host=/var/run/postgresql dbname='.$databaseName.' user=postgres connect_timeout=3 application_name=correspondence_player_reply_it', PGSQL_CONNECT_FORCE_NEW);
autonomyPlayerReplyCheck($connection instanceof PgSql\Connection, 'owned_peer_database_required');
$peer = @pg_connect('host=/var/run/postgresql dbname='.$databaseName.' user=postgres connect_timeout=3 application_name=correspondence_player_reply_it_peer', PGSQL_CONNECT_FORCE_NEW);
autonomyPlayerReplyCheck($peer instanceof PgSql\Connection, 'second_owned_peer_session_required');
$identity = autonomyPlayerReplyQuery($connection, "SELECT current_database() db,current_user role,current_setting('listen_addresses') listeners,(inet_server_addr() IS NULL)::int socket_only")[0] ?? [];
autonomyPlayerReplyCheck($identity === ['db'=>$databaseName,'role'=>'postgres','listeners'=>'','socket_only'=>'1'], 'owned_socket_database_only');
$peerIdentity = autonomyPlayerReplyQuery($peer, "SELECT current_database() db,current_user role,current_setting('listen_addresses') listeners,(inet_server_addr() IS NULL)::int socket_only")[0] ?? [];
autonomyPlayerReplyCheck($peerIdentity === $identity, 'second_session_same_owned_socket_database');
$tables = autonomyPlayerReplyQuery($connection, "SELECT count(*)::int amount FROM information_schema.tables WHERE table_schema IN ('public','plugins','chim_meta')")[0] ?? [];
autonomyPlayerReplyCheck(($tables['amount'] ?? null) === '0', 'blank_owned_database_required');

$ddl = <<<'SQL'
CREATE SCHEMA chim_meta;
CREATE TABLE chim_meta.playthrough_profiles(id bigint PRIMARY KEY,is_active boolean NOT NULL DEFAULT FALSE);
CREATE TABLE public.eventlog(rowid bigserial PRIMARY KEY,ts bigint NOT NULL,gamets numeric NOT NULL,type text NOT NULL,data text NOT NULL,sess text NOT NULL,localts bigint NOT NULL,people text NOT NULL,location text NOT NULL,party text NOT NULL);
CREATE TABLE public.core_npc_master(id bigint PRIMARY KEY,npc_name text NOT NULL,refid text NOT NULL,profile_id bigint,personality text NOT NULL DEFAULT '',extended_data jsonb NOT NULL DEFAULT '{}'::jsonb,metadata jsonb NOT NULL DEFAULT '{}'::jsonb);
CREATE TABLE public.core_player(id text PRIMARY KEY,value text NOT NULL);
CREATE TABLE public.game_plugins(plugin_name text NOT NULL,formid_prefix text NOT NULL);
CREATE TABLE public.books(id bigserial PRIMARY KEY,ts bigint NOT NULL,gamets numeric NOT NULL,content text NOT NULL,sess text NOT NULL,localts bigint NOT NULL,title text NOT NULL);
CREATE TABLE public.responselog(id bigserial PRIMARY KEY,localts bigint NOT NULL,sent integer NOT NULL,actor text NOT NULL,text text NOT NULL,action text NOT NULL,tag text NOT NULL);
INSERT INTO chim_meta.playthrough_profiles(id,is_active) VALUES(810000012,TRUE);
INSERT INTO public.core_player(id,value) VALUES('player_name','Dragonborn');
INSERT INTO public.game_plugins(plugin_name,formid_prefix) VALUES('Skyrim.esm','00');
INSERT INTO public.core_npc_master(id,npc_name,refid,profile_id,personality,extended_data,metadata) VALUES
 (900001,'Camilla','00013488',810000012,'Thoughtful and direct.','{"background_life_enabled":true,"background_life_letters":true,"relationships":{}}','{"stats":{"is_dead":false},"last_coords":[0,0,0,"Riverwood"]}'),
 (900002,'Sigrid','00013BBD',810000012,'Practical.','{"background_life_enabled":true,"background_life_letters":true,"relationships":{}}','{"stats":{"is_dead":false},"last_coords":[0,0,0,"Riverwood"]}'),
 (900003,'Delphine','000A2C94',810000012,'Reserved.','{"background_life_enabled":true,"background_life_letters":true,"relationships":{}}','{"stats":{"is_dead":false},"last_coords":[0,0,0,"Riverwood"]}');
INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(1,1000000000,'inputtext','synthetic player-reply fixture','',1,'','','');
SQL;
autonomyPlayerReplyQuery($connection, $ddl);

$project = dirname(__DIR__, 2);
require_once $project.'/server/lib/store.php';
require_once $project.'/server/lib/boundaries.php';
require_once $project.'/server/lib/autonomy_policy.php';
require_once $project.'/server/lib/autonomy_store.php';

$store = new CorrespondencePgStore(
    $connection,
    static fn($store) => $store->observedExecutor(),
    static fn(string $title, string $body): bool => true
);
$store->ensureSchema();
$service = new CorrespondenceService($store);
$autonomy = new CorrespondenceAutonomyPg($store, $service);
$autonomy->ensureSchema();
$autonomy->setEnabled(true);

$gamets = 1000000000;
$ts = 1;
$bindClock = static function (int $targetGamets) use (&$gamets, &$ts, $connection, $store): void {
    $rows = autonomyPlayerReplyQuery($connection, 'SELECT COALESCE(MAX(ts),0)::text ts,COALESCE(MAX(gamets),0)::text gamets FROM public.eventlog');
    $latest = $rows[0] ?? [];
    $ts = max($ts + 1, (int)($latest['ts'] ?? 0) + 1);
    $gamets = max($gamets, $targetGamets, (int)($latest['gamets'] ?? 0));
    $store->bindRequestClock(['inputtext',(string)$ts,(string)$gamets,'synthetic player-reply fixture']);
};
$presence = base64_encode(correspondenceJson([
    'source'=>'plugin_player_routing_v2',
    'present_actors'=>[['form_id'=>hexdec('000A2C94'),'name'=>'Delphine','distance'=>10,'managed'=>true,'creature'=>false]],
]));
$bindClock($gamets);
$store->observeManaged($presence);

$bindClock($gamets);
$sourceDocument = $service->compose([
    'request_id'=>correspondenceUuid(),
    'author'=>'player',
    'payer'=>'player',
    'recipient'=>'npc:900001',
    'claimed_sender'=>'player',
    'body'=>'Camilla, please reply about the plans for Riverwood.',
    'attachments'=>[],
]);
$bindClock($gamets);
$incomingDispatch = $service->dispatch($sourceDocument['document_id']);
autonomyPlayerReplyCheck(($incomingDispatch['state'] ?? null) === 'dispatched' && is_string($incomingDispatch['operation_id'] ?? null), 'incoming_dispatch_failed');
$incomingOperation = $store->loadOperation($incomingDispatch['operation_id']);
autonomyPlayerReplyCheck(is_array($incomingOperation), 'incoming_operation_missing');
$receipt = array_intersect_key($incomingOperation, array_flip(['protocol_version','document_id','operation_id','receipt_id','playthrough_id','scope_token','nonce']));
$receipt += ['status'=>'delivered','reason'=>'physical_only','note_form_id'=>12345,'attachments'=>[]];
$bindClock($gamets);
$service->receive($receipt);
$bindClock($gamets);
$sourcePrompt = $service->prompt('Camilla');
autonomyPlayerReplyCheck($sourcePrompt !== '' && count($service->sourceReferences) === 1, 'source_must_enter_normal_npc_context');

$twoHours = correspondenceAutonomyGamets(2);
$twelveHours = correspondenceAutonomyGamets(12);
$bindClock($gamets + $twoHours);
$store->observeManaged($presence);
$modelCalls = 0;
$sourceKeys = null;
$queued = $autonomy->tick(static function (array $messages) use (&$modelCalls, &$sourceKeys): string {
    $modelCalls++;
    if (count($messages) !== 2 || ($messages[1]['role'] ?? null) !== 'user' || !is_string($messages[1]['content'] ?? null)) {
        throw new RuntimeException('model_message_shape_invalid');
    }
    $payload = json_decode($messages[1]['content'], true, 64, JSON_THROW_ON_ERROR);
    $sourceKeys = $payload['source']['claimed_sender'] ?? null;
    if (!is_array($sourceKeys) || ($sourceKeys['kind'] ?? null) !== 'player' || ($sourceKeys['name'] ?? null) !== 'Dragonborn') {
        throw new RuntimeException('player_claim_must_be_in_model_context');
    }
    return json_encode(['action'=>'reply','recipient'=>'player','body'=>'I can respond directly to you about the Riverwood plans.','reason'=>'The player asked for a direct reply.'], JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
});
$queuedAtGamets = $gamets;
autonomyPlayerReplyCheck(($queued['status'] ?? null) === 'queued' && ($queued['model_calls'] ?? null) === 1 && $modelCalls === 1, 'player_reply_must_queue_once');
$decisionRows = autonomyPlayerReplyQuery($connection, 'SELECT playthrough_id,source_document_id::text source_id,status,action,child_document_id::text child,due_gamets::text due FROM plugins.correspondence_autonomy_decisions');
autonomyPlayerReplyCheck(count($decisionRows) === 1, 'one_decision_in_active_case_scope');
$decision = $decisionRows[0];
autonomyPlayerReplyCheck(($decision['playthrough_id'] ?? null) === '810000012' && ($decision['source_id'] ?? null) === $sourceDocument['document_id']
    && ($decision['status'] ?? null) === 'queued' && ($decision['action'] ?? null) === 'reply'
    && is_string($decision['child'] ?? null) && is_string($decision['due'] ?? null), 'queued_player_reply_decision_required');
$expectedDue = $queuedAtGamets + $twelveHours;
autonomyPlayerReplyCheck((int)$decision['due'] === $expectedDue, 'player_reply_must_use_twelve_hour_delay');
$child = $store->loadDocument($decision['child']);
autonomyPlayerReplyCheck(is_array($child) && ($child['recipient']['kind'] ?? null) === 'player'
    && ($child['recipient']['name'] ?? null) === 'Dragonborn'
    && ($child['author']['kind'] ?? null) === 'npc' && ($child['author']['id'] ?? null) === 900001
    && ($child['payer']['kind'] ?? null) === 'npc' && ($child['payer']['id'] ?? null) === 900001
    && ($child['claimed_sender']['kind'] ?? null) === 'npc' && ($child['claimed_sender']['id'] ?? null) === 900001
    && ($child['attachments'] ?? null) === [] && ($child['operation_id'] ?? null) === null, 'queued_child_must_be_player_bound_npc_reply');

$bindClock($queuedAtGamets + $twelveHours);
$store->observeManaged($presence);
$dueCallbackCalls = 0;
$dueTick = $autonomy->tick(static function (array $unused) use (&$dueCallbackCalls): string {
    $dueCallbackCalls++;
    throw new RuntimeException('due_dispatch_must_not_call_model');
});
autonomyPlayerReplyCheck(($dueTick['status'] ?? null) === 'dispatched' && ($dueTick['model_calls'] ?? null) === 0
    && $dueCallbackCalls === 0, 'due_player_reply_must_dispatch_without_model');
$decisionAfterDispatch = autonomyPlayerReplyQuery($connection, 'SELECT status FROM plugins.correspondence_autonomy_decisions WHERE source_document_id=$1', [$sourceDocument['document_id']])[0] ?? [];
autonomyPlayerReplyCheck(($decisionAfterDispatch['status'] ?? null) === 'dispatched', 'player_reply_decision_must_be_dispatched');
$child = $store->loadDocument($decision['child']);
autonomyPlayerReplyCheck(is_array($child) && is_string($child['operation_id'] ?? null), 'dispatched_child_operation_required');
$operation = $store->loadOperation($child['operation_id']);
autonomyPlayerReplyCheck(is_array($operation), 'player_reply_operation_missing');
$playerReceipt = array_intersect_key($operation, array_flip(['protocol_version','document_id','operation_id','receipt_id','playthrough_id','scope_token','nonce']));
$playerReceipt += ['status'=>'delivered','reason'=>'physical_only','note_form_id'=>12345,'attachments'=>[]];
$bindClock($gamets);
$service->receive($playerReceipt);
$bindClock($gamets);
$readback = $service->readPlayer($decision['child']);
$readbackKeys = array_keys($readback);
sort($readbackKeys);
autonomyPlayerReplyCheck($readbackKeys === ['attachments','body','claimed_sender','document_id']
    && ($readback['document_id'] ?? null) === $decision['child']
    && ($readback['body'] ?? null) === $child['body']
    && ($readback['claimed_sender']['kind'] ?? null) === 'npc' && ($readback['claimed_sender']['id'] ?? null) === 900001
    && ($readback['attachments'] ?? null) === [], 'player_inbox_readback_must_match_delivered_reply');
autonomyPlayerReplyCheck($service->sourceReferences === [] && $service->knownSourceReferences === [], 'player_readback_must_not_create_npc_source_exposure');
autonomyPlayerReplyCheck(autonomyPlayerReplyQuery($connection, "SELECT rowid FROM public.eventlog WHERE type='info' AND sess='written_evidence_v1' AND data::jsonb->>'document_id'=$1", [$decision['child']]) === [], 'player_reply_must_not_publish_npc_written_evidence');

fwrite(STDOUT, json_encode([
    'status'=>'PASS',
    'active_profile_id'=>810000012,
    'player_reply_queued'=>true,
    'due_player_reply_dispatched'=>true,
    'due_model_calls'=>0,
    'player_readback_exact'=>true,
    'npc_source_exposure_from_player_read'=>false,
    'model_calls'=>$modelCalls,
], JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
