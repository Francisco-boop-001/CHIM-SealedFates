<?php
declare(strict_types=1);

final class CorrespondenceAutonomyPg
{
    private const TRUE_VALUES = ['true','1','t','on'];
    private const STATUS_VALUES = ['claimed','silent','queued','dispatched','failed','unknown','stale'];
    private const ACTION_VALUES = ['none','reply','forward','mediate'];

    public function __construct(private CorrespondencePgStore $store, private CorrespondenceService $service) {}

    public function ensureSchema(): void
    {
        $this->store->ensureSchema();
        $ready = $this->store->query("SELECT to_regclass('plugins.correspondence_autonomy_settings') IS NOT NULL AS settings,to_regclass('plugins.correspondence_autonomy_decisions') IS NOT NULL AS decisions")[0] ?? [];
        if (($ready['settings'] ?? null) !== 't' || ($ready['decisions'] ?? null) !== 't') {
            $path = dirname(__DIR__).'/migrations/002_autonomy.sql';
            $migration = @file_get_contents($path);
            if (!is_string($migration) || trim($migration) === '') throw new DomainException('schema_migration_unavailable');
            $this->store->transaction(function() use ($migration) {
                foreach (explode(';', $migration) as $statement) if (trim($statement) !== '') $this->store->query($statement);
            });
        }
        $columns = $this->store->query("SELECT table_name,column_name FROM information_schema.columns WHERE table_schema='plugins' AND table_name IN ('correspondence_autonomy_settings','correspondence_autonomy_decisions')");
        $expected = [
            'correspondence_autonomy_settings' => ['playthrough_id','enabled','updated_at'],
            'correspondence_autonomy_decisions' => ['playthrough_id','source_document_id','source_event_id','claim_id','request_id','root_document_id','depth','status','action','child_document_id','due_gamets','payload','created_at'],
        ];
        foreach ($expected as $table => $names) foreach ($names as $name) {
            if (!array_filter($columns, fn($row) => $row['table_name'] === $table && $row['column_name'] === $name)) throw new DomainException('schema_migration_incomplete');
        }
    }

    public function settings(): array
    {
        return $this->store->readTransaction(function() {
            $scope = $this->clock()['playthrough_id'];
            $row = $this->settingRow($scope);
            return ['enabled' => $row !== null && $this->dbBoolean($row['enabled'] ?? false)];
        });
    }

    public function setEnabled(bool $enabled): void
    {
        $this->store->transaction(function() use ($enabled) {
            $scope = $this->clock()['playthrough_id'];
            $this->store->query('INSERT INTO plugins.correspondence_autonomy_settings(playthrough_id,enabled) VALUES($1,$2) ON CONFLICT(playthrough_id) DO UPDATE SET enabled=EXCLUDED.enabled,updated_at=clock_timestamp()', [$scope, $enabled ? 'true' : 'false']);
        });
    }

    public function status(): array
    {
        return $this->store->readTransaction(function() {
            $scope = $this->clock()['playthrough_id'];
            $rows = $this->store->query('SELECT source_document_id::text,child_document_id::text,status,action,due_gamets::text,payload->>\'reason_code\' AS reason FROM plugins.correspondence_autonomy_decisions WHERE playthrough_id=$1 ORDER BY created_at DESC,source_document_id LIMIT 20', [$scope]);
            $result = [];
            foreach ($rows as $row) {
                $status = in_array($row['status'] ?? null, self::STATUS_VALUES, true) ? $row['status'] : 'unknown';
                $action = in_array($row['action'] ?? null, self::ACTION_VALUES, true) ? $row['action'] : null;
                $due = isset($row['due_gamets']) && preg_match('/\A[0-9]{1,18}\z/D', (string)$row['due_gamets']) === 1 ? (int)$row['due_gamets'] : null;
                $reason = is_string($row['reason'] ?? null) && preg_match('/\A[a-z][a-z0-9_]{0,47}\z/D', $row['reason']) === 1 ? $row['reason'] : 'unknown';
                $result[] = [
                    'source_document_id' => (string)$row['source_document_id'],
                    'child_document_id' => is_string($row['child_document_id'] ?? null) ? $row['child_document_id'] : null,
                    'status' => $status,
                    'action' => $action,
                    'due_gamets' => $due,
                    'reason' => $reason,
                ];
            }
            return $result;
        });
    }

    public function managedChildren(): array
    {
        return $this->store->readTransaction(function() {
            $scope = $this->clock()['playthrough_id'];
            $rows = $this->store->query('SELECT child_document_id::text FROM plugins.correspondence_autonomy_decisions WHERE playthrough_id=$1 AND child_document_id IS NOT NULL ORDER BY child_document_id', [$scope]);
            return array_map(static fn($row) => (string)$row['child_document_id'], $rows);
        });
    }

