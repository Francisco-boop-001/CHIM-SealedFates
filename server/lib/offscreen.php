<?php
declare(strict_types=1);

require_once __DIR__ . '/offscreen_policy.php';
require_once __DIR__ . '/autonomy_policy.php';

final class CorrespondenceOffscreenPg
{
    private const NATIVE_TYPES = [
        'inputtext','inputtext_s','ginputtext','ginputtext_s','bored','location',
        'infoloc','infonpc','infonpc_close','infoitems','infoplayer',
    ];
    private const TICK_TYPES = ['bored','location'];
    private const BOUNDARY_TYPES = ['newgame','playerinfo'];
    private const TRUE_VALUES = ['true','1','t','on'];
    private const JOB_STATES = ['claimed','sent','silent','failed','stale'];
    private const OBSERVATION_MAX_AGE_SECONDS = 120;
    private const MAX_THREAD_DEPTH = 2;
    private const CLAIM_MAX_AGE_SECONDS = 180;
    private bool $schemaReady = false;
    private array $pendingSourceIds = [];
    private array $retryableSourceIds = [];

    public function __construct(private CorrespondencePgStore $store) {}

    public function ensureSchema(): void
    {
        if ($this->schemaReady) return;
        $this->store->ensureSchema();
        $autonomy = $this->store->query("SELECT to_regclass('plugins.correspondence_autonomy_settings') AS settings")[0] ?? [];
        if (!array_key_exists('settings', $autonomy)) throw new DomainException('schema_dependency_unavailable');
        if ($autonomy['settings'] === null) {
            $path = dirname(__DIR__) . '/migrations/002_autonomy.sql';
            $migration = @file_get_contents($path);
            if (!is_string($migration) || trim($migration) === '') throw new DomainException('schema_migration_unavailable');
            $this->store->transaction(function () use ($migration) {
                foreach (explode(';', $migration) as $statement) if (trim($statement) !== '') $this->store->query($statement);
            });
        }

        $ready = $this->store->query("SELECT to_regclass('plugins.correspondence_offscreen_observations') IS NOT NULL AS observations,to_regclass('plugins.correspondence_offscreen_jobs') IS NOT NULL AS jobs,to_regclass('plugins.correspondence_offscreen_mail') IS NOT NULL AS mail")[0] ?? [];
        if (($ready['observations'] ?? null) !== 't' || ($ready['jobs'] ?? null) !== 't' || ($ready['mail'] ?? null) !== 't') {
            $path = dirname(__DIR__) . '/migrations/003_offscreen.sql';
            $migration = @file_get_contents($path);
            if (!is_string($migration) || trim($migration) === '') throw new DomainException('schema_migration_unavailable');
            $this->store->transaction(function () use ($migration) {
                foreach (explode(';', $migration) as $statement) if (trim($statement) !== '') $this->store->query($statement);
            });
        }
        $columns = $this->store->query("SELECT table_name,column_name FROM information_schema.columns WHERE table_schema='plugins' AND table_name IN ('correspondence_offscreen_observations','correspondence_offscreen_jobs','correspondence_offscreen_mail')");
        $expected = [
            'correspondence_offscreen_observations' => ['playthrough_id','native_type','native_ts','gamets','request_sha256','interaction_generation','restore_generation','boundary','consumed','last_consumed_gamets','anchor'],
            'correspondence_offscreen_jobs' => ['playthrough_id','job_id','trigger_event_id','actor_id','source_message_id','goal_hash','claim_id','status','action','payload'],
            'correspondence_offscreen_mail' => ['playthrough_id','message_id','sender_id','recipient_id','root_message_id','depth','status','body','created_gamets','due_gamets','arrived_gamets','source_event_id','source_event_sha256','payload'],
        ];
        foreach ($expected as $table => $names) foreach ($names as $name) {
            if (!array_filter($columns, static fn($row) => ($row['table_name'] ?? null) === $table && ($row['column_name'] ?? null) === $name)) {
                throw new DomainException('schema_migration_incomplete');
            }
        }
        $this->schemaReady = true;
    }

    public function observe(array $nativeRequest): void
    {
        $this->ensureSchema();
        if (!array_is_list($nativeRequest) || count($nativeRequest) < 4 || !is_string($nativeRequest[0] ?? null)) {
            throw new InvalidArgumentException('invalid_native_observation');
        }
        $type = $nativeRequest[0];
        $boundary = in_array($type, self::BOUNDARY_TYPES, true);
        if (!$boundary && !in_array($type, self::NATIVE_TYPES, true)) throw new InvalidArgumentException('invalid_native_observation');
        if (count($nativeRequest) > 16) throw new InvalidArgumentException('invalid_native_observation');
        foreach ($nativeRequest as $part) if (!is_string($part) && !is_int($part)) throw new InvalidArgumentException('invalid_native_observation');
        $clockTuple = correspondenceRequestClock($nativeRequest);
        if (!method_exists($this->store, 'bindRequestClock')) throw new DomainException('request_clock_unavailable');
        $this->store->bindRequestClock($nativeRequest);
        $interactionLock = $this->acquireInteractionLock(true);
        if ($interactionLock === null) throw new DomainException('interaction_lock_unavailable');
        $interaction = $this->interactionSnapshotFromLock($interactionLock);
        $restore = $this->restoreGeneration();
        $requestHash = hash('sha256', serialize($nativeRequest));

        try {
            $this->store->transaction(function () use ($type, $boundary, $clockTuple, $interaction, $restore, $requestHash, $interactionLock) {
                $clock = $this->clock();
                if ((string)$clock['ts'] !== $clockTuple['ts'] || (string)$clock['gamets'] !== $clockTuple['gamets']) throw new DomainException('observation_clock_mismatch');
                if (($GLOBALS['chim_interaction_generation'] ?? null) !== $interaction['generation']) throw new DomainException('interaction_changed');
                $scope = $clock['playthrough_id'];
                $data = correspondenceJson([
                    'kind' => 'offscreen_observation',
                    'playthrough_id' => $scope,
                    'native_type' => $type,
                    'native_ts' => $clockTuple['ts'],
                    'gamets' => $clockTuple['gamets'],
                    'request_sha256' => $requestHash,
                    'interaction_generation' => $interaction['generation'],
                    'restore_generation' => $restore,
                    'boundary' => $boundary,
                ]);
                $anchor = $this->store->appendEvent('correspondence_anchor', $data, '', 'correspondence_v1');
                $anchorRef = $this->eventRef($anchor);
                $this->store->query('INSERT INTO plugins.correspondence_offscreen_observations(playthrough_id,native_type,native_ts,gamets,request_sha256,interaction_generation,restore_generation,boundary,consumed,last_consumed_gamets,anchor) VALUES($1,$2,$3,$4,$5,$6,$7,$8,FALSE,0,$9::jsonb) ON CONFLICT(playthrough_id) DO UPDATE SET native_type=EXCLUDED.native_type,native_ts=EXCLUDED.native_ts,gamets=EXCLUDED.gamets,request_sha256=EXCLUDED.request_sha256,interaction_generation=EXCLUDED.interaction_generation,restore_generation=EXCLUDED.restore_generation,boundary=EXCLUDED.boundary,consumed=FALSE,last_consumed_gamets=CASE WHEN plugins.correspondence_offscreen_observations.restore_generation IS DISTINCT FROM EXCLUDED.restore_generation THEN 0 ELSE plugins.correspondence_offscreen_observations.last_consumed_gamets END,anchor=EXCLUDED.anchor,updated_at=clock_timestamp()', [
                    $scope, $type, $clockTuple['ts'], $clockTuple['gamets'], $requestHash,
                    $interaction['generation'], $restore, $boundary ? 'true' : 'false', correspondenceJson($anchorRef),
                ]);
            });
        } finally {
            $this->releaseInteractionLock($interactionLock);
        }
    }

    public function tick(callable $requestModel): array
    {
        $this->ensureSchema();
        $this->pendingSourceIds = [];
        $this->retryableSourceIds = [];
        if (!$this->policyReady()) return $this->result('idle', 'policy_unavailable');
        $interaction = $this->interactionSnapshot(true);
        if (!$interaction['enabled']) return $this->result('paused', 'interaction_disabled');

        try {
            $observation = $this->bindLatestObservation();
        } catch (DomainException) {
            return $this->result('stale', 'clock_or_anchor_stale');
        } catch (Throwable) {
            return $this->result('failed', 'storage_error');
        }
        if ($observation === null) return $this->result('idle', 'no_observation');
        if (!$this->observationFresh($observation)) return $this->result('stale', 'observation_expired');
        if ($observation['boundary'] || !in_array($observation['native_type'], self::TICK_TYPES, true)) return $this->result('idle', 'no_native_opportunity');
        if (!$this->interactionMatches($observation)) return $this->result('paused', 'interaction_changed');
        if ($observation['restore_generation'] === null || $this->restoreGeneration() !== $observation['restore_generation']) return $this->result('stale', 'restore_changed');

        $claimLock = $this->acquireInteractionLock();
        if ($claimLock === null) return $this->result('paused', 'interaction_lock_unavailable');
        try {
            if (!$this->interactionLockMatches($claimLock, $observation['interaction_generation'])) return $this->result('paused', 'interaction_changed');
            $work = $this->store->transaction(function () use ($observation, $claimLock) {
                if (!$this->observationStillCurrent($observation) || !$this->currentEventClockMatches($observation)) return ['result_kind'=>'idle','reason'=>'observation_changed'];
                if (!$this->observationFresh($observation)) return ['result_kind'=>'stale','reason'=>'observation_expired'];
                if (!$this->interactionLockMatches($claimLock, $observation['interaction_generation'])) return ['result_kind'=>'paused','reason'=>'interaction_changed'];
                if ($this->restoreGeneration() !== $observation['restore_generation']) return ['result_kind'=>'stale','reason'=>'restore_changed'];
                $scope = $observation['playthrough_id'];
                if (!$this->autonomyEnabled($scope)) return ['result_kind'=>'paused','reason'=>'disabled'];
                if ($this->observationConsumed($scope, $observation['anchor']['rowid'])) return ['result_kind'=>'idle','reason'=>'already_claimed'];
                if ($observation['gamets'] <= $observation['last_consumed_gamets']) return ['result_kind'=>'idle','reason'=>'gametime_paused'];
                if ($this->expireAbandonedClaims($scope)) {
                    $this->consumeObservation($scope, $observation['anchor']['rowid'], $observation['gamets']);
                    return ['result_kind'=>'processed','reason'=>'claim_timeout'];
                }
                $foreignClaimResult = $this->retireForeignGenerationReactionSource($scope);
                if ($foreignClaimResult !== null) {
                    $this->consumeObservation($scope, $observation['anchor']['rowid'], $observation['gamets']);
                    return ['result_kind'=>'processed','reason'=>$foreignClaimResult];
                }
                if ($this->recoverTerminalReactionSources($scope)) {
                    $this->consumeObservation($scope, $observation['anchor']['rowid'], $observation['gamets']);
                    return ['result_kind'=>'processed','reason'=>'reaction_abandoned'];
                }
                $idle = function (string $reason) use ($scope, $observation): array {
                    $this->consumeObservation($scope, $observation['anchor']['rowid'], $observation['gamets']);
                    return ['result_kind'=>'idle','reason'=>$reason];
                };

                if ($this->quarantineInvalidLiveMail($scope)) {
                    $this->consumeObservation($scope, $observation['anchor']['rowid'], $observation['gamets']);
                    return ['result_kind'=>'processed','reason'=>'mail_quarantined'];
                }

                $due = $this->dueMail($scope, $observation['gamets']);
                if ($due !== null) {
                    $actors = $this->npcMap($this->npcRows());
                    $sender = $actors[(int)$due['sender_id']] ?? null;
                    $recipient = $actors[(int)$due['recipient_id']] ?? null;
                    if (!$this->eligible($sender) || !$this->eligible($recipient)) {
                        $this->assertMailCreation($due);
                        $ref = $this->appendAnchor('mail_stale', ['message_id'=>$due['message_id'],'reason_code'=>'actor_ineligible']);
                        $changed = $this->store->query("UPDATE plugins.correspondence_offscreen_mail SET status='stale',payload=payload||jsonb_build_object('stale_anchor',$2::jsonb,'reason_code','actor_ineligible') WHERE playthrough_id=$1 AND message_id=$3 AND status='in_transit' RETURNING message_id::text", [$scope, correspondenceJson($ref), $due['message_id']]);
                        if (count($changed) !== 1) throw new DomainException('mail_transition_conflict');
                    } else {
                        $this->assertMailCreation($due);
                        $ref = $this->appendAnchor('mail_arrived', ['message_id'=>$due['message_id'],'sender_id'=>(string)$sender['id'],'recipient_id'=>(string)$recipient['id'],'gamets'=>(string)$observation['gamets']]);
                        $changed = $this->store->query("UPDATE plugins.correspondence_offscreen_mail SET status='arrived',arrived_gamets=$4,payload=payload||jsonb_build_object('arrival_anchor',$2::jsonb) WHERE playthrough_id=$1 AND message_id=$3 AND status='in_transit' RETURNING message_id::text", [$scope, correspondenceJson($ref), $due['message_id'], (string)$observation['gamets']]);
                        if (count($changed) !== 1) throw new DomainException('mail_transition_conflict');
                    }
                    $this->consumeObservation($scope, $observation['anchor']['rowid'], $observation['gamets']);
                    return ['result_kind'=>'processed','reason'=>'arrival'];
                }

                $existing = $this->store->query('SELECT job_id::text FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id=$1 AND trigger_event_id=$2 LIMIT 1', [$scope, $observation['anchor']['rowid']]);
                if ($existing !== []) return $idle('already_claimed');
                if ($this->globalCooldown($scope, $observation['gamets']) > $observation['gamets']) return $idle('cooldown');

                $actors = $this->npcMap($this->npcRows());
                if ($this->retireIneligibleArrivals($scope, $actors)) {
                    $this->consumeObservation($scope, $observation['anchor']['rowid'], $observation['gamets']);
                    return ['result_kind'=>'processed','reason'=>'actor_ineligible'];
                }
                $reaction = $this->reactionCandidate($scope, $observation['gamets'], $actors);
                if ($reaction !== null) {
                    $mail = $reaction['mail'];
                    $self = $actors[(int)$mail['recipient_id']];
                    if ($this->actorCooldown($scope, (int)$self['id'], $observation['gamets']) > $observation['gamets']) return $idle('cooldown');
                    $context = ['self'=>$reaction['self'],'contacts'=>$reaction['contacts'],'source'=>$reaction['source'],'messages'=>$reaction['messages']];
                    $return = $this->claim($scope, $observation, $self, 'react', $mail, null, $context);
                    return $return ?? $idle('already_claimed');
                }

                $candidate = $this->initiationCandidate($scope, $observation['gamets'], $actors);
                if ($candidate === null) return $idle('no_eligible_source');
                $self = $candidate['self'];
                if ($this->actorCooldown($scope, (int)$self['id'], $observation['gamets']) > $observation['gamets']) return $idle('cooldown');
                $contacts = $this->contacts($self, $actors);
                if ($contacts === []) return $idle('no_eligible_contact');
                $context = ['self'=>$this->selfProjection($self, $contacts),'contacts'=>$contacts,'goal'=>$candidate['goal']];
                return $this->claim($scope, $observation, $self, 'initiate', null, $candidate['goal'], $context) ?? $idle('already_claimed');
            });
        } catch (DomainException) {
            return $this->result('stale', 'clock_or_anchor_stale');
        } catch (Throwable) {
            return $this->result('failed', 'storage_error');
        } finally {
            $this->releaseInteractionLock($claimLock);
        }

        if (in_array($work['result_kind'] ?? null, ['idle','paused','stale','processed'], true)) return $this->result($work['result_kind'], $work['reason'] ?? 'unknown');
        if (($work['result_kind'] ?? null) !== 'claimed') return $this->result('failed', 'storage_error');

        $modelReturned = false;
        $modelCalled = false;
        $outputChars = 0;
        try {
            $messages = $work['kind_of_job'] === 'react'
                ? ($work['messages'] ?? correspondenceAutonomyMessages($work['source'], $work['self'], $work['contacts']))
                : correspondenceOffscreenInitiateMessages($work['self'], $work['goal'], $work['contacts']);
            $modelCalled = true;
            $raw = $requestModel($messages);
            if (!is_string($raw)) throw new UnexpectedValueException('invalid_model_output');
            $modelReturned = true;
            $outputChars = $this->charCount($raw);
            if ($work['kind_of_job'] === 'react') {
                $decision = correspondenceAutonomyDecision($raw, $work['source'], $work['contacts']);
                $action = $decision['action'];
                $body = $action === 'none' ? '' : correspondenceAutonomyBody($decision, $work['source']);
                $target = $action === 'none' ? null : $decision['recipient'];
            } else {
                $decision = correspondenceOffscreenInitiateDecision($raw, $work['contacts']);
                $action = $decision['action'];
                $body = $decision['body'];
                $target = $decision['recipient'];
            }
        } catch (Throwable) {
            $result = $this->finishWork($work, null, '', null, $modelReturned, $modelReturned ? 'invalid_decision' : 'model_error', $outputChars);
            return $this->result($result['status'], $result['reason'], $modelCalled ? 1 : 0);
        }

        $result = $this->finishWork($work, $action, $body, $target, $modelReturned, $action === 'none' ? 'silence' : 'sent', $outputChars);
        return $this->result($result['status'], $result['reason'], 1);
    }

