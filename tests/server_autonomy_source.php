<?php
declare(strict_types=1);
$root = dirname(__DIR__);
function autonomySourceCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function autonomySourceRejected(callable $work, string $reason): void
{
    try { $work(); } catch (DomainException|InvalidArgumentException) { return; }
    throw new RuntimeException($reason);
}
require_once $root . '/server/lib/runtime.php';
require_once __DIR__ . '/support/correspondence_memory_store.php';
require_once __DIR__ . '/support/correspondence_idle_pg_fixture.php';
if (!function_exists('pg_query_params')) {
    final class AutonomyFakePgResult
    {
        public function __construct(public array $rows) {}
    }
    function pg_query_params(mixed $connection, string $sql, array $parameters): mixed
    {
        if(str_contains($sql,"current_setting('lock_timeout')"))return new AutonomyFakePgResult([['lock_timeout'=>'0']]);
        $adapter = $GLOBALS['autonomyFakePg'] ?? null;
        if (!is_callable($adapter)) return false;
        $rows = $adapter($sql, $parameters);
        return is_array($rows) ? new AutonomyFakePgResult($rows) : false;
    }
    function pg_num_fields(AutonomyFakePgResult $result): int { return $result->rows === [] ? 0 : count($result->rows[0]); }
    function pg_fetch_all(AutonomyFakePgResult $result): array|false { return $result->rows === [] ? false : $result->rows; }
    function pg_free_result(AutonomyFakePgResult $result): bool { return true; }
}
require_once $root . '/server/lib/store.php';
require_once $root . '/server/lib/autonomy_policy.php';
require_once $root . '/server/lib/autonomy_store.php';

function autonomySourceFixture(bool $expose = true): array
{
    $store = new CorrespondenceMemoryStore();
    $service = new CorrespondenceService($store);
    $doc = $service->compose([
        'request_id' => '11111111-1111-4111-8111-111111111111',
        'author' => 'player', 'payer' => 'player', 'recipient' => 'npc:900001',
        'claimed_sender' => 'npc:900002', 'body' => 'The claim stays attributed to its sender.', 'attachments' => [],
    ]);
    $sent = $service->dispatch($doc['document_id']);
    $op = $store->operations[$sent['operation_id']];
    $receipt = array_intersect_key($op, array_flip(['protocol_version','document_id','operation_id','receipt_id','playthrough_id','scope_token','nonce']));
    $receipt += ['status'=>'delivered','reason'=>'ok','note_form_id'=>12345,'attachments'=>[]];
    $service->receive($receipt);
    if ($expose) $service->prompt('Camilla');
    $op = $store->operations[$sent['operation_id']];
    if ($expose) {
        $sourceId = (int)$op['source']['rowid'];
        $store->events[$sourceId]['type'] = 'info';
        $op['source']['type'] = 'info';
        $store->operations[$sent['operation_id']] = $op;
    }
    return [$store, $service, $doc, $op];
}

[$store, $service, $doc, $op] = autonomySourceFixture();
$sourceEventId = (int)$op['source']['rowid'];
$read = $service->readExposedSource($sourceEventId, 900001);
autonomySourceCheck(array_keys($read) === ['event','source'], 'source reader returns only event and neutral source');
autonomySourceCheck($read['event'] === $op['source'], 'source reader returns the immutable stored source event');
autonomySourceCheck($read['source']['document_id'] === $doc['document_id'] && $read['source']['body'] === 'The claim stays attributed to its sender.', 'source reader returns the validated neutral envelope');
autonomySourceCheck(array_keys($read['source']) === ['schema_version','document_id','playthrough_id','recipient','claimed_sender','body','receipt','attachments'], 'source envelope remains strict schema 1');
autonomySourceCheck(!array_key_exists('author', $read['source']) && !array_key_exists('payer', $read['source']), 'private author and payer stay hidden');
autonomySourceRejected(fn() => $service->readExposedSource($sourceEventId, 900002), 'wrong recipient cannot read an exposed source');
autonomySourceRejected(fn() => $service->readExposedSource($sourceEventId + 100, 900001), 'wrong event id cannot substitute another source');

[$unexposedStore, $unexposedService, , $unexposedOp] = autonomySourceFixture(false);
autonomySourceRejected(fn() => $unexposedService->readExposedSource((int)$unexposedOp['receipt_anchor']['rowid'], 900001), 'possession without recipient-context exposure is insufficient');

$changed = $store->events[$sourceEventId];
$store->events[$sourceEventId]['data'] = str_replace('claim stays attributed', 'claim was rewritten', $changed['data']);
autonomySourceRejected(fn() => $service->readExposedSource($sourceEventId, 900001), 'changed source row cannot be read');
$store->events[$sourceEventId] = $changed;

