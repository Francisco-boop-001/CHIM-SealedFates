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

// An NPC-to-NPC scheduler-owned letter whose delivery failed with a retryable reason.
$store=new CorrespondenceMemoryStore();$service=new CorrespondenceService($store);
$doc=$service->compose(['request_id'=>correspondenceUuid(),'author'=>'npc:900001','payer'=>'npc:900001','recipient'=>'player','claimed_sender'=>'npc:900001','body'=>'Reply','attachments'=>[]]);
$id=$doc['document_id'];$operation=$store->operations[$service->dispatch($id)['operation_id']];
$service->receive(['protocol_version'=>1,'document_id'=>$id,'operation_id'=>$operation['operation_id'],'receipt_id'=>$operation['receipt_id'],'playthrough_id'=>'unprofiled','scope_token'=>$operation['scope_token'],'nonce'=>$operation['nonce'],'status'=>'failed','reason'=>'renderer_not_ready','note_form_id'=>0,'attachments'=>[]]);
ownershipCheck($service->get($id)['state']==='failed','fixture letter must be retryable-failed');

$managed=[$id];
$html=correspondencePage($service->actors(),[],$service->list(),'token','',null,[],[],$managed);
ownershipCheck(!str_contains($html,'value="retry"'),'scheduler-owned failed letter must not offer a manual retry');
ownershipCheck(!str_contains($html,'value="close"'),'NPC-to-NPC scheduler-owned letters retain their close policy');
$unmanaged=correspondencePage($service->actors(),[],$service->list(),'token','',null,[],[],[]);
ownershipCheck(str_contains($unmanaged,'value="retry"'),'manual failed letter to the player keeps its retry');
foreach(['dispatch','retry','close'] as $action)ownershipRejected(fn()=>correspondenceAutonomyGuardManualDispatch(['action'=>$action,'document_id'=>$id],$managed),'autonomous_dispatch_owned');
ownershipCheck(correspondenceAutonomyGuardManualDispatch(['action'=>'reconcile','document_id'=>$id],$managed)['action']==='reconcile','read-only reconciliation stays available');

// Companion send ownership must not remove the safe per-letter close operation.
$companionOwned=[$id];
$companionHtml=correspondencePage($service->actors(),[],$service->list(),'token','',null,[],[],[],$companionOwned);
ownershipCheck(str_contains($companionHtml,'value="close"'),'companion-owned failed letter must retain safe Close');
ownershipCheck(!str_contains($companionHtml,'value="retry"'),'companion-owned failed letter must not offer a manual retry');
foreach(['dispatched','unknown'] as $state){
    $letter=$service->list()[0];$letter['state']=$state;
    $stateHtml=correspondencePage($service->actors(),[],[$letter],'token','',null,[],[],[],$companionOwned);
    ownershipCheck(str_contains($stateHtml,'value="close"')&&str_contains($stateHtml,'value="reconcile"')&&!str_contains($stateHtml,'value="dispatch"')&&!str_contains($stateHtml,'value="retry"'),'in-flight and unknown companion letters retain safe Close and reconciliation only');
}
foreach(['dispatch','retry'] as $action)ownershipRejected(fn()=>correspondenceAutonomyGuardManualDispatch(['action'=>$action,'document_id'=>$id],[],$companionOwned),'autonomous_dispatch_owned');
$closeRequest=['action'=>'close','document_id'=>$id];
ownershipCheck(correspondenceAutonomyGuardManualDispatch($closeRequest,[],$companionOwned)===$closeRequest,'companion-owned letter permits safe close');
ownershipRejected(fn()=>correspondenceAutonomyGuardManualDispatch($closeRequest,$managed,$companionOwned),'autonomous_dispatch_owned');
foreach([['invalid-id'],['not-a-list'=>$id]] as $invalid)ownershipRejected(fn()=>correspondenceAutonomyGuardManualDispatch($closeRequest,[],$invalid),'autonomy_status_unavailable');
ownershipCheck(correspondenceAutonomyGuardManualDispatch(['action'=>'reconcile','document_id'=>$id],[],$companionOwned)['action']==='reconcile','companion read-only reconciliation stays available');
$operationCount=count($store->operations);$commands=count($store->commands);
$closed=$service->close($id);
ownershipCheck($closed['operator_closed']===true&&count($store->operations)===$operationCount&&count($store->commands)===$commands,'safe Close must not create a transfer or native command');
$closedHtml=correspondencePage($service->actors(),[],$service->list(),'token','',null,[],[],[],$companionOwned);
ownershipCheck(str_contains($closedHtml,'Closed by operator')&&!str_contains($closedHtml,'value="close"')&&!str_contains($closedHtml,'value="retry"'),'closed companion letter stays closed without send/close controls');

$queued=$service->compose(['request_id'=>correspondenceUuid(),'author'=>'npc:900001','payer'=>'npc:900001','recipient'=>'player','claimed_sender'=>'npc:900001','body'=>'Waiting here.','attachments'=>[]]);
$queuedId=$queued['document_id'];
$queuedRows=array_values(array_filter($service->list(),static fn($row)=>$row['document_id']===$queuedId));
$queuedHtml=correspondencePage($service->actors(),[],$queuedRows,'token','',null,[],[],[],[$queuedId]);
ownershipCheck(str_contains($queuedHtml,'Awaiting autonomous scheduler')&&str_contains($queuedHtml,'value="close"')&&!str_contains($queuedHtml,'value="dispatch"'),'queued companion letter stays scheduler-only but can be closed');
$queuedClose=correspondenceAutonomyGuardManualDispatch(['action'=>'close','document_id'=>$queuedId],[],[$queuedId]);
$operations=count($store->operations);$commands=count($store->commands);
$service->close($queuedClose['document_id']);
ownershipRejected(fn()=>$service->dispatch($queuedId),'operator_closed');
ownershipCheck(count($store->operations)===$operations&&count($store->commands)===$commands,'closed queued letter cannot dispatch or create transfers');

$index=file_get_contents($root.'/server/index.php');
ownershipCheck(is_string($index)&&preg_match("/\\\$known=\\[[^\\]]*'companion_scheduler_owned'/",$index)===1,'companion ownership refusal must have a readable message');
ownershipCheck(str_contains($index,"\$runtime['companion']->ownedDocuments()"),'companion-owned letters must join the managed list');
ownershipCheck(str_contains($index,'correspondencePost($_POST,$csrf,$choices),$managedChildren,$companionDocuments)')&&str_contains($index,'$autonomySettings,$autonomyRows,$managedChildren,$companionDocuments)'),'real POST and page must receive companion ownership separately from autonomy ownership');
echo "PASS scheduler ownership: companion safe Close, no manual sends, unchanged autonomy policy and no resend\n";
