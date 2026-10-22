<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/support/recovery_pg_fixture.php';
$candidate = dirname(__DIR__, 2);
require_once $candidate.'/server/lib/store.php';
require_once $candidate.'/server/lib/offscreen.php';
require_once $candidate.'/server/offscreen-worker.php';

$offscreenLivenessConnection = correspondenceRecoveryFixture('chim_corr_recovery_offscreen');

function livenessSqlLabel(string $sql): string
{
    $operation = preg_match('/\A\s*(SELECT|INSERT|UPDATE|DELETE)\b/i', $sql, $operationMatch) === 1
        ? strtolower($operationMatch[1])
        : 'query';
    $relation = preg_match('/\b(?:FROM|INTO|UPDATE)\s+((?:public|plugins|chim_meta|information_schema)\.[a-z_]+)/i', $sql, $relationMatch) === 1
        ? strtolower($relationMatch[1])
        : '';
    if ($relation === 'public.core_npc_master' && preg_match('/\bSET\s+personality\s*=/i', $sql) === 1) return 'npc_personality_update';
    $table = match ($relation) {
        'public.core_npc_master' => 'npc_master',
        'public.eventlog' => 'eventlog',
        'plugins.correspondence_offscreen_jobs' => 'offscreen_jobs',
        'plugins.correspondence_offscreen_mail' => 'offscreen_mail',
        'plugins.correspondence_autonomy_settings' => 'autonomy_settings',
        'information_schema.tables' => 'schema_inventory',
        default => 'fixture_query',
    };
    return $operation.'_'.$table;
}