$opBeforeMalformed = $store->operations[$op['operation_id']];
$malformedSource = $read['source'];
$malformedSource['recipient'] = 'not-an-actor-object';
$malformedEvent = $changed;
$malformedEvent['data'] = correspondenceJson($malformedSource);
$store->events[$sourceEventId] = $malformedEvent;
$opBeforeMalformed['source'] = $malformedEvent;
$store->operations[$op['operation_id']] = $opBeforeMalformed;
autonomySourceRejected(fn() => $service->readExposedSource($sourceEventId, 900001), 'malformed nested source values are rejected without a type error');
$store->events[$sourceEventId] = $changed;
$store->operations[$op['operation_id']] = $op;

$reused = $changed;
$reused['data'] = '{"schema_version":1,"document_id":"aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa"}';
$store->events[$sourceEventId] = $reused;
autonomySourceRejected(fn() => $service->readExposedSource($sourceEventId, 900001), 'reused event row id cannot bypass source identity');
$store->events[$sourceEventId] = $changed;

if (!extension_loaded('pgsql')) {
$lostClaimId = '22222222-2222-4222-8222-222222222222';
$lostSourceId = '33333333-3333-4333-8333-333333333333';
$lostEventId = '77';
$lostRestoreEpoch = '66666666-6666-4666-8666-666666666666';
$lostRestoreGeneration = $lostRestoreEpoch . ':source-fixture-generation';
$claimData = [
    'kind'=>'autonomy_claim','playthrough_id'=>'unprofiled','claim_id'=>$lostClaimId,'request_id'=>$lostClaimId,
    'source_document_id'=>$lostSourceId,'source_event_id'=>'5','claim_gamets'=>'100',
    'global_cooldown_until_gamets'=>'416767','actor_cooldown_until_gamets'=>'2160100',
    'restore_generation'=>$lostRestoreGeneration,
];
$lostAnchor = ['rowid'=>$lostEventId,'ts'=>'100','gamets'=>'100','type'=>'info','data'=>correspondenceJson($claimData),'sess'=>'correspondence_v1','localts'=>'100','people'=>''];
$lostPayload = [
    'reason_code'=>'claimed','claim_gamets'=>'100','claim_global_cooldown_until_gamets'=>'416767',
    'claim_actor_cooldown_until_gamets'=>'2160100','global_cooldown_until_gamets'=>'416767',
    'actor_cooldown_until_gamets'=>'2160100','self_id'=>'900001','source_event_sha256'=>str_repeat('a',64),
    'source_body_sha256'=>str_repeat('b',64),'context_sha256'=>str_repeat('c',64),'claim_anchor'=>$lostAnchor,
    'restore_generation'=>$lostRestoreGeneration,
];
$lostDecision = [
    'playthrough_id'=>'unprofiled','source_document_id'=>$lostSourceId,'source_event_id'=>'5','claim_id'=>$lostClaimId,
    'request_id'=>$lostClaimId,'root_document_id'=>$lostSourceId,'depth'=>0,'child_document_id'=>null,
    'status'=>'claimed','action'=>null,'due_gamets'=>null,'payload'=>correspondenceJson($lostPayload),
];
$completionAppends = 0;
$quarantineWrites = 0;
$managedChildrenReads = 0;
$managedChildIds = ['44444444-4444-4444-8444-444444444444','55555555-5555-4555-8555-555555555555'];
$GLOBALS['autonomyFakePg'] = static function (string $sql, array $parameters) use (&$lostDecision, $lostRestoreEpoch, $lostSourceId, $lostClaimId, &$completionAppends, &$quarantineWrites, &$managedChildrenReads, $managedChildIds): array {
    if (str_contains($sql, "to_regclass('chim_meta.playthrough_profiles')")) return [['profile_table'=>null]];
    if (str_starts_with($sql, 'SELECT epoch::text,pending FROM plugins.correspondence_restore_state')) return [['epoch'=>$lostRestoreEpoch,'pending'=>'f']];
    if (str_starts_with($sql, 'SELECT native_ts_floor FROM plugins.correspondence_restore_state')) return [['native_ts_floor'=>'0']];
    if (str_contains($sql, 'pg_advisory_unlock_shared')) return [['released'=>'t']];
    if (str_starts_with($sql, 'SELECT playthrough_id,source_document_id::text')) return [$lostDecision];
    if (str_starts_with($sql, "UPDATE plugins.correspondence_autonomy_decisions SET status='stale'")) {
        $quarantineWrites++;
        if (($parameters[0] ?? null) !== 'unprofiled' || ($parameters[1] ?? null) !== $lostSourceId
            || ($parameters[2] ?? null) !== '5' || ($parameters[3] ?? null) !== $lostClaimId) return [];
        $lostDecision['status'] = 'stale';
        $payload = json_decode($lostDecision['payload'], true, 32, JSON_THROW_ON_ERROR);
        $payload['reason_code'] = 'claim_anchor_invalid';
        $lostDecision['payload'] = correspondenceJson($payload);
        return [['source_document_id'=>$lostSourceId]];
    }
    if (str_starts_with($sql, 'SELECT child_document_id::text')) {
        $managedChildrenReads++;
        return array_map(static fn($id) => ['child_document_id'=>$id], $managedChildIds);
    }
    if (str_starts_with($sql, 'INSERT INTO public.eventlog')) {
        $completionAppends++;
        return [['rowid'=>'88','ts'=>'200','gamets'=>'200','type'=>'info','data'=>$parameters[3] ?? '',
            'sess'=>$parameters[4] ?? '','localts'=>'200','people'=>$parameters[6] ?? '']];
    }
    return [];
};
$lostPg = new CorrespondencePgStore(null, static fn($store) => null, static fn($title, $body) => true);
$GLOBALS['ptr_runtime_generation'] = 'source-fixture-generation';
$restoreReadyProperty = new ReflectionProperty(CorrespondencePgStore::class, 'restoreStateReady');
$restoreReadyProperty->setValue($lostPg, true);
$lostPg->bindRequestClock(['inputtext','200','200','isolated unit fixture']);
$restoreGeneration = $lostPg->requestRestoreToken();
autonomySourceCheck($restoreGeneration === $lostRestoreGeneration, 'lost claim fixture carries a valid current restore token');
$activeProperty = new ReflectionProperty(CorrespondencePgStore::class, 'active');
$activeProperty->setValue($lostPg, true);
$writerProperty = new ReflectionProperty(CorrespondencePgStore::class, 'writerActive');
$writerProperty->setValue($lostPg, true);
$lostAutonomy = new CorrespondenceAutonomyPg($lostPg, new CorrespondenceService($lostPg));
$bglBoolean = new ReflectionMethod(CorrespondenceAutonomyPg::class, 'bglBoolean');
$dbBoolean = new ReflectionMethod(CorrespondenceAutonomyPg::class, 'dbBoolean');
foreach ([true,1,'1','t','true','on'] as $truth) {
    autonomySourceCheck($bglBoolean->invoke($lostAutonomy, $truth) === true, 'background life accepts only its exact true values');
    autonomySourceCheck($dbBoolean->invoke($lostAutonomy, $truth) === true, 'settings accept only their exact true values');
}
foreach ([false,0,1.0,'TRUE','T','ON','yes',null] as $falsehood) {
    autonomySourceCheck($bglBoolean->invoke($lostAutonomy, $falsehood) === false, 'background life rejects noncanonical true values');
    autonomySourceCheck($dbBoolean->invoke($lostAutonomy, $falsehood) === false, 'settings reject noncanonical true values');
}
$lostWork = [
    'playthrough_id'=>'unprofiled','source_document_id'=>$lostSourceId,'source_event_id'=>5,'claim_id'=>$lostClaimId,
    'request_id'=>$lostClaimId,'root_document_id'=>$lostSourceId,'depth'=>0,'recipient_id'=>900001,
    'claim_gamets'=>100,'claim_global_cooldown_until_gamets'=>416767,'claim_actor_cooldown_until_gamets'=>2160100,
    'source_event_sha256'=>str_repeat('a',64),'source_body_sha256'=>str_repeat('b',64),
    'context_sha256'=>str_repeat('c',64),'claim_anchor'=>$lostAnchor,'restore_generation'=>$restoreGeneration,
];
$failureMethod = new ReflectionMethod(CorrespondenceAutonomyPg::class, 'completeFailure');
$failureResult = $failureMethod->invoke($lostAutonomy, $lostWork, 'invalid_decision', 1, 20, 8);
autonomySourceCheck(($failureResult['status'] ?? null) === 'stale' && ($failureResult['reason'] ?? null) === 'claim_anchor_invalid', 'lost claim during model failure must close stale');
autonomySourceCheck($quarantineWrites === 1 && $lostDecision['status'] === 'stale', 'lost claim quarantine CASes only its exact active claim');
$quarantinedPayload = json_decode($lostDecision['payload'], true, 32, JSON_THROW_ON_ERROR);
autonomySourceCheck(($quarantinedPayload['reason_code'] ?? null) === 'claim_anchor_invalid' && $lostDecision['child_document_id'] === null, 'lost claim quarantine records only an inert stale reason');
autonomySourceCheck($completionAppends === 0, 'lost claim during model failure must not write a completion anchor');
$readsBeforeManagedChildren = $managedChildrenReads;
autonomySourceCheck($lostAutonomy->managedChildren() === $managedChildIds, 'managed children returns current-scope child IDs');
autonomySourceCheck($managedChildrenReads === $readsBeforeManagedChildren + 1, 'managed children uses one list query');
unset($GLOBALS['autonomyFakePg']);
unset($GLOBALS['ptr_runtime_generation']);
}

echo "PASS autonomy source reader: exact exposed recipient, immutable source identity, strict v1 privacy\n";
