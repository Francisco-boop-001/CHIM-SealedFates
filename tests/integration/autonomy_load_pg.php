<?php
declare(strict_types=1);

function loadCheck(bool $ok, string $reason): void
{
    if (!$ok) throw new RuntimeException($reason);
}

function loadSql(string $sql, array $parameters = []): array
{
    global $loadConnection;
    $result = @pg_query_params($loadConnection, $sql, $parameters);
    loadCheck($result !== false, 'load_fixture_sql');
    try { return pg_num_fields($result) > 0 ? (pg_fetch_all($result) ?: []) : []; }
    finally { pg_free_result($result); }
}

function loadBindClock(CorrespondencePgStore $store, int &$ts, int $gamets): void
{
    $ts++;
    $store->bindRequestClock(['inputtext', (string)$ts, (string)$gamets]);
}

function loadKeysAre(array $value, array $expected): bool
{
    $keys = array_keys($value);
    sort($keys);
    sort($expected);
    return $keys === $expected;
}

function loadCodepoints(string $value): int
{
    $count = preg_match_all('/./us', $value);
    return $count === false ? strlen($value) : $count;
}

function loadPercentile(array $values, float $fraction): int
{
    sort($values, SORT_NUMERIC);
    $position = max(0, (int)ceil(count($values) * $fraction) - 1);
    return (int)($values[$position] ?? 0);
}

function loadDistribution(array $values): array
{
    sort($values, SORT_NUMERIC);
    return [
        'count' => count($values),
        'min' => (int)($values[0] ?? 0),
        'p50' => loadPercentile($values, 0.50),
        'p95' => loadPercentile($values, 0.95),
        'max' => (int)($values[array_key_last($values) ?? 0] ?? 0),
    ];
}

loadCheck(PHP_SAPI === 'cli' && getenv('WSL_DISTRO_NAME') === 'DwemerAI4Skyrim3-test', 'clone_cli_only');
foreach (getenv() as $name => $_) loadCheck(!str_starts_with(strtoupper((string)$name), 'PG'), 'postgres_environment_forbidden');
loadCheck(function_exists('pg_connect') && defined('PGSQL_CONNECT_FORCE_NEW'), 'pgsql_extension_required');

