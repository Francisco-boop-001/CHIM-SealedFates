<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/server/lib/view.php';
require_once dirname(__DIR__).'/server/lib/offscreen_host.php';

$isCli=PHP_SAPI==='cli';
$fixture=$isCli?($argv[1]??'populated'):($_GET['fixture']??'populated');
$fixtures=['populated','empty','opened','error','long-unicode'];
if(!is_string($fixture)||!in_array($fixture,$fixtures,true)){
    if($isCli){fwrite(STDERR,'Unknown UI fixture. Available: '.implode(', ',$fixtures).PHP_EOL);exit(2);}
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Unknown UI fixture.');
}

$csrf=str_repeat('a',64);
$actors=[['key'=>'player','name'=>'Dragonborn'],['key'=>'npc:1','name'=>'Camilla'],['key'=>'npc:2','name'=>'J\'Zargo']];
$choices=[['form_id'=>'Skyrim.esm|0000000F','name'=>'Gold','kind'=>'gold']];
$sourceId='11111111-1111-4111-8111-111111111111';
$childId='22222222-2222-4222-8222-222222222222';
$letters=[
    ['document_id'=>$sourceId,'state'=>'exposed','recipient'=>['kind'=>'npc','name'=>'Camilla'],'claimed_sender'=>['kind'=>'player','name'=>'Dragonborn'],'body'=>'The courier says nothing. The letter says everything.','attachments'=>[],'social_status'=>['sworn_and_scorned'=>['status'=>'queued','reason'=>'awaiting_worker']]],
    ['document_id'=>$childId,'state'=>'queued','recipient'=>['kind'=>'player','name'=>'Dragonborn'],'claimed_sender'=>['kind'=>'npc','name'=>'Camilla'],'body'=>'A reply is waiting for the next physical courier.','attachments'=>[['quantity'=>3,'form_id'=>'Skyrim.esm|0000000F']]],
];
$autonomyRows=[['source_document_id'=>$sourceId,'child_document_id'=>$childId,'status'=>'queued','action'=>'reply','due_gamets'=>5400,'reason'=>'dispatch_pending']];
$opened=null;
$enabled=true;

if($fixture==='empty'){
    $letters=[];
    $autonomyRows=[];
    $enabled=false;
}elseif($fixture==='opened'){
    $opened=['document_id'=>$sourceId,'state'=>'exposed','recipient'=>['kind'=>'npc','name'=>'Camilla'],'claimed_sender'=>['kind'=>'player','name'=>'Dragonborn'],'body'=>'Operator-only text with hostile markup: <script>alert("fixture")</script> & Unicode 🦙 — opening does not create NPC exposure.','attachments'=>[['quantity'=>3,'form_id'=>'Skyrim.esm|0000000F']]];
}elseif($fixture==='long-unicode'){
    $longName=str_repeat('Э',96).' 🦙 '.str_repeat('文',24);
    $actors[1]['name']=$longName;
    $letters[0]['claimed_sender']=['kind'=>'npc','name'=>$longName];
    $letters[0]['body']=str_repeat('文字 🦙 — a promise carried through snow. ',28);
    $opened=['document_id'=>$sourceId,'state'=>'exposed','recipient'=>['kind'=>'npc','name'=>'Camilla'],'claimed_sender'=>['kind'=>'npc','name'=>$longName],'body'=>str_repeat('文字 🦙 — a promise carried through snow. ',28),'attachments'=>[]];
}

if($fixture==='error'){
    $html=correspondenceErrorPage('Synthetic request unavailable.');
}else{
    $html=correspondencePage($actors,$choices,$letters,$csrf,'',$opened,['enabled'=>$enabled],$autonomyRows,[$childId]);
    $panel=correspondenceOffscreenPanelHtml($enabled,['status'=>$enabled?'running':'paused'],['last_observation'=>['gamets'=>5400,'type'=>'location'],'messages'=>['in_transit'=>1,'arrived'=>2,'exposed'=>1,'stale'=>0]]);
    $html=str_replace('</main>',$panel.'</main>',$html);
}

if(!$isCli){
    $html=str_replace(['href="assets/style.css"','src="dashboard-art.webp"'],['href="../server/assets/style.css"','src="../server/dashboard-art.webp"'],$html);
    header('Content-Type: text/html; charset=UTF-8');
}
echo $html;
