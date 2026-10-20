<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/server/lib/view.php';

function autonomyUiCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function autonomyUiRejected(callable $call, string $message): void
{
    try { $call(); } catch (DomainException|InvalidArgumentException) { return; }
    throw new RuntimeException($message);
}

$csrf = str_repeat('a', 64);
$on = correspondencePost(['csrf' => $csrf, 'action' => 'autonomy', 'enabled' => '1'], $csrf, []);
$off = correspondencePost(['csrf' => $csrf, 'action' => 'autonomy', 'enabled' => '0'], $csrf, []);
autonomyUiCheck($on === ['action' => 'autonomy', 'enabled' => true] && $off === ['action' => 'autonomy', 'enabled' => false], 'operator pause must use the existing CSRF parser and a strict enabled flag');
autonomyUiRejected(fn() => correspondencePost(['csrf' => $csrf, 'action' => 'autonomy', 'enabled' => 'true'], $csrf, []), 'noncanonical autonomy flag must reject');
autonomyUiRejected(fn() => correspondencePost(['csrf' => $csrf, 'action' => 'autonomy', 'enabled' => '1', 'tick' => '1'], $csrf, []), 'browser must not request an autonomous tick');
autonomyUiRejected(fn() => correspondencePost(['csrf' => 'bad', 'action' => 'autonomy', 'enabled' => '1'], $csrf, []), 'autonomy setting must retain CSRF protection');

$sourceId = '11111111-1111-4111-8111-111111111111';
$childId = '22222222-2222-4222-8222-222222222222';
$manualId = '33333333-3333-4333-8333-333333333333';
$letters = [[
    'document_id' => $sourceId,
    'state' => 'exposed',
    'recipient' => ['kind' => 'npc', 'name' => 'Camilla'],
    'claimed_sender' => ['kind' => 'npc', 'name' => 'Jarl Balgruuf'],
    'body' => '<script>letter text remains a claim</script>',
    'attachments' => [],
    'source_event_id' => 123,
    'author' => ['name' => 'private author'],
    'payer' => ['name' => 'private payer'],
]];
$letters[] = [
    'document_id' => $childId,
    'state' => 'queued',
    'recipient' => ['kind' => 'player', 'name' => 'Dragonborn'],
    'claimed_sender' => ['kind' => 'npc', 'name' => 'Camilla'],
    'body' => 'Automated child letter awaiting delivery.',
    'attachments' => [],
];
$letters[] = [
    'document_id' => $manualId,
    'state' => 'queued',
    'recipient' => ['kind' => 'player', 'name' => 'Dragonborn'],
    'claimed_sender' => ['kind' => 'npc', 'name' => 'Jarl Balgruuf'],
    'body' => 'Ordinary queued letter.',
    'attachments' => [],
];
autonomyUiCheck(function_exists('correspondenceOperatorRead'), 'RED: safe operator letter view helper missing');
$opened = correspondenceOperatorRead($letters, $sourceId);
autonomyUiCheck(array_keys($opened) === ['document_id', 'state', 'recipient', 'claimed_sender', 'body', 'attachments'], 'operator view must return only public letter fields');
autonomyUiCheck($opened['body'] === $letters[0]['body'] && $opened['state'] === 'exposed', 'operator view must show the exact stored content and state');
autonomyUiRejected(fn() => correspondenceOperatorRead($letters, '44444444-4444-4444-8444-444444444444'), 'unknown document cannot be opened');

$rows = [
    ['source_document_id' => $sourceId, 'child_document_id' => $childId, 'status' => 'queued', 'action' => 'forward', 'due_gamets' => 5000000, 'reason' => 'dispatch_pending', 'body' => '<script>private prompt</script>', 'author' => 'operator-only', 'payer' => 'secret'],
    ['source_document_id' => $childId, 'child_document_id' => null, 'status' => 'silent', 'action' => 'none', 'due_gamets' => null, 'reason' => 'no_response', 'body' => 'must not appear'],
    ['source_document_id' => '33333333-3333-4333-8333-333333333333', 'child_document_id' => null, 'status' => 'failed', 'action' => 'reply', 'due_gamets' => null, 'reason' => 'model_unavailable'],
];
$html = correspondencePage(
    [['key' => 'player', 'name' => 'Dragonborn'], ['key' => 'npc:900001', 'name' => 'Camilla']],
    [],
    $letters,
    $csrf,
    '',
    $opened,
    ['enabled' => false],
    $rows,
    [$childId]
);
autonomyUiCheck(str_contains($html, 'Autonomous correspondence') && str_contains($html, 'name="enabled"'), 'default-off setting control must be visible');
autonomyUiCheck(str_contains($html, 'name="action" value="autonomy"') && str_contains($html, 'Pause') && str_contains($html, 'Enable'), 'UI must offer pause and enable controls without a tick button');
autonomyUiCheck(str_contains($html, 'queued') && str_contains($html, 'silent') && str_contains($html, 'failed') && str_contains($html, 'forward'), 'UI must show the shallow autonomous outcomes');
autonomyUiCheck(str_contains($html, 'game-time') || str_contains($html, 'gametime') || str_contains($html, 'Game time'), 'UI must identify the coarse game-time delivery delay');
autonomyUiCheck(str_contains($html, '&lt;script&gt;letter text remains a claim&lt;/script&gt;') && !str_contains($html, '<script>'), 'operator letter content must remain HTML escaped');
autonomyUiCheck(!str_contains($html, 'private prompt') && !str_contains($html, 'operator-only') && !str_contains($html, 'secret') && !str_contains($html, 'must not appear'), 'status rendering must ignore bodies, authors, payers, and provider data');
autonomyUiCheck(str_contains($html, 'does not expose') || str_contains($html, 'does not create') || str_contains($html, 'without exposing'), 'browser reading must say it does not create NPC exposure');
autonomyUiCheck(str_contains($html, 'Awaiting autonomous scheduler'), 'automated queued children must be labelled as scheduler-owned');
$letterForm = static function (string $documentId) use ($html): string {
    $start = strpos($html, '<input type="hidden" name="document_id" value="' . $documentId . '">');
    if ($start === false) return '';
    $end = strpos($html, '</form>', $start);
    return $end === false ? '' : substr($html, $start, $end - $start);
};
autonomyUiCheck(!str_contains($letterForm($childId), 'Dispatch queued letter'), 'UI must not offer manual dispatch for a scheduler-owned child');
autonomyUiCheck(str_contains($letterForm($manualId), 'Dispatch queued letter'), 'ordinary queued correspondence must retain its manual dispatch action');
$forgedDispatch = correspondencePost(['csrf' => $csrf, 'action' => 'dispatch', 'document_id' => $childId], $csrf, []);
autonomyUiRejected(fn() => correspondenceAutonomyGuardManualDispatch($forgedDispatch, [$childId]), 'forged POST must not bypass scheduler ownership for an automated child');
$ordinaryDispatch = correspondencePost(['csrf' => $csrf, 'action' => 'dispatch', 'document_id' => $manualId], $csrf, []);
autonomyUiCheck(correspondenceAutonomyGuardManualDispatch($ordinaryDispatch, [$childId]) === $ordinaryDispatch, 'manual dispatch guard must leave ordinary letters unchanged');
autonomyUiRejected(fn() => correspondenceAutonomyGuardManualDispatch($forgedDispatch, ['invalid-id']), 'malformed ownership state must fail closed for dispatch');

echo "PASS server autonomy UI: CSRF-protected default-off pause, escaped operator read, scheduler-owned dispatch guard, shallow outcomes and no browser tick/exposure\n";
