<?php
declare(strict_types=1);
require_once __DIR__.'/lib/bootstrap.php';
global $gameRequest;
$type=is_string($gameRequest[0]??null)?strtolower(trim($gameRequest[0])):null;
if($type==='info'&&in_array($gameRequest[4]??null,['written_evidence_v1','correspondence_v1'],true)){correspondenceDiagnostic('receipt_rejected');if(function_exists('terminate'))terminate();exit;}
if($type==='init'){try{correspondenceHoldInitFence();}catch(Throwable){correspondenceDiagnostic('init_fence_unavailable');if(function_exists('terminate'))terminate();exit;}}
if(in_array($type,['written_evidence','correspondence_anchor','correspondence_dispatch','correspondence_receipt_confirmed','correspondence_presence'],true)){
    correspondenceDiagnostic('receipt_rejected');if(function_exists('terminate'))terminate();exit;
}
if($type==='correspondence_receipt'){
    try{
        $raw=$gameRequest[3]??null;if(!is_string($raw)||strlen($raw)>16384)throw new InvalidArgumentException('invalid_receipt');
        $receipt=json_decode($raw,true,16,JSON_THROW_ON_ERROR);if(!is_array($receipt)||array_is_list($receipt))throw new InvalidArgumentException('invalid_receipt');
        $runtime=correspondenceProduction();$runtime['store']->bindRequestClock($gameRequest);$runtime['service']->receive($receipt);
    }catch(Throwable){correspondenceDiagnostic('receipt_rejected');}
    if(function_exists('terminate'))terminate();exit;
}
if(in_array($type,['inputtext','inputtext_s','ginputtext','ginputtext_s'],true)&&is_string($gameRequest[4]??null)){
    try{$runtime=correspondenceProduction();$runtime['store']->bindRequestClock($gameRequest);$raw=$gameRequest[4];if(strlen($raw)>45000)throw new InvalidArgumentException('invalid_snapshot');$runtime['store']->observeManaged($raw);}catch(Throwable){correspondenceDiagnostic('presence_rejected');}
}
if(is_array($gameRequest??null)){
    $result=correspondenceOffscreenObserveNativeRequest($gameRequest,'correspondenceOffscreenProduction','correspondenceOffscreenServiceStart');
    if(($result['status']??null)==='unavailable')correspondenceDiagnostic('offscreen_unavailable');
}

