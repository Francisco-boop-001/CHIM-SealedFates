<?php
declare(strict_types=1);

require_once __DIR__ . '/recovery_pg_fixture.php';
require_once dirname(__DIR__, 2) . '/server/lib/store.php';
require_once dirname(__DIR__, 2) . '/server/lib/offscreen.php';

function offscreenDev19PgCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function offscreenDev19PgSql(PgSql\Connection $connection, string $sql, array $args = []): array
{
    $result = @pg_query_params($connection, $sql, $args);
    if ($result === false) throw new RuntimeException('dev19_fixture_sql_failed');
    try { return pg_num_fields($result) > 0 ? (pg_fetch_all($result) ?: []) : []; }
    finally { pg_free_result($result); }
}

function offscreenDev19PgPrivate(CorrespondenceOffscreenPg $engine, string $method, array $arguments = []): mixed
{
    return (new ReflectionMethod($engine, $method))->invokeArgs($engine, $arguments);
}

function offscreenDev19PgObserve(CorrespondenceOffscreenPg $engine, int $ts, int $gamets): void
{
    $engine->observe(['bored',(string)$ts,(string)$gamets,'trusted dev19 regression fixture']);
}

function offscreenDev19PgAdvance(CorrespondenceOffscreenPg $engine, int &$ts, int &$gamets, int $delta): void
{
    $ts++;
    $gamets += $delta;
    offscreenDev19PgObserve($engine, $ts, $gamets);
}

function offscreenDev19PgArrivedMail(
    CorrespondenceOffscreenPg $engine,
    CorrespondencePgStore $store,
    int $senderId,
    int $recipientId,
    string $body,
    int &$ts,
    int &$gamets
): array {
    $ts++;
    $gamets++;
    offscreenDev19PgObserve($engine, $ts, $gamets);
    $actors = offscreenDev19PgPrivate($engine, 'npcMap', [offscreenDev19PgPrivate($engine, 'npcRows')]);
    $created = $store->transaction(fn() => offscreenDev19PgPrivate($engine, 'createMail', [
        'unprofiled',$actors[$senderId],$actors[$recipientId],$body,$gamets,null,'letter',
    ]));

    $gamets = max($gamets + 1, (int)$created['due_gamets'] + 100);
    $ts++;
    offscreenDev19PgObserve($engine, $ts, $gamets);
    $mail = offscreenDev19PgPrivate($engine, 'mailById', ['unprofiled',(string)$created['message_id']]);
    $arrival = $store->transaction(static function () use ($engine, $store, $mail, $gamets): array {
        $reference = offscreenDev19PgPrivate($engine, 'appendAnchor', ['mail_arrived', [
            'message_id'=>$mail['message_id'],'sender_id'=>(string)$mail['sender_id'],
            'recipient_id'=>(string)$mail['recipient_id'],'gamets'=>(string)$gamets,
        ]]);
        $changed = $store->query("UPDATE plugins.correspondence_offscreen_mail SET status='arrived',arrived_gamets=$4,payload=payload||jsonb_build_object('arrival_anchor',$2::jsonb) WHERE playthrough_id=$1 AND message_id=$3 AND status='in_transit' RETURNING message_id::text", [
            'unprofiled',correspondenceJson($reference),$mail['message_id'],(string)$gamets,
        ]);
        return ['reference'=>$reference,'changed'=>count($changed)];
    });
    offscreenDev19PgCheck($arrival['changed'] === 1, 'dev19_fixture_arrival_failed');
    return offscreenDev19PgPrivate($engine, 'mailById', ['unprofiled',(string)$created['message_id']]);
}

function offscreenDev19PgClaimSilent(
    CorrespondenceOffscreenPg $engine,
    CorrespondencePgStore $store,
    array $mail,
    array $actors
): array {
    $source = offscreenDev19PgPrivate($engine, 'sourceForMail', [$mail,$actors]);
    $self = $actors[(int)$mail['recipient_id']];
    $contacts = offscreenDev19PgPrivate($engine, 'contacts', [$self,$actors,(int)$mail['sender_id']]);
    $projection = offscreenDev19PgPrivate($engine, 'selfProjection', [$self,$contacts]);
    $messages = correspondenceAutonomyMessages($source,$projection,$contacts);
    $observation = offscreenDev19PgPrivate($engine, 'bindLatestObservation');
    $work = $store->transaction(fn() => offscreenDev19PgPrivate($engine, 'claim', [
        'unprofiled',$observation,$self,'react',$mail,null,
        ['self'=>$projection,'contacts'=>$contacts,'source'=>$source,'messages'=>$messages],
    ]));
    offscreenDev19PgCheck(($work['result_kind'] ?? null) === 'claimed', 'dev19_fixture_seed_claim_failed');
    $terminal = $store->transaction(fn() => offscreenDev19PgPrivate($engine, 'terminalJob', [
        $work,'silent','none','silence',null,true,
    ]));
    offscreenDev19PgCheck(($terminal['status'] ?? null) === 'processed'
        && ($terminal['reason'] ?? null) === 'silence', 'dev19_fixture_seed_terminal_failed');
    return $work;
}

