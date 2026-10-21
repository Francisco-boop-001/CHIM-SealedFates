<?php
declare(strict_types=1);

final class CorrespondencePgStore
{
    public array $queries = [];
    public array $candidateSql = [];
    public array $decisionSql = [];
    public array $fresh = [];
    public array $recheckable = [];
    public array $deferrals = [];
    public int $npcRowsQueries = 0;
    public array $scopeRow = ['playthrough_id'=>'unprofiled','ts'=>100000,'gamets'=>100000];

    public function transaction(callable $work): mixed { return $work(); }
    public function readTransaction(callable $work): mixed { return $work(); }
    public function scope(): array { return $this->scopeRow; }
    public function restoreGeneration(): string { return 'restore-dev19'; }
    public function requestRestoreToken(): string { return 'restore-dev19'; }

    public function query(string $sql, array $parameters = []): array
    {
        if (str_contains($sql, 'FROM public.core_npc_master WHERE id>0')) {
            $this->npcRowsQueries++;
            return [];
        }
        if (str_contains($sql, 'FROM public.eventlog e')) {
            $this->candidateSql[] = $sql;
            preg_match('/\bLIMIT\s+\$([1-9][0-9]*)/i', $sql, $limitMatch);
            preg_match('/\bOFFSET\s+\$([1-9][0-9]*)/i', $sql, $offsetMatch);
            $limit = isset($limitMatch[1]) ? (int)($parameters[(int)$limitMatch[1] - 1] ?? 64) : 64;
            $offset = isset($offsetMatch[1]) ? (int)($parameters[(int)$offsetMatch[1] - 1] ?? 0) : 0;
            $kind = str_contains($sql, "retry.status='stale'") ? 'recheckable' : 'fresh';
            $this->queries[] = ['kind'=>$kind,'limit'=>$limit,'offset'=>$offset];
            return array_slice($kind === 'fresh' ? $this->fresh : $this->recheckable, $offset, $limit);
        }
        if (str_contains($sql, 'SELECT enabled,updated_at::text')) return [['enabled'=>'t','updated_at'=>'fixture']];
        if (str_contains($sql, 'INSERT INTO plugins.correspondence_autonomy_decisions')) {
            $this->decisionSql[] = $sql;
            $payload = json_decode((string)($parameters[6] ?? ''), true);
            if (is_array($payload) && ($payload['reconsiderable'] ?? false) === true) $this->deferrals[] = $payload;
            return [];
        }
        if (str_contains($sql, "status='claimed'")) return [];
        if (str_contains($sql, "status='queued'")) return [];
        if (str_contains($sql, 'SELECT COALESCE(MAX(CASE')) return [['until_gamets'=>'0']];
        return [];
    }
}
require_once dirname(__DIR__).'/server/lib/boundaries.php';
require_once dirname(__DIR__).'/server/lib/autonomy_policy.php';

final class AutonomySourceFairnessDev19ServiceStore implements CorrespondenceStore
{
    public int $readCalls = 0;
    public function transaction(callable $work): mixed { return $work(); }
    public function readTransaction(callable $work): mixed { return $work(); }
    public function scope(): array { return ['playthrough_id'=>'unprofiled','ts'=>100000,'gamets'=>100000]; }
    public function actors(): array { return []; }
    public function player(): string { return 'Dragonborn'; }
    public function lockActorRows(array $npcIds, bool $includePlayer = false): array { return []; }
    public function findRequest(string $scope, string $request): ?array { return null; }
    public function loadDocument(string $id): ?array { return null; }
    public function saveDocument(array $document): void {}
    public function documents(string $scope, ?int $recipientId = null): array { return []; }
    public function loadOperation(string $id): ?array { return null; }
    public function saveOperation(array $operation): void {}
    public function appendEvent(string $type, string $data, string $people, string $sess): array { return []; }
    public function eventMatches(array $event): bool { return false; }
    public function managedExecutor(): ?string { return null; }
    public function prepareNote(string $title, string $body): void {}
    public function queueCommand(string $actor, string $action): void {}
    public function physicalActor(array $actor): array { return []; }
    public function physicalItem(array $item): array { return []; }
    public function query(string $sql, array $parameters = []): array
    {
        if (str_contains($sql, 'FROM public.eventlog WHERE rowid=$1')) {
            $this->readCalls++;
            throw new DomainException('actor_unavailable');
        }
        return [];
    }
}

