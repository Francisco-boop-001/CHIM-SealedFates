<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/server/lib/offscreen.php';

function offscreenDev19Check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$engine = (new ReflectionClass(CorrespondenceOffscreenPg::class))->newInstanceWithoutConstructor();
$projectionMethod = new ReflectionMethod($engine, 'selfProjection');
$self = [
    'key'=>'npc:900001',
    'name'=>'Camilla',
    'personality'=>'Thoughtful and private.',
    'relationships'=>[
        'Lydia'=>['aff'=>42],
        'lydia'=>['aff'=>-7],
        'FAENDAL'=>['aff'=>11],
    ],
];
$contacts = [
    ['key'=>'npc:900002','name'=>'Lydia'],
    ['key'=>'npc:900003','name'=>'Faendal'],
];
$projection = $projectionMethod->invoke($engine, $self, $contacts);
$expected = [
    ['key'=>'npc:900002','affinity'=>-7],
    ['key'=>'npc:900003','affinity'=>11],
];
offscreenDev19Check($projection['relationships'] === $expected, 'case-variant relationship names must deduplicate by canonical NPC key using lowest affinity and stable key order');

$autonomyMessages = correspondenceAutonomyMessages(
    [
        'schema_version'=>1,
        'document_id'=>'11111111-1111-4111-8111-111111111111',
        'playthrough_id'=>'unprofiled',
        'recipient'=>['kind'=>'npc','id'=>900001,'name'=>'Camilla'],
        'claimed_sender'=>['kind'=>'npc','id'=>900002,'name'=>'Lydia'],
        'body'=>'Would you speak with me?',
        'receipt'=>[
            'receipt_id'=>'11111111-1111-4111-8111-111111111111',
            'operation_id'=>'22222222-2222-4222-8222-222222222222',
            'status'=>'confirmed',
            'exposure'=>'context',
        ],
        'attachments'=>[],
    ],
    $projection,
    $contacts
);
$initiationMessages = correspondenceOffscreenInitiateMessages(
    $projection,
    ['id'=>hash('sha256','ask-about-harvest'),'text'=>'Ask Lydia about the harvest.'],
    $contacts
);
offscreenDev19Check(count($autonomyMessages) === 2 && count($initiationMessages) === 2, 'strict reaction and initiation message builders must accept canonicalized relationships');

echo "PASS dev19 offscreen projection deduplicates relationship aliases for both strict prompt builders\n";
