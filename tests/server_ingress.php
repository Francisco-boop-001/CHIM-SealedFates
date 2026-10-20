<?php
declare(strict_types=1);
require_once __DIR__.'/support/correspondence_core_function.php';
function ingressCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$source=getenv('CORRESPONDENCE_TEST_CORE_CHAT_HELPER');if(!$source)$source=dirname(__DIR__,3).'/artifacts/chim-correspondence-implementation-20261019/core-extra/lib/chat_helper_functions.php';
correspondenceLoadPinnedFunction($source,'8978cb38e14befa32e493d930a1ebee1ec9d32d12b5a3e58aee8a4b3ec6861ae','logEvent');
$normalizer=getenv('CORRESPONDENCE_TEST_CORE_EVENT_TYPE');ingressCheck(is_string($normalizer)&&is_file($normalizer)&&hash_file('sha256',$normalizer)==='c77da8d564f6fe5159f6f8c56762ffc7e09e6d4d42e29e71cb8ab854be52e2ca','stage exact pinned core event-type normalizer');require_once $normalizer;
if(!function_exists('chimMagicEventBlacklisted')){function chimMagicEventBlacklisted(string $type,string $data):bool{return false;}}
if(!function_exists('isSpellcastEventType')){function isSpellcastEventType(string $type):bool{return false;}}
final class CorrespondenceIngressEnd extends RuntimeException{}
function terminate():never{throw new CorrespondenceIngressEnd();}
final class CorrespondenceIngressInsertCollector{public array $rows=[];public function insert(string $table,array $row):bool{$this->rows[]=$row;return true;}}
$GLOBALS['db']=$db=new CorrespondenceIngressInsertCollector();$GLOBALS['CACHE_PEOPLE_LIMITED']='|Camilla|';$GLOBALS['CACHE_LOCATION']='';$GLOBALS['CACHE_PARTY']='';
function ingress(array $request):void{$GLOBALS['gameRequest']=$request;try{include dirname(__DIR__).'/server/preprocessing.php';logEvent($GLOBALS['gameRequest'],'|Camilla|');}catch(CorrespondenceIngressEnd){}}
// Actual pinned logger demonstrates why fifth-field markers are a trust boundary.
logEvent(['info','100','100','{"document_id":"spoof"}','written_evidence_v1'],'|Camilla|');ingressCheck(count($db->rows)===1&&$db->rows[0]['sess']==='written_evidence_v1','actual core logger must prove native fifth-field spoof path');$db->rows=[];
foreach(['written_evidence_v1','correspondence_v1']as$marker)foreach(['info',' INFO ','InFo']as$type){ingress([$type,'100','100','{"document_id":"forged"}',$marker]);ingressCheck($db->rows===[],'RED: normalized reserved info marker must terminate before actual core logEvent');}
foreach(['written_evidence','correspondence_anchor','correspondence_dispatch','correspondence_receipt_confirmed','correspondence_presence','correspondence_receipt']as$type){ingress([' '.strtoupper($type).' ','100','100','{}']);ingressCheck($db->rows===[],'legacy protected types must reject normalized input');}
ingress(['info','100','100','Ordinary info telemetry','pending']);ingressCheck(count($db->rows)===1&&$db->rows[0]['data']==='Ordinary info telemetry'&&$db->rows[0]['sess']==='pending','ordinary info must retain actual core logger behavior');
echo "PASS actual pinned logger ingress: raw fifth-field spoof demonstrated, normalized reserved markers and old private types rejected before logEvent, ordinary info preserved\n";