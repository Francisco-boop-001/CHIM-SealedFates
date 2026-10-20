<?php
declare(strict_types=1);

require_once __DIR__.'/../support/recovery_pg_fixture.php';

$GLOBALS['autonomyRecoveryFailures'] = [];
$GLOBALS['autonomyRecoveryChecks'] = 0;

// Named case labels map to positive decimal playthrough IDs accepted by the native scope parser.
const AUTONOMY_RECOVERY_SCOPE_FOREIGN = '700001';
const AUTONOMY_RECOVERY_SCOPE_DISPATCH = '700002';
const AUTONOMY_RECOVERY_SCOPE_MODELLESS = '700003';
const AUTONOMY_RECOVERY_SCOPE_WORKER_DEATH = '700004';
const AUTONOMY_RECOVERY_SCOPE_SQL_ERROR = '700005';

function autonomyRecoveryCheck(bool $ok, string $reason): void
{
    $GLOBALS['autonomyRecoveryChecks']++;
    if (!$ok) $GLOBALS['autonomyRecoveryFailures'][] = $reason;
}

function autonomyRecoveryRequire(bool $ok, string $reason): void
{
    if (!$ok) throw new RuntimeException($reason);
}

function autonomyRecoverySql(PgSql\Connection $connection, string $sql, array $args = []): array
{
    $result = $args === [] ? pg_query($connection, $sql) : pg_query_params($connection, $sql, $args);
    if (!$result instanceof PgSql\Result) throw new RuntimeException('recovery_fixture_sql_failed:'.pg_last_error($connection));
    try { return pg_num_fields($result) > 0 ? (pg_fetch_all($result) ?: []) : []; }
    finally { pg_free_result($result); }
}

