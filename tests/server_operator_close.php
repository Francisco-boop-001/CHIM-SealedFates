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
closeRejected(fn()=>$service->dispatch($doc['document_id']),'document_unavailable');
echo "PASS operator closure: anchored, idempotent, no resend/refund, late proof and restore safety\n";
