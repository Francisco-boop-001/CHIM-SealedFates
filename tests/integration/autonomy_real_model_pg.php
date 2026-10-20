<?php
declare(strict_types=1);

/**
 * Guarded real-model evaluation. The root operator runs this in the disposable
 * DwemerAI4Skyrim3-test clone; this file never reads evaluator-only case fields.
 */
function realEvalCheck(bool $ok, string $reason): void
{
    if (!$ok) throw new RuntimeException($reason);
}

function realEvalQuery(PgSql\Connection $connection, string $sql, array $args = []): array
{
    $result = $args === [] ? @pg_query($connection, $sql) : @pg_query_params($connection, $sql, $args);
    realEvalCheck($result instanceof PgSql\Result, 'database_query_failed');
    try {
        return pg_num_fields($result) > 0 ? (pg_fetch_all($result) ?: []) : [];
    } finally {
        pg_free_result($result);
    }
}

function realEvalActorKey(mixed $actor): string
{
    if (is_string($actor)) $key = $actor;
    elseif (is_array($actor) && is_string($actor['key'] ?? null)) $key = $actor['key'];
    elseif (is_array($actor) && ($actor['kind'] ?? null) === 'player' && ($actor['id'] ?? null) === null) $key = 'player';
    elseif (is_array($actor) && ($actor['kind'] ?? null) === 'npc' && is_int($actor['id'] ?? null) && $actor['id'] > 0) $key = 'npc:'.$actor['id'];
    else throw new RuntimeException('invalid_public_actor');
    realEvalCheck($key === 'player' || preg_match('/\Anpc:[1-9][0-9]{0,9}\z/D', $key) === 1, 'invalid_public_actor');
    return $key;
}

function realEvalActorName(mixed $actor): string
{
    realEvalCheck(is_array($actor) && is_string($actor['name'] ?? null) && $actor['name'] !== '', 'invalid_public_actor');
    return $actor['name'];
}

function realEvalActorRecord(mixed $actor): array
{
    $key = realEvalActorKey($actor);
    $name = realEvalActorName($actor);
    if ($key === 'player') return ['kind'=>'player','id'=>null,'name'=>$name];
    return ['kind'=>'npc','id'=>(int)substr($key, 4),'name'=>$name];
}

function realEvalWrite(string $path, string $contents): void
{
    $written = @file_put_contents($path, $contents, LOCK_EX);
    realEvalCheck($written === strlen($contents), 'private_output_write_failed');
    @chmod($path, 0600);
}

