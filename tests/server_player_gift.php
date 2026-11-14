<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/server/lib/view.php';
require_once __DIR__.'/support/correspondence_memory_store.php';

function giftCheck(bool $condition,string $reason):void {if(!$condition)throw new RuntimeException($reason);}
function giftRejected(callable $call,string $reason):void
{
    try{$call();}catch(DomainException|InvalidArgumentException){return;}
    throw new RuntimeException($reason);
}

function giftCase(array $changes=[],bool $declare=true):array
{
    $store=new CorrespondenceMemoryStore();
    $service=new CorrespondenceService($store);
    $input=['request_id'=>correspondenceUuid(),'author'=>'player','payer'=>'player','recipient'=>'npc:900001','claimed_sender'=>'player','body'=>'For you, Camilla.','attachments'=>[['kind'=>'gold','form_id'=>'Skyrim.esm|0000000F','quantity'=>50]]];
    if($declare)$input['gift']=true;
    $input=array_replace($input,$changes);
    $doc=$service->composeAndDispatch($input);
    $op=$store->operations[$doc['operation_id']];
    $receipt=array_intersect_key($op,array_flip(['protocol_version','document_id','operation_id','receipt_id','playthrough_id','scope_token','nonce']));
    $receipt+=['status'=>'delivered','reason'=>'physical_only','note_form_id'=>12345,'attachments'=>[['form_id'=>15,'quantity'=>50,'before_source'=>100,'after_source'=>50,'before_recipient'=>5,'after_recipient'=>55]]];
    return [$store,$service,$input,$doc,$receipt];
}

function giftExposure(CorrespondenceMemoryStore $store,CorrespondenceService $service,array $doc):array
{
    $service->prompt('Camilla');
    $source=end($store->events);
    // PostgreSQL writes the logical written_evidence event as info.
    $source['type']='info';
    $store->events[$source['rowid']]=$source;
    $store->operations[$doc['operation_id']]['source']=$source;
    return $source;
}
[$store,$service,$input,$doc,$receipt]=giftCase();
giftCheck($service->compose($input)['document_id']===$doc['document_id'],'explicit gift is idempotent');
$unmarked=$input;unset($unmarked['gift']);
giftRejected(fn()=>$service->compose($unmarked),'declaration must be bound to the request hash');
giftCheck(!array_key_exists('gift_proof',$store->operations[$doc['operation_id']]),'dispatch must not create social proof');
$wrong=$receipt;$wrong['attachments'][0]['after_recipient']=54;
giftRejected(fn()=>$service->receive($wrong),'exact recipient delta required');
$wireReceipt=$receipt;$wireReceipt['attachments'][0]=array_reverse($wireReceipt['attachments'][0],true);$wireReceipt=array_reverse($wireReceipt,true);
$service->receive($wireReceipt);
$stored=$store->operations[$doc['operation_id']];
giftCheck(isset($stored['gift_proof']),'first anchored delivery must mint private proof');
$anchor=json_decode($stored['receipt_anchor']['data'],true,64,JSON_THROW_ON_ERROR);
giftCheck($anchor['receipt_hash']===hash('sha256',correspondenceJson($receipt)),'gift anchors must use native receipt member order');
$before=count($store->events);$service->receive($receipt);
giftCheck(count($store->events)===$before,'receipt replay must not create another anchor');
$source=giftExposure($store,$service,$doc);$prompt=$service->prompt('Camilla');
giftCheck(!str_contains($prompt,'gift_proof')&&!str_contains($source['data'],'gift'),'model prompt and envelope must not receive the private proof');
giftCheck($service->confirmedPlayerGiftSource($source['rowid'],900001),'valid proof must match the exposed recipient');
$operation=$store->operations[$doc['operation_id']];
ksort($operation['receipt']);ksort($operation['receipt']['attachments'][0]);
$store->operations[$doc['operation_id']]=$operation;
giftCheck($service->confirmedPlayerGiftSource($source['rowid'],900001),'JSONB object reordering must not alter verification');
giftRejected(fn()=>$service->confirmedPlayerGiftSource($source['rowid'],900002),'proof cannot cross recipients');
$store->operations[$doc['operation_id']]['receipt']['attachments'][0]['before_source']++;
giftRejected(fn()=>$service->confirmedPlayerGiftSource($source['rowid'],900001),'a changed receipt must fail its anchored proof');
$store->operations[$doc['operation_id']]=$operation;
$store->actors[900001]['refid']='00013489';
giftRejected(fn()=>$service->confirmedPlayerGiftSource($source['rowid'],900001),'changed native recipient reference must fail');
$store->actors[900001]['refid']='00013488';
unset($store->events[$operation['receipt_anchor']['rowid']]);
giftRejected(fn()=>$service->confirmedPlayerGiftSource($source['rowid'],900001),'missing receipt anchor must fail');
unset($store->events[$source['rowid']]);
$store->events[7]=['rowid'=>7,'type'=>'info','data'=>'synthetic restored timeline','people'=>'','sess'=>'','ts'=>100,'gamets'=>100,'localts'=>100];
$service->receive($receipt);
$reexposed=giftExposure($store,$service,$doc);
giftCheck($store->operations[$doc['operation_id']]['gift_proof']===$stored['gift_proof'],'reconciliation cannot replace immutable gift proof');
giftCheck($service->confirmedPlayerGiftSource($reexposed['rowid'],900001),'exact original proven receipt can be re-anchored by native reconciliation');

