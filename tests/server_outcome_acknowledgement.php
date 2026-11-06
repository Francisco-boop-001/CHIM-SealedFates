<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/server/lib/runtime.php';
require_once __DIR__.'/support/correspondence_memory_store.php';

function acknowledgementCheck(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

// Exercise the unchanged web action block with the production service and fake storage.
$source=file_get_contents(dirname(__DIR__).'/server/index.php');
$start=strpos($source,"if(\$request['action']==='compose')");
$end=$start===false?false:strpos($source,"            if((\$result['operator_closed']",$start);
acknowledgementCheck($start!==false&&$end!==false,'Web action block must be identifiable');
$actionBlock=substr($source,$start,$end-$start);
function acknowledgementAction(CorrespondenceService $service, array $request, string $actionBlock): array
{
    $message='';
    $opened=null;
    eval($actionBlock);
    return ['result'=>$result,'message'=>$message];
}

$store=new CorrespondenceMemoryStore();
$service=new CorrespondenceService($store);
$input=['request_id'=>'11111111-1111-4111-8111-111111111111','author'=>'player','payer'=>'player',
    'recipient'=>'npc:900002','claimed_sender'=>'player','body'=>'Offline acknowledgement regression',
    'attachments'=>[['kind'=>'gold','form_id'=>'Skyrim.esm|0000000F','quantity'=>50]]];
$store->executor=null;
$unsent=acknowledgementAction($service,['action'=>'compose','input'=>$input],$actionBlock);
acknowledgementCheck($unsent['result']['state']==='queued'&&count($store->commands)===0,'Unavailable carrier must retain an unsent saved letter');
$store->executor='The Narrator';
$sent=acknowledgementAction($service,['action'=>'dispatch','document_id'=>$unsent['result']['document_id']],$actionBlock);
$id=$sent['result']['document_id'];
$operation=$sent['result']['operation_id'];
$commandCount=count($store->commands);
acknowledgementCheck($sent['result']['state']==='dispatched'&&$commandCount===2,'Delivery queues renderer and transfer once');

$store->executor=null;
$refused=acknowledgementAction($service,['action'=>'reconcile','document_id'=>$id],$actionBlock);
acknowledgementCheck($refused['result']['state']==='dispatched'&&$refused['result']['reason']==='executor_unavailable'&&count($store->commands)===$commandCount,'Refused check must preserve the operation and queue no command');
acknowledgementCheck(str_contains($refused['message'],'Outcome check was not queued'),'RED: refused outcome check must report that no check was queued');
acknowledgementCheck(str_contains($refused['message'],'same letter')&&!str_contains($refused['message'],'Letter dispatched; physical receipt is pending.'),'Refusal must identify same-letter recovery instead of repeating the delivery banner');

$store->executor='The Narrator';
$checked=acknowledgementAction($service,['action'=>'reconcile','document_id'=>$id],$actionBlock);
acknowledgementCheck(str_contains($checked['message'],'Outcome check queued')&&$checked['message']!==$refused['message'],'Queued and refused checks need distinct acknowledgements');
acknowledgementCheck($checked['result']['operation_id']===$operation&&count($store->commands)===$commandCount+1,'Successful check must queue one command on the existing operation');
acknowledgementCheck(str_contains(json_encode(end($store->commands)),'ExtCmdCHIMCorrespondenceBridge_Reconcile@'),'The new command must only reconcile');
$deliveries=array_filter($store->commands,static fn($row)=>str_contains(json_encode($row),'ExtCmdCHIMCorrespondenceBridge_Deliver@'));
acknowledgementCheck(count($deliveries)===1,'Outcome checks must never queue a second transfer');
acknowledgementCheck($service->get($id)['state']==='dispatched'&&$store->operations[$operation]['receipt']===null,'Acknowledgements must not fabricate a receipt or delivery');

$store->executor=null;
$input['request_id']='22222222-2222-4222-8222-222222222222';
$next=acknowledgementAction($service,['action'=>'compose','input'=>$input],$actionBlock);
acknowledgementCheck(str_contains($next['message'],'delivery was not queued'),'Unsent composition must identify unavailable delivery');
acknowledgementCheck(count($store->commands)===$commandCount+1,'Unsent composition must not alter queued transfers');

echo "PASS outcome acknowledgements: refused, queued and unsent; retained operation, no duplicate transfer or fabricated receipt\n";