require_once dirname(__DIR__).'/server/lib/autonomy_store.php';

function autonomySourceFairnessDev19Check(bool $ok, string $reason): void
{
    if (!$ok) throw new RuntimeException($reason);
}

function autonomySourceFairnessDev19Rows(string $kind, int $count): array
{
    return array_map(static fn(int $i): array => [
        'kind'=>$kind,
        'source_event_id'=>(string)(($kind === 'retry' ? 0 : 1000) + $i),
        'source_document_id'=>sprintf('%08x-0000-4000-8000-%012x', $kind === 'retry' ? 1 : 2, $i),
        'recipient_id'=>'900001',
        'source_data'=>'fixture-'.$kind.'-'.$i,
        'root_document_id'=>sprintf('%08x-0000-4000-8000-%012x', $kind === 'retry' ? 1 : 2, $i),
        'source_depth'=>0,
    ], range(1, $count));
}

function autonomySourceFairnessDev19Select(CorrespondenceAutonomyPg $autonomy): array
{
    $method = new ReflectionMethod(CorrespondenceAutonomyPg::class, 'sourceCandidates');
    return $method->invoke($autonomy, 'unprofiled', 100000, 7200);
}

$store = new CorrespondencePgStore();
$autonomy = new CorrespondenceAutonomyPg($store, new CorrespondenceService(new AutonomySourceFairnessDev19ServiceStore()));

$store->fresh = autonomySourceFairnessDev19Rows('fresh', 80);
$store->recheckable = autonomySourceFairnessDev19Rows('retry', 80);
$rows = autonomySourceFairnessDev19Select($autonomy);
autonomySourceFairnessDev19Check(count($rows) === 64, 'candidate batch stays capped at 64');
autonomySourceFairnessDev19Check(array_column(array_slice($rows, 0, 32), 'kind') === array_fill(0, 32, 'retry')
    && array_column(array_slice($rows, 32), 'kind') === array_fill(0, 32, 'fresh'),
    'older due rechecks are interleaved ahead of newer fresh work while both receive reserved slots');
autonomySourceFairnessDev19Check((int)$rows[0]['source_event_id'] < (int)$rows[31]['source_event_id']
    && (int)$rows[31]['source_event_id'] < (int)$rows[32]['source_event_id'],
    'candidate lanes are merged in source-event order before the tick can claim work');
autonomySourceFairnessDev19Check(count($store->queries) === 2
    && $store->queries[0] === ['kind'=>'fresh','limit'=>32,'offset'=>0]
    && $store->queries[1] === ['kind'=>'recheckable','limit'=>32,'offset'=>0],
    'fresh and due-recheck SQL batches are individually bounded');
$retrySql = $store->candidateSql[1] ?? '';
autonomySourceFairnessDev19Check(str_contains($retrySql, "retry.payload->'reconsiderable'='true'::jsonb")
    && str_contains($retrySql, 'retry.action IS NULL')
    && str_contains($retrySql, 'retry.child_document_id IS NULL')
    && str_contains($retrySql, 'retry.due_gamets IS NULL')
    && str_contains($retrySql, 'retry.payload ?| ARRAY[')
    && str_contains($retrySql, "'claim_anchor'")
    && str_contains($retrySql, "'completion_anchor'")
    && str_contains($retrySql, "'model_calls'"),
    'retry selection accepts only clean, anchorless deferral rows');

$store->queries = [];
$store->fresh = autonomySourceFairnessDev19Rows('fresh', 57);
$store->recheckable = autonomySourceFairnessDev19Rows('retry', 7);
$rows = autonomySourceFairnessDev19Select($autonomy);
autonomySourceFairnessDev19Check(count($rows) === 64
    && count(array_filter($rows, static fn(array $row): bool => $row['kind'] === 'fresh')) === 57
    && count(array_filter($rows, static fn(array $row): bool => $row['kind'] === 'retry')) === 7,
    'unused retry slots are filled from the fresh backlog');
autonomySourceFairnessDev19Check($store->queries[2] === ['kind'=>'fresh','limit'=>25,'offset'=>32],
    'fresh fill resumes after the reserved fresh page');

