<?php
declare(strict_types=1);
function uiCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
uiCheck(is_file(dirname(__DIR__).'/server/lib/view.php'),'RED: CSRF composer and recipient inbox view missing');
require_once dirname(__DIR__).'/server/lib/view.php';
$token=str_repeat('a',64);
uiCheck(correspondenceAccess(['REMOTE_ADDR'=>'127.0.0.1']),'loopback should access');
uiCheck(correspondenceAccess(['REMOTE_ADDR'=>'198.51.100.10','REMOTE_USER'=>'operator']),'server authenticated user should access');
uiCheck(!correspondenceAccess(['REMOTE_ADDR'=>'192.168.1.4']),'private address is not authentication');
uiCheck(!correspondenceAccess(['REMOTE_ADDR'=>'127.0.0.1','HTTP_X_FORWARDED_FOR'=>'127.0.0.1']),'forwarded headers must not confer trust');
try{correspondencePost(['csrf'=>'bad'],$token,[]);throw new RuntimeException('CSRF accepted');}catch(DomainException $e){uiCheck($e->getMessage()==='csrf_failed','CSRF must reject first');}
$actors=[['key'=>'player','name'=>'Dragonborn'],['key'=>'npc:1','name'=>'<Camilla>']];
$html=correspondencePage($actors,[],[['document_id'=>'11111111-1111-4111-8111-111111111111','state'=>'delivered','recipient'=>['kind'=>'player','name'=>'Dragonborn'],'claimed_sender'=>['name'=>'<script>bad</script>'],'body'=>'<img src=x onerror=bad>','attachments'=>[],'social_status'=>['sworn_and_scorned'=>['status'=>'queued','reason'=>'awaiting_worker']]]],$token,'<unsafe>');
uiCheck(str_contains($html,'<title>Sealed Fates</title>')&&str_contains($html,'<h1>Sealed Fates</h1>'),'public page uses Sealed Fates branding');
$brandStatus=correspondencePage($actors,[],[],$token,'Sealed Fates autonomous mode enabled.');
uiCheck(str_contains($brandStatus,'role="status">Sealed Fates autonomous mode enabled.</p>'),'status message uses Sealed Fates branding');
uiCheck(str_contains($html,'&lt;Camilla&gt;')&&!str_contains($html,'<script>'),'actor and sender output must escape');
uiCheck(!str_contains($html,'<img src=x'),'letter output must escape');
uiCheck(str_contains($html,'name="csrf"')&&str_contains($html,'name="claimed_sender"')&&str_contains($html,'name="payer"'),'composer identity and CSRF controls');
uiCheck(str_contains($html,'Open letter'),'player must explicitly open a delivered inbox document');
try{correspondencePost(['csrf'=>$token,'action'=>'receipt'],$token,[]);throw new RuntimeException('browser receipt accepted');}catch(DomainException $e){uiCheck($e->getMessage()==='invalid_action','browser cannot accept receipt');}
uiCheck(str_contains($html,'queued')&&str_contains($html,'awaiting worker'),'UI must display pending social readback without claiming completion');
echo "PASS server UI: authentication, CSRF, HTML escaping, independent payer/sender, explicit player read\n";

