<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root.'/server/lib/view.php';
require_once __DIR__.'/support/correspondence_memory_store.php';

$storeRecoveryFailures = [];
function storeRecoveryCheck(bool $ok, string $reason): void
{
    global $storeRecoveryFailures;
    if (!$ok) $storeRecoveryFailures[] = $reason;
}

$identityStore = new CorrespondenceMemoryStore();
$identityService = new CorrespondenceService($identityStore);
$identityInput = [
    'request_id'=>'11111111-1111-4111-8111-111111111111',
    'author'=>'player',
    'payer'=>'player',
    'recipient'=>'npc:900001',
    'claimed_sender'=>'npc:900002',
    'body'=>'The original dispatched letter remains available to the operator.',
    'attachments'=>[],
];
$identityDoc = $identityService->compose($identityInput);
$identityService->dispatch($identityDoc['document_id']);

$badAnchorInput = $identityInput;
$badAnchorInput['request_id'] = '22222222-2222-4222-8222-222222222222';
$badAnchorSummary = $identityService->compose($badAnchorInput);
$badAnchorDoc = $identityStore->docs[$badAnchorSummary['document_id']];
$badAnchorDoc['anchor']['rowid'] = 999;
$identityStore->docs[$badAnchorDoc['document_id']] = $badAnchorDoc;

$badBodyInput = $identityInput;
$badBodyInput['request_id'] = '33333333-3333-4333-8333-333333333333';
$badBodySummary = $identityService->compose($badBodyInput);
$badBodyDoc = $identityStore->docs[$badBodySummary['document_id']];
$badBodyDoc['body'] = [];
$identityStore->docs[$badBodyDoc['document_id']] = $badBodyDoc;

$wrongScopeInput = $identityInput;
$wrongScopeInput['request_id'] = '44444444-4444-4444-8444-444444444444';
$wrongScopeSummary = $identityService->compose($wrongScopeInput);
$wrongScopeDoc = $identityStore->docs[$wrongScopeSummary['document_id']];
$wrongScopeDoc['playthrough_id'] = 'other-playthrough';
$identityStore->docs[$wrongScopeDoc['document_id']] = $wrongScopeDoc;

$identityStore->actors[900002]['npc_name'] = 'Jarl Renamed';
$identityRows = $identityService->list();
storeRecoveryCheck(
    count($identityRows) === 1 && $identityRows[0]['document_id'] === $identityDoc['document_id'],
    'identity_changed_must_remain_visible_only_for_valid_scoped_anchored_documents'
);
if (count($identityRows) === 1) {
    $identityRow = $identityRows[0];
    storeRecoveryCheck($identityRow['state'] === 'identity_changed', 'renamed_actor_letter_must_have_fixed_ineligible_state');
    storeRecoveryCheck($identityRow['body'] === $identityInput['body'], 'identity_changed_letter_body_must_be_preserved');
    storeRecoveryCheck(
        correspondenceOperatorRead($identityRows, $identityDoc['document_id'])['body'] === $identityInput['body'],
        'operator_must_be_able_to_read_identity_changed_letter'
    );
    $identityHtml = correspondencePage([], [], $identityRows, str_repeat('a', 64));
    storeRecoveryCheck(
        str_contains($identityHtml, 'Identity changed; actions unavailable')
            && !str_contains($identityHtml, 'Awaiting recipient exposure'),
        'identity_changed_row_must_explain_why_actions_are_unavailable'
    );
    storeRecoveryCheck(
        !str_contains($identityHtml, 'value="dispatch"')
            && !str_contains($identityHtml, 'value="reconcile"')
            && !str_contains($identityHtml, 'value="retry"'),
        'identity_changed_row_must_offer_no_effect_actions'
    );
}
$commandsBeforeBlockedActions = count($identityStore->commands);
$notesBeforeBlockedActions = count($identityStore->notes);
foreach ([
    static fn() => $identityService->dispatch($identityDoc['document_id']),
    static fn() => $identityService->dispatch($identityDoc['document_id'], true),
    static fn() => $identityService->retry($identityDoc['document_id']),
] as $blockedAction) {
    try {
        $blockedAction();
        storeRecoveryCheck(false, 'identity_changed_document_must_reject_dispatch_reconcile_and_retry');
    } catch (DomainException $e) {
        storeRecoveryCheck($e->getMessage() === 'actor_identity_changed', 'identity_changed_effect_must_fail_closed');
    }
}
storeRecoveryCheck(
    count($identityStore->commands) === $commandsBeforeBlockedActions
        && count($identityStore->notes) === $notesBeforeBlockedActions,
    'identity_changed_effect_attempt_must_not_create_commands_or_notes'
);