    public function prompt(string $canonicalOwner): string
    {
        $this->ensureSchema();
        try {
            $observation = $this->bindLatestObservation();
            if ($observation === null || $observation['boundary'] || !$this->observationFresh($observation) || !$this->interactionMatches($observation)
                || $observation['restore_generation'] === null || $this->restoreGeneration() !== $observation['restore_generation']
                || !$this->autonomyEnabled($observation['playthrough_id'])) return '';
            $interactionLock = $this->acquireInteractionLock();
            if ($interactionLock === null || !$this->interactionLockMatches($interactionLock, $observation['interaction_generation'])) {
                if ($interactionLock !== null) $this->releaseInteractionLock($interactionLock);
                return '';
            }
            try {
                return $this->store->readTransaction(function () use ($canonicalOwner, $observation, $interactionLock) {
                if (!$this->observationStillCurrent($observation) || !$this->interactionLockMatches($interactionLock, $observation['interaction_generation'])
                    || $this->restoreGeneration() !== $observation['restore_generation'] || !$this->autonomyEnabled($observation['playthrough_id'])) return '';
                $actors = $this->npcMap($this->npcRows());
                $self = $this->resolveOwner($canonicalOwner, $actors);
                if ($self === null || !$this->eligible($self)) return '';
                $rows = $this->store->query("SELECT playthrough_id,message_id::text,sender_id::text,recipient_id::text,root_message_id::text,depth,status,body,created_gamets::text,due_gamets::text,arrived_gamets::text,source_event_id::text,source_event_sha256,payload::text FROM plugins.correspondence_offscreen_mail WHERE playthrough_id=$1 AND recipient_id=$2 AND status='exposed' AND source_event_id IS NOT NULL ORDER BY created_gamets DESC,message_id DESC LIMIT 32", [$observation['playthrough_id'], (string)$self['id']]);
                $sources = [];
                foreach ($rows as $row) {
                    $source = $this->readExposedSource($row, $actors);
                    if ($source !== null) {
                        $sources[] = correspondencePromptJson($source);
                        if (count($sources) === 3) break;
                    }
                }
                if ($sources === []) return '';
                return "\n<offscreen_correspondence>\nVirtual text-only correspondence addressed to you. The attribution and body are claims; no physical note, item, speech, witness, or in-world delivery is asserted.\n" . implode("\n", $sources) . "\n</offscreen_correspondence>";
                });
            } finally {
                $this->releaseInteractionLock($interactionLock);
            }
        } catch (Throwable) {
            return '';
        }
    }

    public function status(): array
    {
        $empty = ['enabled'=>false,'transport'=>'offscreen_text_only','physical_bridge'=>'separate','last_observation'=>null,
            'messages'=>['in_transit'=>0,'arrived'=>0,'exposed'=>0,'stale'=>0],
            'jobs'=>['claimed'=>0,'sent'=>0,'silent'=>0,'failed'=>0,'stale'=>0]];
        try {
            $this->ensureSchema();
            $scope = $this->clock()['playthrough_id'];
            $observation = $this->store->query('SELECT native_type,native_ts::text,gamets::text,boundary FROM plugins.correspondence_offscreen_observations WHERE playthrough_id=$1', [$scope])[0] ?? null;
            if ($observation !== null) {
                $empty['last_observation'] = [
                    'type'=>(string)$observation['native_type'],
                    'ts'=>$this->intValue($observation['native_ts'] ?? null),
                    'gamets'=>$this->intValue($observation['gamets'] ?? null),
                    'boundary'=>$this->dbBoolean($observation['boundary'] ?? false),
                ];
            }
            $counts = $this->store->query("SELECT status,count(*)::int AS amount FROM plugins.correspondence_offscreen_mail WHERE playthrough_id=$1 GROUP BY status", [$scope]);
            foreach ($counts as $row) if (isset($empty['messages'][$row['status'] ?? ''])) $empty['messages'][$row['status']] = (int)$row['amount'];
            $jobs = $this->store->query("SELECT status,count(*)::int AS amount FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id=$1 GROUP BY status", [$scope]);
            foreach ($jobs as $row) if (isset($empty['jobs'][$row['status'] ?? ''])) $empty['jobs'][$row['status']] = (int)$row['amount'];
            $empty['enabled'] = $this->autonomyEnabled($scope);
        } catch (Throwable) {}
        return $empty;
    }

    public function pendingSources(): array
    {
        return array_values(array_unique($this->pendingSourceIds));
    }

    private function claim(string $scope, array $observation, array $self, string $kind, ?array $mail, ?array $goal, array $context): ?array
    {
        $restoreGeneration = $this->restoreGeneration();
        if ($restoreGeneration === null || $this->store->query("SELECT 1 FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id=$1 AND status='claimed' AND payload->>'restore_generation'=$2 LIMIT 1", [$scope,$restoreGeneration]) !== []) return null;
        $sourceId = is_array($mail) ? $mail['message_id'] : null;
        $previous = null;
        $attempt = 0;
        $jobId = correspondenceUuid();
        if ($kind === 'react' && is_string($sourceId)) {
            $existing = $this->store->query("SELECT playthrough_id,job_id::text,trigger_event_id::text,actor_id::text,source_message_id::text,goal_hash,claim_id::text,status,action,payload::text FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id=$1 AND source_message_id=$2 FOR UPDATE", [$scope,$sourceId]);
            if ($existing !== []) {
                $previous = $existing[0];
                $previousPayload = $this->jsonObject($previous['payload'] ?? null);
                if (!$this->retryableTerminalReaction($previous, $previousPayload, $restoreGeneration)) return null;
                $attempt = 1;
                $jobId = (string)$previous['job_id'];
            }
        }
        $claimId = correspondenceUuid();
        $goalHash = is_array($goal) ? $goal['id'] : null;
        $contextHash = hash('sha256', correspondenceCanonicalJson([
            'kind'=>$kind,'self'=>$context['self'],'contacts'=>$context['contacts'],
            'source'=>is_array($context['source'] ?? null) ? $context['source'] : null,
            'goal'=>is_array($context['goal'] ?? null) ? $context['goal'] : null,
        ]));
        $payload = [
            'kind'=>$kind,'attempt'=>$attempt,'claim_gamets'=>(string)$observation['gamets'],'context_sha256'=>$contextHash,
            'observation_anchor'=>$observation['anchor'],'interaction_generation'=>$observation['interaction_generation'],
            'restore_generation'=>$observation['restore_generation'],'source_message_id'=>$sourceId,'goal_hash'=>$goalHash,
        ];
        $claimData = ['kind'=>'offscreen_claim','job_id'=>$jobId,'claim_id'=>$claimId,'trigger_event_id'=>$observation['anchor']['rowid'],
            'actor_id'=>(string)$self['id'],'source_message_id'=>$sourceId,'goal_hash'=>$goalHash,'context_sha256'=>$contextHash,
            'interaction_generation'=>$observation['interaction_generation'],'restore_generation'=>$observation['restore_generation'],'attempt'=>$attempt];
        $claimAnchor = $this->appendAnchor('job_claim', $claimData);
        $payload['claim_anchor'] = $claimAnchor;
        if ($previous !== null) {
            $rows = $this->store->query("UPDATE plugins.correspondence_offscreen_jobs SET trigger_event_id=$1,actor_id=$2,goal_hash=$3,claim_id=$4,status='claimed',action=NULL,payload=$5::jsonb,created_at=clock_timestamp() WHERE playthrough_id=$6 AND job_id=$7 AND source_message_id=$8 AND claim_id=$9 AND status=$10 AND action IS NULL RETURNING job_id::text", [
                $observation['anchor']['rowid'],(string)$self['id'],$goalHash,$claimId,correspondenceJson($payload),
                $scope,$jobId,$sourceId,$previous['claim_id'],$previous['status'],
            ]);
        } else {
            $rows = $this->store->query("INSERT INTO plugins.correspondence_offscreen_jobs(playthrough_id,job_id,trigger_event_id,actor_id,source_message_id,goal_hash,claim_id,status,payload) VALUES($1,$2,$3,$4,$5,$6,$7,'claimed',$8::jsonb) ON CONFLICT DO NOTHING RETURNING job_id::text", [
                $scope,$jobId,$observation['anchor']['rowid'],(string)$self['id'],$sourceId,$goalHash,$claimId,correspondenceJson($payload),
            ]);
        }
        if ($rows === []) return null;
        $this->consumeObservation($scope, $observation['anchor']['rowid'], $observation['gamets']);
        return $payload + $context + [
            'result_kind'=>'claimed','kind_of_job'=>$kind,'playthrough_id'=>$scope,'job_id'=>$jobId,'claim_id'=>$claimId,
            'trigger_event_id'=>$observation['anchor']['rowid'],'actor_id'=>(int)$self['id'],
            'interaction_generation'=>$observation['interaction_generation'],'restore_generation'=>$observation['restore_generation'],
            'source_message_id'=>$sourceId,'goal_hash'=>$goalHash,'attempt'=>$attempt,
            'source_message'=>$mail,'goal'=>$goal,'observation'=>$observation,
        ];
    }

    private function finishWork(array $work, ?string $action, string $body, ?string $target, bool $modelReturned, string $reason, int $outputChars): array
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $pendingBefore = $this->pendingSourceIds;
            $result = $this->finishWorkAttempt($work, $action, $body, $target, $modelReturned, $reason, $outputChars);
            if (($result['reason'] ?? null) === 'interaction_lock_unavailable') {
                $this->pendingSourceIds = $pendingBefore;
                if ($attempt === 0) continue;
                return $this->closeAfterInteractionLockUnavailable($work);
            }
            if (($result['reason'] ?? null) !== 'clock_or_anchor_stale') return $result;

