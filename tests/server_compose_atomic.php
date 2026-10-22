<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/server/lib/runtime.php';
require_once __DIR__.'/support/correspondence_memory_store.php';
function composeAtomicCheck(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
$store = new CorrespondenceMemoryStore();
$service = new CorrespondenceService($store);
$send = static fn(array $input): array => method_exists($service, 'composeAndDispatch')
    ? $service->composeAndDispatch($input) : $service->dispatch($service->compose($input)['document_id']);
$input = ['request_id'=>correspondenceUuid(),'author'=>'player','payer'=>'player','recipient'=>'npc:900001','claimed_sender'=>'player','body'=>'A complete letter that cannot fit the renderer.','attachments'=>[]];
$store->beforePrepareNote = static function (): void { throw new DomainException('renderer_content_overflow'); };
try { $send($input); throw new RuntimeException('renderer refusal must escape'); }
catch (DomainException $error) { composeAtomicCheck($error->getMessage()==='renderer_content_overflow', 'original refusal must survive'); }
composeAtomicCheck($store->docs===[] && $store->events===[] && $store->operations===[] && $store->notes===[] && $store->commands===[], 'renderer refusal must leave no queued document, anchors, operation or command');
$store->beforePrepareNote = null;
$sent = $send($input);
composeAtomicCheck($sent['state']==='dispatched' && count($store->docs)===1 && count($store->operations)===1, 'a corrected retry of the same form must create exactly one letter');
$commands = $store->commands;
composeAtomicCheck($send($input)===$sent && $store->commands===$commands, 'repeated successful form must not resend');
$waiting = new CorrespondenceMemoryStore();
$waiting->executor = null;
$queued = (new CorrespondenceService($waiting))->composeAndDispatch($input);
composeAtomicCheck($queued['state']==='queued' && count($waiting->docs)===1 && $waiting->operations===[] && $waiting->commands===[], 'a genuinely unavailable executor still leaves one visible queued letter');
echo "PASS atomic compose: render refusal rolls back; success is idempotent; executor absence remains queued\n";