function autonomyRecoveryReply(string $recipient): string
{
    return json_encode(['action'=>'reply','recipient'=>$recipient,'body'=>'I will explain my concern.','reason'=>'The sender asked for an explanation.'], JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}

function autonomyRecoveryCounts(PgSql\Connection $connection): array
{
    return autonomyRecoverySql($connection, "SELECT
        (SELECT count(*)::int FROM plugins.correspondence_documents) documents,
        (SELECT count(*)::int FROM plugins.correspondence_operations) operations,
        (SELECT count(*)::int FROM public.books) books,
        (SELECT count(*)::int FROM public.responselog) commands")[0];
}

function autonomyRecoveryCleanupWorkerTmpdir(mixed $directory): void
{
    if (!is_string($directory) || $directory === '') return;
    $resolved = realpath($directory);
    $temporaryRoot = realpath(sys_get_temp_dir());
    if (!is_string($resolved) || !is_string($temporaryRoot) || dirname($resolved) !== $temporaryRoot
        || preg_match('/\Achim-autonomy-recovery-[0-9a-f]{32}\z/D', basename($resolved)) !== 1) return;
    foreach (['ready.json','ready.json.tmp','stdout.log','stderr.log'] as $name) {
        $path = $resolved.DIRECTORY_SEPARATOR.$name;
        if (is_file($path) || is_link($path)) @unlink($path);
    }
    @rmdir($resolved);
}

function autonomyRecoveryWorkerDeathChild(array $arguments): void
{
    autonomyRecoveryRequire(PHP_SAPI === 'cli' && PHP_OS_FAMILY === 'Linux', 'worker_child_requires_owned_linux_cli');
    autonomyRecoveryRequire(count($arguments) === 6 && ($arguments[1] ?? null) === '--recovery-worker-death-child', 'worker_child_arguments_invalid');
    $sourceId = (string)$arguments[2];
    $ts = (string)$arguments[3];
    $gamets = (string)$arguments[4];
    $directory = realpath((string)$arguments[5]);
    $temporaryDirectory = realpath(sys_get_temp_dir());
    autonomyRecoveryRequire(preg_match('/\A[0-9a-f-]{36}\z/D', $sourceId) === 1, 'worker_child_source_invalid');
    autonomyRecoveryRequire(preg_match('/\A[1-9][0-9]{0,17}\z/D', $ts) === 1 && preg_match('/\A[0-9]{1,18}\z/D', $gamets) === 1, 'worker_child_clock_invalid');
    autonomyRecoveryRequire(is_string($directory) && is_string($temporaryDirectory) && $directory === $temporaryDirectory
        && preg_match('/\Achim-autonomy-recovery-[0-9a-f]{32}\z/D', basename($directory)) === 1, 'worker_child_tmpdir_not_owned');

    $connection = correspondenceRecoveryFixture('chim_corr_recovery_autonomy');
    $project = dirname(__DIR__, 2);
    require_once $project.'/server/lib/store.php';
    require_once $project.'/server/lib/boundaries.php';
    require_once $project.'/server/lib/autonomy_policy.php';
    require_once $project.'/server/lib/autonomy_store.php';
    require_once $project.'/server/lib/autonomy_host.php';

    // Match the parent fixture's synthetic notePreparer=true seam; no PNG is rendered here.
    $store = new CorrespondencePgStore($connection, static fn($store) => 'Lydia', static fn($title, $body) => true);
    $store->ensureSchema();
    $store->bindRequestClock(['inputtext',$ts,$gamets,'synthetic worker-death request']);
    $service = new CorrespondenceService($store);
    $autonomy = new CorrespondenceAutonomyPg($store, $service);
    $autonomy->ensureSchema();
    autonomyRecoverySql($connection, "INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES($1,$2,'inputtext','synthetic worker-death request','',$1,'','','')", [$ts,$gamets]);

    $runtimeFactory = static fn(): array => ['store'=>$store,'autonomy'=>$autonomy];
    $modelFactory = static function () use ($connection, $store, $sourceId, $directory): callable {
        return static function (array $messages) use ($connection, $store, $sourceId, $directory): string {
            $rows = autonomyRecoverySql($connection, 'SELECT source_document_id::text source_id,claim_id::text claim_id,status,payload::text FROM plugins.correspondence_autonomy_decisions WHERE playthrough_id=$1 AND source_document_id=$2 AND status=$3', [AUTONOMY_RECOVERY_SCOPE_WORKER_DEATH,$sourceId,'claimed']);
            autonomyRecoveryRequire(count($rows) === 1, 'worker_marker_requires_committed_claim');
            $claim = $rows[0];
            $payload = json_decode((string)$claim['payload'], true, 64, JSON_THROW_ON_ERROR);
            autonomyRecoveryRequire($store->eventMatches($payload['claim_anchor'] ?? []), 'worker_marker_requires_matching_claim_anchor');
            $markerPath = $directory.'/ready.json';
            $temporaryMarker = $markerPath.'.tmp';
            $marker = json_encode([
                'playthrough_id'=>AUTONOMY_RECOVERY_SCOPE_WORKER_DEATH,'source_document_id'=>$claim['source_id'],
                'claim_id'=>$claim['claim_id'],'status'=>$claim['status'],
            ], JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
            autonomyRecoveryRequire(file_put_contents($temporaryMarker, $marker, LOCK_EX) === strlen($marker), 'worker_ready_marker_write_failed');
            autonomyRecoveryRequire(rename($temporaryMarker, $markerPath), 'worker_ready_marker_publish_failed');
            while (true) usleep(100000);
        };
    };
    $result = correspondenceAutonomyRunNativePostrequest(['inputtext',$ts,$gamets,'synthetic worker-death request'], $runtimeFactory, $modelFactory);
    fwrite(STDERR, 'worker_returned_before_parent_kill:'.json_encode($result, JSON_THROW_ON_ERROR)."\n");
    exit(4);
}

if (($argv[1] ?? null) === '--recovery-worker-death-child') {
    try {
        autonomyRecoveryWorkerDeathChild($argv);
    } catch (Throwable $error) {
        fwrite(STDERR, 'worker_child_failed:'.$error->getMessage()."\n");
        exit(5);
    }
}

$connection = correspondenceRecoveryFixture('chim_corr_recovery_autonomy');
$identity = autonomyRecoverySql($connection, "SELECT current_database() db,current_user role,current_setting('listen_addresses') listeners,(inet_server_addr() IS NULL)::int socket_only")[0] ?? [];
autonomyRecoveryRequire($identity === ['db'=>'chim_corr_recovery_autonomy','role'=>'postgres','listeners'=>'','socket_only'=>'1'], 'owned_recovery_database_required');
$tables = autonomyRecoverySql($connection, "SELECT count(*)::int amount FROM information_schema.tables WHERE table_schema IN ('public','plugins','chim_meta')")[0] ?? [];
autonomyRecoveryRequire(($tables['amount'] ?? null) === '0', 'blank_owned_database_required');

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
INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(100,100,'inputtext','native autonomy recovery fixture','',100,'','','');
SQL;
autonomyRecoverySql($connection, $ddl);

$project = dirname(__DIR__, 2);
require_once $project.'/server/lib/store.php';
require_once $project.'/server/lib/boundaries.php';
require_once $project.'/server/lib/autonomy_policy.php';
require_once $project.'/server/lib/autonomy_store.php';
require_once $project.'/server/lib/autonomy_host.php';

// notePreparer=true is a synthetic no-filesystem seam; this fixture checks SQL transaction writes only.
$store = new CorrespondencePgStore($connection, static fn($store) => 'Lydia', static fn($title, $body) => true);
$store->ensureSchema();
$store->bindRequestClock(['inputtext','100','100','synthetic native fixture']);
$service = new CorrespondenceService($store);
$autonomy = new CorrespondenceAutonomyPg($store, $service);
$autonomy->ensureSchema();
autonomyRecoverySql($connection, <<<'SQL'
CREATE SEQUENCE public.recovery_autonomy_sql_fault_hits;
CREATE OR REPLACE FUNCTION public.recovery_autonomy_reject_child_link() RETURNS trigger LANGUAGE plpgsql AS $fn$
BEGIN
    IF current_setting('correspondence.recovery_document_id', TRUE) = NEW.document_id::text
       AND OLD.payload->>'operation_id' IS NULL
       AND NEW.payload->>'operation_id' IS NOT NULL THEN
        IF current_setting('correspondence.recovery_failure_mode', TRUE) = 'sql_error' THEN
            -- Sequence increments survive rollback and prove this real SQL fault was reached exactly once.
            PERFORM nextval('public.recovery_autonomy_sql_fault_hits');
            RAISE EXCEPTION 'synthetic_late_dispatch_sql_error' USING ERRCODE = 'P0001';
        END IF;
        PERFORM set_config('correspondence.recovery_document_id', '', FALSE);
        RETURN NULL;
    END IF;
    RETURN NEW;
END
$fn$;
CREATE TRIGGER recovery_autonomy_reject_child_link
BEFORE UPDATE ON plugins.correspondence_documents
FOR EACH ROW EXECUTE FUNCTION public.recovery_autonomy_reject_child_link();
SQL);
// Default mode suppresses the UPDATE so saveDocument throws in PHP; sql_error mode aborts SQL after the same staged writes. Neither mode renders a PNG.
$presence = base64_encode(correspondenceJson(['source'=>'plugin_player_routing_v2','present_actors'=>[
    ['form_id'=>hexdec('000A2C94'),'name'=>'Lydia','distance'=>10,'managed'=>true,'creature'=>false],
]]));

$activateScope = static function (?string $scope) use ($connection): void {
    autonomyRecoverySql($connection, 'UPDATE chim_meta.playthrough_profiles SET is_active=false');
    if ($scope !== null) {
        autonomyRecoverySql($connection, 'INSERT INTO chim_meta.playthrough_profiles(id,is_active) VALUES($1,true) ON CONFLICT(id) DO UPDATE SET is_active=true', [$scope]);
    }
};
$enableScope = static function (string $scope) use ($connection): void {
    autonomyRecoverySql($connection, 'INSERT INTO plugins.correspondence_autonomy_settings(playthrough_id,enabled) VALUES($1,true) ON CONFLICT(playthrough_id) DO UPDATE SET enabled=true,updated_at=clock_timestamp()', [$scope]);
};
$makeSource = static function (string $body, string $recipient, string $sender, string $recipientName) use ($store, $service, $presence): string {
    $store->observeManaged($presence);
    $document = $service->compose([
        'request_id'=>correspondenceUuid(),'author'=>'player','payer'=>'player','recipient'=>$recipient,
        'claimed_sender'=>$sender,'body'=>$body,'attachments'=>[],
    ]);
    $sent = $service->dispatch($document['document_id']);
    $operation = $store->loadOperation($sent['operation_id']);
    autonomyRecoveryRequire(is_array($operation), 'recovery_source_operation_missing');
    $receipt = array_intersect_key($operation, array_flip(['protocol_version','document_id','operation_id','receipt_id','playthrough_id','scope_token','nonce']));
    $receipt += ['status'=>'delivered','reason'=>'physical_only','note_form_id'=>12345,'attachments'=>[]];
    $service->receive($receipt);
    $service->prompt($recipientName);
    return $document['document_id'];
};
$insertNative = static function (int $ts, int $gamets) use ($connection): void {
    autonomyRecoverySql($connection, "INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES($1,$2,'inputtext',$3,'',$4,'','','')", [$ts,$gamets,'synthetic fresh native request',$ts]);
};
$runtimeFactory = static fn(): array => ['store'=>$store,'autonomy'=>$autonomy];
// The native host may resolve one model factory during preflight; model_calls tracks callback attempts.
$runNative = static function (int $ts, int $gamets, callable $modelFactory) use ($insertNative, $runtimeFactory): array {
    $insertNative($ts, $gamets);
    return correspondenceAutonomyRunNativePostrequest(['inputtext',(string)$ts,(string)$gamets,'synthetic native recovery request'], $runtimeFactory, $modelFactory);
};

// F02: the claim commits before the callback; a newer native event makes completion stale.
$f02Source = null;
$abandoned = null;
$f02ModelCalls = 0;
$claimGamets = 100 + correspondenceAutonomyGamets(2);
try {
    $activateScope(null);
    $enableScope('unprofiled');
    $f02Source = $makeSource('This source is abandoned when its worker exits.','npc:900001','npc:900002','Camilla');
    $failed = $runNative(200, $claimGamets, static function () use ($connection, &$abandoned, &$f02ModelCalls, $f02Source, $claimGamets): callable {
        return static function (array $messages) use ($connection, &$abandoned, &$f02ModelCalls, $f02Source, $claimGamets): string {
            $f02ModelCalls++;
            $rows = autonomyRecoverySql($connection, "SELECT source_document_id::text source_id,claim_id::text claim_id,status FROM plugins.correspondence_autonomy_decisions WHERE playthrough_id='unprofiled' AND source_document_id=$1 AND status='claimed'", [$f02Source]);
            autonomyRecoveryRequire(count($rows) === 1, 'claim_must_commit_before_model_call');
            $abandoned = $rows[0];
            autonomyRecoverySql($connection, "UPDATE plugins.correspondence_autonomy_decisions SET created_at=clock_timestamp()-interval '181 seconds' WHERE playthrough_id='unprofiled' AND source_document_id=$1 AND claim_id=$2 AND status='claimed'", [$abandoned['source_id'],$abandoned['claim_id']]);
            autonomyRecoverySql($connection, "INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(201,$1,'inputtext','later native event','',201,'','','')", [$claimGamets+1]);
            throw new RuntimeException('synthetic worker/model failure');
        };
    });
    autonomyRecoveryCheck(($failed['status'] ?? null) === 'failed' && ($failed['reason'] ?? null) === 'storage_error', 'F02_later_timestamp_must_reject_completion');
    if (is_array($abandoned)) {
        $claimed = autonomyRecoverySql($connection, 'SELECT claim_id::text,status,payload::text FROM plugins.correspondence_autonomy_decisions WHERE playthrough_id=$1 AND source_document_id=$2', ['unprofiled',$abandoned['source_id']])[0] ?? [];
        autonomyRecoveryCheck(($claimed['status'] ?? null) === 'claimed' && ($claimed['claim_id'] ?? null) === $abandoned['claim_id'], 'F02_rejection_must_leave_the_same_claim');
        $payload = json_decode((string)($claimed['payload'] ?? '{}'), true, 64, JSON_THROW_ON_ERROR);
        autonomyRecoveryCheck($store->eventMatches($payload['claim_anchor'] ?? []), 'F02_original_claim_anchor_must_still_match');

        $staleModelCalls = 0;
        $staleClock = correspondenceAutonomyRunNativePostrequest(['inputtext','200',(string)($claimGamets+1),'stale clock replay'], $runtimeFactory, static function () use (&$staleModelCalls): callable {
            return static function (array $messages) use (&$staleModelCalls): string { $staleModelCalls++; return autonomyRecoveryReply('npc:900002'); };
        });
        $afterStaleReplay = autonomyRecoverySql($connection, 'SELECT claim_id::text,status FROM plugins.correspondence_autonomy_decisions WHERE playthrough_id=$1 AND source_document_id=$2', ['unprofiled',$abandoned['source_id']])[0] ?? [];
        autonomyRecoveryCheck(($afterStaleReplay['status'] ?? null) === 'claimed' && ($afterStaleReplay['claim_id'] ?? null) === $abandoned['claim_id'] && $staleModelCalls === 0 && ($staleClock['status'] ?? null) === 'failed', 'F02_stale_request_clock_must_not_sweep_or_publish');

        $activateScope(AUTONOMY_RECOVERY_SCOPE_FOREIGN);
        $enableScope(AUTONOMY_RECOVERY_SCOPE_FOREIGN);
        $foreignModelCalls = 0;
        $foreign = $runNative(202, $claimGamets+2, static function () use (&$foreignModelCalls): callable {
            return static function (array $messages) use (&$foreignModelCalls): string { $foreignModelCalls++; return autonomyRecoveryReply('npc:900002'); };
        });
        $foreignCheck = autonomyRecoverySql($connection, 'SELECT status,claim_id::text FROM plugins.correspondence_autonomy_decisions WHERE playthrough_id=$1 AND source_document_id=$2', ['unprofiled',$abandoned['source_id']])[0] ?? [];
        autonomyRecoveryCheck(($foreignCheck['status'] ?? null) === 'claimed' && ($foreignCheck['claim_id'] ?? null) === $abandoned['claim_id'] && $foreignModelCalls === 0 && ($foreign['status'] ?? null) === 'idle', 'F02_foreign_scope_must_not_retire_another_claim');

        $activateScope(null);
        $beforeRecovery = autonomyRecoveryCounts($connection);
        $recoveryModelCalls = 0;
        $recovered = $runNative(203, $claimGamets+3, static function () use (&$recoveryModelCalls): callable {
            return static function (array $messages) use (&$recoveryModelCalls): string { $recoveryModelCalls++; return autonomyRecoveryReply('npc:900002'); };
        });
        $stale = autonomyRecoverySql($connection, 'SELECT claim_id::text,status,child_document_id::text child FROM plugins.correspondence_autonomy_decisions WHERE playthrough_id=$1 AND source_document_id=$2', ['unprofiled',$abandoned['source_id']])[0] ?? [];
        autonomyRecoveryCheck(($stale['status'] ?? null) === 'stale' && ($stale['claim_id'] ?? null) === $abandoned['claim_id'], 'F02_fresh_request_must_terminally_stale_the_same_expired_claim');
        autonomyRecoveryCheck(($stale['child'] ?? null) === null && $recoveryModelCalls === 0 && ($recovered['model_calls'] ?? null) === 0, 'F02_age_sweep_must_not_call_model_or_create_child');
        autonomyRecoveryCheck(autonomyRecoveryCounts($connection) === $beforeRecovery, 'F02_age_sweep_must_not_write_books_operations_or_commands');

        $repeatModelCalls = 0;
        $repeat = $runNative(204, $claimGamets+4, static function () use (&$repeatModelCalls): callable {
            return static function (array $messages) use (&$repeatModelCalls): string { $repeatModelCalls++; return autonomyRecoveryReply('npc:900002'); };
        });
        $repeated = autonomyRecoverySql($connection, 'SELECT claim_id::text,status,child_document_id::text child FROM plugins.correspondence_autonomy_decisions WHERE playthrough_id=$1 AND source_document_id=$2', ['unprofiled',$abandoned['source_id']])[0] ?? [];
        autonomyRecoveryCheck(($repeated['status'] ?? null) === 'stale' && ($repeated['claim_id'] ?? null) === $abandoned['claim_id'] && ($repeated['child'] ?? null) === null && $repeatModelCalls === 0 && ($repeat['model_calls'] ?? null) === 0, 'F02_terminal_sweep_must_be_idempotent_without_retry');
    } else {
        autonomyRecoveryCheck(false, 'F02_claim_missing_prevents_age_sweep_case');
    }
} catch (Throwable $error) {
    autonomyRecoveryCheck(false, 'F02_case_setup_or_execution_failed:'.$error->getMessage());
}

// R4: throw after operation and document writes, while still inside dispatch's transaction.
try {
    $activateScope(AUTONOMY_RECOVERY_SCOPE_DISPATCH);
    $insertNative(205, 100);
    $store->bindRequestClock(['inputtext','205','100','dispatch source setup']);
    $enableScope(AUTONOMY_RECOVERY_SCOPE_DISPATCH);
    $dispatchSource = $makeSource('This source exercises staged dispatch rollback.','npc:900001','npc:900002','Camilla');
    $queueGamets = 100 + correspondenceAutonomyGamets(2);
    $queued = $runNative(206, $queueGamets, static fn(): callable => static fn(array $messages): string => autonomyRecoveryReply('npc:900002'));
    autonomyRecoveryCheck(($queued['status'] ?? null) === 'queued', 'R4_fault_source_must_queue_child');
    $decision = autonomyRecoverySql($connection, 'SELECT child_document_id::text child_id,due_gamets::text due FROM plugins.correspondence_autonomy_decisions WHERE playthrough_id=$1 AND source_document_id=$2 AND status=$3', [AUTONOMY_RECOVERY_SCOPE_DISPATCH,$dispatchSource,'queued'])[0] ?? [];
    autonomyRecoveryRequire(is_string($decision['child_id'] ?? null) && is_string($decision['due'] ?? null), 'R4_queued_child_missing');
    $due = (int)$decision['due'];
    $beforeDispatch = autonomyRecoveryCounts($connection);
    autonomyRecoverySql($connection, "SELECT set_config('correspondence.recovery_document_id',$1,FALSE)", [$decision['child_id']]);
    $dispatchModelCalls = 0;
    $uncertain = $runNative(207, $due, static function () use (&$dispatchModelCalls): callable {
        return static function (array $messages) use (&$dispatchModelCalls): string { $dispatchModelCalls++; return autonomyRecoveryReply('npc:900002'); };
    });
    autonomyRecoverySql($connection, "SELECT set_config('correspondence.recovery_document_id','',FALSE)");
    $childAfterFailure = $store->loadDocument($decision['child_id']);
    $dispatchAfter = autonomyRecoverySql($connection, 'SELECT status FROM plugins.correspondence_autonomy_decisions WHERE playthrough_id=$1 AND source_document_id=$2', [AUTONOMY_RECOVERY_SCOPE_DISPATCH,$dispatchSource])[0] ?? [];
    autonomyRecoveryCheck(($uncertain['status'] ?? null) === 'unknown' && $dispatchModelCalls === 0, 'R4_late_dispatch_exception_must_remain_uncertain_without_model_call');
    autonomyRecoveryCheck(($dispatchAfter['status'] ?? null) === 'unknown', 'R4_failed_dispatch_must_record_unknown_once');
    autonomyRecoveryCheck(is_array($childAfterFailure) && ($childAfterFailure['operation_id'] ?? null) === null, 'R4_late_dispatch_exception_must_rollback_child_operation_link');
    autonomyRecoveryCheck(autonomyRecoveryCounts($connection) === $beforeDispatch, 'R4_late_dispatch_exception_must_rollback_staged_books_operations_and_commands');
} catch (Throwable $error) {
    autonomyRecoveryCheck(false, 'R4_case_setup_or_execution_failed:'.$error->getMessage());
}

// R3: a queued, due child must flush even when no dialogue model is configured.
try {
    $activateScope(AUTONOMY_RECOVERY_SCOPE_MODELLESS);
    $insertNative(208, 100);
    $store->bindRequestClock(['inputtext','208','100','modelless source setup']);
    $enableScope(AUTONOMY_RECOVERY_SCOPE_MODELLESS);
    $modellessSource = $makeSource('This source exercises dispatch without a configured model.','npc:900003','npc:900001','Lydia');
    $modellessGamets = 100 + correspondenceAutonomyGamets(2);
    $modellessQueued = $runNative(209, $modellessGamets, static fn(): callable => static fn(array $messages): string => autonomyRecoveryReply('npc:900001'));
    autonomyRecoveryCheck(($modellessQueued['status'] ?? null) === 'queued', 'R3_model_free_dispatch_source_must_queue_child');
    $modellessDecision = autonomyRecoverySql($connection, 'SELECT child_document_id::text child_id,due_gamets::text due FROM plugins.correspondence_autonomy_decisions WHERE playthrough_id=$1 AND source_document_id=$2 AND status=$3', [AUTONOMY_RECOVERY_SCOPE_MODELLESS,$modellessSource,'queued'])[0] ?? [];
    autonomyRecoveryRequire(is_string($modellessDecision['child_id'] ?? null) && is_string($modellessDecision['due'] ?? null), 'R3_queued_child_missing');
    $modelFactoryCalls = 0;
    $modelless = $runNative(210, (int)$modellessDecision['due'], static function () use (&$modelFactoryCalls): ?callable {
        $modelFactoryCalls++;
        return null;
    });
    $dispatched = autonomyRecoverySql($connection, 'SELECT status,child_document_id::text child_id FROM plugins.correspondence_autonomy_decisions WHERE playthrough_id=$1 AND source_document_id=$2', [AUTONOMY_RECOVERY_SCOPE_MODELLESS,$modellessSource])[0] ?? [];
    autonomyRecoveryCheck(($dispatched['status'] ?? null) === 'dispatched' && $modelFactoryCalls <= 1
        && ($modelless['status'] ?? null) === 'dispatched' && ($modelless['model_calls'] ?? null) === 0,
        'R3_due_child_must_dispatch_without_configured_model');
} catch (Throwable $error) {
    autonomyRecoveryCheck(false, 'R3_case_setup_or_execution_failed:'.$error->getMessage());
}

// F02 worker death: kill only the exact child after it marks its committed claim ready.
$workerProcess = null;
$workerDirectory = null;
try {
    $activateScope(AUTONOMY_RECOVERY_SCOPE_WORKER_DEATH);
    $insertNative(211, 100);
    $store->bindRequestClock(['inputtext','211','100','worker-death source setup']);
    $enableScope(AUTONOMY_RECOVERY_SCOPE_WORKER_DEATH);
    $workerSource = $makeSource('This source is claimed by a worker that will be killed.','npc:900001','npc:900002','Camilla');
    $workerGamets = 100 + correspondenceAutonomyGamets(2);
    $temporaryRoot = realpath(sys_get_temp_dir());
    autonomyRecoveryRequire(is_string($temporaryRoot), 'worker_parent_tmpdir_unavailable');
    $workerDirectory = $temporaryRoot.DIRECTORY_SEPARATOR.'chim-autonomy-recovery-'.bin2hex(random_bytes(16));
    autonomyRecoveryRequire(mkdir($workerDirectory, 0700), 'worker_parent_tmpdir_create_failed');
    $workerDirectory = realpath($workerDirectory);
    autonomyRecoveryRequire(is_string($workerDirectory) && dirname($workerDirectory) === $temporaryRoot, 'worker_parent_tmpdir_not_owned');
    $workerEnvironment = getenv();
    autonomyRecoveryRequire(is_array($workerEnvironment), 'worker_parent_environment_unavailable');
    foreach (array_keys($workerEnvironment) as $name) autonomyRecoveryRequire(preg_match('/\APG/i', (string)$name) !== 1, 'worker_parent_pg_environment_override');
    $workerEnvironment['TMPDIR'] = $workerDirectory;
    $descriptors = [
        0=>['file','/dev/null','r'],
        1=>['file',$workerDirectory.'/stdout.log','a'],
        2=>['file',$workerDirectory.'/stderr.log','a'],
    ];
    $workerProcess = proc_open(
        [PHP_BINARY,__FILE__,'--recovery-worker-death-child',$workerSource,'212',(string)$workerGamets,$workerDirectory],
        $descriptors,
        $workerPipes,
        dirname(__FILE__),
        $workerEnvironment,
        ['bypass_shell'=>true]
    );
    autonomyRecoveryRequire(is_resource($workerProcess), 'worker_child_start_failed');
    $readyPath = $workerDirectory.'/ready.json';
    $readyDeadline = hrtime(true) + 20_000_000_000;
    while (!is_file($readyPath)) {
        $status = proc_get_status($workerProcess);
        if (!($status['running'] ?? false)) {
            $stderr = is_file($workerDirectory.'/stderr.log') ? (string)file_get_contents($workerDirectory.'/stderr.log') : '';
            throw new RuntimeException('worker_exited_before_ready:'.substr($stderr,0,500));
        }
        if (hrtime(true) >= $readyDeadline) throw new RuntimeException('worker_ready_timeout');
        usleep(25000);
    }
    $ready = json_decode((string)file_get_contents($readyPath), true, 16, JSON_THROW_ON_ERROR);
    autonomyRecoveryRequire(is_array($ready) && ($ready['playthrough_id'] ?? null) === AUTONOMY_RECOVERY_SCOPE_WORKER_DEATH
        && ($ready['source_document_id'] ?? null) === $workerSource && ($ready['status'] ?? null) === 'claimed'
        && is_string($ready['claim_id'] ?? null) && preg_match('/\A[0-9a-f-]{36}\z/D', $ready['claim_id']) === 1, 'worker_ready_claim_identity_invalid');
    $committed = autonomyRecoverySql($connection, 'SELECT claim_id::text,status,payload::text FROM plugins.correspondence_autonomy_decisions WHERE playthrough_id=$1 AND source_document_id=$2', [AUTONOMY_RECOVERY_SCOPE_WORKER_DEATH,$workerSource])[0] ?? [];
    autonomyRecoveryRequire(($committed['status'] ?? null) === 'claimed' && ($committed['claim_id'] ?? null) === $ready['claim_id'], 'worker_ready_claim_must_match_committed_row');
    $claimPayload = json_decode((string)$committed['payload'], true, 64, JSON_THROW_ON_ERROR);
    autonomyRecoveryRequire($store->eventMatches($claimPayload['claim_anchor'] ?? []), 'worker_death_claim_anchor_must_still_match');

    $terminationSent = proc_terminate($workerProcess, 9);
    autonomyRecoveryRequire($terminationSent, 'worker_exact_process_termination_failed');
    $reapDeadline = hrtime(true) + 5_000_000_000;
    do {
        $status = proc_get_status($workerProcess);
        if (!($status['running'] ?? false)) break;
        if (hrtime(true) >= $reapDeadline) throw new RuntimeException('worker_exact_process_reap_timeout');
        usleep(25000);
    } while (true);
    proc_close($workerProcess);
    $workerProcess = null;
    autonomyRecoveryCheck(!$status['running'] && $terminationSent, 'F02_worker_callback_must_be_killed_and_reaped_by_exact_process_handle');
    $aged = autonomyRecoverySql($connection, "UPDATE plugins.correspondence_autonomy_decisions SET created_at=clock_timestamp()-interval '181 seconds' WHERE playthrough_id=$1 AND source_document_id=$2 AND claim_id=$3 AND status='claimed' RETURNING claim_id::text", [AUTONOMY_RECOVERY_SCOPE_WORKER_DEATH,$workerSource,$ready['claim_id']]);
    autonomyRecoveryRequire(count($aged) === 1 && ($aged[0]['claim_id'] ?? null) === $ready['claim_id'], 'worker_death_must_age_only_the_exact_claim');

    $beforeWorkerRecovery = autonomyRecoveryCounts($connection);
    $parentModelCalls = 0;
    $workerRecovery = $runNative(213, $workerGamets+1, static function () use (&$parentModelCalls): callable {
        return static function (array $messages) use (&$parentModelCalls): string { $parentModelCalls++; return autonomyRecoveryReply('npc:900002'); };
    });
    $workerDecision = autonomyRecoverySql($connection, 'SELECT claim_id::text,status,child_document_id::text child FROM plugins.correspondence_autonomy_decisions WHERE playthrough_id=$1 AND source_document_id=$2', [AUTONOMY_RECOVERY_SCOPE_WORKER_DEATH,$workerSource])[0] ?? [];
    autonomyRecoveryCheck(($workerDecision['status'] ?? null) === 'stale' && ($workerDecision['claim_id'] ?? null) === $ready['claim_id'], 'F02_killed_worker_claim_must_be_terminally_stale_on_next_fresh_request');
    autonomyRecoveryCheck(($workerDecision['child'] ?? null) === null && $parentModelCalls === 0 && ($workerRecovery['model_calls'] ?? null) === 0, 'F02_killed_worker_recovery_must_not_call_model_or_create_child');
    autonomyRecoveryCheck(autonomyRecoveryCounts($connection) === $beforeWorkerRecovery, 'F02_killed_worker_recovery_must_not_write_books_operations_or_commands');
} catch (Throwable $error) {
    autonomyRecoveryCheck(false, 'F02_worker_death_case_failed:'.$error->getMessage());
} finally {
    if (is_resource($workerProcess)) {
        $status = proc_get_status($workerProcess);
        if ($status['running'] ?? false) proc_terminate($workerProcess, 9);
        $reapDeadline = hrtime(true) + 5_000_000_000;
        do {
            $status = proc_get_status($workerProcess);
            if (!($status['running'] ?? false) || hrtime(true) >= $reapDeadline) break;
            usleep(25000);
        } while (true);
        proc_close($workerProcess);
        $workerProcess = null;
    }
    autonomyRecoveryCleanupWorkerTmpdir($workerDirectory);
}

// R4 SQL failure: PostgreSQL must recover its aborted transaction before persisting unknown.
try {
    $activateScope(AUTONOMY_RECOVERY_SCOPE_SQL_ERROR);
    $insertNative(220, 100);
    $store->bindRequestClock(['inputtext','220','100','SQL-error dispatch source setup']);
    $enableScope(AUTONOMY_RECOVERY_SCOPE_SQL_ERROR);
    $sqlSource = $makeSource('This source exercises an actual SQL error during dispatch.','npc:900001','npc:900002','Camilla');
    $sqlQueued = $runNative(221, 100+correspondenceAutonomyGamets(2), static fn(): callable => static fn(array $messages): string => autonomyRecoveryReply('npc:900002'));
    autonomyRecoveryRequire(($sqlQueued['status'] ?? null) === 'queued', 'R4_SQL_source_must_queue_child');
    $sqlDecision = autonomyRecoverySql($connection, 'SELECT claim_id::text,child_document_id::text child_id,due_gamets::text due FROM plugins.correspondence_autonomy_decisions WHERE playthrough_id=$1 AND source_document_id=$2', [AUTONOMY_RECOVERY_SCOPE_SQL_ERROR,$sqlSource])[0] ?? [];
    autonomyRecoveryRequire(is_string($sqlDecision['child_id'] ?? null) && is_string($sqlDecision['due'] ?? null), 'R4_SQL_queued_child_missing');
    $beforeSqlDispatch = autonomyRecoveryCounts($connection);
    $beforeSqlAnchors = autonomyRecoverySql($connection, "SELECT count(*)::text n FROM public.eventlog WHERE sess='correspondence_v1'");
    autonomyRecoverySql($connection, "SELECT set_config('correspondence.recovery_document_id',$1,FALSE),set_config('correspondence.recovery_failure_mode','sql_error',FALSE)", [$sqlDecision['child_id']]);
    $sqlFactoryCalls = 0;
    $sqlUnavailableModel = static function () use (&$sqlFactoryCalls): ?callable { $sqlFactoryCalls++; return null; };
    $sqlResult = $runNative(222, (int)$sqlDecision['due'], $sqlUnavailableModel);
    autonomyRecoverySql($connection, "SELECT set_config('correspondence.recovery_document_id','',FALSE),set_config('correspondence.recovery_failure_mode','',FALSE)");
    $sqlFaultHits = autonomyRecoverySql($connection, 'SELECT last_value::text,is_called::text FROM public.recovery_autonomy_sql_fault_hits');
    $sqlAfter = autonomyRecoverySql($connection, "SELECT claim_id::text,status,payload->>'reason_code' reason FROM plugins.correspondence_autonomy_decisions WHERE playthrough_id=$1 AND source_document_id=$2", [AUTONOMY_RECOVERY_SCOPE_SQL_ERROR,$sqlSource])[0] ?? [];
    autonomyRecoveryCheck($sqlFaultHits === [['last_value'=>'1','is_called'=>'true']] && pg_transaction_status($connection) === PGSQL_TRANSACTION_IDLE, 'R4_SQL_fault_must_fire_once_and_leave_connection_outside_aborted_transaction');
    autonomyRecoveryCheck(($sqlResult['status'] ?? null) === 'unknown' && ($sqlAfter['status'] ?? null) === 'unknown' && ($sqlAfter['reason'] ?? null) === 'dispatch_uncertain' && ($sqlAfter['claim_id'] ?? null) === $sqlDecision['claim_id'], 'R4_SQL_error_must_commit_unknown_for_same_claim');
    $sqlChild = $store->loadDocument($sqlDecision['child_id']);
    autonomyRecoveryCheck(is_array($sqlChild) && ($sqlChild['operation_id'] ?? null) === null, 'R4_SQL_error_must_rollback_child_operation_link');
    autonomyRecoveryCheck(autonomyRecoveryCounts($connection) === $beforeSqlDispatch && autonomyRecoverySql($connection, "SELECT count(*)::text n FROM public.eventlog WHERE sess='correspondence_v1'") === $beforeSqlAnchors, 'R4_SQL_error_must_rollback_books_operations_commands_and_dispatch_anchor');
    autonomyRecoveryCheck($sqlFactoryCalls <= 1 && ($sqlResult['model_calls'] ?? null) === 0, 'R4_SQL_error_dispatch_must_not_call_model');
    $sqlFactoryCallsBeforeRepeat = $sqlFactoryCalls;
    $sqlRepeat = $runNative(223, (int)$sqlDecision['due']+1, $sqlUnavailableModel);
    $sqlRepeated = autonomyRecoverySql($connection, "SELECT claim_id::text,status,payload->>'reason_code' reason FROM plugins.correspondence_autonomy_decisions WHERE playthrough_id=$1 AND source_document_id=$2", [AUTONOMY_RECOVERY_SCOPE_SQL_ERROR,$sqlSource])[0] ?? [];
    autonomyRecoveryCheck($sqlRepeated === $sqlAfter && ($sqlFactoryCalls - $sqlFactoryCallsBeforeRepeat) <= 1 && ($sqlRepeat['model_calls'] ?? null) === 0
        && autonomyRecoveryCounts($connection) === $beforeSqlDispatch
        && autonomyRecoverySql($connection, 'SELECT last_value::text,is_called::text FROM public.recovery_autonomy_sql_fault_hits') === $sqlFaultHits
        && autonomyRecoverySql($connection, "SELECT count(*)::text n FROM public.eventlog WHERE sess='correspondence_v1'") === $beforeSqlAnchors, 'R4_SQL_unknown_must_not_retry_or_dispatch_on_next_fresh_request');
} catch (Throwable $error) {
    autonomyRecoveryCheck(false, 'R4_SQL_case_setup_or_execution_failed:'.$error->getMessage());
} finally {
    autonomyRecoverySql($connection, "SELECT set_config('correspondence.recovery_document_id','',FALSE),set_config('correspondence.recovery_failure_mode','',FALSE)");
}

// F02: exercise both invalid-anchor exits and the restored game-clock bound in the new sweep.
foreach (['pruned'=>'700006','malformed'=>'700007','restored'=>'700008'] as $edge => $edgeScope) {
    try {
        $baseTs = 230 + 10*((int)$edgeScope-700006);
        $edgeGamets = 100+correspondenceAutonomyGamets(2);
        $activateScope($edgeScope);
        $insertNative($baseTs, 100);
        $store->bindRequestClock(['inputtext',(string)$baseTs,'100','claim-anchor edge source setup']);
        $enableScope($edgeScope);
        $edgeSource = $makeSource('This source exercises '.$edge.' claim recovery.','npc:900001','npc:900002','Camilla');
        $edgeClaim = null;
        $edgeFailed = $runNative($baseTs+1, $edgeGamets, static function () use ($connection, $edgeScope, $edgeSource, $baseTs, $edgeGamets, &$edgeClaim): callable {
            return static function (array $messages) use ($connection, $edgeScope, $edgeSource, $baseTs, $edgeGamets, &$edgeClaim): string {
                $edgeClaim = autonomyRecoverySql($connection, "SELECT claim_id::text,status,payload::text FROM plugins.correspondence_autonomy_decisions WHERE playthrough_id=$1 AND source_document_id=$2 AND status='claimed'", [$edgeScope,$edgeSource])[0] ?? null;
                autonomyRecoveryRequire(is_array($edgeClaim), 'F02_edge_claim_must_commit_before_callback');
                autonomyRecoverySql($connection, "INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES($1,$2,'inputtext','later edge request','',$1,'','','')", [$baseTs+2,$edgeGamets+1]);
                throw new RuntimeException('synthetic edge worker failure');
            };
        });
        autonomyRecoveryRequire(is_array($edgeClaim) && ($edgeFailed['reason'] ?? null) === 'storage_error', 'F02_edge_setup_must_reject_stale_completion');
        $edgePayload = json_decode((string)$edgeClaim['payload'], true, 64, JSON_THROW_ON_ERROR);
        autonomyRecoveryRequire($store->eventMatches($edgePayload['claim_anchor'] ?? []), 'F02_edge_setup_requires_real_claim_anchor');
        $edgeAged = autonomyRecoverySql($connection, "UPDATE plugins.correspondence_autonomy_decisions SET created_at=clock_timestamp()-interval '181 seconds' WHERE playthrough_id=$1 AND source_document_id=$2 AND claim_id=$3 AND status='claimed' RETURNING claim_id::text", [$edgeScope,$edgeSource,$edgeClaim['claim_id']]);
        autonomyRecoveryRequire(count($edgeAged) === 1, 'F02_edge_setup_must_age_exact_claim');
        if ($edge === 'pruned') {
            $deleted = autonomyRecoverySql($connection, 'DELETE FROM public.eventlog WHERE rowid=$1 RETURNING rowid::text', [$edgePayload['claim_anchor']['rowid']]);
            autonomyRecoveryRequire(count($deleted) === 1 && !$store->eventMatches($edgePayload['claim_anchor']), 'F02_edge_prune_must_remove_real_claim_anchor');
        } elseif ($edge === 'malformed') {
            autonomyRecoverySql($connection, "UPDATE plugins.correspondence_autonomy_decisions SET payload=jsonb_set(payload,'{claim_anchor}','\"malformed-anchor\"'::jsonb) WHERE playthrough_id=$1 AND source_document_id=$2 AND claim_id=$3", [$edgeScope,$edgeSource,$edgeClaim['claim_id']]);
        }
        $freshGamets = $edge === 'restored' ? $edgeGamets-1 : $edgeGamets+2;
        if ($edge === 'restored') autonomyRecoveryRequire($store->eventMatches($edgePayload['claim_anchor']) && (int)$edgePayload['claim_anchor']['gamets'] > $freshGamets, 'F02_restore_must_retain_genuine_future_claim_anchor');
        $beforeEdgeEffects = autonomyRecoveryCounts($connection);
        $beforeEdgeAnchors = autonomyRecoverySql($connection, "SELECT count(*)::text n FROM public.eventlog WHERE type='info'");
        $otherClaims = autonomyRecoverySql($connection, 'SELECT playthrough_id,source_document_id::text,status,payload::text FROM plugins.correspondence_autonomy_decisions WHERE playthrough_id<>$1 ORDER BY playthrough_id,source_document_id', [$edgeScope]);
        $edgeFactoryCalls = 0;
        $edgeUnavailableModel = static function () use (&$edgeFactoryCalls): ?callable { $edgeFactoryCalls++; return null; };
        $edgeFactoryCallsBeforeRecovery = $edgeFactoryCalls;
        $edgeResult = $runNative($baseTs+3, $freshGamets, $edgeUnavailableModel);
        $edgeRecoveryFactoryCalls = $edgeFactoryCalls - $edgeFactoryCallsBeforeRecovery;
        $edgeAfter = autonomyRecoverySql($connection, 'SELECT claim_id::text,status,action,child_document_id::text,due_gamets::text,payload::text FROM plugins.correspondence_autonomy_decisions WHERE playthrough_id=$1 AND source_document_id=$2', [$edgeScope,$edgeSource])[0] ?? [];
        $edgeAfterPayload = json_decode((string)($edgeAfter['payload'] ?? '{}'), true, 64, JSON_THROW_ON_ERROR);
        autonomyRecoveryCheck(($edgeResult['reason'] ?? null) === 'abandoned_claim_retired' && ($edgeAfter['status'] ?? null) === 'stale'
            && ($edgeAfter['claim_id'] ?? null) === $edgeClaim['claim_id'] && ($edgeAfterPayload['reason_code'] ?? null) === 'claim_anchor_invalid'
            && ($edgeAfter['action'] ?? null) === null && ($edgeAfter['child_document_id'] ?? null) === null && ($edgeAfter['due_gamets'] ?? null) === null, 'F02_'.$edge.'_anchor_must_terminally_stale_same_claim');
        autonomyRecoveryCheck(!array_key_exists('completion_anchor', $edgeAfterPayload) && !array_key_exists('completed_gamets', $edgeAfterPayload)
            && autonomyRecoverySql($connection, "SELECT count(*)::text n FROM public.eventlog WHERE type='info'") === $beforeEdgeAnchors, 'F02_'.$edge.'_anchor_must_not_fabricate_completion_event');
        autonomyRecoveryCheck($edgeRecoveryFactoryCalls <= 1 && ($edgeResult['model_calls'] ?? null) === 0 && autonomyRecoveryCounts($connection) === $beforeEdgeEffects, 'F02_'.$edge.'_recovery_must_not_call_model_or_create_effect');
        autonomyRecoveryCheck(autonomyRecoverySql($connection, 'SELECT playthrough_id,source_document_id::text,status,payload::text FROM plugins.correspondence_autonomy_decisions WHERE playthrough_id<>$1 ORDER BY playthrough_id,source_document_id', [$edgeScope]) === $otherClaims, 'F02_'.$edge.'_recovery_must_preserve_other_scope_claims');
        $edgeFactoryCallsBeforeRepeat = $edgeFactoryCalls;
        $edgeRepeat = $runNative($baseTs+4, $freshGamets+1, $edgeUnavailableModel);
        autonomyRecoveryCheck(autonomyRecoverySql($connection, 'SELECT claim_id::text,status,action,child_document_id::text,due_gamets::text,payload::text FROM plugins.correspondence_autonomy_decisions WHERE playthrough_id=$1 AND source_document_id=$2', [$edgeScope,$edgeSource]) === [$edgeAfter]
            && ($edgeFactoryCalls - $edgeFactoryCallsBeforeRepeat) <= 1 && ($edgeRepeat['model_calls'] ?? null) === 0 && autonomyRecoveryCounts($connection) === $beforeEdgeEffects
            && autonomyRecoverySql($connection, "SELECT count(*)::text n FROM public.eventlog WHERE type='info'") === $beforeEdgeAnchors, 'F02_'.$edge.'_terminal_recovery_must_be_idempotent');
    } catch (Throwable $error) {
        autonomyRecoveryCheck(false, 'F02_'.$edge.'_case_setup_or_execution_failed:'.$error->getMessage());
    }
}

$failures = $GLOBALS['autonomyRecoveryFailures'];
$report = [
    'status'=>$failures === [] ? 'PASS' : 'RED',
    'checks'=>$GLOBALS['autonomyRecoveryChecks'],
    'failures'=>$failures,
    'f02_claim_age_recovery'=>true,
    'f02_killed_worker_recovery'=>true,
    'f02_invalid_claim_anchor_recovery'=>true,
    'f02_restore_claim_recovery'=>true,
    'r4_dispatch_savepoint'=>true,
    'r4_sql_error_savepoint'=>true,
    'r3_model_free_dispatch'=>true,
    'synthetic_only'=>true,
];
echo json_encode($report, JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
exit($failures === [] ? 0 : 1);
