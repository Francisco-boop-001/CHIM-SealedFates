<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/server/lib/offscreen.php';
require_once dirname(__DIR__).'/server/lib/store.php';
require_once dirname(__DIR__).'/server/lib/autonomy_store.php';
require_once __DIR__.'/support/correspondence_memory_store.php';
function contactsCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function contactsNameKey(string $name):string{return function_exists('mb_strtolower')?mb_strtolower($name,'UTF-8'):strtolower($name);}
function contactsNamesUnique(array $contacts):bool{$names=[];foreach($contacts as$contact){$key=contactsNameKey($contact['name']);if(isset($names[$key]))return false;$names[$key]=true;}return true;}
$actors=[];
for($id=1;$id<=35;$id++)$actors[$id]=['id'=>$id,'key'=>'npc:'.$id,'name'=>$id===1?'Camilla':'Person '.$id,'personality'=>'','enabled'=>true,'letters'=>true,'dead'=>false,'enabled_raw'=>'true','letters_raw'=>'true','dead_raw'=>'false','location'=>'Riverwood','relationships'=>[]];
$actors[35]['name']='Sigrid';$actors[2]['name']='sIGRID';$actors[34]['name']='SIGRID';
$only=$argv[1]??'all';
if(in_array($only,['all','offscreen'],true)){
    $offscreen=(new ReflectionClass(CorrespondenceOffscreenPg::class))->newInstanceWithoutConstructor();
    $method=new ReflectionMethod(CorrespondenceOffscreenPg::class,'contacts');
    $contacts=$method->invoke($offscreen,$actors[1],$actors,35);
    $keys=array_column($contacts,'key');
    contactsCheck(count($contacts)<=32,'contact_budget_must_remain_bounded');
    contactsCheck(($keys[0]??null)==='npc:35','reply_sender_must_be_first_before_contact_cap');
    contactsCheck(in_array('npc:35',$keys,true),'reply_sender_beyond_cap_must_be_prioritized');
    contactsCheck(!in_array('npc:2',$keys,true)&&!in_array('npc:34',$keys,true),'sender_namesakes_must_not_replace_sender');
    contactsCheck(!in_array('npc:1',$keys,true)&&contactsNamesUnique($contacts),'offscreen_contacts_must_exclude_self_and_casefold_namesakes');
    $initiationContacts=$method->invoke($offscreen,$actors[1],$actors);
    contactsCheck(count($initiationContacts)===32&&!in_array('npc:1',array_column($initiationContacts,'key'),true),'initiation_contact_cap_and_self_exclusion_must_remain');
    $ambiguous=$actors;$ambiguous[35]['name']='cAMILLA';
    contactsCheck($method->invoke($offscreen,$ambiguous[1],$ambiguous,35)===[],'self_sender_namesake_must_defer_before_claim');
}
if(in_array($only,['all','autonomy'],true)){
    $memory=new CorrespondenceMemoryStore();$memory->actors=[];
    $actors[2]['name']='cAMILLA';
    foreach($actors as$id=>$actor)$memory->actors[$id]=['id'=>$id,'npc_name'=>$actor['name'],'refid'=>sprintf('%08X',$id)];
    $autonomy=new CorrespondenceAutonomyPg(new CorrespondencePgStore(null,static fn()=>null,static fn()=>null),new CorrespondenceService($memory));
    $method=new ReflectionMethod(CorrespondenceAutonomyPg::class,'contextForSource');
    $source=['schema_version'=>1,'document_id'=>'10000000-0000-4000-8000-000000000001','playthrough_id'=>'1','recipient'=>['kind'=>'npc','id'=>1,'name'=>'Camilla'],'claimed_sender'=>['kind'=>'npc','id'=>35,'name'=>'Sigrid'],'body'=>'A complete original letter.','receipt'=>['receipt_id'=>'10000000-0000-4000-8000-000000000002','operation_id'=>'10000000-0000-4000-8000-000000000003','status'=>'confirmed','exposure'=>'context'],'attachments'=>[]];
    $context=$method->invoke($autonomy,$source,$actors);
    $keys=is_array($context)?array_column($context['contacts'],'key'):[];
    contactsCheck(is_array($context)&&in_array('npc:35',$keys,true),'ordinary_autonomy_must_keep_source_sender');
    contactsCheck(($keys[0]??null)==='npc:35','ordinary_source_sender_must_be_first');
    contactsCheck(!in_array('npc:2',$keys,true)&&!in_array('npc:34',$keys,true),'self_namesake_must_not_poison_message_construction');
    contactsCheck(count($context['contacts'])<=32&&contactsNamesUnique($context['contacts']),'ordinary_autonomy_contacts_must_remain_bounded_and_casefold_unique');
    $messages=correspondenceAutonomyMessages($source,$context['self'],$context['contacts']);
    contactsCheck(count($messages)===2&&is_string($messages[1]['content']??null),'valid_sender_context_must_build_messages');
    $actors[35]['name']='cAMILLA';$memory->actors[35]['npc_name']='cAMILLA';$source['claimed_sender']['name']='cAMILLA';
    contactsCheck($method->invoke($autonomy,$source,$actors)===null,'ambiguous_self_sender_must_be_deferred_before_claim');
}
echo "PASS contact construction: bounded sender priority, namesakes and pre-claim deferral\n";