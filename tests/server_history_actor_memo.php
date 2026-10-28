<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/server/lib/runtime.php';
require_once __DIR__.'/support/correspondence_memory_store.php';
function actorMemoCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}

$store=new CorrespondenceMemoryStore();
$service=new CorrespondenceService($store);
actorMemoCheck(method_exists($store,'transactionMemo')&&isset($store->transactionMemoBuilds),'RED: store must provide an observable transaction-only memo');

$before=$store->transactionMemoBuilds;
$store->readTransaction(function()use($service):void{
    $first=$service->actors();
    $second=$service->actors();
    actorMemoCheck($first===$second,'actor projection changed within one transaction');
});
actorMemoCheck($store->transactionMemoBuilds===$before+1,'derived actor projection must be built once in a transaction');

$store->actors[900003]=['id'=>900003,'npc_name'=>'Lydia','refid'=>'000A2C94'];
$store->readTransaction(fn()=>$service->actors());
actorMemoCheck(in_array('npc:900003',array_column($service->actors(),'key'),true),'actor changes must be re-read after transaction end');
actorMemoCheck($store->transactionMemoBuilds===$before+3,'calls outside a transaction must not retain the derived actor list');

$input=['request_id'=>correspondenceUuid(),'author'=>'player','payer'=>'player','recipient'=>'npc:900001','claimed_sender'=>'npc:900002','body'=>'A bounded actor-cache regression letter.','attachments'=>[]];
$beforeCompose=$store->transactionMemoBuilds;
$document=$service->compose($input);
actorMemoCheck($store->transactionMemoBuilds===$beforeCompose+2,'compose must build one actor list and one keyed lookup per transaction');
$store->actors[900001]['npc_name']='Camilla Restored';
$beforeRead=$store->transactionMemoBuilds;
try{$service->get($document['document_id']);throw new RuntimeException('stale actor identity accepted after transaction boundary');}
catch(DomainException $e){actorMemoCheck($e->getMessage()==='actor_identity_changed','wrong post-transaction actor validation failure');}
actorMemoCheck($store->transactionMemoBuilds===$beforeRead+2,'identity lookup must be rebuilt after an actor change');

echo "PASS transaction-scoped actor projection memo and post-transaction identity refresh\n";
