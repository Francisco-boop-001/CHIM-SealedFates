<?php
declare(strict_types=1);
function consumerCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
consumerCheck(is_file(dirname(__DIR__).'/server/lib/consumers.php'),'RED: optional public consumer adapter missing');
require_once dirname(__DIR__).'/server/lib/consumers.php';
$cap=['version'=>1,'input_type'=>'written_evidence','source_marker'=>'written_evidence_v1','source_event_type'=>'info','recipient_only'=>true,'max_body_codepoints'=>2000];
$calls=[];$enqueue=function(int $id)use(&$calls){$calls[]=$id;return ['status'=>'queued','reason'=>'awaiting_worker'];};
consumerCheck(correspondenceCallConsumer(42,fn()=>$cap,$enqueue)['status']==='queued'&&$calls===[42],'adapter passes durable event reference only and preserves queued status');
$legacy=$cap;unset($legacy['source_event_type']);consumerCheck(correspondenceCallConsumer(43,fn()=>$legacy,$enqueue)['status']==='unsupported'&&$calls===[42],'RED: consumer without exact log-only carrier agreement must not invoke');
$bad=$cap;$bad['version']=2;consumerCheck(correspondenceCallConsumer(43,fn()=>$bad,$enqueue)['status']==='unsupported'&&$calls===[42],'unknown capability must not invoke');
consumerCheck(correspondenceCallConsumer(44,fn()=>$cap,fn($id)=>['status'=>'processed','reason'=>'no_change'])['status']==='processed','confirmed public processed status preserved');
consumerCheck(correspondenceCallConsumer(45,fn()=>$cap,fn($id)=>['status'=>'invented','reason'=>'secret text'])===['status'=>'unavailable','reason'=>'invalid_consumer_result'],'unknown/raw consumer result must be sanitized');
echo "PASS server consumers: public reference-only capability negotiation, unavailable/unsupported/queued/processed separation\n";