    public function tick(?callable $requestModel): array
    {
        $started = hrtime(true);
        $modelCalls = 0;
        $inputChars = 0;
        $outputChars = 0;
        $claimCorrelationId = null;
        $claimSourceIds = [];
        $finish = function(string $status, string $reason) use (&$modelCalls, &$inputChars, &$outputChars, &$claimCorrelationId, &$claimSourceIds, $started): array {
            $result = [
                'status' => $status,
                'reason' => $reason,
                'model_calls' => $modelCalls,
                'input_chars' => $inputChars,
                'output_chars' => $outputChars,
                'elapsed_ms' => max(0, (int)((hrtime(true) - $started) / 1000000)),
            ];
            if ($claimCorrelationId !== null) $result['correlation_id'] = $claimCorrelationId;
            if ($claimSourceIds !== []) $result['source_ids'] = $claimSourceIds;
            return $result;
        };
        if (!$this->policyReady()) return $finish('idle', 'policy_unavailable');

        try {
            $work = $this->store->transaction(function() use ($requestModel) {
                $clock = $this->clock();
                $scope = $clock['playthrough_id'];
                $restoreGeneration = $this->store->restoreGeneration();
                $requestRestoreToken = $this->store->requestRestoreToken();
                if (!is_string($restoreGeneration) || $restoreGeneration === '' || !is_string($requestRestoreToken) || !hash_equals($restoreGeneration, $requestRestoreToken)) return ['kind'=>'idle','status'=>'idle','reason'=>'restore_unavailable'];
                $setting = $this->settingRow($scope);
                if ($setting === null || !$this->dbBoolean($setting['enabled'] ?? false)) return ['kind'=>'idle','status'=>'idle','reason'=>'disabled'];

                if ($this->sweepAbandonedClaims($scope, $clock['gamets'], $restoreGeneration) > 0) return ['kind'=>'idle','status'=>'idle','reason'=>'abandoned_claim_retired'];

                $flush = $this->flushDue($scope, $clock, $setting);
                if ($flush !== null) return $flush;

                if ($requestModel === null) return ['kind'=>'idle','status'=>'idle','reason'=>'model_unavailable'];

                $globalCooldown = $this->globalCooldownUntil($scope, $clock['gamets']);
                if ($globalCooldown > $clock['gamets']) return ['kind'=>'idle','status'=>'idle','reason'=>'cooldown'];

                $age = correspondenceAutonomyGamets(2);
                $rows = $this->sourceCandidates($scope, $clock['gamets'], $age);
                if ($rows === []) return ['kind'=>'idle','status'=>'idle','reason'=>'no_eligible_source'];
                $byId = $this->npcMap($this->npcRows());
                $deferredReason = null;
                foreach ($rows as $candidate) {
                    $eventId = (int)($candidate['source_event_id'] ?? 0);
                    $documentId = (string)($candidate['source_document_id'] ?? '');
                    $recipientId = (int)($candidate['recipient_id'] ?? 0);
                    if ($eventId < 1 || $recipientId < 1 || preg_match('/\A[0-9a-f-]{36}\z/D', $documentId) !== 1) return ['kind'=>'idle','status'=>'idle','reason'=>'invalid_candidate'];
                    if ($this->dbBoolean($candidate['reconsiderable'] ?? false)
                        && !$this->dbBoolean($candidate['recheck_after_valid'] ?? false)) {
                        $deferredReason ??= 'recheck_metadata_invalid';
                        $this->deferSource($scope, $documentId, $eventId, $candidate, $clock['gamets'], 'recheck_metadata_invalid');
                        continue;
                    }
                    try {
                        $exposed = $this->service->readExposedSource($eventId, $recipientId);
                    } catch (DomainException|InvalidArgumentException $error) {
                        $sourceReason = $error->getMessage();
                        if (in_array($sourceReason, ['player_unavailable','actor_unavailable','identity_changed','actor_identity_changed','source_not_due'], true)) {
                            $deferredReason ??= $sourceReason;
                            $this->deferSource($scope, $documentId, $eventId, $candidate, $clock['gamets'], $sourceReason);
                            continue;
                        }
                        $this->recordStaleSource($scope, $documentId, $eventId, $candidate['source_data'] ?? '', $clock['gamets']);
                        return ['kind'=>'idle','status'=>'idle','reason'=>'source_unavailable'];
                    }
                    $source = $exposed['source'];
                    $context = null;
                    $contextFailure = null;
                    try {
                        $context = $this->contextForSource($source, $byId);
                    } catch (DomainException|InvalidArgumentException $error) {
                        $contextFailure = in_array($error->getMessage(), ['player_unavailable','actor_unavailable','identity_changed','actor_identity_changed'], true)
                            ? $error->getMessage() : 'context_unavailable';
                    } catch (Throwable) {
                        $contextFailure = 'context_unavailable';
                    }
                    if ($contextFailure !== null || $context === null) {
                        $contextFailure ??= 'actor_unavailable';
                        $deferredReason ??= $contextFailure;
                        $this->deferSource($scope, $documentId, $eventId, $candidate, $clock['gamets'], $contextFailure);
                        continue;
                    }
                    try {
                        $messages = correspondenceAutonomyMessages($source, $context['self'], $context['contacts']);
                    } catch (Throwable) {
                        $deferredReason ??= 'context_unavailable';
                        $this->deferSource($scope, $documentId, $eventId, $candidate, $clock['gamets'], 'context_unavailable');
                        continue;
                    }
                    $actorCooldown = $this->actorCooldownUntil($scope, $recipientId, $clock['gamets']);
                    if ($actorCooldown > $clock['gamets']) {
                        $deferredReason ??= 'cooldown';
                        $this->deferSource($scope, $documentId, $eventId, $candidate, $clock['gamets'], 'actor_cooldown', $actorCooldown);
                        continue;
                    }
                    $context['messages'] = $messages;
                    $lineage = $this->lineage($scope, $documentId);
                    if ($lineage['depth'] >= 3 || $this->linkCount($scope, $lineage['root_document_id']) >= 3) {
                        $this->recordStaleSource($scope, $documentId, $eventId, $candidate['source_data'] ?? '', $clock['gamets'], 'chain_limit');
                        return ['kind'=>'idle','status'=>'idle','reason'=>'chain_limit'];
                    }
                    $claim = $this->claim($scope, $documentId, $eventId, $clock['gamets'], $lineage, $context, $exposed['event']['data'], $restoreGeneration);
                    if ($claim === null) return ['kind'=>'idle','status'=>'idle','reason'=>'already_claimed'];
                    return $claim + [
                        'kind'=>'claimed', 'clock'=>$clock, 'setting_updated_at'=>$setting['updated_at'] ?? null,
                        'event'=>$exposed['event'], 'source'=>$source, 'self'=>$context['self'], 'contacts'=>$context['contacts'],
                        'messages'=>$context['messages'], 'context_sha256'=>$context['context_sha256'],
                    ];
                }
                return ['kind'=>'idle','status'=>'idle','reason'=>$deferredReason ?? 'no_eligible_source'];
            });
        } catch (Throwable) {
            return $finish('failed', 'storage_error');
        }

        if (($work['kind'] ?? null) === 'idle' || ($work['kind'] ?? null) === 'dispatch') return $finish($work['status'], $work['reason']);
        if (($work['kind'] ?? null) !== 'claimed') return $finish('failed', 'storage_error');

        $workClaimId = $work['claim_id'] ?? null;
        if (is_string($workClaimId) && preg_match('/\A[0-9a-f-]{36}\z/Di', $workClaimId) === 1) $claimCorrelationId = $workClaimId;
        $sourceEventId = $work['source_event_id'] ?? null;
        if (is_int($sourceEventId) && $sourceEventId > 0) $claimSourceIds = [$sourceEventId];

        $decision = null;
        $body = '';
        try {
            $messages = $work['messages'] ?? correspondenceAutonomyMessages($work['source'], $work['self'], $work['contacts']);
            if (!is_array($messages)) throw new UnexpectedValueException('invalid_messages');
            $inputChars = $this->messageChars($messages);
            $modelCalls++;
            if (!$this->persistAttemptCounters($work, $modelCalls, $inputChars, $outputChars)) {
                $modelCalls = 0;
                $this->releaseUnattemptedClaim($work);
                return $this->restoreStateChanged($work['restore_generation'] ?? null) === true
                    ? $finish('stale', 'restore_changed')
                    : $finish('failed', 'storage_error');
            }
            $raw = $requestModel($messages);
            if (!is_string($raw)) throw new UnexpectedValueException('invalid_model_output');
            $outputChars = $this->charCount($raw);
            if (!$this->persistAttemptCounters($work, $modelCalls, $inputChars, $outputChars)) return $this->restoreStateChanged($work['restore_generation'] ?? null) === true ? $finish('stale', 'restore_changed') : $finish('failed', 'storage_error');
            $decision = correspondenceAutonomyDecision($raw, $work['source'], $work['contacts']);
            if (!is_array($decision) || !in_array($decision['action'] ?? null, self::ACTION_VALUES, true)) throw new InvalidArgumentException('invalid_decision');
            if ($decision['action'] !== 'none') {
                $body = correspondenceAutonomyBody($decision, $work['source']);
                correspondenceBody($body);
                $this->validateTarget($decision, $work['source'], $work['self'], $work['contacts']);
            }
        } catch (InvalidArgumentException|UnexpectedValueException) {
            $reason = 'invalid_decision';
            $terminal = $this->completeFailure($work, $reason, $modelCalls, $inputChars, $outputChars);
            return $finish($terminal['status'], $terminal['reason']);
        } catch (DomainException $error) {
            $reason = $error->getMessage() === 'model_unavailable' ? 'model_unavailable' : 'model_error';
            $terminal = $this->completeFailure($work, $reason, $modelCalls, $inputChars, $outputChars);
            return $finish($terminal['status'], $terminal['reason']);
        } catch (Throwable) {
            $reason = 'model_error';
            $terminal = $this->completeFailure($work, $reason, $modelCalls, $inputChars, $outputChars);
            return $finish($terminal['status'], $terminal['reason']);
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $result = $this->runCompletionTransaction(function() use ($work, $decision, $body, $modelCalls, $inputChars, $outputChars) {
                $scope = $this->clock();
                $restoreState = $this->restoreStateChanged($work['restore_generation'] ?? null);
                if ($restoreState === true) return ['status'=>'stale','reason'=>'restore_changed'];
                if ($restoreState === null) return ['status'=>'failed','reason'=>'storage_error'];
                if ($scope['playthrough_id'] !== $work['playthrough_id'] || $scope['gamets'] < $work['claim_gamets']) return ['status'=>'stale','reason'=>'clock_changed'];
                $claimed = $this->decisionRow($work['playthrough_id'], $work['source_document_id']);
                if ($claimed === null || ($claimed['claim_id'] ?? null) !== $work['claim_id'] || ($claimed['status'] ?? null) !== 'claimed') return ['status'=>'stale','reason'=>'claim_changed'];
                if (!$this->claimMatchesWork($claimed, $work, $scope['gamets'])) return $this->closeLostClaim($work);
                $setting = $this->settingRow($scope['playthrough_id']);
                if ($setting === null || !$this->dbBoolean($setting['enabled'] ?? false) || ($setting['updated_at'] ?? null) !== $work['setting_updated_at']) return $this->markTerminal($work, 'stale', null, null, null, 'setting_changed', $scope['gamets'], $modelCalls, $inputChars, $outputChars);
                try { $exposed = $this->service->readExposedSource($work['source_event_id'], $work['recipient_id']); }
                catch (Throwable) { return $this->markTerminal($work, 'stale', null, null, null, 'source_changed', $scope['gamets'], $modelCalls, $inputChars, $outputChars); }
                if (!hash_equals($work['source_event_sha256'], hash('sha256', $exposed['event']['data'])) || !hash_equals($work['source_body_sha256'], hash('sha256', $exposed['source']['body']))) return $this->markTerminal($work, 'stale', null, null, null, 'source_changed', $scope['gamets'], $modelCalls, $inputChars, $outputChars);
                $nowContext = $this->contextForSource($exposed['source'], $this->npcMap($this->npcRows()));
                if ($nowContext === null || !hash_equals($work['context_sha256'], $nowContext['context_sha256'])) return $this->markTerminal($work, 'stale', null, null, null, 'actor_changed', $scope['gamets'], $modelCalls, $inputChars, $outputChars);
                if ($this->globalCooldownUntil($scope['playthrough_id'], $scope['gamets'], $work['claim_id']) > $scope['gamets'] || $this->actorCooldownUntil($scope['playthrough_id'], $work['recipient_id'], $scope['gamets'], $work['claim_id']) > $scope['gamets']) return $this->markTerminal($work, 'stale', null, null, null, 'cooldown_changed', $scope['gamets'], $modelCalls, $inputChars, $outputChars);
                if ($decision['action'] === 'none') return $this->markTerminal($work, 'silent', 'none', null, null, 'silence', $scope['gamets'], $modelCalls, $inputChars, $outputChars);
                if ($this->linkCount($scope['playthrough_id'], $work['root_document_id']) >= 3) return $this->markTerminal($work, 'stale', null, null, null, 'chain_limit', $scope['gamets'], $modelCalls, $inputChars, $outputChars);
                $target = $this->findContact($decision['recipient'], $nowContext['contacts']);
                if ($target === null) return $this->markTerminal($work, 'stale', null, null, null, 'actor_changed', $scope['gamets'], $modelCalls, $inputChars, $outputChars);
                $targetActor = $target['kind'] === 'player' ? null : ($nowContext['contact_rows'][$target['key']] ?? null);
                if ($target['kind'] === 'npc' && (!is_array($targetActor) || !$this->eligible($targetActor))) return $this->markTerminal($work, 'stale', null, null, null, 'actor_changed', $scope['gamets'], $modelCalls, $inputChars, $outputChars);
                $delay = correspondenceAutonomyDelay($nowContext['self_row']['location'], is_array($targetActor) ? $targetActor['location'] : '');
                if (!is_int($delay) || $delay < 1) return $this->markTerminal($work, 'failed', null, null, null, 'invalid_delay', $scope['gamets'], $modelCalls, $inputChars, $outputChars);
                $compose = $this->service->compose([
                    'request_id'=>$work['request_id'],
                    'author'=>$nowContext['self']['key'],
                    'payer'=>$nowContext['self']['key'],
                    'recipient'=>$target['key'],
                    'claimed_sender'=>$nowContext['self']['key'],
                    'body'=>$body,
                    'attachments'=>[],
                ]);
                $completedGamets = $scope['gamets'];
                $due = $completedGamets + $delay;
                $payload = $this->completionPayload($work, 'queued', 'queued_for_transit', $completedGamets, $modelCalls, $inputChars, $outputChars, $decision['action'], $compose['document_id'], $due) + ['target_key'=>$target['key']];
                $this->updateDecision($work, 'queued', $decision['action'], $compose['document_id'], $due, $payload);
                return ['status'=>'queued','reason'=>'queued_for_transit'];
            }, (string)$work['restore_generation']);
            return $finish($result['status'], $result['reason']);
            } catch (Throwable) {
                $settled = $this->readCompletedDecision($work);
                if ($settled !== null) return $finish($settled['status'], $settled['reason']);
                if ($attempt === 0 && $this->claimStillOwned($work)) continue;
                if ($this->restoreStateChanged($work['restore_generation'] ?? null) === true) return $finish('stale', 'restore_changed');
                $this->persistAttemptCounters($work, $modelCalls, $inputChars, $outputChars);
                return $finish('failed', 'storage_error');
            }
        }
        return $finish('failed', 'storage_error');
    }

    private function policyReady(): bool
    {
        foreach (['correspondenceAutonomyGamets','correspondenceAutonomyDelay','correspondenceAutonomyMessages','correspondenceAutonomyDecision','correspondenceAutonomyBody'] as $function) if (!function_exists($function)) return false;
        return true;
    }

    private function clock(): array
    {
        $scope = $this->store->scope();
        $id = $scope['playthrough_id'] ?? null;
        $ts = $scope['ts'] ?? null;
        $gamets = $scope['gamets'] ?? null;
        if (!is_string($id) || ($id !== 'unprofiled' && preg_match('/\A[1-9][0-9]*\z/D', $id) !== 1) || !is_numeric($ts) || (float)$ts < 1 || !is_numeric($gamets) || (float)$gamets < 1 || (float)$gamets > PHP_INT_MAX) throw new DomainException('clock_unavailable');
        return ['playthrough_id'=>$id,'ts'=>(int)$ts,'gamets'=>(int)$gamets];
    }

    private function settingRow(string $scope): ?array
    {
        return $this->store->query('SELECT enabled,updated_at::text FROM plugins.correspondence_autonomy_settings WHERE playthrough_id=$1', [$scope])[0] ?? null;
    }

    private function dbBoolean(mixed $value): bool
    {
        return $value === true || $value === 1 || (is_string($value) && in_array($value, self::TRUE_VALUES, true));
    }

    private function globalCooldownUntil(string $scope, int $gamets, ?string $excludeClaim = null): int
    {
        $sql = $this->cooldownSql('global_cooldown_until_gamets', false, $excludeClaim !== null);
        $params = [$scope, (string)$gamets];
        if ($excludeClaim !== null) $params[] = $excludeClaim;
        return (int)($this->store->query($sql, $params)[0]['until_gamets'] ?? 0);
    }

    private function actorCooldownUntil(string $scope, int $actorId, int $gamets, ?string $excludeClaim = null): int
    {
        $sql = $this->cooldownSql('actor_cooldown_until_gamets', true, $excludeClaim !== null);
        $params = [$scope, (string)$gamets, (string)$actorId];
        if ($excludeClaim !== null) $params[] = $excludeClaim;
        return (int)($this->store->query($sql, $params)[0]['until_gamets'] ?? 0);
    }

    private function cooldownSql(string $field, bool $byActor, bool $excludeClaim): string
    {
        if (!in_array($field, ['global_cooldown_until_gamets','actor_cooldown_until_gamets'], true)) throw new LogicException('invalid_cooldown_field');
        $actor = $byActor ? " AND d.payload->>'self_id'=$3" : '';
        $exclude = $excludeClaim ? ' AND d.claim_id<>$'.($byActor ? '4' : '3') : '';
        return "SELECT COALESCE(MAX(CASE
 WHEN d.status='claimed' AND COALESCE(ca.data::jsonb->>'$field','') ~ '^[0-9]{1,18}$' THEN (ca.data::jsonb->>'$field')::numeric
 WHEN d.status<>'claimed' AND co.rowid IS NOT NULL AND COALESCE(co.data::jsonb->>'$field','') ~ '^[0-9]{1,18}$' THEN (co.data::jsonb->>'$field')::numeric
 ELSE 0 END),0)::text AS until_gamets
