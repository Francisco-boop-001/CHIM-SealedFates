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
$actors=[['key'=>'player','name'=>'Dragonborn'],['key'=>'npc:1','name'=>'<Camilla>'],['key'=>'npc:2','name'=>'Éldrin 🦙']];
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
$openedHtml=correspondencePage($actors,[],[],$token,'',['state'=>'exposed','recipient'=>['kind'=>'npc','name'=>'Camilla'],'claimed_sender'=>['kind'=>'player','name'=>'Dragonborn'],'body'=>'<script>alert("fixture")</script> & Unicode 🦙','attachments'=>[]]);
uiCheck(str_contains($openedHtml,'&lt;script&gt;alert(&quot;fixture&quot;)&lt;/script&gt;')&&!str_contains($openedHtml,'<script>')&&str_contains($openedHtml,'Unicode 🦙'),'opened letter body must escape hostile markup and preserve Unicode');
$uiRoot=dirname(__DIR__);
$emptyHtml=correspondencePage($actors,[],[],$token);
uiCheck(str_contains($html,'<!doctype html><html lang="en"><head>')&&str_contains($html,'<body><a class="skip-link" href="#main-content">Skip to main content</a><main class="page" id="main-content">')&&str_contains($html,'</main></body></html>'),'renderer must return a complete document with a safe main content region');
uiCheck(str_contains($html,'<link rel="stylesheet" href="assets/style.css">')&&!str_contains($html,'<style>'),'page styling must use the same-origin stylesheet');
uiCheck(str_contains($html,'src="dashboard-art.webp"')&&str_contains($html,'alt="Nordic woodcut scene with a courier carrying letters, an affectionate woman and an outraged man below an emo llama over a doorway."')&&str_contains($html,'width="1536"')&&str_contains($html,'height="1024"'),'hero art must keep its full 3:2 dimensions and descriptive alternative text');
uiCheck(str_contains($html,'Part of the World of Drama-Llama')&&str_contains($html,'The new Skyrim interface is optional; this web page still works. The courier is required to cope.'),'hero must identify its world and keep the optional interface note compact');
uiCheck(str_contains($html,'Éldrin 🦙')&&str_contains($html,'&lt;Camilla&gt;')&&!str_contains($html,'<script>'),'Unicode and unsafe actor names must render safely');
uiCheck(str_contains($html,'method="post"')&&str_contains($html,'name="csrf"')&&str_contains($html,'name="request_id"')&&str_contains($html,'name="recipient"')&&str_contains($html,'name="claimed_sender"')&&str_contains($html,'name="author"')&&str_contains($html,'name="payer"')&&str_contains($html,'name="body"')&&str_contains($html,'name="item[]"')&&str_contains($html,'name="quantity[]"'),'compose action must retain its current POST field names');
uiCheck(str_contains($html,'name="action" value="compose"')&&str_contains($html,'name="action" value="read"'),'existing compose and operator-read actions must remain available');
uiCheck(str_contains($emptyHtml,'No letters yet.')&&str_contains($emptyHtml,'No automated outcomes yet.'),'empty mailbox and activity tables must explain that they are empty');
$cssPath=$uiRoot.'/server/assets/style.css';
$css=is_file($cssPath)?file_get_contents($cssPath):false;
uiCheck(is_string($css)&&str_contains($css,'--paper')&&str_contains($css,'--rust')&&str_contains($css,'--slate')&&str_contains($css,'prefers-color-scheme: dark'),'stylesheet must provide the parchment palette and dark color scheme');
uiCheck(is_string($css)&&str_contains($css,'overflow-x: auto')&&str_contains($css,'overflow-wrap: anywhere')&&str_contains($css,'@media (max-width:')&&str_contains($css,'height: auto')&&!str_contains($css,'object-fit: cover')&&str_contains($css,'.skip-link:focus'),'tables and controls must adapt on small screens while keeping the illustration uncropped');
$indexSource=file_get_contents($uiRoot.'/server/index.php');
$policy="default-src 'none'; img-src 'self'; style-src 'self'; form-action 'self'; frame-ancestors 'self'";
uiCheck(is_string($indexSource)&&str_contains($indexSource,$policy)&&!str_contains($indexSource,"'unsafe-inline'")&&!preg_match('/\\b(?:script-src|connect-src)\\b/',$indexSource),'CSP must allow same-origin visual assets only and keep scripts and network sources blocked');
uiCheck(str_contains($indexSource,"str_replace('</main>'")&&!str_contains($indexSource,"str_replace('</body>'"),'offscreen panel must be inserted inside the main wrapper');
$errorHtml=correspondenceErrorPage('<unsafe>');
uiCheck(str_contains($errorHtml,'href="assets/style.css"')&&str_contains($errorHtml,'&lt;unsafe&gt;')&&!str_contains($errorHtml,'<unsafe>'),'error page must share the safe styled document shell');
$renderer=file_get_contents($uiRoot.'/tests/render_ui.php');
uiCheck(is_string($renderer)&&str_contains($renderer,"'populated'")&&str_contains($renderer,"'empty'")&&str_contains($renderer,"'opened'")&&str_contains($renderer,"'error'")&&str_contains($renderer,"'long-unicode'")&&str_contains($renderer,'$argv[1]')&&str_contains($renderer,'correspondenceErrorPage')&&!str_contains($renderer,'lib/bootstrap.php')&&!str_contains($renderer,'correspondenceAutonomyProduction')&&!str_contains($renderer,'->list()'),'fixture renderer must expose static page states without production storage calls');
uiCheck(substr_count($html,'class="table-wrap" tabindex="0" role="region" aria-labelledby=')===2&&str_contains($html,'<div class="table-wrap" tabindex="0" role="region" aria-labelledby="mailbox-title">')&&str_contains($html,'<div class="table-wrap" tabindex="0" role="region" aria-labelledby="autonomy-outcomes-title">'),'both horizontal table scrollers must be named keyboard-focusable regions');
echo "PASS server UI: authentication, CSRF, HTML escaping, independent payer/sender, explicit player read\n";