function livenessSql(string $sql, array $args = []): array
{
    $connection = $GLOBALS['offscreenLivenessConnection'];
    $result = @pg_query_params($connection, $sql, $args);
    if ($result === false) {
        $sqlState = 'unavailable';
        $lastError = @pg_last_error($connection);
        if (is_string($lastError) && preg_match('/SQLSTATE(?:\s*[:=\[]\s*|\s+)([0-9A-Z]{5})/i', $lastError, $stateMatch) === 1) {
            $sqlState = strtoupper($stateMatch[1]);
        }
        fwrite(STDERR, json_encode(['liveness_fixture_sql_error'=>[
            'label'=>livenessSqlLabel($sql),
            'sqlstate'=>$sqlState,
        ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
        throw new RuntimeException('liveness_fixture_sql_failed');
    }
    try { return pg_num_fields($result) > 0 ? (pg_fetch_all($result) ?: []) : []; }
    finally { pg_free_result($result); }
}

function livenessCheck(bool $condition, string $reason): void
{
    if (!$condition) throw new RuntimeException($reason);
}

function livenessFinish(array $failures): void
{
    livenessCheck($failures === [], 'offscreen_liveness_regressions_failed:'.implode(',', $failures));
}

function livenessRecord(array &$failures, bool $condition, string $reason, array $observed = []): void
{
    if (!$condition) {
        $failures[] = $reason;
        if ($observed !== []) {
            fwrite(STDERR, json_encode(['liveness_failure'=>[
                'case'=>$reason,
                'observed'=>$observed,
            ]], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES).PHP_EOL);
        }
    }
}

function livenessObserve(CorrespondenceOffscreenPg $engine, int $ts, int $gamets): void
{
    $engine->observe(['bored', (string)$ts, (string)$gamets, 'trusted offscreen liveness fixture']);
}

function livenessPrivate(CorrespondenceOffscreenPg $engine, string $method, array $arguments = []): mixed
{
    $reflection = new ReflectionMethod($engine, $method);
    $reflection->setAccessible(true);
    return $reflection->invokeArgs($engine, $arguments);
}

function livenessArrivedMail(
    CorrespondenceOffscreenPg $engine,
    CorrespondencePgStore $store,
    int $senderId,
    int $recipientId,
    string $body,
    int &$ts,
    int &$gamets
): array {
    $gamets += correspondenceAutonomyGamets(24);
    $ts++;
    livenessObserve($engine, $ts, $gamets);
    $actors = livenessPrivate($engine, 'npcMap', [livenessPrivate($engine, 'npcRows')]);
    $mail = $store->transaction(static fn() => livenessPrivate($engine, 'createMail', [
        'unprofiled', $actors[$senderId], $actors[$recipientId], $body, $gamets, null, 'letter',
    ]));

    $gamets = max($gamets + 1, (int)$mail['due_gamets'] + 100);
    $ts++;
    livenessObserve($engine, $ts, $gamets);
    $mail = livenessPrivate($engine, 'mailById', ['unprofiled', $mail['message_id']]);
    $arrival = $store->transaction(static function () use ($engine, $store, $mail, $gamets): array {
        $reference = livenessPrivate($engine, 'appendAnchor', ['mail_arrived', [
            'message_id'=>$mail['message_id'],'sender_id'=>(string)$mail['sender_id'],
            'recipient_id'=>(string)$mail['recipient_id'],'gamets'=>(string)$gamets,
        ]]);
        $changed = $store->query("UPDATE plugins.correspondence_offscreen_mail SET status='arrived',arrived_gamets=$4,payload=payload||jsonb_build_object('arrival_anchor',$2::jsonb) WHERE playthrough_id=$1 AND message_id=$3 AND status='in_transit' RETURNING message_id::text", [
            'unprofiled', correspondenceJson($reference), $mail['message_id'], (string)$gamets,
        ]);
        return ['reference'=>$reference,'changed'=>count($changed)];
    });
    livenessCheck($arrival['changed'] === 1, 'fixture_arrival_transition_failed');

    $gamets += correspondenceAutonomyGamets(2);
    $ts++;
    livenessObserve($engine, $ts, $gamets);
    return livenessPrivate($engine, 'mailById', ['unprofiled', $mail['message_id']]);
}

function livenessDeleteFixtureMailTree(CorrespondencePgStore $store, array $rootMail): int
{
    return $store->transaction(static function () use ($rootMail): int {
        $rows = livenessSql("WITH RECURSIVE mail_tree(message_id) AS (
            SELECT message_id FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=$1::uuid
            UNION
            SELECT child.message_id FROM plugins.correspondence_offscreen_mail child
            JOIN mail_tree parent ON child.payload->>'parent_message_id'=parent.message_id::text
            WHERE child.playthrough_id='unprofiled'
        ) SELECT message_id::text FROM mail_tree", [(string)($rootMail['message_id'] ?? '')]);
        $messageIds = array_column($rows, 'message_id');
        if ($messageIds === []) throw new RuntimeException('fixture_mail_tree_cleanup_source_missing');
        foreach ($messageIds as $messageId) {
            if (!is_string($messageId) || preg_match('/\A[0-9a-f-]{36}\z/iD', $messageId) !== 1) {
                throw new RuntimeException('fixture_mail_tree_cleanup_id_invalid');
            }
        }
        $uuidArray = '{'.implode(',', $messageIds).'}';
        livenessSql("DELETE FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id='unprofiled' AND source_message_id=ANY($1::uuid[])", [$uuidArray]);
        livenessSql("DELETE FROM public.eventlog WHERE (sess='correspondence_v1' AND (data::jsonb->>'message_id'=ANY($1::text[])
                OR data::jsonb->>'source_message_id'=ANY($1::text[])))
            OR (sess='written_evidence_v1' AND data::jsonb->>'document_id'=ANY($1::text[]))", [$uuidArray]);
        $deleted = livenessSql("DELETE FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=ANY($1::uuid[]) RETURNING message_id::text", [$uuidArray]);
        if (count($deleted) !== count($messageIds)) throw new RuntimeException('fixture_mail_tree_cleanup_conflict');
        return count($messageIds);
    });
}

function livenessReply(string $recipient, string $body): string
{
    return json_encode([
        'action'=>'reply','recipient'=>$recipient,'body'=>$body,'reason'=>'A current letter gives me a reason to answer.',
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function livenessSeedInitiationClaim(
    CorrespondenceOffscreenPg $engine,
    CorrespondencePgStore $store,
    int &$ts,
    int &$gamets
): array {
    $ts++;
    $gamets++;
    livenessObserve($engine, $ts, $gamets);
    $actors = livenessPrivate($engine, 'npcMap', [livenessPrivate($engine, 'npcRows')]);
    $self = $actors[900001];
    $contacts = livenessPrivate($engine, 'contacts', [$self, $actors]);
    $goalText = trim((string)$self['goal_text']);
    $goal = ['id'=>hash('sha256', $goalText),'text'=>$goalText];
    $context = [
        'self'=>livenessPrivate($engine, 'selfProjection', [$self, $contacts]),
        'contacts'=>$contacts,'goal'=>$goal,
    ];
    $observation = livenessPrivate($engine, 'bindLatestObservation');
    return $store->transaction(static fn() => livenessPrivate($engine, 'claim', [
        'unprofiled',$observation,$self,'initiate',null,$goal,$context,
    ]));
}

function livenessSeedReactionClaim(
    CorrespondenceOffscreenPg $engine,
    CorrespondencePgStore $store,
    array $mail
): array {
    $actors = livenessPrivate($engine, 'npcMap', [livenessPrivate($engine, 'npcRows')]);
    $source = livenessPrivate($engine, 'sourceForMail', [$mail,$actors]);
    $self = $actors[(int)$mail['recipient_id']] ?? null;
    livenessCheck(is_array($source) && is_array($self), 'reaction_claim_fixture_source_invalid');
    $contacts = livenessPrivate($engine, 'contacts', [$self,$actors]);
    $context = [
        'self'=>livenessPrivate($engine,'selfProjection',[$self,$contacts]),
        'contacts'=>$contacts,'source'=>$source,
    ];
    $observation = livenessPrivate($engine, 'bindLatestObservation');
    livenessCheck(is_array($observation), 'reaction_claim_fixture_observation_missing');
    return $store->transaction(static fn() => livenessPrivate($engine, 'claim', [
        'unprofiled',$observation,$self,'react',$mail,null,$context,
    ]));
}

function livenessRetireFixtureSource(
    CorrespondenceOffscreenPg $engine,
    CorrespondencePgStore $store,
    array $mail
): void {
    $generation = livenessPrivate($engine, 'restoreGeneration');
    $retired = $store->transaction(static fn() => livenessPrivate($engine, 'retireUnexposedSource', [
        'unprofiled',(string)$mail['message_id'],'reaction_abandoned',$generation,$mail,
    ]));
    livenessCheck($retired, 'fixture_source_cleanup_failed');
}

function chimInteractionFile()
{
    $path = $GLOBALS['offscreen_liveness_lock_path'] ?? null;
    $handle = is_string($path) ? @fopen($path, 'c+') : false;
    if (!is_resource($handle)) throw new RuntimeException('interaction_lock_fixture_unavailable');
    return $handle;
}

function chimInteractionRead($handle): array
{
    $path = $GLOBALS['offscreen_liveness_lock_path'] ?? null;
    $probe = is_string($path) ? @fopen($path, 'c+') : false;
    if (!is_resource($probe)) throw new RuntimeException('interaction_lock_probe_unavailable');
    $acquired = @flock($probe, LOCK_EX | LOCK_NB);
    if ($acquired) @flock($probe, LOCK_UN);
    fclose($probe);
    return ['enabled'=>true, 'generation'=>(int)($GLOBALS['offscreen_liveness_generation'] ?? 1)];
}

function chimInteractionBegin(): void
{
    if (!array_key_exists('chim_interaction_generation', $GLOBALS)) {
        $GLOBALS['chim_interaction_generation'] = (int)($GLOBALS['offscreen_liveness_generation'] ?? 1);
    }
}

function chimInteractionState(): array
{
    return ['enabled'=>true, 'generation'=>(int)($GLOBALS['offscreen_liveness_generation'] ?? 1)];
}

livenessCheck(PHP_SAPI === 'cli', 'fixture_must_run_as_cli');
livenessCheck(livenessSql("SELECT table_name FROM information_schema.tables WHERE table_schema IN ('public','plugins','chim_meta')") === [], 'fixture_database_must_begin_empty');

$ddl = <<<'SQL'
CREATE SCHEMA chim_meta;
CREATE TABLE chim_meta.playthrough_profiles(id bigint PRIMARY KEY,is_active boolean NOT NULL);
CREATE TABLE public.eventlog(rowid bigserial PRIMARY KEY,ts bigint NOT NULL,gamets numeric NOT NULL,type text NOT NULL,data text NOT NULL,sess text NOT NULL,localts bigint NOT NULL,people text NOT NULL,location text NOT NULL,party text NOT NULL);
CREATE TABLE public.core_npc_master(id bigint PRIMARY KEY,npc_name text NOT NULL,personality text NOT NULL,extended_data jsonb NOT NULL DEFAULT '{}'::jsonb,metadata jsonb NOT NULL DEFAULT '{}'::jsonb);
INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(100,100,'inputtext','synthetic liveness fixture seed','',100,'','','');
INSERT INTO public.core_npc_master(id,npc_name,personality,extended_data,metadata) VALUES
  (900001,'Camilla','Thoughtful and private.','{"background_life_enabled":true,"background_life_letters":true,"background_life_goals":"Ask Sigrid about the harvest.","relationships":{"Sigrid":{"aff":-31.5}}}','{"last_coords":"[0,0,0,\"00013488\"]"}'),
  (900002,'Sigrid','Practical and curious.','{"background_life_enabled":true,"background_life_letters":true}','{"last_coords":"[0,0,0,\"00013488\"]"}'),
  (900003,'Faendal','Quiet and observant.','{"background_life_enabled":true,"background_life_letters":true}','{"last_coords":"[0,0,0,\"00013488\"]"}');
SQL;
livenessCheck(@pg_query($offscreenLivenessConnection, $ddl) !== false, 'fixture_schema_create_failed');

$lockPath = tempnam(sys_get_temp_dir(), 'offscreen-liveness-lock-');
livenessCheck(is_string($lockPath), 'interaction_lock_fixture_unavailable');
$GLOBALS['offscreen_liveness_lock_path'] = $lockPath;
$GLOBALS['offscreen_liveness_generation'] = 41;
$GLOBALS['chim_interaction_generation'] = 41;
$GLOBALS['ptr_runtime_generation'] = 'runtime-generation-liveness-1';
register_shutdown_function(static function () use ($lockPath): void {
    if (is_file($lockPath)) @unlink($lockPath);
});

$store = new CorrespondencePgStore($offscreenLivenessConnection, static fn($s) => null, static fn($title, $body) => true);
$store->ensureSchema();
$engine = new CorrespondenceOffscreenPg($store);
$engine->ensureSchema();
livenessSql("INSERT INTO plugins.correspondence_autonomy_settings(playthrough_id,enabled) VALUES('unprofiled',TRUE)");
$originalRecipientData = (string)(livenessSql("SELECT extended_data::text FROM public.core_npc_master WHERE id=900002")[0]['extended_data'] ?? '');
$originalRecipientPersonality = (string)(livenessSql("SELECT personality FROM public.core_npc_master WHERE id=900002")[0]['personality'] ?? '');
$originalSenderData = (string)(livenessSql("SELECT extended_data::text FROM public.core_npc_master WHERE id=900001")[0]['extended_data'] ?? '');

$ts = 101;
$gamets = 101;
livenessObserve($engine, $ts, $gamets);
if (in_array('--concurrent-events-only', $argv, true) || in_array('--concurrent-observation-only', $argv, true) || in_array('--completion-lock-only', $argv, true)) {
    $mail = livenessArrivedMail($engine,$store,900001,900002,'A concurrent event must not lose this reply.',$ts,$gamets);
    $modelCalls = 0;
    $heldLock = null;
    $result = $engine->tick(static function (array $messages) use ($engine,&$ts,&$gamets,&$modelCalls,&$heldLock,$argv,$lockPath): string {
        $modelCalls++;
        $ts++;
        $gamets++;
        if (in_array('--concurrent-observation-only',$argv,true)) livenessObserve($engine,$ts,$gamets);
        elseif (in_array('--completion-lock-only',$argv,true)) {
            $heldLock=fopen($lockPath,'c+');
            livenessCheck(is_resource($heldLock) && flock($heldLock,LOCK_EX|LOCK_NB),'fixture_competing_interaction_lock_required');
        } else livenessSql("INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES($1,$2,'infoaction','Unrelated concurrent event','',100,'','','')",[$ts,$gamets]);
        return livenessReply('npc:900001','One carefully considered response survives ordinary background events.');
    });
    if (is_resource($heldLock)) {flock($heldLock,LOCK_UN);fclose($heldLock);}
    $job = livenessSql("SELECT status,payload->>'reason_code' reason FROM plugins.correspondence_offscreen_jobs WHERE source_message_id=$1",[$mail['message_id']])[0] ?? [];
    $children=(int)(livenessSql("SELECT count(*) n FROM plugins.correspondence_offscreen_mail WHERE payload->>'parent_message_id'=$1",[$mail['message_id']])[0]['n'] ?? 0);
    if (in_array('--completion-lock-only',$argv,true)) {
        livenessCheck(($job['status'] ?? null) !== 'claimed' && $children===0 && $modelCalls===1,'completion_lock_failure_must_close_claim_without_effects');
    } else {
        $marker=in_array('--concurrent-observation-only',$argv,true)?'concurrent_observation_must_commit_valid_offscreen_reply':'concurrent_event_must_commit_valid_offscreen_reply';
        livenessCheck(($result['status'] ?? null)==='processed' && ($job['status'] ?? null)==='sent' && $children===1 && $modelCalls===1,$marker);
    }
    echo json_encode(['status'=>'PASS','case'=>$argv[1],'model_calls'=>$modelCalls,'job_status'=>$job['status'] ?? null,'children'=>$children],JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
}

$failures = [];
$ineligibleIds = [];
$validLaterMail = null;

try {
    for ($i = 0; $i < 17; $i++) {
        $mail = livenessArrivedMail($engine,$store,900001,900002,'Disabled-recipient pending letter '.$i.'.',$ts,$gamets);
        $ineligibleIds[] = $mail['message_id'];
    }
    livenessSql("UPDATE public.core_npc_master SET extended_data=jsonb_set(extended_data,'{background_life_letters}','false'::jsonb,TRUE) WHERE id=900002");
    $validLaterMail = livenessArrivedMail($engine,$store,900003,900001,'Valid letter after seventeen disabled recipients.',$ts,$gamets);
    $noJobCount = livenessSql("SELECT count(*)::int AS amount FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id='unprofiled' AND source_message_id=ANY($1::uuid[])", ['{'.implode(',', $ineligibleIds).'}'])[0]['amount'] ?? 0;
    livenessRecord($failures, (int)$noJobCount === 0, 'ineligible_arrival_fixture_must_have_no_reaction_jobs');

    $mailCalls = 0;
    for ($i = 0; $i < 18; $i++) {
        $ts++;
        $gamets++;
        livenessObserve($engine, $ts, $gamets);
        $result = $engine->tick(static function (array $messages) use (&$mailCalls): string {
            $mailCalls++;
            return livenessReply('npc:900003','One reply to the later valid letter.');
        });
    }
    $ineligibleAfter = livenessSql("SELECT count(*)::int AS amount FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=ANY($1::uuid[]) AND status='stale' AND payload->>'reason_code'='actor_ineligible'", ['{'.implode(',', $ineligibleIds).'}'])[0]['amount'] ?? 0;
    $validAfter = livenessSql("SELECT status,source_event_id::text FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=$1", [$validLaterMail['message_id']])[0] ?? [];
    $validChildren = livenessSql("SELECT count(*)::int AS amount FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND payload->>'parent_message_id'=$1", [$validLaterMail['message_id']])[0]['amount'] ?? 0;
    livenessRecord($failures, (int)$ineligibleAfter === 17, 'seventeen_ineligible_no_job_arrivals_must_each_become_stale');
    livenessRecord($failures, $mailCalls === 1 && ($validAfter['status'] ?? null) === 'exposed'
        && is_string($validAfter['source_event_id'] ?? null) && (int)$validChildren === 1,
        'valid_later_arrival_must_get_one_reaction_after_ineligible_rows');

    $ts++;
    $gamets += correspondenceAutonomyGamets(8);
    livenessObserve($engine, $ts, $gamets);
    $currentActors = livenessPrivate($engine, 'npcMap', [livenessPrivate($engine, 'npcRows')]);
    $senderCandidate = livenessPrivate($engine, 'initiationCandidate', ['unprofiled',$gamets,$currentActors]);
    livenessRecord($failures, (int)($senderCandidate['self']['id'] ?? 0) === 900001,
        'living_sender_must_be_eligible_to_initiate_after_ineligible_arrivals_are_retired');
    $validMailTreeCount = livenessDeleteFixtureMailTree($store, $validLaterMail);
    livenessCheck($validMailTreeCount === 1 + (int)$validChildren, 'fixture_mail_tree_cleanup_must_match_asserted_root_and_children');

    if (in_array('--arrivals-only', $argv ?? [], true)) {
        livenessSql("UPDATE public.core_npc_master SET extended_data=$2::jsonb WHERE id=$1", ['900002',$originalRecipientData]);
        livenessFinish($failures);
        echo json_encode(['mode'=>'arrivals-only','ineligible_job_count'=>(int)$noJobCount,'ineligible_stale_count'=>(int)$ineligibleAfter,
            'valid_mail_status'=>$validAfter['status'] ?? null,'valid_mail_exposed'=>is_string($validAfter['source_event_id'] ?? null),
            'valid_model_calls'=>$mailCalls,'valid_child_count'=>(int)$validChildren,
            'sender_candidate_id'=>$senderCandidate['self']['id'] ?? null], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
        exit(0);
    }

    foreach ($ineligibleIds as $messageId) {
        livenessSql("DELETE FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=$1 AND source_event_id IS NULL", [$messageId]);
        livenessSql("DELETE FROM public.eventlog WHERE type='info' AND sess='correspondence_v1' AND data::jsonb->>'message_id'=$1", [$messageId]);
    }
    livenessSql("UPDATE public.core_npc_master SET extended_data=$2::jsonb WHERE id=$1", ['900002',$originalRecipientData]);

    $contextMail = livenessArrivedMail($engine,$store,900001,900002,'Letter preserved across one context reconsideration.',$ts,$gamets);
    $contextCalls = 0;
    $firstContext = $engine->tick(static function (array $messages) use (&$contextCalls): string {
        $contextCalls++;
        livenessSql("UPDATE public.core_npc_master SET personality='Sigrid after the first context drift.' WHERE id=900002");
        return livenessReply('npc:900001','This first response must be rejected.');
    });
    $contextJobFirst = livenessSql("SELECT job_id::text,status,payload->>'attempt' AS attempt,payload->>'reconsiderable' AS reconsiderable,payload->'completion_anchor'->>'rowid' AS completion_anchor,payload->>'reason_code' AS reason_code FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id='unprofiled' AND source_message_id=$1", [$contextMail['message_id']])[0] ?? [];
    $contextAnchorFirst = livenessSql("SELECT data::jsonb->>'attempt' AS attempt,data::jsonb->>'reconsiderable' AS reconsiderable,data::jsonb->>'reason_code' AS reason_code FROM public.eventlog WHERE rowid::text=$1", [$contextJobFirst['completion_anchor'] ?? ''])[0] ?? [];
    $contextSourceFirst = livenessSql("SELECT status,source_event_id::text FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=$1", [$contextMail['message_id']])[0] ?? [];
    $firstEffects = livenessSql("SELECT count(*)::int AS amount FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND payload->>'parent_message_id'=$1", [$contextMail['message_id']])[0]['amount'] ?? 0;
    $firstExposures = livenessSql("SELECT count(*)::int AS amount FROM public.eventlog WHERE sess='written_evidence_v1' AND data::jsonb->>'document_id'=$1", [$contextMail['message_id']])[0]['amount'] ?? 0;
    livenessRecord($failures, ($firstContext['status'] ?? null) === 'stale'
        && ($contextJobFirst['reason_code'] ?? null) === 'context_changed'
        && (string)($contextJobFirst['attempt'] ?? '') === '0'
        && ($contextJobFirst['reconsiderable'] ?? null) === 'true'
        && ($contextAnchorFirst['reason_code'] ?? null) === 'context_changed'
        && (string)($contextAnchorFirst['attempt'] ?? '') === '0'
        && ($contextAnchorFirst['reconsiderable'] ?? null) === 'true'
        && ($contextSourceFirst['status'] ?? null) === 'arrived'
        && ($contextSourceFirst['source_event_id'] ?? null) === null && (int)$firstEffects === 0 && (int)$firstExposures === 0,
        'stale_context_output_must_not_expose_or_create_social_effects', [
            'result_status'=>$firstContext['status'] ?? null,
            'result_reason'=>$firstContext['reason'] ?? null,
            'result_model_calls'=>$firstContext['model_calls'] ?? null,
            'model_callback_calls'=>$contextCalls,
            'job_status'=>$contextJobFirst['status'] ?? null,
            'job_reason'=>$contextJobFirst['reason_code'] ?? null,
            'job_attempt'=>$contextJobFirst['attempt'] ?? null,
            'job_reconsiderable'=>$contextJobFirst['reconsiderable'] ?? null,
            'anchor_reason'=>$contextAnchorFirst['reason_code'] ?? null,
            'anchor_attempt'=>$contextAnchorFirst['attempt'] ?? null,
            'anchor_reconsiderable'=>$contextAnchorFirst['reconsiderable'] ?? null,
            'source_status'=>$contextSourceFirst['status'] ?? null,
            'source_event_present'=>is_string($contextSourceFirst['source_event_id'] ?? null),
            'child_count'=>(int)$firstEffects,
            'exposure_count'=>(int)$firstExposures,
        ]);

    $ts++;
    $gamets += correspondenceAutonomyGamets(8);
    livenessObserve($engine,$ts,$gamets);
    $secondContext = $engine->tick(static function (array $messages) use (&$contextCalls): string {
        $contextCalls++;
        livenessSql("UPDATE public.core_npc_master SET personality='Sigrid after the second context drift.' WHERE id=900002");
        return livenessReply('npc:900001','This reconsidered response must also be rejected.');
    });
    $contextJobSecond = livenessSql("SELECT job_id::text,status,payload->>'attempt' AS attempt,payload->>'reconsiderable' AS reconsiderable,payload->>'reason_code' AS reason_code FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id='unprofiled' AND source_message_id=$1", [$contextMail['message_id']])[0] ?? [];
    livenessRecord($failures, ($secondContext['status'] ?? null) === 'stale'
        && ($contextJobSecond['job_id'] ?? null) === ($contextJobFirst['job_id'] ?? null)
        && ($contextJobSecond['reason_code'] ?? null) === 'context_changed'
        && (string)($contextJobSecond['attempt'] ?? '') === '1' && ($contextJobSecond['reconsiderable'] ?? null) !== 'true'
        && $contextCalls === 2,
        'one_reconsideration_must_reuse_job_and_reject_second_stale_output', [
            'result_status'=>$secondContext['status'] ?? null,
            'result_reason'=>$secondContext['reason'] ?? null,
            'result_model_calls'=>$secondContext['model_calls'] ?? null,
            'model_callback_calls'=>$contextCalls,
            'same_job'=>($contextJobSecond['job_id'] ?? null) === ($contextJobFirst['job_id'] ?? null),
            'job_status'=>$contextJobSecond['status'] ?? null,
            'job_reason'=>$contextJobSecond['reason_code'] ?? null,
            'job_attempt'=>$contextJobSecond['attempt'] ?? null,
            'job_reconsiderable'=>$contextJobSecond['reconsiderable'] ?? null,
        ]);

    $ts++;
    $gamets++;
    livenessObserve($engine,$ts,$gamets);
    $contextRetirementCalls = 0;
    $contextRetirement = $engine->tick(static function () use (&$contextRetirementCalls): string {
        $contextRetirementCalls++;
        return livenessReply('npc:900001','A third attempt must not occur.');
    });
    $contextSourceFinal = livenessSql("SELECT status,source_event_id::text,payload->>'reason_code' AS reason_code FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=$1", [$contextMail['message_id']])[0] ?? [];
    $contextChildrenFinal = livenessSql("SELECT count(*)::int AS amount FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND payload->>'parent_message_id'=$1", [$contextMail['message_id']])[0]['amount'] ?? 0;
    livenessRecord($failures, $contextRetirementCalls === 0 && ($contextRetirement['reason'] ?? null) === 'reaction_abandoned'
        && ($contextSourceFinal['status'] ?? null) === 'stale'
        && ($contextSourceFinal['reason_code'] ?? null) === 'reaction_abandoned'
        && ($contextSourceFinal['source_event_id'] ?? null) === null && (int)$contextChildrenFinal === 0,
        'second_stale_result_must_terminalize_without_a_third_model_call', [
            'result_status'=>$contextRetirement['status'] ?? null,
            'result_reason'=>$contextRetirement['reason'] ?? null,
            'result_model_calls'=>$contextRetirement['model_calls'] ?? null,
            'model_callback_calls'=>$contextRetirementCalls,
            'source_status'=>$contextSourceFinal['status'] ?? null,
            'source_reason'=>$contextSourceFinal['reason_code'] ?? null,
            'source_event_present'=>is_string($contextSourceFinal['source_event_id'] ?? null),
            'child_count'=>(int)$contextChildrenFinal,
        ]);

    livenessSql("UPDATE public.core_npc_master SET personality=$2 WHERE id=$1", ['900002',$originalRecipientPersonality]);

    $forgedMarkerMail = livenessArrivedMail($engine,$store,900001,900002,'Payload-only retry marker forgery.',$ts,$gamets);
    $forgedMarkerWork = livenessSeedReactionClaim($engine,$store,$forgedMarkerMail);
    livenessCheck(($forgedMarkerWork['result_kind'] ?? null) === 'claimed', 'forged_marker_fixture_claim_failed');
    $store->transaction(static fn() => livenessPrivate($engine, 'terminalJob', [$forgedMarkerWork,'stale',null,'context_changed',null,true,null,false]));
    livenessSql("UPDATE plugins.correspondence_offscreen_jobs SET payload=jsonb_set(payload,'{reconsiderable}','true'::jsonb,TRUE) WHERE playthrough_id='unprofiled' AND job_id=$1", [(string)$forgedMarkerWork['job_id']]);
    $forgedMarkerRecovery = $store->transaction(static fn() => livenessPrivate($engine,'recoverTerminalReactionSources',['unprofiled']));
    $forgedMarkerJob = livenessSql("SELECT status,payload->>'attempt' AS attempt,payload->>'reconsiderable' AS reconsiderable,payload->'completion_anchor'->>'rowid' AS completion_anchor FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id='unprofiled' AND job_id=$1", [(string)$forgedMarkerWork['job_id']])[0] ?? [];
    $forgedMarkerAnchor = livenessSql("SELECT data::jsonb->>'reconsiderable' AS reconsiderable FROM public.eventlog WHERE rowid::text=$1", [$forgedMarkerJob['completion_anchor'] ?? ''])[0] ?? [];
    $forgedMarkerSource = livenessSql("SELECT status,body,source_event_id::text FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=$1", [$forgedMarkerMail['message_id']])[0] ?? [];
    $forgedMarkerChildren = livenessSql("SELECT count(*)::int AS amount FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND payload->>'parent_message_id'=$1", [$forgedMarkerMail['message_id']])[0]['amount'] ?? 0;
    $forgedMarkerExposures = livenessSql("SELECT count(*)::int AS amount FROM public.eventlog WHERE sess='written_evidence_v1' AND data::jsonb->>'document_id'=$1", [$forgedMarkerMail['message_id']])[0]['amount'] ?? 0;
    livenessRecord($failures, $forgedMarkerRecovery === false && ($forgedMarkerJob['status'] ?? null) === 'stale'
        && (string)($forgedMarkerJob['attempt'] ?? '') === '0' && ($forgedMarkerJob['reconsiderable'] ?? null) === 'true'
        && ($forgedMarkerAnchor['reconsiderable'] ?? null) === null && ($forgedMarkerSource['status'] ?? null) === 'arrived'
        && ($forgedMarkerSource['body'] ?? null) === $forgedMarkerMail['body']
        && ($forgedMarkerSource['source_event_id'] ?? null) === null && (int)$forgedMarkerChildren === 0
        && (int)$forgedMarkerExposures === 0,
        'payload_only_reconsiderable_forgery_must_not_retry_or_change_source_effects');
    livenessRetireFixtureSource($engine,$store,$forgedMarkerMail);

    $forgedAttemptMail = livenessArrivedMail($engine,$store,900001,900002,'Payload-only attempt forgery.',$ts,$gamets);
    $forgedAttemptWork = livenessSeedReactionClaim($engine,$store,$forgedAttemptMail);
    livenessCheck(($forgedAttemptWork['result_kind'] ?? null) === 'claimed', 'forged_attempt_fixture_claim_failed');
    $store->transaction(static fn() => livenessPrivate($engine, 'terminalJob', [$forgedAttemptWork,'stale',null,'context_changed',null,true,null,true]));
    livenessSql("UPDATE plugins.correspondence_offscreen_jobs SET payload=jsonb_set(payload,'{attempt}','1'::jsonb,TRUE) WHERE playthrough_id='unprofiled' AND job_id=$1", [(string)$forgedAttemptWork['job_id']]);
    $forgedAttemptRecovery = $store->transaction(static fn() => livenessPrivate($engine,'recoverTerminalReactionSources',['unprofiled']));
    $forgedAttemptJob = livenessSql("SELECT status,payload->>'attempt' AS attempt,payload->>'reconsiderable' AS reconsiderable,payload->'completion_anchor'->>'rowid' AS completion_anchor FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id='unprofiled' AND job_id=$1", [(string)$forgedAttemptWork['job_id']])[0] ?? [];
    $forgedAttemptAnchor = livenessSql("SELECT data::jsonb->>'attempt' AS attempt,data::jsonb->>'reconsiderable' AS reconsiderable FROM public.eventlog WHERE rowid::text=$1", [$forgedAttemptJob['completion_anchor'] ?? ''])[0] ?? [];
    $forgedAttemptSource = livenessSql("SELECT status,body,source_event_id::text FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=$1", [$forgedAttemptMail['message_id']])[0] ?? [];
    $forgedAttemptChildren = livenessSql("SELECT count(*)::int AS amount FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND payload->>'parent_message_id'=$1", [$forgedAttemptMail['message_id']])[0]['amount'] ?? 0;
    $forgedAttemptExposures = livenessSql("SELECT count(*)::int AS amount FROM public.eventlog WHERE sess='written_evidence_v1' AND data::jsonb->>'document_id'=$1", [$forgedAttemptMail['message_id']])[0]['amount'] ?? 0;
    livenessRecord($failures, $forgedAttemptRecovery === false && ($forgedAttemptJob['status'] ?? null) === 'stale'
        && (string)($forgedAttemptJob['attempt'] ?? '') === '1' && ($forgedAttemptJob['reconsiderable'] ?? null) === 'true'
        && (string)($forgedAttemptAnchor['attempt'] ?? '') === '0' && ($forgedAttemptAnchor['reconsiderable'] ?? null) === 'true'
        && ($forgedAttemptSource['status'] ?? null) === 'arrived'
        && ($forgedAttemptSource['body'] ?? null) === $forgedAttemptMail['body']
        && ($forgedAttemptSource['source_event_id'] ?? null) === null && (int)$forgedAttemptChildren === 0
        && (int)$forgedAttemptExposures === 0,
        'payload_only_attempt_forgery_must_not_retry_or_change_source_effects');
    livenessRetireFixtureSource($engine,$store,$forgedAttemptMail);

    $replayMail = livenessArrivedMail($engine,$store,900001,900002,'Attempt zero must not finish after attempt one owns the job.',$ts,$gamets);
    $replayWork0 = livenessSeedReactionClaim($engine,$store,$replayMail);
    livenessCheck(($replayWork0['result_kind'] ?? null) === 'claimed', 'replayed_attempt_zero_fixture_claim_failed');
    $store->transaction(static fn() => livenessPrivate($engine, 'terminalJob', [$replayWork0,'stale',null,'context_changed',null,true,null,true]));
    $ts++;
    $gamets += correspondenceAutonomyGamets(8);
    livenessObserve($engine,$ts,$gamets);
    $replayWork1 = livenessSeedReactionClaim($engine,$store,$replayMail);
    livenessCheck(($replayWork1['result_kind'] ?? null) === 'claimed' && (int)($replayWork1['attempt'] ?? -1) === 1
        && ($replayWork1['job_id'] ?? null) === ($replayWork0['job_id'] ?? null), 'replayed_attempt_one_fixture_claim_failed');
    $replayedFinish = livenessPrivate($engine, 'finishWork', [$replayWork0,'reply','A stale attempt zero reply.','npc:900001',true,'',25]);
    $replayJobAfter = livenessSql("SELECT status,claim_id::text,payload->>'attempt' AS attempt FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id='unprofiled' AND job_id=$1", [(string)$replayWork1['job_id']])[0] ?? [];
    $replaySourceAfter = livenessSql("SELECT status,body,source_event_id::text FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=$1", [$replayMail['message_id']])[0] ?? [];
    $replayChildrenAfter = livenessSql("SELECT count(*)::int AS amount FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND payload->>'parent_message_id'=$1", [$replayMail['message_id']])[0]['amount'] ?? 0;
    $replayExposureAfter = livenessSql("SELECT count(*)::int AS amount FROM public.eventlog WHERE sess='written_evidence_v1' AND data::jsonb->>'document_id'=$1", [$replayMail['message_id']])[0]['amount'] ?? 0;
    livenessRecord($failures, ($replayedFinish['status'] ?? null) === 'stale'
        && ($replayJobAfter['status'] ?? null) === 'claimed' && ($replayJobAfter['claim_id'] ?? null) === ($replayWork1['claim_id'] ?? null)
        && (string)($replayJobAfter['attempt'] ?? '') === '1' && ($replaySourceAfter['status'] ?? null) === 'arrived'
        && ($replaySourceAfter['body'] ?? null) === $replayMail['body']
        && ($replaySourceAfter['source_event_id'] ?? null) === null
        && (int)$replayChildrenAfter === 0 && (int)$replayExposureAfter === 0,
        'attempt_zero_replay_after_attempt_one_claim_must_not_change_ownership_or_effects');
    $store->transaction(static fn() => livenessPrivate($engine, 'terminalJob', [$replayWork1,'stale',null,'model_error',null,true,null,false]));
    livenessRetireFixtureSource($engine,$store,$replayMail);

    $forgedGenerationMail = livenessArrivedMail($engine,$store,900001,900002,'Payload-only restore generation forgery.',$ts,$gamets);
    $forgedGenerationWork = livenessSeedReactionClaim($engine,$store,$forgedGenerationMail);
    livenessCheck(($forgedGenerationWork['result_kind'] ?? null) === 'claimed', 'forged_generation_fixture_claim_failed');
    $forgedGeneration = 'payload-only-foreign-generation';
    livenessSql("UPDATE plugins.correspondence_offscreen_jobs SET payload=jsonb_set(payload,'{restore_generation}',to_jsonb($2::text),TRUE) WHERE playthrough_id=$1 AND job_id=$3", ['unprofiled',$forgedGeneration,(string)$forgedGenerationWork['job_id']]);
    $ts++;
    $gamets++;
    livenessObserve($engine,$ts,$gamets);
    $forgedGenerationCalls = 0;
    $forgedGenerationResult = $engine->tick(static function () use (&$forgedGenerationCalls): string {
        $forgedGenerationCalls++;
        return livenessReply('npc:900001','A forged generation must not gain authority.');
    });
    $forgedGenerationJob = livenessSql("SELECT status,payload->>'reason_code' AS reason_code,payload->>'claim_invalidated' AS claim_invalidated,payload->>'restore_generation' AS restore_generation FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id='unprofiled' AND job_id=$1", [(string)$forgedGenerationWork['job_id']])[0] ?? [];
    $forgedGenerationSource = livenessSql("SELECT status,body,source_event_id::text FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=$1", [$forgedGenerationMail['message_id']])[0] ?? [];
    $forgedGenerationChildren = livenessSql("SELECT count(*)::int AS amount FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND payload->>'parent_message_id'=$1", [$forgedGenerationMail['message_id']])[0]['amount'] ?? 0;
    $forgedGenerationExposures = livenessSql("SELECT count(*)::int AS amount FROM public.eventlog WHERE sess='written_evidence_v1' AND data::jsonb->>'document_id'=$1", [$forgedGenerationMail['message_id']])[0]['amount'] ?? 0;
    livenessRecord($failures, ($forgedGenerationResult['reason'] ?? null) === 'claim_anchor_invalid' && $forgedGenerationCalls === 0
        && ($forgedGenerationJob['status'] ?? null) === 'stale' && ($forgedGenerationJob['reason_code'] ?? null) === 'claim_anchor_invalid'
        && ($forgedGenerationJob['claim_invalidated'] ?? null) === 'true'
        && ($forgedGenerationJob['restore_generation'] ?? null) === $forgedGeneration
        && ($forgedGenerationSource['status'] ?? null) === 'arrived'
        && ($forgedGenerationSource['body'] ?? null) === $forgedGenerationMail['body']
        && ($forgedGenerationSource['source_event_id'] ?? null) === null
        && (int)$forgedGenerationChildren === 0 && (int)$forgedGenerationExposures === 0,
        'payload_only_restore_generation_forgery_must_not_retire_current_source');

    $oldGenerationBefore = $GLOBALS['ptr_runtime_generation'];
    $oldGeneration = 'runtime-generation-liveness-old';
    $GLOBALS['ptr_runtime_generation'] = $oldGeneration;
    $oldGenerationMail = livenessArrivedMail($engine,$store,900001,900002,'A verified old-generation claim must retire its preserved source.',$ts,$gamets);
    $oldGenerationWork = livenessSeedReactionClaim($engine,$store,$oldGenerationMail);
    livenessCheck(($oldGenerationWork['result_kind'] ?? null) === 'claimed', 'old_generation_reaction_fixture_claim_failed');
    $GLOBALS['ptr_runtime_generation'] = 'runtime-generation-liveness-current';
    $ts++;
    $gamets++;
    livenessObserve($engine,$ts,$gamets);
    $oldGenerationCalls = 0;
    $oldGenerationResult = $engine->tick(static function () use (&$oldGenerationCalls): string {
        $oldGenerationCalls++;
        return livenessReply('npc:900002','An old generation must never reply.');
    });
    $oldGenerationJob = livenessSql("SELECT status,claim_id::text,payload->>'attempt' AS attempt,payload->>'reason_code' AS reason_code,payload->>'claim_invalidated' AS claim_invalidated,payload->>'restore_generation' AS restore_generation FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id='unprofiled' AND job_id=$1", [(string)$oldGenerationWork['job_id']])[0] ?? [];
    $oldGenerationSource = livenessSql("SELECT status,body,source_event_id::text,payload->>'reason_code' AS reason_code,payload->'stale_anchor'->>'rowid' AS stale_anchor FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=$1", [$oldGenerationMail['message_id']])[0] ?? [];
    $oldGenerationAnchor = livenessSql("SELECT data::jsonb->>'transition' AS transition,data::jsonb->>'reason_code' AS reason_code,data::jsonb->>'message_id' AS message_id FROM public.eventlog WHERE rowid::text=$1", [$oldGenerationSource['stale_anchor'] ?? ''])[0] ?? [];
    $lateOldFinish = livenessPrivate($engine, 'finishWork', [$oldGenerationWork,'reply','Late reply from an old restore generation.','npc:900002',true,'',30]);
    $oldGenerationChildren = livenessSql("SELECT count(*)::int AS amount FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND payload->>'parent_message_id'=$1", [$oldGenerationMail['message_id']])[0]['amount'] ?? 0;
    $oldGenerationExposures = livenessSql("SELECT count(*)::int AS amount FROM public.eventlog WHERE sess='written_evidence_v1' AND data::jsonb->>'document_id'=$1", [$oldGenerationMail['message_id']])[0]['amount'] ?? 0;
    livenessRecord($failures, ($oldGenerationResult['reason'] ?? null) === 'restore_changed' && $oldGenerationCalls === 0
        && ($oldGenerationJob['status'] ?? null) === 'stale' && ($oldGenerationJob['claim_id'] ?? null) === ($oldGenerationWork['claim_id'] ?? null)
        && (string)($oldGenerationJob['attempt'] ?? '') === '0' && ($oldGenerationJob['reason_code'] ?? null) === 'restore_changed'
        && ($oldGenerationJob['claim_invalidated'] ?? null) === 'true' && ($oldGenerationJob['restore_generation'] ?? null) === $oldGenerationWork['restore_generation']
        && ($oldGenerationSource['status'] ?? null) === 'stale' && ($oldGenerationSource['body'] ?? null) === $oldGenerationMail['body']
        && ($oldGenerationSource['source_event_id'] ?? null) === null && ($oldGenerationSource['reason_code'] ?? null) === 'restore_changed'
        && ($oldGenerationAnchor['transition'] ?? null) === 'mail_stale'
        && ($oldGenerationAnchor['reason_code'] ?? null) === 'restore_changed'
        && ($oldGenerationAnchor['message_id'] ?? null) === $oldGenerationMail['message_id']
        && ($lateOldFinish['status'] ?? null) === 'stale'
        && (int)$oldGenerationChildren === 0 && (int)$oldGenerationExposures === 0,
        'old_generation_claim_must_close_and_retire_source_without_revive_or_effects');
    $ts++;
    $gamets += correspondenceAutonomyGamets(8);
    livenessObserve($engine,$ts,$gamets);
    $currentActors = livenessPrivate($engine, 'npcMap', [livenessPrivate($engine, 'npcRows')]);
    $senderAfterRestore = livenessPrivate($engine, 'initiationCandidate', ['unprofiled',$gamets,$currentActors]);
    $senderNpc = $currentActors[900001] ?? null;
    $senderContacts = is_array($senderNpc) ? livenessPrivate($engine, 'contacts', [$senderNpc,$currentActors]) : [];
    $senderCooldown = is_array($senderNpc) ? livenessPrivate($engine, 'actorCooldown', ['unprofiled',900001,$gamets]) : null;
    $senderPending = livenessSql("SELECT count(*)::int AS amount FROM plugins.correspondence_offscreen_mail m WHERE m.playthrough_id=$1 AND m.sender_id=$2 AND m.depth=0 AND m.status IN ('in_transit','arrived') AND NOT (m.status='arrived' AND m.source_event_id IS NULL AND m.source_event_sha256 IS NULL AND EXISTS (SELECT 1 FROM plugins.correspondence_offscreen_jobs j WHERE j.playthrough_id=m.playthrough_id AND j.source_message_id=m.message_id AND j.status IN ('failed','stale') AND j.action IS NULL AND j.payload->>'kind'='react'))", ['unprofiled','900001'])[0]['amount'] ?? 0;
    livenessRecord($failures, (int)($senderAfterRestore['self']['id'] ?? 0) === 900001,
        'living_sender_must_be_unblocked_after_old_generation_source_retirement', [
            'candidate_sender_id'=>$senderAfterRestore['self']['id'] ?? null,
            'sender_enabled'=>$senderNpc['enabled'] ?? null,
            'sender_letters'=>$senderNpc['letters'] ?? null,
            'sender_dead'=>$senderNpc['dead'] ?? null,
            'sender_has_goal'=>is_string($senderNpc['goal_text'] ?? null) && trim($senderNpc['goal_text']) !== '',
            'contact_count'=>count($senderContacts),
            'actor_cooldown_gamets'=>$senderCooldown,
            'current_gamets'=>$gamets,
            'pending_depth0_count'=>(int)$senderPending,
            'old_generation_job_status'=>$oldGenerationJob['status'] ?? null,
            'old_generation_job_reason'=>$oldGenerationJob['reason_code'] ?? null,
            'old_generation_source_status'=>$oldGenerationSource['status'] ?? null,
            'old_generation_source_reason'=>$oldGenerationSource['reason_code'] ?? null,
            'old_generation_source_event_present'=>is_string($oldGenerationSource['source_event_id'] ?? null),
            'old_generation_child_count'=>(int)$oldGenerationChildren,
            'old_generation_exposure_count'=>(int)$oldGenerationExposures,
        ]);
    $GLOBALS['ptr_runtime_generation'] = $oldGenerationBefore;

    $prunedGeneration = 'runtime-generation-liveness-pruned';
    $GLOBALS['ptr_runtime_generation'] = $prunedGeneration;
    $prunedMail = livenessArrivedMail($engine,$store,900001,900002,'A pruned source anchor must quarantine rather than expose.',$ts,$gamets);
    $prunedWork = livenessSeedReactionClaim($engine,$store,$prunedMail);
    livenessCheck(($prunedWork['result_kind'] ?? null) === 'claimed', 'pruned_source_fixture_claim_failed');
    livenessSql("UPDATE plugins.correspondence_offscreen_mail SET payload=jsonb_set(payload,'{arrival_anchor,data_sha256}',to_jsonb(repeat('0',64)),TRUE) WHERE playthrough_id='unprofiled' AND message_id=$1", [$prunedMail['message_id']]);
    $GLOBALS['ptr_runtime_generation'] = 'runtime-generation-liveness-after-prune';
    $ts++;
    $gamets++;
    livenessObserve($engine,$ts,$gamets);
    $prunedCalls = 0;
    $prunedResult = $engine->tick(static function () use (&$prunedCalls): string {
        $prunedCalls++;
        return livenessReply('npc:900001','An invalid source must not reach the model.');
    });
    $prunedJob = livenessSql("SELECT status,payload->>'claim_invalidated' AS claim_invalidated,payload->>'reason_code' AS reason_code FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id='unprofiled' AND job_id=$1", [(string)$prunedWork['job_id']])[0] ?? [];
    $prunedSource = livenessSql("SELECT status,body,source_event_id::text,payload->>'reason_code' AS reason_code FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=$1", [$prunedMail['message_id']])[0] ?? [];
    $prunedChildren = livenessSql("SELECT count(*)::int AS amount FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND payload->>'parent_message_id'=$1", [$prunedMail['message_id']])[0]['amount'] ?? 0;
    $prunedExposures = livenessSql("SELECT count(*)::int AS amount FROM public.eventlog WHERE sess='written_evidence_v1' AND data::jsonb->>'document_id'=$1", [$prunedMail['message_id']])[0]['amount'] ?? 0;
    livenessRecord($failures, ($prunedResult['reason'] ?? null) === 'mail_quarantined' && $prunedCalls === 0
        && ($prunedJob['status'] ?? null) === 'stale' && ($prunedJob['claim_invalidated'] ?? null) === 'true'
        && ($prunedJob['reason_code'] ?? null) === 'restore_changed'
        && ($prunedSource['status'] ?? null) === 'stale' && ($prunedSource['body'] ?? null) === $prunedMail['body']
        && ($prunedSource['source_event_id'] ?? null) === null && ($prunedSource['reason_code'] ?? null) === 'anchor_invalid'
        && (int)$prunedChildren === 0 && (int)$prunedExposures === 0,
        'pruned_old_generation_source_anchor_must_quarantine_without_effects');

    $invalidExposureGeneration = 'runtime-generation-liveness-invalid-exposure';
    $GLOBALS['ptr_runtime_generation'] = $invalidExposureGeneration;
    $invalidExposureMail = livenessArrivedMail($engine,$store,900001,900002,'Inconsistent source-event state must be quarantined.',$ts,$gamets);
    $invalidExposureWork = livenessSeedReactionClaim($engine,$store,$invalidExposureMail);
    livenessCheck(($invalidExposureWork['result_kind'] ?? null) === 'claimed', 'invalid_exposure_fixture_claim_failed');
    livenessSql("UPDATE plugins.correspondence_offscreen_mail SET source_event_id=999999999,source_event_sha256=repeat('0',64) WHERE playthrough_id='unprofiled' AND message_id=$1", [$invalidExposureMail['message_id']]);
    $GLOBALS['ptr_runtime_generation'] = 'runtime-generation-liveness-after-invalid-exposure';
    $ts++;
    $gamets++;
    livenessObserve($engine,$ts,$gamets);
    $invalidExposureCalls = 0;
    $invalidExposureResult = $engine->tick(static function () use (&$invalidExposureCalls): string {
        $invalidExposureCalls++;
        return livenessReply('npc:900002','An inconsistent source must not reach the model.');
    });
    $invalidExposureJob = livenessSql("SELECT status,payload->>'claim_invalidated' AS claim_invalidated,payload->>'reason_code' AS reason_code FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id='unprofiled' AND job_id=$1", [(string)$invalidExposureWork['job_id']])[0] ?? [];
    $invalidExposureSource = livenessSql("SELECT status,body,source_event_id::text,payload->>'reason_code' AS reason_code,payload->'stale_anchor'->>'rowid' AS stale_anchor FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=$1", [$invalidExposureMail['message_id']])[0] ?? [];
    $invalidExposureAnchor = livenessSql("SELECT data::jsonb->>'transition' AS transition,data::jsonb->>'reason_code' AS reason_code,data::jsonb->>'message_id' AS message_id FROM public.eventlog WHERE rowid::text=$1", [$invalidExposureSource['stale_anchor'] ?? ''])[0] ?? [];
    $invalidExposureChildren = livenessSql("SELECT count(*)::int AS amount FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND payload->>'parent_message_id'=$1", [$invalidExposureMail['message_id']])[0]['amount'] ?? 0;
    $invalidExposureEffects = livenessSql("SELECT count(*)::int AS amount FROM public.eventlog WHERE sess='written_evidence_v1' AND data::jsonb->>'document_id'=$1", [$invalidExposureMail['message_id']])[0]['amount'] ?? 0;
    livenessRecord($failures, ($invalidExposureResult['reason'] ?? null) === 'mail_quarantined' && $invalidExposureCalls === 0
        && ($invalidExposureJob['status'] ?? null) === 'stale' && ($invalidExposureJob['claim_invalidated'] ?? null) === 'true'
        && ($invalidExposureJob['reason_code'] ?? null) === 'restore_changed'
        && ($invalidExposureSource['status'] ?? null) === 'stale' && ($invalidExposureSource['body'] ?? null) === $invalidExposureMail['body']
        && ($invalidExposureSource['source_event_id'] ?? null) === '999999999' && ($invalidExposureSource['reason_code'] ?? null) === 'anchor_invalid'
        && ($invalidExposureAnchor['transition'] ?? null) === 'mail_stale' && ($invalidExposureAnchor['reason_code'] ?? null) === 'anchor_invalid'
        && ($invalidExposureAnchor['message_id'] ?? null) === $invalidExposureMail['message_id']
        && (int)$invalidExposureChildren === 0 && (int)$invalidExposureEffects === 0,
        'inconsistent_arrived_source_event_state_must_quarantine_without_effects');

    $GLOBALS['ptr_runtime_generation'] = 'runtime-generation-liveness-missing';
    $missingGenerationMail = livenessArrivedMail($engine,$store,900001,900002,'A missing generation must not authorize source retirement.',$ts,$gamets);
    $missingGenerationWork = livenessSeedReactionClaim($engine,$store,$missingGenerationMail);
    livenessCheck(($missingGenerationWork['result_kind'] ?? null) === 'claimed', 'missing_generation_fixture_claim_failed');
    livenessSql("UPDATE plugins.correspondence_offscreen_jobs SET payload=payload-'restore_generation' WHERE playthrough_id='unprofiled' AND job_id=$1", [(string)$missingGenerationWork['job_id']]);
    $GLOBALS['ptr_runtime_generation'] = 'runtime-generation-liveness-after-missing';
    $ts++;
    $gamets++;
    livenessObserve($engine,$ts,$gamets);
    $missingGenerationCalls = 0;
    $missingGenerationResult = $engine->tick(static function () use (&$missingGenerationCalls): string {
        $missingGenerationCalls++;
        return livenessReply('npc:900002','A missing-generation claim must not reach the model.');
    });
    $missingGenerationJob = livenessSql("SELECT status,payload->>'claim_invalidated' AS claim_invalidated,payload->>'reason_code' AS reason_code,payload->>'invalidated_restore_generation' AS invalidated_restore_generation,payload->>'restore_generation' AS restore_generation FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id='unprofiled' AND job_id=$1", [(string)$missingGenerationWork['job_id']])[0] ?? [];
    $missingGenerationSource = livenessSql("SELECT status,body,source_event_id::text FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=$1", [$missingGenerationMail['message_id']])[0] ?? [];
    $missingGenerationChildren = livenessSql("SELECT count(*)::int AS amount FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND payload->>'parent_message_id'=$1", [$missingGenerationMail['message_id']])[0]['amount'] ?? 0;
    $missingGenerationEffects = livenessSql("SELECT count(*)::int AS amount FROM public.eventlog WHERE sess='written_evidence_v1' AND data::jsonb->>'document_id'=$1", [$missingGenerationMail['message_id']])[0]['amount'] ?? 0;
    livenessRecord($failures, ($missingGenerationResult['reason'] ?? null) === 'claim_anchor_invalid' && $missingGenerationCalls === 0
        && ($missingGenerationJob['status'] ?? null) === 'stale' && ($missingGenerationJob['claim_invalidated'] ?? null) === 'true'
        && ($missingGenerationJob['reason_code'] ?? null) === 'claim_anchor_invalid'
        && ($missingGenerationJob['invalidated_restore_generation'] ?? null) === $store->restoreGeneration()
        && ($missingGenerationJob['restore_generation'] ?? null) === null
        && ($missingGenerationSource['status'] ?? null) === 'arrived' && ($missingGenerationSource['body'] ?? null) === $missingGenerationMail['body']
        && ($missingGenerationSource['source_event_id'] ?? null) === null
        && (int)$missingGenerationChildren === 0 && (int)$missingGenerationEffects === 0,
        'missing_restore_generation_must_only_inert_close_claim_without_retiring_source');

    $oldGenerationJobs = [];
    for ($i = 0; $i < 17; $i++) {
        $oldWork = livenessSeedInitiationClaim($engine,$store,$ts,$gamets);
        livenessCheck(($oldWork['result_kind'] ?? null) === 'claimed', 'old_generation_fixture_claim_failed');
        $oldGenerationJobs[] = (string)$oldWork['job_id'];
        $retiredGeneration = 'old-liveness-generation-'.$i;
        $changed = livenessSql("UPDATE plugins.correspondence_offscreen_jobs SET payload=jsonb_set(payload,'{restore_generation}',to_jsonb($3::text),TRUE),created_at=clock_timestamp()-INTERVAL '600 seconds' WHERE playthrough_id=$1 AND job_id=$2 RETURNING job_id::text", [
            'unprofiled',(string)$oldWork['job_id'],$retiredGeneration,
        ]);
        livenessCheck(count($changed) === 1, 'old_generation_fixture_payload_update_failed');
    }

    $crashedMail = livenessArrivedMail($engine,$store,900003,900002,'One crashed claim may be reconsidered once.',$ts,$gamets);
    $crashActors = livenessPrivate($engine, 'npcMap', [livenessPrivate($engine, 'npcRows')]);
    $crashSource = livenessPrivate($engine, 'sourceForMail', [$crashedMail,$crashActors]);
    $crashSelf = $crashActors[900002];
    $crashContacts = livenessPrivate($engine, 'contacts', [$crashSelf,$crashActors]);
    $crashContext = [
        'self'=>livenessPrivate($engine,'selfProjection',[$crashSelf,$crashContacts]),
        'contacts'=>$crashContacts,'source'=>$crashSource,
    ];
    $crashObservation = livenessPrivate($engine, 'bindLatestObservation');
    $crashWork = $store->transaction(static fn() => livenessPrivate($engine, 'claim', [
        'unprofiled',$crashObservation,$crashSelf,'react',$crashedMail,null,$crashContext,
    ]));
    livenessCheck(($crashWork['result_kind'] ?? null) === 'claimed', 'crashed_reaction_fixture_claim_failed');
    livenessSql("UPDATE plugins.correspondence_offscreen_jobs SET created_at=clock_timestamp()-INTERVAL '600 seconds' WHERE playthrough_id=$1 AND job_id=$2", ['unprofiled',(string)$crashWork['job_id']]);

    $ts++;
    $gamets++;
    livenessObserve($engine,$ts,$gamets);
    $expiry = $engine->tick(static function (): string { throw new RuntimeException('expired_claim_must_not_call_model'); });
    $expiredJob = livenessSql("SELECT job_id::text,status,claim_id::text,payload->>'attempt' AS attempt,payload->>'reconsiderable' AS reconsiderable,payload->'abandoned_anchor'->>'rowid' AS abandoned_anchor,payload->>'reason_code' AS reason_code FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id='unprofiled' AND source_message_id=$1", [$crashedMail['message_id']])[0] ?? [];
    $expiredAnchor = livenessSql("SELECT data::jsonb->>'attempt' AS attempt,data::jsonb->>'reconsiderable' AS reconsiderable,data::jsonb->>'reason_code' AS reason_code FROM public.eventlog WHERE rowid::text=$1", [$expiredJob['abandoned_anchor'] ?? ''])[0] ?? [];
    $expiredMail = livenessSql("SELECT status,source_event_id::text FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=$1", [$crashedMail['message_id']])[0] ?? [];
    $oldClaimsUnchanged = true;
    foreach ($oldGenerationJobs as $jobId) {
        $oldClaim = livenessSql("SELECT status FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id='unprofiled' AND job_id=$1", [$jobId])[0] ?? [];
        $oldClaimsUnchanged = $oldClaimsUnchanged && ($oldClaim['status'] ?? null) === 'claimed';
    }
    livenessRecord($failures, ($expiry['reason'] ?? null) === 'claim_timeout'
        && ($expiredJob['status'] ?? null) === 'stale' && ($expiredJob['reason_code'] ?? null) === 'claim_timeout'
        && (string)($expiredJob['attempt'] ?? '') === '0' && ($expiredJob['reconsiderable'] ?? null) === 'true'
        && (string)($expiredAnchor['attempt'] ?? '') === '0' && ($expiredAnchor['reconsiderable'] ?? null) === 'true'
        && ($expiredAnchor['reason_code'] ?? null) === 'claim_timeout'
        && ($expiredMail['status'] ?? null) === 'arrived' && ($expiredMail['source_event_id'] ?? null) === null
        && $oldClaimsUnchanged,
        'current_expired_claim_must_pass_seventeen_old_generation_claims_and_preserve_its_source');

    // Foreign initiation claims now retire one per observation, without touching the current retry.
    foreach ($oldGenerationJobs as $oldJobId) {
        $ts++;
        $gamets++;
        livenessObserve($engine,$ts,$gamets);
        $cleanup = $engine->tick(static function (): string { throw new RuntimeException('foreign_claim_cleanup_must_not_call_model'); });
        $oldClaim = livenessSql("SELECT status,payload->>'reason_code' AS reason FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id='unprofiled' AND job_id=$1", [$oldJobId])[0] ?? [];
        livenessCheck(($cleanup['reason'] ?? null)==='claim_anchor_invalid' && ($cleanup['model_calls'] ?? null)===0
            && ($oldClaim['status'] ?? null)==='stale' && ($oldClaim['reason'] ?? null)==='claim_anchor_invalid',
            'foreign_initiation_claim_must_retire_without_model_or_source_effects');
        $current = livenessSql("SELECT status,payload->>'reason_code' AS reason FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id='unprofiled' AND job_id=$1", [$crashWork['job_id']])[0] ?? [];
        livenessCheck(($current['status'] ?? null)==='stale' && ($current['reason'] ?? null)==='claim_timeout',
            'foreign_claim_cleanup_must_preserve_the_current_reconsiderable_claim');
    }
    $ts++;
    $gamets += correspondenceAutonomyGamets(8);
    livenessObserve($engine,$ts,$gamets);
    $crashCalls = 0;
    $reconsidered = $engine->tick(static function (array $messages) use (&$crashCalls): string {
        $crashCalls++;
        return livenessReply('npc:900003','One response after the worker crashed.');
    });
    $reconsideredJob = livenessSql("SELECT job_id::text,status,action,claim_id::text,payload->>'attempt' AS attempt FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id='unprofiled' AND source_message_id=$1", [$crashedMail['message_id']])[0] ?? [];
    $reconsideredMail = livenessSql("SELECT status,source_event_id::text FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=$1", [$crashedMail['message_id']])[0] ?? [];
    $crashChildren = livenessSql("SELECT count(*)::int AS amount FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND payload->>'parent_message_id'=$1", [$crashedMail['message_id']])[0]['amount'] ?? 0;
    livenessRecord($failures, ($reconsidered['status'] ?? null) === 'processed' && $crashCalls === 1
        && ($reconsideredJob['job_id'] ?? null) === ($crashWork['job_id'] ?? null)
        && ($reconsideredJob['status'] ?? null) === 'sent' && ($reconsideredJob['action'] ?? null) === 'reply'
        && (string)($reconsideredJob['attempt'] ?? '') === '1'
        && ($reconsideredMail['status'] ?? null) === 'exposed' && is_string($reconsideredMail['source_event_id'] ?? null)
        && (int)$crashChildren === 1,
        'crashed_claim_must_reuse_the_job_for_one_anchored_reply', [
            'expiry_status'=>$expiry['status'] ?? null,
            'expiry_reason'=>$expiry['reason'] ?? null,
            'expired_job_status'=>$expiredJob['status'] ?? null,
            'expired_job_reason'=>$expiredJob['reason_code'] ?? null,
            'expired_job_attempt'=>$expiredJob['attempt'] ?? null,
            'expired_job_reconsiderable'=>$expiredJob['reconsiderable'] ?? null,
            'reconsider_result_status'=>$reconsidered['status'] ?? null,
            'reconsider_result_reason'=>$reconsidered['reason'] ?? null,
            'reconsider_result_model_calls'=>$reconsidered['model_calls'] ?? null,
            'model_callback_calls'=>$crashCalls,
            'same_job'=>($reconsideredJob['job_id'] ?? null) === ($crashWork['job_id'] ?? null),
            'job_status'=>$reconsideredJob['status'] ?? null,
            'job_action'=>$reconsideredJob['action'] ?? null,
            'job_attempt'=>$reconsideredJob['attempt'] ?? null,
            'source_status'=>$reconsideredMail['status'] ?? null,
            'source_event_present'=>is_string($reconsideredMail['source_event_id'] ?? null),
            'child_count'=>(int)$crashChildren,
        ]);

    $ts++;
    $gamets += correspondenceAutonomyGamets(8);
    livenessSql("UPDATE public.core_npc_master SET extended_data=jsonb_set(extended_data,'{background_life_goals}','\"\"'::jsonb,TRUE) WHERE id=900001");
    livenessObserve($engine,$ts,$gamets);
    $repeatCalls = 0;
    $repeat = $engine->tick(static function () use (&$repeatCalls): string {
        $repeatCalls++;
        throw new RuntimeException('completed_source_must_not_repeat');
    });
    $finalChildren = livenessSql("SELECT count(*)::int AS amount FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND payload->>'parent_message_id'=$1", [$crashedMail['message_id']])[0]['amount'] ?? 0;
    $finalExposure = livenessSql("SELECT count(*)::int AS amount FROM public.eventlog WHERE sess='written_evidence_v1' AND data::jsonb->>'document_id'=$1", [$crashedMail['message_id']])[0]['amount'] ?? 0;
    livenessRecord($failures, $repeatCalls === 0 && (int)$finalChildren === 1 && (int)$finalExposure === 1,
        'reconsidered_reply_and_exposure_must_not_repeat', [
            'repeat_result_status'=>$repeat['status'] ?? null,
            'repeat_result_reason'=>$repeat['reason'] ?? null,
            'repeat_result_model_calls'=>$repeat['model_calls'] ?? null,
            'repeat_callback_calls'=>$repeatCalls,
            'job_status'=>$reconsideredJob['status'] ?? null,
            'job_action'=>$reconsideredJob['action'] ?? null,
            'job_attempt'=>$reconsideredJob['attempt'] ?? null,
            'source_status'=>$reconsideredMail['status'] ?? null,
            'source_event_present'=>is_string($reconsideredMail['source_event_id'] ?? null),
            'child_count'=>(int)$finalChildren,
            'exposure_count'=>(int)$finalExposure,
        ]);

} finally {
    livenessSql("UPDATE public.core_npc_master SET extended_data=$2::jsonb,personality=$3 WHERE id=$1", ['900002',$originalRecipientData,$originalRecipientPersonality]);
    livenessSql("UPDATE public.core_npc_master SET extended_data=$2::jsonb WHERE id=$1", ['900001',$originalSenderData]);
}
livenessFinish($failures);

echo json_encode([
    'status'=>'PASS',
    'ineligible_arrivals_do_not_pin_reactions_or_senders'=>true,
    'context_drift_gets_one_bounded_reconsideration'=>true,
    'retry_integrity_rejects_payload_only_forgery_and_old_claim_replay'=>true,
    'restore_generation_payload_forgery_cannot_retire_current_source'=>true,
    'old_generation_reaction_source_is_retired_without_effects'=>true,
    'pruned_old_generation_source_anchor_is_quarantined'=>true,
    'inconsistent_arrived_source_event_state_is_quarantined'=>true,
    'missing_restore_generation_is_inert_not_authority'=>true,
    'old_generation_claims_do_not_pin_expiry'=>true,
    'crashed_reaction_reconsiders_once_without_duplicate_effects'=>true,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