$store->queries = [];
$store->fresh = autonomySourceFairnessDev19Rows('fresh', 5);
$store->recheckable = autonomySourceFairnessDev19Rows('retry', 59);
$rows = autonomySourceFairnessDev19Select($autonomy);
autonomySourceFairnessDev19Check(count($rows) === 64
    && count(array_filter($rows, static fn(array $row): bool => $row['kind'] === 'fresh')) === 5
    && count(array_filter($rows, static fn(array $row): bool => $row['kind'] === 'retry')) === 59,
    'unused fresh slots are filled from the due-recheck backlog');
autonomySourceFairnessDev19Check($store->queries[2] === ['kind'=>'recheckable','limit'=>27,'offset'=>32],
    'recheck fill resumes after the reserved retry page');

$store->queries = [];
$store->deferrals = [];
$store->npcRowsQueries = 0;
$store->fresh = autonomySourceFairnessDev19Rows('fresh', 80);
$store->recheckable = autonomySourceFairnessDev19Rows('retry', 80);
$serviceStore = new AutonomySourceFairnessDev19ServiceStore();
$service = new CorrespondenceService($serviceStore);
$autonomy = new CorrespondenceAutonomyPg($store, $service);
$modelCalls = 0;
$result = $autonomy->tick(static function(array $messages) use (&$modelCalls): string { $modelCalls++; return ''; });
autonomySourceFairnessDev19Check(($result['status'] ?? null) === 'idle' && ($result['reason'] ?? null) === 'actor_unavailable',
    'all mutable-ineligible source candidates are durably deferred');
autonomySourceFairnessDev19Check($serviceStore->readCalls === 64 && count($store->deferrals) === 64,
    'only the capped 64-source batch is reconsidered and persisted');
autonomySourceFairnessDev19Check($modelCalls === 0,
    'read-time construction failure consumes no model call');
autonomySourceFairnessDev19Check($store->npcRowsQueries === 1,
    'NPC roster is queried once for the entire candidate batch');
autonomySourceFairnessDev19Check(count(array_filter($store->deferrals, static fn($payload): bool =>
    is_array($payload) && ($payload['reconsiderable'] ?? false) === true
        && ($payload['reason_code'] ?? null) === 'actor_unavailable'
        && ($payload['recheck_after_gamets'] ?? null) === (string)(100000 + correspondenceAutonomyGamets(1)))) === 64,
    'mutable-ineligible failures persist a one-hour reconsideration marker');
autonomySourceFairnessDev19Check($store->decisionSql !== []
    && str_contains($store->decisionSql[0], 'existing.action IS NULL')
    && str_contains($store->decisionSql[0], 'existing.child_document_id IS NULL')
    && str_contains($store->decisionSql[0], 'existing.due_gamets IS NULL')
    && str_contains($store->decisionSql[0], 'existing.payload ?| ARRAY[')
    && str_contains($store->decisionSql[0], "existing.payload->'reconsiderable'='true'::jsonb"),
    'deferral upsert cannot overwrite a retry row with prior effect evidence');

$store->decisionSql = [];
$documentId = '00000001-0000-4000-8000-000000000001';
$recordStale = new ReflectionMethod(CorrespondenceAutonomyPg::class, 'recordStaleSource');
$recordStale->invoke($autonomy, 'unprofiled', $documentId, 9001, 'fixture event', 100000, 'source_unavailable');
$claim = new ReflectionMethod(CorrespondenceAutonomyPg::class, 'claim');
$claimResult = $claim->invoke($autonomy, 'unprofiled', $documentId, 9001, 100000,
    ['root_document_id'=>$documentId,'depth'=>0],
    ['source_body'=>'fixture body','self_row'=>['id'=>900001],'context_sha256'=>str_repeat('a',64)],
    'fixture event', 'restore-dev19');
$writesAreGuarded = count($store->decisionSql) === 2 && $claimResult === null;
foreach ($store->decisionSql as $sql) {
    $writesAreGuarded = $writesAreGuarded
        && str_contains($sql, 'existing.action IS NULL')
        && str_contains($sql, 'existing.child_document_id IS NULL')
        && str_contains($sql, 'existing.due_gamets IS NULL')
        && str_contains($sql, 'existing.payload ?| ARRAY[')
        && str_contains($sql, "existing.payload->'reconsiderable'='true'::jsonb");
}
autonomySourceFairnessDev19Check($writesAreGuarded,
    'terminal-stale and claim upserts require an evidence-free retry row');

echo "PASS autonomy source fairness selection\n";
