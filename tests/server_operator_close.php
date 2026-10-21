<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/server/lib/view.php';
require_once __DIR__.'/support/correspondence_memory_store.php';
function closeCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function closeRejected(callable $work,string $reason):void{try{$work();}catch(DomainException $e){closeCheck($e->getMessage()===$reason,'unexpected refusal: '.$e->getMessage());return;}throw new RuntimeException('expected '.$reason);}
$store=new CorrespondenceMemoryStore();$service=new CorrespondenceService($store);
$input=['request_id'=>correspondenceUuid(),'author'=>'player','payer'=>'player','recipient'=>'npc:900001','claimed_sender'=>'npc:900002','body'=>'This courier deserves a holiday.','attachments'=>[['kind'=>'gold','form_id'=>'Skyrim.esm|0000000F','quantity'=>50]]];
$doc=$service->compose($input);
closeCheck(method_exists($service,'close'),'RED: auditable operator closure missing');
$closed=$service->close($doc['document_id']);
closeCheck($closed['state']==='queued'&&$closed['operator_closed']===true&&$store->commands===[]&&$store->operations===[],'queued closure must not claim a delivery outcome');
$events=count($store->events);$service->close($doc['document_id']);closeCheck(count($store->events)===$events,'closing twice must reuse the same closure');
closeRejected(fn()=>$service->dispatch($doc['document_id']),'operator_closed');
closeRejected(fn()=>$service->retry($doc['document_id']),'retry_unsafe');
closeCheck($service->list()[0]['operator_closed']===true,'operator list exposes closure separately from delivery');
$token=str_repeat('a',64);closeCheck(correspondencePost(['csrf'=>$token,'action'=>'close','document_id'=>$doc['document_id']],$token,[])['action']==='close','closure requires the existing CSRF parser');
closeRejected(fn()=>correspondenceAutonomyGuardManualDispatch(['action'=>'close','document_id'=>$doc['document_id']],[$doc['document_id']]),'autonomous_dispatch_owned');
$html=correspondencePage($service->actors(),[],$service->list(),$token);
closeCheck(str_contains($html,'Closed by operator')&&!str_contains($html,'value="dispatch"'),'closed queued letters cannot offer dispatch');
$input['request_id']=correspondenceUuid();$sentDoc=$service->compose($input);$sent=$service->dispatch($sentDoc['document_id']);$op=$store->operations[$sent['operation_id']];
$receipt=array_intersect_key($op,array_flip(['protocol_version','document_id','operation_id','receipt_id','playthrough_id','scope_token','nonce']));
$receipt+=['status'=>'unknown','reason'=>'journal_unavailable','note_form_id'=>0,'attachments'=>[['form_id'=>15,'quantity'=>0,'before_source'=>100,'after_source'=>100,'before_recipient'=>0,'after_recipient'=>0]]];
$service->receive($receipt);$beforeCommands=count($store->commands);$service->close($sentDoc['document_id']);
closeCheck($service->get($sentDoc['document_id'])['state']==='unknown'&&count($store->commands)===$beforeCommands,'abandoning unknown neither refunds nor fabricates failure');
closeRejected(fn()=>$service->retry($sentDoc['document_id']),'retry_unsafe');closeRejected(fn()=>$service->dispatch($sentDoc['document_id']),'operator_closed');
$service->dispatch($sentDoc['document_id'],true);closeCheck(str_contains(end($store->commands)['action'],'_Reconcile@'),'closed unknown still allows read-only reconciliation');
$receipt['status']='delivered';$receipt['reason']='physical_only';$receipt['note_form_id']=12345;$receipt['attachments'][0]['quantity']=50;$receipt['attachments'][0]['after_source']=50;$receipt['attachments'][0]['after_recipient']=50;
closeCheck($service->receive($receipt)['state']==='delivered','late genuine proof may resolve a closed unknown');
$afterProofEvents=count($store->events);closeCheck($service->close($sentDoc['document_id'])['state']==='delivered'&&count($store->events)===$afterProofEvents,'repeated administrative close stays idempotent after late proof');
closeCheck($service->list()[1]['operator_closed']===true,'late receipt must not reopen operator closure');
$closure=$store->docs[$doc['document_id']]['operator_close'];unset($store->events[$closure['rowid']]);
$listed=array_column($service->list(),null,'document_id');
closeCheck(isset($listed[$doc['document_id']])&&$listed[$doc['document_id']]['operator_closed']===true&&$listed[$doc['document_id']]['operator_close_evidence_unavailable']===true,'pruned close proof keeps the letter visible, flagged, and closed');
closeCheck($service->get($doc['document_id'])['state']==='queued','missing close proof does not corrupt the original document state');
$eventsBeforeRepeatedClose=count($store->events);$service->close($doc['document_id']);
closeCheck(count($store->events)===$eventsBeforeRepeatedClose&&isset($store->docs[$doc['document_id']]['operator_close']),'repeated close preserves the raw closure without recreating its proof');
$commandsBeforePrunedDispatch=count($store->commands);
closeRejected(fn()=>$service->dispatch($doc['document_id']),'operator_closed');
closeRejected(fn()=>$service->retry($doc['document_id']),'retry_unsafe');
closeCheck(count($store->commands)===$commandsBeforePrunedDispatch,'missing close proof cannot resend');
$html=correspondencePage($service->actors(),[],$service->list(),$token);
closeCheck(str_contains($html,'Closure evidence unavailable; remains closed.'),'operator view discloses missing close proof');