foreach([['gift'=>false],['gift'=>'true'],['gift'=>null],['payer'=>'npc:900002'],['author'=>'npc:900002'],['claimed_sender'=>'npc:900002'],['recipient'=>'player'],['attachments'=>[]]] as $changes){
    giftRejected(fn()=>giftCase($changes),'invalid gift declaration accepted');
}
foreach([['claimed_sender'=>'npc:900002'],[]]as$changes){
    [$normal,$normalService,,$normalDoc,$normalReceipt]=giftCase($changes,false);
    $normalService->receive($normalReceipt);$normalSource=giftExposure($normal,$normalService,$normalDoc);
    giftCheck(!isset($normal->operations[$normalDoc['operation_id']]['gift_proof'])&&!$normalService->confirmedPlayerGiftSource($normalSource['rowid'],900001),'unmarked and forged letters stay on the existing path');
}
[$provisional,$provisionalService,,$provisionalDoc,$realReceipt]=giftCase();
$unknown=$realReceipt;$unknown['status']='unknown';$unknown['reason']='transfer_mismatch';$unknown['note_form_id']=0;$unknown['attachments']=[];
$provisionalService->receive($unknown);$provisionalService->receive($realReceipt);$now=giftExposure($provisional,$provisionalService,$provisionalDoc);
giftCheck($provisionalService->confirmedPlayerGiftSource($now['rowid'],900001),'first proven delivery may resolve an earlier provisional receipt');
[$nonPhysical,$nonPhysicalService,,$nonPhysicalDoc,$nonPhysicalReceipt]=giftCase();
$nonPhysicalReceipt['reason']='ok';$nonPhysicalService->receive($nonPhysicalReceipt);$unsupported=giftExposure($nonPhysical,$nonPhysicalService,$nonPhysicalDoc);
giftCheck(!$nonPhysicalService->confirmedPlayerGiftSource($unsupported['rowid'],900001),'non-physical receipt cannot prove a gift');
giftCheck(array_key_exists('gift_proof',$nonPhysical->operations[$nonPhysicalDoc['operation_id']])
    && $nonPhysical->operations[$nonPhysicalDoc['operation_id']]['gift_proof']===null,'first delivery must record even denied gift credit');
unset($nonPhysical->events[$unsupported['rowid']],$nonPhysical->events[$nonPhysical->operations[$nonPhysicalDoc['operation_id']]['receipt_anchor']['rowid']]);
$nonPhysical->events[7]=['rowid'=>7,'type'=>'info','data'=>'synthetic restored timeline','people'=>'','sess'=>'','ts'=>100,'gamets'=>100,'localts'=>100];
$unconfirmed=$nonPhysicalReceipt;$unconfirmed['status']='unknown';$unconfirmed['reason']='transfer_mismatch';$unconfirmed['note_form_id']=0;$unconfirmed['attachments']=[];
$nonPhysicalService->receive($unconfirmed);
$nonPhysicalReceipt['reason']='physical_only';$nonPhysicalService->receive($nonPhysicalReceipt);
giftCheck($nonPhysical->operations[$nonPhysicalDoc['operation_id']]['gift_proof']===null,'subsequent exact-looking receipts cannot backfill previously denied credit');
[$remapped,$remappedService,,$remappedDoc,$remappedReceipt]=giftCase();
$remapped->actors[900001]['refid']='00013489';
giftCheck($remappedService->receive($remappedReceipt)['state']==='delivered'
    && $remapped->operations[$remappedDoc['operation_id']]['gift_proof']===null,'changed native reference must not prevent the ordinary receipt');
[$closed,$closedService,,$closedDoc,$closedReceipt]=giftCase();
$closedService->close($closedDoc['document_id']);
giftCheck($closedService->receive($closedReceipt)['state']==='delivered'
    && $closed->operations[$closedDoc['operation_id']]['gift_proof']===null,'operator-closed letter acknowledges delivery without credit');

$post=['action'=>'compose','csrf'=>'token','request_id'=>correspondenceUuid(),'author'=>'player','payer'=>'player','recipient'=>'npc:900001','claimed_sender'=>'player','body'=>'For you.','item'=>['Skyrim.esm|0000000F'],'quantity'=>['50']];
$choices=[['form_id'=>'Skyrim.esm|0000000F','kind'=>'gold']];
giftCheck(!array_key_exists('gift',correspondencePost($post,'token',$choices)['input']),'unchecked web form must keep legacy request shape');
giftCheck(correspondencePost($post+['gift'=>'1'],'token',$choices)['input']['gift']===true,'checked web form must declare true');
giftRejected(fn()=>correspondencePost($post+['gift'=>['1']],'token',$choices),'malformed campaign POST must reject');
echo "PASS private gift declaration, receipt provenance, recipient and restore invalidation, and web validation\n";
