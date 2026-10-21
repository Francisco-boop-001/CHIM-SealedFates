<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/support/recovery_pg_fixture.php';
$root = dirname(__DIR__, 2);
require_once $root.'/server/lib/store.php';
require_once $root.'/server/lib/runtime.php';

$connection = correspondenceRecoveryFixture('chim_corr_recovery_store');
$catalog = pg_query($connection, "SELECT (to_regclass('public.eventlog') IS NULL) AS empty, (to_regclass('plugins.correspondence_documents') IS NULL) AS plugin_empty");
if ($catalog === false || (pg_fetch_assoc($catalog) ?: []) !== ['empty'=>'t','plugin_empty'=>'t']) {
    throw new RuntimeException('receipt_uncertainty_fixture_must_be_empty');
}
pg_free_result($catalog);

$ddl = <<<'SQL'
CREATE SCHEMA chim_meta;
CREATE TABLE chim_meta.playthrough_profiles(id bigint PRIMARY KEY,is_active boolean NOT NULL);
INSERT INTO chim_meta.playthrough_profiles(id,is_active) VALUES(1,TRUE);
CREATE TABLE public.eventlog(
    rowid bigserial PRIMARY KEY,
    ts bigint NOT NULL,
    gamets numeric NOT NULL,
    type text NOT NULL,
    data text NOT NULL,
    sess text NOT NULL,
    localts bigint NOT NULL,
    people text NOT NULL,
    location text NOT NULL,
    party text NOT NULL
);
CREATE TABLE public.core_npc_master(id bigint PRIMARY KEY,npc_name text NOT NULL,refid text NOT NULL,profile_id text);
CREATE TABLE public.core_player(id text PRIMARY KEY,value text NOT NULL);
INSERT INTO public.core_player(id,value) VALUES('player_name','Dragonborn');
INSERT INTO public.core_npc_master(id,npc_name,refid,profile_id) VALUES
    (900001,'Camilla','00013488',NULL),
    (900002,'Jarl Balgruuf','0001A677',NULL),
    (900003,'Lydia','000A2C94',NULL);
CREATE TABLE public.game_plugins(plugin_name text NOT NULL,formid_prefix text NOT NULL);
INSERT INTO public.game_plugins(plugin_name,formid_prefix) VALUES('Skyrim.esm','00');
CREATE TABLE public.books(ts bigint,gamets numeric,content text,sess text,localts bigint,title text);
CREATE TABLE public.responselog(localts bigint,sent integer,actor text,text text,action text,tag text);
SQL;
if (@pg_query($connection, $ddl) === false) throw new RuntimeException('receipt_uncertainty_fixture_schema_create_failed');

$store = new CorrespondencePgStore($connection, static fn($unused) => 'Lydia', static fn($title, $body) => true);
$store->ensureSchema();
$store->bindRequestClock(['inputtext','1000','1000']);
$service = new CorrespondenceService($store);
$input = [
    'request_id'=>'11111111-1111-4111-8111-111111111111',
    'author'=>'player',
    'payer'=>'player',
    'recipient'=>'npc:900001',
    'claimed_sender'=>'npc:900002',
    'body'=>'Keep the original text unchanged during uncertain receipt recovery.',
    'attachments'=>[['kind'=>'item','form_id'=>'Skyrim.esm|00000010','quantity'=>5]],
];
$queued = $service->compose($input);
$sent = $service->dispatch($queued['document_id']);
$operation = $store->loadOperation($sent['operation_id']);
$unknown = [
    'protocol_version'=>1,
    'document_id'=>$operation['document_id'],
    'operation_id'=>$operation['operation_id'],
    'receipt_id'=>$operation['receipt_id'],
    'playthrough_id'=>$operation['playthrough_id'],
    'scope_token'=>$operation['scope_token'],
    'nonce'=>$operation['nonce'],
    'status'=>'unknown',
    'reason'=>'transfer_mismatch',
    'note_form_id'=>12345,
    'attachments'=>[['form_id'=>16,'quantity'=>-2,'before_source'=>20,'after_source'=>15,'before_recipient'=>10,'after_recipient'=>8]],
];
$result = $service->receive($unknown);
$stored = $store->loadOperation($operation['operation_id']);
if (($result['state'] ?? null) !== 'unknown' || !is_array($stored['receipt']??null)
    || correspondenceCanonicalJson($stored['receipt'])!==correspondenceCanonicalJson($unknown)) {
    throw new RuntimeException('negative_unknown_delta_must_be_preserved_without_confirmation');
}