            $settled = $this->readTerminalCompletion($work);
            if ($settled !== null) return $settled;
            $this->pendingSourceIds = $pendingBefore;
            if ($attempt === 0 && $this->claimStillOwned($work)) continue;
            return $this->restoreStateChanged($work['restore_generation'] ?? null) === true
                ? ['status'=>'stale','reason'=>'restore_changed']
                : ['status'=>'failed','reason'=>'storage_error'];
        }
        return ['status'=>'failed','reason'=>'storage_error'];
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

    private function restoreStateChanged(mixed $expectedRestoreToken): ?bool
    {
        if (!is_string($expectedRestoreToken) || $expectedRestoreToken === '') return true;
        try {
            $current = $this->restoreGeneration();
            $requestToken = method_exists($this->store, 'requestRestoreToken')
                ? $this->store->requestRestoreToken()
                : $current;
            if (!is_string($current) || !is_string($requestToken)) return true;
            return !hash_equals($expectedRestoreToken, $current) || !hash_equals($expectedRestoreToken, $requestToken);
        } catch (Throwable) {
            return null;
        }
    }

    private function restoreTokenMatches(mixed $expectedRestoreToken): bool
    {
        return $this->restoreStateChanged($expectedRestoreToken) === false;
    }
    private function claimStillOwned(array $work): bool
    {
        if (!$this->restoreTokenMatches($work['restore_generation'] ?? null)) return false;
        try {
            $read = function () use ($work): bool {
                $row = $this->store->query('SELECT status,claim_id::text,payload::text FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id=$1 AND job_id=$2', [$work['playthrough_id'],$work['job_id']])[0] ?? null;
                if ($row === null || ($row['status'] ?? null) !== 'claimed' || ($row['claim_id'] ?? null) !== $work['claim_id']) return false;
                return $this->validClaimAnchor($work, $this->jsonObject($row['payload'] ?? null));
            };
            return method_exists($this->store, 'readTransaction') ? $this->store->readTransaction($read) : $read();
        } catch (Throwable) {
            return false;
        }
    }

    private function readTerminalCompletion(array $work): ?array
    {
        if (!$this->restoreTokenMatches($work['restore_generation'] ?? null)) return null;
        try {
            $read = function () use ($work): ?array {
                $row = $this->store->query('SELECT playthrough_id,job_id::text,trigger_event_id::text,actor_id::text,source_message_id::text,goal_hash,claim_id::text,status,action,payload::text FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id=$1 AND job_id=$2', [$work['playthrough_id'],$work['job_id']])[0] ?? null;
                if ($row === null || ($row['claim_id'] ?? null) !== $work['claim_id']) return null;
                $payload = $this->jsonObject($row['payload'] ?? null);
                if (!$this->validClaimAnchor($work, $payload)) return null;
                if (in_array($row['status'] ?? null, ['sent','silent'], true)) {
                    if ($this->validatedCompletionGamets($row, $payload) === null) return null;
                    return ['status'=>'processed','reason'=>(string)($payload['reason_code'] ?? 'completed')];
                }
                if (!in_array($row['status'] ?? null, ['failed','stale'], true) || ($row['action'] ?? null) !== null
                    || ($payload['exposed_event_id'] ?? null) !== null || ($payload['child_message_id'] ?? null) !== null
                    || !$this->validTerminalCompletionAnchor($row, $payload)) return null;
                return ['status'=>$row['status'],'reason'=>(string)($payload['reason_code'] ?? 'completed')];
            };
            return method_exists($this->store, 'readTransaction') ? $this->store->readTransaction($read) : $read();
        } catch (Throwable) {
            return null;
        }
    }

    private function closeAfterInteractionLockUnavailable(array $work): array
    {
        try {
            return $this->runCompletionTransaction(function () use ($work): array {
                $scope = $this->clock();
                if ($scope['playthrough_id'] !== $work['playthrough_id']) return ['status'=>'stale','reason'=>'scope_changed'];
                if (!$this->restoreTokenMatches($work['restore_generation'] ?? null)) return ['status'=>'stale','reason'=>'restore_changed'];
                if ($scope['gamets'] < (int)$work['claim_gamets']) return ['status'=>'stale','reason'=>'clock_changed'];
                $row = $this->store->query('SELECT status,claim_id::text,payload::text FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id=$1 AND job_id=$2', [$work['playthrough_id'],$work['job_id']])[0] ?? null;
                if ($row === null || ($row['status'] ?? null) !== 'claimed' || ($row['claim_id'] ?? null) !== $work['claim_id']) return ['status'=>'stale','reason'=>'claim_changed'];
                $payload = $this->jsonObject($row['payload'] ?? null);
                if (!$this->validClaimAnchor($work, $payload)) return ['status'=>'stale','reason'=>'claim_anchor_invalid'];
                if ($work['kind_of_job'] === 'react') {
                    $mail = $this->mailById($work['playthrough_id'], (string)$work['source_message_id']);
                    $source = $mail === null ? null : $this->sourceForMail($mail, $this->npcMap($this->npcRows()));
                    if ($source === null || !hash_equals(correspondenceCanonicalJson($source), correspondenceCanonicalJson($work['source']))) return ['status'=>'stale','reason'=>'source_changed'];
                } else {
                    $actors = $this->npcMap($this->npcRows());
                    $self = $actors[(int)$work['actor_id']] ?? null;
                    $goalText = $self['goal_text'] ?? null;
                    if (!is_array($self) || !$this->eligible($self) || !is_string($goalText) || hash('sha256', trim($goalText)) !== ($work['goal']['id'] ?? null)) return ['status'=>'stale','reason'=>'actor_changed'];
                }
                return $this->terminalJob($work, 'stale', null, 'gate_changed', null, true, null, true);
            }, (string)($work['restore_generation'] ?? ''));
        } catch (Throwable) {
            $settled = $this->readTerminalCompletion($work);
            if ($settled !== null) return $settled;
            return $this->restoreStateChanged($work['restore_generation'] ?? null) === true
                ? ['status'=>'stale','reason'=>'restore_changed']
                : ['status'=>'failed','reason'=>'storage_error'];
        }
    }

    private function finishWorkAttempt(array $work, ?string $action, string $body, ?string $target, bool $modelReturned, string $reason, int $outputChars): array
    {
        try {
            $latest = $this->bindLatestObservation();
            if ($latest === null || $latest['playthrough_id'] !== $work['playthrough_id']) return ['status'=>'stale','reason'=>'scope_changed'];
            $interactionLock = $this->acquireInteractionLock();
            if ($interactionLock === null) return ['status'=>'stale','reason'=>'interaction_lock_unavailable'];
            try {
                return $this->runCompletionTransaction(function () use ($work, $latest, $action, $body, $target, $modelReturned, $reason, $outputChars, $interactionLock) {
                $scope = $this->clock();
                if ($scope['playthrough_id'] !== $work['playthrough_id']) return ['status'=>'stale','reason'=>'scope_changed'];
                $job = $this->store->query('SELECT status,claim_id::text,payload::text FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id=$1 AND job_id=$2', [$work['playthrough_id'],$work['job_id']])[0] ?? null;
                if ($job === null || ($job['status'] ?? null) !== 'claimed' || ($job['claim_id'] ?? null) !== $work['claim_id']) return ['status'=>'stale','reason'=>'claim_changed'];
                $jobPayload = $this->jsonObject($job['payload'] ?? null);
                if (!$this->validClaimAnchor($work, $jobPayload)) return ['status'=>'stale','reason'=>'claim_anchor_invalid'];
                if ($this->restoreGeneration() !== $work['restore_generation'] || $latest['restore_generation'] !== $work['restore_generation']) return ['status'=>'stale','reason'=>'restore_changed'];
                if ($scope['gamets'] < (int)$work['claim_gamets'] || (int)($latest['gamets'] ?? 0) < (int)$work['claim_gamets']
                    || ($latest['interaction_generation'] ?? null) !== $work['interaction_generation']
                    || !$this->observationStillCurrent($latest, true, false)) {
                    return $this->terminalJob($work, 'stale', null, 'observation_changed', null, true, null, true);
                }
                if ($latest['boundary'] || !$this->observationFresh($latest) || !$this->interactionLockMatches($interactionLock, $work['interaction_generation'])
                    || $latest['interaction_generation'] !== $work['interaction_generation']
                    || !$this->autonomyEnabled($scope['playthrough_id'])) return $this->terminalJob($work, 'stale', null, 'gate_changed', null, true, null, true);

                $selectedActors = $this->npcMap($this->npcRows());
                $selfId = (int)$work['actor_id'];
                $self = $selectedActors[$selfId] ?? null;
                if (!$this->eligible($self)) return $this->terminalJob($work, 'stale', null, 'actor_changed', null, false);
                $sourceMail = null;
                $sourceActorIds = [];
                if ($work['kind_of_job'] === 'react') {
                    $sourceMail = $this->mailById($work['playthrough_id'], (string)$work['source_message_id']);
                    if ($sourceMail === null) return $this->terminalJob($work, 'stale', null, 'source_changed', null, false);
                    $sourceActorIds = [(int)$sourceMail['sender_id'], (int)$sourceMail['recipient_id']];
                    foreach ($sourceActorIds as $actorId) {
                        if (!isset($selectedActors[$actorId])) return $this->terminalJob($work, 'stale', null, 'source_changed', null, false);
                    }
                }
                $preferredSenderId = $work['kind_of_job'] === 'react' ? (int)$sourceMail['sender_id'] : null;
                $initialContacts = $this->contacts($self, $selectedActors, $preferredSenderId);
                $targetActorId = null;
                if ($action === 'letter' || in_array($action, ['reply','forward','mediate'], true)) {
                    $initialContact = $this->findContact((string)$target, $initialContacts);
                    if ($initialContact !== null && isset($selectedActors[$initialContact['id']])) $targetActorId = (int)$initialContact['id'];
                }
                $lockActorIds = array_values(array_unique(array_merge([$selfId], $sourceActorIds, $targetActorId === null ? [] : [$targetActorId])));
                foreach ($lockActorIds as $actorId) {
                    if (!isset($selectedActors[$actorId])) {
                        $identityReason = $actorId === $selfId ? 'actor_changed' : (in_array($actorId, $sourceActorIds, true) ? 'source_changed' : 'target_changed');
                        return $this->terminalJob($work, 'stale', null, $identityReason, null, false);
                    }
                }
                $locked = $this->store->lockActorRows($lockActorIds);
                $lockedRows = $locked['actors'] ?? [];
                $freshActors = $this->npcMap(array_values($lockedRows));
                foreach ($lockActorIds as $actorId) {
                    if (isset($lockedRows[$actorId]) && isset($freshActors[$actorId])) continue;
                    $identityReason = $actorId === $selfId ? 'actor_changed' : (in_array($actorId, $sourceActorIds, true) ? 'source_changed' : 'target_changed');
                    return $this->terminalJob($work, 'stale', null, $identityReason, null, false);
                }
                $actors = array_replace($selectedActors, $freshActors);
                $self = $actors[$selfId] ?? null;
                if (!$this->eligible($self)) return $this->terminalJob($work, 'stale', null, 'actor_changed', null, false);
                if ($this->globalCooldown($scope['playthrough_id'], $scope['gamets'], (string)$work['claim_id']) > $scope['gamets']
                    || $this->actorCooldown($scope['playthrough_id'], (int)$self['id'], $scope['gamets'], (string)$work['claim_id']) > $scope['gamets']) return $this->terminalJob($work, 'stale', null, 'cooldown_changed', null, true, null, true);
                $preferredSenderId = $work['kind_of_job'] === 'react' ? ($sourceActorIds[0] ?? null) : null;
                $contacts = $this->contacts($self, $actors, $preferredSenderId);
                $nowContext = ['self'=>$this->selfProjection($self, $contacts),'contacts'=>$contacts];
                if ($work['kind_of_job'] === 'initiate') {
                    $goalText = $self['goal_text'] ?? '';
                    if (!is_string($goalText) || hash('sha256', trim($goalText)) !== ($work['goal']['id'] ?? null)) return $this->terminalJob($work, 'stale', null, 'goal_changed', null, false);
                    $nowContext['goal'] = $work['goal'];
                } else {
                    $mail = $this->mailById($work['playthrough_id'], (string)$work['source_message_id']);
                    if ($mail === null || [(int)$mail['sender_id'], (int)$mail['recipient_id']] !== $sourceActorIds) return $this->terminalJob($work, 'stale', null, 'source_changed', null, false);
                    $source = $mail === null ? null : $this->sourceForMail($mail, $actors);
                    if ($source === null || hash('sha256', correspondenceCanonicalJson($source)) !== hash('sha256', correspondenceCanonicalJson($work['source']))) return $this->terminalJob($work, 'stale', null, 'source_changed', null, false);
                    $work['source_message'] = $mail;
                    $nowContext['source'] = $source;
                }
                $contextHash = hash('sha256', correspondenceCanonicalJson([
                    'kind'=>$work['kind_of_job'],'self'=>$nowContext['self'],'contacts'=>$nowContext['contacts'],
                    'source'=>$nowContext['source'] ?? null,'goal'=>$nowContext['goal'] ?? null,
                ]));
                if (!hash_equals((string)$work['context_sha256'], $contextHash)) return $this->terminalJob($work, 'stale', null, 'context_changed', null, true, null, true);

                if (!$modelReturned || $action === null) {
                    $result = $this->terminalJob($work, 'failed', null, $reason, null, true);
                    if ($work['kind_of_job'] === 'react') {
                        $this->retireUnexposedSource((string)$work['playthrough_id'], (string)$work['source_message_id'], 'reaction_abandoned', (string)$work['restore_generation'], $work['source_message']);
                    }
                    return $result;
                }
                if ($action === 'none') {
                    $exposure = $work['kind_of_job'] === 'react' ? $this->exposeMail($work['source_message'], $work['source']) : null;
                    return $this->terminalJob($work, 'silent', 'none', 'silence', $exposure, true);
                }
                if ($action !== 'letter' && !in_array($action, ['reply','forward','mediate'], true)) return $this->terminalJob($work, 'failed', null, 'invalid_decision', null, false);

                $contact = $this->findContact((string)$target, $contacts);
                if ($contact === null || !isset($actors[$contact['id']]) || !$this->eligible($actors[$contact['id']])) return $this->terminalJob($work, 'stale', null, 'target_changed', null, false);
                $parent = $work['kind_of_job'] === 'react' ? ($work['source_message'] ?? null) : null;
                $depth = is_array($parent) ? (int)$parent['depth'] + 1 : 0;
                $exposure = $work['kind_of_job'] === 'react' ? $this->exposeMail($work['source_message'], $work['source']) : null;
                if ($depth > self::MAX_THREAD_DEPTH) return $this->terminalJob($work, 'silent', 'none', 'chain_limit', $exposure, true);
                $message = $this->createMail($scope['playthrough_id'], $self, $actors[$contact['id']], $body, $scope['gamets'], $parent, $action);
                $this->terminalJob($work, 'sent', $action, 'sent', $exposure, true, $message['message_id']);
                return ['status'=>'processed','reason'=>$work['kind_of_job'] === 'react' ? 'replied' : 'initiated'];
                }, (string)$work['restore_generation']);
            } finally {
                $this->releaseInteractionLock($interactionLock);
            }
        } catch (Throwable) {
            return ['status'=>'stale','reason'=>'clock_or_anchor_stale'];
        }
    }

    private function validClaimAnchor(array $work, array $payload): bool
    {
        $reference = $payload['claim_anchor'] ?? null;
        if (!is_array($reference) || !is_array($work['claim_anchor'] ?? null) || !$this->validEventRef($reference, 'info', 'correspondence_v1')
            || !hash_equals((string)($payload['context_sha256'] ?? ''), (string)$work['context_sha256'])
            || !is_array($payload['observation_anchor'] ?? null) || !is_array($work['observation'] ?? null)
            || !hash_equals(correspondenceCanonicalJson($reference), correspondenceCanonicalJson($work['claim_anchor']))
            || !hash_equals(correspondenceCanonicalJson($payload['observation_anchor']), correspondenceCanonicalJson($work['observation']['anchor']))
            || !$this->validObservationAnchor($payload['observation_anchor'], $work['observation'])) return false;
        $event = $this->eventById((string)$reference['rowid']);
        $data = $event === null ? [] : $this->jsonObject($event['data'] ?? null);
        return $event !== null && ($data['kind'] ?? null) === 'offscreen_transition' && ($data['transition'] ?? null) === 'job_claim'
            && (string)($data['job_id'] ?? '') === (string)$work['job_id']
            && (string)($data['claim_id'] ?? '') === (string)$work['claim_id']
            && (string)($data['trigger_event_id'] ?? '') === (string)$work['trigger_event_id']
            && (string)($data['actor_id'] ?? '') === (string)$work['actor_id']
            && ($data['source_message_id'] ?? null) === ($work['source_message_id'] ?? null)
            && ($data['goal_hash'] ?? null) === ($work['goal_hash'] ?? null)
            && hash_equals((string)($data['context_sha256'] ?? ''), (string)$work['context_sha256'])
            && (string)($data['interaction_generation'] ?? '') === (string)$work['interaction_generation']
            && ($data['restore_generation'] ?? null) === $work['restore_generation']
            && (int)($payload['attempt'] ?? 0) === (int)($work['attempt'] ?? 0)
            && (int)($data['attempt'] ?? 0) === (int)($work['attempt'] ?? 0);
    }

    private function terminalJob(array $work, string $status, ?string $action, string $reason, mixed $exposure, bool $writeAnchor, ?string $childMessageId = null, bool $reconsiderable = false): array
    {
        if (!in_array($status, self::JOB_STATES, true)) throw new LogicException('invalid_offscreen_job_status');
        $reconsiderable = $reconsiderable && $status === 'stale' && $action === null
            && $work['kind_of_job'] === 'react' && (int)($work['attempt'] ?? 0) === 0
            && in_array($reason, ['context_changed','cooldown_changed','gate_changed','observation_changed'], true);
        $payload = [
            // Keep the immutable attempted-claim evidence so failed, silent, sent, and stale
            // outcomes continue to consume their bounded cooldown after this payload update.
            'kind'=>$work['kind_of_job'],'attempt'=>(int)($work['attempt'] ?? 0),'claim_gamets'=>(string)$work['claim_gamets'],
            'observation_anchor'=>$work['observation_anchor'],'interaction_generation'=>$work['interaction_generation'],
            'restore_generation'=>$work['restore_generation'],'source_message_id'=>$work['source_message_id'] ?? null,
            'goal_hash'=>$work['goal_hash'] ?? null,'claim_anchor'=>$work['claim_anchor'],
            'context_sha256'=>$work['context_sha256'],'reason_code'=>$reason,'completed_gamets'=>(string)$this->clock()['gamets'],
            'exposed_event_id'=>is_array($exposure) ? (string)($exposure['rowid'] ?? '') : null,
            'child_message_id'=>$childMessageId,
        ];
        if ($reconsiderable) $payload['reconsiderable'] = true;
        if ($writeAnchor) {
            $completionData = [
                'job_id'=>$work['job_id'],'claim_id'=>$work['claim_id'],'status'=>$status,'action'=>$action,
                'reason_code'=>$reason,'exposed_event_id'=>$payload['exposed_event_id'],'child_message_id'=>$childMessageId,
                'actor_id'=>(string)$work['actor_id'],'source_message_id'=>$work['source_message_id'] ?? null,
                'context_sha256'=>$work['context_sha256'],'attempt'=>(int)($work['attempt'] ?? 0),
                'gamets'=>(string)$this->clock()['gamets'],
            ];
            if ($reconsiderable) $completionData['reconsiderable'] = true;
            $payload['completion_anchor'] = $this->appendAnchor('job_completion', $completionData);
        }
        $rows = $this->store->query("UPDATE plugins.correspondence_offscreen_jobs SET status=$1,action=$2,payload=$3::jsonb WHERE playthrough_id=$4 AND job_id=$5 AND claim_id=$6 AND status='claimed' RETURNING job_id::text", [$status,$action,correspondenceJson($payload),$work['playthrough_id'],$work['job_id'],$work['claim_id']]);
        if (count($rows) !== 1) throw new DomainException('claim_lost');
        return ['status'=>in_array($status,['sent','silent'],true) ? 'processed' : $status,'reason'=>$reason];
    }

    private function exposeMail(array $mail, array $source): array
    {
        $existingId = $mail['source_event_id'] ?? null;
        if ($existingId !== null && $existingId !== '') {
            $mail = $this->mailById((string)$mail['playthrough_id'], (string)$mail['message_id']) ?? throw new DomainException('source_unavailable');
            $actors = $this->npcMap($this->npcRows());
            $this->assertMailExposure($mail, $actors);
            $event = $this->eventById((string)$mail['source_event_id']);
            if ($event === null) throw new DomainException('source_unavailable');
            return $event;
        }
        $mail = $this->mailById((string)$mail['playthrough_id'], (string)$mail['message_id']) ?? throw new DomainException('source_unavailable');
        $this->assertMailArrival($mail);
        if (!hash_equals(correspondenceCanonicalJson($source), correspondenceCanonicalJson($this->sourcePayload($mail, $this->npcMap($this->npcRows()))))) throw new DomainException('source_changed');
        $event = $this->store->appendEvent('written_evidence', correspondenceJson($source), '|'.$source['recipient']['name'].'|', 'written_evidence_v1');
        $ref = $this->eventRef($event);
        $updated = $this->store->query("UPDATE plugins.correspondence_offscreen_mail SET status='exposed',source_event_id=$3,source_event_sha256=$4,payload=payload||jsonb_build_object('exposure_anchor',$5::jsonb) WHERE playthrough_id=$1 AND message_id=$2 AND status='arrived' RETURNING message_id::text", [
            $mail['playthrough_id'] ?? $this->clock()['playthrough_id'], $mail['message_id'], (string)$event['rowid'], hash('sha256', (string)$event['data']), correspondenceJson($ref),
        ]);
        if (count($updated) !== 1) throw new DomainException('mail_exposure_transition_conflict');
        $this->pendingSourceIds[] = (int)$event['rowid'];
        return $event;
    }

    private function createMail(string $scope, array $sender, array $recipient, string $body, int $gamets, ?array $parent, string $action): array
    {
        correspondenceBody($body);
        $delay = correspondenceAutonomyDelay($sender['location'], $recipient['location']);
        if (!is_int($delay) || $delay < 1 || $gamets > PHP_INT_MAX - $delay) throw new DomainException('invalid_offscreen_delay');
        if (is_array($parent)) {
            $parent = $this->mailById($scope, (string)$parent['message_id']) ?? throw new DomainException('mail_parent_unavailable');
            if (($parent['status'] ?? null) !== 'exposed') throw new DomainException('mail_parent_unexposed');
            $actors = $this->npcMap($this->npcRows());
            $this->assertMailExposure($parent, $actors);
        }
        $messageId = correspondenceUuid();
        $rootId = is_array($parent) ? (string)$parent['root_message_id'] : $messageId;
        $depth = is_array($parent) ? (int)$parent['depth'] + 1 : 0;
        if ($depth > self::MAX_THREAD_DEPTH) throw new DomainException('offscreen_chain_limit');
        $due = $gamets + $delay;
        $anchor = $this->appendAnchor('mail_created', [
            'message_id'=>$messageId,'sender_id'=>(string)$sender['id'],'recipient_id'=>(string)$recipient['id'],
            'root_message_id'=>$rootId,'depth'=>$depth,'body_sha256'=>hash('sha256',$body),
            'created_gamets'=>(string)$gamets,'due_gamets'=>(string)$due,'action'=>$action,
        ]);
        $this->store->query("INSERT INTO plugins.correspondence_offscreen_mail(playthrough_id,message_id,sender_id,recipient_id,root_message_id,depth,status,body,created_gamets,due_gamets,payload) VALUES($1,$2,$3,$4,$5,$6,'in_transit',$7,$8,$9,$10::jsonb)", [
            $scope,$messageId,(string)$sender['id'],(string)$recipient['id'],$rootId,$depth,$body,(string)$gamets,(string)$due,
            correspondenceJson(['creation_anchor'=>$anchor,'parent_message_id'=>is_array($parent) ? ($parent['message_id'] ?? null) : null,'action'=>$action]),
        ]);
        return ['message_id'=>$messageId,'due_gamets'=>$due];
    }

    private function dueMail(string $scope, int $gamets): ?array
    {
        $rows = $this->store->query("SELECT playthrough_id,message_id::text,sender_id::text,recipient_id::text,root_message_id::text,depth,status,body,created_gamets::text,due_gamets::text,arrived_gamets::text,source_event_id::text,source_event_sha256,payload::text FROM plugins.correspondence_offscreen_mail WHERE playthrough_id=$1 AND status='in_transit' AND due_gamets<=$2 ORDER BY due_gamets,created_gamets,message_id LIMIT 1", [$scope,(string)$gamets]);
        if ($rows === []) return null;
        $mail = $this->normalizeMail($rows[0]);
        $this->assertMailCreation($mail);
        return $mail;
    }

    private function quarantineInvalidLiveMail(string $scope): bool
    {
        $rows = $this->store->query("SELECT playthrough_id,message_id::text,sender_id::text,recipient_id::text,root_message_id::text,depth,status,body,created_gamets::text,due_gamets::text,arrived_gamets::text,source_event_id::text,source_event_sha256,payload::text FROM plugins.correspondence_offscreen_mail WHERE playthrough_id=$1 AND status IN ('in_transit','arrived','exposed') ORDER BY created_gamets,message_id", [$scope]);
        $actors = null;
        foreach ($rows as $row) {
            try {
                $mail = $this->normalizeMail($row);
            } catch (DomainException|InvalidArgumentException|JsonException) {
                $this->quarantineInvalidMail($scope, (string)$row['message_id']);
                return true;
            }
            if ($mail['status'] === 'exposed' && $actors === null) $actors = $this->npcMap($this->npcRows());
            try {
                if ($mail['status'] === 'in_transit') $this->assertMailCreation($mail);
                elseif ($mail['status'] === 'arrived') $this->assertMailArrival($mail);
                elseif ($mail['status'] === 'exposed') $this->assertMailExposure($mail, $actors);
            } catch (DomainException|InvalidArgumentException|JsonException) {
                $this->quarantineInvalidMail($scope, (string)$row['message_id']);
                return true;
            }
        }
        return false;
    }

    private function quarantineInvalidMail(string $scope, string $messageId): void
    {
        $anchor = $this->appendAnchor('mail_stale', ['message_id'=>$messageId,'reason_code'=>'anchor_invalid']);
        $changed = $this->store->query("UPDATE plugins.correspondence_offscreen_mail SET status='stale',payload=payload||jsonb_build_object('stale_anchor',$2::jsonb,'reason_code','anchor_invalid') WHERE playthrough_id=$1 AND message_id=$3 AND status IN ('in_transit','arrived','exposed') RETURNING message_id::text", [$scope,correspondenceJson($anchor),$messageId]);
        if (count($changed) !== 1) throw new DomainException('mail_quarantine_conflict');
    }

    private function reactionCandidate(string $scope, int $gamets, array $actors): ?array
    {
        $retryableIds = array_keys($this->retryableSourceIds);
        $retryableArray = '{'.implode(',', $retryableIds).'}';
        $rows = $this->store->query("SELECT m.playthrough_id,m.message_id::text,m.sender_id::text,m.recipient_id::text,m.root_message_id::text,m.depth,m.status,m.body,m.created_gamets::text,m.due_gamets::text,m.arrived_gamets::text,m.source_event_id::text,m.source_event_sha256,m.payload::text FROM plugins.correspondence_offscreen_mail m WHERE m.playthrough_id=$1 AND m.status='arrived' AND m.due_gamets<=$2 AND (NOT EXISTS(SELECT 1 FROM plugins.correspondence_offscreen_jobs j WHERE j.playthrough_id=m.playthrough_id AND j.source_message_id=m.message_id) OR m.message_id=ANY($3::uuid[])) ORDER BY m.due_gamets,m.created_gamets,m.message_id LIMIT 16", [$scope,(string)$gamets,$retryableArray]);
        foreach ($rows as $row) {
            $sender = $actors[(int)$row['sender_id']] ?? null;
            $self = $actors[(int)$row['recipient_id']] ?? null;
            if (!$this->eligible($sender) || !$this->eligible($self)) continue;
            $mail = $this->normalizeMail($row);
            $this->assertMailArrival($mail);
            $arrived = $this->intValue($mail['arrived_gamets'] ?? null);
            $delay = correspondenceAutonomyGamets(2);
            if ($arrived < 1 || $arrived > PHP_INT_MAX - $delay || $gamets < $arrived + $delay) continue;
            $source = $this->sourceForMail($mail, $actors);
            if ($source === null) continue;
            $contacts = $this->contacts($self, $actors, (int)$mail['sender_id']);
            if ($contacts === []) continue;
            $selfProjection = $this->selfProjection($self, $contacts);
            try {
                $messages = correspondenceAutonomyMessages($source, $selfProjection, $contacts);
            } catch (Throwable) {
                continue;
            }
            return ['mail'=>$mail,'source'=>$source,'self'=>$selfProjection,'contacts'=>$contacts,'messages'=>$messages];
        }
        return null;
    }

    private function retireIneligibleArrivals(string $scope, array $actors): bool
    {
        $rows = $this->store->query("SELECT playthrough_id,message_id::text,sender_id::text,recipient_id::text,root_message_id::text,depth,status,body,created_gamets::text,due_gamets::text,arrived_gamets::text,source_event_id::text,source_event_sha256,payload::text FROM plugins.correspondence_offscreen_mail m WHERE m.playthrough_id=$1 AND m.status='arrived' AND NOT EXISTS(SELECT 1 FROM plugins.correspondence_offscreen_jobs j WHERE j.playthrough_id=m.playthrough_id AND j.source_message_id=m.message_id) ORDER BY m.due_gamets,m.created_gamets,m.message_id LIMIT 16", [$scope]);
        foreach ($rows as $row) {
            if ($this->eligible($actors[(int)$row['sender_id']] ?? null) && $this->eligible($actors[(int)$row['recipient_id']] ?? null)) continue;
            $mail = $this->normalizeMail($row);
            if ($this->retireIneligibleArrival($scope, $mail, 'actor_ineligible')) return true;
        }
        return false;
    }

    private function retireIneligibleArrival(string $scope, array $mail, string $reason): bool
    {
        if (!in_array($reason, ['actor_ineligible','restore_changed'], true)
            || ($this->clock()['playthrough_id'] ?? null) !== $scope) return false;
        try {
            $mail = $this->normalizeMail($mail);
            if (($mail['status'] ?? null) !== 'arrived' || ($mail['source_event_id'] ?? null) !== null
                || ($mail['source_event_sha256'] ?? null) !== null) return false;
            $this->assertMailArrival($mail);
        } catch (DomainException|InvalidArgumentException|JsonException) {
            return false;
        }
        $payload = $mail['payload_array'];
        $creationAnchor = $payload['creation_anchor'] ?? null;
        $arrivalAnchor = $payload['arrival_anchor'] ?? null;
        if (!is_array($creationAnchor) || !is_array($arrivalAnchor)) return false;
        if ($reason === 'actor_ineligible') {
            $locked = $this->store->lockActorRows([(int)$mail['sender_id'],(int)$mail['recipient_id']]);
            $actors = $this->npcMap(array_values($locked['actors'] ?? []));
            if ($this->eligible($actors[(int)$mail['sender_id']] ?? null)
                && $this->eligible($actors[(int)$mail['recipient_id']] ?? null)) return false;
        }
        $reference = $this->appendAnchor('mail_stale', ['message_id'=>$mail['message_id'],'reason_code'=>$reason]);
        $changed = $this->store->query("UPDATE plugins.correspondence_offscreen_mail SET status='stale',payload=payload||jsonb_build_object('stale_anchor',$11::jsonb,'reason_code',$12::text) WHERE playthrough_id=$1 AND message_id=$2 AND sender_id=$3 AND recipient_id=$4 AND root_message_id=$5 AND depth=$6 AND status='arrived' AND body=$7 AND created_gamets=$8 AND due_gamets=$9 AND arrived_gamets=$10 AND source_event_id IS NULL AND source_event_sha256 IS NULL AND payload->'creation_anchor'=$13::jsonb AND payload->'arrival_anchor'=$14::jsonb RETURNING message_id::text", [
            $scope,$mail['message_id'],$mail['sender_id'],$mail['recipient_id'],$mail['root_message_id'],(string)$mail['depth'],$mail['body'],$mail['created_gamets'],$mail['due_gamets'],$mail['arrived_gamets'],correspondenceJson($reference),$reason,correspondenceJson($creationAnchor),correspondenceJson($arrivalAnchor),
        ]);
        if (count($changed) !== 1) throw new DomainException('mail_retirement_conflict');
        return true;
    }

    private function attemptValue(array $payload): ?int
    {
        $attempt = $payload['attempt'] ?? 0;
        return is_int($attempt) && in_array($attempt, [0,1], true) ? $attempt : null;
    }

    private function reconsiderationMarkerMatches(array $payload, array $anchor): bool
    {
        $payloadMarker = $payload['reconsiderable'] ?? false;
        $anchorMarker = $anchor['reconsiderable'] ?? false;
        return is_bool($payloadMarker) && is_bool($anchorMarker) && $payloadMarker === $anchorMarker;
    }

    private function sourceForMail(array $mail, array $actors): ?array
    {
        $mail = $this->normalizeMail($mail);
        if (!in_array($mail['status'] ?? null, ['arrived','exposed'], true)) return null;
        $this->assertMailArrival($mail);
        if ($mail['status'] === 'exposed') $this->assertMailExposure($mail, $actors);
        $sender = $actors[(int)$mail['sender_id']] ?? null;
        $recipient = $actors[(int)$mail['recipient_id']] ?? null;
        if (!$this->eligible($sender) || !$this->eligible($recipient)) return null;
        return $this->sourcePayload($mail, $actors);
    }

    private function sourcePayload(array $mail, array $actors): array
    {
        $sender = $actors[(int)$mail['sender_id']] ?? null;
        $recipient = $actors[(int)$mail['recipient_id']] ?? null;
        if (!is_array($sender) || !is_array($recipient)) throw new DomainException('mail_actor_missing');
        $source = [
            'schema_version'=>1,'document_id'=>(string)$mail['message_id'],'playthrough_id'=>(string)$mail['playthrough_id'],
            'recipient'=>['kind'=>'npc','id'=>(int)$recipient['id'],'name'=>$recipient['name']],
            'claimed_sender'=>['kind'=>'npc','id'=>(int)$sender['id'],'name'=>$sender['name']],
            'body'=>(string)$mail['body'],
            'receipt'=>['receipt_id'=>(string)$mail['message_id'],'operation_id'=>$this->virtualOperationId((string)$mail['message_id']),'status'=>'confirmed','exposure'=>'context'],
            'attachments'=>[],
        ];
        return $source;
    }

    private function virtualOperationId(string $messageId): string
    {
        correspondenceUuidCheck($messageId);
        $bytes = substr(hash('sha256', "offscreen-text-context-v1\0" . $messageId, true), 0, 16);
        $bytes[6] = chr((ord($bytes[6]) & 15) | 80);
        $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
        $hex = bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }

    private function readExposedSource(array $mail, array $actors): ?array
    {
        try {
            $mail = $this->normalizeMail($mail);
            if (($mail['status'] ?? null) !== 'exposed') return null;
            $this->assertMailExposure($mail, $actors);
            $recipient = $actors[(int)$mail['recipient_id']] ?? null;
            if (!is_array($recipient)) return null;
            $source = $this->sourcePayload($mail, $actors);
            $contacts = $this->contacts($recipient, $actors, (int)$mail['sender_id']);
            if ($contacts === []) return null;
            correspondenceAutonomyMessages($source, $this->selfProjection($recipient, $contacts), $contacts);
            return $source;
        } catch (Throwable) {
            return null;
        }
    }

    private function assertMailCreation(array $mail): void
    {
        $mail = $this->normalizeMail($mail);
        $payload = $mail['payload_array'];
        $reference = $payload['creation_anchor'] ?? null;
        if (!is_array($reference) || !$this->validEventRef($reference, 'info', 'correspondence_v1')) throw new DomainException('mail_creation_anchor_invalid');
        $event = $this->eventById((string)$reference['rowid']);
        $data = $event === null ? [] : $this->jsonObject($event['data'] ?? null);
        $depth = $this->intValue($mail['depth'] ?? null);
        $created = (string)($mail['created_gamets'] ?? '');
        $due = (string)($mail['due_gamets'] ?? '');
        if ($event === null || ($data['kind'] ?? null) !== 'offscreen_transition' || ($data['transition'] ?? null) !== 'mail_created'
            || (string)($data['message_id'] ?? '') !== (string)$mail['message_id']
            || (string)($data['sender_id'] ?? '') !== (string)$mail['sender_id']
            || (string)($data['recipient_id'] ?? '') !== (string)$mail['recipient_id']
            || (string)($data['root_message_id'] ?? '') !== (string)$mail['root_message_id']
            || $this->intValue($data['depth'] ?? null) !== $depth
            || !hash_equals((string)($data['body_sha256'] ?? ''), hash('sha256',(string)$mail['body']))
            || (string)($data['created_gamets'] ?? '') !== $created || (string)($data['due_gamets'] ?? '') !== $due
            || (string)($event['gamets'] ?? '') !== $created) throw new DomainException('mail_creation_anchor_mismatch');
        correspondenceUuidCheck((string)$mail['message_id']);
        correspondenceUuidCheck((string)$mail['root_message_id']);
        if ($depth < 0 || $depth > self::MAX_THREAD_DEPTH || (int)$mail['sender_id'] === (int)$mail['recipient_id']) throw new DomainException('mail_creation_invalid');
        correspondenceBody((string)$mail['body']);
        if (($mail['status'] ?? null) === 'in_transit' && (($mail['arrived_gamets'] ?? null) !== null || ($mail['source_event_id'] ?? null) !== null)) throw new DomainException('mail_transit_state_invalid');
        $parentId = $payload['parent_message_id'] ?? null;
        if ($parentId === null) {
            if ($depth !== 0 || $mail['root_message_id'] !== $mail['message_id'] || ($data['action'] ?? null) !== 'letter') throw new DomainException('mail_root_invalid');
            return;
        }
        correspondenceUuidCheck($parentId);
        if ($depth < 1 || !in_array($data['action'] ?? null, ['reply','forward','mediate'], true)) throw new DomainException('mail_child_invalid');
        $parent = $this->mailById((string)$mail['playthrough_id'], $parentId);
        if ($parent === null || ($parent['status'] ?? null) !== 'exposed') throw new DomainException('mail_parent_unexposed');
        $actors = $this->npcMap($this->npcRows());
        $this->assertMailExposure($parent, $actors);
        $parentExposure = $this->eventById((string)$parent['source_event_id']);
        if ((string)$parent['root_message_id'] !== (string)$mail['root_message_id']
            || (int)$parent['depth'] + 1 !== $depth || (string)$parent['recipient_id'] !== (string)$mail['sender_id']
            || $parentExposure === null || $this->intValue($event['rowid'] ?? null) <= $this->intValue($parentExposure['rowid'] ?? null)
            || (float)($event['gamets'] ?? 0) < (float)($parentExposure['gamets'] ?? 0)) throw new DomainException('mail_parent_chain_mismatch');
        if (($data['action'] === 'reply' && (string)$mail['recipient_id'] !== (string)$parent['sender_id'])
            || ($data['action'] !== 'reply' && (string)$mail['recipient_id'] === (string)$parent['sender_id'])) throw new DomainException('mail_action_target_mismatch');
    }

    private function assertMailArrival(array $mail): void
    {
        $mail = $this->normalizeMail($mail);
        if (!in_array($mail['status'] ?? null, ['arrived','exposed'], true)) throw new DomainException('mail_not_arrived');
        $this->assertMailCreation($mail);
        $payload = $mail['payload_array'];
        $reference = $payload['arrival_anchor'] ?? null;
        $arrived = (string)($mail['arrived_gamets'] ?? '');
        if (!is_array($reference) || !$this->validEventRef($reference, 'info', 'correspondence_v1') || $arrived === '') throw new DomainException('mail_arrival_anchor_invalid');
        $event = $this->eventById((string)$reference['rowid']);
        $data = $event === null ? [] : $this->jsonObject($event['data'] ?? null);
        if ($event === null || ($data['kind'] ?? null) !== 'offscreen_transition' || ($data['transition'] ?? null) !== 'mail_arrived'
            || (string)($data['message_id'] ?? '') !== (string)$mail['message_id']
            || (string)($data['sender_id'] ?? '') !== (string)$mail['sender_id']
            || (string)($data['recipient_id'] ?? '') !== (string)$mail['recipient_id']
            || (string)($data['gamets'] ?? '') !== $arrived || (string)($event['gamets'] ?? '') !== $arrived
            || (float)$arrived < (float)$mail['due_gamets']
            || $this->intValue($reference['rowid'] ?? null) <= $this->intValue($payload['creation_anchor']['rowid'] ?? null)
            || (($mail['status'] === 'arrived') !== (($mail['source_event_id'] ?? null) === null))) throw new DomainException('mail_arrival_anchor_mismatch');
    }

    private function assertMailExposure(array $mail, array $actors): void
    {
        $mail = $this->normalizeMail($mail);
        $this->assertMailArrival($mail);
        $payload = $mail['payload_array'];
        $reference = $payload['exposure_anchor'] ?? null;
        $sourceId = (string)($mail['source_event_id'] ?? '');
        $sourceHash = (string)($mail['source_event_sha256'] ?? '');
        if (!is_array($reference) || !$this->validEventRef($reference, 'info', 'written_evidence_v1')
            || (string)($reference['rowid'] ?? '') !== $sourceId || preg_match('/\A[0-9a-f]{64}\z/D',$sourceHash)!==1) throw new DomainException('mail_exposure_anchor_invalid');
        $event = $this->eventById($sourceId);
        $arrivalRef = $payload['arrival_anchor'] ?? [];
        if ($event === null || !hash_equals($sourceHash, hash('sha256',(string)$event['data']))
            || $this->intValue($sourceId) <= $this->intValue($arrivalRef['rowid'] ?? null)
            || (float)($event['gamets'] ?? 0) < (float)($mail['arrived_gamets'] ?? 0)
            || (string)($event['people'] ?? '') !== '|'.($actors[(int)$mail['recipient_id']]['name'] ?? '').'|') throw new DomainException('mail_exposure_anchor_mismatch');
        $source = json_decode((string)$event['data'], true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($source) || !hash_equals(correspondenceCanonicalJson($this->sourcePayload($mail, $actors)), correspondenceCanonicalJson($source))) throw new DomainException('mail_exposure_source_mismatch');
    }
    private function initiationCandidate(string $scope, int $gamets, array $actors): ?array
    {
        foreach ($actors as $npc) {
            if (!$this->eligible($npc) || !is_string($npc['goal_text'] ?? null) || trim($npc['goal_text']) === '') continue;
            $goalText = trim($npc['goal_text']);
            try { _correspondenceAutonomyText($goalText, 1000, 4000); } catch (Throwable) { continue; }
            $goalId = hash('sha256', $goalText);
            if ($this->actorCooldown($scope, (int)$npc['id'], $gamets) > $gamets) continue;
            $pending = $this->store->query("SELECT 1 FROM plugins.correspondence_offscreen_mail m WHERE m.playthrough_id=$1 AND m.sender_id=$2 AND m.depth=0 AND m.status IN ('in_transit','arrived') AND NOT (m.status='arrived' AND m.source_event_id IS NULL AND m.source_event_sha256 IS NULL AND EXISTS (SELECT 1 FROM plugins.correspondence_offscreen_jobs j WHERE j.playthrough_id=m.playthrough_id AND j.source_message_id=m.message_id AND j.status IN ('failed','stale') AND j.action IS NULL AND j.payload->>'kind'='react')) LIMIT 1", [$scope,(string)$npc['id']]);
            if ($pending !== []) continue;
            if ($this->contacts($npc, $actors) === []) continue;
            return ['self'=>$npc,'goal'=>['id'=>$goalId,'text'=>$goalText]];
        }
        return null;
    }

    private function contacts(array $self, array $actors, ?int $preferredSenderId = null): array
    {
        $selfId = (int)$self['id'];
        $selfName = mb_strtolower($self['name'],'UTF-8');
        $contacts = [];
        $names = [$selfName=>true];
        if ($preferredSenderId !== null) {
            $sender = $actors[$preferredSenderId] ?? null;
            if ($preferredSenderId < 1 || $preferredSenderId === $selfId || !$this->eligible($sender)
                || ($sender['key'] ?? null) !== 'npc:'.$preferredSenderId) return [];
            $senderName = mb_strtolower($sender['name'],'UTF-8');
            if ($senderName === $selfName) return [];
            $contacts[] = ['key'=>$sender['key'],'name'=>$sender['name']];
            $names[$senderName] = true;
        }
        foreach ($actors as $npc) {
            if (!$this->eligible($npc) || (int)$npc['id'] === $selfId || (int)$npc['id'] === $preferredSenderId) continue;
            $name = mb_strtolower($npc['name'],'UTF-8');
            if (isset($names[$name])) continue;
            $names[$name] = true;
            $contacts[] = ['key'=>$npc['key'],'name'=>$npc['name']];
            if (count($contacts) >= 32) break;
        }
        return $contacts;
    }

    private function selfProjection(array $self, array $contacts): array
    {
        $allowed = array_fill_keys(array_column($contacts,'key'), true);
        $relationships = [];
        foreach (($self['relationships'] ?? []) as $name => $relationship) {
            $contact = null;
            foreach ($contacts as $candidate) if (mb_strtolower($candidate['name'],'UTF-8') === mb_strtolower((string)$name,'UTF-8')) { $contact = $candidate; break; }
            if ($contact === null || !is_array($relationship) || !array_key_exists('aff',$relationship)) continue;
            $affinity = $relationship['aff'];
            if ((!is_int($affinity) && !is_float($affinity)) || (is_float($affinity) && !is_finite($affinity)) || $affinity < -100 || $affinity > 100) continue;
            $relationships[] = ['key'=>$contact['key'],'affinity'=>$affinity];
        }
        return ['key'=>$self['key'],'name'=>$self['name'],'personality'=>$self['personality'],'relationships'=>$relationships];
    }

    private function npcRows(): array
    {
        return $this->store->offscreenActors();
    }

    private function npcMap(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $id = $this->intValue($row['id'] ?? null);
            if ($id < 1 || $id > 2147483647 || !correspondenceActorName($row['npc_name'] ?? null)) continue;
            $extended = $this->jsonObject($row['extended_data'] ?? null);
            $metadata = $this->jsonObject($row['metadata'] ?? null);
            $stats = is_array($metadata['stats'] ?? null) ? $metadata['stats'] : [];
            $personality = $row['personality'] ?? '';
            if (!is_string($personality) || strlen($personality) > 8000 || preg_match('//u',$personality)!==1 || preg_match_all('/./us',$personality)>2000) $personality = '';
            $coords = $metadata['last_coords'] ?? null;
            if (is_string($coords)) $coords = json_decode($coords, true);
            $location = is_array($coords) && is_string($coords[3] ?? null) && strlen($coords[3]) <= 200 && preg_match('//u',$coords[3])===1 ? $coords[3] : '';
            $goal = $extended['background_life_goals'] ?? '';
            if (!is_string($goal)) $goal = '';
            $relationships = $extended['relationships'] ?? [];
            if (!is_array($relationships) || array_is_list($relationships)) $relationships = [];
            $result[$id] = [
                'id'=>$id,'key'=>'npc:'.$id,'name'=>$row['npc_name'],'personality'=>$personality,'location'=>$location,
                'enabled'=>$this->dbBoolean($extended['background_life_enabled'] ?? false),
                'letters'=>$this->dbBoolean($extended['background_life_letters'] ?? false),
                'dead'=>$this->dbBoolean($stats['is_dead'] ?? false),'goal_text'=>$goal,'relationships'=>$relationships,
            ];
        }
        return $result;
    }

    private function eligible(?array $npc): bool
    {
        return is_array($npc) && ($npc['enabled'] ?? false) === true && ($npc['letters'] ?? false) === true && ($npc['dead'] ?? false) !== true;
    }

    private function globalCooldown(string $scope, int $gamets, ?string $excludeClaimId = null): int
    {
        return $this->cooldownUntil($this->latestAttemptGamets($scope, null, $excludeClaimId), 1, $gamets);
    }

    private function actorCooldown(string $scope, int $actorId, int $gamets, ?string $excludeClaimId = null): int
    {
        return $this->cooldownUntil($this->latestAttemptGamets($scope, $actorId, $excludeClaimId), 6, $gamets);
    }

    private function latestAttemptGamets(string $scope, ?int $actorId, ?string $excludeClaimId = null): ?int
    {
        $restoreGeneration = $this->restoreGeneration();
        if ($restoreGeneration === null) return PHP_INT_MAX;
        $sql = "SELECT playthrough_id,job_id::text,trigger_event_id::text,actor_id::text,source_message_id::text,goal_hash,claim_id::text,status,action,payload::text,created_at FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id=$1 AND status IN ('claimed','sent','silent','failed','stale') AND payload->>'claim_invalidated' IS DISTINCT FROM 'true'";
        $args = [$scope];
        if ($actorId !== null) { $sql .= ' AND actor_id=$2'; $args[] = (string)$actorId; }
        if ($excludeClaimId !== null) { $sql .= ' AND claim_id<>$' . (count($args) + 1); $args[] = $excludeClaimId; }
        $rows = $this->store->query($sql, $args);
        $latest = null;
        $invalid = false;
        foreach ($rows as $row) {
            $payload = $this->jsonObject($row['payload'] ?? null);
            // Restore creates a new game-time timeline. Old claims remain auditable but must not
            // pace a new timeline whose generation and native anchors have changed.
            if (($payload['restore_generation'] ?? null) !== $restoreGeneration) continue;
            $claimGamets = $this->validatedClaimGamets($row, $payload);
            if ($claimGamets === null) {
                $anchor = $this->appendAnchor('job_cooldown_invalidated', ['job_id'=>(string)$row['job_id'],'claim_id'=>(string)$row['claim_id'],'reason_code'=>'claim_anchor_invalid']);
                $this->store->query("UPDATE plugins.correspondence_offscreen_jobs SET status='stale',payload=payload||jsonb_build_object('claim_invalidated',TRUE,'reason_code','claim_anchor_invalid','stale_anchor',$3::jsonb) WHERE playthrough_id=$1 AND job_id=$2 AND status IN ('claimed','sent','silent','failed','stale')", [$scope,$row['job_id'],correspondenceJson($anchor)]);
                $invalid = true;
                continue;
            }
            if (in_array($row['status'] ?? null, ['sent','silent'], true)
                && !($payload['completion_invalidated'] ?? false)
                && $this->validatedCompletionGamets($row, $payload) === null) {
                $anchor = $this->appendAnchor('job_cooldown_invalidated', ['job_id'=>(string)$row['job_id'],'claim_id'=>(string)$row['claim_id'],'reason_code'=>'completion_anchor_invalid']);
                $this->store->query("UPDATE plugins.correspondence_offscreen_jobs SET status='stale',payload=payload||jsonb_build_object('completion_invalidated',TRUE,'reason_code','completion_anchor_invalid','stale_anchor',$3::jsonb) WHERE playthrough_id=$1 AND job_id=$2 AND status IN ('sent','silent')", [$scope,$row['job_id'],correspondenceJson($anchor)]);
            }
            if ($latest === null || $claimGamets > $latest) $latest = $claimGamets;
        }
        return $invalid ? PHP_INT_MAX : $latest;
    }

    private function validatedClaimGamets(array $row, array $payload): ?int
    {
        $reference = $payload['claim_anchor'] ?? null;
        $observationRef = $payload['observation_anchor'] ?? null;
        $contextHash = $payload['context_sha256'] ?? null;
        if (!is_array($reference) || !is_array($observationRef) || !is_string($contextHash)
            || preg_match('/\A[0-9a-f]{64}\z/D',$contextHash)!==1
            || !$this->validEventRef($reference, 'info', 'correspondence_v1')
            || !$this->validEventRef($observationRef, 'info', 'correspondence_v1')) return null;
        $claimEvent = $this->eventById((string)$reference['rowid']);
        $observationEvent = $this->eventById((string)$observationRef['rowid']);
        $claim = $claimEvent === null ? [] : $this->jsonObject($claimEvent['data'] ?? null);
        $observation = $observationEvent === null ? [] : $this->jsonObject($observationEvent['data'] ?? null);
        $gamets = $claimEvent['gamets'] ?? null;
        $observationSnapshot = [
            'playthrough_id'=>(string)($observation['playthrough_id'] ?? ''),
            'native_type'=>(string)($observation['native_type'] ?? ''),
            'ts'=>$this->intValue($observation['native_ts'] ?? null),
            'gamets'=>$this->intValue($observation['gamets'] ?? null),
            'request_sha256'=>(string)($observation['request_sha256'] ?? ''),
            'interaction_generation'=>isset($observation['interaction_generation']) ? (int)$observation['interaction_generation'] : null,
            'restore_generation'=>$observation['restore_generation'] ?? null,
            'boundary'=>($observation['boundary'] ?? null) === true,
        ];
        if ($claimEvent === null || $observationEvent === null || !is_numeric($gamets)
            || ($claim['kind'] ?? null) !== 'offscreen_transition' || ($claim['transition'] ?? null) !== 'job_claim'
            || (string)($claim['job_id'] ?? '') !== (string)($row['job_id'] ?? '')
            || (string)($claim['claim_id'] ?? '') !== (string)($row['claim_id'] ?? '')
            || (string)($claim['trigger_event_id'] ?? '') !== (string)($row['trigger_event_id'] ?? '')
            || (string)($claim['actor_id'] ?? '') !== (string)($row['actor_id'] ?? '')
            || ($claim['source_message_id'] ?? null) !== ($row['source_message_id'] ?? null)
            || ($claim['goal_hash'] ?? null) !== ($row['goal_hash'] ?? null)
            || !hash_equals($contextHash,(string)($claim['context_sha256'] ?? ''))
            || (string)($payload['claim_gamets'] ?? '') !== (string)$gamets
            || ($observation['kind'] ?? null) !== 'offscreen_observation'
            || (string)($observation['playthrough_id'] ?? '') !== (string)($row['playthrough_id'] ?? '')
            || (string)($observationRef['rowid'] ?? '') !== (string)($claim['trigger_event_id'] ?? '')
            || (string)($observationEvent['gamets'] ?? '') !== (string)$gamets
            || (string)($claimEvent['ts'] ?? '') !== (string)($observationEvent['ts'] ?? '')
            || (string)($claim['interaction_generation'] ?? '') !== (string)($payload['interaction_generation'] ?? '')
            || ($claim['restore_generation'] ?? null) !== ($payload['restore_generation'] ?? null)
            || $this->attemptValue($payload) === null
            || (int)($claim['attempt'] ?? 0) !== $this->attemptValue($payload)
            || (string)($observation['interaction_generation'] ?? '') !== (string)($payload['interaction_generation'] ?? '')
            || ($observation['restore_generation'] ?? null) !== ($payload['restore_generation'] ?? null)
            || !in_array($observation['native_type'] ?? null, self::NATIVE_TYPES, true)
            || ($observation['boundary'] ?? null) !== false
            || !$this->validObservationAnchor($observationRef, $observationSnapshot)) return null;
        $parsed = $this->intValue((string)$gamets);
        return $parsed > 0 ? $parsed : null;
    }

    private function expireAbandonedClaims(string $scope): bool
    {
        $restoreGeneration = $this->restoreGeneration();
        if ($restoreGeneration === null || ($this->clock()['playthrough_id'] ?? null) !== $scope) return false;
        $rows = $this->store->query("SELECT playthrough_id,job_id::text,trigger_event_id::text,actor_id::text,source_message_id::text,goal_hash,claim_id::text,status,action,payload::text,created_at FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id=$1 AND status='claimed' AND payload->>'restore_generation'=$3 AND created_at < clock_timestamp()-($2||' seconds')::interval ORDER BY created_at,job_id LIMIT 16 FOR UPDATE", [$scope,(string)self::CLAIM_MAX_AGE_SECONDS,$restoreGeneration]);
        $actors = null;
        foreach ($rows as $row) {
            $payload = $this->jsonObject($row['payload'] ?? null);
            if (($payload['restore_generation'] ?? null) !== $restoreGeneration
                || !in_array($payload['kind'] ?? null, ['react','initiate'], true)
                || $this->attemptValue($payload) === null
                || $this->validatedClaimGamets($row, $payload) === null) continue;

            $sourceMail = null;
            $sourceEligible = false;
            if (($payload['kind'] ?? null) === 'react') {
                $sourceId = $row['source_message_id'] ?? null;
                if (!is_string($sourceId) || $sourceId === '') continue;
                $sourceMail = $this->mailById($scope, $sourceId);
                if ($sourceMail === null || (string)$sourceMail['recipient_id'] !== (string)$row['actor_id']) continue;
                if ($actors === null) $actors = $this->npcMap($this->npcRows());
                try {
                    $this->assertMailArrival($sourceMail);
                    $sourceEligible = $this->sourceForMail($sourceMail, $actors) !== null;
                } catch (Throwable) {
                    $sourceMail = null;
                }
            }

            $completedGamets = (string)$this->clock()['gamets'];
            $attempt = $this->attemptValue($payload);
            $reconsiderable = $sourceMail !== null && $sourceEligible && $attempt === 0;
            $abandonedData = ['job_id'=>(string)$row['job_id'],'claim_id'=>(string)$row['claim_id'],'reason_code'=>'claim_timeout','attempt'=>$attempt,'gamets'=>$completedGamets];
            if ($reconsiderable) $abandonedData['reconsiderable'] = true;
            $anchor = $this->appendAnchor('job_abandoned', $abandonedData);
            $changed = $this->store->query("UPDATE plugins.correspondence_offscreen_jobs SET status='stale',payload=payload||jsonb_build_object('reason_code','claim_timeout','abandoned_anchor',$3::jsonb,'completed_gamets',$5::text)||CASE WHEN $6::boolean THEN jsonb_build_object('reconsiderable',TRUE) ELSE '{}'::jsonb END WHERE playthrough_id=$1 AND job_id=$2 AND claim_id=$4 AND status='claimed' RETURNING job_id::text", [$scope,$row['job_id'],correspondenceJson($anchor),$row['claim_id'],$completedGamets,$reconsiderable ? 'true' : 'false']);
            if (count($changed) !== 1) throw new DomainException('claim_expiry_conflict');
            if ($sourceMail !== null) {
                if (!$sourceEligible) $this->retireIneligibleArrival($scope, $sourceMail, 'actor_ineligible');
                elseif ($attempt === 1) $this->retireUnexposedSource($scope, (string)$sourceMail['message_id'], 'claim_timeout', $restoreGeneration, $sourceMail);
            }
            return true;
        }
        return false;
    }

    private function retireForeignGenerationReactionSource(string $scope): ?string
    {
        $restoreGeneration = $this->restoreGeneration();
        if ($restoreGeneration === null || ($this->clock()['playthrough_id'] ?? null) !== $scope) return null;
        $rows = $this->store->query("SELECT playthrough_id,job_id::text,trigger_event_id::text,actor_id::text,source_message_id::text,goal_hash,claim_id::text,status,action,payload::text,created_at FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id=$1 AND status='claimed' AND source_message_id IS NOT NULL AND payload->>'restore_generation' IS DISTINCT FROM $2 ORDER BY created_at,job_id LIMIT 16 FOR UPDATE", [$scope,$restoreGeneration]);
        foreach ($rows as $row) {
            $payload = $this->jsonObject($row['payload'] ?? null);
            $claimRestoreGeneration = $payload['restore_generation'] ?? null;
            if ($claimRestoreGeneration === $restoreGeneration) continue;
            $claimGamets = ($payload['kind'] ?? null) === 'react' && is_string($claimRestoreGeneration)
                ? $this->validatedClaimGamets($row, $payload)
                : null;
            $claimProofValid = $claimGamets !== null;
            $reason = $claimProofValid ? 'restore_changed' : 'claim_anchor_invalid';
            $closed = $this->store->query("UPDATE plugins.correspondence_offscreen_jobs SET status='stale',action=NULL,payload=payload||jsonb_build_object('reason_code',$3::text,'claim_invalidated',TRUE,'invalidated_restore_generation',$4::text) WHERE playthrough_id=$1 AND job_id=$2::uuid AND claim_id=$5::uuid AND status='claimed' AND source_message_id=$6::uuid AND payload->>'restore_generation' IS DISTINCT FROM $4::text RETURNING job_id::text", [$scope,$row['job_id'],$reason,$restoreGeneration,$row['claim_id'],$row['source_message_id']]);
            if (count($closed) !== 1) continue;

            try {
                $mail = $this->mailById($scope, (string)$row['source_message_id']);
            } catch (DomainException|InvalidArgumentException|JsonException) {
                $mail = null;
            }
            if ($mail === null) {
                $active = $this->store->query("SELECT status FROM plugins.correspondence_offscreen_mail WHERE playthrough_id=$1 AND message_id=$2::uuid", [$scope,$row['source_message_id']])[0] ?? null;
                if (in_array($active['status'] ?? null, ['in_transit','arrived','exposed'], true)) {
                    $this->quarantineInvalidMail($scope, (string)$row['source_message_id']);
                    return 'mail_quarantined';
                }
                return $reason;
            }
            $mailStatus = $mail['status'] ?? null;
            if ($mailStatus === 'stale' || $mailStatus === 'exposed') return $reason;
            if ($mailStatus !== 'arrived') {
                if ($mailStatus === 'in_transit') {
                    $this->quarantineInvalidMail($scope, (string)$row['source_message_id']);
                    return 'mail_quarantined';
                }
                return $reason;
            }
            try {
                $this->assertMailArrival($mail);
            } catch (DomainException|InvalidArgumentException|JsonException) {
                $this->quarantineInvalidMail($scope, (string)$row['source_message_id']);
                return 'mail_quarantined';
            }
            if (($mail['source_event_id'] ?? null) !== null || ($mail['source_event_sha256'] ?? null) !== null) {
                $this->quarantineInvalidMail($scope, (string)$row['source_message_id']);
                return 'mail_quarantined';
            }
            if (!$claimProofValid || (string)$mail['recipient_id'] !== (string)$row['actor_id']) return $reason;
            if ($this->retireIneligibleArrival($scope, $mail, 'restore_changed')) return 'restore_changed';
            return $reason;
        }
        return null;
    }

    private function recoverTerminalReactionSources(string $scope): bool
    {
        $restoreGeneration = $this->restoreGeneration();
        if ($restoreGeneration === null || ($this->clock()['playthrough_id'] ?? null) !== $scope) return false;
        $rows = $this->store->query("SELECT j.playthrough_id,j.job_id::text,j.trigger_event_id::text,j.actor_id::text,j.source_message_id::text,j.goal_hash,j.claim_id::text,j.status,j.action,j.payload::text FROM plugins.correspondence_offscreen_jobs j JOIN plugins.correspondence_offscreen_mail m ON m.playthrough_id=j.playthrough_id AND m.message_id=j.source_message_id WHERE j.playthrough_id=$1 AND j.status IN ('failed','stale') AND j.source_message_id IS NOT NULL AND j.action IS NULL AND j.payload->>'claim_invalidated' IS DISTINCT FROM 'true' AND m.status='arrived' AND m.source_event_id IS NULL AND m.source_event_sha256 IS NULL ORDER BY CASE WHEN j.payload->>'recovery_checked_at' ~ '^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])T([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9][.][0-9]{6}Z$' AND j.payload->>'recovery_checked_at' <= to_char(statement_timestamp() AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') THEN j.payload->>'recovery_checked_at' ELSE '' END,j.created_at,j.job_id LIMIT 16 FOR UPDATE OF j", [$scope]);
        $actors = null;
        foreach ($rows as $row) {
            $progress = $this->store->query("UPDATE plugins.correspondence_offscreen_jobs SET payload=jsonb_set(payload,'{recovery_checked_at}',to_jsonb(to_char(statement_timestamp() AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"')),true) WHERE playthrough_id=$1 AND job_id=$2::uuid AND claim_id=$3::uuid AND status=$4 AND action IS NULL AND source_message_id=$5::uuid RETURNING job_id::text", [$scope,$row['job_id'],$row['claim_id'],$row['status'],$row['source_message_id']]);
            if (count($progress) !== 1) throw new DomainException('terminal_recovery_progress_conflict');
            $payload = $this->jsonObject($row['payload'] ?? null);
            $claimRestoreGeneration = $payload['restore_generation'] ?? null;
            if ($claimRestoreGeneration !== $restoreGeneration) {
                $mail = $this->mailById($scope, (string)$row['source_message_id']);
                if (is_string($claimRestoreGeneration) && $this->validTerminalReaction($row, $payload, $claimRestoreGeneration)
                    && $mail !== null && (string)$mail['recipient_id'] === (string)$row['actor_id']
                    && $this->retireIneligibleArrival($scope, $mail, 'restore_changed')) return true;
                continue;
            }
            if (!$this->validTerminalReaction($row, $payload, $restoreGeneration)) continue;
            $mail = $this->mailById($scope, (string)$row['source_message_id']);
            if ($mail === null || (string)$mail['recipient_id'] !== (string)$row['actor_id']) continue;
            if ($actors === null) $actors = $this->npcMap($this->npcRows());
            try {
                if ($this->sourceForMail($mail, $actors) === null) {
                    if ($this->retireIneligibleArrival($scope, $mail, 'actor_ineligible')) return true;
                    continue;
                }
            } catch (Throwable) {
                continue;
            }
            if ($this->retryableTerminalReaction($row, $payload, $restoreGeneration)) {
                $this->retryableSourceIds[(string)$mail['message_id']] = true;
                continue;
            }
            $reason = ($payload['reason_code'] ?? null) === 'claim_timeout' ? 'claim_timeout' : 'reaction_abandoned';
            if ($this->retireUnexposedSource($scope, (string)$mail['message_id'], $reason, $restoreGeneration, $mail)) return true;
        }
        return false;
    }

    private function retryableTerminalReaction(array $row, array $payload, string $restoreGeneration): bool
    {
        $reason = $payload['reason_code'] ?? null;
        $hasRetryAnchor = $reason === 'claim_timeout'
            ? is_array($payload['abandoned_anchor'] ?? null)
            : is_array($payload['completion_anchor'] ?? null);
        return $hasRetryAnchor && ($payload['reconsiderable'] ?? null) === true
            && $this->attemptValue($payload) === 0
            && in_array($reason, [
                'claim_timeout','context_changed','cooldown_changed','gate_changed','interaction_lock_unavailable',
                'observation_changed','observation_expired',
            ], true)
            && $this->validTerminalReaction($row, $payload, $restoreGeneration);
    }

    private function validTerminalReaction(array $row, array $payload, string $restoreGeneration): bool
    {
        $reason = $payload['reason_code'] ?? null;
        $knownReasons = ['claim_anchor_invalid','claim_timeout','context_changed','cooldown_changed','gate_changed','goal_changed',
            'interaction_lock_unavailable','invalid_decision','model_error','observation_changed','observation_expired','restore_changed',
            'source_changed','target_changed','actor_changed'];
        if (!in_array($row['status'] ?? null, ['failed','stale'], true) || ($row['action'] ?? null) !== null
            || ($payload['kind'] ?? null) !== 'react' || !is_string($row['source_message_id'] ?? null)
            || ($payload['source_message_id'] ?? null) !== $row['source_message_id']
            || $this->attemptValue($payload) === null
            || ($payload['restore_generation'] ?? null) !== $restoreGeneration
            || !in_array($reason, $knownReasons, true) || ($payload['exposed_event_id'] ?? null) !== null
            || ($payload['child_message_id'] ?? null) !== null || !is_numeric($payload['completed_gamets'] ?? null)
            || $this->validatedClaimGamets($row, $payload) === null) return false;
        if (array_key_exists('completion_anchor', $payload)
            && (!$this->validTerminalCompletionAnchor($row, $payload))) return false;
        if ($reason === 'claim_timeout' && !$this->validAbandonedAnchor($row, $payload)) return false;
        return true;
    }

    private function validTerminalCompletionAnchor(array $row, array $payload): bool
    {
        $reference = $payload['completion_anchor'] ?? null;
        $claimReference = $payload['claim_anchor'] ?? null;
        if (!is_array($reference) || !is_array($claimReference)
            || !$this->validEventRef($reference, 'info', 'correspondence_v1')
            || !$this->validEventRef($claimReference, 'info', 'correspondence_v1')) return false;
        $event = $this->eventById((string)$reference['rowid']);
        $data = $event === null ? [] : $this->jsonObject($event['data'] ?? null);
        return $event !== null && ($data['kind'] ?? null) === 'offscreen_transition'
            && ($data['transition'] ?? null) === 'job_completion'
            && (string)($data['job_id'] ?? '') === (string)$row['job_id']
            && (string)($data['claim_id'] ?? '') === (string)$row['claim_id']
            && ($data['status'] ?? null) === ($row['status'] ?? null)
            && ($data['action'] ?? null) === null
            && ($data['reason_code'] ?? null) === ($payload['reason_code'] ?? null)
            && ($data['exposed_event_id'] ?? null) === null && ($data['child_message_id'] ?? null) === null
            && (string)($data['actor_id'] ?? '') === (string)$row['actor_id']
            && ($data['source_message_id'] ?? null) === ($row['source_message_id'] ?? null)
            && $this->reconsiderationMarkerMatches($payload, $data)
            && (int)($data['attempt'] ?? 0) === (int)($payload['attempt'] ?? 0)
            && hash_equals((string)($payload['context_sha256'] ?? ''), (string)($data['context_sha256'] ?? ''))
            && (string)($data['gamets'] ?? '') === (string)($payload['completed_gamets'] ?? '')
            && (string)($event['gamets'] ?? '') === (string)($payload['completed_gamets'] ?? '')
            && $this->intValue((string)$reference['rowid']) > $this->intValue((string)$claimReference['rowid']);
    }

    private function validAbandonedAnchor(array $row, array $payload): bool
    {
        $reference = $payload['abandoned_anchor'] ?? null;
        if (!is_array($reference) || !$this->validEventRef($reference, 'info', 'correspondence_v1')) return false;
        $event = $this->eventById((string)$reference['rowid']);
        $data = $event === null ? [] : $this->jsonObject($event['data'] ?? null);
        $claimReference = $payload['claim_anchor'] ?? null;
        return $event !== null && is_array($claimReference)
            && ($data['kind'] ?? null) === 'offscreen_transition' && ($data['transition'] ?? null) === 'job_abandoned'
            && (string)($data['job_id'] ?? '') === (string)$row['job_id']
            && (string)($data['claim_id'] ?? '') === (string)$row['claim_id']
            && ($data['reason_code'] ?? null) === 'claim_timeout'
            && $this->reconsiderationMarkerMatches($payload, $data)
            && (int)($data['attempt'] ?? 0) === (int)($payload['attempt'] ?? 0)
            && (string)($data['gamets'] ?? '') === (string)($payload['completed_gamets'] ?? '')
            && (string)($event['gamets'] ?? '') === (string)($payload['completed_gamets'] ?? '')
            && $this->intValue((string)$reference['rowid']) > $this->intValue((string)$claimReference['rowid']);
    }

    private function retireUnexposedSource(string $scope, string $messageId, string $reason, string $restoreGeneration, ?array $expectedMail = null): bool
    {
        if (!in_array($reason, ['reaction_abandoned','claim_timeout'], true)
            || $restoreGeneration === '' || $this->restoreGeneration() !== $restoreGeneration
            || ($this->clock()['playthrough_id'] ?? null) !== $scope) return false;
        $rows = $this->store->query("SELECT playthrough_id,message_id::text,sender_id::text,recipient_id::text,root_message_id::text,depth,status,body,created_gamets::text,due_gamets::text,arrived_gamets::text,source_event_id::text,source_event_sha256,payload::text FROM plugins.correspondence_offscreen_mail WHERE playthrough_id=$1 AND message_id=$2 FOR UPDATE", [$scope,$messageId]);
        if (count($rows) !== 1) return false;
        try {
            $mail = $this->normalizeMail($rows[0]);
            if (($mail['status'] ?? null) !== 'arrived' || ($mail['source_event_id'] ?? null) !== null
                || ($mail['source_event_sha256'] ?? null) !== null) return false;
            $this->assertMailArrival($mail);
            if ($expectedMail !== null) foreach (['playthrough_id','message_id','sender_id','recipient_id','root_message_id','depth','body','created_gamets','due_gamets','arrived_gamets'] as $field) {
                if ((string)($mail[$field] ?? '') !== (string)($expectedMail[$field] ?? '')) return false;
            }
            $actors = $this->npcMap($this->npcRows());
            if ($this->sourceForMail($mail, $actors) === null) return false;
        } catch (Throwable) {
            return false;
        }
        $creationAnchor = $mail['payload_array']['creation_anchor'] ?? null;
        $arrivalAnchor = $mail['payload_array']['arrival_anchor'] ?? null;
        if (!is_array($creationAnchor) || !is_array($arrivalAnchor)) return false;
        $anchor = $this->appendAnchor('mail_stale', ['message_id'=>$messageId,'reason_code'=>$reason]);
        $changed = $this->store->query("UPDATE plugins.correspondence_offscreen_mail SET status='stale',payload=payload||jsonb_build_object('stale_anchor',$11::jsonb,'reason_code',$12::text) WHERE playthrough_id=$1 AND message_id=$2 AND sender_id=$3 AND recipient_id=$4 AND root_message_id=$5 AND depth=$6 AND status='arrived' AND body=$7 AND created_gamets=$8 AND due_gamets=$9 AND arrived_gamets=$10 AND source_event_id IS NULL AND source_event_sha256 IS NULL AND payload->'creation_anchor'=$13::jsonb AND payload->'arrival_anchor'=$14::jsonb RETURNING message_id::text", [
            $scope,$messageId,$mail['sender_id'],$mail['recipient_id'],$mail['root_message_id'],(string)$mail['depth'],$mail['body'],$mail['created_gamets'],$mail['due_gamets'],$mail['arrived_gamets'],correspondenceJson($anchor),$reason,correspondenceJson($creationAnchor),correspondenceJson($arrivalAnchor),
        ]);
        if (count($changed) !== 1) throw new DomainException('mail_retirement_conflict');
        return true;
    }

    private function validatedCompletionGamets(array $row, array $payload): ?int
    {
        $jobId = (string)($row['job_id'] ?? '');
        $claimId = (string)($row['claim_id'] ?? '');
        $claimRef = $payload['claim_anchor'] ?? null;
        $completionRef = $payload['completion_anchor'] ?? null;
        if (!is_array($claimRef) || !is_array($completionRef)
            || !$this->validEventRef($claimRef, 'info', 'correspondence_v1')
            || !$this->validEventRef($completionRef, 'info', 'correspondence_v1')) return null;
        $claimEvent = $this->eventById((string)$claimRef['rowid']);
        $doneEvent = $this->eventById((string)$completionRef['rowid']);
        $claim = $claimEvent === null ? [] : $this->jsonObject($claimEvent['data'] ?? null);
        $done = $doneEvent === null ? [] : $this->jsonObject($doneEvent['data'] ?? null);
        $contextHash = $payload['context_sha256'] ?? null;
        $gamets = $done['gamets'] ?? null;
        if ($claimEvent === null || $doneEvent === null || !is_string($contextHash) || preg_match('/\A[0-9a-f]{64}\z/D',$contextHash)!==1
            || ($claim['kind'] ?? null) !== 'offscreen_transition' || ($claim['transition'] ?? null) !== 'job_claim'
            || $this->attemptValue($payload) === null
            || (string)($claim['job_id'] ?? '') !== $jobId || (string)($claim['claim_id'] ?? '') !== $claimId
            || (int)($claim['attempt'] ?? 0) !== $this->attemptValue($payload)
            || (string)($claim['trigger_event_id'] ?? '') !== (string)$row['trigger_event_id']
            || (string)($claim['actor_id'] ?? '') !== (string)$row['actor_id']
            || ($claim['source_message_id'] ?? null) !== ($row['source_message_id'] ?? null)
            || ($claim['goal_hash'] ?? null) !== ($row['goal_hash'] ?? null)
            || !hash_equals($contextHash,(string)($claim['context_sha256'] ?? ''))
            || ($done['kind'] ?? null) !== 'offscreen_transition' || ($done['transition'] ?? null) !== 'job_completion'
            || (string)($done['job_id'] ?? '') !== $jobId || (string)($done['claim_id'] ?? '') !== $claimId
            || ($done['status'] ?? null) !== ($row['status'] ?? null) || ($done['action'] ?? null) !== ($row['action'] ?? null)
            || (int)($done['attempt'] ?? 0) !== $this->attemptValue($payload)
            || (string)($done['actor_id'] ?? '') !== (string)$row['actor_id']
            || ($done['source_message_id'] ?? null) !== ($row['source_message_id'] ?? null)
            || !hash_equals($contextHash,(string)($done['context_sha256'] ?? ''))
            || ($done['exposed_event_id'] ?? null) !== ($payload['exposed_event_id'] ?? null)
            || ($done['child_message_id'] ?? null) !== ($payload['child_message_id'] ?? null)
            || !is_numeric($gamets) || !is_numeric($payload['completed_gamets'] ?? null)
            || (string)$gamets !== (string)$payload['completed_gamets'] || (string)($doneEvent['gamets'] ?? '') !== (string)$gamets) return null;
        if (($row['status'] ?? null) === 'silent') {
            if (($row['action'] ?? null) !== 'none' || ($payload['child_message_id'] ?? null) !== null) return null;
        } elseif (($row['status'] ?? null) === 'sent') {
            if (!in_array($row['action'] ?? null, ['letter','reply','forward','mediate'], true)
                || !is_string($payload['child_message_id'] ?? null)) return null;
            try { correspondenceUuidCheck($payload['child_message_id']); } catch (Throwable) { return null; }
            $child = $this->mailById((string)$row['playthrough_id'], $payload['child_message_id']);
            if ($child === null) return null;
            try { $this->assertMailCreation($child); } catch (Throwable) { return null; }
            if ((string)$child['sender_id'] !== (string)$row['actor_id'] || (string)$child['created_gamets'] !== (string)$gamets
                || ($child['payload_array']['action'] ?? null) !== $row['action']
                || ($child['payload_array']['parent_message_id'] ?? null) !== ($row['source_message_id'] ?? null)) return null;
        } else {
            return null;
        }
        $exposedId = $payload['exposed_event_id'] ?? null;
        if (($row['source_message_id'] ?? null) === null) {
            if ($exposedId !== null) return null;
        } else {
            if (!is_string($exposedId) || preg_match('/\A[1-9][0-9]{0,18}\z/D',$exposedId)!==1) return null;
            $sourceMail = $this->mailById((string)$row['playthrough_id'], (string)$row['source_message_id']);
            if ($sourceMail === null || ($sourceMail['status'] ?? null) !== 'exposed'
                || (string)($sourceMail['source_event_id'] ?? '') !== $exposedId) return null;
            try { $this->assertMailExposure($sourceMail, $this->npcMap($this->npcRows())); } catch (Throwable) { return null; }
        }
        $parsed = $this->intValue((string)$gamets);
        return $parsed > 0 ? $parsed : null;
    }

    private function cooldownUntil(mixed $completed, int $hours, int $gamets): int
    {
        if (!is_numeric($completed) || (float)$completed < 1 || (float)$completed > PHP_INT_MAX) return 0;
        $base = (int)$completed;
        $delay = correspondenceAutonomyGamets($hours);
        return $base > PHP_INT_MAX - $delay ? PHP_INT_MAX : $base + $delay;
    }

    private function findContact(string $key, array $contacts): ?array
    {
        foreach ($contacts as $contact) if (($contact['key'] ?? null) === $key && preg_match('/\Anpc:([1-9][0-9]{0,9})\z/D',$key,$match) === 1) {
            return ['id'=>(int)$match[1],'key'=>$key,'name'=>$contact['name']];
        }
        return null;
    }

    private function resolveOwner(string $owner, array $actors): ?array
    {
        if (preg_match('/\Anpc:([1-9][0-9]{0,9})\z/D', $owner, $match) === 1) return $actors[(int)$match[1]] ?? null;
        $matches = array_values(array_filter($actors, static fn($row) => ($row['name'] ?? null) === $owner));
        return count($matches) === 1 ? $matches[0] : null;
    }

    private function mailById(string $scope, string $messageId): ?array
    {
        $row = $this->store->query('SELECT playthrough_id,message_id::text,sender_id::text,recipient_id::text,root_message_id::text,depth,status,body,created_gamets::text,due_gamets::text,arrived_gamets::text,source_event_id::text,source_event_sha256,payload::text FROM plugins.correspondence_offscreen_mail WHERE playthrough_id=$1 AND message_id=$2', [$scope,$messageId])[0] ?? null;
        return $row === null ? null : $this->normalizeMail($row);
    }

    private function normalizeMail(array $row): array
    {
        $payload = $this->jsonObject($row['payload'] ?? null);
        return $row + ['payload_array'=>$payload];
    }

    private function observationConsumed(string $scope, string $anchorId): bool
    {
        $row = $this->store->query('SELECT consumed,anchor::text FROM plugins.correspondence_offscreen_observations WHERE playthrough_id=$1', [$scope])[0] ?? [];
        $anchor = $this->jsonObject($row['anchor'] ?? null);
        return $this->dbBoolean($row['consumed'] ?? false) || (string)($anchor['rowid'] ?? '') !== $anchorId;
    }

    private function consumeObservation(string $scope, string $anchorId, int $gamets): void
    {
        $this->store->query('UPDATE plugins.correspondence_offscreen_observations SET consumed=TRUE,last_consumed_gamets=GREATEST(last_consumed_gamets,$3) WHERE playthrough_id=$1 AND anchor->>\'rowid\'=$2', [$scope,$anchorId,(string)$gamets]);
    }

    private function observationStillCurrent(array $observation, bool $allowBoundary = false, bool $requireEventClock = true): bool
    {
        $rows = $this->store->query('SELECT native_type,native_ts::text,gamets::text,request_sha256,interaction_generation,restore_generation,boundary,consumed,last_consumed_gamets::text,anchor::text FROM plugins.correspondence_offscreen_observations WHERE playthrough_id=$1', [$observation['playthrough_id']]);
        if (count($rows) !== 1) return false;
        $row = $rows[0];
        $anchor = $this->jsonObject($row['anchor'] ?? null);
        if (($row['native_type'] ?? null) !== $observation['native_type'] || (string)$row['native_ts'] !== (string)$observation['ts']
            || (string)$row['gamets'] !== (string)$observation['gamets'] || ($row['request_sha256'] ?? null) !== $observation['request_sha256']
            || (string)($row['interaction_generation'] ?? '') !== (string)($observation['interaction_generation'] ?? '')
            || ($row['restore_generation'] ?? null) !== $observation['restore_generation']
            || (!$allowBoundary && $this->dbBoolean($row['boundary'] ?? false))
            || (string)($anchor['rowid'] ?? '') !== (string)$observation['anchor']['rowid']) return false;
        return $this->validObservationAnchor($anchor, $observation) && $this->observationFresh($observation) && (!$requireEventClock || $this->currentEventClockMatches($observation));
    }

    private function bindLatestObservation(): ?array
    {
        $scope = $this->clock()['playthrough_id'];
        $rows = $this->store->query('SELECT playthrough_id,native_type,native_ts::text,gamets::text,request_sha256,interaction_generation,restore_generation,boundary,consumed,last_consumed_gamets::text,anchor::text,GREATEST(0,EXTRACT(EPOCH FROM clock_timestamp()-updated_at))::text AS age_seconds FROM plugins.correspondence_offscreen_observations WHERE playthrough_id=$1', [$scope]);
        if (count($rows) !== 1) return null;
        $row = $rows[0];
        $anchor = $this->jsonObject($row['anchor'] ?? null);
        if ($anchor === [] || !$this->validEventRef($anchor, 'info', 'correspondence_v1')) return null;
        if (!method_exists($this->store, 'bindRequestClock')) return null;
        $request = [(string)$row['native_type'],(string)$row['native_ts'],(string)$row['gamets'],''];
        $this->store->bindRequestClock($request);
        $clock = $this->clock();
        if ($clock['playthrough_id'] !== $scope || (string)$clock['ts'] !== (string)$row['native_ts'] || (string)$clock['gamets'] !== (string)$row['gamets']) return null;
        $observation = [
            'playthrough_id'=>$scope,'native_type'=>(string)$row['native_type'],'ts'=>(int)$row['native_ts'],'gamets'=>(int)$row['gamets'],
            'request_sha256'=>(string)$row['request_sha256'],'interaction_generation'=>$row['interaction_generation'] === null ? null : (int)$row['interaction_generation'],
            'restore_generation'=>$row['restore_generation'],'boundary'=>$this->dbBoolean($row['boundary']),'consumed'=>$this->dbBoolean($row['consumed']),'anchor'=>$anchor,
            'last_consumed_gamets'=>$this->intValue($row['last_consumed_gamets'] ?? null),'age_seconds'=>(float)($row['age_seconds'] ?? PHP_INT_MAX),
        ];
        return $this->validObservationAnchor($anchor, $observation) ? $observation : null;
    }

    private function observationStillCurrentStrict(array $observation): bool
    {
        return $this->observationStillCurrent($observation) && !$observation['boundary'];
    }

    private function validEventRef(array $reference, string $type, string $session): bool
    {
        $id = $reference['rowid'] ?? null;
        $hash = $reference['data_sha256'] ?? null;
        if (!is_string($id) && !is_int($id)) return false;
        if (!is_string($hash) || preg_match('/\A[0-9a-f]{64}\z/D',$hash)!==1) return false;
        $event = $this->eventById((string)$id);
        return $event !== null && (string)($reference['rowid'] ?? '') === (string)($event['rowid'] ?? '')
            && (string)($reference['ts'] ?? '') === (string)($event['ts'] ?? '')
            && (string)($reference['gamets'] ?? '') === (string)($event['gamets'] ?? '')
            && ($reference['type'] ?? null) === ($event['type'] ?? null)
            && ($reference['sess'] ?? null) === ($event['sess'] ?? null)
            && ($event['type'] ?? null) === $type && ($event['sess'] ?? null) === $session
            && hash_equals($hash,hash('sha256',(string)$event['data'])) && $this->store->eventMatches($event);
    }

    private function observationFresh(array $observation): bool
    {
        if ((float)($observation['age_seconds'] ?? PHP_INT_MAX) > self::OBSERVATION_MAX_AGE_SECONDS) return false;
        $rows = $this->store->query('SELECT GREATEST(0,EXTRACT(EPOCH FROM clock_timestamp()-updated_at))::text AS age_seconds FROM plugins.correspondence_offscreen_observations WHERE playthrough_id=$1 AND anchor->>\'rowid\'=$2', [$observation['playthrough_id'],$observation['anchor']['rowid']]);
        return count($rows) === 1 && is_numeric($rows[0]['age_seconds'] ?? null) && (float)$rows[0]['age_seconds'] <= self::OBSERVATION_MAX_AGE_SECONDS;
    }

    private function currentEventClockMatches(array $observation): bool
    {
        $event = $this->store->query('SELECT ts::text,gamets::text FROM public.eventlog ORDER BY rowid DESC LIMIT 1')[0] ?? null;
        return $event !== null && (string)$event['ts'] === (string)$observation['ts'] && (string)$event['gamets'] === (string)$observation['gamets'];
    }

    private function validObservationAnchor(array $reference, array $observation): bool
    {
        if (!$this->validEventRef($reference, 'info', 'correspondence_v1')) return false;
        $event = $this->eventById((string)$reference['rowid']);
        $data = $event === null ? [] : $this->jsonObject($event['data'] ?? null);
        return $event !== null && ($data['kind'] ?? null) === 'offscreen_observation'
            && (string)($data['playthrough_id'] ?? '') === (string)$observation['playthrough_id']
            && ($data['native_type'] ?? null) === $observation['native_type']
            && (string)($data['native_ts'] ?? '') === (string)$observation['ts']
            && (string)($data['gamets'] ?? '') === (string)$observation['gamets']
            && hash_equals((string)($data['request_sha256'] ?? ''), (string)$observation['request_sha256'])
            && (string)($data['interaction_generation'] ?? '') === (string)($observation['interaction_generation'] ?? '')
            && ($data['restore_generation'] ?? null) === $observation['restore_generation']
            && ($data['boundary'] ?? null) === $observation['boundary'];
    }

    private function eventRef(array $event): array
    {
        if (!is_string($event['data'] ?? null) || !is_string($event['rowid'] ?? null)) throw new DomainException('invalid_anchor');
        return ['rowid'=>$event['rowid'],'ts'=>(string)$event['ts'],'gamets'=>(string)$event['gamets'],
            'type'=>(string)$event['type'],'sess'=>(string)$event['sess'],'data_sha256'=>hash('sha256',$event['data'])];
    }

    private function appendAnchor(string $transition, array $data): array
    {
        $event = $this->store->appendEvent('correspondence_anchor',correspondenceJson(['kind'=>'offscreen_transition','transition'=>$transition]+$data),'','correspondence_v1');
        return $this->eventRef($event);
    }

    private function eventById(string $eventId): ?array
    {
        if (preg_match('/\A[1-9][0-9]{0,18}\z/D',$eventId)!==1) return null;
        $rows = $this->store->query('SELECT rowid,ts,gamets,type,data,sess,localts,people FROM public.eventlog WHERE rowid=$1', [$eventId]);
        return count($rows) === 1 ? $rows[0] : null;
    }

    private function autonomyEnabled(string $scope): bool
    {
        $row = $this->store->query('SELECT enabled FROM plugins.correspondence_autonomy_settings WHERE playthrough_id=$1',[$scope])[0] ?? null;
        return $row !== null && $this->dbBoolean($row['enabled'] ?? false);
    }

    private function acquireInteractionLock(bool $begin = false): ?array
    {
        if (!function_exists('chimInteractionFile') || !function_exists('chimInteractionRead') || !function_exists('chimInteractionBegin')) return null;
        $handle = null;
        try {
            if ($begin) chimInteractionBegin();
            $handle = chimInteractionFile();
            if (!is_resource($handle) || !@flock($handle, LOCK_SH | LOCK_NB)) {
                if (is_resource($handle)) fclose($handle);
                return null;
            }
            $state = chimInteractionRead($handle);
            $captured = $GLOBALS['chim_interaction_generation'] ?? null;
            if (!is_array($state) || !is_bool($state['enabled'] ?? null) || !is_int($state['generation'] ?? null) || !is_int($captured)) {
                @flock($handle, LOCK_UN);
                fclose($handle);
                return null;
            }
            return ['handle'=>$handle,'state'=>$state,'generation'=>$captured,'passive'=>($_SERVER['HTTP_X_CHIM_PASSIVE'] ?? '') === '1'];
        } catch (Throwable) {
            if (is_resource($handle)) {
                @flock($handle, LOCK_UN);
                fclose($handle);
            }
            return null;
        }
    }

    private function releaseInteractionLock(array $lock): void
    {
        $handle = $lock['handle'] ?? null;
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function interactionSnapshotFromLock(array $lock): array
    {
        $state = $lock['state'];
        $generation = $lock['generation'];
        return ['generation'=>$generation,'enabled'=>$state['enabled'] && $state['generation'] === $generation && !$lock['passive']];
    }

    private function interactionSnapshot(bool $begin = false): array
    {
        $lock = $this->acquireInteractionLock($begin);
        if ($lock === null) return ['generation'=>null,'enabled'=>false];
        try { return $this->interactionSnapshotFromLock($lock); }
        finally { $this->releaseInteractionLock($lock); }
    }

    private function interactionLockMatches(array $lock, mixed $expectedGeneration): bool
    {
        $state = $lock['state'] ?? null;
        return is_array($state) && is_int($expectedGeneration) && $lock['generation'] === $expectedGeneration
            && $state['enabled'] === true && $state['generation'] === $expectedGeneration && ($lock['passive'] ?? true) === false;
    }

    private function interactionMatches(array $observation): bool
    {
        $current = $this->interactionSnapshot();
        return $current['enabled'] && is_int($observation['interaction_generation'] ?? null)
            && $current['generation'] === $observation['interaction_generation'];
    }

    private function restoreGeneration(): ?string
    {
        if (method_exists($this->store, 'restoreGeneration')) return $this->store->restoreGeneration();
        if (!array_key_exists('ptr_runtime_generation',$GLOBALS) || !is_string($GLOBALS['ptr_runtime_generation'])) return null;
        return $GLOBALS['ptr_runtime_generation'];
    }

    private function clock(): array
    {
        $scope = $this->store->scope();
        $id = $scope['playthrough_id'] ?? null;
        $ts = $scope['ts'] ?? null;
        $gamets = $scope['gamets'] ?? null;
        if (!is_string($id) || ($id !== 'unprofiled' && preg_match('/\A[1-9][0-9]*\z/D',$id)!==1)
            || !is_numeric($ts) || (float)$ts < 1 || (float)$ts > PHP_INT_MAX
            || !is_numeric($gamets) || (float)$gamets < 1 || (float)$gamets > PHP_INT_MAX) throw new DomainException('clock_unavailable');
        return ['playthrough_id'=>$id,'ts'=>(int)$ts,'gamets'=>(int)$gamets];
    }

    private function result(string $status, string $reason, int $calls = 0): array
    {
        return ['status'=>$status,'reason'=>$reason,'model_calls'=>max(0,min(1,$calls))];
    }

    private function policyReady(): bool
    {
        foreach (['correspondenceOffscreenInitiateMessages','correspondenceOffscreenInitiateDecision','correspondenceAutonomyMessages','correspondenceAutonomyDecision','correspondenceAutonomyBody','correspondenceAutonomyDelay','correspondenceAutonomyGamets'] as $function) if (!function_exists($function)) return false;
        return true;
    }

    private function jsonObject(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') return [];
        try { $value = json_decode($raw,true,64,JSON_THROW_ON_ERROR); } catch (JsonException) { return []; }
        return is_array($value) && !array_is_list($value) ? $value : [];
    }

    private function dbBoolean(mixed $value): bool
    {
        return $value === true || $value === 1 || (is_string($value) && in_array($value,self::TRUE_VALUES,true));
    }

    private function intValue(mixed $value): int
    {
        if (is_int($value)) return $value;
        if (!is_string($value) || preg_match('/\A-?[0-9]{1,19}\z/D',$value)!==1) return 0;
        $parsed = filter_var($value,FILTER_VALIDATE_INT);
        return $parsed === false ? 0 : $parsed;
    }

    private function charCount(string $value): int
    {
        if (preg_match('//u',$value)!==1) return strlen($value);
        $count = preg_match_all('/./us',$value,$matches);
        return $count === false ? 0 : $count;
    }
}