FROM plugins.correspondence_autonomy_decisions d
JOIN public.eventlog ca ON ca.rowid=CASE WHEN COALESCE(d.payload->'claim_anchor'->>'rowid','') ~ '^[1-9][0-9]{0,17}$' THEN (d.payload->'claim_anchor'->>'rowid')::bigint END
 AND ca.ts::text=d.payload->'claim_anchor'->>'ts' AND ca.gamets::text=d.payload->'claim_anchor'->>'gamets'
 AND ca.type=d.payload->'claim_anchor'->>'type' AND ca.data=d.payload->'claim_anchor'->>'data'
 AND ca.sess=d.payload->'claim_anchor'->>'sess' AND ca.localts::text=d.payload->'claim_anchor'->>'localts'
 AND ca.people=d.payload->'claim_anchor'->>'people'
LEFT JOIN public.eventlog co ON co.rowid=CASE WHEN COALESCE(d.payload->'completion_anchor'->>'rowid','') ~ '^[1-9][0-9]{0,17}$' THEN (d.payload->'completion_anchor'->>'rowid')::bigint END
 AND co.ts::text=d.payload->'completion_anchor'->>'ts' AND co.gamets::text=d.payload->'completion_anchor'->>'gamets'
 AND co.type=d.payload->'completion_anchor'->>'type' AND co.data=d.payload->'completion_anchor'->>'data'
 AND co.sess=d.payload->'completion_anchor'->>'sess' AND co.localts::text=d.payload->'completion_anchor'->>'localts'
 AND co.people=d.payload->'completion_anchor'->>'people'