function realEvalWriteJson(string $path, array $value): void
{
    realEvalWrite($path, json_encode($value, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n");
}

function realEvalCheckPublicProjection(array $messages, array $publicInput, array $expectedSource): bool
{
    if (count($messages) !== 2 || ($messages[0]['role'] ?? null) !== 'system'
        || ($messages[1]['role'] ?? null) !== 'user' || !is_string($messages[1]['content'] ?? null)) return false;
    try {
        $payload = json_decode($messages[1]['content'], true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return false;
    }
    if (!is_array($payload) || array_keys($payload) !== ['source','self','contacts']) return false;
    $expectedSelf = $publicInput['self'] ?? null;
    $expectedSender = $publicInput['claimed_sender'] ?? null;
    $expectedContacts = $publicInput['contacts'] ?? null;
    $expectedBody = $publicInput['body'] ?? null;
    $actualSource = $payload['source'] ?? null;
    $actualSelf = $payload['self'] ?? null;
    $actualContacts = $payload['contacts'] ?? null;
    if (!is_array($expectedSelf) || !is_array($expectedSender) || !is_array($expectedContacts)
        || !is_string($expectedBody) || !is_array($actualSource) || !is_array($actualSelf)
        || !is_array($actualContacts) || !is_array($expectedSource)) return false;
    $sourceKeys = array_keys($actualSource);
    $expectedSourceKeys = array_keys($expectedSource);
    sort($sourceKeys);
    sort($expectedSourceKeys);
    $neutralSourceKeys = ['attachments','body','claimed_sender','document_id','playthrough_id','receipt','recipient','schema_version'];
    if ($sourceKeys !== $neutralSourceKeys || $expectedSourceKeys !== $neutralSourceKeys
        || ($actualSource['schema_version'] ?? null) !== 1 || ($expectedSource['schema_version'] ?? null) !== 1
        || !is_string($actualSource['document_id'] ?? null) || !is_string($expectedSource['document_id'] ?? null)
        || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD',$actualSource['document_id']) !== 1
        || $actualSource['document_id'] !== $expectedSource['document_id']
        || !is_string($actualSource['playthrough_id'] ?? null) || !is_string($expectedSource['playthrough_id'] ?? null)
        || $actualSource['playthrough_id'] !== $expectedSource['playthrough_id']
        || ($actualSource['body'] ?? null) !== $expectedBody || ($expectedSource['body'] ?? null) !== $expectedBody
        || ($actualSource['attachments'] ?? null) !== [] || ($expectedSource['attachments'] ?? null) !== []) return false;
    $actualReceipt = $actualSource['receipt'] ?? null;
    $expectedReceipt = $expectedSource['receipt'] ?? null;
    if (!is_array($actualReceipt) || !is_array($expectedReceipt)) return false;
    $receiptKeys = array_keys($actualReceipt);
    $expectedReceiptKeys = array_keys($expectedReceipt);
    sort($receiptKeys);
    sort($expectedReceiptKeys);
    if ($receiptKeys !== ['exposure','operation_id','receipt_id','status']
        || $expectedReceiptKeys !== ['exposure','operation_id','receipt_id','status']
        || ($actualReceipt['status'] ?? null) !== 'confirmed' || ($actualReceipt['exposure'] ?? null) !== 'context') return false;
    foreach (['receipt_id','operation_id','status','exposure'] as $field) {
        if (($actualReceipt[$field] ?? null) !== ($expectedReceipt[$field] ?? null)) return false;
    }
    foreach (['receipt_id','operation_id'] as $field) {
        if (!is_string($actualReceipt[$field] ?? null)
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD',$actualReceipt[$field]) !== 1) return false;
    }
    foreach (['recipient','claimed_sender'] as $field) {
        $actualActor = $actualSource[$field] ?? null;
        $expectedActor = $expectedSource[$field] ?? null;
        if (!is_array($actualActor) || !is_array($expectedActor)) return false;
        $actualActorKeys = array_keys($actualActor);
        $expectedActorKeys = array_keys($expectedActor);
        sort($actualActorKeys);
        sort($expectedActorKeys);
        if ($actualActorKeys !== ['id','kind','name'] || $expectedActorKeys !== ['id','kind','name']) return false;
    }
    try {
        if (realEvalActorKey($actualSource['recipient']) !== realEvalActorKey($expectedSelf)
            || realEvalActorName($actualSource['recipient']) !== realEvalActorName($expectedSelf)
            || realEvalActorKey($expectedSource['recipient']) !== realEvalActorKey($expectedSelf)
            || realEvalActorName($expectedSource['recipient']) !== realEvalActorName($expectedSelf)
            || realEvalActorKey($actualSource['claimed_sender']) !== realEvalActorKey($expectedSender)
            || realEvalActorName($actualSource['claimed_sender']) !== realEvalActorName($expectedSender)
            || realEvalActorKey($expectedSource['claimed_sender']) !== realEvalActorKey($expectedSender)
            || realEvalActorName($expectedSource['claimed_sender']) !== realEvalActorName($expectedSender)) return false;
    } catch (Throwable) {
        return false;
    }
    $selfKeys = array_keys($actualSelf);
    sort($selfKeys);
    if ($selfKeys !== ['key','name','personality','relationships']
        || ($actualSelf['key'] ?? null) !== realEvalActorKey($expectedSelf)
        || ($actualSelf['name'] ?? null) !== realEvalActorName($expectedSelf)
        || ($actualSelf['personality'] ?? null) !== ($expectedSelf['personality'] ?? null)) return false;
    if (!is_array($expectedContacts) || !array_is_list($expectedContacts) || !is_array($actualContacts) || !array_is_list($actualContacts)) return false;
    $expectedContactMap = [];
    foreach ($expectedContacts as $contact) {
        if (!is_array($contact)) return false;
        $keys = array_keys($contact);
        sort($keys);
        if ($keys !== ['key','name']) return false;
        try {
            $key = realEvalActorKey($contact);
            $name = realEvalActorName($contact);
        } catch (Throwable) {
            return false;
        }
        if (array_key_exists($key, $expectedContactMap)) return false;
        $expectedContactMap[$key] = $name;
    }
    $actualContactMap = [];
    foreach ($actualContacts as $contact) {
        if (!is_array($contact)) return false;
        $keys = array_keys($contact);
        sort($keys);
        if ($keys !== ['key','name']) return false;
        try {
            $key = realEvalActorKey($contact);
            $name = realEvalActorName($contact);
        } catch (Throwable) {
            return false;
        }
        if (array_key_exists($key, $actualContactMap)) return false;
        $actualContactMap[$key] = $name;
    }
    ksort($expectedContactMap);
    ksort($actualContactMap);
    if ($actualContactMap !== $expectedContactMap) return false;
    $expectedSelfRelationships = $expectedSelf['relationships'] ?? [];
    $actualSelfRelationships = $actualSelf['relationships'] ?? null;
    if (!is_array($expectedSelfRelationships) || !array_is_list($expectedSelfRelationships)
        || !is_array($actualSelfRelationships) || !array_is_list($actualSelfRelationships)) return false;
    $expectedRelationshipMap = [];
    foreach ($expectedSelfRelationships as $relationship) {
        if (!is_array($relationship) || !is_string($relationship['key'] ?? null)
            || (!is_int($relationship['affinity'] ?? null) && !is_float($relationship['affinity'] ?? null))) return false;
        try {
            $key = realEvalActorKey($relationship['key']);
        } catch (Throwable) {
            return false;
        }
        $affinity = $relationship['affinity'];
        if (isset($expectedRelationshipMap[$key]) || !array_key_exists($key, $expectedContactMap)
            || (is_float($affinity) && !is_finite($affinity)) || $affinity < -100 || $affinity > 100) return false;
        $expectedRelationshipMap[$key] = $affinity;
    }
    $actualRelationshipMap = [];
    foreach ($actualSelfRelationships as $relationship) {
        if (!is_array($relationship) || !is_string($relationship['key'] ?? null)
            || (!is_int($relationship['affinity'] ?? null) && !is_float($relationship['affinity'] ?? null))) return false;
        try {
            $key = realEvalActorKey($relationship['key']);
        } catch (Throwable) {
            return false;
        }
        $affinity = $relationship['affinity'];
        if (isset($actualRelationshipMap[$key]) || !array_key_exists($key, $actualContactMap)
            || (is_float($affinity) && !is_finite($affinity)) || $affinity < -100 || $affinity > 100) return false;
        $actualRelationshipMap[$key] = $affinity;
    }
    ksort($expectedRelationshipMap);
    ksort($actualRelationshipMap);
    return $actualRelationshipMap === $expectedRelationshipMap;
}

function realEvalCurrentStatus(CorrespondenceAutonomyPg $autonomy): ?array
{
    $rows = $autonomy->status();
    return $rows[0] ?? null;
}

function realEvalDeliveredReceipt(array $operation): array
{
    $receipt = array_intersect_key($operation, array_flip(['protocol_version','document_id','operation_id','receipt_id','playthrough_id','scope_token','nonce']));
    return $receipt + ['status'=>'delivered','reason'=>'physical_only','note_form_id'=>12345,'attachments'=>[]];
}

function realEvalRunCase(
    int $caseNumber,
    string $label,
    array $publicInput,
    PgSql\Connection $connection,
    CorrespondencePgStore $store,
    CorrespondenceService $service,
    CorrespondenceAutonomyPg $autonomy,
    string $presence,
    callable $realModelCallback,
    string $runDirectory,
    int &$ts,
    int &$gamets
): array {
    $started = hrtime(true);
    $messages = null;
    $rawResponse = null;
    $normalized = null;
    $modelError = null;
    $providerCalls = 0;
    $providerElapsed = 0;
    $sourceDocumentId = null;
    $sourceEventId = null;
    $tick = ['status'=>'failed','reason'=>'case_setup_failed','model_calls'=>0,'input_chars'=>0,'output_chars'=>0,'elapsed_ms'=>0];
    $status = null;
    $recipientContext = null;
    $childEvidence = null;
    $publicProjectionMatches = false;
    $source = null;
    $contacts = null;
    $callback = static function(array $prompt) use (
        &$messages, &$rawResponse, &$normalized, &$modelError, &$providerCalls, &$providerElapsed,
        &$publicProjectionMatches, &$source, $realModelCallback, $publicInput, $label, $runDirectory
    ): string {
        if ($messages !== null || $providerCalls > 0) throw new RuntimeException('model_retry_forbidden');
        $messages = $prompt;
        realEvalWriteJson($runDirectory.'/'.$label.'.messages.json', $prompt);
        if (!is_array($source) || !realEvalCheckPublicProjection($prompt, $publicInput, $source)) {
            $modelError = 'public_projection_mismatch';
            throw new RuntimeException('public_projection_mismatch');
        }
        $publicProjectionMatches = true;
        $start = hrtime(true);
        try {
            $providerCalls++;
            $response = $realModelCallback($prompt);
            $providerElapsed = max(0, (int)((hrtime(true)-$start)/1000000));
            if (is_string($response)) {
                $rawResponse = $response;
                realEvalWrite($runDirectory.'/'.$label.'.raw-response.txt', $response);
                $user = is_array($prompt[1] ?? null) && is_string($prompt[1]['content'] ?? null)
                    ? json_decode($prompt[1]['content'], true, 64, JSON_THROW_ON_ERROR) : null;
                if (is_array($user) && is_array($user['source'] ?? null) && is_array($user['contacts'] ?? null)) {
                    try {
                        $normalized = correspondenceAutonomyDecision($response, $user['source'], $user['contacts']);
                    } catch (Throwable) {
                        $normalized = null;
                    }
                }
            }
            return is_string($response) ? $response : '';
        } catch (Throwable) {
            $providerElapsed = max(0, (int)((hrtime(true)-$start)/1000000));
            $modelError = 'model_error';
            throw new RuntimeException('model_error');
        }
    };

    try {
        $self = $publicInput['self'] ?? null;
        $sourceSenderInput = $publicInput['claimed_sender'] ?? null;
        $publicContacts = $publicInput['contacts'] ?? null;
        realEvalCheck(is_array($self) && is_array($sourceSenderInput) && is_array($publicContacts) && array_is_list($publicContacts), 'invalid_public_input');
        $selfKey = realEvalActorKey($self);
        $selfName = realEvalActorName($self);
        $selfPersonality = $self['personality'] ?? '';
        realEvalCheck($selfKey !== 'player' && is_string($selfPersonality)
            && strlen($selfPersonality) <= 8000 && preg_match('//u',$selfPersonality) === 1
            && (preg_match_all('/./us',$selfPersonality) ?: 0) <= 2000, 'invalid_public_self');
        $sourceRecipient = realEvalActorRecord($self);
        $sourceSender = realEvalActorRecord($sourceSenderInput);
        realEvalCheck($sourceRecipient['kind'] === 'npc' && $sourceRecipient['id'] === (int)substr($selfKey,4)
            && $sourceRecipient['name'] === $selfName && is_string($publicInput['body'] ?? null), 'public_source_self_mismatch');
        $body = $publicInput['body'];
        $npcNames = ['npc:900001'=>'Camilla','npc:900002'=>'Sigrid','npc:900003'=>'Delphine'];
        realEvalCheck(isset($npcNames[$selfKey]) && $npcNames[$selfKey] === $selfName, 'unsupported_public_self');
        $nameByKey = ['player'=>'Dragonborn'] + $npcNames;
        $contactProjection = [];
        foreach ($publicContacts as $contact) {
            $key = realEvalActorKey($contact);
            $name = realEvalActorName($contact);
            realEvalCheck(isset($nameByKey[$key]) && $nameByKey[$key] === $name && $key !== $selfKey, 'unsupported_public_contact');
            $contactProjection[] = ['key'=>$key,'name'=>$name];
        }
        realEvalCheck(in_array(['key'=>$sourceSender['kind']==='player'?'player':'npc:'.$sourceSender['id'],'name'=>$sourceSender['name']], $contactProjection, true), 'claimed_sender_missing_from_public_contacts');

        $profileId = 810000000 + $caseNumber;
        realEvalQuery($connection, 'UPDATE chim_meta.playthrough_profiles SET is_active=FALSE WHERE is_active IS TRUE');
        realEvalQuery($connection, 'INSERT INTO chim_meta.playthrough_profiles(id,is_active) VALUES($1,TRUE)', [(string)$profileId]);

        $relationships = [];
        foreach (($self['relationships'] ?? []) as $relationship) {
            if (!is_array($relationship) || !is_string($relationship['key'] ?? null)
                || (!is_int($relationship['affinity'] ?? null) && !is_float($relationship['affinity'] ?? null))) continue;
            $key = realEvalActorKey($relationship['key']);
            if (!isset($nameByKey[$key]) || $key === $selfKey) continue;
            $affinity = $relationship['affinity'];
            if ((is_float($affinity) && !is_finite($affinity)) || $affinity < -100 || $affinity > 100) continue;
            $relationships[$nameByKey[$key]] = ['aff'=>$affinity];
        }
        $extended = ['background_life_enabled'=>true,'background_life_letters'=>true,'relationships'=>$relationships];
        $metadata = ['stats'=>['is_dead'=>false],'last_coords'=>[0,0,0,'Riverwood']];
        $refs = ['npc:900001'=>'00013488','npc:900002'=>'00013BBD','npc:900003'=>'000A2C94'];
        foreach ($npcNames as $key=>$name) {
            $personality = $key === $selfKey ? $selfPersonality : '';
            $actorExtended = $key === $selfKey ? $extended : ['background_life_enabled'=>true,'background_life_letters'=>true,'relationships'=>new stdClass()];
            realEvalQuery($connection, 'INSERT INTO public.core_npc_master(id,npc_name,refid,profile_id,personality,extended_data,metadata) VALUES($1,$2,$3,$4,$5,$6::jsonb,$7::jsonb) ON CONFLICT(id) DO UPDATE SET npc_name=EXCLUDED.npc_name,refid=EXCLUDED.refid,profile_id=EXCLUDED.profile_id,personality=EXCLUDED.personality,extended_data=EXCLUDED.extended_data,metadata=EXCLUDED.metadata', [
                (string)substr($key,4),$name,$refs[$key],(string)$profileId,$personality,
                json_encode($actorExtended, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                json_encode($metadata, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ]);
        }
        $gamets = max($gamets, 1000000000 + $caseNumber * 10000000);
        $ts = max($ts, 10000 + $caseNumber * 1000);
        $bindClock = static function(int $targetGamets) use (&$ts,&$gamets,$store): void {
            $gamets = max($gamets, $targetGamets);
            $ts++;
            $store->bindRequestClock(['inputtext',(string)$ts,(string)$gamets,'synthetic correspondence evaluation']);
        };
        $autonomy->setEnabled(true);
        $bindClock($gamets);
        $store->observeManaged($presence);
        $bindClock($gamets);
        $sourceDocument = $service->compose([
            'request_id'=>correspondenceUuid(),'author'=>'player','payer'=>'player',
            'recipient'=>'npc:'.$sourceRecipient['id'],
            'claimed_sender'=>$sourceSender['kind']==='player'?'player':'npc:'.$sourceSender['id'],
            'body'=>$body,'attachments'=>[],
        ]);
        $sourceDocumentId = $sourceDocument['document_id'];
        $bindClock($gamets);
        $incomingDispatch = $service->dispatch($sourceDocumentId);
        realEvalCheck(($incomingDispatch['state'] ?? null) === 'dispatched' && is_string($incomingDispatch['operation_id'] ?? null), 'incoming_dispatch_failed');
        $incomingOperation = $store->loadOperation($incomingDispatch['operation_id']);
        realEvalCheck(is_array($incomingOperation), 'incoming_operation_missing');
        $bindClock($gamets);
        $service->receive(realEvalDeliveredReceipt($incomingOperation));
        $bindClock($gamets);
        $prompt = $service->prompt($selfName);
        realEvalCheck($prompt !== '' && count($service->sourceReferences) === 1, 'normal_recipient_context_missing');
        $sourceEventId = (int)$service->sourceReferences[0];
        $exposed = $service->readExposedSource($sourceEventId, $sourceRecipient['id']);
        $source = $exposed['source'];
        realEvalCheck($source['body'] === $body && $source['recipient']['name'] === $selfName, 'normal_source_readback_mismatch');
        $contacts = $contactProjection;
        $sourceGamets = (int)$exposed['event']['gamets'];
        $bindClock($sourceGamets + correspondenceAutonomyGamets(2));
        $store->observeManaged($presence);
        $bindClock($gamets);

        $tick = $autonomy->tick($callback);
        $status = realEvalCurrentStatus($autonomy);
        if (($tick['status'] ?? null) === 'queued' && is_array($status) && is_string($status['child_document_id'] ?? null)) {
            $childId = $status['child_document_id'];
            $child = $store->loadDocument($childId);
            realEvalCheck(is_array($child), 'queued_child_missing');
            $selfActorKey = $sourceRecipient['kind']==='player'?'player':'npc:'.$sourceRecipient['id'];
            $childIdentityValid = ($child['author']['kind'] ?? null)==='npc' && ($child['author']['id'] ?? null)===$sourceRecipient['id']
                && ($child['payer']['kind'] ?? null)==='npc' && ($child['payer']['id'] ?? null)===$sourceRecipient['id']
                && ($child['claimed_sender']['kind'] ?? null)==='npc' && ($child['claimed_sender']['id'] ?? null)===$sourceRecipient['id']
                && ($child['attachments'] ?? null) === [];
            $due = (int)($status['due_gamets'] ?? 0);
            realEvalCheck($due > 0, 'queued_child_due_missing');
            $bindClock($due);
            $store->observeManaged($presence);
            $bindClock($gamets);
            $dueCallbackCalls = 0;
            $dueTick = $autonomy->tick(static function(array $unused) use (&$dueCallbackCalls): string {
                $dueCallbackCalls++;
                throw new RuntimeException('due_dispatch_must_not_call_model');
            });
            $dueStatus = realEvalCurrentStatus($autonomy);
            $child = $store->loadDocument($childId) ?? $child;
            $operation = is_string($child['operation_id'] ?? null) ? $store->loadOperation($child['operation_id']) : null;
            $generatedSenderSelf = false;
            try {
                $generatedSenderSelf = $childIdentityValid
                    && realEvalActorKey($child['author']) === $selfActorKey
                    && realEvalActorKey($child['payer']) === $selfActorKey
                    && realEvalActorKey($child['claimed_sender']) === $selfActorKey;
            } catch (Throwable) {
                $generatedSenderSelf = false;
            }
            $childEvidence = [
                'child_document_id'=>$childId,
                'action'=>$status['action'] ?? null,
                'generated_sender_self'=>$generatedSenderSelf,
                'no_attachments'=>($child['attachments'] ?? null) === [],
                'child_body_sha256'=>hash('sha256',(string)($child['body'] ?? '')),
                'due_tick_status'=>$dueTick['status'] ?? 'unknown',
                'due_tick_model_calls'=>$dueTick['model_calls'] ?? 0,
                'due_callback_actual_calls'=>$dueCallbackCalls,
                'decision_status_after_due'=>$dueStatus['status'] ?? null,
                'synthetic_receipt'=>false,
                'recipient_path'=>null,
                'recipient_context_exposed'=>false,
                'recipient_readback_body_exact'=>false,
                'full_source_copy_bytes'=>null,
            ];
            if (($dueTick['status'] ?? null) === 'dispatched' && $dueCallbackCalls === 0 && is_array($operation)) {
                $service->receive(realEvalDeliveredReceipt($operation));
                $bindClock($gamets);
                $target = $child['recipient'];
                if (($target['kind'] ?? null) === 'npc') {
                    $recipientName = (string)$target['name'];
                    $recipientId = (int)$target['id'];
                    $recipientPrompt = $service->prompt($recipientName);
                    $recipientPathOk = $recipientPrompt !== '' && count($service->sourceReferences) === 1;
                    if ($recipientPathOk) {
                        $recipientEventId = (int)$service->sourceReferences[0];
                        $recipientExposed = $service->readExposedSource($recipientEventId,$recipientId);
                        $recipientContext = $recipientPrompt;
                        realEvalWrite($runDirectory.'/'.$label.'.recipient-context.txt',$recipientPrompt);
                        $childEvidence['recipient_path'] = 'npc_context';
                        $childEvidence['recipient_context_exposed'] = true;
                        $childEvidence['recipient_readback_body_exact'] = ($recipientExposed['source']['body'] ?? null) === ($child['body'] ?? null);
                    }
                } elseif (($target['kind'] ?? null) === 'player') {
                    $playerReadback = $service->readPlayer($childId);
                    realEvalWriteJson($runDirectory.'/'.$label.'.recipient-readback.json',$playerReadback);
                    $childEvidence['recipient_path'] = 'player_readback';
                    $childEvidence['recipient_readback_body_exact'] = ($playerReadback['body'] ?? null) === ($child['body'] ?? null);
                }
                $childEvidence['synthetic_receipt'] = true;
                realEvalWrite($runDirectory.'/'.$label.'.child-body.txt',(string)$child['body']);
                if (in_array($status['action'] ?? null, ['forward','mediate'], true)) {
                    $copy = "BEGIN ENCLOSED ORIGINAL BODY\n".$body."\nEND ENCLOSED ORIGINAL BODY";
                    $childEvidence['full_source_copy_bytes'] = str_contains((string)$child['body'], $copy)
                        && hash_equals(hash('sha256',$body), hash('sha256',substr((string)$child['body'], strpos((string)$child['body'],$copy)+strlen("BEGIN ENCLOSED ORIGINAL BODY\n"), strlen($body))));
                }
            }
        }
    } catch (Throwable) {
        $modelError ??= 'case_setup_or_delivery_failed';
    }

    $rawHash = is_string($rawResponse) ? hash('sha256',$rawResponse) : null;
    $messagesHash = is_array($messages)
        ? hash('sha256',json_encode($messages,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)) : null;
    $status = $status ?? realEvalCurrentStatus($autonomy);
    $result = [
        'case'=>$label,
        'public_input_sha256'=>hash('sha256',json_encode($publicInput,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),
        'source_document_id'=>$sourceDocumentId,
        'source_event_id'=>$sourceEventId,
        'source_body_sha256'=>is_array($source)?hash('sha256',(string)$source['body']):null,
        'public_projection_matches_model_input'=>$publicProjectionMatches,
        'raw_response_present'=>is_string($rawResponse),
        'raw_response_sha256'=>$rawHash,
        'normalized_decision'=>$normalized,
        'model_callback_invocations'=>$providerCalls,
        'provider_elapsed_ms'=>$providerElapsed,
        'messages_sha256'=>$messagesHash,
        'tick'=>$tick,
        'decision_status'=>$status['status'] ?? null,
        'decision_action'=>$status['action'] ?? null,
        'decision_reason'=>$status['reason'] ?? ($tick['reason'] ?? null),
        'child'=>$childEvidence,
        'fixed_error'=>$modelError,
        'case_elapsed_ms'=>max(0,(int)((hrtime(true)-$started)/1000000)),
    ];
    if (is_array($messages) && !is_file($runDirectory.'/'.$label.'.messages.json')) {
        realEvalWriteJson($runDirectory.'/'.$label.'.messages.json',$messages);
    }
    realEvalWriteJson($runDirectory.'/'.$label.'.result.json',$result);
    return $result;
}

ini_set('display_errors','0');
ini_set('log_errors','0');
set_exception_handler(static function(Throwable $ignored): void {
    fwrite(STDERR,"{\"status\":\"failed\",\"reason\":\"harness_error\"}\n");
    exit(1);
});

realEvalCheck(PHP_SAPI === 'cli' && extension_loaded('pgsql'), 'native_cli_pgsql_required');
realEvalCheck(getenv('WSL_DISTRO_NAME') === 'DwemerAI4Skyrim3-test', 'clone_only');
foreach (getenv() as $name => $_value) {
    realEvalCheck(!str_starts_with(strtoupper((string)$name), 'PG'), 'postgres_environment_forbidden');
}
$runLabel = getenv('REAL_EVAL_RUN');
realEvalCheck(is_string($runLabel) && preg_match('/\A[a-z0-9-]{1,40}\z/D',$runLabel) === 1, 'invalid_run_label');
$actionProbeModeValue = getenv('REAL_EVAL_ACTION_PROBES');
realEvalCheck($actionProbeModeValue === false || $actionProbeModeValue === '' || $actionProbeModeValue === '1', 'invalid_action_probe_mode');
$actionProbeMode = $actionProbeModeValue === '1';
$artifactRoot = '/mnt/k/ActorwrightExchange/artifacts/chim-correspondence-model-eval-20261019';
$inputFilename = $actionProbeMode ? 'public-action-probes-v1.json' : 'public-autonomy-inputs.json';
$expectedProjectionHash = $actionProbeMode
    ? '8ac9e3285d04ec1807ff39767ce417d24340f150669b2c68a9e7024952b09074'
    : 'ed15695e0cf7ae5aec7d8c080e6a9be3e07e45514ab8f87cbbba29ad9eaf2af8';
$expectedSourceHash = $actionProbeMode
    ? '18b45acaa78213115d584afb244efbd39565b23ae8ab417c26e46adce7e86680'
    : '70a9b3bbd53d512a66e98e431751631a1e1b22a05220aaefbee203d339fa8b29';
$expectedCaseCount = $actionProbeMode ? 3 : 12;
$corpus = $actionProbeMode ? 'supplemental_action_probes' : 'original_heldout';
$maximumLimit = $expectedCaseCount;
$defaultLimit = $expectedCaseCount;
$limitValue = getenv('REAL_EVAL_LIMIT');
$limit = $defaultLimit;
if ($limitValue !== false && $limitValue !== '') {
    realEvalCheck(preg_match('/\A(?:[1-9]|1[0-2])\z/D',(string)$limitValue) === 1
        && (int)$limitValue <= $maximumLimit, 'invalid_case_limit');
    $limit = (int)$limitValue;
}

$databaseName = 'chim_correspondence_model_eval_it';
$connection = @pg_connect('host=/var/run/postgresql dbname='.$databaseName.' user=postgres connect_timeout=3 application_name=correspondence_real_model_eval', PGSQL_CONNECT_FORCE_NEW);
realEvalCheck($connection instanceof PgSql\Connection, 'owned_peer_database_required');
$identity = realEvalQuery($connection, "SELECT current_database() db,current_user role,current_setting('listen_addresses') listeners,(inet_server_addr() IS NULL)::int socket_only")[0] ?? [];
realEvalCheck($identity === ['db'=>$databaseName,'role'=>'postgres','listeners'=>'','socket_only'=>'1'], 'owned_socket_database_only');
$tables = realEvalQuery($connection, "SELECT count(*)::int amount FROM information_schema.tables WHERE table_schema IN ('public','plugins','chim_meta')")[0] ?? [];
realEvalCheck(($tables['amount'] ?? null) === '0', 'blank_owned_database_required');

$inputPath = $artifactRoot.'/'.$inputFilename;
$bootstrapPath = $artifactRoot.'/provider-bootstrap.php';
$runDirectory = $artifactRoot.'/run-'.$runLabel;
realEvalCheck(is_file($inputPath) && hash_file('sha256',$inputPath) === $expectedProjectionHash, 'public_projection_hash_mismatch');
realEvalCheck(is_file($bootstrapPath), 'provider_bootstrap_unavailable');
realEvalCheck(!file_exists($runDirectory) && !is_link($runDirectory), 'run_label_already_used');
$publicFile = @file_get_contents($inputPath);
realEvalCheck(is_string($publicFile), 'public_projection_unavailable');
$publicProjection = json_decode($publicFile, true, 128, JSON_THROW_ON_ERROR);
realEvalCheck(is_array($publicProjection) && array_keys($publicProjection) === ['source_sha256','projection','cases']
    && ($publicProjection['source_sha256'] ?? null) === $expectedSourceHash
    && is_string($publicProjection['projection'] ?? null) && is_array($publicProjection['cases'] ?? null)
    && count($publicProjection['cases']) === $expectedCaseCount, 'invalid_public_projection');
$publicCases = [];
foreach ($publicProjection['cases'] as $index=>$case) {
    $label = sprintf('R%02d',$index+1);
    realEvalCheck(is_array($case) && array_keys($case) === ['ordinal','public_input']
        && ($case['ordinal'] ?? null) === $label && is_array($case['public_input'] ?? null), 'invalid_public_projection_case');
    $publicCases[] = ['label'=>$label,'public_input'=>$case['public_input']];
}
unset($publicFile,$publicProjection);
realEvalCheck($limit <= count($publicCases), 'public_case_count_mismatch');
$publicCases = array_slice($publicCases,0,$limit);

$project = dirname(__DIR__,2);
require_once $project.'/server/lib/store.php';
require_once $project.'/server/lib/boundaries.php';
require_once $project.'/server/lib/autonomy_policy.php';
require_once $project.'/server/lib/autonomy_store.php';

umask(0077);
realEvalCheck(@mkdir($runDirectory,0700), 'run_output_create_failed');
try {
    require_once $bootstrapPath;
    realEvalCheck(function_exists('modelEvalCreateCallback'), 'provider_bootstrap_unavailable');
    $GLOBALS['MODEL_EVAL_SYNTHETIC_CONNECTION'] = $connection;
    $realModelCallback = modelEvalCreateCallback();
} catch (Throwable) {
    fwrite(STDERR,"{\"status\":\"failed\",\"reason\":\"provider_bootstrap_failed\"}\n");
    exit(1);
}
realEvalCheck(is_callable($realModelCallback), 'provider_callback_unavailable');

$ddl = <<<'SQL'
CREATE SCHEMA chim_meta;
CREATE TABLE chim_meta.playthrough_profiles(id bigint PRIMARY KEY,is_active boolean NOT NULL DEFAULT FALSE);
CREATE TABLE public.eventlog(rowid bigserial PRIMARY KEY,ts bigint NOT NULL,gamets numeric NOT NULL,type text NOT NULL,data text NOT NULL,sess text NOT NULL,localts bigint NOT NULL,people text NOT NULL,location text NOT NULL,party text NOT NULL);
CREATE TABLE public.core_npc_master(id bigint PRIMARY KEY,npc_name text NOT NULL,refid text NOT NULL,profile_id bigint,personality text NOT NULL DEFAULT '',extended_data jsonb NOT NULL DEFAULT '{}'::jsonb,metadata jsonb NOT NULL DEFAULT '{}'::jsonb);
CREATE TABLE public.core_player(id text PRIMARY KEY,value text NOT NULL);
CREATE TABLE public.game_plugins(plugin_name text NOT NULL,formid_prefix text NOT NULL);
CREATE TABLE public.books(id bigserial PRIMARY KEY,ts bigint NOT NULL,gamets numeric NOT NULL,content text NOT NULL,sess text NOT NULL,localts bigint NOT NULL,title text NOT NULL);
CREATE TABLE public.responselog(id bigserial PRIMARY KEY,localts bigint NOT NULL,sent integer NOT NULL,actor text NOT NULL,text text NOT NULL,action text NOT NULL,tag text NOT NULL);
INSERT INTO public.core_player(id,value) VALUES('player_name','Dragonborn');
INSERT INTO public.game_plugins(plugin_name,formid_prefix) VALUES('Skyrim.esm','00');
INSERT INTO public.core_npc_master(id,npc_name,refid,profile_id,personality,extended_data,metadata) VALUES
 (900001,'Camilla','00013488',1,'','{"background_life_enabled":true,"background_life_letters":true,"relationships":{}}','{"stats":{"is_dead":false},"last_coords":[0,0,0,"Riverwood"]}'),
 (900002,'Sigrid','00013BBD',1,'','{"background_life_enabled":true,"background_life_letters":true,"relationships":{}}','{"stats":{"is_dead":false},"last_coords":[0,0,0,"Riverwood"]}'),
 (900003,'Delphine','000A2C94',1,'','{"background_life_enabled":true,"background_life_letters":true,"relationships":{}}','{"stats":{"is_dead":false},"last_coords":[0,0,0,"Riverwood"]}');
INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(1,1,'inputtext','synthetic model evaluation origin','',1,'','','');
SQL;
realEvalQuery($connection,$ddl);
$store = new CorrespondencePgStore($connection, static fn($store) => $store->observedExecutor(), static fn(string $title,string $body): bool => true);
$store->ensureSchema();
$service = new CorrespondenceService($store);
$autonomy = new CorrespondenceAutonomyPg($store,$service);
$autonomy->ensureSchema();
$presence = base64_encode(correspondenceJson([
    'source'=>'plugin_player_routing_v2',
    'present_actors'=>[['form_id'=>hexdec('000A2C94'),'name'=>'Delphine','distance'=>10,'managed'=>true,'creature'=>false]],
]));
$codeFiles = ['server/lib/runtime.php','server/lib/store.php','server/lib/boundaries.php','server/lib/autonomy_policy.php','server/lib/autonomy_store.php','server/migrations/001_initial.sql','server/migrations/002_autonomy.sql'];
$codeHashes = [];
foreach ($codeFiles as $relative) $codeHashes[$relative] = is_file($project.'/'.$relative) ? hash_file('sha256',$project.'/'.$relative) : null;
$manifest = [
    'run'=>$runLabel,
    'corpus'=>$corpus,
    'case_limit'=>$limit,
    'public_projection_sha256'=>hash_file('sha256',$inputPath),
    'frozen_source_fixture_sha256'=>$expectedSourceHash,
    'provider_bootstrap_sha256'=>hash_file('sha256',$bootstrapPath),
    'production_source_sha256'=>$codeHashes,
    'database'=>$databaseName,
    'php_version'=>PHP_VERSION,
    'result_boundary'=>'public inputs and synthetic evaluation outcomes only',
    'tokens'=>'not available from the connector callback',
];
realEvalWriteJson($runDirectory.'/run-manifest.json',$manifest);

$ts = 10000;
$gamets = 1000000000;
$results = [];
foreach ($publicCases as $index=>$publicCase) {
    $results[] = realEvalRunCase($index+1,$publicCase['label'],$publicCase['public_input'],$connection,$store,$service,$autonomy,$presence,$realModelCallback,$runDirectory,$ts,$gamets);
    echo json_encode(['case'=>$publicCase['label'],'status'=>$results[array_key_last($results)]['tick']['status'] ?? 'unknown','model_calls'=>$results[array_key_last($results)]['model_callback_invocations'] ?? 0])."\n";
}
$summary = [
    'run'=>$runLabel,
    'corpus'=>$corpus,
    'cases'=>count($results),
    'model_callback_invocations'=>array_sum(array_column($results,'model_callback_invocations')),
    'statuses'=>array_count_values(array_map(static fn($r)=>(string)($r['tick']['status'] ?? 'unknown'),$results)),
    'private_results'=>true,
];
realEvalWriteJson($runDirectory.'/summary.json',$summary);
echo json_encode(['run'=>$runLabel,'cases'=>count($results),'model_calls'=>$summary['model_callback_invocations'],'output_directory'=>$runDirectory])."\n";
