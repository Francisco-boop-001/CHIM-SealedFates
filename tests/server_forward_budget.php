<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/server/lib/autonomy_policy.php';

$forwardBudgetFailures = [];

function forwardBudgetCheck(bool $condition, string $message): void
{
    global $forwardBudgetFailures;
    if (!$condition) $forwardBudgetFailures[] = $message;
}

function forwardBudgetReject(callable $call, string $message): void
{
    global $forwardBudgetFailures;
    try {
        $call();
    } catch (InvalidArgumentException) {
        return;
    } catch (Throwable $e) {
        $forwardBudgetFailures[] = $message . ' (unexpected ' . get_class($e) . ')';
        return;
    }
    $forwardBudgetFailures[] = $message;
}

function forwardBudgetCodepoints(string $value): int
{
    $count = preg_match_all('/./us', $value);
    if ($count === false) throw new RuntimeException('invalid UTF-8 in pure fixture');
    return $count;
}

function forwardBudgetCompose(array $source, string $action, string $cover): string
{
    $coverHeader = $action === 'mediate'
        ? 'Mediation request: Please consider this earlier correspondence and offer perspective. The attribution below is a claim, not authenticated authorship.'
        : 'Forwarded cover: Please consider this earlier correspondence as context. The attribution below is a claim, not authenticated authorship.';
    return $cover . "\n\n" . $coverHeader . "\n"
        . 'Original document ID: ' . $source['document_id'] . "\n"
        . 'Claimed sender: ' . $source['claimed_sender']['name'] . "\n"
        . 'Original recipient: ' . $source['recipient']['name'] . "\n"
        . "BEGIN ENCLOSED ORIGINAL BODY\n"
        . $source['body']
        . "\nEND ENCLOSED ORIGINAL BODY";
}

function forwardBudgetExpectedLimits(array $source, string $action): array
{
    $fixed = forwardBudgetCompose($source, $action, '');
    return [
        'codepoints' => max(0, min(700, 2000 - forwardBudgetCodepoints($fixed))),
        'bytes' => max(0, min(2800, 8000 - strlen($fixed))),
    ];
}

