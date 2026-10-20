<?php
declare(strict_types=1);

// N01 real-store regression. Root runs this only against its blank disposable socket database.
final class AutonomyConnectorFixtureFailure extends RuntimeException
{
    public function __construct(string $reason, public readonly int $assertionLine)
    {
        parent::__construct($reason);
    }
}

function autonomyConnectorCheck(bool $ok, string $reason): void
{
    if (!$ok) {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? [];
        throw new AutonomyConnectorFixtureFailure($reason, (int)($caller['line'] ?? 0));
    }
}

function autonomyConnectorQuery(PgSql\Connection $connection, string $sql, array $args = []): array
{
    $result = $args === [] ? @pg_query($connection, $sql) : @pg_query_params($connection, $sql, $args);
    autonomyConnectorCheck($result instanceof PgSql\Result, 'fixture_sql_failed');
    try { return pg_num_fields($result) > 0 ? (pg_fetch_all($result) ?: []) : []; }
    finally { pg_free_result($result); }
}

function autonomyConnectorReply(string $recipient): string
{
    return json_encode(['action'=>'reply','recipient'=>$recipient,'body'=>'I will explain my concern.','reason'=>'The sender asked for an explanation.'], JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}

function autonomyConnectorSilence(): string
{
    return json_encode(['action'=>'none','recipient'=>null,'body'=>'','reason'=>'I need more time.'], JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}

ini_set('display_errors', '0');
ini_set('log_errors', '0');
set_exception_handler(static function (Throwable $error): void {
    $failure = $error instanceof AutonomyConnectorFixtureFailure
        ? ['status'=>'failed','reason'=>$error->getMessage(),'line'=>$error->assertionLine]
        : ['status'=>'failed','reason'=>'fixture_exception','type'=>get_class($error),'file'=>basename($error->getFile()),'line'=>$error->getLine()];
    fwrite(STDERR, json_encode($failure, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
    exit(1);
});

autonomyConnectorCheck(PHP_SAPI === 'cli' && extension_loaded('pgsql'), 'native_cli_pgsql_required');
autonomyConnectorCheck(getenv('WSL_DISTRO_NAME') === 'DwemerAI4Skyrim3-test', 'clone_only');
foreach (getenv() as $name => $_value) autonomyConnectorCheck(!str_starts_with(strtoupper((string)$name), 'PG'), 'postgres_environment_forbidden');

$databaseName = 'chim_correspondence_connector_n01_it';
$connection = @pg_connect('host=/var/run/postgresql dbname='.$databaseName.' user=postgres connect_timeout=3 application_name=correspondence_connector_n01_it', PGSQL_CONNECT_FORCE_NEW);
autonomyConnectorCheck($connection instanceof PgSql\Connection, 'owned_peer_database_required');
$peer = @pg_connect('host=/var/run/postgresql dbname='.$databaseName.' user=postgres connect_timeout=3 application_name=correspondence_connector_n01_it_peer', PGSQL_CONNECT_FORCE_NEW);
autonomyConnectorCheck($peer instanceof PgSql\Connection, 'second_owned_peer_session_required');
$identity = autonomyConnectorQuery($connection, "SELECT current_database() db,current_user role,current_setting('listen_addresses') listeners,(inet_server_addr() IS NULL)::int socket_only")[0] ?? [];
autonomyConnectorCheck($identity === ['db'=>$databaseName,'role'=>'postgres','listeners'=>'','socket_only'=>'1'], 'owned_socket_database_only');
$peerIdentity = autonomyConnectorQuery($peer, "SELECT current_database() db,current_user role,current_setting('listen_addresses') listeners,(inet_server_addr() IS NULL)::int socket_only")[0] ?? [];
autonomyConnectorCheck($peerIdentity === $identity, 'second_session_same_owned_socket_database');
$tables = autonomyConnectorQuery($connection, "SELECT count(*)::int amount FROM information_schema.tables WHERE table_schema IN ('public','plugins','chim_meta')")[0] ?? [];
autonomyConnectorCheck(($tables['amount'] ?? null) === '0', 'blank_owned_database_required');

$project = dirname(__DIR__, 2);
require_once $project.'/server/lib/store.php';
require_once $project.'/server/lib/boundaries.php';
require_once $project.'/server/lib/autonomy_policy.php';
require_once $project.'/server/lib/autonomy_store.php';
require_once $project.'/server/lib/autonomy_host.php';

$ddl = <<<'SQL'
CREATE SCHEMA chim_meta;
CREATE TABLE chim_meta.playthrough_profiles(id bigint PRIMARY KEY,is_active boolean NOT NULL DEFAULT FALSE);
CREATE TABLE public.eventlog(rowid bigserial PRIMARY KEY,ts bigint NOT NULL,gamets numeric NOT NULL,type text NOT NULL,data text NOT NULL,sess text NOT NULL,localts bigint NOT NULL,people text NOT NULL,location text NOT NULL,party text NOT NULL);
CREATE TABLE public.core_npc_master(id bigint PRIMARY KEY,npc_name text NOT NULL,refid text NOT NULL,profile_id bigint,personality text NOT NULL DEFAULT '',extended_data jsonb NOT NULL DEFAULT '{}'::jsonb,metadata jsonb NOT NULL DEFAULT '{}'::jsonb);
CREATE TABLE public.core_player(id text PRIMARY KEY,value text NOT NULL);
CREATE TABLE public.game_plugins(plugin_name text NOT NULL,formid_prefix text NOT NULL);
CREATE TABLE public.books(id bigserial PRIMARY KEY,ts bigint NOT NULL,gamets numeric NOT NULL,content text NOT NULL,sess text NOT NULL,localts bigint NOT NULL,title text NOT NULL);
CREATE TABLE public.responselog(id bigserial PRIMARY KEY,localts bigint NOT NULL,sent integer NOT NULL,actor text NOT NULL,text text NOT NULL,action text NOT NULL,tag text NOT NULL);
INSERT INTO chim_meta.playthrough_profiles(id,is_active) VALUES(810000099,TRUE);
INSERT INTO public.core_player(id,value) VALUES('player_name','Dragonborn');
INSERT INTO public.game_plugins(plugin_name,formid_prefix) VALUES('Skyrim.esm','00');
INSERT INTO public.core_npc_master(id,npc_name,refid,profile_id,personality,extended_data,metadata) VALUES
 (900001,'Camilla','00013488',810000099,'Thoughtful and direct.','{"background_life_enabled":true,"background_life_letters":true,"relationships":{}}','{"stats":{"is_dead":false},"last_coords":[0,0,0,"Riverwood"]}'),
 (900002,'Jarl Balgruuf','00013BBD',810000099,'Practical.','{"background_life_enabled":true,"background_life_letters":true,"relationships":{}}','{"stats":{"is_dead":false},"last_coords":[0,0,0,"Riverwood"]}'),
 (900003,'Lydia','000A2C94',810000099,'Reserved.','{"background_life_enabled":true,"background_life_letters":true,"relationships":{}}','{"stats":{"is_dead":false},"last_coords":[0,0,0,"Riverwood"]}');
INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(1,1000000000,'inputtext','synthetic connector N01 fixture','',1,'','','');
SQL;
autonomyConnectorQuery($connection, $ddl);

$store = new CorrespondencePgStore(
    $connection,
    static fn($store) => $store->observedExecutor(),
    static fn(string $title, string $body): bool => true
);
$store->ensureSchema();
$store->bindRequestClock(['inputtext','1','1000000000','synthetic connector N01 fixture']);
$service = new CorrespondenceService($store);
$autonomy = new CorrespondenceAutonomyPg($store, $service);
$autonomy->ensureSchema();
$autonomy->setEnabled(true);

$presence = base64_encode(correspondenceJson([
    'source'=>'plugin_player_routing_v2',
    'present_actors'=>[['form_id'=>hexdec('000A2C94'),'name'=>'Lydia','distance'=>10,'managed'=>true,'creature'=>false]],
]));
$store->observeManaged($presence);

$gamets = 1000000000;
$ts = 1;
$bindNativeRequest = static function (int $targetGamets) use (&$gamets, &$ts, $connection): array {
    $gamets = max($gamets, $targetGamets);
    $ts++;
    autonomyConnectorQuery($connection, "INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES($1,$2,'inputtext','synthetic native connector request','',$1,'','','')", [(string)$ts,(string)$gamets]);
    return ['inputtext',(string)$ts,(string)$gamets,'synthetic native connector request'];
};
$runtimeFactory = static fn(): array => ['store'=>$store,'autonomy'=>$autonomy];
$runNative = static function (int $targetGamets, callable $modelFactory) use ($bindNativeRequest, $store, $presence, $runtimeFactory): array {
    $request = $bindNativeRequest($targetGamets);
    $store->bindRequestClock($request);
    $store->observeManaged($presence);
    return correspondenceAutonomyRunNativePostrequest($request, $runtimeFactory, $modelFactory);
};
$makeExposedSource = static function (string $body, string $recipient, string $sender, string $recipientName) use ($store, $service, $presence): string {
    $store->observeManaged($presence);
    $document = $service->compose([
        'request_id'=>correspondenceUuid(),'author'=>'player','payer'=>'player','recipient'=>$recipient,
        'claimed_sender'=>$sender,'body'=>$body,'attachments'=>[],
    ]);
    $dispatch = $service->dispatch($document['document_id']);
    autonomyConnectorCheck(($dispatch['state'] ?? null) === 'dispatched' && is_string($dispatch['operation_id'] ?? null), 'incoming_dispatch_failed');
    $operation = $store->loadOperation($dispatch['operation_id']);
    autonomyConnectorCheck(is_array($operation), 'incoming_operation_missing');
    $receipt = array_intersect_key($operation, array_flip(['protocol_version','document_id','operation_id','receipt_id','playthrough_id','scope_token','nonce']));
    $receipt += ['status'=>'delivered','reason'=>'physical_only','note_form_id'=>12345,'attachments'=>[]];
    $service->receive($receipt);
    autonomyConnectorCheck($service->prompt($recipientName) !== '', 'source_must_enter_normal_npc_context');
    return $document['document_id'];
};
$decisionFor = static function (string $sourceId) use ($connection): array {
    return autonomyConnectorQuery($connection, 'SELECT playthrough_id,source_document_id::text source_id,source_event_id::text event_id,claim_id::text claim_id,status,child_document_id::text child,payload->>\'reason_code\' reason,payload->>\'model_calls\' model_calls,payload::text payload FROM plugins.correspondence_autonomy_decisions WHERE source_document_id=$1', [$sourceId])[0] ?? [];
};

$twoHours = correspondenceAutonomyGamets(2);
$sixHours = correspondenceAutonomyGamets(6);
$sourceQueued = $makeExposedSource('A due reply must dispatch while the connector is unavailable.','npc:900001','npc:900002','Camilla');
$sourceDeferred = $makeExposedSource('This source must stay eligible while the connector is unavailable.','npc:900003','npc:900002','Lydia');
$providerCalls = 0;
$configuredFactoryCalls = 0;
$queuedTick = $runNative($gamets + $twoHours, static function () use (&$configuredFactoryCalls, &$providerCalls): callable {
    $configuredFactoryCalls++;
    return static function (array $messages) use (&$providerCalls): string {
        $providerCalls++;
        return autonomyConnectorReply('npc:900002');
    };
});
$queuedDecision = $decisionFor($sourceQueued);
autonomyConnectorCheck(($queuedTick['status'] ?? null) === 'queued' && ($queuedTick['model_calls'] ?? null) === 1
    && $configuredFactoryCalls === 1 && $providerCalls === 1, 'configured_source_must_queue_one_reply');
autonomyConnectorCheck(($queuedDecision['playthrough_id'] ?? null) === '810000099' && ($queuedDecision['source_id'] ?? null) === $sourceQueued
    && ($queuedDecision['status'] ?? null) === 'queued' && is_string($queuedDecision['child'] ?? null), 'queued_reply_must_use_expected_scope_and_identity');

$queuedRow = autonomyConnectorQuery($connection, 'SELECT due_gamets::text due FROM plugins.correspondence_autonomy_decisions WHERE source_document_id=$1', [$sourceQueued])[0] ?? [];
autonomyConnectorCheck(preg_match('/\A[1-9][0-9]*\z/D', (string)($queuedRow['due'] ?? '')) === 1, 'queued_reply_due_time_missing');
$dueGamets = (int)$queuedRow['due'];
$unavailableCalls = 0;
$dispatchWhileUnavailable = $runNative(max($dueGamets + 1, $gamets + $sixHours + 1), static function () use (&$unavailableCalls): ?callable {
    $unavailableCalls++;
    return null;
});
$dispatchedDecision = $decisionFor($sourceQueued);
autonomyConnectorCheck(($dispatchWhileUnavailable['status'] ?? null) === 'dispatched' && ($dispatchWhileUnavailable['model_calls'] ?? null) === 0
    && $providerCalls === 1 && $unavailableCalls <= 1 && ($dispatchedDecision['status'] ?? null) === 'dispatched'
    && ($dispatchedDecision['child'] ?? null) === ($queuedDecision['child'] ?? null),
    'due_child_must_dispatch_with_missing_connector_without_model_request');

$decisionCountBeforeUnavailable = autonomyConnectorQuery($connection, 'SELECT count(*)::int amount FROM plugins.correspondence_autonomy_decisions')[0]['amount'] ?? null;
$completionCountBeforeUnavailable = autonomyConnectorQuery($connection, "SELECT count(*)::int amount FROM public.eventlog WHERE sess='correspondence_v1' AND data::jsonb->>'kind'='autonomy_completion'")[0]['amount'] ?? null;
$deferredTick = $runNative($gamets + $sixHours + 2, static function () use (&$unavailableCalls): ?callable {
    $unavailableCalls++;
    return null;
});
$decisionCountAfterUnavailable = autonomyConnectorQuery($connection, 'SELECT count(*)::int amount FROM plugins.correspondence_autonomy_decisions')[0]['amount'] ?? null;
$completionCountAfterUnavailable = autonomyConnectorQuery($connection, "SELECT count(*)::int amount FROM public.eventlog WHERE sess='correspondence_v1' AND data::jsonb->>'kind'='autonomy_completion'")[0]['amount'] ?? null;
$deferredDecision = $decisionFor($sourceDeferred);
autonomyConnectorCheck(($deferredTick['status'] ?? null) === 'idle' && ($deferredTick['model_calls'] ?? null) === 0
    && $providerCalls === 1 && $decisionCountAfterUnavailable === $decisionCountBeforeUnavailable
    && $completionCountAfterUnavailable === $completionCountBeforeUnavailable && $deferredDecision === [],
    'missing_connector_must_leave_source_eligible_without_decision_cooldown_or_model_attempt');

$modelReadyCalls = 0;
$sameSourceReady = $runNative($gamets + $sixHours + 3, static function () use (&$modelReadyCalls): callable {
    return static function (array $messages) use (&$modelReadyCalls): string {
        $modelReadyCalls++;
        return autonomyConnectorSilence();
    };
});
$deferredDecision = $decisionFor($sourceDeferred);
autonomyConnectorCheck(($sameSourceReady['status'] ?? null) === 'silent' && ($sameSourceReady['model_calls'] ?? null) === 1
    && $modelReadyCalls === 1 && ($deferredDecision['playthrough_id'] ?? null) === '810000099'
    && ($deferredDecision['source_id'] ?? null) === $sourceDeferred && ($deferredDecision['status'] ?? null) === 'silent',
    'same_source_must_proceed_once_after_connector_is_configured');
$sameSourceReplay = $runNative($gamets + $sixHours + 4, static function () use (&$modelReadyCalls): callable {
    return static function (array $messages) use (&$modelReadyCalls): string {
        $modelReadyCalls++;
        return autonomyConnectorSilence();
    };
});
autonomyConnectorCheck(($sameSourceReplay['model_calls'] ?? null) === 0 && $modelReadyCalls === 1
    && $decisionFor($sourceDeferred) === $deferredDecision, 'configured_source_must_not_create_a_second_decision');

$sourceRequestFailure = $makeExposedSource('A real request exception remains terminal.','npc:900001','npc:900003','Camilla');
$attemptsBeforeFailure = 0;
$requestFailureTick = $runNative($gamets + $twoHours + $sixHours + 5, static function () use (&$attemptsBeforeFailure): callable {
    return static function (array $messages) use (&$attemptsBeforeFailure): string {
        $attemptsBeforeFailure++;
        throw new RuntimeException('synthetic request timeout');
    };
});
$requestFailure = $decisionFor($sourceRequestFailure);
autonomyConnectorCheck(($requestFailureTick['status'] ?? null) === 'failed' && ($requestFailureTick['model_calls'] ?? null) === 1
    && $attemptsBeforeFailure === 1 && ($requestFailure['status'] ?? null) === 'failed'
    && ($requestFailure['reason'] ?? null) === 'model_error' && ($requestFailure['model_calls'] ?? null) === '1'
    && ($requestFailure['child'] ?? null) === null, 'invoked_request_exception_must_be_one_terminal_attempt');
$attemptsAfterFailure = 0;
$requestFailureReplay = $runNative($gamets + $twoHours + $sixHours + 5 + $sixHours + 1, static function () use (&$attemptsAfterFailure): callable {
    return static function (array $messages) use (&$attemptsAfterFailure): string {
        $attemptsAfterFailure++;
        return autonomyConnectorSilence();
    };
});
autonomyConnectorCheck(($requestFailureReplay['model_calls'] ?? null) === 0 && $attemptsAfterFailure === 0
    && $decisionFor($sourceRequestFailure) === $requestFailure, 'actual_request_failure_must_not_retry_automatically');

// Old model_unavailable rows can have entered a request callback; retain them as terminal audit records.
$sourceLegacyUnavailable = $makeExposedSource('A legacy model_unavailable decision must not be retried blindly.','npc:900003','npc:900002','Lydia');
$legacyAttempts = 0;
$legacyTick = $runNative($gamets + $twoHours + $sixHours * 2 + 2, static function () use (&$legacyAttempts): callable {
    return static function (array $messages) use (&$legacyAttempts): string {
        $legacyAttempts++;
        throw new DomainException('model_unavailable');
    };
});
$legacyFailure = $decisionFor($sourceLegacyUnavailable);
$legacyPayload = json_decode((string)($legacyFailure['payload'] ?? ''), true, 64, JSON_THROW_ON_ERROR);
autonomyConnectorCheck(($legacyTick['status'] ?? null) === 'failed' && ($legacyTick['model_calls'] ?? null) === 1
    && $legacyAttempts === 1 && ($legacyFailure['status'] ?? null) === 'failed'
    && ($legacyFailure['reason'] ?? null) === 'model_unavailable' && ($legacyFailure['model_calls'] ?? null) === '1'
    && ($legacyFailure['playthrough_id'] ?? null) === '810000099' && ($legacyFailure['source_id'] ?? null) === $sourceLegacyUnavailable
    && is_array($legacyPayload['claim_anchor'] ?? null) && $store->eventMatches($legacyPayload['claim_anchor'])
    && is_array($legacyPayload['completion_anchor'] ?? null) && $store->eventMatches($legacyPayload['completion_anchor'])
    && ($legacyFailure['child'] ?? null) === null, 'legacy_model_unavailable_failure_must_have_valid_scope_identity_anchors_and_no_child');
$legacyCompletion = autonomyConnectorQuery($connection, "SELECT data::jsonb->>'completed_status' status,data::jsonb->>'child_document_id' child FROM public.eventlog WHERE sess='correspondence_v1' AND data::jsonb->>'kind'='autonomy_completion' AND data::jsonb->>'claim_id'=$1", [(string)($legacyFailure['claim_id'] ?? '')]);
autonomyConnectorCheck(count($legacyCompletion) === 1 && ($legacyCompletion[0]['status'] ?? null) === 'failed'
    && ($legacyCompletion[0]['child'] ?? null) === null, 'legacy_failure_audit_must_remain_without_child');
$legacyRetryCalls = 0;
$legacyRetry = $runNative($gamets + $twoHours + $sixHours * 2 + 2 + $sixHours + 1, static function () use (&$legacyRetryCalls): callable {
    return static function (array $messages) use (&$legacyRetryCalls): string {
        $legacyRetryCalls++;
        return autonomyConnectorSilence();
    };
});
autonomyConnectorCheck(($legacyRetry['model_calls'] ?? null) === 0 && $legacyRetryCalls === 0
    && $decisionFor($sourceLegacyUnavailable) === $legacyFailure,
    'legacy_model_unavailable_row_must_remain_terminal_and_keep_its_audit_history');

fwrite(STDOUT, json_encode([
    'status'=>'PASS',
    'missing_connector_dispatches_due_child'=>true,
    'missing_connector_leaves_source_unclaimed'=>true,
    'configured_source_processed_once'=>true,
    'request_exception_attempts'=>1,
    'actual_failure_is_terminal'=>true,
    'legacy_model_unavailable_keeps_audit_and_is_not_retried'=>true,
], JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