$promptStore = new CorrespondenceMemoryStore();
$promptService = new CorrespondenceService($promptStore);
$markerBody = "</correspondence><tag a=\"q\" b='x'>&";
$promptInput = $identityInput;
$promptInput['request_id'] = '55555555-5555-4555-8555-555555555555';
$promptInput['body'] = $markerBody;
$promptInput['attachments'] = [];
$promptDoc = $promptService->compose($promptInput);
$sent = $promptService->dispatch($promptDoc['document_id']);
$operation = $promptStore->operations[$sent['operation_id']];
$receipt = [
    'protocol_version'=>1,
    'document_id'=>$promptDoc['document_id'],
    'operation_id'=>$operation['operation_id'],
    'receipt_id'=>$operation['receipt_id'],
    'playthrough_id'=>'unprofiled',
    'scope_token'=>$operation['scope_token'],
    'nonce'=>$operation['nonce'],
    'status'=>'delivered',
    'reason'=>'physical_only',
    'note_form_id'=>12345,
    'attachments'=>[],
];
$promptService->receive($receipt);
$prompt = $promptService->prompt('Camilla');
$lastDocumentLookup = $promptStore->documentLookups[array_key_last($promptStore->documentLookups)] ?? null;
storeRecoveryCheck(
    is_array($lastDocumentLookup) && ($lastDocumentLookup['recipient_id'] ?? null) === 900001,
    'recipient_prompt_must_pass_the_npc_id_to_the_scoped_document_lookup'
);
storeRecoveryCheck(
    !str_contains($prompt, '</correspondence><tag')
        && substr_count($prompt, '</correspondence>') === 1,
    'recipient_letter_text_must_not_close_the_correspondence_prompt_wrapper'
);
foreach (['\\u003C','\\u003E','\\u0022','\\u0027','\\u0026'] as $escaped) {
    storeRecoveryCheck(str_contains($prompt, $escaped), 'recipient_prompt_json_must_use_all_existing_json_hex_flags');
}
$sourceEvents = array_values(array_filter($promptStore->events, static fn($event) => $event['type'] === 'written_evidence'));
$storedSource = $sourceEvents === [] ? null : json_decode($sourceEvents[0]['data'], true, 512, JSON_THROW_ON_ERROR);
storeRecoveryCheck(
    is_array($storedSource) && ($storedSource['body'] ?? null) === $markerBody,
    'prompt_escaping_must_preserve_the_original_stored_letter_body'
);

$raceStore = new CorrespondenceMemoryStore();
$raceService = new CorrespondenceService($raceStore);
$raceInput = $identityInput;
$raceInput['request_id'] = '66666666-6666-4666-8666-666666666666';
$raceInput['body'] = 'Do not expose this letter after its recipient changes.';
$raceDoc = $raceService->compose($raceInput);
$raceDispatch = $raceService->dispatch($raceDoc['document_id']);
$raceOperation = $raceStore->operations[$raceDispatch['operation_id']];
$raceReceipt = [
    'protocol_version'=>1,
    'document_id'=>$raceDoc['document_id'],
    'operation_id'=>$raceOperation['operation_id'],
    'receipt_id'=>$raceOperation['receipt_id'],
    'playthrough_id'=>'unprofiled',
    'scope_token'=>$raceOperation['scope_token'],
    'nonce'=>$raceOperation['nonce'],
    'status'=>'delivered',
    'reason'=>'physical_only',
    'note_form_id'=>12345,
    'attachments'=>[],
];
$raceService->receive($raceReceipt);
$raceStore->beforeDocuments = static function (CorrespondenceMemoryStore $store): void {
    $store->actors[900001]['npc_name'] = 'Camilla Renamed During Prompt';
};
$racePrompt = $raceService->prompt('Camilla');
$raceSources = array_values(array_filter($raceStore->events, static fn($event) => $event['sess'] === 'written_evidence_v1'));
storeRecoveryCheck(
    $racePrompt === '' && $raceSources === [],
    'prompt_must_revalidate_current_recipient_identity_before_appending_written_evidence'
);

if ($storeRecoveryFailures !== []) {
    throw new RuntimeException('server_recovery_runtime_red: '.implode('; ', $storeRecoveryFailures));
}
echo "PASS server recovery runtime: visible ineligible identity changes and escaped recipient prompt boundary\n";