WHERE d.playthrough_id=$1 AND ca.gamets<=$2::numeric
 AND ca.type='info' AND ca.sess='correspondence_v1'
 AND ca.data::jsonb->>'kind'='autonomy_claim'
 AND ca.data::jsonb->>'playthrough_id'=d.playthrough_id
 AND ca.data::jsonb->>'claim_id'=d.claim_id::text
 AND ca.data::jsonb->>'request_id'=d.request_id::text
 AND ca.data::jsonb->>'source_document_id'=d.source_document_id::text
 AND ca.data::jsonb->>'source_event_id'=d.source_event_id::text
 AND ca.data::jsonb->>'claim_gamets'=ca.gamets::text
 AND CASE WHEN d.status='claimed' THEN
     COALESCE(ca.data::jsonb->>'$field','') ~ '^[0-9]{1,18}$'
     AND (ca.data::jsonb->>'$field')::numeric>$2::numeric
   ELSE co.rowid IS NOT NULL AND co.gamets<=$2::numeric
     AND co.type='info' AND co.sess='correspondence_v1'
     AND co.data::jsonb->>'kind'='autonomy_completion'
     AND co.data::jsonb->>'playthrough_id'=d.playthrough_id
     AND co.data::jsonb->>'claim_id'=d.claim_id::text
     AND co.data::jsonb->>'source_document_id'=d.source_document_id::text
     AND co.data::jsonb->>'source_event_id'=d.source_event_id::text
     AND co.data::jsonb->>'claim_anchor_rowid'=d.payload->'claim_anchor'->>'rowid'
     AND co.data::jsonb->>'completed_gamets'=co.gamets::text
     AND COALESCE(co.data::jsonb->>'$field','') ~ '^[0-9]{1,18}$'
     AND (co.data::jsonb->>'$field')::numeric>$2::numeric
   END$actor$exclude";
    }

    private function validDecisionAnchors(array $decision, int $gamets): bool
    {
        if (!$this->validClaimAnchor($decision, $gamets)) return false;
        $payload = $decision['payload'] ?? null;
        if (!is_array($payload)) return false;
        foreach (['completed_gamets','global_cooldown_until_gamets','actor_cooldown_until_gamets'] as $key) {
            if (!is_string($payload[$key] ?? null) || preg_match('/\A[1-9][0-9]{0,17}\z/D', $payload[$key]) !== 1) return false;
        }
        if ((int)$payload['completed_gamets'] > $gamets
            || !in_array($payload['completed_status'] ?? null, ['silent','queued','failed','unknown','stale'], true)
            || !in_array($payload['completed_action'] ?? null, [null,'none','reply','forward','mediate'], true)
            || !array_key_exists('completed_child_document_id', $payload)
            || !array_key_exists('completed_due_gamets', $payload)) return false;
        $completionData = [
            'kind'=>'autonomy_completion','playthrough_id'=>(string)$decision['playthrough_id'],
            'claim_id'=>(string)$decision['claim_id'],'source_document_id'=>(string)$decision['source_document_id'],
            'source_event_id'=>(string)$decision['source_event_id'],
            'claim_anchor_rowid'=>(string)($payload['claim_anchor']['rowid'] ?? ''),
            'completed_status'=>$payload['completed_status'],'completed_action'=>$payload['completed_action'],
            'child_document_id'=>$payload['completed_child_document_id'],
            'due_gamets'=>$payload['completed_due_gamets'],
            'completed_gamets'=>$payload['completed_gamets'],
            'global_cooldown_until_gamets'=>$payload['global_cooldown_until_gamets'],
            'actor_cooldown_until_gamets'=>$payload['actor_cooldown_until_gamets'],
        ];
        if (array_key_exists('restore_generation', $payload)) {
            if (!is_string($payload['restore_generation']) || $payload['restore_generation'] === '') return false;
            $completionData['restore_generation'] = $payload['restore_generation'];
        }
        if (!$this->anchorMatches($payload['completion_anchor'] ?? null, $completionData, $payload['completed_gamets'], $gamets)) return false;
        return ($decision['action'] ?? null) === $payload['completed_action']
            && ($decision['child_document_id'] ?? null) === $payload['completed_child_document_id']
            && ($decision['due_gamets'] ?? null) === $payload['completed_due_gamets'];
    }

    private function validClaimAnchor(array $decision, int $gamets): bool
    {
        $payload = $decision['payload'] ?? null;
        if (!is_array($payload)) return false;
        foreach (['claim_gamets','claim_global_cooldown_until_gamets','claim_actor_cooldown_until_gamets'] as $key) {
            if (!is_string($payload[$key] ?? null) || preg_match('/\A[1-9][0-9]{0,17}\z/D', $payload[$key]) !== 1) return false;
        }
        if ((int)$payload['claim_gamets'] > $gamets) return false;
        $claimData = [
            'kind'=>'autonomy_claim','playthrough_id'=>(string)$decision['playthrough_id'],
            'claim_id'=>(string)$decision['claim_id'],'request_id'=>(string)$decision['request_id'],
            'source_document_id'=>(string)$decision['source_document_id'],'source_event_id'=>(string)$decision['source_event_id'],
            'claim_gamets'=>$payload['claim_gamets'],
            'global_cooldown_until_gamets'=>$payload['claim_global_cooldown_until_gamets'],
            'actor_cooldown_until_gamets'=>$payload['claim_actor_cooldown_until_gamets'],
        ];
        if (array_key_exists('restore_generation', $payload)) {
            if (!is_string($payload['restore_generation']) || $payload['restore_generation'] === '') return false;
            $claimData['restore_generation'] = $payload['restore_generation'];
        }
        return $this->anchorMatches($payload['claim_anchor'] ?? null, $claimData, $payload['claim_gamets'], $gamets);
    }

    private function claimMatchesWork(array $decision, array $work, int $gamets): bool
    {
        if (($decision['playthrough_id'] ?? null) !== $work['playthrough_id']
            || ($decision['source_document_id'] ?? null) !== $work['source_document_id']
            || ($decision['source_event_id'] ?? null) !== (string)$work['source_event_id']
            || ($decision['request_id'] ?? null) !== $work['request_id']) return false;
        $payload = $decision['payload'] ?? null;
        if (!is_array($payload) || ($payload['self_id'] ?? null) !== (string)$work['recipient_id']
            || ($payload['context_sha256'] ?? null) !== $work['context_sha256']
            || ($payload['source_event_sha256'] ?? null) !== $work['source_event_sha256']
            || ($payload['source_body_sha256'] ?? null) !== $work['source_body_sha256']
            || ($payload['restore_generation'] ?? null) !== ($work['restore_generation'] ?? null)) return false;
        return $this->validClaimAnchor($decision, $gamets)
            && hash_equals(correspondenceCanonicalJson($work['claim_anchor']), correspondenceCanonicalJson($payload['claim_anchor']));
    }

    private function closeLostClaim(array $work): array
    {
        $rows = $this->store->query("UPDATE plugins.correspondence_autonomy_decisions SET status='stale',payload=COALESCE(payload,'{}'::jsonb)||jsonb_build_object('reason_code','claim_anchor_invalid') WHERE playthrough_id=$1 AND source_document_id=$2 AND source_event_id=$3 AND claim_id=$4 AND status='claimed' AND action IS NULL AND child_document_id IS NULL AND due_gamets IS NULL RETURNING source_document_id::text", [
            $work['playthrough_id'],$work['source_document_id'],(string)$work['source_event_id'],$work['claim_id'],
        ]);
        return count($rows) === 1
            ? ['status'=>'stale','reason'=>'claim_anchor_invalid']
            : ['status'=>'stale','reason'=>'claim_changed'];
    }

    private function restoreStateChanged(mixed $expectedRestoreToken): ?bool
    {
        if (!is_string($expectedRestoreToken) || $expectedRestoreToken === '') return true;
        try {
            $current = $this->store->restoreGeneration();
            $request = $this->store->requestRestoreToken();
            if (!is_string($current) || !is_string($request)) return true;
            return !hash_equals($expectedRestoreToken, $current) || !hash_equals($expectedRestoreToken, $request);
        } catch (Throwable) {
            return null;
        }
    }

    private function restoreTokenMatches(mixed $expectedRestoreToken): bool
    {
        return $this->restoreStateChanged($expectedRestoreToken) === false;
    }
    private function runCompletionTransaction(callable $work, string $expectedRestoreToken): mixed
    {
        if (method_exists($this->store, 'completionTransaction')) return $this->store->completionTransaction($work, $expectedRestoreToken);
        return $this->store->transaction(function () use ($work, $expectedRestoreToken) {
            if (!$this->restoreTokenMatches($expectedRestoreToken)) throw new DomainException('restore_epoch_changed');
            $result = $work();
            if (!$this->restoreTokenMatches($expectedRestoreToken)) throw new DomainException('restore_epoch_changed');
            return $result;
        });
    }

    private function claimStillOwned(array $work): bool
    {
        if (!$this->restoreTokenMatches($work['restore_generation'] ?? null)) return false;
        try {
            return $this->store->readTransaction(function () use ($work): bool {
                $clock = $this->clock();
                if ($clock['playthrough_id'] !== $work['playthrough_id'] || $clock['gamets'] < $work['claim_gamets']) return false;
                $decision = $this->decisionRow($work['playthrough_id'], $work['source_document_id']);
                return $decision !== null && ($decision['status'] ?? null) === 'claimed'
                    && ($decision['claim_id'] ?? null) === $work['claim_id']
                    && $this->claimMatchesWork($decision, $work, $clock['gamets']);
            });
        } catch (Throwable) {
            return false;
        }
    }

    private function readCompletedDecision(array $work): ?array
    {
        if (!$this->restoreTokenMatches($work['restore_generation'] ?? null)) return null;
        try {
            return $this->store->readTransaction(function () use ($work): ?array {
                $clock = $this->clock();
                if ($clock['playthrough_id'] !== $work['playthrough_id']) return null;
                $decision = $this->decisionRow($work['playthrough_id'], $work['source_document_id']);
                if ($decision === null || ($decision['claim_id'] ?? null) !== $work['claim_id']
                    || ($decision['status'] ?? null) === 'claimed'
                    || ($decision['source_event_id'] ?? null) !== (string)$work['source_event_id']
                    || ($decision['payload']['restore_generation'] ?? null) !== ($work['restore_generation'] ?? null)) return null;
                $status = (string)$decision['status'];
                $payload = $decision['payload'] ?? null;
                if ($status === 'stale' && is_array($payload) && ($payload['reason_code'] ?? null) === 'claim_anchor_invalid'
                    && !array_key_exists('completion_anchor', $payload) && !array_key_exists('completed_status', $payload)
                    && ($decision['action'] ?? null) === null && ($decision['child_document_id'] ?? null) === null
                    && ($decision['due_gamets'] ?? null) === null) {
                    return ['status'=>'stale','reason'=>'claim_anchor_invalid'];
                }
                if (!$this->validDecisionAnchors($decision, $clock['gamets'])) return null;
                if ($status === 'queued') {
                    $childId = $decision['child_document_id'] ?? null;
                    $child = is_string($childId) ? $this->store->loadDocument($childId) : null;
                    if ($child === null || ($child['playthrough_id'] ?? null) !== $work['playthrough_id']
                        || ($child['request_id'] ?? null) !== $work['request_id']
                        || !$this->store->eventMatches($child['anchor'] ?? [])) return null;
                    return ['status'=>'queued','reason'=>'queued_for_transit'];
                }
                if (!in_array($status, ['silent','failed','stale','unknown','dispatched'], true)) return null;
                return ['status'=>$status,'reason'=>(string)($decision['payload']['reason_code'] ?? 'completed')];
            });
        } catch (Throwable) {
            return null;
        }
    }
    private function anchorMatches(mixed $anchor, array $expectedData, string $expectedGamets, int $currentGamets): bool
    {
        if (!is_array($anchor) || ($anchor['type'] ?? null) !== 'info' || ($anchor['sess'] ?? null) !== 'correspondence_v1'
            || ($anchor['gamets'] ?? null) !== $expectedGamets || !is_string($anchor['data'] ?? null)
            || (int)$expectedGamets > $currentGamets || !$this->store->eventMatches($anchor)) return false;
        try {
            $actual = json_decode($anchor['data'], true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($actual) || array_is_list($actual)) return false;
            return hash_equals(correspondenceCanonicalJson($expectedData), correspondenceCanonicalJson($actual));
        } catch (JsonException|InvalidArgumentException) {
            return false;
        }
    }

    private function sweepAbandonedClaims(string $scope, int $gamets, string $restoreGeneration): int
    {
        $rows = $this->store->query("SELECT playthrough_id,source_document_id::text,source_event_id::text,claim_id::text,request_id::text,payload::text FROM plugins.correspondence_autonomy_decisions WHERE playthrough_id=$1 AND status='claimed' AND (payload->>'restore_generation' IS DISTINCT FROM $2 OR created_at <= clock_timestamp()-interval '180 seconds') ORDER BY created_at,source_document_id LIMIT 64 FOR UPDATE", [$scope,$restoreGeneration]);
        $retired = 0;
        foreach ($rows as $row) {
            $decision = array_merge($row, ['payload'=>$this->jsonObject($row['payload'] ?? null)]);
            $work = $this->claimWorkForRetirement($decision);
            if ($work === null || !$this->claimMatchesWork($decision, $work, $gamets)) {
                // Inert quarantine never turns missing proof into a completion or permission to retry.
                if ($this->closeLostClaim($row)['reason'] === 'claim_anchor_invalid') $retired++;
                continue;
            }
            $reason = ($work['restore_generation'] ?? null) !== $restoreGeneration ? 'restore_changed' : 'abandoned_claim_expired';
            $metrics = $this->attemptCounters($decision['payload']);
            $this->markTerminal($work, 'stale', null, null, null, $reason, $gamets, $metrics['model_calls'], $metrics['input_chars'], $metrics['output_chars']);
            $retired++;
        }
        return $retired;
    }

    private function claimWorkForRetirement(array $decision): ?array
    {
        $payload = $decision['payload'] ?? null;
        $playthroughId = $decision['playthrough_id'] ?? null;
        $sourceDocumentId = $decision['source_document_id'] ?? null;
        $sourceEventId = $decision['source_event_id'] ?? null;
        $claimId = $decision['claim_id'] ?? null;
        $requestId = $decision['request_id'] ?? null;
        if (!is_array($payload) || !is_string($playthroughId) || !is_string($sourceDocumentId)
            || preg_match('/\A[0-9a-f-]{36}\z/D', $sourceDocumentId) !== 1
            || !is_string($sourceEventId) || preg_match('/\A[1-9][0-9]{0,17}\z/D', $sourceEventId) !== 1
            || !is_string($claimId) || preg_match('/\A[0-9a-f-]{36}\z/D', $claimId) !== 1
            || !is_string($requestId) || preg_match('/\A[0-9a-f-]{36}\z/D', $requestId) !== 1) return null;
        foreach (['claim_gamets','claim_global_cooldown_until_gamets','claim_actor_cooldown_until_gamets','self_id'] as $key) {
            if (!is_string($payload[$key] ?? null) || preg_match('/\A[1-9][0-9]{0,17}\z/D', $payload[$key]) !== 1) return null;
        }
        foreach (['source_event_sha256','source_body_sha256','context_sha256'] as $key) {
            if (!is_string($payload[$key] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $payload[$key]) !== 1) return null;
        }
        $recipientId = filter_var($payload['self_id'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        $sourceEventIdInt = filter_var($sourceEventId, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if (!is_int($recipientId) || !is_int($sourceEventIdInt) || !is_array($payload['claim_anchor'] ?? null)
            || (array_key_exists('restore_generation', $payload) && (!is_string($payload['restore_generation']) || $payload['restore_generation'] === ''))) return null;
        return [
            'playthrough_id'=>$playthroughId,
            'source_document_id'=>$sourceDocumentId,
            'source_event_id'=>$sourceEventIdInt,
            'claim_id'=>$claimId,
            'request_id'=>$requestId,
            'recipient_id'=>$recipientId,
            'claim_gamets'=>(int)$payload['claim_gamets'],
            'claim_global_cooldown_until_gamets'=>(int)$payload['claim_global_cooldown_until_gamets'],
            'claim_actor_cooldown_until_gamets'=>(int)$payload['claim_actor_cooldown_until_gamets'],
            'source_event_sha256'=>$payload['source_event_sha256'],
            'source_body_sha256'=>$payload['source_body_sha256'],
            'context_sha256'=>$payload['context_sha256'],
            'claim_anchor'=>$payload['claim_anchor'],
            'restore_generation'=>$payload['restore_generation'] ?? null,
        ];
    }

    private function flushDue(string $scope, array $clock, array $setting): ?array
    {
        $rows = $this->store->query("SELECT source_document_id::text,child_document_id::text,due_gamets::text FROM plugins.correspondence_autonomy_decisions WHERE playthrough_id=$1 AND status='queued' AND child_document_id IS NOT NULL AND due_gamets <= $2::numeric ORDER BY due_gamets,created_at,source_document_id LIMIT 1", [$scope, (string)$clock['gamets']]);
        if ($rows === []) return null;
        $row = $rows[0];
        $sourceId = (string)$row['source_document_id'];
        $childId = (string)$row['child_document_id'];
        $decision = $this->decisionRow($scope, $sourceId);
        if ($decision === null) return ['kind'=>'dispatch','status'=>'failed','reason'=>'decision_missing'];
        $payload = $decision['payload'];
        $selfId = (int)($payload['self_id'] ?? 0);
        $child = $this->store->loadDocument($childId);
        if ($child === null) return $this->setDispatchState($decision, 'stale', 'child_missing');
        if ($child['operation_id'] !== null) {
            $this->setDispatchState($decision, 'unknown', 'dispatch_already_claimed');
            return ['kind'=>'dispatch','status'=>'unknown','reason'=>'dispatch_already_claimed'];
        }
        if (!$this->validDecisionAnchors($decision, $clock['gamets'])) return $this->setDispatchState($decision, 'stale', 'decision_anchor_invalid');
        if (!$this->dbBoolean($setting['enabled'] ?? false)) return ['kind'=>'dispatch','status'=>'idle','reason'=>'disabled'];
        try {
            $exposed = $this->service->readExposedSource((int)$decision['source_event_id'], $selfId);
        } catch (DomainException|InvalidArgumentException $error) {
            if (in_array($error->getMessage(), ['player_unavailable','actor_unavailable','identity_changed','actor_identity_changed','source_not_due'], true)) {
                return ['kind'=>'dispatch','status'=>'queued','reason'=>$error->getMessage()];
            }
            return $this->setDispatchState($decision, 'stale', 'source_changed');
        }
        if (!hash_equals((string)$payload['source_event_sha256'], hash('sha256', $exposed['event']['data']))
            || !hash_equals((string)$payload['source_body_sha256'], hash('sha256', $exposed['source']['body']))) return $this->setDispatchState($decision, 'stale', 'source_changed');
        if (($child['playthrough_id'] ?? null) !== $scope || ($child['request_id'] ?? null) !== $decision['request_id']
            || !$this->store->eventMatches($child['anchor'] ?? []) || ($child['operation_id'] ?? null) !== null
            || $this->documentActorKey($child['author'] ?? null) !== 'npc:'.$selfId
            || $this->documentActorKey($child['payer'] ?? null) !== 'npc:'.$selfId
            || $this->documentActorKey($child['claimed_sender'] ?? null) !== 'npc:'.$selfId
            || ($child['attachments'] ?? null) !== []) return $this->setDispatchState($decision, 'stale', 'child_changed');
        $npcById = $this->npcMap($this->npcRows());
        if (!isset($npcById[$selfId]) || !$this->eligible($npcById[$selfId]) || $npcById[$selfId]['name'] !== $exposed['source']['recipient']['name']) return $this->setDispatchState($decision, 'stale', 'actor_changed');
        if (($child['recipient']['kind'] ?? null) === 'npc') {
            $targetId = (int)($child['recipient']['id'] ?? 0);
            if (!isset($npcById[$targetId]) || !$this->eligible($npcById[$targetId]) || $npcById[$targetId]['name'] !== ($child['recipient']['name'] ?? null)
                || $this->documentActorKey($child['recipient'] ?? null) !== ($payload['target_key'] ?? null)) return $this->setDispatchState($decision, 'stale', 'actor_changed');
        } elseif (($child['recipient']['kind'] ?? null) === 'player') {
            if ($this->store->player() !== ($child['recipient']['name'] ?? null)
                || $this->documentActorKey($child['recipient'] ?? null) !== ($payload['target_key'] ?? null)) return $this->setDispatchState($decision, 'stale', 'actor_changed');
        } else return $this->setDispatchState($decision, 'stale', 'actor_changed');
        $this->store->query('SAVEPOINT correspondence_autonomy_dispatch');
        try {
            $dispatched = $this->service->dispatch($childId);
        } catch (Throwable) {
            $this->store->query('ROLLBACK TO SAVEPOINT correspondence_autonomy_dispatch');
            $this->store->query('RELEASE SAVEPOINT correspondence_autonomy_dispatch');
            $this->setDispatchState($decision, 'unknown', 'dispatch_uncertain');
            return ['kind'=>'dispatch','status'=>'unknown','reason'=>'dispatch_uncertain'];
        }
        $this->store->query('RELEASE SAVEPOINT correspondence_autonomy_dispatch');
        if (($dispatched['state'] ?? null) === 'dispatched') {
            $this->setDispatchState($decision, 'dispatched', 'dispatch_queued');
            return ['kind'=>'dispatch','status'=>'dispatched','reason'=>'dispatch_queued'];
        }
        if (($dispatched['reason'] ?? null) === 'executor_unavailable') {
            $this->setDispatchState($decision, 'queued', 'executor_unavailable');
            return ['kind'=>'dispatch','status'=>'queued','reason'=>'executor_unavailable'];
        }
        $this->setDispatchState($decision, 'unknown', 'dispatch_unknown');
        return ['kind'=>'dispatch','status'=>'unknown','reason'=>'dispatch_unknown'];
    }

    private function setDispatchState(array $decision, string $status, string $reason): array
    {
        $payload = $decision['payload'];
        $payload['reason_code'] = $reason;
        $this->store->query('UPDATE plugins.correspondence_autonomy_decisions SET status=$1,payload=$2::jsonb WHERE playthrough_id=$3 AND source_document_id=$4', [$status, correspondenceJson($payload), $decision['playthrough_id'], $decision['source_document_id']]);
        return ['kind'=>'dispatch','status'=>$status,'reason'=>$reason];
    }

    private function documentActorKey(mixed $actor): ?string
    {
        if (!is_array($actor)) return null;
        if (($actor['kind'] ?? null) === 'player' && ($actor['id'] ?? null) === null) return 'player';
        if (($actor['kind'] ?? null) !== 'npc' || !is_int($actor['id'] ?? null) || $actor['id'] < 1) return null;
        return 'npc:'.$actor['id'];
    }

    private function cleanReconsiderablePredicate(string $alias): string
    {
        if (!in_array($alias, ['retry','existing'], true)) throw new LogicException('invalid_retry_alias');
        $evidenceKeys = "'claim_anchor','completion_anchor','claim_global_cooldown_until_gamets','claim_actor_cooldown_until_gamets','global_cooldown_until_gamets','actor_cooldown_until_gamets','self_id','source_body_sha256','context_sha256','restore_generation','model_calls','input_chars','output_chars','completed_status','completed_action','completed_child_document_id','completed_due_gamets','target_key'";
        return "{$alias}.action IS NULL AND {$alias}.child_document_id IS NULL AND {$alias}.due_gamets IS NULL AND NOT ({$alias}.payload ?| ARRAY[{$evidenceKeys}]::text[])";
    }

    private function sourceCandidates(string $scope, int $gamets, int $minimumAge): array
    {
        $cleanRetry = $this->cleanReconsiderablePredicate('retry');
        $fetch = function(bool $recheck, int $limit, int $offset) use ($scope, $gamets, $minimumAge, $cleanRetry): array {
            $laneFilter = $recheck ? <<<'SQL'
  AND retry.status='stale'
  AND retry.payload->'reconsiderable'='true'::jsonb
  AND CLEAN_RETRY_PREDICATE
  AND CASE
      WHEN COALESCE(retry.payload->>'recheck_after_gamets','') ~ '^[1-9][0-9]{0,18}$'
      THEN CASE
          WHEN (retry.payload->>'recheck_after_gamets')::numeric <= 9223372036854775807::numeric
          THEN (retry.payload->>'recheck_after_gamets')::numeric <= $2::numeric
          ELSE TRUE
      END
      ELSE TRUE
  END
SQL
                : <<<'SQL'
  AND retry.source_document_id IS NULL
  AND NOT EXISTS (
      SELECT 1 FROM plugins.correspondence_autonomy_decisions prior
      WHERE prior.playthrough_id=d.playthrough_id
        AND (prior.source_document_id=d.document_id OR prior.source_event_id=e.rowid)
  )
  AND e.people=('|'||n.npc_name||'|')
  AND COALESCE(n.extended_data->>'background_life_enabled','') IN ('true','1','t','on')
  AND COALESCE(n.extended_data->>'background_life_letters','') IN ('true','1','t','on')
  AND COALESCE(n.metadata->'stats'->>'is_dead','') NOT IN ('true','1','t','on')
SQL;
            if ($recheck) $laneFilter = str_replace('CLEAN_RETRY_PREDICATE', $cleanRetry, $laneFilter);
            $ordering = $recheck
                ? "ORDER BY CASE WHEN COALESCE(retry.payload->>'recheck_after_gamets','') ~ '^[1-9][0-9]{0,18}$' THEN CASE WHEN (retry.payload->>'recheck_after_gamets')::numeric <= 9223372036854775807::numeric THEN 0 ELSE 1 END ELSE 1 END ASC,CASE WHEN COALESCE(retry.payload->>'recheck_after_gamets','') ~ '^[1-9][0-9]{0,18}$' THEN CASE WHEN (retry.payload->>'recheck_after_gamets')::numeric <= 9223372036854775807::numeric THEN (retry.payload->>'recheck_after_gamets')::numeric ELSE 9223372036854775807::numeric END ELSE 9223372036854775807::numeric END ASC,retry.created_at ASC,e.rowid ASC"
                : 'ORDER BY e.rowid ASC';
            $sql = <<<SQL
SELECT e.rowid::text AS source_event_id,e.gamets::text AS source_gamets,e.data AS source_data,
       d.document_id::text AS source_document_id,d.payload->'recipient'->>'id' AS recipient_id,
       COALESCE(retry.root_document_id,parent.root_document_id,d.document_id)::text AS root_document_id,
       COALESCE(retry.depth,parent.depth+1,0) AS source_depth,
       retry.payload->>'reconsiderable' AS reconsiderable,
       CASE WHEN COALESCE(retry.payload->>'recheck_after_gamets','') ~ '^[1-9][0-9]{0,18}$'
            THEN CASE WHEN (retry.payload->>'recheck_after_gamets')::numeric <= 9223372036854775807::numeric THEN TRUE ELSE FALSE END
            ELSE FALSE END AS recheck_after_valid
FROM public.eventlog e
JOIN plugins.correspondence_operations o
  ON e.rowid=CASE WHEN COALESCE(o.payload->'source'->>'rowid','') ~ '^[1-9][0-9]{0,17}$' THEN (o.payload->'source'->>'rowid')::bigint END
 AND e.data=o.payload->'source'->>'data'
 AND e.ts::text=o.payload->'source'->>'ts'
 AND e.gamets::text=o.payload->'source'->>'gamets'
 AND e.type=o.payload->'source'->>'type'
 AND e.sess=o.payload->'source'->>'sess'
 AND e.localts::text=o.payload->'source'->>'localts'
 AND e.people=o.payload->'source'->>'people'
JOIN plugins.correspondence_documents d ON d.document_id=o.document_id
JOIN public.core_npc_master n ON n.id=CASE WHEN COALESCE(d.payload->'recipient'->>'id','') ~ '^[1-9][0-9]{0,17}$' THEN (d.payload->'recipient'->>'id')::bigint END
JOIN public.eventlog da ON da.rowid=CASE WHEN COALESCE(d.payload->'anchor'->>'rowid','') ~ '^[1-9][0-9]{0,17}$' THEN (d.payload->'anchor'->>'rowid')::bigint END
 AND da.type='info' AND da.sess='correspondence_v1'
 AND da.data LIKE ('{"document_id":"'||d.document_id::text||'","scope_token":"%')
JOIN public.eventlog xa ON xa.rowid=CASE WHEN COALESCE(o.payload->'dispatch_anchor'->>'rowid','') ~ '^[1-9][0-9]{0,17}$' THEN (o.payload->'dispatch_anchor'->>'rowid')::bigint END
 AND xa.type='info' AND xa.sess='correspondence_v1'
 AND xa.data=('{"document_id":"'||d.document_id::text||'","operation_id":"'||o.operation_id::text||'"}')
JOIN public.eventlog ra ON ra.rowid=CASE WHEN COALESCE(o.payload->'receipt_anchor'->>'rowid','') ~ '^[1-9][0-9]{0,17}$' THEN (o.payload->'receipt_anchor'->>'rowid')::bigint END
 AND ra.type='info' AND ra.sess='correspondence_v1'
 AND ra.data LIKE ('{"document_id":"'||d.document_id::text||'","operation_id":"'||o.operation_id::text||'","receipt_hash":"%')
LEFT JOIN plugins.correspondence_autonomy_decisions retry
  ON retry.playthrough_id=d.playthrough_id AND retry.source_document_id=d.document_id AND retry.source_event_id=e.rowid
LEFT JOIN plugins.correspondence_autonomy_decisions parent
  ON parent.playthrough_id=d.playthrough_id AND parent.child_document_id=d.document_id
WHERE d.playthrough_id=$1
  AND d.payload->>'operation_id'=o.operation_id::text
  AND e.type='info' AND e.sess='written_evidence_v1'
  AND left(e.data,length('{"schema_version":1,"document_id":"'||d.document_id::text||'",'))='{"schema_version":1,"document_id":"'||d.document_id::text||'",'
  AND e.gamets <= $2::numeric - $3::numeric
  AND o.payload->'receipt'->>'status'='delivered'
  AND d.payload->'recipient'->>'kind'='npc'
  AND (parent.depth IS NULL OR (parent.depth<3 AND (
      SELECT count(*) FROM plugins.correspondence_autonomy_decisions chain
      WHERE chain.playthrough_id=d.playthrough_id AND chain.root_document_id=parent.root_document_id AND chain.child_document_id IS NOT NULL
  )<3))
  {$laneFilter}
  AND NOT EXISTS (
      SELECT 1 FROM plugins.correspondence_autonomy_decisions c
      JOIN public.eventlog cca ON cca.rowid=CASE WHEN COALESCE(c.payload->'claim_anchor'->>'rowid','') ~ '^[1-9][0-9]{0,17}$' THEN (c.payload->'claim_anchor'->>'rowid')::bigint END
       AND cca.ts::text=c.payload->'claim_anchor'->>'ts' AND cca.gamets::text=c.payload->'claim_anchor'->>'gamets'
       AND cca.type=c.payload->'claim_anchor'->>'type' AND cca.data=c.payload->'claim_anchor'->>'data'
       AND cca.sess=c.payload->'claim_anchor'->>'sess' AND cca.localts::text=c.payload->'claim_anchor'->>'localts'
       AND cca.people=c.payload->'claim_anchor'->>'people'
      LEFT JOIN public.eventlog cco ON cco.rowid=CASE WHEN COALESCE(c.payload->'completion_anchor'->>'rowid','') ~ '^[1-9][0-9]{0,17}$' THEN (c.payload->'completion_anchor'->>'rowid')::bigint END
       AND cco.ts::text=c.payload->'completion_anchor'->>'ts' AND cco.gamets::text=c.payload->'completion_anchor'->>'gamets'
       AND cco.type=c.payload->'completion_anchor'->>'type' AND cco.data=c.payload->'completion_anchor'->>'data'
       AND cco.sess=c.payload->'completion_anchor'->>'sess' AND cco.localts::text=c.payload->'completion_anchor'->>'localts'
       AND cco.people=c.payload->'completion_anchor'->>'people'
      WHERE c.playthrough_id=d.playthrough_id
        AND c.payload->>'self_id'=d.payload->'recipient'->>'id'
        AND cca.gamets<=$2::numeric AND cca.type='info' AND cca.sess='correspondence_v1'
        AND cca.data::jsonb->>'kind'='autonomy_claim'
        AND cca.data::jsonb->>'playthrough_id'=c.playthrough_id
        AND cca.data::jsonb->>'claim_id'=c.claim_id::text
        AND cca.data::jsonb->>'request_id'=c.request_id::text
        AND cca.data::jsonb->>'source_document_id'=c.source_document_id::text
        AND cca.data::jsonb->>'source_event_id'=c.source_event_id::text
        AND cca.data::jsonb->>'claim_gamets'=cca.gamets::text
        AND ((c.status='claimed'
              AND COALESCE(cca.data::jsonb->>'actor_cooldown_until_gamets','') ~ '^[0-9]{1,18}$'
              AND (cca.data::jsonb->>'actor_cooldown_until_gamets')::numeric>$2::numeric)
          OR (c.status<>'claimed' AND cco.rowid IS NOT NULL AND cco.gamets<=$2::numeric
              AND cco.type='info' AND cco.sess='correspondence_v1'
              AND cco.data::jsonb->>'kind'='autonomy_completion'
              AND cco.data::jsonb->>'playthrough_id'=c.playthrough_id
              AND cco.data::jsonb->>'claim_id'=c.claim_id::text
              AND cco.data::jsonb->>'source_document_id'=c.source_document_id::text
              AND cco.data::jsonb->>'source_event_id'=c.source_event_id::text
              AND cco.data::jsonb->>'claim_anchor_rowid'=c.payload->'claim_anchor'->>'rowid'
              AND cco.data::jsonb->>'completed_gamets'=cco.gamets::text
              AND COALESCE(cco.data::jsonb->>'actor_cooldown_until_gamets','') ~ '^[0-9]{1,18}$'
              AND (cco.data::jsonb->>'actor_cooldown_until_gamets')::numeric>$2::numeric))
  )
{$ordering}
LIMIT $4 OFFSET $5
SQL;
            return $this->store->query($sql, [$scope, (string)$gamets, (string)$minimumAge, $limit, $offset]);
        };

        $fresh = $fetch(false, 32, 0);
        $recheckable = $fetch(true, 32, 0);
        $rows = array_merge($fresh, $recheckable);
        $remaining = 64 - count($rows);
        if ($remaining > 0 && count($fresh) < 32 && count($recheckable) === 32) {
            $rows = array_merge($rows, $fetch(true, $remaining, 32));
        } elseif ($remaining > 0 && count($recheckable) < 32 && count($fresh) === 32) {
            $rows = array_merge($rows, $fetch(false, $remaining, 32));
        }
        usort($rows, static fn(array $left, array $right): int =>
            ((int)($left['source_event_id'] ?? 0)) <=> ((int)($right['source_event_id'] ?? 0)));
        return $rows;
    }

    private function deferSource(string $scope, string $documentId, int $eventId, array $candidate, int $gamets, string $reason, ?int $recheckAfter = null): void
    {
        if ($eventId < 1 || preg_match('/\A[0-9a-f-]{36}\z/D', $documentId) !== 1) return;
        $delay = correspondenceAutonomyGamets(1);
        if ($recheckAfter === null || $recheckAfter <= $gamets) {
            $recheckAfter = $gamets > PHP_INT_MAX - $delay ? PHP_INT_MAX : $gamets + $delay;
        }
        $rootDocumentId = is_string($candidate['root_document_id'] ?? null)
            && preg_match('/\A[0-9a-f-]{36}\z/D', $candidate['root_document_id']) === 1
                ? $candidate['root_document_id'] : $documentId;
        $depth = filter_var($candidate['source_depth'] ?? 0, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0,'max_range'=>3]]);
        if ($depth === false) $depth = 0;
        $claim = correspondenceUuid();
        $payload = [
            'reason_code'=>$reason,
            'reconsiderable'=>true,
            'recheck_after_gamets'=>(string)$recheckAfter,
            'claim_gamets'=>(string)$gamets,
            'completed_gamets'=>(string)$gamets,
            'source_event_sha256'=>is_string($candidate['source_data'] ?? null) ? hash('sha256', $candidate['source_data']) : '',
        ];
        $cleanRetry = $this->cleanReconsiderablePredicate('existing');
        $this->store->query("INSERT INTO plugins.correspondence_autonomy_decisions AS existing(playthrough_id,source_document_id,source_event_id,claim_id,request_id,root_document_id,depth,status,action,payload) VALUES($1,$2,$3,$4,$4,$5,$6,'stale',NULL,$7::jsonb) ON CONFLICT(playthrough_id,source_document_id) DO UPDATE SET source_event_id=EXCLUDED.source_event_id,claim_id=EXCLUDED.claim_id,request_id=EXCLUDED.request_id,root_document_id=EXCLUDED.root_document_id,depth=EXCLUDED.depth,status='stale',action=NULL,child_document_id=NULL,due_gamets=NULL,payload=EXCLUDED.payload WHERE existing.status='stale' AND existing.payload->'reconsiderable'='true'::jsonb AND {$cleanRetry} RETURNING source_document_id::text", [$scope,$documentId,$eventId,$claim,$rootDocumentId,$depth,correspondenceJson($payload)]);
    }

    private function recordStaleSource(string $scope, string $documentId, int $eventId, mixed $data, int $gamets, string $reason='source_unavailable'): void
    {
        if ($eventId < 1 || preg_match('/\A[0-9a-f-]{36}\z/D', $documentId) !== 1) return;
        $lineage = $this->lineage($scope, $documentId);
        $claim = correspondenceUuid();
        $payload = [
            'reason_code'=>$reason,
            'claim_gamets'=>$gamets,
            'completed_gamets'=>$gamets,
            'source_event_sha256'=>is_string($data) ? hash('sha256', $data) : '',
        ];
        $cleanRetry = $this->cleanReconsiderablePredicate('existing');
        $this->store->query("INSERT INTO plugins.correspondence_autonomy_decisions AS existing(playthrough_id,source_document_id,source_event_id,claim_id,request_id,root_document_id,depth,status,action,payload) VALUES($1,$2,$3,$4,$4,$5,$6,'stale',NULL,$7::jsonb) ON CONFLICT(playthrough_id,source_document_id) DO UPDATE SET status='stale',action=NULL,child_document_id=NULL,due_gamets=NULL,payload=EXCLUDED.payload WHERE existing.status='stale' AND existing.payload->'reconsiderable'='true'::jsonb AND {$cleanRetry}", [$scope,$documentId,$eventId,$claim,$lineage['root_document_id'],$lineage['depth'],correspondenceJson($payload)]);
    }

    private function claim(string $scope, string $documentId, int $eventId, int $gamets, array $lineage, array $context, string $eventData, string $restoreGeneration): ?array
    {
        $claim = correspondenceUuid();
        $sourceBodyHash = hash('sha256', $context['source_body']);
        $claimGlobalCooldown = $gamets + correspondenceAutonomyGamets(1);
        $claimActorCooldown = $gamets + correspondenceAutonomyGamets(6);
        $payload = [
            'reason_code'=>'claimed',
            'claim_gamets'=>(string)$gamets,
            'claim_global_cooldown_until_gamets'=>(string)$claimGlobalCooldown,
            'claim_actor_cooldown_until_gamets'=>(string)$claimActorCooldown,
            'global_cooldown_until_gamets'=>(string)$claimGlobalCooldown,
            'actor_cooldown_until_gamets'=>(string)$claimActorCooldown,
            'self_id'=>(string)$context['self_row']['id'],
            'source_event_sha256'=>hash('sha256', $eventData),
            'source_body_sha256'=>$sourceBodyHash,
            'context_sha256'=>$context['context_sha256'],
            'restore_generation'=>$restoreGeneration,
            'claim_anchor'=>null,
        ];
        $cleanRetry = $this->cleanReconsiderablePredicate('existing');
        $rows = $this->store->query("INSERT INTO plugins.correspondence_autonomy_decisions AS existing(playthrough_id,source_document_id,source_event_id,claim_id,request_id,root_document_id,depth,status,action,payload) VALUES($1,$2,$3,$4,$4,$5,$6,'claimed',NULL,$7::jsonb) ON CONFLICT(playthrough_id,source_document_id) DO UPDATE SET source_event_id=EXCLUDED.source_event_id,claim_id=EXCLUDED.claim_id,request_id=EXCLUDED.request_id,root_document_id=EXCLUDED.root_document_id,depth=EXCLUDED.depth,status='claimed',action=NULL,child_document_id=NULL,due_gamets=NULL,payload=EXCLUDED.payload,created_at=clock_timestamp() WHERE existing.status='stale' AND existing.payload->'reconsiderable'='true'::jsonb AND {$cleanRetry} AND CASE WHEN COALESCE(existing.payload->>'recheck_after_gamets','') ~ '^[1-9][0-9]{0,18}$' THEN (existing.payload->>'recheck_after_gamets')::numeric <= 9223372036854775807::numeric AND (existing.payload->>'recheck_after_gamets')::numeric <= $8::numeric ELSE FALSE END RETURNING claim_id::text", [$scope,$documentId,$eventId,$claim,$lineage['root_document_id'],$lineage['depth'],correspondenceJson($payload),(string)$gamets]);
        if ($rows === []) return null;
        $claimData = [
            'kind'=>'autonomy_claim','playthrough_id'=>$scope,'claim_id'=>$claim,'request_id'=>$claim,
            'source_document_id'=>$documentId,'source_event_id'=>(string)$eventId,'claim_gamets'=>(string)$gamets,
            'global_cooldown_until_gamets'=>(string)$claimGlobalCooldown,'actor_cooldown_until_gamets'=>(string)$claimActorCooldown,
            'restore_generation'=>$restoreGeneration,
        ];
        $claimAnchor = $this->store->appendEvent('correspondence_anchor', correspondenceJson($claimData), '', 'correspondence_v1');
        $payload['claim_anchor'] = $claimAnchor;
        $updated = $this->store->query("UPDATE plugins.correspondence_autonomy_decisions SET payload=$1::jsonb WHERE playthrough_id=$2 AND source_document_id=$3 AND claim_id=$4 AND status='claimed' RETURNING claim_id::text", [correspondenceJson($payload),$scope,$documentId,$claim]);
        if (count($updated) !== 1) throw new DomainException('claim_lost');
        return [
            'playthrough_id'=>$scope,'source_document_id'=>$documentId,'source_event_id'=>$eventId,
            'recipient_id'=>(int)$context['self_row']['id'],'claim_id'=>$claim,'request_id'=>$claim,
            'root_document_id'=>$lineage['root_document_id'],'depth'=>$lineage['depth'],'claim_gamets'=>$gamets,
            'source_event_sha256'=>hash('sha256',$eventData),'source_body_sha256'=>$sourceBodyHash,
            'claim_anchor'=>$claimAnchor,'claim_global_cooldown_until_gamets'=>$claimGlobalCooldown,'restore_generation'=>$restoreGeneration,
            'claim_actor_cooldown_until_gamets'=>$claimActorCooldown,
        ];
    }

    private function lineage(string $scope, string $documentId): array
    {
        $rows = $this->store->query('SELECT root_document_id::text,depth FROM plugins.correspondence_autonomy_decisions WHERE playthrough_id=$1 AND child_document_id=$2 LIMIT 1', [$scope,$documentId]);
        if ($rows === []) return ['root_document_id'=>$documentId,'depth'=>0];
        return ['root_document_id'=>(string)$rows[0]['root_document_id'],'depth'=>(int)$rows[0]['depth']+1];
    }

    private function linkCount(string $scope, string $rootDocumentId): int
    {
        return (int)($this->store->query('SELECT count(*)::int AS links FROM plugins.correspondence_autonomy_decisions WHERE playthrough_id=$1 AND root_document_id=$2 AND child_document_id IS NOT NULL', [$scope,$rootDocumentId])[0]['links'] ?? 0);
    }

    private function npcRows(): array
    {
        return $this->store->query('SELECT id,npc_name,personality,extended_data::text AS extended_data,metadata::text AS metadata FROM public.core_npc_master WHERE id>0 ORDER BY id LIMIT 10000');
    }

    private function npcMap(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $id = filter_var($row['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if ($id === false || !is_string($row['npc_name'] ?? null) || !correspondenceActorName($row['npc_name'])) continue;
            $extended = $this->jsonObject($row['extended_data'] ?? null);
            $metadata = $this->jsonObject($row['metadata'] ?? null);
            $stats = is_array($metadata['stats'] ?? null) ? $metadata['stats'] : [];
            $personality = $row['personality'] ?? '';
            if (!is_string($personality) || strlen($personality)>8000 || preg_match('//u',$personality)!==1 || preg_match_all('/./us',$personality)>2000) $personality = '';
            $relationships = $extended['relationships'] ?? [];
            if (!is_array($relationships) || array_is_list($relationships)) $relationships = [];
            $coords = $metadata['last_coords'] ?? null;
            if (is_string($coords)) $coords = json_decode($coords, true);
            $location = is_array($coords) && is_string($coords[3] ?? null) && strlen($coords[3])<=200 && preg_match('//u',$coords[3])===1 ? $coords[3] : '';
            $result[(int)$id] = [
                'id'=>(int)$id,'key'=>'npc:'.(int)$id,'name'=>$row['npc_name'],
                'enabled_raw'=>$extended['background_life_enabled'] ?? null,
                'letters_raw'=>$extended['background_life_letters'] ?? null,
                'dead_raw'=>$stats['is_dead'] ?? null,
                'enabled'=>$this->bglBoolean($extended['background_life_enabled'] ?? false),
                'letters'=>$this->bglBoolean($extended['background_life_letters'] ?? false),
                'dead'=>$this->bglBoolean($stats['is_dead'] ?? false),
                'personality'=>$personality,'location'=>$location,'relationships'=>$relationships,
            ];
        }
        return $result;
    }

    private function jsonObject(mixed $raw): array
    {
        if (!is_string($raw) || $raw==='') return [];
        try { $value=json_decode($raw,true,64,JSON_THROW_ON_ERROR); }
        catch (JsonException) { return []; }
        return is_array($value) && !array_is_list($value) ? $value : [];
    }

    private function bglBoolean(mixed $value): bool
    {
        return $value === true || $value === 1 || (is_string($value) && in_array($value, self::TRUE_VALUES, true));
    }

    private function eligible(array $npc): bool
    {
        return ($npc['enabled'] ?? false) === true && ($npc['letters'] ?? false) === true && ($npc['dead'] ?? false) !== true;
    }

    private function contextForSource(array $source, array $npcById): ?array
    {
        $selfId = (int)($source['recipient']['id'] ?? 0);
        $selfRow = $npcById[$selfId] ?? null;
        if (!is_array($selfRow) || !$this->eligible($selfRow)) return null;
        $self = ['key'=>$selfRow['key'],'name'=>$selfRow['name'],'personality'=>$selfRow['personality']];
        $actors = $this->service->actors();
        $player = null;
        $actorByKey = [];
        foreach ($actors as $actor) {
            if (($actor['kind'] ?? null) === 'player') $player = ['key'=>'player','name'=>$actor['name'],'kind'=>'player'];
            elseif (($actor['kind'] ?? null) === 'npc') $actorByKey[$actor['key']] = $actor;
        }
        $eligible = [];
        foreach ($actorByKey as $key=>$actor) {
            $id=(int)$actor['id'];
            if ($id===$selfId || !isset($npcById[$id]) || !$this->eligible($npcById[$id]) || $npcById[$id]['name']!==$actor['name']) continue;
            $eligible[$key]=['key'=>$key,'name'=>$actor['name'],'kind'=>'npc'];
        }
        $senderKey = ($source['claimed_sender']['kind'] ?? null)==='player' ? 'player' : 'npc:'.(int)($source['claimed_sender']['id'] ?? 0);
        $senderName = $source['claimed_sender']['name'] ?? null;
        if (!is_string($senderName)) return null;
        $nameKey = static fn(string $name): string => function_exists('mb_strtolower') ? mb_strtolower($name,'UTF-8') : strtolower($name);
        $selfNameKey = $nameKey($selfRow['name']);
        $sender = $senderKey === 'player' ? $player : ($eligible[$senderKey] ?? null);
        if (!is_array($sender) || $sender['name'] !== $senderName || $senderKey === $selfRow['key']) return null;
        $senderNameKey = $nameKey($sender['name']);
        if ($senderNameKey === $selfNameKey) return null;
        $contacts=[$sender];
        $contactNames=[$selfNameKey=>true,$senderNameKey=>true];
        $sorted=array_values($eligible);
        usort($sorted,fn($a,$b)=>(int)substr($a['key'],4)<=>(int)substr($b['key'],4));
        $npcLimit=$player!==null?31:32;
        $npcCount=count(array_filter($contacts,fn($contact)=>$contact['kind']==='npc'));
        foreach($sorted as $contact){
            if($npcCount>=$npcLimit)break;
            if(in_array($contact['key'],array_column($contacts,'key'),true))continue;
            $contactNameKey=$nameKey($contact['name']);
            if(isset($contactNames[$contactNameKey])||($player!==null&&$contactNameKey===$nameKey($player['name'])))continue;
            $contacts[]=$contact;$contactNames[$contactNameKey]=true;$npcCount++;
        }
        if($player!==null&&!in_array('player',array_column($contacts,'key'),true)){
            $playerNameKey=$nameKey($player['name']);
            if(!isset($contactNames[$playerNameKey]))$contacts[]=$player;
        }
        $contactRows=[];
        $state=['self'=>['key'=>$selfRow['key'],'name'=>$selfRow['name'],'personality'=>$selfRow['personality'],'enabled_raw'=>$selfRow['enabled_raw'],'letters_raw'=>$selfRow['letters_raw'],'dead_raw'=>$selfRow['dead_raw']],'contacts'=>[]];
        foreach($contacts as $contact){
            if($contact['kind']==='npc'){
                $row=$npcById[(int)substr($contact['key'],4)];
                $contactRows[$contact['key']]=array_intersect_key($row,array_flip(['id','key','name','enabled_raw','letters_raw','dead_raw','enabled','letters','dead','location']));
                $state['contacts'][]=['key'=>$contact['key'],'name'=>$contact['name'],'enabled_raw'=>$row['enabled_raw'],'letters_raw'=>$row['letters_raw'],'dead_raw'=>$row['dead_raw']];
            }else $state['contacts'][]=['key'=>'player','name'=>$contact['name']];
        }
        $relationships = $this->relationshipProjection($selfRow, $contacts);
        $self['relationships'] = $relationships;
        $state['self']['relationships'] = $relationships;
        return ['self'=>$self,'contacts'=>array_map(fn($c)=>['key'=>$c['key'],'name'=>$c['name']],$contacts),'contact_rows'=>$contactRows,'self_row'=>$selfRow,'context_sha256'=>hash('sha256',correspondenceCanonicalJson($state)),'source_body'=>$source['body']];
    }

    private function relationshipProjection(array $selfRow, array $contacts): array
    {
        $source = $selfRow['relationships'] ?? [];
        if (!is_array($source) || array_is_list($source)) return [];
        $result = [];
        foreach ($contacts as $contact) {
            $entry = $source[$contact['name']] ?? null;
            if (!is_array($entry) || !array_key_exists('aff', $entry)) continue;
            $affinity = $entry['aff'];
            if ((!is_int($affinity) && !is_float($affinity))
                || (is_float($affinity) && !is_finite($affinity))
                || $affinity < -100 || $affinity > 100) continue;
            $result[] = ['key'=>$contact['key'],'affinity'=>$affinity];
        }
        return $result;
    }

    private function validateTarget(array $decision, array $source, array $self, array $contacts): void
    {
        $recipient=$decision['recipient']??null;
        $keys=array_column($contacts,'key');
        $selfKey=$self['key'];
        $senderKey=($source['claimed_sender']['kind']??null)==='player'?'player':'npc:'.(int)($source['claimed_sender']['id']??0);
        if(!is_string($recipient)||!in_array($recipient,$keys,true)||$recipient===$selfKey)throw new InvalidArgumentException('invalid_target');
        if($decision['action']==='reply'&&$recipient!==$senderKey)throw new InvalidArgumentException('invalid_target');
        if(in_array($decision['action'],['forward','mediate'],true)&&$recipient===$senderKey)throw new InvalidArgumentException('invalid_target');
    }

    private function findContact(string $key, array $contacts): ?array
    {
        foreach($contacts as $contact)if(($contact['key']??null)===$key)return ['key'=>$key,'name'=>$contact['name'],'kind'=>$key==='player'?'player':'npc'];
        return null;
    }

    private function messageChars(array $messages): int
    {
        $count=0;
        foreach($messages as $message){
            if(!is_array($message))continue;
            foreach($message as $value)if(is_string($value))$count+=$this->charCount($value);
        }
        return $count;
    }

    private function charCount(string $value): int
    {
        if(preg_match('//u',$value)!==1)return strlen($value);
        $count=preg_match_all('/./us',$value,$matches);
        return $count===false?0:$count;
    }

    private function releaseUnattemptedClaim(array $work): bool
    {
        try {
            return $this->runCompletionTransaction(function () use ($work): bool {
                $clock = $this->clock();
                if ($this->restoreStateChanged($work['restore_generation'] ?? null) !== false
                    || $clock['playthrough_id'] !== $work['playthrough_id'] || $clock['gamets'] < $work['claim_gamets']) return false;
                $claimed = $this->decisionRow($work['playthrough_id'], $work['source_document_id']);
                if ($claimed === null || ($claimed['status'] ?? null) !== 'claimed'
                    || !$this->claimMatchesWork($claimed, $work, $clock['gamets'])) return false;
                // Only this live caller knows the callback was never entered; historical zero counters do not prove that.
                $removed = $this->store->query("DELETE FROM plugins.correspondence_autonomy_decisions WHERE playthrough_id=$1 AND source_document_id=$2 AND claim_id=$3 AND status='claimed' AND child_document_id IS NULL RETURNING claim_id::text", [$work['playthrough_id'],$work['source_document_id'],$work['claim_id']]);
                if (count($removed) !== 1) return false;
                $this->store->appendEvent('correspondence_anchor', correspondenceJson(['kind'=>'autonomy_unattempted','playthrough_id'=>$work['playthrough_id'],'source_document_id'=>$work['source_document_id'],'source_event_id'=>(string)$work['source_event_id'],'claim_id'=>$work['claim_id'],'reason_code'=>'attempt_write_failed']), '', 'correspondence_v1');
                return true;
            }, (string)($work['restore_generation'] ?? ''));
        } catch (Throwable) { return false; }
    }

    private function completeFailure(array $work,string $reason,int $calls,int $inputChars,int $outputChars): array
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                return $this->runCompletionTransaction(function () use ($work,$reason,$calls,$inputChars,$outputChars): array {
                    $clock = $this->clock();
                    $restoreState = $this->restoreStateChanged($work['restore_generation'] ?? null);
                    if ($restoreState === true) return ['status'=>'stale','reason'=>'restore_changed'];
                    if ($restoreState === null) return ['status'=>'failed','reason'=>'storage_error'];
                    if ($clock['playthrough_id'] !== $work['playthrough_id'] || $clock['gamets'] < $work['claim_gamets']) return ['status'=>'stale','reason'=>'clock_changed'];
                    $claimed = $this->decisionRow($work['playthrough_id'],$work['source_document_id']);
                    if ($claimed === null || ($claimed['claim_id'] ?? null) !== $work['claim_id'] || ($claimed['status'] ?? null) !== 'claimed') return ['status'=>'stale','reason'=>'claim_changed'];
                    if (!$this->claimMatchesWork($claimed,$work,$clock['gamets'])) return $this->closeLostClaim($work);
                    return $this->markTerminal($work,'failed',null,null,null,$reason,$clock['gamets'],$calls,$inputChars,$outputChars);
                }, (string)($work['restore_generation'] ?? ''));
            } catch (Throwable) {
                $settled = $this->readCompletedDecision($work);
                if ($settled !== null) return $settled;
                if ($attempt === 0 && $this->claimStillOwned($work)) continue;
                if ($this->restoreStateChanged($work['restore_generation'] ?? null) === true) return ['status'=>'stale','reason'=>'restore_changed'];
                $this->persistAttemptCounters($work,$calls,$inputChars,$outputChars);
                return ['status'=>'failed','reason'=>'storage_error'];
            }
        }
        return ['status'=>'failed','reason'=>'storage_error'];
    }
    private function attemptCounters(array $payload): array
    {
        $limit = 2147483647;
        $calls = $payload['model_calls'] ?? null;
        $inputChars = $payload['input_chars'] ?? null;
        $outputChars = $payload['output_chars'] ?? null;
        return [
            'model_calls'=>is_int($calls) && $calls >= 0 && $calls <= 1 ? $calls : 0,
            'input_chars'=>is_int($inputChars) && $inputChars >= 0 && $inputChars <= $limit ? $inputChars : 0,
            'output_chars'=>is_int($outputChars) && $outputChars >= 0 && $outputChars <= $limit ? $outputChars : 0,
        ];
    }

    private function persistAttemptCounters(array $work,int $calls,int $inputChars,int $outputChars): bool
    {
        if (!$this->restoreTokenMatches($work['restore_generation'] ?? null) || $calls < 0 || $calls > 1 || $inputChars < 0 || $inputChars > 2147483647 || $outputChars < 0 || $outputChars > 2147483647) return false;
        try {
            $rows = $this->store->query("UPDATE plugins.correspondence_autonomy_decisions SET payload=COALESCE(payload,'{}'::jsonb)||jsonb_build_object('model_calls',$5::integer,'input_chars',$6::integer,'output_chars',$7::integer) WHERE playthrough_id=$1 AND source_document_id=$2 AND source_event_id=$3 AND claim_id=$4 AND status='claimed' RETURNING claim_id::text", [
                $work['playthrough_id'],$work['source_document_id'],(string)$work['source_event_id'],$work['claim_id'],$calls,$inputChars,$outputChars,
            ]);
            return count($rows) === 1 && ($rows[0]['claim_id'] ?? null) === $work['claim_id'];
        } catch (Throwable) {
            return false;
        }
    }

    private function markTerminal(array $work,string $status,?string $action,?string $child,?int $due,string $reason,int $gamets,int $calls,int $inputChars,int $outputChars): array
    {
        $payload=$this->completionPayload($work,$status,$reason,$gamets,$calls,$inputChars,$outputChars,$action,$child,$due);
        $this->updateDecision($work,$status,$action,$child,$due,$payload);
        return ['status'=>$status,'reason'=>$reason];
    }

    private function completionPayload(array $work,string $status,string $reason,int $gamets,int $calls,int $inputChars,int $outputChars,?string $action,?string $child,?int $due): array
    {
        $globalCooldown = $gamets + correspondenceAutonomyGamets(1);
        $actorCooldown = $gamets + correspondenceAutonomyGamets(6);
        $completionData = [
            'kind'=>'autonomy_completion','playthrough_id'=>$work['playthrough_id'],'claim_id'=>$work['claim_id'],
            'source_document_id'=>$work['source_document_id'],'source_event_id'=>(string)$work['source_event_id'],
            'claim_anchor_rowid'=>(string)$work['claim_anchor']['rowid'],'completed_status'=>$status,
            'completed_action'=>$action,'child_document_id'=>$child,'due_gamets'=>$due===null?null:(string)$due,
            'completed_gamets'=>(string)$gamets,'global_cooldown_until_gamets'=>(string)$globalCooldown,
            'actor_cooldown_until_gamets'=>(string)$actorCooldown,
        ];
        if (is_string($work['restore_generation'] ?? null) && $work['restore_generation'] !== '') $completionData['restore_generation'] = $work['restore_generation'];
        $completionAnchor = $this->store->appendEvent('correspondence_anchor', correspondenceJson($completionData), '', 'correspondence_v1');
        $payload = [
            'reason_code'=>$reason,
            'claim_gamets'=>(string)$work['claim_gamets'],
            'claim_global_cooldown_until_gamets'=>(string)$work['claim_global_cooldown_until_gamets'],
            'claim_actor_cooldown_until_gamets'=>(string)$work['claim_actor_cooldown_until_gamets'],
            'completed_gamets'=>(string)$gamets,
            'global_cooldown_until_gamets'=>(string)$globalCooldown,
            'actor_cooldown_until_gamets'=>(string)$actorCooldown,
            'self_id'=>(string)$work['recipient_id'],
            'source_event_sha256'=>$work['source_event_sha256'],
            'source_body_sha256'=>$work['source_body_sha256'],
            'context_sha256'=>$work['context_sha256'],
            'claim_anchor'=>$work['claim_anchor'],'completion_anchor'=>$completionAnchor,
            'completed_status'=>$status,'completed_action'=>$action,'completed_child_document_id'=>$child,
            'completed_due_gamets'=>$due===null?null:(string)$due,
            'model_calls'=>$calls,'input_chars'=>$inputChars,'output_chars'=>$outputChars,
        ];
        if (is_string($work['restore_generation'] ?? null) && $work['restore_generation'] !== '') $payload['restore_generation'] = $work['restore_generation'];
        return $payload;
    }

    private function updateDecision(array $work,string $status,?string $action,?string $child,?int $due,array $payload): void
    {
        if(!in_array($status,self::STATUS_VALUES,true)||($action!==null&&!in_array($action,self::ACTION_VALUES,true)))throw new LogicException('invalid_decision_state');
        $rows=$this->store->query("UPDATE plugins.correspondence_autonomy_decisions SET status=$1,action=$2,child_document_id=$3,due_gamets=$4,payload=$5::jsonb WHERE playthrough_id=$6 AND source_document_id=$7 AND claim_id=$8 AND status='claimed' RETURNING source_document_id",[$status,$action,$child,$due===null?null:(string)$due,correspondenceJson($payload),$work['playthrough_id'],$work['source_document_id'],$work['claim_id']]);
        if(count($rows)!==1)throw new DomainException('claim_lost');
    }

    private function decisionRow(string $scope,string $sourceDocumentId): ?array
    {
        $rows=$this->store->query('SELECT playthrough_id,source_document_id::text,source_event_id::text,claim_id::text,request_id::text,root_document_id::text,depth,child_document_id::text,status,action,due_gamets::text,payload::text FROM plugins.correspondence_autonomy_decisions WHERE playthrough_id=$1 AND source_document_id=$2',[$scope,$sourceDocumentId]);
        if($rows===[])return null;
        $row=$rows[0];
        $payload=$this->jsonObject($row['payload']??null);
        return array_merge($row,['payload'=>$payload]);
    }
}