$loadConnection = @pg_connect('host=/var/run/postgresql dbname=chim_correspondence_autonomy_load_it user=postgres', PGSQL_CONNECT_FORCE_NEW);
loadCheck($loadConnection !== false, 'socket_database_unavailable');
try {
    $identity = loadSql("SELECT current_database() AS db,current_user AS role,current_setting('listen_addresses') AS listeners,(inet_server_addr() IS NULL)::int AS local")[0] ?? [];
    loadCheck($identity === ['db' => 'chim_correspondence_autonomy_load_it', 'role' => 'postgres', 'listeners' => '', 'local' => '1'], 'isolated_peer_db_only');
    loadCheck(loadSql("SELECT table_name FROM information_schema.tables WHERE table_schema IN ('public','plugins','chim_meta')") === [], 'blank_owned_db_required');

    $ddl = <<<'SQL'
CREATE TABLE public.eventlog(rowid bigserial PRIMARY KEY,ts bigint,gamets bigint,type text,data text,sess text,localts bigint,people text,location text,party text);
CREATE TABLE public.core_npc_master(id bigint PRIMARY KEY,npc_name text,refid text,profile_id bigint,personality text,extended_data jsonb,metadata jsonb);
CREATE TABLE public.core_player(id text PRIMARY KEY,value text);
CREATE TABLE public.game_plugins(plugin_name text,formid_prefix text);
CREATE TABLE public.books(id bigserial PRIMARY KEY,ts bigint,gamets bigint,content text,sess text,localts bigint,title text);
CREATE TABLE public.responselog(id bigserial PRIMARY KEY,localts bigint,sent integer,actor text,text text,action text,tag text);
INSERT INTO public.core_player VALUES('player_name','Dragonborn');
INSERT INTO public.game_plugins VALUES('Skyrim.esm','00');
SQL;
    loadCheck(@pg_query($loadConnection, $ddl) !== false, 'load_fixture_ddl');

    $npcCount = 100;
    $seedGamets = 1000000;
    $ts = 1700000000;
    loadSql("INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES($1,$2,'inputtext','Dragonborn: load fixture','','1700000000','','','')", [(string)$ts, (string)$seedGamets]);

    $expectedByDocument = [];
    $corePersonalities = [];
    $privateSentinels = [];
    for ($index = 1; $index <= $npcCount; $index++) {
        $id = 900000 + $index;
        $suffix = sprintf('%03d', $index);
        $name = 'Load Actor '.$suffix;
        $personality = 'Patient and practical. CORE_PERSONALITY_LOAD_ACTOR_'.$suffix.'.';
        $corruptPersonality = 'CORRUPTED_EXT_PERSONALITY_LOAD_ACTOR_'.$suffix;
        $privateField = 'PRIVATE_EXTENSION_LOAD_ACTOR_'.$suffix;
        $extended = json_encode([
            'background_life_enabled' => true,
            'background_life_letters' => true,
            'personality' => $corruptPersonality,
            'ordinary_private_notes' => $privateField,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $metadata = json_encode(['last_coords' => [0, 0, 0, 'Riverwood']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $refid = sprintf('%08X', 0x00020000 + $index);
        loadSql('INSERT INTO public.core_npc_master(id,npc_name,refid,profile_id,personality,extended_data,metadata) VALUES($1,$2,$3,1,$4,$5::jsonb,$6::jsonb)', [
            (string)$id, $name, $refid, $personality, $extended, $metadata,
        ]);
        $corePersonalities[$id] = $personality;
        $privateSentinels[$id] = [$corruptPersonality, $privateField];
    }

    $project = dirname(__DIR__, 2);
    require_once $project.'/server/lib/store.php';
    require_once $project.'/server/lib/autonomy_policy.php';
    require_once $project.'/server/lib/autonomy_store.php';

    $rendererCalls = 0;
    $store = new CorrespondencePgStore(
        $loadConnection,
        static fn(CorrespondencePgStore $unused): string => 'Load Actor 001',
        static function (string $title, string $body) use (&$rendererCalls): bool { $rendererCalls++; return true; }
    );
    $store->ensureSchema();
    $service = new CorrespondenceService($store);
    $autonomy = new CorrespondenceAutonomyPg($store, $service);
    $autonomy->ensureSchema();

    for ($index = 1; $index <= $npcCount; $index++) {
        $id = 900000 + $index;
        $suffix = sprintf('%03d', $index);
        $name = 'Load Actor '.$suffix;
        $body = 'Original exposed letter for '.$name.'. Source '.$suffix.'.';

        loadBindClock($store, $ts, $seedGamets);
        $document = $service->compose([
            'request_id' => correspondenceUuid(),
            'author' => 'player',
            'payer' => 'player',
            'recipient' => 'npc:'.$id,
            'claimed_sender' => 'player',
            'body' => $body,
            'attachments' => [],
        ]);
        loadCheck(($document['state'] ?? null) === 'queued', 'incoming_compose_failed');

        loadBindClock($store, $ts, $seedGamets);
        $dispatch = $service->dispatch($document['document_id']);
        loadCheck(($dispatch['state'] ?? null) === 'dispatched' && is_string($dispatch['operation_id'] ?? null), 'incoming_dispatch_failed');
        $operation = $store->loadOperation($dispatch['operation_id']);
        loadCheck(is_array($operation), 'incoming_operation_unavailable');
        $receipt = array_intersect_key($operation, array_flip(['protocol_version','document_id','operation_id','receipt_id','playthrough_id','scope_token','nonce']));
        $receipt += ['status' => 'delivered', 'reason' => 'physical_only', 'note_form_id' => 12345, 'attachments' => []];

        loadBindClock($store, $ts, $seedGamets);
        $received = $service->receive($receipt);
        loadCheck(($received['state'] ?? null) === 'delivered', 'synthetic_receipt_failed');

        loadBindClock($store, $ts, $seedGamets);
        $prompt = $service->prompt($name);
        loadCheck(str_contains($prompt, $body) && count($service->sourceReferences) === 1, 'normal_recipient_exposure_failed');
        $sourceEventId = $service->sourceReferences[0];
        loadCheck(in_array($sourceEventId, $service->knownSourceReferences, true), 'exposed_source_not_known_to_recipient');
        $expectedByDocument[$document['document_id']] = [
            'id' => $id,
            'name' => $name,
            'personality' => $corePersonalities[$id],
            'body' => $body,
            'source_event_id' => $sourceEventId,
        ];
    }
    loadCheck(count($expectedByDocument) === $npcCount && $rendererCalls === $npcCount, 'source_fixture_count');
    $seedSourceEventIds = array_column(array_values($expectedByDocument), 'source_event_id');
    loadCheck(count(array_unique($seedSourceEventIds)) === $npcCount, 'unique_normal_source_events');

    $hourGamets = correspondenceAutonomyGamets(1);
    $gamets = $seedGamets + correspondenceAutonomyGamets(2);
    loadBindClock($store, $ts, $gamets);
    $autonomy->setEnabled(false);
    $disabledCallbackCalls = 0;
    $disabled = $autonomy->tick(static function () use (&$disabledCallbackCalls): string { $disabledCallbackCalls++; return ''; });
    loadCheck(($disabled['status'] ?? null) === 'idle' && ($disabled['reason'] ?? null) === 'disabled' && ($disabled['model_calls'] ?? null) === 0 && $disabledCallbackCalls === 0, 'disabled_tick_must_not_call_model');

    loadBindClock($store, $ts, $gamets);
    $autonomy->setEnabled(true);
    $sourceCalls = [];
    $callbackInputChars = [];
    $callbackFailure = null;
    $modelOutput = '{"action":"none","recipient":null,"body":"","reason":"No useful response yet."}';
    $requestModel = static function (array $messages) use (&$sourceCalls, &$callbackInputChars, &$callbackFailure, $expectedByDocument, $corePersonalities, $privateSentinels, $modelOutput): string {
        try {
            loadCheck(count($messages) === 2 && ($messages[0]['role'] ?? null) === 'system' && ($messages[1]['role'] ?? null) === 'user', 'model_message_shape');
            $inputChars = 0;
            $serializedMessages = '';
            foreach ($messages as $message) foreach ($message as $value) if (is_string($value)) { $inputChars += loadCodepoints($value); $serializedMessages .= $value."\n"; }
            $callbackInputChars[] = $inputChars;
            $payload = json_decode($messages[1]['content'], true, 64, JSON_THROW_ON_ERROR);
            loadCheck(is_array($payload) && loadKeysAre($payload, ['source','self','contacts']), 'model_payload_keys');
            loadCheck(is_array($payload['source']) && loadKeysAre($payload['source'], ['schema_version','document_id','playthrough_id','recipient','claimed_sender','body','receipt','attachments']), 'exposed_source_shape');
            $documentId = $payload['source']['document_id'] ?? null;
            loadCheck(is_string($documentId) && isset($expectedByDocument[$documentId]), 'source_must_be_one_seeded_original');
            $expected = $expectedByDocument[$documentId];
            loadCheck(($payload['source']['recipient']['kind'] ?? null) === 'npc' && ($payload['source']['recipient']['id'] ?? null) === $expected['id'], 'source_recipient_scope');
            loadCheck(($payload['source']['claimed_sender']['kind'] ?? null) === 'player' && ($payload['source']['body'] ?? null) === $expected['body'], 'source_sender_or_body');
            loadCheck(($payload['source']['receipt']['status'] ?? null) === 'confirmed' && ($payload['source']['receipt']['exposure'] ?? null) === 'context', 'source_must_be_normal_context_exposure');
            loadCheck(is_array($payload['self']) && loadKeysAre($payload['self'], ['key','name','personality','relationships']), 'self_context_shape');
            loadCheck(($payload['self']['key'] ?? null) === 'npc:'.$expected['id'] && ($payload['self']['name'] ?? null) === $expected['name'], 'self_must_match_recipient');
            loadCheck(($payload['self']['personality'] ?? null) === $expected['personality'] && str_contains($messages[1]['content'], $expected['personality']), 'core_personality_must_be_used');
            loadCheck(($payload['self']['relationships'] ?? null) === [], 'only_self_relationship_projection');
            loadCheck(is_array($payload['contacts']) && array_is_list($payload['contacts']) && count($payload['contacts']) === 32, 'bounded_contact_roster');
            $contactKeys = [];
            foreach ($payload['contacts'] as $contact) {
                loadCheck(is_array($contact) && loadKeysAre($contact, ['key','name']) && is_string($contact['key'] ?? null) && is_string($contact['name'] ?? null), 'contact_context_shape');
                $contactKeys[] = $contact['key'];
            }
            loadCheck(count(array_unique($contactKeys)) === 32 && !in_array($payload['self']['key'], $contactKeys, true) && $contactKeys[0] === 'player', 'bounded_known_contacts');
            loadCheck(!array_key_exists('author', $payload['source']) && !array_key_exists('payer', $payload['source']), 'hidden_author_or_payer_not_exposed');
            foreach ($corePersonalities as $id => $personality) if ($id !== $expected['id']) loadCheck(!str_contains($serializedMessages, $personality), 'other_npc_personality_leak');
            foreach ($privateSentinels as [$corruptPersonality, $privateField]) {
                loadCheck(!str_contains($serializedMessages, $corruptPersonality), 'extended_personality_must_not_be_used');
                loadCheck(!str_contains($serializedMessages, $privateField), 'private_extension_field_must_not_be_used');
            }
            $sourceCalls[] = $documentId;
            return $modelOutput;
        } catch (Throwable $error) {
            $callbackFailure = $error;
            throw $error;
        }
    };

    $metrics = [];
    $maxCallsPerTick = 0;
    $runTick = static function (int $atGamets) use ($autonomy, $store, &$ts, &$metrics, &$maxCallsPerTick, &$callbackFailure, &$callbackInputChars, $requestModel, $modelOutput): array {
        loadBindClock($store, $ts, $atGamets);
        $result = $autonomy->tick($requestModel);
        loadCheck(is_int($result['model_calls'] ?? null) && $result['model_calls'] >= 0 && $result['model_calls'] <= 1, 'tick_model_call_bound');
        loadCheck(is_int($result['input_chars'] ?? null) && is_int($result['output_chars'] ?? null) && is_int($result['elapsed_ms'] ?? null) && $result['elapsed_ms'] >= 0, 'tick_metrics_shape');
        $maxCallsPerTick = max($maxCallsPerTick, $result['model_calls']);
        if ($callbackFailure !== null) throw new RuntimeException('model_context_assertion_failed', 0, $callbackFailure);
        if ($result['model_calls'] === 1) {
            loadCheck($result['input_chars'] === $callbackInputChars[array_key_last($callbackInputChars)] && $result['output_chars'] === loadCodepoints($modelOutput), 'tick_actual_character_metrics');
            $metrics[] = ['input_chars' => $result['input_chars'], 'output_chars' => $result['output_chars'], 'elapsed_ms' => $result['elapsed_ms']];
        } else {
            loadCheck($result['input_chars'] === 0 && $result['output_chars'] === 0, 'idle_tick_character_metrics');
        }
        return $result;
    };

    $first = $runTick($gamets);
    loadCheck(($first['status'] ?? null) === 'silent' && ($first['reason'] ?? null) === 'silence' && ($first['model_calls'] ?? null) === 1 && count($sourceCalls) === 1, 'first_enabled_tick_must_make_one_silence_decision');
    $sameClockTicks = 0;
    for ($index = 0; $index < 100; $index++) {
        $sameClockTicks++;
        $result = $runTick($gamets);
        loadCheck(($result['model_calls'] ?? null) === 0 && ($result['reason'] ?? null) === 'cooldown', 'same_game_clock_global_cooldown');
    }
    loadCheck(count($sourceCalls) === 1, 'same_clock_must_not_repeat_model_call');

    $hourSteps = 0;
    while (count($sourceCalls) < $npcCount) {
        $gamets += $hourGamets;
        $hourSteps++;
        $result = $runTick($gamets);
        loadCheck(($result['status'] ?? null) === 'silent' && ($result['reason'] ?? null) === 'silence' && ($result['model_calls'] ?? null) === 1, 'one_hour_tick_must_process_one_original');
        loadCheck(count($sourceCalls) === count(array_unique($sourceCalls)), 'source_claim_must_be_unique');
        loadCheck($hourSteps <= $npcCount - 1, 'hourly_scheduler_progress_bound');
    }
    loadCheck($hourSteps === 99 && count($metrics) === $npcCount && $maxCallsPerTick === 1, 'load_progress_and_per_tick_budget');
    $expectedDocuments = array_keys($expectedByDocument);
    $actualDocuments = array_values(array_unique($sourceCalls));
    sort($expectedDocuments);
    sort($actualDocuments);
    loadCheck($actualDocuments === $expectedDocuments, 'every_original_source_once');

    $gamets += $hourGamets;
    $afterCompletion = $runTick($gamets);
    loadCheck(($afterCompletion['model_calls'] ?? null) === 0 && ($afterCompletion['reason'] ?? null) === 'no_eligible_source', 'completed_sources_must_not_retry');

    // Verify durable scheduler outcomes through ordinary public eventlog anchors, not private scheduler tables.
    $completionRows = loadSql("SELECT data::text AS data FROM public.eventlog WHERE type='info' AND sess='correspondence_v1' AND data::jsonb->>'kind'='autonomy_completion' ORDER BY rowid");
    loadCheck(count($completionRows) === $npcCount, 'one_public_completion_anchor_per_source');
    $completedDocuments = [];
    foreach ($completionRows as $row) {
        $completion = json_decode($row['data'], true, 32, JSON_THROW_ON_ERROR);
        loadCheck(($completion['completed_status'] ?? null) === 'silent' && ($completion['completed_action'] ?? null) === 'none', 'durable_silence_completion');
        $documentId = $completion['source_document_id'] ?? null;
        loadCheck(is_string($documentId) && isset($expectedByDocument[$documentId]) && !isset($completedDocuments[$documentId]), 'durable_source_identity_unique');
        loadCheck(($completion['source_event_id'] ?? null) === (string)$expectedByDocument[$documentId]['source_event_id'], 'durable_source_event_matches_exposed_source');
        $completedDocuments[$documentId] = true;
    }
    loadCheck(count($completedDocuments) === $npcCount, 'all_sources_persisted_once');

    $documents = $service->list();
    loadCheck(count($documents) === $npcCount && count(array_filter($documents, static fn($row) => ($row['state'] ?? null) === 'exposed')) === $npcCount, 'only_original_exposed_documents');
    loadCheck($autonomy->managedChildren() === [], 'silence_creates_no_managed_children');
    $status = $autonomy->status();
    loadCheck(count($status) === 20 && count(array_unique(array_column($status, 'source_document_id'))) === 20, 'public_status_is_bounded_and_distinct');
    foreach ($status as $row) loadCheck(($row['status'] ?? null) === 'silent' && ($row['action'] ?? null) === 'none' && ($row['child_document_id'] ?? null) === null, 'public_status_silence_shape');
    $dispatchRows = loadSql("SELECT count(*)::int AS n FROM public.responselog WHERE concat(action,chr(124),text) LIKE 'command|ExtCmdCHIMCorrespondenceBridge_Deliver@%'");
    loadCheck((int)($dispatchRows[0]['n'] ?? 0) === $npcCount, 'no_autonomous_delivery_commands_from_silence');

    $inputValues = array_column($metrics, 'input_chars');
    $outputValues = array_column($metrics, 'output_chars');
    $elapsedValues = array_column($metrics, 'elapsed_ms');
    echo json_encode([
        'status' => 'PASS',
        'selected_npcs' => $npcCount,
        'normal_exposed_original_sources' => count($expectedByDocument),
        'disabled_model_calls' => $disabled['model_calls'],
        'first_enabled_tick_model_calls' => $first['model_calls'],
        'same_gamets_repeat_ticks' => $sameClockTicks,
        'same_gamets_additional_model_calls' => 0,
        'hourly_progress_ticks' => $hourSteps,
        'gamets_per_game_hour' => $hourGamets,
        'total_model_calls' => count($sourceCalls),
        'unique_original_sources_processed' => count($actualDocuments),
        'unique_normal_source_events' => count(array_unique($seedSourceEventIds)),
        'model_calls_per_tick_max' => $maxCallsPerTick,
        'input_chars' => ['total' => array_sum($inputValues)] + loadDistribution($inputValues),
        'output_chars' => ['total' => array_sum($outputValues)] + loadDistribution($outputValues),
        'elapsed_ms' => loadDistribution($elapsedValues),
        'public_completion_anchors' => count($completionRows),
        'post_completion_retry_model_calls' => $afterCompletion['model_calls'],
        'private_extension_and_other_personalities_exposed' => false,
        'autonomous_children_or_delivery_commands' => 0,
        'renderer_calls' => $rendererCalls,
        'synthetic_receipts' => true,
        'provider_calls' => 0,
        'tokens_unavailable' => true,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
} finally {
    pg_close($loadConnection);
}