function offscreenDev19PgSetup(): array
{
    $connection = correspondenceRecoveryFixture('chim_corr_recovery_offscreen');
    $existing = offscreenDev19PgSql($connection, "SELECT table_name FROM information_schema.tables WHERE table_schema IN ('public','plugins','chim_meta')");
    offscreenDev19PgCheck($existing === [], 'dev19_fixture_database_must_begin_empty');

    $ddl = <<<'SQL'
CREATE SCHEMA chim_meta;
CREATE TABLE chim_meta.playthrough_profiles(id bigint PRIMARY KEY,is_active boolean NOT NULL);
CREATE TABLE public.eventlog(rowid bigserial PRIMARY KEY,ts bigint NOT NULL,gamets numeric NOT NULL,type text NOT NULL,data text NOT NULL,sess text NOT NULL,localts bigint NOT NULL,people text NOT NULL,location text NOT NULL,party text NOT NULL);
CREATE TABLE public.core_npc_master(id bigint PRIMARY KEY,npc_name text NOT NULL,personality text NOT NULL,extended_data jsonb NOT NULL DEFAULT '{}'::jsonb,metadata jsonb NOT NULL DEFAULT '{}'::jsonb);
INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(100,100,'inputtext','synthetic dev19 fixture seed','',100,'','','');
INSERT INTO public.core_npc_master(id,npc_name,personality,extended_data,metadata) VALUES
  (900001,'Camilla','Thoughtful and private.','{"background_life_enabled":true,"background_life_letters":true,"background_life_goals":"","relationships":{"Sigrid":{"aff":-31.5}}}','{"last_coords":"[0,0,0,\"00013488\"]"}'),
  (900002,'Sigrid','Practical and curious.','{"background_life_enabled":true,"background_life_letters":true,"background_life_goals":""}','{"last_coords":"[0,0,0,\"00013488\"]"}'),
  (900003,'Faendal','Quiet and observant.','{"background_life_enabled":true,"background_life_letters":true,"background_life_goals":""}','{"last_coords":"[0,0,0,\"00013488\"]"}');
SQL;
    $result = @pg_query($connection, $ddl);
    if ($result === false) throw new RuntimeException('dev19_fixture_schema_create_failed');
    pg_free_result($result);

    $lockPath = tempnam(sys_get_temp_dir(), 'correspondence-dev19-lock-');
    offscreenDev19PgCheck(is_string($lockPath), 'dev19_fixture_interaction_lock_unavailable');
    $GLOBALS['offscreen_dev19_interaction_lock_path'] = $lockPath;
    $GLOBALS['offscreen_dev19_interaction_generation'] = 19;
    $GLOBALS['chim_interaction_generation'] = 19;
    $GLOBALS['ptr_runtime_generation'] = 'runtime-generation-dev19-1';
    register_shutdown_function(static function () use ($lockPath): void { if (is_file($lockPath)) @unlink($lockPath); });

    $store = new CorrespondencePgStore($connection,static fn($scope) => null,static fn($title,$body) => true);
    $store->ensureSchema();
    $engine = new CorrespondenceOffscreenPg($store);
    $engine->ensureSchema();
    offscreenDev19PgSql($connection,"INSERT INTO plugins.correspondence_autonomy_settings(playthrough_id,enabled) VALUES('unprofiled',TRUE)");

    return ['connection'=>$connection,'store'=>$store,'engine'=>$engine,'ts'=>101,'gamets'=>101];
}

function chimInteractionFile()
{
    $path = $GLOBALS['offscreen_dev19_interaction_lock_path'] ?? null;
    $handle = is_string($path) ? @fopen($path,'c+') : false;
    if (!is_resource($handle)) throw new RuntimeException('dev19_fixture_interaction_lock_unavailable');
    return $handle;
}

function chimInteractionRead($handle): array
{
    $path = $GLOBALS['offscreen_dev19_interaction_lock_path'] ?? null;
    $probe = is_string($path) ? @fopen($path,'c+') : false;
    if (!is_resource($probe)) throw new RuntimeException('dev19_fixture_interaction_probe_unavailable');
    $acquired = @flock($probe,LOCK_EX|LOCK_NB);
    if ($acquired) @flock($probe,LOCK_UN);
    fclose($probe);
    return ['enabled'=>true,'generation'=>(int)($GLOBALS['offscreen_dev19_interaction_generation'] ?? 19)];
}

function chimInteractionBegin(): void
{
    if (!array_key_exists('chim_interaction_generation',$GLOBALS)) {
        $GLOBALS['chim_interaction_generation'] = (int)($GLOBALS['offscreen_dev19_interaction_generation'] ?? 19);
    }
}

function chimInteractionState(): array
{
    return ['enabled'=>true,'generation'=>(int)($GLOBALS['offscreen_dev19_interaction_generation'] ?? 19)];
}
