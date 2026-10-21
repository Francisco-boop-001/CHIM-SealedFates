<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/support/recovery_pg_fixture.php';
$candidate = dirname(__DIR__, 2);
require_once $candidate.'/server/lib/store.php';
require_once $candidate.'/server/lib/offscreen.php';
require_once $candidate.'/server/offscreen-worker.php';

$recoveryConnection = correspondenceRecoveryFixture('chim_corr_recovery_offscreen');

function recoveryCheck(bool $ok, string $reason): void
{
    if (!$ok) throw new RuntimeException($reason);
}

function recoverySql(string $sql, array $args = []): array
{
    global $recoveryConnection;
    $result = @pg_query_params($recoveryConnection, $sql, $args);
    if ($result === false) throw new RuntimeException('fixture_sql_failed');
    try { return pg_num_fields($result) > 0 ? (pg_fetch_all($result) ?: []) : []; }
    finally { pg_free_result($result); }
}

function recoveryObserve(CorrespondenceOffscreenPg $engine, int $ts, int $gamets): void
{
    $engine->observe(['bored', (string)$ts, (string)$gamets, 'trusted native recovery fixture']);
}

function recoveryInitiation(string $body): string
{
    return json_encode(['action'=>'letter','recipient'=>'npc:900002','body'=>$body,'reason'=>'A current goal gives me a reason to write.'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function recoveryPrivate(CorrespondenceOffscreenPg $engine, string $method, array $arguments = []): mixed
{
    $reflection = new ReflectionMethod($engine, $method);
    $reflection->setAccessible(true);
    return $reflection->invokeArgs($engine, $arguments);
}

function recoveryFixtureExposedMail(
    CorrespondenceOffscreenPg $engine,
    CorrespondencePgStore $store,
    int $senderId,
    int $recipientId,
    string $body,
    int &$ts,
    int &$gamets
): void {
    $ts++;
    $gamets++;
    recoveryObserve($engine, $ts, $gamets);
    $actors = recoveryPrivate($engine, 'npcMap', [recoveryPrivate($engine, 'npcRows')]);
    $mail = $store->transaction(static fn() => recoveryPrivate($engine, 'createMail', [
        'unprofiled', $actors[$senderId], $actors[$recipientId], $body, $gamets, null, 'letter',
    ]));

    $gamets = (int)$mail['due_gamets'] + 100;
    $ts++;
    recoveryObserve($engine, $ts, $gamets);
    $arrivalCalls = 0;
    $arrival = $engine->tick(static function () use (&$arrivalCalls): string {
        $arrivalCalls++;
        throw new RuntimeException('arrival_must_not_call_model');
    });
    if ($arrival !== ['status'=>'processed','reason'=>'arrival','model_calls'=>0] || $arrivalCalls !== 0) {
        fwrite(STDERR, json_encode(['fixture_mail_arrival'=>[
            'status'=>$arrival['status'] ?? null,
            'reason'=>$arrival['reason'] ?? null,
            'model_calls'=>$arrival['model_calls'] ?? null,
            'callback_calls'=>$arrivalCalls,
        ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
    }
    recoveryCheck($arrival === ['status'=>'processed','reason'=>'arrival','model_calls'=>0] && $arrivalCalls === 0, 'fixture_mail_arrival_failed');

    $mail = recoveryPrivate($engine, 'mailById', ['unprofiled', $mail['message_id']]);
    $source = recoveryPrivate($engine, 'sourceForMail', [$mail, $actors]);
    $store->transaction(static fn() => recoveryPrivate($engine, 'exposeMail', [$mail, $source]));
}

function recoveryFixtureArrivedMail(
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
    recoveryObserve($engine, $ts, $gamets);
    $actors = recoveryPrivate($engine, 'npcMap', [recoveryPrivate($engine, 'npcRows')]);
    $mail = $store->transaction(static function () use ($engine, $actors, $senderId, $recipientId, $body, $gamets): array {
        $created = recoveryPrivate($engine, 'createMail', [
            'unprofiled', $actors[$senderId], $actors[$recipientId], $body, $gamets, null, 'letter',
        ]);
        return recoveryPrivate($engine, 'mailById', ['unprofiled', (string)$created['message_id']])
            ?? throw new RuntimeException('fixture_created_mail_missing');
    });

    $gamets = max($gamets + 1, (int)$mail['due_gamets'] + 100);
    $ts++;
    recoveryObserve($engine, $ts, $gamets);
    $arrival = $store->transaction(static function () use ($engine, $store, $mail, $gamets): array {
        $reference = recoveryPrivate($engine, 'appendAnchor', ['mail_arrived', [
            'message_id'=>$mail['message_id'],'sender_id'=>(string)$mail['sender_id'],
            'recipient_id'=>(string)$mail['recipient_id'],'gamets'=>(string)$gamets,
        ]]);
        $changed = $store->query("UPDATE plugins.correspondence_offscreen_mail SET status='arrived',arrived_gamets=$4,payload=payload||jsonb_build_object('arrival_anchor',$2::jsonb) WHERE playthrough_id=$1 AND message_id=$3 AND status='in_transit' RETURNING message_id::text", [
            'unprofiled',correspondenceJson($reference),$mail['message_id'],(string)$gamets,
        ]);
        return ['reference'=>$reference,'changed'=>count($changed)];
    });
    recoveryCheck($arrival['changed'] === 1, 'fixture_arrived_mail_transition_failed');
    $mail = recoveryPrivate($engine, 'mailById', ['unprofiled', $mail['message_id']]);
    recoveryCheck(recoveryPrivate($engine, 'sourceForMail', [$mail, $actors]) !== null, 'fixture_arrived_mail_must_have_valid_source_anchors');

    $ts++;
    $gamets += correspondenceAutonomyGamets(2);
    recoveryObserve($engine, $ts, $gamets);
    return recoveryPrivate($engine, 'mailById', ['unprofiled', $mail['message_id']]);
}

function recoverySeedTerminalReaction(
    CorrespondenceOffscreenPg $engine,
    CorrespondencePgStore $store,
    int $senderId,
    int $recipientId,
    string $body,
    int &$ts,
    int &$gamets,
    bool $retireSource
): array {
    $mail = recoveryFixtureArrivedMail($engine, $store, $senderId, $recipientId, $body, $ts, $gamets);
    $actors = recoveryPrivate($engine, 'npcMap', [recoveryPrivate($engine, 'npcRows')]);
    $source = recoveryPrivate($engine, 'sourceForMail', [$mail, $actors]);
    $self = $actors[(int)$mail['recipient_id']];
    $contacts = recoveryPrivate($engine, 'contacts', [$self, $actors]);
    $context = ['self'=>recoveryPrivate($engine, 'selfProjection', [$self, $contacts]), 'contacts'=>$contacts, 'source'=>$source];
    $observation = recoveryPrivate($engine, 'bindLatestObservation');
    $work = $store->transaction(static fn() => recoveryPrivate($engine, 'claim', [
        'unprofiled',$observation,$self,'react',$mail,null,$context,
    ]));
    recoveryCheck(($work['result_kind'] ?? null) === 'claimed', 'fixture_terminal_reaction_claim_failed');
    $terminal = $store->transaction(static fn() => recoveryPrivate($engine, 'terminalJob', [
        $work,'stale',null,'context_changed',null,true,
    ]));
    recoveryCheck(($terminal['status'] ?? null) === 'stale', 'fixture_terminal_reaction_completion_failed');
    if ($retireSource) {
        $restoreGeneration = recoveryPrivate($engine, 'restoreGeneration');
        $retired = $store->transaction(static fn() => recoveryPrivate($engine, 'retireUnexposedSource', [
            'unprofiled',$mail['message_id'],'reaction_abandoned',$restoreGeneration,$mail,
        ]));
        recoveryCheck($retired === true, 'fixture_old_terminal_source_retirement_failed');
    }
    return $mail;
}

function recoveryRecordFailure(array &$failures, bool $condition, string $reason): void
{
    if (!$condition) $failures[] = $reason;
}

function chimInteractionFile()
{
    $path = $GLOBALS['recovery_interaction_lock_path'] ?? null;
    $handle = is_string($path) ? @fopen($path, 'c+') : false;
    if (!is_resource($handle)) throw new RuntimeException('interaction_lock_fixture_unavailable');
    return $handle;
}

function chimInteractionRead($handle): array
{
    $path = $GLOBALS['recovery_interaction_lock_path'] ?? null;
    $probe = is_string($path) ? @fopen($path, 'c+') : false;
    if (!is_resource($probe)) throw new RuntimeException('interaction_lock_probe_unavailable');
    $acquired = @flock($probe, LOCK_EX | LOCK_NB);
    if ($acquired) @flock($probe, LOCK_UN);
    fclose($probe);
    return ['enabled'=>true, 'generation'=>(int)($GLOBALS['recovery_interaction_generation'] ?? 1)];
}

function chimInteractionBegin(): void
{
    if (!array_key_exists('chim_interaction_generation', $GLOBALS)) {
        $GLOBALS['chim_interaction_generation'] = (int)($GLOBALS['recovery_interaction_generation'] ?? 1);
    }
}

function chimInteractionState(): array
{
    return ['enabled'=>true, 'generation'=>(int)($GLOBALS['recovery_interaction_generation'] ?? 1)];
}

recoveryCheck(PHP_SAPI === 'cli', 'fixture_must_run_as_cli');
recoveryCheck(recoverySql("SELECT table_name FROM information_schema.tables WHERE table_schema IN ('public','plugins','chim_meta')") === [], 'fixture_database_must_begin_empty');

$ddl = <<<'SQL'
CREATE SCHEMA chim_meta;
CREATE TABLE chim_meta.playthrough_profiles(id bigint PRIMARY KEY,is_active boolean NOT NULL);
CREATE TABLE public.eventlog(rowid bigserial PRIMARY KEY,ts bigint NOT NULL,gamets numeric NOT NULL,type text NOT NULL,data text NOT NULL,sess text NOT NULL,localts bigint NOT NULL,people text NOT NULL,location text NOT NULL,party text NOT NULL);
CREATE TABLE public.core_npc_master(id bigint PRIMARY KEY,npc_name text NOT NULL,personality text NOT NULL,extended_data jsonb NOT NULL DEFAULT '{}'::jsonb,metadata jsonb NOT NULL DEFAULT '{}'::jsonb);
INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(100,100,'inputtext','synthetic recovery fixture seed','',100,'','','');
INSERT INTO public.core_npc_master(id,npc_name,personality,extended_data,metadata) VALUES
  (900001,'Camilla','Thoughtful and private.','{"background_life_enabled":true,"background_life_letters":true,"background_life_goals":"Ask Sigrid about the harvest.","relationships":{"Sigrid":{"aff":-31.5}}}','{"last_coords":"[0,0,0,\"00013488\"]"}'),
  (900002,'Sigrid','Practical and curious.','{"background_life_enabled":true,"background_life_letters":true}','{"last_coords":"[0,0,0,\"00013488\"]"}'),
  (900003,'Faendal','Quiet and observant.','{"background_life_enabled":true,"background_life_letters":true}','{"last_coords":"[0,0,0,\"00013488\"]"}');
SQL;
recoveryCheck(@pg_query($recoveryConnection, $ddl) !== false, 'fixture_schema_create_failed');

$lockPath = tempnam(sys_get_temp_dir(), 'correspondence-recovery-lock-');
recoveryCheck(is_string($lockPath), 'interaction_lock_fixture_unavailable');
$GLOBALS['recovery_interaction_lock_path'] = $lockPath;
$GLOBALS['recovery_interaction_generation'] = 17;
$GLOBALS['chim_interaction_generation'] = 17;
$GLOBALS['ptr_runtime_generation'] = 'runtime-generation-recovery-1';
register_shutdown_function(static function () use ($lockPath): void {
    if (is_file($lockPath)) @unlink($lockPath);
});

$store = new CorrespondencePgStore($recoveryConnection, static fn($s) => null, static fn($title, $body) => true);
$store->ensureSchema();
$engine = new CorrespondenceOffscreenPg($store);
$engine->ensureSchema();
recoverySql("INSERT INTO plugins.correspondence_autonomy_settings(playthrough_id,enabled) VALUES('unprofiled',TRUE)");

$ts = 101;
$gamets = 101;
recoveryObserve($engine, $ts, $gamets);
$actors = recoveryPrivate($engine, 'npcMap', [recoveryPrivate($engine, 'npcRows')]);
$strandedBody = 'Already-terminal dev12 reaction source must remain exact.';
$strandedMail = $store->transaction(static fn() => recoveryPrivate($engine, 'createMail', [
    'unprofiled', $actors[900001], $actors[900003], $strandedBody, $gamets, null, 'letter',
]));

$ts++;
$gamets = (int)$strandedMail['due_gamets'] + 100;
recoveryObserve($engine, $ts, $gamets);
$strandedArrival = $engine->tick(static function (): string { throw new RuntimeException('arrival_must_not_call_model'); });
recoveryCheck($strandedArrival['status'] === 'processed' && $strandedArrival['reason'] === 'arrival', 'fixture_stranded_source_arrival_failed');

$ts++;
$gamets += correspondenceAutonomyGamets(2);
recoveryObserve($engine, $ts, $gamets);
$strandedObservation = recoveryPrivate($engine, 'bindLatestObservation');
$strandedMail = recoveryPrivate($engine, 'mailById', ['unprofiled', $strandedMail['message_id']]);
$strandedSource = recoveryPrivate($engine, 'sourceForMail', [$strandedMail, $actors]);
$strandedSelf = $actors[(int)$strandedMail['recipient_id']];
$strandedContacts = recoveryPrivate($engine, 'contacts', [$strandedSelf, $actors]);
$strandedContext = ['self'=>recoveryPrivate($engine, 'selfProjection', [$strandedSelf, $strandedContacts]), 'contacts'=>$strandedContacts, 'source'=>$strandedSource];
$strandedWork = $store->transaction(static fn() => recoveryPrivate($engine, 'claim', ['unprofiled', $strandedObservation, $strandedSelf, 'react', $strandedMail, null, $strandedContext]));
recoveryCheck(($strandedWork['result_kind'] ?? null) === 'claimed', 'fixture_must_create_real_anchored_stranded_reaction_claim');
$strandedTerminal = $store->transaction(static fn() => recoveryPrivate($engine, 'terminalJob', [$strandedWork, 'failed', null, 'invalid_decision', null, true]));
recoveryCheck(($strandedTerminal['status'] ?? null) === 'failed' && ($strandedTerminal['reason'] ?? null) === 'invalid_decision', 'fixture_must_seed_existing_terminal_dev12_reaction_job');
$strandedJob = recoverySql("SELECT status,payload->>'reason_code' AS reason_code,payload->'claim_anchor'->>'rowid' AS claim_anchor,payload->'completion_anchor'->>'rowid' AS completion_anchor FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id='unprofiled' AND job_id=$1", [$strandedWork['job_id']])[0] ?? [];
$strandedBefore = recoverySql("SELECT status,body,source_event_id::text FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=$1", [$strandedMail['message_id']])[0] ?? [];
recoveryCheck(($strandedJob['status'] ?? null) === 'failed' && ($strandedJob['reason_code'] ?? null) === 'invalid_decision'
    && is_string($strandedJob['claim_anchor'] ?? null) && is_string($strandedJob['completion_anchor'] ?? null)
    && ($strandedBefore['status'] ?? null) === 'arrived' && ($strandedBefore['body'] ?? null) === $strandedBody
    && ($strandedBefore['source_event_id'] ?? null) === null, 'fixture_terminal_dev12_job_must_pair_with_exact_unexposed_arrived_source');

$ts++;
$gamets++;
recoveryObserve($engine, $ts, $gamets);
$strandedRecoveryCalls = 0;
$strandedRecovery = $engine->tick(static function () use (&$strandedRecoveryCalls): string { $strandedRecoveryCalls++; throw new RuntimeException('terminal_recovery_must_not_retry_model'); });
recoveryCheck($strandedRecoveryCalls === 0 && $strandedRecovery['model_calls'] === 0, 'existing_terminal_reaction_recovery_must_not_retry_model');
$strandedAfter = recoverySql("SELECT status,body,source_event_id::text,payload->>'reason_code' AS reason_code,payload->'stale_anchor'->>'rowid' AS stale_anchor FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=$1", [$strandedMail['message_id']])[0] ?? [];
recoveryCheck(($strandedAfter['status'] ?? null) === 'stale' && ($strandedAfter['reason_code'] ?? null) === 'reaction_abandoned'
    && is_string($strandedAfter['stale_anchor'] ?? null) && ($strandedAfter['body'] ?? null) === $strandedBody
    && ($strandedAfter['source_event_id'] ?? null) === null, 'existing_terminal_reaction_must_retire_only_exact_unexposed_source');
$strandedAnchor = recoverySql("SELECT data::jsonb->>'transition' AS transition,data::jsonb->>'message_id' AS message_id,data::jsonb->>'reason_code' AS reason_code FROM public.eventlog WHERE rowid=$1", [$strandedAfter['stale_anchor']])[0] ?? [];
recoveryCheck(($strandedAnchor['transition'] ?? null) === 'mail_stale'
    && ($strandedAnchor['message_id'] ?? null) === $strandedMail['message_id']
    && ($strandedAnchor['reason_code'] ?? null) === 'reaction_abandoned', 'existing_terminal_recovery_anchor_must_match_source');
recoveryCheck(recoverySql("SELECT rowid FROM public.eventlog WHERE sess='written_evidence_v1' AND data::jsonb->>'document_id'=$1", [$strandedMail['message_id']]) === []
    && $engine->pendingSources() === [], 'existing_terminal_recovery_must_not_create_false_exposure');

$legacyStaleBody = 'Legacy stale reaction without a completion anchor.';
$legacyStaleMail = $store->transaction(static fn() => recoveryPrivate($engine, 'createMail', [
    'unprofiled', $actors[900001], $actors[900002], $legacyStaleBody, $gamets, null, 'letter',
]));
$ts++;
$gamets = (int)$legacyStaleMail['due_gamets'] + 100;
recoveryObserve($engine, $ts, $gamets);
$legacyStaleArrival = $engine->tick(static function (): string { throw new RuntimeException('arrival_must_not_call_model'); });
recoveryCheck($legacyStaleArrival['reason'] === 'arrival', 'legacy_stale_fixture_arrival_failed');
$ts++;
$gamets += correspondenceAutonomyGamets(2);
recoveryObserve($engine, $ts, $gamets);
$legacyObservation = recoveryPrivate($engine, 'bindLatestObservation');
$legacyStaleMail = recoveryPrivate($engine, 'mailById', ['unprofiled', $legacyStaleMail['message_id']]);
$legacySource = recoveryPrivate($engine, 'sourceForMail', [$legacyStaleMail, $actors]);
$legacySelf = $actors[(int)$legacyStaleMail['recipient_id']];
$legacyContacts = recoveryPrivate($engine, 'contacts', [$legacySelf, $actors]);
$legacyContext = ['self'=>recoveryPrivate($engine, 'selfProjection', [$legacySelf, $legacyContacts]), 'contacts'=>$legacyContacts, 'source'=>$legacySource];
$legacyWork = $store->transaction(static fn() => recoveryPrivate($engine, 'claim', ['unprofiled', $legacyObservation, $legacySelf, 'react', $legacyStaleMail, null, $legacyContext]));
recoveryCheck(($legacyWork['result_kind'] ?? null) === 'claimed', 'legacy_stale_fixture_claim_failed');
$legacyTerminal = $store->transaction(static fn() => recoveryPrivate($engine, 'terminalJob', [$legacyWork, 'stale', null, 'context_changed', null, false]));
recoveryCheck(($legacyTerminal['status'] ?? null) === 'stale', 'legacy_stale_fixture_terminal_failed');
$legacyJob = recoverySql("SELECT status,payload->>'reason_code' AS reason_code,payload->'completion_anchor' AS completion_anchor FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id='unprofiled' AND job_id=$1", [$legacyWork['job_id']])[0] ?? [];
recoveryCheck(($legacyJob['status'] ?? null) === 'stale' && ($legacyJob['reason_code'] ?? null) === 'context_changed'
    && ($legacyJob['completion_anchor'] ?? null) === null, 'legacy_stale_fixture_must_have_no_completion_anchor');
$ts++;
$gamets++;
recoveryObserve($engine, $ts, $gamets);
$legacyRecoveryCalls = 0;
$legacyRecovery = $engine->tick(static function () use (&$legacyRecoveryCalls): string { $legacyRecoveryCalls++; throw new RuntimeException('legacy_stale_recovery_must_not_retry'); });
$legacyAfter = recoverySql("SELECT status,body,payload->>'reason_code' AS reason_code,payload->'stale_anchor'->>'rowid' AS stale_anchor FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=$1", [$legacyStaleMail['message_id']])[0] ?? [];
recoveryCheck($legacyRecoveryCalls === 0 && $legacyRecovery['model_calls'] === 0
    && ($legacyAfter['status'] ?? null) === 'stale' && ($legacyAfter['reason_code'] ?? null) === 'reaction_abandoned'
    && ($legacyAfter['body'] ?? null) === $legacyStaleBody && is_string($legacyAfter['stale_anchor'] ?? null)
    && recoverySql("SELECT rowid FROM public.eventlog WHERE sess='written_evidence_v1' AND data::jsonb->>'document_id'=$1", [$legacyStaleMail['message_id']]) === [],
    'legacy_stale_without_completion_anchor_must_retire_exact_source_without_exposure_or_retry');

$originalBody = 'Original full letter: meet me by the old bridge.';
$ts++;
$gamets += correspondenceAutonomyGamets(8);
recoveryObserve($engine, $ts, $gamets);
$afterStrandedRecovery = $engine->tick(static fn(array $messages): string => recoveryInitiation($originalBody));
recoveryCheck($afterStrandedRecovery['status'] === 'processed' && $afterStrandedRecovery['reason'] === 'initiated', 'terminal_recovery_must_allow_later_sender_initiation');
$root = recoverySql("SELECT message_id::text,body,due_gamets::text FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND sender_id=900001 AND depth=0 AND status='in_transit' AND body=$1 LIMIT 1", [$originalBody])[0] ?? [];
recoveryCheck(($root['body'] ?? null) === $originalBody, 'original_letter_changed_before_reaction');

$ts++;
$gamets = (int)$root['due_gamets'] + 100;
recoveryObserve($engine, $ts, $gamets);
$arrival = $engine->tick(static function (): string { throw new RuntimeException('arrival_must_not_call_model'); });
recoveryCheck($arrival['status'] === 'processed' && $arrival['reason'] === 'arrival' && $arrival['model_calls'] === 0, 'root_arrival_failed');

$ts++;
$gamets += correspondenceAutonomyGamets(2);
recoveryObserve($engine, $ts, $gamets);
$throwCalls = 0;
$failedReaction = $engine->tick(static function () use (&$throwCalls): string {
    $throwCalls++;
    throw new RuntimeException('synthetic_provider_throw');
});
recoveryCheck($failedReaction['status'] === 'failed' && $failedReaction['model_calls'] === 1 && $throwCalls === 1, 'model_throw_must_be_one_terminal_attempt');
$failedJob = recoverySql("SELECT status,payload->>'reason_code' AS reason_code,payload::text FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id='unprofiled' AND source_message_id=$1", [$root['message_id']])[0] ?? [];
recoveryCheck(($failedJob['status'] ?? null) === 'failed' && ($failedJob['reason_code'] ?? null) === 'model_error'
    && !str_contains((string)($failedJob['payload'] ?? ''), 'synthetic_provider_throw')
    && recoverySql('SELECT rowid FROM public.eventlog WHERE data LIKE $1', ['%synthetic_provider_throw%']) === [],
    'model_throw_must_use_fixed_reason_without_raw_exception_text');

$failedSource = recoverySql("SELECT status,body,source_event_id::text,payload->>'reason_code' AS reason_code,payload->'stale_anchor'->>'rowid' AS stale_anchor FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=$1", [$root['message_id']])[0] ?? [];
recoveryCheck(($failedSource['status'] ?? null) === 'stale', 'model_throw_must_retire_unexposed_arrived_mail');
recoveryCheck(($failedSource['reason_code'] ?? null) === 'reaction_abandoned'
    && is_string($failedSource['stale_anchor'] ?? null)
    && ($failedSource['body'] ?? null) === $originalBody
    && ($failedSource['source_event_id'] ?? null) === null, 'model_throw_retirement_must_keep_fixed_reason_and_exact_original');
$failedAnchor = recoverySql("SELECT data::jsonb->>'transition' AS transition,data::jsonb->>'message_id' AS message_id,data::jsonb->>'reason_code' AS reason_code FROM public.eventlog WHERE rowid=$1", [$failedSource['stale_anchor']])[0] ?? [];
recoveryCheck(($failedAnchor['transition'] ?? null) === 'mail_stale'
    && ($failedAnchor['message_id'] ?? null) === $root['message_id']
    && ($failedAnchor['reason_code'] ?? null) === 'reaction_abandoned', 'model_throw_retirement_anchor_must_match_source');
recoveryCheck(recoverySql("SELECT rowid FROM public.eventlog WHERE sess='written_evidence_v1' AND data::jsonb->>'document_id'=$1", [$root['message_id']]) === []
    && $engine->pendingSources() === [], 'model_throw_must_never_publish_false_exposure');

$ts++;
$gamets += correspondenceAutonomyGamets(8);
recoveryObserve($engine, $ts, $gamets);
$laterInitiation = $engine->tick(static fn(array $messages): string => recoveryInitiation('A fresh letter after the abandoned reaction.'));
recoveryCheck($laterInitiation['status'] === 'processed' && $laterInitiation['reason'] === 'initiated', 'retired_source_must_allow_sender_to_initiate_again');
$secondRoot = recoverySql("SELECT message_id::text,due_gamets::text FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND sender_id=900001 AND depth=0 AND status='in_transit' AND body=$1 LIMIT 1", ['A fresh letter after the abandoned reaction.'])[0] ?? [];

$ts++;
$gamets = (int)$secondRoot['due_gamets'] + 100;
recoveryObserve($engine, $ts, $gamets);
$secondArrival = $engine->tick(static function (): string { throw new RuntimeException('arrival_must_not_call_model'); });
recoveryCheck($secondArrival['status'] === 'processed' && $secondArrival['reason'] === 'arrival', 'second_root_arrival_failed');

$ts++;
$gamets += correspondenceAutonomyGamets(2);
recoveryObserve($engine, $ts, $gamets);
$observation = recoveryPrivate($engine, 'bindLatestObservation');
$actors = recoveryPrivate($engine, 'npcMap', [recoveryPrivate($engine, 'npcRows')]);
$secondMail = recoveryPrivate($engine, 'mailById', ['unprofiled', $secondRoot['message_id']]);
$source = recoveryPrivate($engine, 'sourceForMail', [$secondMail, $actors]);
$self = $actors[(int)$secondMail['recipient_id']];
$contacts = recoveryPrivate($engine, 'contacts', [$self, $actors]);
$context = ['self'=>recoveryPrivate($engine, 'selfProjection', [$self, $contacts]), 'contacts'=>$contacts, 'source'=>$source];
$abandoned = $store->transaction(static fn() => recoveryPrivate($engine, 'claim', ['unprofiled', $observation, $self, 'react', $secondMail, null, $context]));
recoveryCheck(($abandoned['result_kind'] ?? null) === 'claimed', 'fixture_must_create_real_anchored_reaction_claim');
recoveryCheck(count(recoverySql("UPDATE plugins.correspondence_offscreen_jobs SET created_at=clock_timestamp()-INTERVAL '10 minutes' WHERE playthrough_id='unprofiled' AND job_id=$1 AND claim_id=$2 RETURNING job_id", [$abandoned['job_id'], $abandoned['claim_id']])) === 1, 'fixture_claim_age_failed');

$ts++;
$gamets++;
recoveryObserve($engine, $ts, $gamets);
$expiryCalls = 0;
$expiry = $engine->tick(static function () use (&$expiryCalls): string { $expiryCalls++; throw new RuntimeException('expired_claim_must_not_retry'); });
recoveryCheck($expiryCalls === 0 && $expiry['model_calls'] === 0 && ($expiry['reason'] ?? null) === 'claim_timeout', 'expired_claim_must_not_retry_provider');
$expiredJob = recoverySql("SELECT job_id::text,claim_id::text,status,payload->>'attempt' AS attempt,payload->>'reconsiderable' AS reconsiderable,payload->>'reason_code' AS reason_code,payload->'abandoned_anchor'->>'rowid' AS abandoned_anchor FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id='unprofiled' AND job_id=$1", [$abandoned['job_id']])[0] ?? [];
$expiredSource = recoverySql("SELECT status,body,source_event_id::text,payload->>'reason_code' AS reason_code,payload->'stale_anchor'->>'rowid' AS stale_anchor FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=$1", [$secondRoot['message_id']])[0] ?? [];
recoveryCheck(($expiredJob['status'] ?? null) === 'stale' && ($expiredJob['reason_code'] ?? null) === 'claim_timeout'
    && (string)($expiredJob['attempt'] ?? '') === '0' && ($expiredJob['reconsiderable'] ?? null) === 'true'
    && is_string($expiredJob['abandoned_anchor'] ?? null), 'expired_claim_must_get_one_anchored_terminal_state');
recoveryCheck(($expiredSource['status'] ?? null) === 'arrived' && ($expiredSource['stale_anchor'] ?? null) === null
    && ($expiredSource['source_event_id'] ?? null) === null
    && ($expiredSource['body'] ?? null) === 'A fresh letter after the abandoned reaction.', 'first_expired_claim_must_preserve_exact_unexposed_source_for_one_retry');
$expiredAnchor = recoverySql("SELECT data::jsonb->>'transition' AS transition,data::jsonb->>'job_id' AS job_id,data::jsonb->>'claim_id' AS claim_id,data::jsonb->>'attempt' AS attempt,data::jsonb->>'reconsiderable' AS reconsiderable,data::jsonb->>'reason_code' AS reason_code FROM public.eventlog WHERE rowid=$1", [$expiredJob['abandoned_anchor']])[0] ?? [];
recoveryCheck(($expiredAnchor['transition'] ?? null) === 'job_abandoned'
    && ($expiredAnchor['job_id'] ?? null) === $abandoned['job_id']
    && ($expiredAnchor['claim_id'] ?? null) === $abandoned['claim_id']
    && ($expiredAnchor['attempt'] ?? null) === '0' && ($expiredAnchor['reconsiderable'] ?? null) === 'true'
    && ($expiredAnchor['reason_code'] ?? null) === 'claim_timeout', 'expired_claim_reconsideration_anchor_must_match_source_claim');
recoveryCheck(recoverySql("SELECT rowid FROM public.eventlog WHERE sess='written_evidence_v1' AND data::jsonb->>'document_id'=$1", [$secondRoot['message_id']]) === []
    && $engine->pendingSources() === [], 'expired_claim_must_never_publish_false_exposure');

$ts++;
$gamets += correspondenceAutonomyGamets(8);
recoveryObserve($engine, $ts, $gamets);
$secondTimeoutCalls = 0;
$afterTimeout = $engine->tick(static function () use (&$secondTimeoutCalls, &$ts, &$gamets, $engine): string {
    $secondTimeoutCalls++;
    $ts++;
    $gamets++;
    recoveryObserve($engine, $ts, $gamets);
    return json_encode(['action'=>'reply','recipient'=>'npc:900001','body'=>'The second stale output must not expose this letter.','reason'=>'I want to answer.'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});
$secondTimeoutJob = recoverySql("SELECT job_id::text,claim_id::text,status,payload->>'attempt' AS attempt,payload->>'reconsiderable' AS reconsiderable,payload->'completion_anchor'->>'rowid' AS completion_anchor,payload->>'reason_code' AS reason_code FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id='unprofiled' AND source_message_id=$1", [$secondRoot['message_id']])[0] ?? [];
$secondTimeoutAnchor = recoverySql("SELECT data::jsonb->>'attempt' AS attempt,data::jsonb->>'reconsiderable' AS reconsiderable,data::jsonb->>'reason_code' AS reason_code FROM public.eventlog WHERE rowid=$1", [$secondTimeoutJob['completion_anchor'] ?? ''])[0] ?? [];
$secondTimeoutSource = recoverySql("SELECT status,body,source_event_id::text FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=$1", [$secondRoot['message_id']])[0] ?? [];
$secondTimeoutChildren = recoverySql("SELECT count(*)::int AS amount FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND payload->>'parent_message_id'=$1", [$secondRoot['message_id']])[0]['amount'] ?? 0;
recoveryCheck(($afterTimeout['status'] ?? null) === 'stale' && ($afterTimeout['reason'] ?? null) === 'observation_changed'
    && $secondTimeoutCalls === 1 && ($secondTimeoutJob['job_id'] ?? null) === $abandoned['job_id']
    && ($secondTimeoutJob['claim_id'] ?? null) !== $abandoned['claim_id']
    && ($secondTimeoutJob['status'] ?? null) === 'stale' && (string)($secondTimeoutJob['attempt'] ?? '') === '1'
    && ($secondTimeoutJob['reconsiderable'] ?? null) !== 'true' && ($secondTimeoutJob['reason_code'] ?? null) === 'observation_changed'
    && (string)($secondTimeoutAnchor['attempt'] ?? '') === '1' && ($secondTimeoutAnchor['reconsiderable'] ?? null) !== 'true'
    && ($secondTimeoutAnchor['reason_code'] ?? null) === 'observation_changed'
    && ($secondTimeoutSource['status'] ?? null) === 'arrived' && ($secondTimeoutSource['body'] ?? null) === 'A fresh letter after the abandoned reaction.'
    && ($secondTimeoutSource['source_event_id'] ?? null) === null && (int)$secondTimeoutChildren === 0,
    'claim_timeout_must_reuse_one_job_for_only_one_stale_reconsideration');

$ts++;
$gamets++;
recoveryObserve($engine, $ts, $gamets);
$thirdTimeoutCalls = 0;
$afterSecondFailure = $engine->tick(static function () use (&$thirdTimeoutCalls): string {
    $thirdTimeoutCalls++;
    throw new RuntimeException('second_stale_claim_must_not_retry');
});
$retiredTimeoutSource = recoverySql("SELECT status,body,source_event_id::text,payload->>'reason_code' AS reason_code,payload->'stale_anchor'->>'rowid' AS stale_anchor FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=$1", [$secondRoot['message_id']])[0] ?? [];
$retiredTimeoutChildren = recoverySql("SELECT count(*)::int AS amount FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND payload->>'parent_message_id'=$1", [$secondRoot['message_id']])[0]['amount'] ?? 0;
recoveryCheck(($afterSecondFailure['status'] ?? null) === 'processed' && ($afterSecondFailure['reason'] ?? null) === 'reaction_abandoned'
    && $thirdTimeoutCalls === 0 && ($retiredTimeoutSource['status'] ?? null) === 'stale'
    && ($retiredTimeoutSource['reason_code'] ?? null) === 'reaction_abandoned'
    && ($retiredTimeoutSource['body'] ?? null) === 'A fresh letter after the abandoned reaction.'
    && ($retiredTimeoutSource['source_event_id'] ?? null) === null && is_string($retiredTimeoutSource['stale_anchor'] ?? null)
    && (int)$retiredTimeoutChildren === 0
    && recoverySql("SELECT rowid FROM public.eventlog WHERE sess='written_evidence_v1' AND data::jsonb->>'document_id'=$1", [$secondRoot['message_id']]) === [],
    'second_stale_reconsideration_must_retire_once_without_model_or_social_effects');

$ts++;
$gamets += correspondenceAutonomyGamets(8);
recoveryObserve($engine, $ts, $gamets);
$afterTimeout = $engine->tick(static fn(array $messages): string => recoveryInitiation('A fresh letter after claim timeout.'));
recoveryCheck($afterTimeout['status'] === 'processed' && $afterTimeout['reason'] === 'initiated', 'retired_timeout_source_must_allow_later_sender_initiation');

$thirdRoot = recoverySql("SELECT message_id::text,due_gamets::text FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND sender_id=900001 AND depth=0 AND status='in_transit' AND body=$1 LIMIT 1", ['A fresh letter after claim timeout.'])[0] ?? [];
$ts++;
$gamets = (int)$thirdRoot['due_gamets'] + 100;
recoveryObserve($engine, $ts, $gamets);
$thirdArrival = $engine->tick(static function (): string { throw new RuntimeException('arrival_must_not_call_model'); });
recoveryCheck($thirdArrival['status'] === 'processed' && $thirdArrival['reason'] === 'arrival', 'third_root_arrival_failed');

$ts++;
$gamets += correspondenceAutonomyGamets(2);
recoveryObserve($engine, $ts, $gamets);
$restoreCalls = 0;
$restoreResult = $engine->tick(static function () use (&$restoreCalls): string {
    $restoreCalls++;
    $GLOBALS['ptr_runtime_generation'] = 'runtime-generation-after-restore';
    return json_encode(['action'=>'reply','recipient'=>'npc:900001','body'=>'A stale response must not expose this letter.','reason'=>'I want to answer.'], JSON_THROW_ON_ERROR);
});
recoveryCheck($restoreResult['status'] === 'stale' && $restoreResult['model_calls'] === 1 && $restoreCalls === 1, 'restore_changed_model_result_must_be_terminal');
$restoredSource = recoverySql("SELECT status,body,source_event_id::text FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=$1", [$thirdRoot['message_id']])[0] ?? [];
recoveryCheck(($restoredSource['status'] ?? null) === 'arrived'
    && ($restoredSource['body'] ?? null) === 'A fresh letter after claim timeout.'
    && ($restoredSource['source_event_id'] ?? null) === null
    && recoverySql("SELECT rowid FROM public.eventlog WHERE sess='written_evidence_v1' AND data::jsonb->>'document_id'=$1", [$thirdRoot['message_id']]) === [],
    'restore_changed_result_must_not_expose_or_mutate_source');

$ts++;
$gamets++;
recoveryObserve($engine, $ts, $gamets);
$restoreCleanupCalls = 0;
$restoreCleanup = $engine->tick(static function () use (&$restoreCleanupCalls): string {
    $restoreCleanupCalls++;
    throw new RuntimeException('restore_changed_terminal_cleanup_must_not_call_model');
});
$restoreTerminalJob = recoverySql("SELECT status,payload->>'reason_code' AS reason_code,payload->>'reconsiderable' AS reconsiderable FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id='unprofiled' AND source_message_id=$1", [$thirdRoot['message_id']])[0] ?? [];
$restoreRetiredSource = recoverySql("SELECT status,body,source_event_id::text,payload->>'reason_code' AS reason_code,payload->'stale_anchor'->>'rowid' AS stale_anchor FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=$1", [$thirdRoot['message_id']])[0] ?? [];
$restoreRetiredAnchor = recoverySql("SELECT data::jsonb->>'transition' AS transition,data::jsonb->>'message_id' AS message_id,data::jsonb->>'reason_code' AS reason_code FROM public.eventlog WHERE rowid::text=$1", [$restoreRetiredSource['stale_anchor'] ?? ''])[0] ?? [];
recoveryCheck($restoreCleanup === ['status'=>'processed','reason'=>'reaction_abandoned','model_calls'=>0] && $restoreCleanupCalls === 0,
    'restore_changed_terminal_cleanup_must_take_one_metadata_only_tick');
recoveryCheck(($restoreTerminalJob['status'] ?? null) === 'stale' && ($restoreTerminalJob['reason_code'] ?? null) === 'restore_changed'
    && ($restoreTerminalJob['reconsiderable'] ?? null) !== 'true'
    && ($restoreRetiredSource['status'] ?? null) === 'stale'
    && ($restoreRetiredSource['body'] ?? null) === 'A fresh letter after claim timeout.'
    && ($restoreRetiredSource['source_event_id'] ?? null) === null
    && ($restoreRetiredSource['reason_code'] ?? null) === 'restore_changed'
    && is_string($restoreRetiredSource['stale_anchor'] ?? null)
    && ($restoreRetiredAnchor['transition'] ?? null) === 'mail_stale'
    && ($restoreRetiredAnchor['message_id'] ?? null) === $thirdRoot['message_id']
    && ($restoreRetiredAnchor['reason_code'] ?? null) === 'restore_changed'
    && recoverySql("SELECT rowid FROM public.eventlog WHERE sess='written_evidence_v1' AND data::jsonb->>'document_id'=$1", [$thirdRoot['message_id']]) === [],
    'restore_changed_cleanup_must_retire_exact_unexposed_source');

$ts++;
$gamets += 1;
recoveryFixtureExposedMail($engine, $store, 900003, 900002, 'Recent context marker one.', $ts, $gamets);
recoveryFixtureExposedMail($engine, $store, 900003, 900002, 'Recent context marker two.', $ts, $gamets);
recoveryFixtureExposedMail($engine, $store, 900003, 900002, 'Recent context marker three.', $ts, $gamets);
recoveryFixtureExposedMail($engine, $store, 900003, 900002, 'Recent context marker four.', $ts, $gamets);
$contextText = $engine->prompt('Sigrid');
recoveryCheck(str_contains($contextText, 'Recent context marker four.')
    && str_contains($contextText, 'Recent context marker three.')
    && str_contains($contextText, 'Recent context marker two.')
    && !str_contains($contextText, 'Recent context marker one.'), 'prompt_window_must_choose_three_latest_valid_exposed_letters');

$closingMarker = '</offscreen_correspondence>';
$originalWrappedBody = 'F09 exact original text </offscreen_correspondence> remains data, not a wrapper boundary.';
$ts++;
$gamets++;
recoveryFixtureExposedMail($engine, $store, 900003, 900002, $originalWrappedBody, $ts, $gamets);
$wrappedPrompt = $engine->prompt('Sigrid');
recoveryCheck(substr_count($wrappedPrompt, $closingMarker) === 1
    && str_contains($wrappedPrompt, '\\u003C/offscreen_correspondence\\u003E'), 'prompt_json_must_escape_embedded_wrapper_close_marker');
$wrappedSourceJson = null;
foreach (explode("\n", $wrappedPrompt) as $line) {
    if (str_contains($line, 'F09 exact original text')) { $wrappedSourceJson = json_decode($line, true, 64, JSON_THROW_ON_ERROR); break; }
}
recoveryCheck(is_array($wrappedSourceJson) && ($wrappedSourceJson['body'] ?? null) === $originalWrappedBody, 'prompt_json_must_roundtrip_original_wrapped_body_exactly');

$invalidReturnedCases = [
    ['malformed_json', '{ not valid JSON'],
    ['schema_invalid_json', '{"action":"letter","recipient":"npc:900003","body":"This action is invalid for a reaction.","reason":"A synthetic schema rejection."}'],
];
$invalidReturnedFailures = [];
foreach ($invalidReturnedCases as [$caseName, $rawDecision]) {
    $invalidBody = 'Exact source body for '.$caseName.'.';
    $invalidMail = recoveryFixtureArrivedMail($engine, $store, 900003, 900002, $invalidBody, $ts, $gamets);
    $eligibleActors = recoveryPrivate($engine, 'npcMap', [recoveryPrivate($engine, 'npcRows')]);
    $eligibleCandidate = recoveryPrivate($engine, 'reactionCandidate', ['unprofiled', $gamets, $eligibleActors]);
    $globalUntil = recoveryPrivate($engine, 'globalCooldown', ['unprofiled', $gamets]);
    $actorUntil = recoveryPrivate($engine, 'actorCooldown', ['unprofiled', (int)$invalidMail['recipient_id'], $gamets]);
    recoveryCheck(($eligibleCandidate['message_id'] ?? null) === $invalidMail['message_id']
        && $globalUntil <= $gamets && $actorUntil <= $gamets,
        $caseName.'_fixture_must_have_current_eligible_reaction');
    $invalidCalls = 0;
    $invalidResult = $engine->tick(static function () use (&$invalidCalls, $rawDecision): string {
        $invalidCalls++;
        return $rawDecision;
    });
    $invalidJob = recoverySql("SELECT status,action,payload->>'reason_code' AS reason_code FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id='unprofiled' AND source_message_id=$1 ORDER BY created_at DESC LIMIT 1", [$invalidMail['message_id']])[0] ?? [];
    $invalidSource = recoverySql("SELECT status,body,source_event_id::text,payload->>'reason_code' AS reason_code,payload->'stale_anchor'->>'rowid' AS stale_anchor FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=$1", [$invalidMail['message_id']])[0] ?? [];
    $invalidPending = $engine->pendingSources();
    $consumerCalls = 0;
    $workerResult = correspondenceOffscreenWorkerFinalizeTick([
        'status'=>$invalidResult['status'],'reason'=>$invalidResult['reason'],'model_calls'=>$invalidResult['model_calls'],
    ], $invalidPending, static function (array $eventIds) use (&$consumerCalls): array {
        $consumerCalls++;
        return ['status'=>'processed','sources'=>[]];
    });
    $invalidExposure = recoverySql("SELECT rowid FROM public.eventlog WHERE sess='written_evidence_v1' AND data::jsonb->>'document_id'=$1", [$invalidMail['message_id']]);
    recoveryRecordFailure($invalidReturnedFailures,
        $invalidCalls === 1 && $invalidResult['status'] === 'failed' && $invalidResult['reason'] === 'invalid_decision'
            && $invalidResult['model_calls'] === 1 && ($invalidJob['status'] ?? null) === 'failed'
            && array_key_exists('action', $invalidJob) && $invalidJob['action'] === null
            && ($invalidJob['reason_code'] ?? null) === 'invalid_decision',
        $caseName.'_must_commit_one_failed_invalid_decision');
    recoveryRecordFailure($invalidReturnedFailures,
        ($invalidSource['status'] ?? null) === 'stale' && ($invalidSource['reason_code'] ?? null) === 'reaction_abandoned'
            && is_string($invalidSource['stale_anchor'] ?? null) && ($invalidSource['body'] ?? null) === $invalidBody
            && ($invalidSource['source_event_id'] ?? null) === null,
        $caseName.'_must_retire_exact_source_without_exposure');
    recoveryRecordFailure($invalidReturnedFailures,
        $invalidExposure === [] && $invalidPending === [] && $consumerCalls === 0 && ($workerResult['status'] ?? null) === 'failed',
        $caseName.'_must_not_publish_to_social_consumers');
}

$retiredTerminalMailIds = [];
for ($i = 1; $i <= 16; $i++) {
    $oldMail = recoverySeedTerminalReaction(
        $engine,$store,900003,900002,'Previously retired terminal source '.$i.'.',$ts,$gamets,true,
    );
    $retiredTerminalMailIds[] = $oldMail['message_id'];
}
$laterTerminalMail = recoverySeedTerminalReaction(
    $engine,$store,900003,900002,'Later arrived terminal source beyond the recovery window.',$ts,$gamets,false,
);
$laterBeforeRecovery = recoverySql("SELECT status,body,source_event_id::text FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=$1", [$laterTerminalMail['message_id']])[0] ?? [];
recoveryCheck(count($retiredTerminalMailIds) === 16
    && ($laterBeforeRecovery['status'] ?? null) === 'arrived'
    && ($laterBeforeRecovery['body'] ?? null) === 'Later arrived terminal source beyond the recovery window.'
    && ($laterBeforeRecovery['source_event_id'] ?? null) === null,
    'fixture_must_place_later_arrived_terminal_source_after_sixteen_retired_terminal_sources');
$ts++;
$gamets++;
recoveryObserve($engine, $ts, $gamets);
$starvationCalls = 0;
$starvationTick = $engine->tick(static function () use (&$starvationCalls): string {
    $starvationCalls++;
    throw new RuntimeException('terminal_recovery_must_not_retry_model');
});
$laterAfterRecovery = recoverySql("SELECT status,body,source_event_id::text,payload->>'reason_code' AS reason_code,payload->'stale_anchor'->>'rowid' AS stale_anchor FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=$1", [$laterTerminalMail['message_id']])[0] ?? [];
$starvationExposure = recoverySql("SELECT rowid FROM public.eventlog WHERE sess='written_evidence_v1' AND data::jsonb->>'document_id'=$1", [$laterTerminalMail['message_id']]);
$starvationFailures = [];
recoveryRecordFailure($starvationFailures, $starvationCalls === 0 && $starvationTick['model_calls'] === 0
    && ($laterAfterRecovery['status'] ?? null) === 'stale'
    && ($laterAfterRecovery['reason_code'] ?? null) === 'reaction_abandoned'
    && is_string($laterAfterRecovery['stale_anchor'] ?? null)
    && ($laterAfterRecovery['body'] ?? null) === 'Later arrived terminal source beyond the recovery window.'
    && ($laterAfterRecovery['source_event_id'] ?? null) === null
    && $starvationExposure === [] && $engine->pendingSources() === [],
    'terminal_recovery_must_reach_later_arrived_source_without_retry_or_exposure');

recoveryCheck($invalidReturnedFailures === [] && $starvationFailures === [],
    'offscreen_recovery_regressions_failed:'.implode(',', array_merge($invalidReturnedFailures, $starvationFailures)));

$identityMail = recoveryFixtureArrivedMail($engine, $store, 900003, 900002, 'Source must not expose under a renamed actor.', $ts, $gamets);
$identityActors = recoveryPrivate($engine, 'npcMap', [recoveryPrivate($engine, 'npcRows')]);
$identitySource = recoveryPrivate($engine, 'sourceForMail', [$identityMail, $identityActors]);
$identitySelf = $identityActors[(int)$identityMail['recipient_id']];
$identityContacts = recoveryPrivate($engine, 'contacts', [$identitySelf, $identityActors]);
recoveryCheck($identityContacts !== [], 'identity_lock_fixture_needs_a_reply_target');
$identityObservation = recoveryPrivate($engine, 'bindLatestObservation');
recoveryCheck(recoveryPrivate($engine, 'globalCooldown', ['unprofiled', $gamets]) <= $gamets
    && recoveryPrivate($engine, 'actorCooldown', ['unprofiled', (int)$identitySelf['id'], $gamets]) <= $gamets,
    'identity_lock_fixture_cooldowns_must_be_expired');
$identityContext = [
    'self'=>recoveryPrivate($engine, 'selfProjection', [$identitySelf, $identityContacts]),
    'contacts'=>$identityContacts,'source'=>$identitySource,
];
$identityWork = $store->transaction(static fn() => recoveryPrivate($engine, 'claim', [
    'unprofiled',$identityObservation,$identitySelf,'react',$identityMail,null,$identityContext,
]));
recoveryCheck(($identityWork['result_kind'] ?? null) === 'claimed', 'identity_lock_fixture_claim_failed');
$identityPeer = correspondenceRecoveryFixture('chim_corr_recovery_offscreen');
$identityOriginalName = (string)$identitySelf['name'];
$identityRenamedName = 'Selected actor identity changed';
$identityFinish = null;
try {
    $renameResult = @pg_query_params($identityPeer, 'UPDATE public.core_npc_master SET npc_name=$2 WHERE id=$1 RETURNING npc_name', ['900002',$identityRenamedName]);
    if ($renameResult === false) throw new RuntimeException('identity_lock_fixture_peer_rename_failed');
    $renamed = pg_fetch_assoc($renameResult) ?: [];
    pg_free_result($renameResult);
    if (($renamed['npc_name'] ?? null) !== $identityRenamedName) throw new RuntimeException('identity_lock_fixture_peer_rename_missing');
    $identityFinish = recoveryPrivate($engine, 'finishWork', [
        $identityWork,'reply','A reply must not expose a source after its recipient is renamed.',$identityContacts[0]['key'],true,'sent',68,
    ]);
    $identityAfter = recoverySql("SELECT status,body,source_event_id::text FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=$1", [$identityMail['message_id']])[0] ?? [];
    $identityJob = recoverySql("SELECT status,payload->>'reason_code' AS reason_code FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id='unprofiled' AND job_id=$1", [$identityWork['job_id']])[0] ?? [];
    $identityExposure = recoverySql("SELECT rowid FROM public.eventlog WHERE sess='written_evidence_v1' AND data::jsonb->>'document_id'=$1", [$identityMail['message_id']]);
    recoveryCheck(($identityFinish['status'] ?? null) === 'stale' && ($identityFinish['reason'] ?? null) === 'source_changed'
        && ($identityJob['status'] ?? null) === 'stale' && ($identityJob['reason_code'] ?? null) === 'source_changed'
        && ($identityAfter['status'] ?? null) === 'arrived'
        && ($identityAfter['body'] ?? null) === 'Source must not expose under a renamed actor.'
        && ($identityAfter['source_event_id'] ?? null) === null && $identityExposure === [],
        'writer_actor_rename_must_reject_source_without_exposure');
} finally {
    $restoreName = @pg_query_params($identityPeer, 'UPDATE public.core_npc_master SET npc_name=$2 WHERE id=$1', ['900002',$identityOriginalName]);
    if ($restoreName !== false) pg_free_result($restoreName);
    pg_close($identityPeer);
}

$fairScanFailures = [];
$savedSenderGoal = recoverySql("SELECT extended_data::text AS value FROM public.core_npc_master WHERE id=900001")[0]['value'] ?? null;
$savedBlockedSenderGoal = recoverySql("SELECT extended_data::text AS value FROM public.core_npc_master WHERE id=900003")[0]['value'] ?? null;
recoveryCheck(is_string($savedSenderGoal) && is_string($savedBlockedSenderGoal), 'fair_scan_fixture_must_read_original_actor_profiles');
try {
$goalSwap = recoverySql("UPDATE public.core_npc_master SET extended_data=extended_data-'background_life_goals' WHERE id=900001 RETURNING id::text");
$senderGoal = 'Ask Sigrid about the sealed harvest ledger.';
$blockedSenderGoal = recoverySql("UPDATE public.core_npc_master SET extended_data=jsonb_set(extended_data,'{background_life_goals}',to_jsonb($1::text),TRUE) WHERE id=900003 RETURNING id::text", [$senderGoal]);
recoveryCheck(count($goalSwap) === 1 && count($blockedSenderGoal) === 1, 'fair_scan_fixture_must_isolate_blocked_sender_goal');

$readFairState = static function (array $entry): array {
    $job = recoverySql("SELECT status,action,claim_id::text,payload::text FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id='unprofiled' AND job_id=$1", [$entry['job_id']])[0] ?? [];
    $mail = recoverySql("SELECT status,body,source_event_id::text,source_event_sha256,payload::text FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=$1", [$entry['message_id']])[0] ?? [];
    $jobPayload = json_decode((string)($job['payload'] ?? ''), true, 64, JSON_THROW_ON_ERROR);
    $mailPayload = json_decode((string)($mail['payload'] ?? ''), true, 64, JSON_THROW_ON_ERROR);
    $marker = $jobPayload['recovery_checked_at'] ?? null;
    unset($jobPayload['recovery_checked_at']);
    $completionExists = $entry['completion_anchor_id'] === ''
        ? false
        : recoverySql("SELECT rowid::text FROM public.eventlog WHERE rowid=$1 AND type='info' AND sess='correspondence_v1'", [$entry['completion_anchor_id']]) !== [];
    return [
        'job'=>[
            'status'=>$job['status'] ?? null,'action'=>$job['action'] ?? null,'claim_id'=>$job['claim_id'] ?? null,
            'payload'=>correspondenceCanonicalJson($jobPayload),'recovery_checked_at'=>$marker,
        ],
        'mail'=>[
            'status'=>$mail['status'] ?? null,'body'=>$mail['body'] ?? null,
            'source_event_id'=>$mail['source_event_id'] ?? null,'source_event_sha256'=>$mail['source_event_sha256'] ?? null,
            'payload'=>correspondenceCanonicalJson($mailPayload),
        ],
        'completion_anchor_exists'=>$completionExists,
        'exposures'=>recoverySql("SELECT rowid::text FROM public.eventlog WHERE sess='written_evidence_v1' AND data::jsonb->>'document_id'=$1", [$entry['message_id']]),
        'stale_receipts'=>recoverySql("SELECT rowid::text FROM public.eventlog WHERE sess='correspondence_v1' AND data::jsonb->>'transition'='mail_stale' AND data::jsonb->>'message_id'=$1", [$entry['message_id']]),
        'child_count'=>recoverySql("SELECT count(*)::text AS count FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND depth>0 AND root_message_id=$1", [$entry['message_id']])[0]['count'] ?? null,
        'job_count'=>recoverySql("SELECT count(*)::text AS count FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id='unprofiled' AND source_message_id=$1", [$entry['message_id']])[0]['count'] ?? null,
    ];
};

$invalidProofRows = [];
for ($i = 0; $i < 16; $i++) {
    $mail = recoverySeedTerminalReaction(
        $engine,$store,900003,900002,'Terminal proof blocker '.($i + 1).'.',$ts,$gamets,false,
    );
    $job = recoverySql("SELECT job_id::text,payload::text FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id='unprofiled' AND source_message_id=$1 AND status IN ('failed','stale') ORDER BY created_at DESC LIMIT 1", [$mail['message_id']])[0] ?? [];
    $payload = json_decode((string)($job['payload'] ?? ''), true, 64, JSON_THROW_ON_ERROR);
    $completionAnchorId = (string)($payload['completion_anchor']['rowid'] ?? '');
    recoveryCheck(($job['job_id'] ?? null) !== null && (string)($payload['kind'] ?? '') === 'react'
        && ($payload['source_message_id'] ?? null) === $mail['message_id']
        && is_string($payload['claim_anchor']['rowid'] ?? null), 'fair_scan_fixture_must_seed_real_terminal_reaction_claims');
    if ($i < 8) {
        $removed = recoverySql("DELETE FROM public.eventlog WHERE rowid=$1 AND type='info' AND sess='correspondence_v1' RETURNING rowid::text", [$completionAnchorId]);
        recoveryCheck($completionAnchorId !== '' && count($removed) === 1, 'fair_scan_fixture_must_prune_exact_completion_anchor');
    } else {
        $wrongRestore = recoverySql("UPDATE plugins.correspondence_offscreen_jobs SET payload=jsonb_set(payload,'{restore_generation}',to_jsonb($3::text),TRUE) WHERE playthrough_id=$1 AND job_id=$2 AND status IN ('failed','stale') RETURNING job_id::text", ['unprofiled',$job['job_id'],'wrong-fair-scan-generation-'.$i]);
        recoveryCheck(count($wrongRestore) === 1, 'fair_scan_fixture_must_corrupt_only_stored_restore_proof');
    }
    if ($i === 0 || $i === 1) {
        $corruptMarker = $i === 0 ? 'not-a-timestamp' : '2999-12-31T23:59:59.999999Z';
        $markerSeeded = recoverySql("UPDATE plugins.correspondence_offscreen_jobs SET payload=jsonb_set(payload,'{recovery_checked_at}',to_jsonb($3::text),TRUE) WHERE playthrough_id=$1 AND job_id=$2 AND status IN ('failed','stale') RETURNING job_id::text", ['unprofiled',$job['job_id'],$corruptMarker]);
        recoveryCheck(count($markerSeeded) === 1, 'fair_scan_fixture_must_seed_malformed_and_future_progress_markers');
    }
    $ordered = recoverySql("UPDATE plugins.correspondence_offscreen_jobs SET created_at=TIMESTAMPTZ '2000-01-01 00:00:00+00'+$3::bigint*INTERVAL '1 second' WHERE playthrough_id=$1 AND job_id=$2 AND status IN ('failed','stale') RETURNING job_id::text", ['unprofiled',$job['job_id'],(string)$i]);
    recoveryCheck(count($ordered) === 1, 'fair_scan_fixture_must_order_terminal_proof_blockers');
    $invalidProofRows[] = ['message_id'=>$mail['message_id'],'job_id'=>$job['job_id'],'completion_anchor_id'=>$completionAnchorId];
}
$laterFairMail = recoverySeedTerminalReaction(
    $engine,$store,900003,900002,'Valid terminal source after sixteen invalid proofs.',$ts,$gamets,false,
);
$laterFairJob = recoverySql("SELECT job_id::text FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id='unprofiled' AND source_message_id=$1 AND status IN ('failed','stale') ORDER BY created_at DESC LIMIT 1", [$laterFairMail['message_id']])[0] ?? [];
recoveryCheck(($laterFairJob['job_id'] ?? null) !== null, 'fair_scan_fixture_must_seed_later_valid_terminal_job');
$laterOrder = recoverySql("UPDATE plugins.correspondence_offscreen_jobs SET created_at=TIMESTAMPTZ '2000-01-01 00:00:00+00'+30*INTERVAL '1 second' WHERE playthrough_id='unprofiled' AND job_id=$1 AND status IN ('failed','stale') RETURNING job_id::text", [$laterFairJob['job_id']]);
recoveryCheck(count($laterOrder) === 1, 'fair_scan_fixture_must_place_valid_job_after_first_sixteen');
$expectedFairSources = array_merge(array_column($invalidProofRows, 'message_id'), [$laterFairMail['message_id']]);
$orderedFairSources = array_column(recoverySql("SELECT source_message_id::text FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id='unprofiled' AND status IN ('failed','stale') AND source_message_id IS NOT NULL AND created_at<TIMESTAMPTZ '2000-01-02 00:00:00+00' ORDER BY created_at,job_id LIMIT 17"), 'source_message_id');
recoveryCheck($orderedFairSources === $expectedFairSources, 'fair_scan_fixture_must_put_sixteen_invalid_proofs_before_later_valid_source');
$beforeFairStates = [];
foreach ($invalidProofRows as $entry) $beforeFairStates[$entry['message_id']] = $readFairState($entry);
$laterFairBefore = recoverySql("SELECT status,body,source_event_id::text FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=$1", [$laterFairMail['message_id']])[0] ?? [];
recoveryCheck(($laterFairBefore['status'] ?? null) === 'arrived' && ($laterFairBefore['body'] ?? null) === 'Valid terminal source after sixteen invalid proofs.'
    && ($laterFairBefore['source_event_id'] ?? null) === null, 'fair_scan_fixture_later_source_must_be_valid_and_unexposed');

    $ts++;
    $gamets++;
    recoveryObserve($engine, $ts, $gamets);
    $firstFairPass = $store->transaction(static fn() => recoveryPrivate($engine, 'recoverTerminalReactionSources', ['unprofiled']));
    recoveryRecordFailure($fairScanFailures, $firstFairPass === false, 'fair_scan_first_pass_must_leave_invalid_proof_sources_unretired');
    $allBlockersMarked = true;
    $allBlockersUnchanged = true;
    foreach ($invalidProofRows as $entry) {
        $after = $readFairState($entry);
        $before = $beforeFairStates[$entry['message_id']];
        $marker = $after['job']['recovery_checked_at'];
        $markerValid = is_string($marker) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}Z\z/D', $marker) === 1;
        $allBlockersMarked = $allBlockersMarked && $markerValid;
        $allBlockersUnchanged = $allBlockersUnchanged && (
            $after['job']['status'] === $before['job']['status'] && $after['job']['action'] === null
                && $after['job']['claim_id'] === $before['job']['claim_id'] && $after['job']['payload'] === $before['job']['payload']
                && $after['mail'] === $before['mail'] && $after['completion_anchor_exists'] === $before['completion_anchor_exists']
                && $after['exposures'] === [] && $after['stale_receipts'] === [] && $after['child_count'] === '0' && $after['job_count'] === '1'
        );
    }
    recoveryRecordFailure($fairScanFailures, $allBlockersMarked, 'fair_scan_must_stamp_each_selected_job_with_fixed_utc_progress');
    recoveryRecordFailure($fairScanFailures, $allBlockersUnchanged, 'fair_scan_progress_must_not_change_invalid_source_or_proof_state');
    $laterFairAfterFirst = recoverySql("SELECT status,body,source_event_id::text FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=$1", [$laterFairMail['message_id']])[0] ?? [];
    recoveryRecordFailure($fairScanFailures, ($laterFairAfterFirst['status'] ?? null) === 'arrived'
        && ($laterFairAfterFirst['body'] ?? null) === 'Valid terminal source after sixteen invalid proofs.'
        && ($laterFairAfterFirst['source_event_id'] ?? null) === null,
        'fair_scan_first_pass_must_not_skip_proofs_or_mutate_later_source');

    $ts++;
    $gamets++;
    recoveryObserve($engine, $ts, $gamets);
    $secondFairPass = $store->transaction(static fn() => recoveryPrivate($engine, 'recoverTerminalReactionSources', ['unprofiled']));
    $laterFairAfter = recoverySql("SELECT status,body,source_event_id::text,payload->>'reason_code' AS reason_code,payload->'stale_anchor'->>'rowid' AS stale_anchor FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=$1", [$laterFairMail['message_id']])[0] ?? [];
    $laterFairAnchor = $laterFairAfter['stale_anchor'] ?? null;
    $laterFairReceipt = is_string($laterFairAnchor) && $laterFairAnchor !== ''
        ? (recoverySql("SELECT data::jsonb->>'transition' AS transition,data::jsonb->>'message_id' AS message_id,data::jsonb->>'reason_code' AS reason_code FROM public.eventlog WHERE rowid=$1", [$laterFairAnchor])[0] ?? [])
        : [];
    recoveryRecordFailure($fairScanFailures, $secondFairPass === true
        && ($laterFairAfter['status'] ?? null) === 'stale'
        && ($laterFairAfter['reason_code'] ?? null) === 'reaction_abandoned'
        && is_string($laterFairAfter['stale_anchor'] ?? null)
        && ($laterFairAfter['body'] ?? null) === 'Valid terminal source after sixteen invalid proofs.'
        && ($laterFairAfter['source_event_id'] ?? null) === null
        && ($laterFairReceipt['transition'] ?? null) === 'mail_stale'
        && ($laterFairReceipt['message_id'] ?? null) === $laterFairMail['message_id'],
        'fair_scan_second_fresh_pass_must_reach_later_valid_source_without_model_or_exposure');
    $allBlockersUnchanged = true;
    foreach ($invalidProofRows as $entry) {
        $after = $readFairState($entry);
        $before = $beforeFairStates[$entry['message_id']];
        $allBlockersUnchanged = $allBlockersUnchanged && (
            $after['job']['status'] === $before['job']['status'] && $after['job']['action'] === null
                && $after['job']['claim_id'] === $before['job']['claim_id'] && $after['job']['payload'] === $before['job']['payload']
                && is_string($after['job']['recovery_checked_at']) && $after['mail'] === $before['mail']
                && $after['completion_anchor_exists'] === $before['completion_anchor_exists']
                && $after['exposures'] === [] && $after['stale_receipts'] === [] && $after['child_count'] === '0' && $after['job_count'] === '1'
        );
    }
    recoveryRecordFailure($fairScanFailures, $allBlockersUnchanged, 'fair_scan_second_pass_must_leave_all_invalid_sources_and_proofs_untouched');

    $controlExtendedData = correspondenceJson(['background_life_enabled'=>true,'background_life_letters'=>true,'background_life_goals'=>'Check pending correspondence state.']);
    $controlMetadata = correspondenceJson(['last_coords'=>'[0,0,0,"00013488"]']);
    $controlInsert = recoverySql("INSERT INTO public.core_npc_master(id,npc_name,personality,extended_data,metadata) VALUES(900004,$1,$2,$3::jsonb,$4::jsonb) RETURNING id::text", ['Recovery Control','A synthetic pending-state control actor.',$controlExtendedData,$controlMetadata]);
    recoveryCheck(count($controlInsert) === 1 && recoverySql("SELECT message_id::text FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND sender_id=900004 AND depth=0 AND status IN ('in_transit','arrived')") === [], 'pending_control_fixture_must_start_without_existing_mail');
    $controlRows = $store->transaction(static fn() => $store->offscreenActors());
    $controlCatalog = recoveryPrivate($engine, 'npcMap', [$controlRows]);
    $controlActors = $controlCatalog;
    foreach ($controlActors as $id => &$actor) if ((int)$id !== 900004) $actor['goal_text'] = '';
    unset($actor);
    $controlCandidate = static fn() => recoveryPrivate($engine, 'initiationCandidate', ['unprofiled',$gamets,$controlActors]);
    $controlEligible = $controlCandidate();
    recoveryRecordFailure($fairScanFailures, (int)($controlEligible['self']['id'] ?? 0) === 900004, 'pending_control_fixture_must_have_isolated_eligible_actor');

    $inTransitCreated = $store->transaction(static fn() => recoveryPrivate($engine, 'createMail', [
        'unprofiled',$controlCatalog[900004],$controlCatalog[900002],'In-transit pending control letter.',$gamets,null,'letter',
    ]));
    $inTransitMail = recoveryPrivate($engine, 'mailById', ['unprofiled',$inTransitCreated['message_id']]);
    $inTransitBlocked = $controlCandidate();
    recoveryRecordFailure($fairScanFailures, $inTransitBlocked === null, 'in_transit_root_mail_must_remain_pending');
    $creationAnchorId = (string)($inTransitMail['payload_array']['creation_anchor']['rowid'] ?? '');
    $removedTransit = recoverySql("DELETE FROM plugins.correspondence_offscreen_mail WHERE playthrough_id=$1 AND message_id=$2 AND status='in_transit' RETURNING message_id::text", ['unprofiled',$inTransitCreated['message_id']]);
    $removedCreation = recoverySql("DELETE FROM public.eventlog WHERE rowid=$1 AND sess='correspondence_v1' RETURNING rowid::text", [$creationAnchorId]);
    recoveryCheck($creationAnchorId !== '' && count($removedTransit) === 1 && count($removedCreation) === 1, 'in_transit_control_fixture_must_clean_exact_seed');

    $noJobMail = recoveryFixtureArrivedMail($engine,$store,900004,900002,'Arrived no-job pending control letter.',$ts,$gamets);
    $noJobBefore = recoverySql("SELECT job_id::text FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id='unprofiled' AND source_message_id=$1", [$noJobMail['message_id']]);
    $noJobBlocked = $controlCandidate();
    recoveryRecordFailure($fairScanFailures, $noJobBefore === [] && $noJobBlocked === null, 'arrived_source_without_terminal_reaction_job_must_remain_pending');
    $noJobRetired = $store->transaction(static fn() => recoveryPrivate($engine, 'retireUnexposedSource', [
        'unprofiled',$noJobMail['message_id'],'reaction_abandoned',recoveryPrivate($engine, 'restoreGeneration'),$noJobMail,
    ]));
    recoveryCheck($noJobRetired === true, 'arrived_no_job_control_fixture_must_cleanup_exact_source');
    recoveryRecordFailure($fairScanFailures, (int)(($controlCandidate()['self']['id'] ?? 0)) === 900004, 'arrived_no_job_cleanup_must_restore_candidate');

    $claimedControlMail = recoveryFixtureArrivedMail($engine,$store,900004,900002,'Claimed reaction pending control letter.',$ts,$gamets);
    $claimedControlActors = recoveryPrivate($engine, 'npcMap', [recoveryPrivate($engine, 'npcRows')]);
    $claimedControlSource = recoveryPrivate($engine, 'sourceForMail', [$claimedControlMail,$claimedControlActors]);
    $claimedControlSelf = $claimedControlActors[900002];
    $claimedControlContacts = recoveryPrivate($engine, 'contacts', [$claimedControlSelf,$claimedControlActors]);
    $claimedControlContext = ['self'=>recoveryPrivate($engine, 'selfProjection', [$claimedControlSelf,$claimedControlContacts]),'contacts'=>$claimedControlContacts,'source'=>$claimedControlSource];
    $claimedControlObservation = recoveryPrivate($engine, 'bindLatestObservation');
    $claimedControlWork = $store->transaction(static fn() => recoveryPrivate($engine, 'claim', [
        'unprofiled',$claimedControlObservation,$claimedControlSelf,'react',$claimedControlMail,null,$claimedControlContext,
    ]));
    recoveryCheck(($claimedControlWork['result_kind'] ?? null) === 'claimed', 'claimed_pending_control_fixture_must_create_real_claim');
    $claimedBlocked = $controlCandidate();
    recoveryRecordFailure($fairScanFailures, $claimedBlocked === null, 'claimed_nonterminal_reaction_source_must_remain_pending');
    $claimedTerminal = $store->transaction(static fn() => recoveryPrivate($engine, 'terminalJob', [
        $claimedControlWork,'stale',null,'context_changed',null,true,
    ]));
    recoveryCheck(($claimedTerminal['status'] ?? null) === 'stale', 'claimed_pending_control_fixture_must_complete_terminal_job');
    recoveryRecordFailure($fairScanFailures, (int)(($controlCandidate()['self']['id'] ?? 0)) === 900004, 'arrived_terminal_reaction_source_must_be_inert_for_new_initiation');
    $claimedRetired = $store->transaction(static fn() => recoveryPrivate($engine, 'retireUnexposedSource', [
        'unprofiled',$claimedControlMail['message_id'],'reaction_abandoned',recoveryPrivate($engine, 'restoreGeneration'),$claimedControlMail,
    ]));
    recoveryCheck($claimedRetired === true, 'claimed_pending_control_fixture_must_cleanup_exact_source');

    $ts++;
    $gamets += correspondenceAutonomyGamets(8);
    recoveryObserve($engine, $ts, $gamets);
    $freshActorRows = $store->transaction(static fn() => recoveryPrivate($engine, 'npcRows'));
    $freshActors = recoveryPrivate($engine, 'npcMap', [$freshActorRows]);
    $sameSenderCandidate = recoveryPrivate($engine, 'initiationCandidate', ['unprofiled',$gamets,$freshActors]);
    $cooldownsReady = recoveryPrivate($engine, 'globalCooldown', ['unprofiled',$gamets]) <= $gamets
        && recoveryPrivate($engine, 'actorCooldown', ['unprofiled',900003,$gamets]) <= $gamets;
    recoveryRecordFailure($fairScanFailures, $cooldownsReady && (int)($sameSenderCandidate['self']['id'] ?? 0) === 900003,
        'invalid_terminal_arrivals_must_not_block_same_sender_after_cooldowns');
    if (is_array($sameSenderCandidate) && (int)($sameSenderCandidate['self']['id'] ?? 0) === 900003 && $cooldownsReady) {
        $sameSenderContacts = recoveryPrivate($engine, 'contacts', [$sameSenderCandidate['self'],$freshActors]);
        $sameSenderTarget = recoveryPrivate($engine, 'findContact', ['npc:900002',$sameSenderContacts]);
        $sameSenderContext = [
            'self'=>recoveryPrivate($engine, 'selfProjection', [$sameSenderCandidate['self'],$sameSenderContacts]),
            'contacts'=>$sameSenderContacts,'goal'=>$sameSenderCandidate['goal'],
        ];
        $sameSenderObservation = recoveryPrivate($engine, 'bindLatestObservation');
        $sameSenderWork = $store->transaction(static fn() => recoveryPrivate($engine, 'claim', [
            'unprofiled',$sameSenderObservation,$sameSenderCandidate['self'],'initiate',null,$sameSenderCandidate['goal'],$sameSenderContext,
        ]));
        if (($sameSenderWork['result_kind'] ?? null) === 'claimed' && is_array($sameSenderTarget)) {
            $newGoalBody = 'A new goal letter from the sender previously pinned by invalid terminal proofs.';
            $newGoalFinish = recoveryPrivate($engine, 'finishWork', [$sameSenderWork,'letter',$newGoalBody,$sameSenderTarget['key'],true,'sent',strlen($newGoalBody)]);
            $newGoalMail = recoverySql("SELECT message_id::text,body,status,depth,source_event_id::text FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND sender_id=900003 AND depth=0 AND body=$1 ORDER BY created_at DESC LIMIT 1", [$newGoalBody])[0] ?? [];
            recoveryRecordFailure($fairScanFailures, ($newGoalFinish['status'] ?? null) === 'processed'
                && ($newGoalFinish['reason'] ?? null) === 'initiated'
                && ($newGoalMail['body'] ?? null) === $newGoalBody && ($newGoalMail['status'] ?? null) === 'in_transit'
                && ($newGoalMail['source_event_id'] ?? null) === null,
                'same_sender_must_create_new_root_goal_mail_after_invalid_terminal_arrivals');
        } else {
            recoveryRecordFailure($fairScanFailures, false, 'same_sender_must_claim_goal_after_invalid_terminal_arrivals');
        }
    }
    $invalidSourcesStillIsolatedAfterInitiation = true;
    foreach ($invalidProofRows as $entry) {
        $after = $readFairState($entry);
        $before = $beforeFairStates[$entry['message_id']];
        $invalidSourcesStillIsolatedAfterInitiation = $invalidSourcesStillIsolatedAfterInitiation && (
            $after['job']['status'] === $before['job']['status'] && $after['job']['action'] === null
                && $after['job']['claim_id'] === $before['job']['claim_id'] && $after['job']['payload'] === $before['job']['payload']
                && is_string($after['job']['recovery_checked_at']) && $after['mail'] === $before['mail']
                && $after['completion_anchor_exists'] === $before['completion_anchor_exists']
                && $after['exposures'] === [] && $after['stale_receipts'] === [] && $after['child_count'] === '0' && $after['job_count'] === '1'
        );
    }
    recoveryRecordFailure($fairScanFailures, $invalidSourcesStillIsolatedAfterInitiation,
        'same_sender_initiation_must_leave_all_invalid_sources_and_proofs_untouched');
} finally {
    recoverySql("UPDATE public.core_npc_master SET extended_data=$2::jsonb WHERE id=$1", ['900001',$savedSenderGoal]);
    recoverySql("UPDATE public.core_npc_master SET extended_data=$2::jsonb WHERE id=$1", ['900003',$savedBlockedSenderGoal]);
}
recoveryCheck($fairScanFailures === [], 'offscreen_recovery_fair_scan_regressions_failed:'.implode(',', $fairScanFailures));

echo json_encode([
    'status'=>'PASS',
    'model_throw_retires_without_exposure'=>true,
    'expired_reaction_claim_reconsiders_once_then_retires_without_effects'=>true,
    'existing_terminal_reaction_recovers_without_retry'=>true,
    'malformed_returned_json_retires_without_exposure'=>true,
    'schema_invalid_returned_json_retires_without_exposure'=>true,
    'recovery_window_reaches_later_arrived_source'=>true,
    'later_initiation_after_retirement'=>true,
    'prompt_uses_latest_three_valid_letters'=>true,
    'prompt_wrapper_closing_marker_is_escaped'=>true,
    'provider_calls'=>0,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
pg_close($recoveryConnection);
