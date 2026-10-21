<?php
declare(strict_types=1);

require_once __DIR__.'/../support/recovery_pg_fixture.php';

$GLOBALS['autonomyAccountingFailures'] = [];

function autonomyAccountingCheck(bool $condition, string $reason): void
{
    if (!$condition) $GLOBALS['autonomyAccountingFailures'][] = $reason;
}

function autonomyAccountingRequire(bool $condition, string $reason): void
{
    if (!$condition) throw new RuntimeException($reason);
}

function autonomyAccountingSql(PgSql\Connection $connection, string $sql, array $args = []): array
{
    $result = $args === [] ? pg_query($connection, $sql) : pg_query_params($connection, $sql, $args);
    if (!$result instanceof PgSql\Result) throw new RuntimeException('accounting_fixture_sql_failed:'.pg_last_error($connection));
    try { return pg_num_fields($result) > 0 ? (pg_fetch_all($result) ?: []) : []; }
    finally { pg_free_result($result); }
}

function autonomyAccountingReply(string $recipient): string
{
    return json_encode([
        'action'=>'reply','recipient'=>$recipient,'body'=>'I will answer this carefully.','reason'=>'The sender requested a response.',
    ], JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}

function autonomyAccountingSafeOutcome(array $result): array
{
    $safe = [];
    if (in_array($result['status'] ?? null, ['idle','paused','skipped','failed','stale','silent','queued','dispatched','unknown'], true)) $safe['status'] = $result['status'];
    if (in_array($result['reason'] ?? null, ['queued_for_transit','dispatch_queued','cooldown','identity_changed','actor_identity_changed','source_not_due','actor_unavailable','player_unavailable','source_unavailable','no_eligible_source','model_unavailable','model_error','invalid_decision','storage_error','already_claimed','chain_limit','abandoned_claim_retired','executor_unavailable','dispatch_uncertain','dispatch_unknown','dispatch_already_claimed','child_missing','decision_anchor_invalid','child_changed','actor_changed','source_changed','silence','invalid_delay'], true)) $safe['reason'] = $result['reason'];
    if (is_int($result['model_calls'] ?? null) && $result['model_calls'] >= 0 && $result['model_calls'] <= 1) $safe['model_calls'] = $result['model_calls'];
    if (is_string($result['correlation_id'] ?? null) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $result['correlation_id']) === 1) $safe['correlation_id'] = $result['correlation_id'];
    if (is_array($result['source_ids'] ?? null) && array_is_list($result['source_ids']) && count($result['source_ids']) <= 1
        && ($result['source_ids'] === [] || is_int($result['source_ids'][0]) && $result['source_ids'][0] > 0)) $safe['source_ids'] = $result['source_ids'];
    return $safe;
}