$badUnknown = $unknown;
$badUnknown['attachments'][0]['quantity'] = 1;
try {
    $service->receive($badUnknown);
    throw new RuntimeException('unknown_delta_must_match_signed_recipient_observation');
} catch (DomainException|InvalidArgumentException $expected) {
}
$badDelivered = $unknown;
$badDelivered['status'] = 'delivered';
$badDelivered['reason'] = 'physical_only';
try {
    $service->receive($badDelivered);
    throw new RuntimeException('delivered_receipt_must_keep_exact_positive_transfer_invariants');
} catch (DomainException|InvalidArgumentException $expected) {
}
$service->dispatch($queued['document_id'], true);
$actions = array_column($store->query('SELECT action FROM public.responselog WHERE action LIKE $1 ORDER BY localts', ['%'.$queued['document_id'].'%']), 'action');
$deliverCount = count(array_filter($actions, static fn($action) => str_starts_with($action, 'command|ExtCmdCHIMCorrespondenceBridge_Deliver@')));
if ($deliverCount !== 1 || !str_starts_with((string)end($actions), 'command|ExtCmdCHIMCorrespondenceBridge_Reconcile@')) {
    throw new RuntimeException('unknown_receipt_reconcile_must_not_queue_a_second_transfer');
}

$exposureInput = $input;
$exposureInput['request_id'] = '22222222-2222-4222-8222-222222222222';
$exposureInput['body'] = 'Preserve the existing written source after receipt-anchor loss.';
$exposureInput['attachments'] = [];
$exposureQueued = $service->compose($exposureInput);
$exposureSent = $service->dispatch($exposureQueued['document_id']);
$exposureOperation = $store->loadOperation($exposureSent['operation_id']);
$confirmed = [
    'protocol_version'=>1,
    'document_id'=>$exposureOperation['document_id'],
    'operation_id'=>$exposureOperation['operation_id'],
    'receipt_id'=>$exposureOperation['receipt_id'],
    'playthrough_id'=>$exposureOperation['playthrough_id'],
    'scope_token'=>$exposureOperation['scope_token'],
    'nonce'=>$exposureOperation['nonce'],
    'status'=>'delivered',
    'reason'=>'physical_only',
    'note_form_id'=>12346,
    'attachments'=>[],
];
$service->receive($confirmed);
$service->prompt('Camilla');
$before = $store->query("SELECT count(*)::int AS n FROM public.eventlog WHERE sess='written_evidence_v1' AND data::jsonb->>'document_id'=$1", [$exposureQueued['document_id']]);
$exposureOperation = $store->loadOperation($exposureSent['operation_id']);
$source = $exposureOperation['source'];
$store->bindRequestClock(['inputtext','1001','999']);
$sourceNotDue = null;
try {
    $service->readExposedSource((int)$source['rowid'], 900001);
} catch (DomainException $error) {
    $sourceNotDue = $error->getMessage();
}
$store->bindRequestClock(['inputtext','1002','1000']);
if ($sourceNotDue !== 'source_not_due') throw new RuntimeException('temporary_gamets_rollback_must_defer_an_authenticated_source');
$store->transaction(static function () use ($store, $exposureOperation): void {
    $store->query('DELETE FROM public.eventlog WHERE rowid=$1', [$exposureOperation['receipt_anchor']['rowid']]);
});
$wasUnknown = $service->get($exposureQueued['document_id'])['state'] === 'unknown';
$service->receive($confirmed);
$prompt = $service->prompt('Camilla');
$after = $store->query("SELECT count(*)::int AS n FROM public.eventlog WHERE sess='written_evidence_v1' AND data::jsonb->>'document_id'=$1", [$exposureQueued['document_id']]);
$recovered = $store->loadOperation($exposureSent['operation_id']);
// JSONB may reorder the embedded event's object members when the operation is reloaded.
$recoveredSource = $recovered['source'] ?? null;
$recoveredSourceRowId = is_array($recoveredSource) && is_numeric($recoveredSource['rowid'] ?? null)
    ? (int)$recoveredSource['rowid'] : null;
$originalSourceRowId = (int)($source['rowid'] ?? 0);
$eventMatches = is_array($recoveredSource) && $store->eventMatches($recoveredSource);
$sameSourceEvent = $recoveredSourceRowId === $originalSourceRowId && $eventMatches;
$promptNonempty = $prompt !== '';
$beforeCount = (int)($before[0]['n'] ?? -1);
$afterCount = (int)($after[0]['n'] ?? -1);
if (!$wasUnknown || !$sameSourceEvent || !$promptNonempty || $beforeCount !== 1 || $afterCount !== 1) {
    $diagnostic = [
        'wasUnknown'=>$wasUnknown,
        'sameSourceEvent'=>$sameSourceEvent,
        'promptNonempty'=>$promptNonempty,
        'beforeCount'=>$beforeCount,
        'afterCount'=>$afterCount,
        'recoveredSourceRowId'=>$recoveredSourceRowId,
        'originalSourceRowId'=>$originalSourceRowId,
        'eventMatches'=>$eventMatches,
    ];
    throw new RuntimeException('matching_source_anchor_must_survive_receipt_anchor_restore_without_duplicate_written_event: '.json_encode($diagnostic, JSON_THROW_ON_ERROR));
}

echo "PASS receipt uncertainty PostgreSQL fixture: signed unknown evidence, strict final delivery, read-only reconcile, exact source-anchor recovery\n";
pg_close($connection);
