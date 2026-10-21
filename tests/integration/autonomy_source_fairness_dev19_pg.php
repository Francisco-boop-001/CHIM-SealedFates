<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/support/recovery_pg_fixture.php';

function autonomySourceFairnessPgDev19Check(bool $ok, string $reason): void
{
    if (!$ok) throw new RuntimeException($reason);
}

function autonomySourceFairnessPgDev19Query(PgSql\Connection $connection, string $sql, array $args = []): array
{
    $result = $args === [] ? pg_query($connection, $sql) : pg_query_params($connection, $sql, $args);
    autonomySourceFairnessPgDev19Check($result instanceof PgSql\Result, 'fixture_sql_failed');
    return pg_num_fields($result) > 0 ? (pg_fetch_all($result) ?: []) : [];
}

function autonomySourceFairnessPgDev19DecisionEvidence(PgSql\Connection $connection, string $sourceDocumentId): array
{
    return autonomySourceFairnessPgDev19Query($connection, "SELECT status,action,child_document_id::text child,due_gamets::text due,(payload->'claim_anchor')::text claim_anchor,(payload->'completion_anchor')::text completion_anchor,payload->>'model_calls' model_calls,payload->>'input_chars' input_chars,payload->>'output_chars' output_chars,payload->>'recheck_after_gamets' recheck_after FROM plugins.correspondence_autonomy_decisions WHERE source_document_id=$1", [$sourceDocumentId])[0] ?? [];
}

function autonomySourceFairnessPgDev19EvidenceUnchanged(array $before, array $after, array $fields): bool
{
    foreach ($fields as $field) if (($before[$field] ?? null) !== ($after[$field] ?? null)) return false;
    return true;
}

