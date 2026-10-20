<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/support/recovery_pg_fixture.php';
$candidate = dirname(__DIR__, 2);
require_once $candidate.'/server/lib/store.php';
require_once $candidate.'/server/lib/runtime.php';

$connection = correspondenceRecoveryFixture('chim_corr_recovery_store');
$peer = correspondenceRecoveryFixture('chim_corr_recovery_store');
$storeRecoveryPgFailures = [];

function storeRecoveryPgRequire(bool $ok, string $reason): void
{
    if (!$ok) throw new RuntimeException($reason);
}

function storeRecoveryPgCheck(bool $ok, string $reason): void
{
    global $storeRecoveryPgFailures;
    if (!$ok) $storeRecoveryPgFailures[] = $reason;
}

function storeRecoveryPgSql(PgSql\Connection $connection, string $sql, array $parameters = []): array
{
    $result = @pg_query_params($connection, $sql, $parameters);
    if ($result === false) throw new RuntimeException('store_recovery_fixture_sql_failed');
    try {
        return pg_num_fields($result) > 0 ? (pg_fetch_all($result) ?: []) : [];
    } finally {
        pg_free_result($result);
    }
}

function storeRecoveryPgLogOffset(PgSql\Connection $connection, string $file): int
{
    $rows = storeRecoveryPgSql($connection, 'SELECT (pg_stat_file($1)).size::text AS size', [$file]);
    $size = $rows[0]['size'] ?? null;
    if (!is_string($size) || preg_match('/\A[0-9]+\z/D', $size) !== 1) {
        throw new RuntimeException('store_recovery_log_offset_unavailable');
    }
    return (int)$size;
}

function storeRecoveryPgStartStatementLog(PgSql\Connection $connection): array
{
    $files = storeRecoveryPgSql($connection, 'SELECT pg_current_logfile() AS file');
    $file = $files[0]['file'] ?? null;
    if (!is_string($file) || $file === '') throw new RuntimeException('store_recovery_statement_log_unavailable');
    $offset = storeRecoveryPgLogOffset($connection, $file);
    $marker = 'chim_corr_recovery_store_count';
    storeRecoveryPgSql($connection, 'SELECT set_config($1,$2,FALSE)', ['application_name', $marker]);
    storeRecoveryPgSql($connection, "SET log_statement='all'");
    return ['file'=>$file, 'offset'=>$offset, 'marker'=>$marker];
}

function storeRecoveryPgReadLogSince(PgSql\Connection $connection, array $position): string
{
    $file = $position['file'] ?? null;
    $offset = $position['offset'] ?? null;
    if (!is_string($file) || $file === '' || !is_int($offset) || $offset < 0) {
        throw new RuntimeException('store_recovery_log_position_invalid');
    }
    $length = storeRecoveryPgLogOffset($connection, $file) - $offset;
    if ($length < 0) throw new RuntimeException('store_recovery_log_rotated');
    if ($length === 0) return '';
    $maxBytes = 8 * 1024 * 1024;
    if ($length > $maxBytes) throw new RuntimeException('store_recovery_log_segment_too_large');
    $rows = storeRecoveryPgSql(
        $connection,
        'SELECT pg_read_file($1,$2::bigint,$3::bigint) AS contents',
        [$file, (string)$offset, (string)$length]
    );
    return is_string($rows[0]['contents'] ?? null) ? $rows[0]['contents'] : '';
}

function storeRecoveryPgLoggedQueries(string $log, string $marker): array
{
    $queries = [];
    foreach (preg_split('/\r?\n/', $log) ?: [] as $line) {
        if (!str_contains($line, $marker)) continue;
        if (preg_match('/(?:statement:\s*|execute\s+[^:]+:\s*)(.*)$/i', $line, $match) === 1) {
            $queries[] = $match[1];
        }
    }
    return $queries;
}

function storeRecoveryPgQueryCount(array $queries, string $table): int
{
    $count = 0;
    foreach ($queries as $sql) if (stripos($sql, 'FROM '.$table) !== false) $count++;
    return $count;
}

function storeRecoveryPgCatalogQueryCount(array $queries, string $table): int
{
    $count = 0;
    foreach ($queries as $sql) {
        if (stripos($sql, 'FROM '.$table) !== false && stripos($sql, 'FOR SHARE') === false) $count++;
    }
    return $count;
}

function storeRecoveryPgEffectLockQueries(array $queries, string $table): array
{
    return array_values(array_filter(
        $queries,
        static fn($sql) => stripos($sql, 'FROM '.$table) !== false && stripos($sql, 'FOR SHARE') !== false
    ));
}

