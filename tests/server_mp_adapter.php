<?php
declare(strict_types=1);
require_once __DIR__.'/support/correspondence_mp_public_fixture.php';
require_once dirname(__DIR__).'/server/lib/consumers.php';
function mpCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$root=__DIR__.'/support/correspondence_absent_consumer_root';$native=new stdClass();$GLOBALS['db']=$dbSentinel=new stdClass();$GLOBALS['correspondence_mp_fixture']=[];
$result=correspondenceConsumeReferences($root,$native,[42]);
mpCheck(($result[42]['mind_poisoning']['status']??null)==='queued'&&($GLOBALS['correspondence_mp_fixture']['enqueued']??[])===[42],'RED: MP adapter must map frozen public enqueue and trusted native StoreDb');
mpCheck($GLOBALS['db']===$dbSentinel&&$GLOBALS['correspondence_mp_fixture']['connections']==[$native],'borrowed native handle must not alter global db');
mpCheck(!str_contains(json_encode($result),'Secret')&&empty($GLOBALS['correspondence_mp_fixture']['forbidden_evaluate']),'adapter must strip private fields and never call evaluator/provider');
$GLOBALS['correspondence_mp_fixture']['hints']=[];$GLOBALS['correspondence_mp_fixture']['enqueued']=[];
$result=correspondenceConsumeReferences($root,$native,[43],true);mpCheck($result[43]['mind_poisoning']['status']==='pending'&&$GLOBALS['correspondence_mp_fixture']['enqueued']===[],'read-only UI must use public status, never enqueue');
$GLOBALS['correspondence_mp_fixture']['version']=2;$before=count($GLOBALS['correspondence_mp_fixture']['status_calls']);$result=correspondenceConsumeReferences($root,$native,[44]);mpCheck($result[44]['mind_poisoning']['status']==='unsupported'&&count($GLOBALS['correspondence_mp_fixture']['status_calls'])===$before&&$GLOBALS['correspondence_mp_fixture']['enqueued']===[],'unknown MP capability must not call status or enqueue');
$GLOBALS['correspondence_mp_fixture']['version']=1;$GLOBALS['correspondence_mp_fixture']['throw']=true;$result=correspondenceConsumeReferences($root,$native,[45]);mpCheck($result[45]['mind_poisoning']===['status'=>'unavailable','reason'=>'consumer_unavailable']&&!str_contains(json_encode($result),'Private'),'public adapter errors must fail closed without private diagnostics');
$GLOBALS['correspondence_mp_fixture']= ['statuses'=>[1=>'processed',2=>'invalid',3=>'paused',4=>'abstained',5=>'failed',6=>'pending',7=>'pending']];$result=correspondenceConsumeReferences($root,$native,[],false,[7,6,5,4,3,2,1]);mpCheck(($GLOBALS['correspondence_mp_fixture']['enqueued']??[])===[6]&&count($GLOBALS['correspondence_mp_fixture']['status_calls'])===7,'offer exactly oldest pending after one public status check per source');
$GLOBALS['correspondence_mp_fixture']=['hints'=>[7=>true]];correspondenceConsumeReferences($root,$native,[],false,[6,7]);mpCheck(empty($GLOBALS['correspondence_mp_fixture']['enqueued']),'existing queued hint must be preserved without adding another');
echo "PASS MP public adapter: frozen exports, borrowed store, readonly status, private-field filtering, capability/absence/errors and bounded pending selection\n";
