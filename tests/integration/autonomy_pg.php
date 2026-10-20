<?php
declare(strict_types=1);

// Guarded native regression. Root alone runs this in the owned blank clone database.
function autonomyPgCheck(bool $ok, string $reason): void
{
    if (!$ok) throw new RuntimeException($reason);
}

function autonomyPgQuery(PgSql\Connection $connection, string $sql, array $args = []): array
{
    $result = $args === [] ? pg_query($connection, $sql) : pg_query_params($connection, $sql, $args);
    autonomyPgCheck($result instanceof PgSql\Result, 'fixture_sql_failed');
    return pg_num_fields($result) > 0 ? (pg_fetch_all($result) ?: []) : [];
}

function autonomyPgReply(string $recipient): string
{
    return json_encode(['action'=>'reply','recipient'=>$recipient,'body'=>'I will explain my concern.','reason'=>'The sender asked for an explanation.'], JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}

function autonomyPgSilence(): string
{
    return json_encode(['action'=>'none','recipient'=>null,'body'=>'','reason'=>'I need more time.'], JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}

autonomyPgCheck(PHP_SAPI === 'cli' && extension_loaded('pgsql'), 'native_cli_pgsql_required');
autonomyPgCheck(getenv('WSL_DISTRO_NAME') === 'DwemerAI4Skyrim3-test', 'clone_only');
foreach (getenv() as $name => $_value) autonomyPgCheck(!str_starts_with(strtoupper((string)$name), 'PG'), 'postgres_environment_forbidden');

$connection = pg_connect('host=/var/run/postgresql dbname=chim_correspondence_autonomy_it user=postgres connect_timeout=3 application_name=correspondence_autonomy_it', PGSQL_CONNECT_FORCE_NEW);
autonomyPgCheck($connection instanceof PgSql\Connection, 'owned_peer_database_required');
$peer = pg_connect('host=/var/run/postgresql dbname=chim_correspondence_autonomy_it user=postgres connect_timeout=3 application_name=correspondence_autonomy_it_peer', PGSQL_CONNECT_FORCE_NEW);
autonomyPgCheck($peer instanceof PgSql\Connection, 'second_owned_peer_session_required');
$identity = autonomyPgQuery($connection, "SELECT current_database() db,current_user role,current_setting('listen_addresses') listeners,(inet_server_addr() IS NULL)::int socket_only")[0] ?? [];
autonomyPgCheck($identity === ['db'=>'chim_correspondence_autonomy_it','role'=>'postgres','listeners'=>'','socket_only'=>'1'], 'owned_socket_database_only');
$peerIdentity = autonomyPgQuery($peer, "SELECT current_database() db,current_user role,current_setting('listen_addresses') listeners,(inet_server_addr() IS NULL)::int socket_only")[0] ?? [];
autonomyPgCheck($peerIdentity === $identity, 'second_session_same_owned_socket_database');
$tables = autonomyPgQuery($connection, "SELECT count(*)::int amount FROM information_schema.tables WHERE table_schema IN ('public','plugins','chim_meta')")[0] ?? [];
autonomyPgCheck(($tables['amount'] ?? null) === '0', 'blank_owned_database_required');

$project = dirname(__DIR__, 2);
require_once $project.'/server/lib/store.php';
require_once $project.'/server/lib/boundaries.php';
require_once $project.'/server/lib/autonomy_policy.php';
require_once $project.'/server/lib/autonomy_store.php';

$ddl = <<<'SQL'
CREATE TABLE public.eventlog(rowid bigserial PRIMARY KEY,ts bigint,gamets numeric,type text,data text,sess text,localts bigint,people text,location text,party text);
CREATE TABLE public.core_npc_master(id bigint PRIMARY KEY,npc_name text,refid text,profile_id bigint,personality text,extended_data jsonb,metadata jsonb);
CREATE TABLE public.core_player(id text PRIMARY KEY,value text);
CREATE TABLE public.game_plugins(plugin_name text,formid_prefix text);
CREATE TABLE public.books(id bigserial PRIMARY KEY,ts bigint,gamets numeric,content text,sess text,localts bigint,title text);
CREATE TABLE public.responselog(id bigserial PRIMARY KEY,localts bigint,sent integer,actor text,text text,action text,tag text);
INSERT INTO public.core_player VALUES('player_name','Dragonborn');
INSERT INTO public.game_plugins VALUES('Skyrim.esm','00');
INSERT INTO public.core_npc_master VALUES
 (900001,'Camilla','00013488',1,'Proud and thoughtful.','{"background_life_enabled":true,"background_life_letters":true}','{"last_coords":[0,0,0,"Riverwood"]}'),
 (900002,'Jarl Balgruuf','00013BBD',2,'Reserved.','{"background_life_enabled":true,"background_life_letters":true}','{"last_coords":[0,0,0,"Riverwood"]}'),
 (900003,'Lydia','000A2C94',3,'Practical.','{"background_life_enabled":true,"background_life_letters":true}','{}');
INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(100,100,'inputtext','native autonomy fixture','',100,'','','');
SQL;
autonomyPgQuery($connection, $ddl);

$store = new CorrespondencePgStore($connection, static fn($store) => $store->observedExecutor(), static fn($title, $body) => true);
$store->ensureSchema();
$store->bindRequestClock(['inputtext','100','100','synthetic native fixture']);
$service = new CorrespondenceService($store);
$autonomy = new CorrespondenceAutonomyPg($store, $service);
$autonomy->ensureSchema();
$autonomy->setEnabled(true);
$presence = base64_encode(correspondenceJson(['source'=>'plugin_player_routing_v2','present_actors'=>[['form_id'=>hexdec('000A2C94'),'name'=>'Lydia','distance'=>10,'managed'=>true,'creature'=>false]]]));
$store->observeManaged($presence);

$makeExposedSource = static function (string $body, string $recipient='npc:900001', string $sender='npc:900002') use ($store, $service, $presence): string {
    $store->observeManaged($presence);
    $document = $service->compose([
        'request_id'=>correspondenceUuid(), 'author'=>'player', 'payer'=>'player', 'recipient'=>$recipient,
        'claimed_sender'=>$sender, 'body'=>$body, 'attachments'=>[],
    ]);
    $sent = $service->dispatch($document['document_id']);
    $operation = $store->loadOperation($sent['operation_id']);
    autonomyPgCheck(is_array($operation), 'incoming_operation_missing');
    $receipt = array_intersect_key($operation, array_flip(['protocol_version','document_id','operation_id','receipt_id','playthrough_id','scope_token','nonce']));
    $receipt += ['status'=>'delivered','reason'=>'physical_only','note_form_id'=>12345,'attachments'=>[]];
    $service->receive($receipt);
    $recipientName = match ($recipient) {
        'npc:900001' => 'Camilla', 'npc:900002' => 'Jarl Balgruuf', 'npc:900003' => 'Lydia',
        default => throw new RuntimeException('fixture_unknown_recipient'),
    };
    $service->prompt($recipientName);
    return $document['document_id'];
};

$sourceIds = [
    $makeExposedSource('First claim must stay a claim.'),
    $makeExposedSource('Second claim must stay a claim.'),
];
$store->bindRequestClock(['inputtext','200',(string)(100 + correspondenceAutonomyGamets(2)),'synthetic native fixture']);
$modelCalls = 0;
$outsideLockChecks = 0;
$duplicateClaimChecks = 0;
$claimSourceIds = [];
$lostClaim = static function (array $messages) use (&$modelCalls, &$outsideLockChecks, &$duplicateClaimChecks, &$claimSourceIds, $peer): string {
    $modelCalls++;
    $lock = autonomyPgQuery($peer, 'SELECT pg_try_advisory_lock(18459814,1)::text acquired')[0] ?? [];
    $acquired = ($lock['acquired'] ?? null) === 'true';
    autonomyPgCheck($acquired, 'model_callback_must_run_after_database_lock_release');
    try {
    $outsideLockChecks++;
    $row = autonomyPgQuery($peer, "SELECT playthrough_id,source_document_id::text source_id,source_event_id::text event_id,root_document_id::text root_id,payload->'claim_anchor'->>'rowid' rowid FROM plugins.correspondence_autonomy_decisions WHERE status='claimed' ORDER BY created_at,source_document_id LIMIT 1")[0] ?? [];
    autonomyPgCheck(preg_match('/\A[1-9][0-9]*\z/D', (string)($row['rowid'] ?? '')) === 1, 'claimed_anchor_missing');
    $claimSourceIds[] = $row['source_id'];
    $duplicate = autonomyPgQuery($peer, "INSERT INTO plugins.correspondence_autonomy_decisions(playthrough_id,source_document_id,source_event_id,claim_id,request_id,root_document_id,depth,status,action,payload) VALUES($1,$2,$3,$4,$4,$5,0,'claimed',NULL,'{}'::jsonb) ON CONFLICT DO NOTHING RETURNING claim_id::text", [$row['playthrough_id'],$row['source_id'],$row['event_id'],'66666666-6666-4666-8666-666666666666',$row['root_id']]);
    autonomyPgCheck($duplicate === [], 'second_session_must_not_claim_the_same_source');
    $duplicateClaimChecks++;
    autonomyPgQuery($peer, 'DELETE FROM public.eventlog WHERE rowid=$1', [$row['rowid']]);
    } finally {
        if ($acquired) {
            $unlocked = autonomyPgQuery($peer, 'SELECT pg_advisory_unlock(18459814,1)::text unlocked')[0] ?? [];
            autonomyPgCheck(($unlocked['unlocked'] ?? null) === 'true', 'peer_must_release_probe_lock');
        }
    }
    return 'not valid JSON';
};
$failurePath = $autonomy->tick($lostClaim);
$failedDecision = autonomyPgQuery($connection, 'SELECT source_document_id::text source_id,status,payload->>\'reason_code\' reason,child_document_id::text child,claim_id::text claim FROM plugins.correspondence_autonomy_decisions WHERE source_document_id=$1', [$claimSourceIds[0]])[0] ?? [];
autonomyPgCheck(($failedDecision['status'] ?? null) === 'stale' && ($failedDecision['reason'] ?? null) === 'claim_anchor_invalid', 'lost_claim_failure_must_close_stale');
autonomyPgCheck(($failedDecision['child'] ?? null) === null, 'lost_claim_failure_must_not_compose');
autonomyPgCheck((autonomyPgQuery($connection, "SELECT rowid FROM public.eventlog WHERE sess='correspondence_v1' AND data::jsonb->>'kind'='autonomy_completion' AND data::jsonb->>'claim_id'=$1", [$failedDecision['claim']]) === []), 'lost_claim_failure_must_not_write_completion');
autonomyPgCheck(($failurePath['status'] ?? null) === 'stale', 'lost_claim_failure_result_must_be_stale');

$store->bindRequestClock(['inputtext','300',(string)(100 + correspondenceAutonomyGamets(2) + 1),'synthetic native fixture']);
$successPath = $autonomy->tick(static function (array $messages) use (&$modelCalls, &$claimSourceIds, $connection): string {
    $modelCalls++;
    $row = autonomyPgQuery($connection, "SELECT source_document_id::text source_id,payload->'claim_anchor'->>'rowid' rowid FROM plugins.correspondence_autonomy_decisions WHERE status='claimed' ORDER BY created_at,source_document_id LIMIT 1")[0] ?? [];
    autonomyPgCheck(preg_match('/\A[1-9][0-9]*\z/D', (string)($row['rowid'] ?? '')) === 1, 'second_claimed_anchor_missing');
    $claimSourceIds[] = $row['source_id'];
    autonomyPgQuery($connection, 'DELETE FROM public.eventlog WHERE rowid=$1', [$row['rowid']]);
    return '{"action":"reply","recipient":"npc:900002","body":"I will explain my concern.","reason":"The sender asked for an explanation."}';
});
$secondDecision = autonomyPgQuery($connection, 'SELECT status,payload->>\'reason_code\' reason,child_document_id::text child FROM plugins.correspondence_autonomy_decisions WHERE source_document_id=$1', [$claimSourceIds[1]])[0] ?? [];
autonomyPgCheck(($secondDecision['status'] ?? null) === 'stale' && ($secondDecision['reason'] ?? null) === 'claim_anchor_invalid', 'lost_claim_success_must_close_stale');
autonomyPgCheck(($secondDecision['child'] ?? null) === null && ($successPath['status'] ?? null) === 'stale', 'lost_claim_success_must_not_compose');
autonomyPgCheck((autonomyPgQuery($connection, 'SELECT count(*)::int amount FROM plugins.correspondence_documents')[0]['amount'] ?? null) === '2', 'lost_claims_must_not_create_child_documents');
autonomyPgCheck((autonomyPgQuery($connection, "SELECT count(*)::int amount FROM public.eventlog WHERE sess='correspondence_v1' AND data::jsonb->>'kind'='autonomy_completion'")[0]['amount'] ?? null) === '0', 'lost_claims_must_not_create_completion_anchors');

$twoHours = correspondenceAutonomyGamets(2);
$sixHours = correspondenceAutonomyGamets(6);
$currentGamets = 100 + $twoHours + 1;
$currentTs = 300;
$setClock = static function (int $target) use (&$currentGamets, &$currentTs, $store): int {
    $currentGamets = max($currentGamets, $target);
    $currentTs++;
    $store->bindRequestClock(['inputtext',(string)$currentTs,(string)$currentGamets,'synthetic native fixture']);
    return $currentGamets;
};
$touchPresence = static function () use ($store, $presence): void { $store->observeManaged($presence); };
$runTick = static function (callable $callback) use ($touchPresence, $autonomy): array {
    $touchPresence();
    return $autonomy->tick($callback);
};
$claimedRow = static function (PgSql\Connection $connection): array {
    return autonomyPgQuery($connection, "SELECT source_document_id::text source_id,source_event_id::text event_id,payload->'claim_anchor'->>'rowid' claim_rowid FROM plugins.correspondence_autonomy_decisions WHERE status='claimed' ORDER BY created_at,source_document_id LIMIT 1")[0] ?? [];
};
$decisionFor = static function (string $sourceId) use ($connection): array {
    return autonomyPgQuery($connection, "SELECT source_document_id::text source_id,status,action,child_document_id::text child,due_gamets::text due,payload->>'reason_code' reason,payload->>'actor_cooldown_until_gamets' actor_cooldown,payload->>'depth' depth FROM plugins.correspondence_autonomy_decisions WHERE source_document_id=$1", [$sourceId])[0] ?? [];
};
$retryCalls = 0;
$retryConsumed = $runTick(static function (array $messages) use (&$retryCalls): string { $retryCalls++; return autonomyPgSilence(); });
autonomyPgCheck($retryCalls === 0 && ($retryConsumed['reason'] ?? null) === 'no_eligible_source', 'consumed_lost_claim_sources_must_not_retry');

// Rechecking after the model must reject changed source bytes without creating a child.
$sourceChangedId = $makeExposedSource('Source mutation is rechecked after the model.');
$sourceChangedAt = $currentGamets;
$setClock($sourceChangedAt + $twoHours);
$sourceChangedClaim = null;
$sourceChangedResult = $runTick(static function (array $messages) use (&$modelCalls, &$sourceChangedClaim, $connection, $claimedRow): string {
    $modelCalls++;
    $row = $claimedRow($connection);
    $sourceChangedClaim = $row['source_id'] ?? null;
    autonomyPgCheck(is_string($sourceChangedClaim), 'source_changed_claim_must_be_captured');
    autonomyPgQuery($connection, 'UPDATE public.eventlog SET data=data||\' \' WHERE rowid=$1', [$row['event_id']]);
    return autonomyPgReply('npc:900002');
});
$sourceChangedDecision = $decisionFor((string)$sourceChangedClaim);
autonomyPgCheck(($sourceChangedResult['status'] ?? null) === 'stale' && ($sourceChangedDecision['reason'] ?? null) === 'source_changed' && ($sourceChangedDecision['child'] ?? null) === null, 'changed_source_must_not_compose');
$decisionsBeforeSourceRetry = autonomyPgQuery($connection, 'SELECT count(*)::int amount FROM plugins.correspondence_autonomy_decisions')[0]['amount'] ?? null;
$retryCalls = 0;
$sourceRetry = $runTick(static function (array $messages) use (&$retryCalls): string { $retryCalls++; return autonomyPgReply('npc:900002'); });
$decisionsAfterSourceRetry = autonomyPgQuery($connection, 'SELECT count(*)::int amount FROM plugins.correspondence_autonomy_decisions')[0]['amount'] ?? null;
$sourceRetryDecision = $decisionFor((string)$sourceChangedClaim);
autonomyPgCheck($retryCalls === 0 && ($sourceRetry['model_calls'] ?? null) === 0 && $decisionsAfterSourceRetry === $decisionsBeforeSourceRetry
    && ($sourceRetryDecision['source_id'] ?? null) === $sourceChangedClaim && ($sourceRetryDecision['status'] ?? null) === 'stale'
    && ($sourceRetryDecision['child'] ?? null) === null,
    'changed_source_must_not_be_retried:'.json_encode(['tick'=>$sourceRetry,'calls'=>$retryCalls,'decisions_before'=>$decisionsBeforeSourceRetry,'decisions_after'=>$decisionsAfterSourceRetry,'decision'=>$sourceRetryDecision], JSON_THROW_ON_ERROR));

// Actor eligibility and NPC context are reread after the callback.
$actorChangedAt = $currentGamets;
$makeExposedSource('Actor eligibility is rechecked after the model.', 'npc:900002', 'npc:900001');
$setClock($actorChangedAt + $twoHours);
$actorChangedClaim = null;
$actorChangedResult = $runTick(static function (array $messages) use (&$modelCalls, &$actorChangedClaim, $connection, $claimedRow): string {
    $modelCalls++;
    $row = $claimedRow($connection);
    $actorChangedClaim = $row['source_id'] ?? null;
    autonomyPgCheck(is_string($actorChangedClaim), 'actor_changed_claim_must_be_captured');
    autonomyPgQuery($connection, "UPDATE public.core_npc_master SET extended_data=jsonb_set(extended_data,'{background_life_letters}','false'::jsonb) WHERE id=900002");
    return autonomyPgReply('npc:900001');
});
autonomyPgQuery($connection, "UPDATE public.core_npc_master SET extended_data=jsonb_set(extended_data,'{background_life_letters}','true'::jsonb) WHERE id=900002");
$actorChangedDecision = $decisionFor((string)$actorChangedClaim);
autonomyPgCheck(($actorChangedResult['status'] ?? null) === 'stale' && ($actorChangedDecision['reason'] ?? null) === 'actor_changed' && ($actorChangedDecision['child'] ?? null) === null, 'changed_actor_must_not_compose');

// A user setting change during generation invalidates the claim before composition.
$settingChangedAt = $currentGamets;
$makeExposedSource('Autonomy settings are rechecked after the model.', 'npc:900003', 'npc:900002');
$setClock($settingChangedAt + $twoHours);
$settingChangedClaim = null;
$settingChangedResult = $runTick(static function (array $messages) use (&$modelCalls, &$settingChangedClaim, $connection, $claimedRow, $autonomy): string {
    $modelCalls++;
    $row = $claimedRow($connection);
    $settingChangedClaim = $row['source_id'] ?? null;
    autonomyPgCheck(is_string($settingChangedClaim), 'setting_changed_claim_must_be_captured');
    $autonomy->setEnabled(false);
    return autonomyPgReply('npc:900002');
});
$autonomy->setEnabled(true);
$settingChangedDecision = $decisionFor((string)$settingChangedClaim);
autonomyPgCheck(($settingChangedResult['status'] ?? null) === 'stale' && ($settingChangedDecision['reason'] ?? null) === 'setting_changed' && ($settingChangedDecision['child'] ?? null) === null, 'changed_setting_must_not_compose');

// A completed decision for one NPC suppresses that NPC for six game hours.
$silenceSourceId = $makeExposedSource('Camilla needs time to consider this.');
$silenceAt = $currentGamets;
$setClock($silenceAt + $twoHours);
$silenceClaim = null;
$silenceResult = $runTick(static function (array $messages) use (&$modelCalls, &$silenceClaim, $connection, $claimedRow): string {
    $modelCalls++;
    $row = $claimedRow($connection);
    $silenceClaim = $row['source_id'] ?? null;
    return autonomyPgSilence();
});
$silenceDecision = $decisionFor((string)$silenceClaim);
autonomyPgCheck(($silenceResult['status'] ?? null) === 'silent' && ($silenceDecision['status'] ?? null) === 'silent', 'silence_decision_must_complete');
$cooldownSourceId = $makeExposedSource('Camilla receives another letter during her cooldown.');
$setClock($currentGamets + $twoHours);
$cooldownCalls = 0;
$cooldownResult = $runTick(static function (array $messages) use (&$cooldownCalls): string { $cooldownCalls++; return autonomyPgReply('npc:900002'); });
autonomyPgCheck($cooldownCalls === 0 && ($cooldownResult['reason'] ?? null) === 'no_eligible_source', 'same_npc_six_hour_cooldown_must_block');
$silenceExpiry = (int)($silenceDecision['actor_cooldown'] ?? 0);
autonomyPgCheck($silenceExpiry === $silenceAt + $twoHours + $sixHours, 'six_hour_cooldown_expiry_must_follow_completion_clock');
$setClock($silenceExpiry);
$firstChainClaim = null;
$firstChainResult = $runTick(static function (array $messages) use (&$modelCalls, &$firstChainClaim, $connection, $claimedRow): string {
    $modelCalls++;
    $row = $claimedRow($connection);
    $firstChainClaim = $row['source_id'] ?? null;
    return autonomyPgReply('npc:900002');
});
$firstChainDecision = $decisionFor((string)$firstChainClaim);
autonomyPgCheck(($firstChainResult['status'] ?? null) === 'queued' && ($firstChainDecision['child'] ?? null) !== null, 'first_chain_reply_must_queue');

$dispatchAndExpose = static function (string $sourceId, string $recipientName) use (&$modelCalls, &$currentGamets, $setClock, $touchPresence, $autonomy, $connection, $store, $service): array {
    $row = autonomyPgQuery($connection, 'SELECT child_document_id::text child,due_gamets::text due FROM plugins.correspondence_autonomy_decisions WHERE source_document_id=$1', [$sourceId])[0] ?? [];
    autonomyPgCheck(is_string($row['child'] ?? null) && is_string($row['due'] ?? null), 'queued_child_missing');
    $setClock(max($currentGamets, (int)$row['due']));
    $callsBefore = $modelCalls;
    $touchPresence();
    $dispatchResult = $autonomy->tick(static function (array $messages) use (&$modelCalls): string { $modelCalls++; return autonomyPgSilence(); });
    autonomyPgCheck($modelCalls === $callsBefore && ($dispatchResult['status'] ?? null) === 'dispatched', 'due_child_must_dispatch_without_model_call');
    $document = $store->loadDocument($row['child']);
    autonomyPgCheck(is_array($document) && $document['recipient']['name'] === $recipientName && is_string($document['operation_id']), 'dispatched_child_document_must_match_target');
    $operation = $store->loadOperation($document['operation_id']);
    autonomyPgCheck(is_array($operation), 'dispatched_child_operation_missing');
    $receipt = array_intersect_key($operation, array_flip(['protocol_version','document_id','operation_id','receipt_id','playthrough_id','scope_token','nonce']));
    $receipt += ['status'=>'delivered','reason'=>'physical_only','note_form_id'=>12345,'attachments'=>[]];
    $service->receive($receipt);
    $service->prompt($recipientName);
    return ['child_id'=>$row['child'],'exposed_gamets'=>$currentGamets];
};

$firstChainChild = $dispatchAndExpose((string)$firstChainClaim, 'Jarl Balgruuf');
$jarlCooldown = (int)$decisionFor((string)$firstChainClaim)['actor_cooldown'];
$setClock($firstChainChild['exposed_gamets'] + $twoHours);
$secondChainClaim = null;
$secondChainResult = $runTick(static function (array $messages) use (&$modelCalls, &$secondChainClaim, $connection, $claimedRow): string {
    $modelCalls++;
    $row = $claimedRow($connection);
    $secondChainClaim = $row['source_id'] ?? null;
    return autonomyPgReply('npc:900001');
});
$secondChainDecision = $decisionFor((string)$secondChainClaim);
autonomyPgCheck(($secondChainResult['status'] ?? null) === 'queued' && ($secondChainDecision['child'] ?? null) !== null, 'second_chain_reply_must_queue');
$secondChainChild = $dispatchAndExpose((string)$secondChainClaim, 'Camilla');
$setClock(max($secondChainChild['exposed_gamets'] + $twoHours, $jarlCooldown));
$thirdChainClaim = null;
$thirdChainResult = $runTick(static function (array $messages) use (&$modelCalls, &$thirdChainClaim, $connection, $claimedRow): string {
    $modelCalls++;
    $row = $claimedRow($connection);
    $thirdChainClaim = $row['source_id'] ?? null;
    return autonomyPgReply('npc:900002');
});
$thirdChainDecision = $decisionFor((string)$thirdChainClaim);
autonomyPgCheck(($thirdChainResult['status'] ?? null) === 'queued' && ($thirdChainDecision['child'] ?? null) !== null, 'third_chain_reply_must_queue');
$thirdChainChild = $dispatchAndExpose((string)$thirdChainClaim, 'Jarl Balgruuf');
$jarlSecondCooldown = (int)$decisionFor((string)$secondChainClaim)['actor_cooldown'];
$setClock(max($thirdChainChild['exposed_gamets'] + $twoHours, $jarlSecondCooldown));
$callsBeforeChainCutoff = $modelCalls;
$chainCutoff = $runTick(static function (array $messages) use (&$modelCalls): string { $modelCalls++; return autonomyPgReply('npc:900001'); });
$chainLinks = autonomyPgQuery($connection, 'SELECT count(*)::int amount FROM plugins.correspondence_autonomy_decisions WHERE root_document_id=(SELECT root_document_id FROM plugins.correspondence_autonomy_decisions WHERE source_document_id=$1) AND child_document_id IS NOT NULL', [$firstChainClaim])[0]['amount'] ?? null;
autonomyPgCheck(($chainLinks ?? null) === '3' && $modelCalls === $callsBeforeChainCutoff && ($chainCutoff['model_calls'] ?? null) === 0, 'three_link_cutoff_must_stop_fourth_model_call');

echo json_encode([
    'status'=>'PASS','lost_claim_failure_is_closed_without_completion'=>true,'lost_claim_success_is_closed_without_child'=>true,
    'source_actor_setting_rechecks_block_composition'=>true,'same_npc_six_hour_cooldown_blocks_until_expiry'=>true,
    'chain_stops_after_three_links'=>true,'consumed_sources_are_not_retried'=>true,'model_calls'=>$modelCalls,
], JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