storeRecoveryPgRequire(
    storeRecoveryPgSql($connection, "SELECT table_name FROM information_schema.tables WHERE table_schema IN ('public','plugins','chim_meta')") === [],
    'store_recovery_database_must_begin_empty'
);

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
CREATE TABLE public.core_npc_master(
    id bigint PRIMARY KEY,
    npc_name text NOT NULL,
    refid text NOT NULL,
    profile_id text
);
CREATE TABLE public.core_player(id text PRIMARY KEY,value text NOT NULL);
INSERT INTO public.core_player(id,value) VALUES('player_name','Dragonborn');
INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party)
SELECT 1000+i,1000+i,'inputtext','synthetic store recovery baseline','',1000+i,'','',''
FROM generate_series(1,100000) AS g(i);
INSERT INTO public.core_npc_master(id,npc_name,refid,profile_id)
SELECT 900000+i,'NPC '||lpad(i::text,4,'0'),'00013488',NULL
FROM generate_series(1,2000) AS g(i);
SQL;
storeRecoveryPgRequire(@pg_query($connection, $ddl) !== false, 'store_recovery_fixture_schema_create_failed');

$planRows = storeRecoveryPgSql(
    $connection,
    'EXPLAIN (ANALYZE,BUFFERS,FORMAT JSON) SELECT MAX(ts) AS ts FROM public.eventlog'
);
$maxTsPlan = json_decode($planRows[0]['QUERY PLAN'] ?? 'null', true, 32, JSON_THROW_ON_ERROR);
echo 'BASELINE_MAX_TS_PLAN_100K_NO_TS_INDEX='.json_encode($maxTsPlan, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL;

$store = new CorrespondencePgStore(
    $connection,
    static fn($unused) => null,
    static fn($title, $body) => true
);
$store->ensureSchema();
$store->bindRequestClock(['inputtext','101000','101000']);
$service = new CorrespondenceService($store);
$targetId = 900001;
$targetName = 'NPC 0001';

$store->transaction(static function () use ($store, $targetId, $targetName): void {
    $scope = $store->scope()['playthrough_id'];
    for ($i = 1; $i <= 50; $i++) {
        $recipientId = 900000 + $i;
        $recipientName = 'NPC '.str_pad((string)$i, 4, '0', STR_PAD_LEFT);
        $requestId = correspondenceUuid();
        $documentId = correspondenceUuid();
        $body = 'Synthetic queued letter '.$i;
        $requestInput = [
            'request_id'=>$requestId,
            'author'=>'player',
            'payer'=>'player',
            'recipient'=>'npc:'.$recipientId,
            'claimed_sender'=>'npc:'.$targetId,
            'body'=>$body,
            'attachments'=>[],
        ];
        $document = [
            'document_id'=>$documentId,
            'playthrough_id'=>$scope,
            'request_id'=>$requestId,
            'request_hash'=>hash('sha256', correspondenceCanonicalJson($requestInput)),
            'title'=>'letter '.$documentId,
            'author'=>['kind'=>'player','id'=>null,'name'=>'Dragonborn'],
            'payer'=>['kind'=>'player','id'=>null,'name'=>'Dragonborn'],
            'recipient'=>['kind'=>'npc','id'=>$recipientId,'name'=>$recipientName],
            'claimed_sender'=>['kind'=>'npc','id'=>$targetId,'name'=>$targetName],
            'body'=>$body,
            'attachments'=>[],
            'operation_id'=>null,
        ];
        $document['anchor'] = $store->appendEvent(
            'correspondence_anchor',
            correspondenceJson(['document_id'=>$documentId,'scope_token'=>correspondenceUuid()]),
            '',
            'correspondence_v1'
        );
        $store->saveDocument($document);
    }
});

$logContext = storeRecoveryPgStartStatementLog($connection);
$logFile = $logContext['file'];
$promptLogStart = storeRecoveryPgLogOffset($connection, $logFile);
$prompt = $service->prompt($targetName);
$promptQueries = storeRecoveryPgLoggedQueries(storeRecoveryPgReadLogSince($connection, ['file'=>$logFile,'offset'=>$promptLogStart]), $logContext['marker']);
$promptNpcQueries = storeRecoveryPgCatalogQueryCount($promptQueries, 'public.core_npc_master');
$promptPlayerQueries = storeRecoveryPgCatalogQueryCount($promptQueries, 'public.core_player');
$promptNpcLockQueries = storeRecoveryPgEffectLockQueries($promptQueries, 'public.core_npc_master');
$promptPlayerLockQueries = storeRecoveryPgEffectLockQueries($promptQueries, 'public.core_player');
$promptReadOnlyBegins = array_values(array_filter(
    $promptQueries,
    static fn($sql) => stripos($sql, 'BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY') !== false
));
$promptEventlogWriterLocks = array_values(array_filter(
    $promptQueries,
    static fn($sql) => stripos($sql, 'LOCK TABLE public.eventlog IN SHARE ROW EXCLUSIVE MODE') !== false
));
$promptAdvisoryWriterLocks = array_values(array_filter(
    $promptQueries,
    static fn($sql) => stripos($sql, 'pg_advisory_xact_lock(18459814, 1)') !== false
));
$promptDocumentQueries = array_values(array_filter(
    $promptQueries,
    static fn($sql) => stripos($sql, 'FROM plugins.correspondence_documents') !== false
));
$promptCollectionQueries = array_values(array_filter(
    $promptDocumentQueries,
    static fn($sql) => stripos($sql, 'WHERE playthrough_id=$1') !== false
));
$filterSql = count($promptCollectionQueries) === 1
    && stripos($promptCollectionQueries[0], 'recipient') !== false
    && str_contains($promptCollectionQueries[0], '$2');
echo 'PROMPT_QUERY_COUNTS_50_DOCS_2000_NPCS='.json_encode([
    'total'=>count($promptQueries),
    'npc_catalog'=>$promptNpcQueries,
    'player_name'=>$promptPlayerQueries,
    'targeted_actor_locks'=>count($promptNpcLockQueries),
    'targeted_player_locks'=>count($promptPlayerLockQueries),
    'read_only_begins'=>count($promptReadOnlyBegins),
    'eventlog_writer_locks'=>count($promptEventlogWriterLocks),
    'advisory_writer_locks'=>count($promptAdvisoryWriterLocks),
    'document_selects'=>count($promptDocumentQueries),
    'document_collection_selects'=>count($promptCollectionQueries),
    'recipient_filter'=>$filterSql,
], JSON_UNESCAPED_SLASHES).PHP_EOL;
storeRecoveryPgCheck($prompt === '', 'queued_letters_must_not_be_exposed_by_prompt');
storeRecoveryPgCheck($promptNpcQueries === 1, 'npc_catalog_must_be_read_once_per_transaction');
storeRecoveryPgCheck($promptPlayerQueries === 1, 'player_name_must_be_read_once_per_transaction');
storeRecoveryPgCheck(count($promptNpcLockQueries) === 0, 'queued_prompt_must_not_take_actor_effect_locks');
storeRecoveryPgCheck(count($promptPlayerLockQueries) === 0, 'queued_prompt_must_not_take_player_effect_locks');
storeRecoveryPgCheck(count($promptReadOnlyBegins) === 1, 'queued_prompt_must_run_in_one_repeatable_read_read_only_transaction');
storeRecoveryPgCheck(count($promptEventlogWriterLocks) === 0, 'queued_prompt_must_not_take_the_eventlog_writer_table_lock');
storeRecoveryPgCheck(count($promptAdvisoryWriterLocks) === 0, 'queued_prompt_must_not_take_the_writer_advisory_lock');
storeRecoveryPgCheck($filterSql, 'recipient_prompt_document_select_must_filter_before_validating_other_letters');
storeRecoveryPgCheck(count($promptQueries) <= 20, 'prompt_query_count_must_remain_bounded_for_50_letters_and_2000_npcs');

$deliveredBody = 'Synthetic delivered body for the real PostgreSQL prompt path.';
$deliveredSummary = $service->compose([
    'request_id'=>correspondenceUuid(),
    'author'=>'player',
    'payer'=>'player',
    'recipient'=>'npc:900001',
    'claimed_sender'=>'npc:900002',
    'body'=>$deliveredBody,
    'attachments'=>[],
]);
$serviceOperationId = correspondenceUuid();
$serviceReceiptId = correspondenceUuid();
$serviceScopeToken = correspondenceUuid();
$serviceNonce = bin2hex(random_bytes(32));
$store->transaction(static function () use ($store, $deliveredSummary, $serviceOperationId, $serviceReceiptId, $serviceScopeToken, $serviceNonce): void {
    $document = $store->loadDocument($deliveredSummary['document_id']);
    if (!is_array($document)) throw new RuntimeException('delivered_prompt_document_missing');
    $dispatchAnchor = $store->appendEvent(
        'correspondence_dispatch',
        correspondenceJson(['document_id'=>$document['document_id'],'operation_id'=>$serviceOperationId]),
        '',
        'correspondence_v1'
    );
    $receipt = [
        'protocol_version'=>1,
        'document_id'=>$document['document_id'],
        'operation_id'=>$serviceOperationId,
        'receipt_id'=>$serviceReceiptId,
        'playthrough_id'=>$document['playthrough_id'],
        'scope_token'=>$serviceScopeToken,
        'nonce'=>$serviceNonce,
        'status'=>'delivered',
        'reason'=>'physical_only',
        'note_form_id'=>12345,
        'attachments'=>[],
    ];
    $receiptAnchor = $store->appendEvent(
        'correspondence_receipt_confirmed',
        correspondenceJson([
            'document_id'=>$document['document_id'],
            'operation_id'=>$serviceOperationId,
            'receipt_hash'=>hash('sha256', correspondenceJson($receipt)),
        ]),
        '',
        'correspondence_v1'
    );
    $store->saveOperation([
        'protocol_version'=>1,
        'document_id'=>$document['document_id'],
        'operation_id'=>$serviceOperationId,
        'receipt_id'=>$serviceReceiptId,
        'playthrough_id'=>$document['playthrough_id'],
        'scope_token'=>$serviceScopeToken,
        'nonce'=>$serviceNonce,
        'receipt'=>$receipt,
        'receipt_anchor'=>$receiptAnchor,
        'dispatch_anchor'=>$dispatchAnchor,
        'source'=>null,
    ]);
    $document['operation_id']=$serviceOperationId;
    $store->saveDocument($document);
});

$deliveredLogStart = storeRecoveryPgLogOffset($connection, $logFile);
$deliveredPrompt = $service->prompt($targetName);
$deliveredPromptQueries = storeRecoveryPgLoggedQueries(
    storeRecoveryPgReadLogSince($connection, ['file'=>$logFile,'offset'=>$deliveredLogStart]),
    $logContext['marker']
);
$deliveredNpcLockQueries = storeRecoveryPgEffectLockQueries($deliveredPromptQueries, 'public.core_npc_master');
$deliveredPlayerLockQueries = storeRecoveryPgEffectLockQueries($deliveredPromptQueries, 'public.core_player');
$deliveredEventlogWriterLocks = array_values(array_filter(
    $deliveredPromptQueries,
    static fn($sql) => stripos($sql, 'LOCK TABLE public.eventlog IN SHARE ROW EXCLUSIVE MODE') !== false
));
$deliveredAdvisoryWriterLocks = array_values(array_filter(
    $deliveredPromptQueries,
    static fn($sql) => stripos($sql, 'pg_advisory_xact_lock(18459814, 1)') !== false
));
$deliveredReadOnlyBegins = array_values(array_filter(
    $deliveredPromptQueries,
    static fn($sql) => stripos($sql, 'BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY') !== false
));
$deliveredNpcLockShapes = array_values(array_filter(
    $deliveredNpcLockQueries,
    static fn($sql) => stripos($sql, 'WHERE id=ANY(') !== false
        && stripos($sql, '::bigint[]') !== false
        && stripos($sql, 'ORDER BY id FOR SHARE') !== false
));
$deliveredPlayerLockShapes = array_values(array_filter(
    $deliveredPlayerLockQueries,
    static fn($sql) => stripos($sql, 'WHERE id=$1 FOR SHARE') !== false
));
$deliveredSourceRows = storeRecoveryPgSql(
    $connection,
    "SELECT count(*)::int AS n FROM public.eventlog WHERE sess='written_evidence_v1' AND position($1 in data)>0",
    [$deliveredSummary['document_id']]
);
$deliveredSourceCount = (int)($deliveredSourceRows[0]['n'] ?? -1);
$deliveredPromptReferences = $service->sourceReferences;
$deliveredKnownReferences = $service->knownSourceReferences;
echo 'DELIVERED_PROMPT_LOCK_COUNTS='.json_encode([
    'npc_targeted_locks'=>count($deliveredNpcLockQueries),
    'npc_targeted_lock_shapes'=>count($deliveredNpcLockShapes),
    'player_targeted_locks'=>count($deliveredPlayerLockQueries),
    'player_targeted_lock_shapes'=>count($deliveredPlayerLockShapes),
    'eventlog_writer_locks'=>count($deliveredEventlogWriterLocks),
    'advisory_writer_locks'=>count($deliveredAdvisoryWriterLocks),
], JSON_UNESCAPED_SLASHES).PHP_EOL;
storeRecoveryPgCheck(str_contains($deliveredPrompt, $deliveredBody), 'delivered_prompt_must_preserve_the_original_body');
storeRecoveryPgCheck($deliveredSourceCount === 1, 'delivered_prompt_must_append_exactly_one_written_source');
storeRecoveryPgCheck(count($deliveredReadOnlyBegins) === 1, 'delivered_prompt_must_probe_once_in_read_only_transaction');
storeRecoveryPgCheck(count($deliveredEventlogWriterLocks) === 1, 'first_exposure_must_reenter_the_original_eventlog_writer_lock');
storeRecoveryPgCheck(count($deliveredAdvisoryWriterLocks) === 1, 'first_exposure_must_reenter_the_original_advisory_writer_lock');
storeRecoveryPgCheck(count($deliveredNpcLockQueries) === 2, 'first_exposure_must_revalidate_each_eligible_document_npc_identity');
storeRecoveryPgCheck(count($deliveredNpcLockShapes) === 2, 'writer_retry_must_keep_each_npc_lock_bounded_to_selected_ids');
storeRecoveryPgCheck(count($deliveredPlayerLockQueries) === 2, 'first_exposure_must_revalidate_each_eligible_document_player_identity');
storeRecoveryPgCheck(count($deliveredPlayerLockShapes) === 2, 'writer_retry_must_keep_each_player_lock_bounded_to_player_name_row');

$exposedLogStart = storeRecoveryPgLogOffset($connection, $logFile);
$exposedPrompt = $service->prompt($targetName);
$exposedPromptQueries = storeRecoveryPgLoggedQueries(
    storeRecoveryPgReadLogSince($connection, ['file'=>$logFile,'offset'=>$exposedLogStart]),
    $logContext['marker']
);
$exposedNpcLockQueries = storeRecoveryPgEffectLockQueries($exposedPromptQueries, 'public.core_npc_master');
$exposedPlayerLockQueries = storeRecoveryPgEffectLockQueries($exposedPromptQueries, 'public.core_player');
$exposedEventlogWriterLocks = array_values(array_filter(
    $exposedPromptQueries,
    static fn($sql) => stripos($sql, 'LOCK TABLE public.eventlog IN SHARE ROW EXCLUSIVE MODE') !== false
));
$exposedAdvisoryWriterLocks = array_values(array_filter(
    $exposedPromptQueries,
    static fn($sql) => stripos($sql, 'pg_advisory_xact_lock(18459814, 1)') !== false
));
$exposedSourceRows = storeRecoveryPgSql(
    $connection,
    "SELECT count(*)::int AS n FROM public.eventlog WHERE sess='written_evidence_v1' AND position($1 in data)>0",
    [$deliveredSummary['document_id']]
);
storeRecoveryPgCheck(
    $exposedPrompt === $deliveredPrompt
        && $service->sourceReferences === $deliveredPromptReferences
        && $service->knownSourceReferences === $deliveredKnownReferences
        && (int)($exposedSourceRows[0]['n'] ?? -1) === 1,
    'already_exposed_postgres_prompt_must_preserve_prompt_and_reference_arrays_without_duplicate_source'
);
storeRecoveryPgCheck(count($exposedNpcLockQueries) === 0, 'already_exposed_prompt_must_not_lock_npc_rows');
storeRecoveryPgCheck(count($exposedPlayerLockQueries) === 0, 'already_exposed_prompt_must_not_lock_player_rows');
storeRecoveryPgCheck(count($exposedEventlogWriterLocks) === 0, 'already_exposed_prompt_must_not_take_eventlog_writer_lock');
storeRecoveryPgCheck(count($exposedAdvisoryWriterLocks) === 0, 'already_exposed_prompt_must_not_take_advisory_writer_lock');

storeRecoveryPgRequire(@pg_query($peer, 'BEGIN') !== false, 'peer_transaction_begin_failed');
storeRecoveryPgRequire(
    storeRecoveryPgSql(
        $peer,
        "INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(101001,101001,'inputtext','peer uncommitted insert','',101001,'','','')"
    ) === [],
    'peer_uncommitted_eventlog_insert_failed'
);

$promptsDuringPeerLogStart = storeRecoveryPgLogOffset($connection, $logFile);
$promptsDuringPeerStarted = microtime(true);
$queuedPromptDuringPeer = $service->prompt('NPC 0002');
$exposedPromptDuringPeer = $service->prompt($targetName);
$promptsDuringPeerElapsed = microtime(true) - $promptsDuringPeerStarted;
$promptsDuringPeerQueries = storeRecoveryPgLoggedQueries(
    storeRecoveryPgReadLogSince($connection, ['file'=>$logFile,'offset'=>$promptsDuringPeerLogStart]),
    $logContext['marker']
);
$promptsDuringPeerReadOnlyBegins = array_values(array_filter(
    $promptsDuringPeerQueries,
    static fn($sql) => stripos($sql, 'BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY') !== false
));
$promptsDuringPeerNpcLocks = storeRecoveryPgEffectLockQueries($promptsDuringPeerQueries, 'public.core_npc_master');
$promptsDuringPeerPlayerLocks = storeRecoveryPgEffectLockQueries($promptsDuringPeerQueries, 'public.core_player');
$promptsDuringPeerEventlogLocks = array_values(array_filter(
    $promptsDuringPeerQueries,
    static fn($sql) => stripos($sql, 'LOCK TABLE public.eventlog IN SHARE ROW EXCLUSIVE MODE') !== false
));
$promptsDuringPeerAdvisoryLocks = array_values(array_filter(
    $promptsDuringPeerQueries,
    static fn($sql) => stripos($sql, 'pg_advisory_xact_lock(18459814, 1)') !== false
));
echo 'PROMPT_READS_PEER_UNCOMMITTED_INSERT='.json_encode([
    'elapsed_seconds'=>$promptsDuringPeerElapsed,
    'queued_prompt_empty'=>$queuedPromptDuringPeer === '',
    'exposed_prompt_unchanged'=>$exposedPromptDuringPeer === $deliveredPrompt,
    'read_only_begins'=>count($promptsDuringPeerReadOnlyBegins),
    'npc_effect_locks'=>count($promptsDuringPeerNpcLocks),
    'player_effect_locks'=>count($promptsDuringPeerPlayerLocks),
    'eventlog_writer_locks'=>count($promptsDuringPeerEventlogLocks),
    'advisory_writer_locks'=>count($promptsDuringPeerAdvisoryLocks),
], JSON_UNESCAPED_SLASHES).PHP_EOL;
storeRecoveryPgCheck(
    $queuedPromptDuringPeer === '' && $exposedPromptDuringPeer === $deliveredPrompt,
    'queued_and_exposed_prompts_must_remain_available_during_peer_uncommitted_event_insert'
);
storeRecoveryPgCheck(count($promptsDuringPeerReadOnlyBegins) === 2, 'queued_and_exposed_prompts_must_use_repeatable_read_snapshots_during_peer_insert');
storeRecoveryPgCheck($promptsDuringPeerElapsed < 1.5, 'queued_and_exposed_prompts_must_not_wait_for_peer_eventlog_insert');
storeRecoveryPgCheck(count($promptsDuringPeerNpcLocks) === 0, 'read_only_prompts_must_not_take_actor_effect_locks_during_peer_insert');
storeRecoveryPgCheck(count($promptsDuringPeerPlayerLocks) === 0, 'read_only_prompts_must_not_take_player_effect_locks_during_peer_insert');
storeRecoveryPgCheck(count($promptsDuringPeerEventlogLocks) === 0, 'read_only_prompts_must_not_take_eventlog_writer_lock_during_peer_insert');
storeRecoveryPgCheck(count($promptsDuringPeerAdvisoryLocks) === 0, 'read_only_prompts_must_not_take_advisory_writer_lock_during_peer_insert');

$listLogStart = storeRecoveryPgLogOffset($connection, $logFile);
$listStarted = microtime(true);
$listResult = null;
$listFailure = null;
try {
    $listResult = $service->list();
} catch (Throwable $error) {
    $listFailure = $error->getMessage();
}
$listElapsed = microtime(true) - $listStarted;
$listQueries = storeRecoveryPgLoggedQueries(storeRecoveryPgReadLogSince($connection, ['file'=>$logFile,'offset'=>$listLogStart]), $logContext['marker']);
$listNpcQueries = storeRecoveryPgQueryCount($listQueries, 'public.core_npc_master');
$listPlayerQueries = storeRecoveryPgQueryCount($listQueries, 'public.core_player');
echo 'READ_LIST_PEER_INSERT='.json_encode([
    'elapsed_seconds'=>$listElapsed,
    'returned_rows'=>is_array($listResult)?count($listResult):null,
    'failure'=>$listFailure,
    'npc_catalog_queries'=>$listNpcQueries,
    'player_name_queries'=>$listPlayerQueries,
], JSON_UNESCAPED_SLASHES).PHP_EOL;
storeRecoveryPgCheck(is_array($listResult) && count($listResult) === 51, 'read_only_list_must_not_wait_for_peer_eventlog_insert');
storeRecoveryPgCheck($listElapsed < 1.5, 'read_only_list_must_not_take_the_eventlog_writer_lock_timeout');
storeRecoveryPgCheck($listNpcQueries === 1, 'npc_cache_must_reset_and_load_once_in_the_next_transaction');
storeRecoveryPgCheck($listPlayerQueries === 1, 'player_cache_must_reset_and_load_once_in_the_next_transaction');

storeRecoveryPgRequire(@pg_query($peer, 'COMMIT') !== false, 'peer_transaction_commit_failed');
$documentCountBeforeStale = (int)(storeRecoveryPgSql($connection, 'SELECT count(*)::int AS n FROM plugins.correspondence_documents')[0]['n'] ?? -1);
$peerEventsBeforeStale = (int)(storeRecoveryPgSql(
    $connection,
    "SELECT count(*)::int AS n FROM public.eventlog WHERE type='info' AND sess='correspondence_v1'"
)[0]['n'] ?? -1);
storeRecoveryPgCheck($documentCountBeforeStale === 51, 'fixture_must_contain_fifty_queued_letters_and_one_delivered_letter');
storeRecoveryPgCheck($peerEventsBeforeStale === 53, 'fixture_must_retain_all_fifty_queued_and_three_delivered_anchors');
$staleReadRejected = false;
try {
    $service->list();
} catch (DomainException $error) {
    $staleReadRejected = $error->getMessage() === 'stale_request_clock';
}
storeRecoveryPgCheck($staleReadRejected, 'read_transaction_must_reject_a_newer_committed_eventlog_clock');

$staleWriteRejected = false;
try {
    $service->compose([
        'request_id'=>correspondenceUuid(),
        'author'=>'player',
        'payer'=>'player',
        'recipient'=>'npc:900001',
        'claimed_sender'=>'npc:900002',
        'body'=>'This stale request must not persist.',
        'attachments'=>[],
    ]);
} catch (DomainException $error) {
    $staleWriteRejected = $error->getMessage() === 'stale_request_clock';
}
storeRecoveryPgCheck($staleWriteRejected, 'writer_must_retain_request_clock_max_ts_guard_after_read_lock_split');
$documentCount = storeRecoveryPgSql($connection, 'SELECT count(*)::int AS n FROM plugins.correspondence_documents')[0]['n'] ?? null;
storeRecoveryPgCheck((int)$documentCount === $documentCountBeforeStale, 'stale_request_must_not_persist_a_new_document');

$peerEvents = storeRecoveryPgSql(
    $connection,
    "SELECT count(*)::int AS n FROM public.eventlog WHERE type='info' AND sess='correspondence_v1'"
)[0]['n'] ?? null;
storeRecoveryPgCheck((int)$peerEvents === $peerEventsBeforeStale, 'stale_read_and_write_paths_must_not_append_correspondence_events');

$raceStore = new CorrespondencePgStore(
    $connection,
    static fn($unused) => null,
    static fn($title, $body) => true
);
$raceStore->bindRequestClock(['inputtext','101001','101001']);
$raceService = new CorrespondenceService($raceStore);
$raceInput = [
    'request_id'=>correspondenceUuid(),
    'author'=>'player',
    'payer'=>'player',
    'recipient'=>'npc:900001',
    'claimed_sender'=>'npc:900002',
    'body'=>'Peer rename must prevent written-evidence exposure.',
    'attachments'=>[],
];
$raceSummary = $raceService->compose($raceInput);
$raceDocument = $raceStore->loadDocument($raceSummary['document_id']);
$raceOperationId = correspondenceUuid();
$raceReceiptId = correspondenceUuid();
$raceScopeToken = correspondenceUuid();
$raceStore->transaction(function () use ($raceStore, $raceDocument, $raceOperationId, $raceReceiptId, $raceScopeToken): void {
    $dispatchAnchor = $raceStore->appendEvent(
        'correspondence_dispatch',
        correspondenceJson(['document_id'=>$raceDocument['document_id'],'operation_id'=>$raceOperationId]),
        '',
        'correspondence_v1'
    );
    $receiptAnchor = $raceStore->appendEvent(
        'correspondence_receipt_confirmed',
        correspondenceJson(['document_id'=>$raceDocument['document_id'],'operation_id'=>$raceOperationId,'receipt_hash'=>str_repeat('a', 64)]),
        '',
        'correspondence_v1'
    );
    $raceStore->saveOperation([
        'document_id'=>$raceDocument['document_id'],
        'operation_id'=>$raceOperationId,
        'receipt_id'=>$raceReceiptId,
        'scope_token'=>$raceScopeToken,
        'nonce'=>bin2hex(random_bytes(32)),
        'dispatch_anchor'=>$dispatchAnchor,
        'receipt'=>['status'=>'delivered'],
        'receipt_anchor'=>$receiptAnchor,
        'source'=>null,
    ]);
    $raceDocument['operation_id']=$raceOperationId;
    $raceStore->saveDocument($raceDocument);
});

$racePrompt = null;
$raceSourceCount = null;
$raceStore->transaction(function () use ($raceStore, $raceService, $connection, $peer, $raceSummary, &$racePrompt, &$raceSourceCount): void {
    $raceStore->actors();
    $renamed = storeRecoveryPgSql(
        $peer,
        'UPDATE public.core_npc_master SET npc_name=$1 WHERE id=$2 RETURNING id',
        ['NPC 0001 renamed during prompt','900001']
    );
    storeRecoveryPgRequire(count($renamed) === 1, 'peer_actor_rename_after_catalog_cache_failed');
    $racePrompt = $raceService->prompt('NPC 0001');
    $sources = storeRecoveryPgSql(
        $connection,
        "SELECT count(*)::int AS n FROM public.eventlog WHERE sess='written_evidence_v1' AND position($1 in data)>0",
        [$raceSummary['document_id']]
    );
    $raceSourceCount = (int)($sources[0]['n'] ?? -1);
});
storeRecoveryPgSql($peer, 'UPDATE public.core_npc_master SET npc_name=$1 WHERE id=$2', [$targetName,'900001']);
echo 'PROMPT_IDENTITY_RECHECK='.json_encode([
    'prompt_empty'=>$racePrompt === '',
    'written_source_count'=>$raceSourceCount,
], JSON_UNESCAPED_SLASHES).PHP_EOL;
storeRecoveryPgCheck(
    $racePrompt === '' && $raceSourceCount === 0,
    'prompt_must_reject_peer_actor_rename_after_catalog_cache_without_appending_written_evidence'
);

$lockStore = new CorrespondencePgStore(
    $connection,
    static fn($unused) => null,
    static fn($title, $body) => true
);
$lockStore->bindRequestClock(['inputtext','101001','101001']);
$outsideLockRejected = false;
try {
    $lockStore->lockActorRows([900001]);
} catch (LogicException $error) {
    $outsideLockRejected = $error->getMessage() === 'effect_identity_lock_requires_writer';
} catch (Throwable) {
}
storeRecoveryPgCheck($outsideLockRejected, 'effect_identity_lock_must_require_an_active_writer_transaction');

$readOnlyLockRejected = false;
try {
    $lockStore->readTransaction(fn() => $lockStore->lockActorRows([900001]));
} catch (LogicException $error) {
    $readOnlyLockRejected = $error->getMessage() === 'effect_identity_lock_requires_writer';
} catch (Throwable) {
}
storeRecoveryPgCheck($readOnlyLockRejected, 'effect_identity_lock_must_reject_read_only_transactions');

$effectRowLockHeld = false;
try {
    $lockStore->transaction(function () use ($lockStore, $peer, $targetName, &$effectRowLockHeld): void {
        $locked = $lockStore->lockActorRows([900001]);
        $name = $locked['actors'][900001]['npc_name'] ?? null;
        storeRecoveryPgSql($peer, "SELECT set_config('lock_timeout','100ms',FALSE)");
        $rename = @pg_query_params(
            $peer,
            'UPDATE public.core_npc_master SET npc_name=$1 WHERE id=$2 RETURNING id',
            ['NPC 0001 concurrent rename','900001']
        );
        $lockError = $rename === false ? pg_last_error($peer) : '';
        if ($rename !== false) pg_free_result($rename);
        $effectRowLockHeld = $name === $targetName && $rename === false && stripos($lockError, 'lock timeout') !== false;
        storeRecoveryPgSql($peer, "SELECT set_config('lock_timeout','0',FALSE)");
    });
} catch (Throwable) {
    $effectRowLockHeld = false;
}
$renameAfterCommit = storeRecoveryPgSql(
    $peer,
    'UPDATE public.core_npc_master SET npc_name=$1 WHERE id=$2 RETURNING id',
    ['NPC 0001 lock probe','900001']
);
$lockReleasedAtCommit = count($renameAfterCommit) === 1;
storeRecoveryPgSql($peer, 'UPDATE public.core_npc_master SET npc_name=$1 WHERE id=$2', [$targetName,'900001']);
storeRecoveryPgCheck(
    $effectRowLockHeld && $lockReleasedAtCommit,
    'effect_identity_row_lock_must_block_peer_rename_until_writer_commit'
);

$savepointFreshName = null;
$savepointRename = 'NPC 0001 renamed after savepoint rollback';
$lockStore->transaction(function () use ($lockStore, $connection, $peer, $targetName, $savepointRename, &$savepointFreshName): void {
    storeRecoveryPgSql($connection, 'SAVEPOINT actor_identity_lock_probe');
    $beforeRollback = $lockStore->lockActorRows([900001]);
    storeRecoveryPgRequire(
        ($beforeRollback['actors'][900001]['npc_name'] ?? null) === $targetName,
        'savepoint_lock_probe_initial_name_mismatch'
    );
    storeRecoveryPgSql($connection, 'ROLLBACK TO SAVEPOINT actor_identity_lock_probe');
    $renamed = storeRecoveryPgSql(
        $peer,
        'UPDATE public.core_npc_master SET npc_name=$1 WHERE id=$2 RETURNING id',
        [$savepointRename,'900001']
    );
    storeRecoveryPgRequire(count($renamed) === 1, 'savepoint_rollback_must_release_targeted_identity_lock');
    $afterRollback = $lockStore->lockActorRows([900001]);
    $savepointFreshName = $afterRollback['actors'][900001]['npc_name'] ?? null;
});
storeRecoveryPgSql($peer, 'UPDATE public.core_npc_master SET npc_name=$1 WHERE id=$2', [$targetName,'900001']);
storeRecoveryPgCheck(
    $savepointFreshName === $savepointRename,
    'effect_identity_lock_must_refetch_after_savepoint_rollback_releases_prior_row_lock'
);

pg_close($peer);
pg_close($connection);
if ($storeRecoveryPgFailures !== []) {
    throw new RuntimeException('store_recovery_pg_red: '.implode('; ', $storeRecoveryPgFailures));
}
echo "PASS store recovery PG: bounded prompt query, transaction-local catalog cache, nonblocking read list and stale read/write guards\n";