$recoveryStore=new CorrespondenceMemoryStore();$recoveryService=new CorrespondenceService($recoveryStore);
$recoveryDoc=$recoveryService->compose(['request_id'=>correspondenceUuid(),'author'=>'player','payer'=>'player','recipient'=>'npc:900001','claimed_sender'=>'npc:900002','body'=>'A closed letter with a late courier receipt.','attachments'=>[]]);
$recoveryDispatch=$recoveryService->dispatch($recoveryDoc['document_id']);$recoveryClose=$recoveryService->close($recoveryDoc['document_id']);
$recoveryOp=$recoveryStore->operations[$recoveryDispatch['operation_id']];$recoveryReceipt=array_intersect_key($recoveryOp,array_flip(['protocol_version','document_id','operation_id','receipt_id','playthrough_id','scope_token','nonce']));
$recoveryReceipt+=['status'=>'delivered','reason'=>'physical_only','note_form_id'=>12345,'attachments'=>[]];
unset($recoveryStore->events[$recoveryStore->docs[$recoveryDoc['document_id']]['operator_close']['rowid']]);
closeCheck($recoveryService->list()[0]['operator_close_evidence_unavailable']===true,'missing close proof is ephemeral list state');
$badReceipt=$recoveryReceipt;$badReceipt['nonce']=str_repeat('b',64);
closeRejected(fn()=>$recoveryService->receive($badReceipt),'receipt_identity_mismatch');
closeCheck($recoveryStore->operations[$recoveryDispatch['operation_id']]['receipt']===null,'tampered receipt cannot resolve a closed document');
$recoveryService->dispatch($recoveryDoc['document_id'],true);
closeCheck(str_contains(end($recoveryStore->commands)['action'],'_Reconcile@'),'missing close proof permits read-only reconciliation with original dispatch proof');
closeCheck($recoveryService->receive($recoveryReceipt)['state']==='delivered'&&$recoveryService->list()[0]['operator_closed']===true,'genuine late receipt resolves delivery while closure remains');

$identityStore=new CorrespondenceMemoryStore();$identityService=new CorrespondenceService($identityStore);
$identityDoc=$identityService->compose(['request_id'=>correspondenceUuid(),'author'=>'player','payer'=>'player','recipient'=>'npc:900001','claimed_sender'=>'npc:900002','body'=>'Identity remains a recovery boundary.','attachments'=>[]]);
$identityDispatch=$identityService->dispatch($identityDoc['document_id']);$identityService->close($identityDoc['document_id']);
$identityOp=$identityStore->operations[$identityDispatch['operation_id']];$identityReceipt=array_intersect_key($identityOp,array_flip(['protocol_version','document_id','operation_id','receipt_id','playthrough_id','scope_token','nonce']));
$identityReceipt+=['status'=>'delivered','reason'=>'physical_only','note_form_id'=>12345,'attachments'=>[]];
unset($identityStore->events[$identityStore->docs[$identityDoc['document_id']]['operator_close']['rowid']]);$identityStore->actors[900001]['npc_name']='Renamed Camilla';
closeRejected(fn()=>$identityService->receive($identityReceipt),'actor_identity_changed');
closeCheck($identityStore->operations[$identityDispatch['operation_id']]['receipt']===null,'identity drift cannot accept stale-close receipt recovery');

$anchorStore=new CorrespondenceMemoryStore();$anchorService=new CorrespondenceService($anchorStore);
$anchorDoc=$anchorService->compose(['request_id'=>correspondenceUuid(),'author'=>'player','payer'=>'player','recipient'=>'npc:900001','claimed_sender'=>'npc:900002','body'=>'Missing original anchors cannot recover.','attachments'=>[]]);
$anchorDispatch=$anchorService->dispatch($anchorDoc['document_id']);$anchorService->close($anchorDoc['document_id']);
$anchorOp=$anchorStore->operations[$anchorDispatch['operation_id']];$anchorReceipt=array_intersect_key($anchorOp,array_flip(['protocol_version','document_id','operation_id','receipt_id','playthrough_id','scope_token','nonce']));
$anchorReceipt+=['status'=>'delivered','reason'=>'physical_only','note_form_id'=>12345,'attachments'=>[]];
$closedAnchor=$anchorStore->docs[$anchorDoc['document_id']]['operator_close'];unset($anchorStore->events[$closedAnchor['rowid']]);
$dispatchAnchor=$anchorOp['dispatch_anchor'];unset($anchorStore->events[$dispatchAnchor['rowid']]);
closeRejected(fn()=>$anchorService->dispatch($anchorDoc['document_id'],true),'dispatch_anchor_unavailable');
closeRejected(fn()=>$anchorService->receive($anchorReceipt),'dispatch_anchor_unavailable');
closeCheck($anchorStore->operations[$anchorDispatch['operation_id']]['receipt']===null,'missing dispatch proof cannot accept a receipt');