function autonomySourceFairnessPgDev19Silence(): string
{
    return json_encode(['action'=>'none','recipient'=>null,'body'=>'','reason'=>'I need more time.'], JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}

autonomySourceFairnessPgDev19Check(PHP_SAPI === 'cli', 'native_cli_required');
$connection = correspondenceRecoveryFixture('chim_corr_recovery_autonomy');
autonomySourceFairnessPgDev19Check(autonomySourceFairnessPgDev19Query($connection, "SELECT table_name FROM information_schema.tables WHERE table_schema IN ('public','plugins','chim_meta')") === [], 'blank_owned_database_required');

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
 (900002,'Jarl Balgruuf','00013BBD',2,'Reserved.','{"background_life_enabled":true,"background_life_letters":false}','{"last_coords":[0,0,0,"Dragonsreach"]}'),
 (900003,'Lydia','000A2C94',3,'Practical.','{"background_life_enabled":true,"background_life_letters":true}','{"last_coords":[0,0,0,"Riverwood"]}');
INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(100,100,'inputtext','Dragonborn: autonomy fairness fixture','',100,'','','');
SQL;
autonomySourceFairnessPgDev19Check(pg_query($connection, $ddl) !== false, 'fixture_schema_create_failed');

$project = dirname(__DIR__, 2);
require_once $project.'/server/lib/store.php';
require_once $project.'/server/lib/boundaries.php';
require_once $project.'/server/lib/autonomy_policy.php';
require_once $project.'/server/lib/autonomy_store.php';

$store = new CorrespondencePgStore($connection, static fn($store) => $store->observedExecutor(), static fn($title, $body) => true);
$store->ensureSchema();
$store->bindRequestClock(['inputtext','100','100','synthetic fairness fixture']);
$service = new CorrespondenceService($store);
$autonomy = new CorrespondenceAutonomyPg($store, $service);
$autonomy->ensureSchema();
$autonomy->setEnabled(true);
$presence = base64_encode(correspondenceJson(['source'=>'plugin_player_routing_v2','present_actors'=>[['form_id'=>hexdec('000A2C94'),'name'=>'Lydia','distance'=>10,'managed'=>true,'creature'=>false]]]));

$makeExposedSource = static function (string $body, string $recipient, string $sender) use ($store, $service, $presence): string {
    $store->observeManaged($presence);
    $document = $service->compose([
        'request_id'=>correspondenceUuid(), 'author'=>'player', 'payer'=>'player', 'recipient'=>$recipient,
        'claimed_sender'=>$sender, 'body'=>$body, 'attachments'=>[],
    ]);
    $sent = $service->dispatch($document['document_id']);
    $operation = $store->loadOperation($sent['operation_id']);
    autonomySourceFairnessPgDev19Check(is_array($operation), 'incoming_operation_missing');
    $receipt = array_intersect_key($operation, array_flip(['protocol_version','document_id','operation_id','receipt_id','playthrough_id','scope_token','nonce']));
    $receipt += ['status'=>'delivered','reason'=>'physical_only','note_form_id'=>12345,'attachments'=>[]];
    $service->receive($receipt);
    $recipientName = match ($recipient) {
        'npc:900001' => 'Camilla', 'npc:900003' => 'Lydia',
        default => throw new RuntimeException('fixture_unknown_recipient'),
    };
    $service->prompt($recipientName);
    return $document['document_id'];
};

$earlySources = [];
for ($index = 1; $index <= 65; $index++) {
    $earlySources[] = $makeExposedSource('Mutable sender eligibility fixture '.$index.'.', 'npc:900001', 'npc:900002');
}
$validSource = $makeExposedSource('The later eligible source must still be considered.', 'npc:900003', 'player');

$twoHours = correspondenceAutonomyGamets(2);
$oneHour = correspondenceAutonomyGamets(1);
$gamets = 100 + $twoHours;
$timestamp = 200;
$setClock = static function (int $target) use (&$gamets, &$timestamp, $store, $presence): int {
    $gamets = max($gamets, $target);
    $timestamp++;
    $store->bindRequestClock(['inputtext',(string)$timestamp,(string)$gamets,'synthetic fairness fixture']);
    $store->observeManaged($presence);
    return $gamets;
};
$setClock($gamets);
$modelCalls = 0;
$firstTick = $autonomy->tick(static function(array $messages) use (&$modelCalls): string { $modelCalls++; return autonomySourceFairnessPgDev19Silence(); });
$firstMarkers = autonomySourceFairnessPgDev19Query($connection, "SELECT count(*)::int amount FROM plugins.correspondence_autonomy_decisions WHERE status='stale' AND payload->>'reconsiderable'='true'");
autonomySourceFairnessPgDev19Check(($firstTick['reason'] ?? null) === 'actor_unavailable' && $modelCalls === 0, 'first_mutable_batch_must_not_call_model');
autonomySourceFairnessPgDev19Check(($firstMarkers[0]['amount'] ?? null) === '64', 'all_first_64_mutable_failures_must_be_recheckable');
autonomySourceFairnessPgDev19Check(autonomySourceFairnessPgDev19Query($connection, "SELECT rowid FROM public.eventlog WHERE sess='correspondence_v1' AND data::jsonb->>'kind'='autonomy_claim'") === [], 'deferred_sources_must_not_create_claim_anchors');

$validTick = $autonomy->tick(static function(array $messages) use (&$modelCalls): string { $modelCalls++; return autonomySourceFairnessPgDev19Silence(); });
$validDecision = autonomySourceFairnessPgDev19Query($connection, 'SELECT status,action,child_document_id::text child,payload->>\'reason_code\' reason FROM plugins.correspondence_autonomy_decisions WHERE source_document_id=$1', [$validSource])[0] ?? [];
autonomySourceFairnessPgDev19Check(($validTick['status'] ?? null) === 'silent' && $modelCalls === 1, 'later_valid_source_must_cross_the_mutable_backlog');
autonomySourceFairnessPgDev19Check(($validDecision['status'] ?? null) === 'silent' && ($validDecision['reason'] ?? null) === 'silence' && ($validDecision['child'] ?? null) === null, 'later_valid_source_completes_once_without_child_effect');
autonomySourceFairnessPgDev19Check(($autonomy->status()[0]['source_document_id'] ?? null) !== null, 'autonomy_status_readable');

autonomySourceFairnessPgDev19Query($connection, "UPDATE public.core_npc_master SET npc_name='jarl balgruuf',extended_data=jsonb_set(extended_data,'{background_life_letters}','true'::jsonb,true) WHERE id=900002");
$setClock($gamets + $oneHour);
$oneHourTick = $autonomy->tick(static function(array $messages) use (&$modelCalls): string { $modelCalls++; return autonomySourceFairnessPgDev19Silence(); });
if (($oneHourTick['reason'] ?? null) !== 'actor_identity_changed' || $modelCalls !== 1) {
    $diagnostic = autonomySourceFairnessPgDev19Query($connection, "SELECT payload->>'reason_code' reason,count(*)::int amount,min(payload->>'recheck_after_gamets') earliest_recheck FROM plugins.correspondence_autonomy_decisions WHERE status='stale' GROUP BY payload->>'reason_code' ORDER BY payload->>'reason_code'");
    throw new RuntimeException('case_changed_sender_is_rechecked_without_model_call: '.json_encode(['tick'=>$oneHourTick,'model_calls'=>$modelCalls,'stale_rows'=>$diagnostic], JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
}
autonomySourceFairnessPgDev19Query($connection, "UPDATE public.core_npc_master SET npc_name='Jarl Balgruuf' WHERE id=900002");
$setClock($gamets + $oneHour);
$reentryTick = $autonomy->tick(static function(array $messages) use (&$modelCalls): string { $modelCalls++; return autonomySourceFairnessPgDev19Silence(); });
$reentered = autonomySourceFairnessPgDev19Query($connection, "SELECT source_document_id::text source_id,claim_id::text claim_id,child_document_id::text child,payload->>'reason_code' reason FROM plugins.correspondence_autonomy_decisions WHERE status='silent' AND action='none' AND source_document_id<>$1 ORDER BY created_at DESC,source_document_id LIMIT 1", [$validSource])[0] ?? [];
autonomySourceFairnessPgDev19Check(($reentryTick['status'] ?? null) === 'silent' && $modelCalls === 2, 'restored_sender_identity_makes_due_source_eligible_again');
autonomySourceFairnessPgDev19Check(in_array($reentered['source_id'] ?? null, $earlySources, true) && ($reentered['reason'] ?? null) === 'silence' && ($reentered['child'] ?? null) === null, 'an_original_mutable_source_reenters_as_one_silent_decision');
$claimAnchors = autonomySourceFairnessPgDev19Query($connection, "SELECT rowid FROM public.eventlog WHERE sess='correspondence_v1' AND data::jsonb->>'kind'='autonomy_claim' AND data::jsonb->>'source_document_id'=$1", [$reentered['source_id']]);
$completionAnchors = autonomySourceFairnessPgDev19Query($connection, "SELECT rowid FROM public.eventlog WHERE sess='correspondence_v1' AND data::jsonb->>'kind'='autonomy_completion' AND data::jsonb->>'source_document_id'=$1", [$reentered['source_id']]);
autonomySourceFairnessPgDev19Check(count($claimAnchors) === 1 && count($completionAnchors) === 1, 'reentered_source_has_one_claim_and_completion_anchor');
$duplicateTick = $autonomy->tick(static function(array $messages) use (&$modelCalls): string { $modelCalls++; return autonomySourceFairnessPgDev19Silence(); });
autonomySourceFairnessPgDev19Check(($duplicateTick['reason'] ?? null) === 'cooldown' && $modelCalls === 2, 'reentry_has_no_duplicate_model_or_effect');

autonomySourceFairnessPgDev19Query($connection, "UPDATE plugins.correspondence_autonomy_decisions SET payload=jsonb_set(payload,'{recheck_after_gamets}','\"malformed\"'::jsonb,true) WHERE status='stale' AND payload->>'reconsiderable'='true'");
$actorCooldown = correspondenceAutonomyGamets(6);
$setClock($gamets + $actorCooldown + 1);
$malformedTick = $autonomy->tick(static function(array $messages) use (&$modelCalls): string { $modelCalls++; return autonomySourceFairnessPgDev19Silence(); });
$markerCounts = autonomySourceFairnessPgDev19Query($connection, "SELECT count(*) FILTER (WHERE payload->>'recheck_after_gamets' ~ '^[1-9][0-9]{0,18}$')::int repaired,count(*) FILTER (WHERE payload->>'recheck_after_gamets'='malformed')::int malformed,count(*) FILTER (WHERE payload->>'reason_code'='recheck_metadata_invalid')::int metadata_reason FROM plugins.correspondence_autonomy_decisions WHERE status='stale' AND payload->>'reconsiderable'='true'")[0] ?? [];
if (($malformedTick['reason'] ?? null) !== 'recheck_metadata_invalid' || $modelCalls !== 2
    || ($markerCounts['repaired'] ?? null) !== '64' || ($markerCounts['malformed'] ?? null) !== '0'
    || ($markerCounts['metadata_reason'] ?? null) !== '64') {
    $diagnostic = autonomySourceFairnessPgDev19Query($connection, "SELECT payload->>'reason_code' reason,payload->>'recheck_after_gamets' recheck_after,count(*)::int amount FROM plugins.correspondence_autonomy_decisions WHERE status='stale' AND payload->>'reconsiderable'='true' GROUP BY payload->>'reason_code',payload->>'recheck_after_gamets' ORDER BY payload->>'reason_code',payload->>'recheck_after_gamets'");
    throw new RuntimeException('malformed_retry_metadata_is_repaired_in_one_bounded_64_row_pass: '.json_encode(['tick'=>$malformedTick,'model_calls'=>$modelCalls,'counts'=>$markerCounts,'rows'=>$diagnostic,'clock_gamets'=>$gamets], JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
}

$trustedAttempt = autonomySourceFairnessPgDev19DecisionEvidence($connection, $validSource);
autonomySourceFairnessPgDev19Check(($trustedAttempt['status'] ?? null) === 'silent'
    && ($trustedAttempt['model_calls'] ?? null) === '1'
    && is_string($trustedAttempt['claim_anchor'] ?? null)
    && is_string($trustedAttempt['completion_anchor'] ?? null), 'prior_trusted_attempt_has_anchors_and_model_record');
$sourceEvent = autonomySourceFairnessPgDev19Query($connection, 'SELECT d.source_event_id::text,e.data source_data FROM plugins.correspondence_autonomy_decisions d JOIN public.eventlog e ON e.rowid=d.source_event_id WHERE d.source_document_id=$1', [$validSource])[0] ?? [];
$sourceEventId = (int)($sourceEvent['source_event_id'] ?? 0);
$syntheticChild = 'f0000000-0000-4000-8000-000000000019';
autonomySourceFairnessPgDev19Query($connection, "UPDATE plugins.correspondence_autonomy_decisions SET status='stale',action='reply',child_document_id=$2::uuid,due_gamets=$3::numeric,payload=jsonb_set(jsonb_set(payload,'{reconsiderable}',$4::jsonb,true),'{recheck_after_gamets}',$5::jsonb,true) WHERE source_document_id=$1", [$validSource,$syntheticChild,(string)($gamets + 1),json_encode(true, JSON_THROW_ON_ERROR),json_encode('malformed', JSON_THROW_ON_ERROR)]);
$contaminatedBefore = autonomySourceFairnessPgDev19DecisionEvidence($connection, $validSource);
$protectedFields = ['status','action','child','due','claim_anchor','completion_anchor','model_calls','input_chars','output_chars','recheck_after'];
$retryCandidate = ['root_document_id'=>$validSource,'source_depth'=>0,'source_data'=>(string)($sourceEvent['source_data'] ?? '')];
$deferWriter = new ReflectionMethod(CorrespondenceAutonomyPg::class, 'deferSource');
$store->transaction(static fn() => $deferWriter->invoke($autonomy, 'unprofiled', $validSource, $sourceEventId, $retryCandidate, $gamets, 'context_unavailable'));
$afterDeferWriter = autonomySourceFairnessPgDev19DecisionEvidence($connection, $validSource);
autonomySourceFairnessPgDev19Check(autonomySourceFairnessPgDev19EvidenceUnchanged($contaminatedBefore, $afterDeferWriter, $protectedFields), 'defer_upsert_preserves_prior_attempt_and_effect_fields');
$staleWriter = new ReflectionMethod(CorrespondenceAutonomyPg::class, 'recordStaleSource');
$store->transaction(static fn() => $staleWriter->invoke($autonomy, 'unprofiled', $validSource, $sourceEventId, (string)($sourceEvent['source_data'] ?? ''), $gamets, 'source_unavailable'));
$afterStaleWriter = autonomySourceFairnessPgDev19DecisionEvidence($connection, $validSource);
autonomySourceFairnessPgDev19Check(autonomySourceFairnessPgDev19EvidenceUnchanged($contaminatedBefore, $afterStaleWriter, $protectedFields), 'terminal_stale_upsert_preserves_prior_attempt_and_effect_fields');

$claimDueJson = json_encode((string)$gamets, JSON_THROW_ON_ERROR);
autonomySourceFairnessPgDev19Query($connection, "UPDATE plugins.correspondence_autonomy_decisions SET payload=jsonb_set(payload,'{recheck_after_gamets}',$2::jsonb,true) WHERE source_document_id=$1", [$validSource,$claimDueJson]);
$claimWriter = new ReflectionMethod(CorrespondenceAutonomyPg::class, 'claim');
$claimResult = $store->transaction(static fn() => $claimWriter->invoke($autonomy, 'unprofiled', $validSource, $sourceEventId, $gamets,
    ['root_document_id'=>$validSource,'depth'=>0],
    ['source_body'=>'persisted-row regression','self_row'=>['id'=>900003],'context_sha256'=>hash('sha256','persisted-row regression')],
    (string)($sourceEvent['source_data'] ?? ''), $store->restoreGeneration()));
$afterClaimWriter = autonomySourceFairnessPgDev19DecisionEvidence($connection, $validSource);
autonomySourceFairnessPgDev19Check($claimResult === null
    && autonomySourceFairnessPgDev19EvidenceUnchanged($contaminatedBefore, $afterClaimWriter, array_values(array_diff($protectedFields, ['recheck_after']))),
    'claim_upsert_refuses_due_retry_marker_with_prior_claim_and_effect_evidence');
autonomySourceFairnessPgDev19Query($connection, "UPDATE plugins.correspondence_autonomy_decisions SET payload=jsonb_set(payload,'{recheck_after_gamets}',$2::jsonb,true) WHERE source_document_id=$1", [$validSource,json_encode('malformed', JSON_THROW_ON_ERROR)]);
$futureRetryJson = json_encode((string)($gamets + 100 * $oneHour), JSON_THROW_ON_ERROR);
autonomySourceFairnessPgDev19Query($connection, "UPDATE plugins.correspondence_autonomy_decisions SET payload=jsonb_set(payload,'{recheck_after_gamets}',$1::jsonb,true) WHERE source_document_id<>$2 AND status='stale' AND payload->'reconsiderable'='true'::jsonb", [$futureRetryJson,$validSource]);
$beforeGuardTick = autonomySourceFairnessPgDev19DecisionEvidence($connection, $validSource);
$guardTick = $autonomy->tick(static function(array $messages) use (&$modelCalls): string { $modelCalls++; return autonomySourceFairnessPgDev19Silence(); });
$afterGuardTick = autonomySourceFairnessPgDev19DecisionEvidence($connection, $validSource);
autonomySourceFairnessPgDev19Check(($guardTick['reason'] ?? null) === 'no_eligible_source' && $modelCalls === 2,
    'dirty_toggled_marker_is_filtered_before_replay_after_cooldown');
autonomySourceFairnessPgDev19Check(autonomySourceFairnessPgDev19EvidenceUnchanged($beforeGuardTick, $afterGuardTick, $protectedFields)
    && ($afterGuardTick['claim_anchor'] ?? null) === ($trustedAttempt['claim_anchor'] ?? null)
    && ($afterGuardTick['completion_anchor'] ?? null) === ($trustedAttempt['completion_anchor'] ?? null)
    && ($afterGuardTick['model_calls'] ?? null) === '1'
    && ($afterGuardTick['action'] ?? null) === 'reply'
    && ($afterGuardTick['child'] ?? null) === $syntheticChild,
    'malformed_marker_does_not_erase_anchors_counters_action_child_or_due');

$anchorsOnlySource = (string)($reentered['source_id'] ?? '');
$anchorsOnlyTrusted = autonomySourceFairnessPgDev19DecisionEvidence($connection, $anchorsOnlySource);
autonomySourceFairnessPgDev19Check(($anchorsOnlyTrusted['status'] ?? null) === 'silent'
    && ($anchorsOnlyTrusted['model_calls'] ?? null) === '1'
    && is_string($anchorsOnlyTrusted['claim_anchor'] ?? null)
    && is_string($anchorsOnlyTrusted['completion_anchor'] ?? null), 'anchors_only_fixture_has_prior_trusted_attempt');
$anchorsOnlyEvent = autonomySourceFairnessPgDev19Query($connection, 'SELECT d.source_event_id::text,e.data source_data FROM plugins.correspondence_autonomy_decisions d JOIN public.eventlog e ON e.rowid=d.source_event_id WHERE d.source_document_id=$1', [$anchorsOnlySource])[0] ?? [];
$anchorsOnlyEventId = (int)($anchorsOnlyEvent['source_event_id'] ?? 0);
autonomySourceFairnessPgDev19Query($connection, "UPDATE plugins.correspondence_autonomy_decisions SET status='stale',action=NULL,child_document_id=NULL,due_gamets=NULL,payload=jsonb_set(jsonb_set(payload,'{reconsiderable}','true'::jsonb,true),'{recheck_after_gamets}','\"malformed\"'::jsonb,true) WHERE source_document_id=$1", [$anchorsOnlySource]);
$anchorsOnlyBefore = autonomySourceFairnessPgDev19DecisionEvidence($connection, $anchorsOnlySource);
autonomySourceFairnessPgDev19Check(($anchorsOnlyBefore['action'] ?? null) === null
    && ($anchorsOnlyBefore['child'] ?? null) === null && ($anchorsOnlyBefore['due'] ?? null) === null
    && ($anchorsOnlyBefore['claim_anchor'] ?? null) === ($anchorsOnlyTrusted['claim_anchor'] ?? null)
    && ($anchorsOnlyBefore['completion_anchor'] ?? null) === ($anchorsOnlyTrusted['completion_anchor'] ?? null)
    && ($anchorsOnlyBefore['model_calls'] ?? null) === '1', 'anchors_only_retry_row_has_no_action_child_or_due_columns');
$anchorsOnlyCandidate = ['root_document_id'=>$anchorsOnlySource,'source_depth'=>0,'source_data'=>(string)($anchorsOnlyEvent['source_data'] ?? '')];
$store->transaction(static fn() => $deferWriter->invoke($autonomy, 'unprofiled', $anchorsOnlySource, $anchorsOnlyEventId, $anchorsOnlyCandidate, $gamets, 'context_unavailable'));
$anchorsOnlyAfterDefer = autonomySourceFairnessPgDev19DecisionEvidence($connection, $anchorsOnlySource);
autonomySourceFairnessPgDev19Check(autonomySourceFairnessPgDev19EvidenceUnchanged($anchorsOnlyBefore, $anchorsOnlyAfterDefer, $protectedFields), 'anchors_only_defer_writer_preserves_prior_attempt_evidence');
$store->transaction(static fn() => $staleWriter->invoke($autonomy, 'unprofiled', $anchorsOnlySource, $anchorsOnlyEventId, (string)($anchorsOnlyEvent['source_data'] ?? ''), $gamets, 'source_unavailable'));
$anchorsOnlyAfterStale = autonomySourceFairnessPgDev19DecisionEvidence($connection, $anchorsOnlySource);
autonomySourceFairnessPgDev19Check(autonomySourceFairnessPgDev19EvidenceUnchanged($anchorsOnlyBefore, $anchorsOnlyAfterStale, $protectedFields), 'anchors_only_stale_writer_preserves_prior_attempt_evidence');
$anchorsOnlyDueJson = json_encode((string)$gamets, JSON_THROW_ON_ERROR);
autonomySourceFairnessPgDev19Query($connection, "UPDATE plugins.correspondence_autonomy_decisions SET payload=jsonb_set(payload,'{recheck_after_gamets}',$2::jsonb,true) WHERE source_document_id=$1", [$anchorsOnlySource,$anchorsOnlyDueJson]);
$anchorsOnlyClaimResult = $store->transaction(static fn() => $claimWriter->invoke($autonomy, 'unprofiled', $anchorsOnlySource, $anchorsOnlyEventId, $gamets,
    ['root_document_id'=>$anchorsOnlySource,'depth'=>0],
    ['source_body'=>'anchors-only persisted-row regression','self_row'=>['id'=>900001],'context_sha256'=>hash('sha256','anchors-only persisted-row regression')],
    (string)($anchorsOnlyEvent['source_data'] ?? ''), $store->restoreGeneration()));
$anchorsOnlyAfterClaim = autonomySourceFairnessPgDev19DecisionEvidence($connection, $anchorsOnlySource);
autonomySourceFairnessPgDev19Check($anchorsOnlyClaimResult === null
    && autonomySourceFairnessPgDev19EvidenceUnchanged($anchorsOnlyBefore, $anchorsOnlyAfterClaim, array_values(array_diff($protectedFields, ['recheck_after']))),
    'anchors_only_claim_writer_refuses_prior_attempt_evidence');
autonomySourceFairnessPgDev19Query($connection, "UPDATE plugins.correspondence_autonomy_decisions SET payload=jsonb_set(payload,'{recheck_after_gamets}','\"malformed\"'::jsonb,true) WHERE source_document_id=$1", [$anchorsOnlySource]);
$anchorsOnlyFutureRetryJson = json_encode((string)($gamets + 100 * $oneHour), JSON_THROW_ON_ERROR);
autonomySourceFairnessPgDev19Query($connection, "UPDATE plugins.correspondence_autonomy_decisions SET payload=jsonb_set(payload,'{recheck_after_gamets}',$1::jsonb,true) WHERE source_document_id<>$2 AND source_document_id<>$3 AND status='stale' AND payload->'reconsiderable'='true'::jsonb", [$anchorsOnlyFutureRetryJson,$validSource,$anchorsOnlySource]);
$anchorsOnlyBeforeTick = autonomySourceFairnessPgDev19DecisionEvidence($connection, $anchorsOnlySource);
$anchorsOnlyTick = $autonomy->tick(static function(array $messages) use (&$modelCalls): string { $modelCalls++; return autonomySourceFairnessPgDev19Silence(); });
$anchorsOnlyAfterTick = autonomySourceFairnessPgDev19DecisionEvidence($connection, $anchorsOnlySource);
autonomySourceFairnessPgDev19Check(($anchorsOnlyTick['reason'] ?? null) === 'no_eligible_source' && $modelCalls === 2,
    'anchors_only_selector_excludes_prior_attempt_evidence_without_model_replay');
autonomySourceFairnessPgDev19Check(autonomySourceFairnessPgDev19EvidenceUnchanged($anchorsOnlyBeforeTick, $anchorsOnlyAfterTick, $protectedFields)
    && ($anchorsOnlyAfterTick['claim_anchor'] ?? null) === ($anchorsOnlyTrusted['claim_anchor'] ?? null)
    && ($anchorsOnlyAfterTick['completion_anchor'] ?? null) === ($anchorsOnlyTrusted['completion_anchor'] ?? null)
    && ($anchorsOnlyAfterTick['model_calls'] ?? null) === '1', 'anchors_only_malformed_marker_preserves_prior_attempt_counters_and_anchors');

echo json_encode([
    'status'=>'PASS',
    'first64_mutable_sources_reconsiderable'=>true,
    'later_valid_source_processed'=>true,
    'changed_then_restored_identity_reentered'=>true,
    'reentered_claim_anchors'=>count($claimAnchors),
    'reentered_completion_anchors'=>count($completionAnchors),
    'malformed_markers_repaired'=>(int)$markerCounts['repaired'],
    'prior_attempt_marker_tampering_blocked'=>true,
    'anchors_only_prior_attempt_marker_tampering_blocked'=>true,
    'model_calls'=>$modelCalls,
], JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