function forwardBudgetPromptContract(array $messages): ?array
{
    $marker = 'Available actions and model-body limits for this exact source (JSON; only these actions are valid): ';
    $start = strpos($messages[0]['content'], $marker);
    if ($start === false) return null;
    $line = strtok(substr($messages[0]['content'], $start + strlen($marker)), "\r\n");
    try {
        $contract = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
    return is_array($contract) ? $contract : null;
}

function forwardBudgetSource(string $recipientName, string $senderName, string $body): array
{
    return [
        'schema_version' => 1,
        'document_id' => '11111111-1111-4111-8111-111111111111',
        'playthrough_id' => 'unprofiled',
        'recipient' => ['kind' => 'npc', 'id' => 900001, 'name' => $recipientName],
        'claimed_sender' => ['kind' => 'npc', 'id' => 900002, 'name' => $senderName],
        'body' => $body,
        'receipt' => [
            'receipt_id' => '22222222-2222-4222-8222-222222222222',
            'operation_id' => '33333333-3333-4333-8333-333333333333',
            'status' => 'confirmed',
            'exposure' => 'context',
        ],
        'attachments' => [],
    ];
}

function forwardBudgetSelf(string $name): array
{
    return ['key' => 'npc:900001', 'name' => $name, 'personality' => 'Careful and practical.'];
}

function forwardBudgetContacts(string $senderName): array
{
    return [
        ['key' => 'npc:900002', 'name' => $senderName],
        ['key' => 'npc:900003', 'name' => 'Faendal'],
        ['key' => 'player', 'name' => 'Dragonborn'],
    ];
}

function forwardBudgetRaw(string $action, ?string $recipient, string $body): string
{
    return json_encode(
        ['action' => $action, 'recipient' => $recipient, 'body' => $body, 'reason' => 'A grounded choice.'],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
}

$recipientName = str_repeat('🦊', 24) . str_repeat('R', 104);
$senderName = str_repeat('🦊', 24) . str_repeat('S', 104);
forwardBudgetCheck(forwardBudgetCodepoints($recipientName) === 128 && strlen($recipientName) === 200, 'fixture must exercise valid 128-codepoint, 200-byte actor names');
forwardBudgetCheck(forwardBudgetCodepoints($senderName) === 128 && strlen($senderName) === 200, 'sender fixture must exercise valid 128-codepoint, 200-byte actor name');

$mediumSource = forwardBudgetSource($recipientName, $senderName, str_repeat('A', 900) . str_repeat('🦊', 200));
$mediumSelf = forwardBudgetSelf($recipientName);
$mediumContacts = forwardBudgetContacts($senderName);
$mediumMessages = correspondenceAutonomyMessages($mediumSource, $mediumSelf, $mediumContacts);
$mediumContract = forwardBudgetPromptContract($mediumMessages);
forwardBudgetCheck($mediumContract !== null, 'prompt must advertise a parseable per-source action and body-budget contract');
if ($mediumContract !== null) {
    $expectedKeys = ['none', 'reply', 'forward', 'mediate'];
    forwardBudgetCheck(array_keys($mediumContract) === $expectedKeys, 'a medium complete copy must advertise only its feasible action set');
    forwardBudgetCheck(($mediumContract['reply'] ?? null) === ['codepoints' => 700, 'bytes' => 2800], 'reply must retain its existing independent body budget');
    foreach (['forward', 'mediate'] as $action) {
        $expected = forwardBudgetExpectedLimits($mediumSource, $action);
        forwardBudgetCheck(($mediumContract[$action] ?? null) === $expected, $action . ' prompt budget must include its exact fixed header, UUID, 128-codepoint names, and full original in both units');
        forwardBudgetCheck($expected['codepoints'] > 0 && $expected['codepoints'] < 700, $action . ' medium-source budget must use the remaining space below the 700-codepoint cap');
    }
}

$forwardLimits = forwardBudgetExpectedLimits($mediumSource, 'forward');
$forwardCoverLimit = min($forwardLimits['codepoints'], $forwardLimits['bytes']);
if ($forwardCoverLimit > 0 && $forwardCoverLimit < 700) {
    $maxCover = str_repeat('x', $forwardCoverLimit);
    $decision = correspondenceAutonomyDecision(forwardBudgetRaw('forward', 'npc:900003', $maxCover), $mediumSource, $mediumContacts);
    $composed = correspondenceAutonomyBody($decision, $mediumSource);
    forwardBudgetCheck($decision['body'] === $maxCover, 'the exact advertised cover limit must pass shared decision validation');
    forwardBudgetCheck(forwardBudgetCodepoints($composed) <= 2000 && strlen($composed) <= 8000, 'full forward composition must fit both renderer limits with the complete original');
    $copyStart = strpos($composed, "BEGIN ENCLOSED ORIGINAL BODY\n");
    $copyEnd = strrpos($composed, "\nEND ENCLOSED ORIGINAL BODY");
    forwardBudgetCheck($copyStart !== false && $copyEnd !== false && substr($composed, $copyStart + strlen("BEGIN ENCLOSED ORIGINAL BODY\n"), $copyEnd - ($copyStart + strlen("BEGIN ENCLOSED ORIGINAL BODY\n"))) === $mediumSource['body'], 'the original body must be copied byte-for-byte at the budget boundary');
    forwardBudgetReject(
        fn() => correspondenceAutonomyDecision(forwardBudgetRaw('forward', 'npc:900003', $maxCover . 'x'), $mediumSource, $mediumContacts),
        'a response that ignores the offered forward budget must reject in decision validation before composition'
    );
}

$longSource = forwardBudgetSource('Camilla', 'Jarl Balgruuf', str_repeat('L', 1900));
$longSelf = forwardBudgetSelf('Camilla');
$longContacts = forwardBudgetContacts('Jarl Balgruuf');
$longMessages = correspondenceAutonomyMessages($longSource, $longSelf, $longContacts);
$longContract = forwardBudgetPromptContract($longMessages);
forwardBudgetCheck($longContract !== null, 'long-source prompt must keep an explicit action contract');
if ($longContract !== null) {
    forwardBudgetCheck(array_keys($longContract) === ['none', 'reply'], 'forward and mediate must be omitted when even a one-codepoint cover cannot fit');
    forwardBudgetCheck(($longContract['reply'] ?? null) === ['codepoints' => 700, 'bytes' => 2800], 'reply must remain feasible for a long original');
}
$longReply = correspondenceAutonomyDecision(forwardBudgetRaw('reply', 'npc:900002', 'I will consider this carefully.'), $longSource, $longContacts);
forwardBudgetCheck(correspondenceAutonomyBody($longReply, $longSource) === $longReply['body'], 'a long source must still permit an unclipped reply');
forwardBudgetReject(
    fn() => correspondenceAutonomyDecision(forwardBudgetRaw('forward', 'npc:900003', 'Please consider this.'), $longSource, $longContacts),
    'shared decision validation must reject a forward action omitted by the source-specific budget'
);

if ($forwardBudgetFailures !== []) {
    fwrite(STDERR, "RED server forward budget:\n - " . implode("\n - ", $forwardBudgetFailures) . "\n");
    exit(1);
}

echo "PASS server forward budget: source-specific actions, exact dual-unit caps, and full-copy preservation\n";
