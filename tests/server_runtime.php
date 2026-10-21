<?php
declare(strict_types=1);
$root = dirname(__DIR__);
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
check(is_file($root . '/server/lib/runtime.php'), 'RED: correspondence production runtime does not exist');
require_once $root . '/server/lib/runtime.php';
require_once __DIR__ . '/support/correspondence_memory_store.php';
check(count(correspondenceAttachments([['kind'=>'item','form_id'=>'Example.ESP|00000001','quantity'=>1]]))===1,'uppercase plugin extension must remain valid');
$store = new CorrespondenceMemoryStore();
$service = new CorrespondenceService($store);
$input = ['request_id'=>'11111111-1111-4111-8111-111111111111','author'=>'player','payer'=>'player','recipient'=>'npc:900001','claimed_sender'=>'npc:900002','body'=>"Camilla, enclosed gold.\nSigned the jarl",'attachments'=>[['kind'=>'gold','form_id'=>'Skyrim.esm|0000000F','quantity'=>50]]];
$doc = $service->compose($input);
check($doc['state'] === 'queued', 'composition must queue without publishing evidence');
check(count($store->events) === 1 && $store->events[1]['type'] === 'correspondence_anchor', 'only private anchor is published on compose');
check($service->compose($input)['document_id'] === $doc['document_id'], 'repeated form request must be idempotent');
function rejected(callable $fn, string $why): void { try { $fn(); } catch (DomainException|InvalidArgumentException $e) { return; } throw new RuntimeException($why); }
function accepted(callable $fn): mixed { try { return $fn(); } catch (InvalidArgumentException) { return null; } }
rejected(fn()=>correspondenceAttachments([['kind'=>'gold','form_id'=>'skyrim.esm|0000000F','quantity'=>1]]),'Gold must use exact frozen canonical stable identity');
$changed = $input; $changed['body'] = 'different'; rejected(fn()=>$service->compose($changed), 'same request id must reject changed content');
$bad=$input; $bad['request_id']='22222222-2222-4222-8222-222222222222'; $bad['body']=str_repeat('é',2001); rejected(fn()=>$service->compose($bad),'Unicode overflow must reject');
$bad['body']="\xff"; rejected(fn()=>$service->compose($bad),'invalid UTF8 must reject');
$bad['body']='valid'; $bad['attachments'][0]['quantity']=0; rejected(fn()=>$service->compose($bad),'zero quantity must reject');
$bad['attachments']=$input['attachments']; $bad['attachments'][]=$bad['attachments'][0]; rejected(fn()=>$service->compose($bad),'duplicate item must reject');
$store->executor=null; check($service->dispatch($doc['document_id'])['state']==='queued','missing executor cannot dispatch');
$store->executor='Lydia'; $sent=$service->dispatch($doc['document_id']);
check($sent['state']==='dispatched' && count($store->commands)===2,'dispatch prepares note and then actor command once');
$service->dispatch($doc['document_id']); check(count($store->commands)===2,'dispatched mutation cannot be resent');
$op=$store->operations[$sent['operation_id']]; $wire=$store->commands[1];
check(str_starts_with($wire['action'],'command|ExtCmdCHIMCorrespondenceBridge_Deliver@'),'must use actor command route');
check($wire['actor']==='Lydia' && !str_contains($wire['action'],'rolecommand'),'executor must be separate verified managed actor');
$receipt=['protocol_version'=>1,'document_id'=>$doc['document_id'],'operation_id'=>$op['operation_id'],'receipt_id'=>$op['receipt_id'],'playthrough_id'=>'unprofiled','scope_token'=>$op['scope_token'],'nonce'=>$op['nonce'],'status'=>'delivered','reason'=>'ok','note_form_id'=>12345,'attachments'=>[['form_id'=>15,'quantity'=>50,'before_source'=>100,'after_source'=>50,'before_recipient'=>5,'after_recipient'=>55]]];
$wrong=$receipt; $wrong['nonce']=str_repeat('a',64); rejected(fn()=>$service->receive($wrong),'wrong nonce must reject');
$wrong=$receipt; $wrong['attachments'][0]['after_source']=60; rejected(fn()=>$service->receive($wrong),'mismatched deltas must reject');
$got=$service->receive($receipt); check($got['state']==='delivered','receipt confirms possession');
check(count($store->events)===3,'possession must not publish written evidence');
$upperReceipt=$receipt;foreach(['document_id','operation_id','receipt_id','scope_token','nonce']as$key)$upperReceipt[$key]=strtoupper($upperReceipt[$key]);$upperReceipt['status']=strtoupper($upperReceipt['status']);$upperReceipt['reason']=strtoupper($upperReceipt['reason']);
$upperAccepted=accepted(fn()=>$service->receive($upperReceipt));check($upperAccepted===$got&&$store->operations[$op['operation_id']]['receipt']===$receipt,'uppercase identities and ASCII enum fields must bind to the same operation and keep canonical stored values');
check($service->receive($receipt)===$got && count($store->events)===3,'receipt replay must be idempotent');
$badEnum=$receipt;$badEnum['status']='DELIVERED?';rejected(fn()=>$service->receive($badEnum),'unknown receipt status must reject');
$badReason=$receipt;$badReason['reason']="PHYSICAL\u{00C9}_ONLY";rejected(fn()=>$service->receive($badReason),'non-ASCII receipt reason must reject after ASCII case normalization');
$badScope=$receipt;$badScope['playthrough_id']='UNPROFILED';rejected(fn()=>$service->receive($badScope),'playthrough identity stays case-sensitive');
check($service->prompt('The Narrator')==='' && $service->prompt('Faendal')==='','unrelated/Narrator prompt gets no letters');
$text=$service->prompt('Camilla'); check(str_contains($text,'Signed the jarl'),'recipient prompt gets original text');
$source=end($store->events); check($source['type']==='written_evidence' && $source['sess']==='written_evidence_v1' && $source['people']==='|Camilla|','canonical source marker and recipient only');
$visible=json_decode($source['data'],true,512,JSON_THROW_ON_ERROR);
check($visible['claimed_sender']['name']==='Jarl Balgruuf' && $visible['receipt']['exposure']==='context','claimed attribution and context exposure');
check(!str_contains($source['data'],'Dragonborn') && !str_contains($text,'Dragonborn') && !str_contains($source['data'],$op['nonce']),'private author/payer/nonce must never leak');
check(count($visible)===8 && $visible['attachments'][0]['quantity']===50,'strict envelope and confirmed attachments');
$service->prompt('Camilla'); check(count($store->events)===4,'prompt replay must not publish twice');
$store->actors[900003]=['id'=>900003,'npc_name'=>'Camilla','refid'=>'00013488']; check($service->prompt('Camilla')==='','ambiguous prompt owner must fail closed'); unset($store->actors[900003]);
unset($store->events[3]); check($service->prompt('Camilla')==='','restored missing delivery marker must hide future state');
check($service->get($doc['document_id'])['state']==='unknown','lost receipt anchor must be unknown, never future delivered');
$service->dispatch($doc['document_id']); check(count($store->commands)===2,'restore must not replay physical mutation');
unset($store->events[1]); rejected(fn()=>$service->get($doc['document_id']),'restored missing document anchor must reject');
$store2=new CorrespondenceMemoryStore();$svc2=new CorrespondenceService($store2);$queued=$svc2->compose($input);$sent2=$svc2->dispatch($queued['document_id']);$op2=$store2->operations[$sent2['operation_id']];
$r2=$receipt;foreach(['document_id','operation_id','receipt_id','playthrough_id','scope_token','nonce']as$key)$r2[$key]=$op2[$key];
$unknown=$r2;$unknown['status']='UNKNOWN';$unknown['reason']='INTERRUPTED';$unknown['note_form_id']=0;$unknown['attachments'][0]['quantity']=0;$unknown['attachments'][0]['after_source']=100;$unknown['attachments'][0]['after_recipient']=5;
$unknown['attachments'][0]['after_source']=95;$unknown['attachments'][0]['after_recipient']=9;$unknown['attachments'][0]['quantity']=4;
$unknownAccepted=accepted(fn()=>$svc2->receive($unknown));check(($unknownAccepted['state']??null)==='unknown','ambiguous outcome must remain unknown');
$storedUnknown=$store2->operations[$op2['operation_id']]['receipt'];check($storedUnknown['status']==='unknown'&&$storedUnknown['reason']==='interrupted'&&$storedUnknown['playthrough_id']==='unprofiled','uppercase unknown enum fields canonicalize while preserving unprofiled wire scope');
$lowerUnknown=$unknown;$lowerUnknown['status']='unknown';$lowerUnknown['reason']='interrupted';check($svc2->receive($lowerUnknown)['state']==='unknown','lowercase replay of a case-normalized unknown stays unknown');
rejected(fn()=>$svc2->retry($queued['document_id']),'unknown receipt never becomes retryable through case normalization');
$svc2->dispatch($queued['document_id']);check(count($store2->commands)===2,'unknown must not resend');
check($svc2->receive($r2)['state']==='delivered','read-only reconciliation may upgrade unknown to confirmed');
$reordered=$store2->docs[$queued['document_id']];foreach(['author','payer','recipient','claimed_sender']as$key){$a=$reordered[$key];$reordered[$key]=['name'=>$a['name'],'id'=>$a['id'],'kind'=>$a['kind']];}$store2->docs[$queued['document_id']]=$reordered;
check($svc2->get($queued['document_id'])['state']==='delivered','JSONB object key order must not change actor identity');
$beforeWire=explode('@',$store2->commands[1]['action'],2)[1];$opSorted=$store2->operations[$sent2['operation_id']];ksort($opSorted);foreach(['payer','recipient']as$key)ksort($opSorted[$key]);foreach($opSorted['attachments']as&$line)ksort($line);unset($line);$store2->operations[$sent2['operation_id']]=$opSorted;
$svc2->dispatch($queued['document_id'],true);$reconcileWire=explode('@',end($store2->commands)['action'],2)[1];check($beforeWire===$reconcileWire,'JSONB reload must preserve exact bridge wire bytes for reconciliation');
$store3=new CorrespondenceMemoryStore();$svc3=new CorrespondenceService($store3);
for($i=0;$i<4;$i++){$next=$input;$next['request_id']=correspondenceUuid();$next['attachments']=[];$q=$svc3->compose($next);$d=$svc3->dispatch($q['document_id']);$o=$store3->operations[$d['operation_id']];$r=$receipt;foreach(['document_id','operation_id','receipt_id','playthrough_id','scope_token','nonce']as$key)$r[$key]=$o[$key];$r['attachments']=[];$svc3->receive($r);}
$svc3->prompt('Camilla');$svc3->prompt('Camilla');check(count(array_filter($store3->events,fn($e)=>$e['type']==='written_evidence'))===4,'bounded prompts must eventually expose all delivered letters');
$store4=new CorrespondenceMemoryStore();$svc4=new CorrespondenceService($store4);$next=$input;$next['attachments']=[];$q=$svc4->compose($next);$d=$svc4->dispatch($q['document_id']);$o=$store4->operations[$d['operation_id']];$r=$receipt;foreach(['document_id','operation_id','receipt_id','playthrough_id','scope_token','nonce']as$key)$r[$key]=$o[$key];$r['status']='FAILED';$r['reason']='INSUFFICIENT_INVENTORY';$r['note_form_id']=0;$r['attachments']=[];$failedAccepted=accepted(fn()=>$svc4->receive($r));check(($failedAccepted['state']??null)==='failed','case-normalized safe preflight failure remains retryable');
$lowerFailed=$r;$lowerFailed['status']='failed';$lowerFailed['reason']='insufficient_inventory';$failedEvents=count($store4->events);check($svc4->receive($lowerFailed)['state']==='failed'&&$store4->operations[$d['operation_id']]['receipt']['status']==='failed'&&$store4->operations[$d['operation_id']]['receipt']['reason']==='insufficient_inventory'&&count($store4->events)===$failedEvents,'lowercase replay of a case-normalized retryable failure is idempotent');
check(method_exists($svc4,'retry'),'explicit safe failure retry missing');$retried=$svc4->retry($q['document_id']);check($retried['operation_id']!==$d['operation_id']&&$retried['state']==='dispatched','safe failed retry must get a new physical operation identity');
rejected(fn()=>$svc4->retry($q['document_id']),'dispatched/unknown operation cannot be retried');
$store5=new CorrespondenceMemoryStore();$svc5=new CorrespondenceService($store5);$next=$input;$next['attachments']=[];$q=$svc5->compose($next);$d=$svc5->dispatch($q['document_id']);$o=$store5->operations[$d['operation_id']];$r=$receipt;foreach(['document_id','operation_id','receipt_id','playthrough_id','scope_token','nonce']as$key)$r[$key]=$o[$key];$r['attachments']=[];$svc5->receive($r);$lost=$store5->operations[$d['operation_id']]['receipt_anchor']['rowid'];unset($store5->events[$lost]);$r['status']='failed';$r['reason']='operation_conflict';$r['note_form_id']=0;$r['attachments']=[];$svc5->receive($r);
rejected(fn()=>$svc5->retry($q['document_id']),'operation conflict cannot prove old physical effects absent');
$r['status']='delivered';$r['reason']='physical_only';$r['note_form_id']=12345;check($svc5->receive($r)['state']==='delivered','read-only recovery must replace provisional protocol failure');
$playerStore=new CorrespondenceMemoryStore();$playerService=new CorrespondenceService($playerStore);$playerInput=$input;$playerInput['recipient']='player';$playerInput['attachments']=[];$playerDoc=$playerService->compose($playerInput);$playerDispatch=$playerService->dispatch($playerDoc['document_id']);$playerOp=$playerStore->operations[$playerDispatch['operation_id']];$playerReceipt=$receipt;foreach(['document_id','operation_id','receipt_id','playthrough_id','scope_token','nonce']as$key)$playerReceipt[$key]=$playerOp[$key];$playerReceipt['attachments']=[];$playerService->receive($playerReceipt);$playerService->sourceReferences=[42];$opened=$playerService->readPlayer($playerDoc['document_id']);
check(array_keys($opened)===['document_id','claimed_sender','body','attachments']&&$playerService->list()[0]['source_event_id']===null&&$playerService->sourceReferences===[]&&count(array_filter($playerStore->events,fn($e)=>$e['type']==='written_evidence'))===0,'Player browser read must show verified delivery without inventing neutral game-clock evidence');
$nameStore=new CorrespondenceMemoryStore();$nameService=new CorrespondenceService($nameStore);
foreach([str_repeat('A',201),str_repeat('A',129),"Invalid\xff","Bad\x7fName","Bad\u{0085}Name",'|Camilla',"\u{00a0}Camilla","Camilla\u{00a0}"]as$invalidName){$nameStore->actors[900004]=['id'=>900004,'npc_name'=>$invalidName,'refid'=>'00013489'];check(!in_array('npc:900004',array_column($nameService->actors(),'key'),true),'NPC name outside consumer trust boundary must be omitted');$invalidInput=$input;$invalidInput['request_id']=correspondenceUuid();$invalidInput['recipient']='npc:900004';rejected(fn()=>$nameService->compose($invalidInput),'invalid canonical name must not enter persisted document');}
$nameStore->actors[900004]['npc_name']='Élodie';check(in_array('npc:900004',array_column($nameService->actors(),'key'),true),'valid Unicode canonical NPC must remain selectable');
$futureStore=new CorrespondenceMemoryStore();$futureService=new CorrespondenceService($futureStore);$futureInput=$input;$futureInput['attachments']=[];$futureDoc=$futureService->compose($futureInput);$futureSent=$futureService->dispatch($futureDoc['document_id']);$futureOp=$futureStore->operations[$futureSent['operation_id']];$futureReceipt=$receipt;foreach(['document_id','operation_id','receipt_id','playthrough_id','scope_token','nonce']as$key)$futureReceipt[$key]=$futureOp[$key];$futureReceipt['attachments']=[];$futureStore->clock=['ts'=>200,'gamets'=>200];$futureService->receive($futureReceipt);$futureStore->clock=['ts'=>300,'gamets'=>150];
check($futureService->prompt('Camilla')===''&&count(array_filter($futureStore->events,fn($e)=>$e['type']==='written_evidence'))===0,'current context150 cannot expose a future physical receipt200 before prune finishes');
$negativeStore=new CorrespondenceMemoryStore();$negativeService=new CorrespondenceService($negativeStore);$negativeDoc=$negativeService->compose($input);$negativeDispatch=$negativeService->dispatch($negativeDoc['document_id']);$negativeOp=$negativeStore->operations[$negativeDispatch['operation_id']];$negativeReceipt=$receipt;foreach(['document_id','operation_id','receipt_id','playthrough_id','scope_token','nonce']as$key)$negativeReceipt[$key]=$negativeOp[$key];$negativeReceipt['status']='unknown';$negativeReceipt['reason']='transfer_mismatch';$negativeReceipt['note_form_id']=12345;$negativeReceipt['attachments'][0]=['form_id'=>15,'quantity'=>-3,'before_source'=>100,'after_source'=>50,'before_recipient'=>10,'after_recipient'=>7];
check($negativeService->receive($negativeReceipt)['state']==='unknown'&&$negativeStore->operations[$negativeDispatch['operation_id']]['receipt']['attachments'][0]===$negativeReceipt['attachments'][0],'unknown must preserve an honest negative recipient delta');
$wrongUnknown=$negativeReceipt;$wrongUnknown['attachments'][0]['quantity']=1;rejected(fn()=>$negativeService->receive($wrongUnknown),'unknown quantity must equal its signed observed recipient delta');
$unboundedUnknown=$negativeReceipt;$observedDelta=$negativeOp['attachments'][0]['quantity']+1;$unboundedUnknown['attachments'][0]['after_recipient']=$unboundedUnknown['attachments'][0]['before_recipient']+$observedDelta;$unboundedUnknown['attachments'][0]['quantity']=$observedDelta;
check($negativeService->receive($unboundedUnknown)['state']==='unknown'&&$negativeStore->operations[$negativeDispatch['operation_id']]['receipt']['attachments'][0]===$unboundedUnknown['attachments'][0],'unknown must preserve a valid observed delta even when concurrent gains exceed the requested transfer');
$negativeCounter=$negativeReceipt;$negativeCounter['attachments'][0]['after_recipient']=-1;$negativeCounter['attachments'][0]['quantity']=-11;rejected(fn()=>$negativeService->receive($negativeCounter),'unknown counters must remain nonnegative and bounded');
$overflowCounter=$negativeReceipt;$overflowCounter['attachments'][0]['after_source']=2147483648;rejected(fn()=>$negativeService->receive($overflowCounter),'unknown counter magnitudes above int32 must reject');
$underflowQuantity=$negativeReceipt;$underflowQuantity['attachments'][0]['quantity']=-2147483649;rejected(fn()=>$negativeService->receive($underflowQuantity),'unknown signed quantity below int32 must reject');
$negativeService->dispatch($negativeDoc['document_id'],true);$negativeBridgeActions=array_values(array_filter(array_column($negativeStore->commands,'action'),fn($action)=>str_contains($action,'ExtCmdCHIMCorrespondenceBridge_')));
check(count(array_filter($negativeBridgeActions,fn($action)=>str_contains($action,'_Deliver@')))===1&&str_contains(end($negativeBridgeActions),'_Reconcile@'),'replaying an unknown receipt may reconcile but must not resend the transfer');
$negativeDelivered=$negativeReceipt;$negativeDelivered['status']='delivered';$negativeDelivered['reason']='physical_only';rejected(fn()=>$negativeService->receive($negativeDelivered),'negative observed recipient delta cannot claim delivered');
echo "PASS server runtime: validation, privacy, command routing, nonce, quantities, receipt replay, recipient scope, source publication, restore\n";