$documentAnchor=$recoveryStore->docs[$recoveryDoc['document_id']]['anchor'];unset($recoveryStore->events[$documentAnchor['rowid']]);
closeRejected(fn()=>$recoveryService->dispatch($recoveryDoc['document_id'],true),'document_unavailable');
closeRejected(fn()=>$recoveryService->receive($recoveryReceipt),'document_unavailable');

$malformedStore=new CorrespondenceMemoryStore();$malformedService=new CorrespondenceService($malformedStore);
$malformedDoc=$malformedService->compose(['request_id'=>correspondenceUuid(),'author'=>'player','payer'=>'player','recipient'=>'npc:900001','claimed_sender'=>'npc:900002','body'=>'Malformed close data stays unavailable.','attachments'=>[]]);
$malformedService->close($malformedDoc['document_id']);$malformedStore->docs[$malformedDoc['document_id']]['operator_close']['data']='changed';
closeRejected(fn()=>$malformedService->get($malformedDoc['document_id']),'document_unavailable');

$tamperedDocumentStore=new CorrespondenceMemoryStore();$tamperedDocumentService=new CorrespondenceService($tamperedDocumentStore);
$tamperedDocument=$tamperedDocumentService->compose(['request_id'=>correspondenceUuid(),'author'=>'player','payer'=>'player','recipient'=>'npc:900001','claimed_sender'=>'npc:900002','body'=>'Original document evidence is required.','attachments'=>[]]);
$tamperedDocumentStore->docs[$tamperedDocument['document_id']]['body']='altered';
closeRejected(fn()=>$tamperedDocumentService->get($tamperedDocument['document_id']),'document_unavailable');

$tamperedDispatchStore=new CorrespondenceMemoryStore();$tamperedDispatchService=new CorrespondenceService($tamperedDispatchStore);
$tamperedDispatchDoc=$tamperedDispatchService->compose(['request_id'=>correspondenceUuid(),'author'=>'player','payer'=>'player','recipient'=>'npc:900001','claimed_sender'=>'npc:900002','body'=>'Original dispatch evidence is required.','attachments'=>[]]);
$tamperedDispatch=$tamperedDispatchService->dispatch($tamperedDispatchDoc['document_id']);$tamperedDispatchService->close($tamperedDispatchDoc['document_id']);
$tamperedDispatchOp=$tamperedDispatchStore->operations[$tamperedDispatch['operation_id']];$tamperedDispatchReceipt=array_intersect_key($tamperedDispatchOp,array_flip(['protocol_version','document_id','operation_id','receipt_id','playthrough_id','scope_token','nonce']));
$tamperedDispatchReceipt+=['status'=>'delivered','reason'=>'physical_only','note_form_id'=>12345,'attachments'=>[]];
$tamperedDispatchStore->events[$tamperedDispatchOp['dispatch_anchor']['rowid']]['data']='tampered';
closeRejected(fn()=>$tamperedDispatchService->dispatch($tamperedDispatchDoc['document_id'],true),'dispatch_anchor_unavailable');
closeRejected(fn()=>$tamperedDispatchService->receive($tamperedDispatchReceipt),'dispatch_anchor_unavailable');

$tamperedClosureStore=new CorrespondenceMemoryStore();$tamperedClosureService=new CorrespondenceService($tamperedClosureStore);
$tamperedClosureDoc=$tamperedClosureService->compose(['request_id'=>correspondenceUuid(),'author'=>'player','payer'=>'player','recipient'=>'npc:900001','claimed_sender'=>'npc:900002','body'=>'The close event may be missing or mismatched.','attachments'=>[]]);
$tamperedClosureService->close($tamperedClosureDoc['document_id']);$tamperedClose=$tamperedClosureStore->docs[$tamperedClosureDoc['document_id']]['operator_close'];
$tamperedClosureStore->events[$tamperedClose['rowid']]['data']='tampered';
$tamperedClosureRow=$tamperedClosureService->list()[0];
closeCheck($tamperedClosureRow['operator_closed']===true&&$tamperedClosureRow['operator_close_evidence_unavailable']===true,'mismatched close proof remains conservatively closed and visible');
closeRejected(fn()=>$tamperedClosureService->dispatch($tamperedClosureDoc['document_id']),'operator_closed');
echo "PASS operator closure: anchored, idempotent, no resend/refund, late proof and restore safety\n";
