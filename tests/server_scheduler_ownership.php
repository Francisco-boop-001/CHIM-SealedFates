<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require_once $root.'/server/lib/runtime.php';require_once $root.'/server/lib/view.php';require_once __DIR__.'/support/correspondence_memory_store.php';
function ownershipCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function ownershipRejected(callable $work,string $reason):void
{
    try{$work();}catch(DomainException $error){ownershipCheck($error->getMessage()===$reason,'unexpected rejection: '.$error->getMessage());return;}
    throw new RuntimeException('expected rejection: '.$reason);
}

// A scheduler-owned letter to the player whose delivery failed with a retryable reason.
$store=new CorrespondenceMemoryStore();$service=new CorrespondenceService($store);
$doc=$service->compose(['request_id'=>correspondenceUuid(),'author'=>'npc:900001','payer'=>'npc:900001','recipient'=>'player','claimed_sender'=>'npc:900001','body'=>'Reply','attachments'=>[]]);
$id=$doc['document_id'];$operation=$store->operations[$service->dispatch($id)['operation_id']];
$service->receive(['protocol_version'=>1,'document_id'=>$id,'operation_id'=>$operation['operation_id'],'receipt_id'=>$operation['receipt_id'],'playthrough_id'=>'unprofiled','scope_token'=>$operation['scope_token'],'nonce'=>$operation['nonce'],'status'=>'failed','reason'=>'renderer_not_ready','note_form_id'=>0,'attachments'=>[]]);
ownershipCheck($service->get($id)['state']==='failed','fixture letter must be retryable-failed');

$managed=[$id];
$html=correspondencePage($service->actors(),[],$service->list(),'token','',null,[],[],$managed);
ownershipCheck(!str_contains($html,'value="retry"'),'scheduler-owned failed letter must not offer a manual retry');
$unmanaged=correspondencePage($service->actors(),[],$service->list(),'token','',null,[],[],[]);
ownershipCheck(str_contains($unmanaged,'value="retry"'),'manual failed letter to the player keeps its retry');
foreach(['dispatch','retry','close'] as $action)ownershipRejected(fn()=>correspondenceAutonomyGuardManualDispatch(['action'=>$action,'document_id'=>$id],$managed),'autonomous_dispatch_owned');
ownershipCheck(correspondenceAutonomyGuardManualDispatch(['action'=>'reconcile','document_id'=>$id],$managed)['action']==='reconcile','read-only reconciliation stays available');

$index=file_get_contents($root.'/server/index.php');
ownershipCheck(is_string($index)&&preg_match("/\\\$known=\\[[^\\]]*'companion_scheduler_owned'/",$index)===1,'companion ownership refusal must have a readable message');
ownershipCheck(str_contains($index,"\$runtime['companion']->ownedDocuments()"),'companion-owned letters must join the managed list');
echo "PASS scheduler ownership: managed letters hide manual sends, guard retry and explain companion refusals\n";