function autonomyAccountingCounts(PgSql\Connection $connection): array
{
    return autonomyAccountingSql($connection, 'SELECT
        (SELECT count(*)::int FROM plugins.correspondence_documents) documents,
        (SELECT count(*)::int FROM plugins.correspondence_operations) operations,
        (SELECT count(*)::int FROM public.books) books,
        (SELECT count(*)::int FROM public.responselog) commands')[0];
}

$connection = correspondenceRecoveryFixture('chim_corr_recovery_autonomy');
$identity = autonomyAccountingSql($connection, "SELECT current_database() db,current_user role,current_setting('listen_addresses') listeners,(inet_server_addr() IS NULL)::int socket_only")[0] ?? [];
autonomyAccountingRequire($identity === ['db'=>'chim_corr_recovery_autonomy','role'=>'postgres','listeners'=>'','socket_only'=>'1'], 'owned_disposable_database_required');
$tables = autonomyAccountingSql($connection, "SELECT count(*)::int amount FROM information_schema.tables WHERE table_schema IN ('public','plugins','chim_meta')")[0] ?? [];
autonomyAccountingRequire(($tables['amount'] ?? null) === '0', 'blank_owned_database_required');

$ddl = <<<'SQL'
CREATE TABLE public.eventlog(rowid bigserial PRIMARY KEY,ts bigint,gamets numeric,type text,data text,sess text,localts bigint,people text,location text,party text);
CREATE TABLE public.core_npc_master(id bigint PRIMARY KEY,npc_name text,refid text,profile_id bigint,personality text,extended_data jsonb,metadata jsonb);
CREATE TABLE public.core_player(id text PRIMARY KEY,value text);
CREATE TABLE public.game_plugins(plugin_name text,formid_prefix text);
CREATE TABLE public.books(id bigserial PRIMARY KEY,ts bigint,gamets numeric,content text,sess text,localts bigint,title text);
CREATE TABLE public.responselog(id bigserial PRIMARY KEY,localts bigint,sent integer,actor text,text text,action text,tag text);
CREATE SCHEMA chim_meta;
CREATE TABLE chim_meta.playthrough_profiles(id bigint PRIMARY KEY,is_active boolean NOT NULL);
INSERT INTO public.core_player VALUES('player_name','Dragonborn');
INSERT INTO public.game_plugins VALUES('Skyrim.esm','00');
INSERT INTO public.core_npc_master VALUES
 (900001,'Camilla','00013488',1,'Proud and thoughtful.','{"background_life_enabled":true,"background_life_letters":true}','{"last_coords":[0,0,0,"Riverwood"]}'),
 (900002,'Jarl Balgruuf','00013BBD',2,'Reserved.','{"background_life_enabled":true,"background_life_letters":true}','{"last_coords":[0,0,0,"Riverwood"]}'),
 (900003,'Lydia','000A2C94',3,'Practical.','{"background_life_enabled":true,"background_life_letters":true}','{"last_coords":[0,0,0,"Riverwood"]}');
INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(100,100,'inputtext','native autonomy accounting fixture','',100,'','','');
SQL;
autonomyAccountingSql($connection, $ddl);

$project = dirname(__DIR__, 2);
require_once $project.'/server/lib/store.php';
require_once $project.'/server/lib/boundaries.php';
require_once $project.'/server/lib/autonomy_policy.php';
require_once $project.'/server/lib/autonomy_store.php';
require_once $project.'/server/lib/autonomy_host.php';

// The synthetic note preparer keeps this accounting test independent of files and game runtime.
$store = new CorrespondencePgStore($connection, static fn($store) => 'Lydia', static fn($title, $body) => true);
$store->bindRequestClock(['inputtext','100','100','synthetic autonomy accounting setup']);
$store->ensureSchema();
$service = new CorrespondenceService($store);
$autonomy = new CorrespondenceAutonomyPg($store, $service);
$autonomy->ensureSchema();

$presence = base64_encode(correspondenceJson(['source'=>'plugin_player_routing_v2','present_actors'=>[
    ['form_id'=>hexdec('000A2C94'),'name'=>'Lydia','distance'=>10,'managed'=>true,'creature'=>false],
]]));
$makeSource = static function (string $body, string $sender='npc:900001') use ($store, $service, $presence): string {
    $store->observeManaged($presence);
    $document = $service->compose([
        'request_id'=>correspondenceUuid(),'author'=>'player','payer'=>'player','recipient'=>'npc:900003',
        'claimed_sender'=>$sender,'body'=>$body,'attachments'=>[],
    ]);
    $sent = $service->dispatch($document['document_id']);
    $operation = $store->loadOperation($sent['operation_id']);
    autonomyAccountingRequire(is_array($operation), 'source_operation_missing');
    $receipt = array_intersect_key($operation, array_flip(['protocol_version','document_id','operation_id','receipt_id','playthrough_id','scope_token','nonce']));
    $receipt += ['status'=>'delivered','reason'=>'physical_only','note_form_id'=>12345,'attachments'=>[]];
    $service->receive($receipt);
    $service->prompt('Lydia');
    return $document['document_id'];
};
$activateScope = static function (?string $scope) use ($connection): void {
    autonomyAccountingSql($connection, 'UPDATE chim_meta.playthrough_profiles SET is_active=false');
    if ($scope !== null) autonomyAccountingSql($connection, 'INSERT INTO chim_meta.playthrough_profiles(id,is_active) VALUES($1,true) ON CONFLICT(id) DO UPDATE SET is_active=true', [$scope]);
};
$enableScope = static function (string $scope) use ($connection): void {
    autonomyAccountingSql($connection, 'INSERT INTO plugins.correspondence_autonomy_settings(playthrough_id,enabled) VALUES($1,true) ON CONFLICT(playthrough_id) DO UPDATE SET enabled=true,updated_at=clock_timestamp()', [$scope]);
};
$insertNative = static function (int $ts, int $gamets, string $data='synthetic native autonomy request') use ($connection): void {
    autonomyAccountingSql($connection, "INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES($1,$2,'inputtext',$3,'',$1,'','','')", [$ts,$gamets,$data]);
};
$runtimeFactory = static fn(): array => ['store'=>$store,'autonomy'=>$autonomy];
$runNative = static function (int $ts, int $gamets, callable $modelFactory) use ($insertNative, $runtimeFactory): array {
    $insertNative($ts, $gamets);
    return correspondenceAutonomyRunNativePostrequest(['inputtext',(string)$ts,(string)$gamets,'synthetic native autonomy request'], $runtimeFactory, $modelFactory);
};

// A later unrelated native event must not discard a valid reply or strand its claim.
$enableScope('unprofiled');
$failedSource = $makeSource('This source tests durable autonomy accounting.');
$sourceCounts = autonomyAccountingCounts($connection);
$requestGamets = 100 + correspondenceAutonomyGamets(4);
$modelCalls = 0;
$countersVisibleBeforeCall = false;
$failed = $runNative(200, $requestGamets, static function () use ($connection, $store, $failedSource, $requestGamets, &$modelCalls, &$countersVisibleBeforeCall): callable {
    return static function (array $messages) use ($connection, $store, $failedSource, $requestGamets, &$modelCalls, &$countersVisibleBeforeCall): string {
        $modelCalls++;
        $rows = autonomyAccountingSql($connection, 'SELECT claim_id::text,payload::text FROM plugins.correspondence_autonomy_decisions WHERE playthrough_id=$1 AND source_document_id=$2 AND status=$3', ['unprofiled',$failedSource,'claimed']);
        autonomyAccountingRequire(count($rows) === 1, 'claim_must_commit_before_model_call');
        $payload = json_decode((string)$rows[0]['payload'], true, 64, JSON_THROW_ON_ERROR);
        $countersVisibleBeforeCall = ($payload['model_calls'] ?? null) === 1 && is_int($payload['input_chars'] ?? null) && $payload['input_chars'] > 0;
        if (!in_array('--transient-completion-only',$GLOBALS['argv'],true)) autonomyAccountingSql($connection, "INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(201,$1,'inputtext','later native event during model call','',201,'','','')", [$requestGamets+1]);
        if (in_array('--transient-completion-only', $GLOBALS['argv'], true)) {
            autonomyAccountingSql($connection, "CREATE SEQUENCE public.completion_fault_attempt");
            autonomyAccountingSql($connection, "CREATE FUNCTION public.completion_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF nextval('public.completion_fault_attempt')=1 THEN RAISE EXCEPTION 'synthetic transient completion failure'; END IF; RETURN NEW; END $$");
            autonomyAccountingSql($connection, 'CREATE TRIGGER completion_fault BEFORE INSERT ON plugins.correspondence_documents FOR EACH ROW EXECUTE FUNCTION public.completion_fault()');
        }
        if (in_array('--restore-during-model-only', $GLOBALS['argv'], true)) {
            $epoch=$store->beginRestoreEpoch();$store->completeRestoreEpoch($epoch);
        }
        return autonomyAccountingReply('npc:900001');
    };
});
autonomyAccountingCheck($modelCalls === 1 && $countersVisibleBeforeCall, 'attempt_and_input_count_must_be_written_before_callback_invocation');
if (in_array('--restore-during-model-only', $argv, true)) {
    $row=autonomyAccountingSql($connection,'SELECT status,child_document_id::text child FROM plugins.correspondence_autonomy_decisions WHERE playthrough_id=$1 AND source_document_id=$2',['unprofiled',$failedSource])[0] ?? [];
    autonomyAccountingRequire(($failed['status'] ?? null)!=='queued' && ($row['child'] ?? null)===null && $modelCalls===1
        && autonomyAccountingCounts($connection)===$sourceCounts,'retained_anchor_restore_must_reject_old_model_output_without_effects');
    echo "PASS retained-anchor asynchronous restore: one model call, no child or physical effects\n";
    exit;
}
autonomyAccountingRequire(($failed['status'] ?? null) === 'queued' && ($failed['reason'] ?? null) === 'queued_for_transit',
    in_array('--transient-completion-only',$argv,true)?'transient_completion_must_retry_same_reply_once':'concurrent_event_must_commit_valid_autonomy_reply');
$claimed = autonomyAccountingSql($connection, 'SELECT claim_id::text,source_event_id::text,status,child_document_id::text child,payload::text FROM plugins.correspondence_autonomy_decisions WHERE playthrough_id=$1 AND source_document_id=$2', ['unprofiled',$failedSource])[0] ?? [];
$claimedPayload = json_decode((string)($claimed['payload'] ?? '{}'), true, 64, JSON_THROW_ON_ERROR);
autonomyAccountingRequire(($claimed['status'] ?? null) === 'queued' && is_string($claimed['child'] ?? null)
    && ($claimedPayload['model_calls'] ?? null) === 1 && ($claimedPayload['input_chars'] ?? 0) > 0 && ($claimedPayload['output_chars'] ?? 0) > 0,
    'concurrent_completion_must_close_same_claim_with_one_child_and_durable_counters');
$afterCounts = autonomyAccountingCounts($connection);
autonomyAccountingRequire((int)$afterCounts['documents'] === (int)$sourceCounts['documents']+1
    && $afterCounts['operations'] === $sourceCounts['operations'] && $afterCounts['books'] === $sourceCounts['books']
    && $afterCounts['commands'] === $sourceCounts['commands'], 'concurrent_completion_must_queue_one_child_without_physical_resend');
if (in_array($argv[1] ?? null,['--concurrent-events-only','--transient-completion-only'],true)) {
    echo "PASS concurrent-event autonomy completion: one model attempt, one queued child, no physical resend\n";
    exit;
}

// R2: a transient first source is deferred while the next valid source can use the one-call tick.
$renameScope = '700101';
$activateScope($renameScope);
$enableScope($renameScope);
$insertNative(300, 100, 'synthetic source setup request');
$store->bindRequestClock(['inputtext','300','100','synthetic source setup request']);
$renamedSource = $makeSource('This source must survive a temporary sender rename.');
$laterSource = $makeSource('A second sender source should proceed in the same tick.','npc:900002');
autonomyAccountingSql($connection, "UPDATE public.core_npc_master SET npc_name='Camilla Temp' WHERE id=900001");
$renameGamets = 100 + correspondenceAutonomyGamets(4);
$renameCalls = 0;
$renameResult = $runNative(400, $renameGamets, static function () use (&$renameCalls): callable {
    return static function (array $messages) use (&$renameCalls): string { $renameCalls++; return autonomyAccountingReply('npc:900002'); };
});
$renameDecisions = autonomyAccountingSql($connection, 'SELECT count(*)::int amount FROM plugins.correspondence_autonomy_decisions WHERE playthrough_id=$1 AND source_document_id=$2', [$renameScope,$renamedSource])[0]['amount'] ?? null;
$laterDecision = autonomyAccountingSql($connection, 'SELECT status FROM plugins.correspondence_autonomy_decisions WHERE playthrough_id=$1 AND source_document_id=$2', [$renameScope,$laterSource])[0] ?? [];
autonomyAccountingCheck(($renameResult['status'] ?? null) === 'queued' && ($renameResult['reason'] ?? null) === 'queued_for_transit'
    && $renameCalls === 1 && $renameDecisions === '0' && ($laterDecision['status'] ?? null) === 'queued',
    'temporary_first_source_must_be_deferred_without_a_claim_while_the_next_valid_source_proceeds');
$maintenanceGamets = $renameGamets + correspondenceAutonomyGamets(7);
$maintenance = $runNative(500, $maintenanceGamets, static fn() => null);
autonomyAccountingCheck(($maintenance['status'] ?? null) === 'dispatched' && ($maintenance['reason'] ?? null) === 'dispatch_queued',
    'due_child_dispatch_must_be_serviced_before_retrying_fresh_sources');
autonomyAccountingSql($connection, "UPDATE public.core_npc_master SET npc_name='Camilla' WHERE id=900001");
$recoveredCalls = 0;
$recovered = $runNative(501, $maintenanceGamets+1, static function () use (&$recoveredCalls): callable {
    return static function (array $messages) use (&$recoveredCalls): string { $recoveredCalls++; return autonomyAccountingReply('npc:900001'); };
});
$recoveredDecision = autonomyAccountingSql($connection, 'SELECT status FROM plugins.correspondence_autonomy_decisions WHERE playthrough_id=$1 AND source_document_id=$2', [$renameScope,$renamedSource])[0] ?? [];
autonomyAccountingCheck(($recovered['status'] ?? null) === 'queued' && $recoveredCalls === 1 && ($recoveredDecision['status'] ?? null) === 'queued',
    'restored_actor_identity_must_allow_the_same_source_to_complete');

$r2FailureDiagnostics = [
    'renamed_source_id'=>$renamedSource,
    'later_source_id'=>$laterSource,
    'rename_result'=>autonomyAccountingSafeOutcome($renameResult),
    'rename_model_calls'=>$renameCalls,
    'renamed_source_decision_count'=>(int)$renameDecisions,
    'later_source_decision_status'=>in_array($laterDecision['status'] ?? null, ['claimed','silent','queued','dispatched','failed','unknown','stale'], true) ? $laterDecision['status'] : null,
    'maintenance_result'=>autonomyAccountingSafeOutcome($maintenance),
    'recovered_result'=>autonomyAccountingSafeOutcome($recovered),
    'recovered_model_calls'=>$recoveredCalls,
    'recovered_source_decision_status'=>in_array($recoveredDecision['status'] ?? null, ['claimed','silent','queued','dispatched','failed','unknown','stale'], true) ? $recoveredDecision['status'] : null,
];
if ($GLOBALS['autonomyAccountingFailures'] !== []) throw new RuntimeException('autonomy_accounting_failed:'.implode(',', $GLOBALS['autonomyAccountingFailures']).':'.json_encode($r2FailureDiagnostics, JSON_THROW_ON_ERROR));
echo json_encode([
    'status'=>'PASS','attempt_counters_persist_before_model_callback'=>true,'concurrent_completion_keeps_counters'=>true,
    'one_child_without_physical_resend'=>true,'temporary_first_source_does_not_pin_later_source'=>true,
    'temporary_actor_identity_change_retries'=>true,
    'model_calls_semantics'=>'attempted invocation, not provider billing confirmation',
], JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
