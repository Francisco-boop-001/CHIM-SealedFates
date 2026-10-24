<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/server/lib/companion_policy.php';
require_once dirname(__DIR__).'/server/lib/companion_host.php';
require_once dirname(__DIR__).'/server/lib/offscreen_host.php';

function companionCheck(bool $ok,string $why):void { if(!$ok)throw new RuntimeException($why); }
function companionReject(callable $work,string $why):void
{
    try{$work();}catch(InvalidArgumentException|JsonException){return;}
    throw new RuntimeException($why);
}

$obs='11111111-1111-4111-8111-111111111111';
$identity=['id'=>'900001','plugin'=>'Skyrim.esm','local_id'=>0x13488,'refid'=>0x13488];
$request=['version'=>1,'observation_id'=>$obs,'playthrough_id'=>'unprofiled','epoch'=>'22222222-2222-4222-8222-222222222222','nonce'=>str_repeat('a',64),'actors'=>[$identity]];
$reply=$request;
$reply['actors']=[['id'=>'900001','refid'=>0x13488,'state'=>'waiting','episode'=>$obs,'first_game_minute'=>4320,'game_minute'=>4320]];
$gamets=30000000;
companionCheck(correspondenceCompanionGameMinute($gamets)===4320,'game clock conversion');
companionCheck(correspondenceCompanionEpoch('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa:db-only')!==correspondenceCompanionEpoch('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa:new-runtime'),'full restore token changes wire epoch');
companionCheck(count(correspondenceCompanionReply($reply,$request,$gamets))===1,'valid correlated reply');
companionReject(fn()=>correspondenceCompanionReply($reply+['extra'=>true],$request,$gamets),'extra top-level field');
$bad=$reply;$bad['nonce']=str_repeat('b',64);companionReject(fn()=>correspondenceCompanionReply($bad,$request,$gamets),'nonce mismatch');
$bad=$reply;$bad['actors'][0]['game_minute']=4323;companionReject(fn()=>correspondenceCompanionReply($bad,$request,$gamets),'clock outside one minute');
$bad=$reply;$bad['actors'][0]['refid']=-1;companionReject(fn()=>correspondenceCompanionReply($bad,$request,$gamets),'actor identity mismatch');
$bad=$reply;$bad['actors'][0]['state']='unknown';companionReject(fn()=>correspondenceCompanionReply($bad,$request,$gamets),'unknown cannot retain episode');
companionReject(fn()=>correspondenceCompanionIdentity($identity+['name'=>'Camilla']),'identity extra field');
companionCheck(correspondenceCompanionDecision('{"action":"none","recipient":"player","body":""}')['action']==='none','silence');
companionCheck(correspondenceCompanionDecision('{"action":"letter","recipient":"player","body":"I hope you are safe."}')['action']==='letter','letter');
foreach(['{"action":"letter","recipient":"npc:900002","body":"Hello"}','{"action":"letter","recipient":"Dragonborn","body":"Hello"}','{"action":"letter","recipient":"player","body":"You abandoned me."}','{"action":"letter","recipient":"player","body":"I have waited exactly three days."}','{"action":"letter","recipient":"player","body":"I have waited four days."}','{"action":"letter","recipient":"player","body":"It has been a week."}','{"action":"letter","recipient":"player","body":"It has been 96 hours."}','{"action":"letter","recipient":"player","body":"I have waited since Monday."}','{"action":"letter","recipient":"player","body":"I am holding the spot where we parted."}','{"action":"letter","recipient":"player","body":"Meet me at the meeting place."}','{"action":"letter","recipient":"player","body":"No news, no trouble; I will keep watch."}','{"action":"letter","recipient":"player","body":"There is work yet."}','{"action":"letter","recipient":"player","body":"'.str_repeat('x',701).'"}','{"action":"letter","action":"none","recipient":"player","body":"Hello"}','{"action":"none","recipient":"player","body":"Hello"}'] as $raw)companionReject(fn()=>correspondenceCompanionDecision($raw),'invalid decision accepted');
$messages=correspondenceCompanionMessages('Camilla','Dragonborn','Warm.',25.0);
companionCheck(count($messages)===2&&str_contains($messages[0]['content'],'continuous absence')&&str_contains($messages[0]['content'],'untrusted style')&&!str_contains($messages[1]['content'],'25')
    &&str_contains($messages[0]['content'],'MUST be exactly the literal string "player"')&&str_contains($messages[0]['content'],'{"action":"none","recipient":"player","body":""}')
    &&str_contains($messages[0]['content'],'The only fact the letter may state is that the NPC is currently waiting for the player.')
    &&str_contains($messages[0]['content'],'at most 50 words total')&&str_contains($messages[0]['content'],'Unknown history, locations, activities and world conditions are not topics')
    &&str_contains($messages[0]['content'],'Do not add scene-setting or an update about events.'),'bounded evidence and literal wire schema prompt');
$native=['correspondence_companion','101',(string)$gamets,correspondenceJson($reply)];
companionCheck(!correspondenceOffscreenNativeStartTrigger($native)&&correspondenceOffscreenNativeStartTrigger($native,true),'only validated companion receipt can trigger worker start');
$resumeCalls=[];
$host=correspondenceCompanionReceiveNativeRequest($native,static fn():array=>['companion'=>new class {
    public function receive(array $request,array $reply):array {return ['status'=>'processed','reason'=>'observation_accepted'];}
}],static function(array $request,bool $validated)use(&$resumeCalls):array {$resumeCalls[]=[$request,$validated];return ['status'=>'spawn_requested'];});
companionCheck($host['status']==='processed'&&$resumeCalls===[[$native,true]],'accepted correlated receipt wakes worker through trusted start path');
$rejected=correspondenceCompanionReceiveNativeRequest(['correspondence_companion','bad',(string)$gamets,'{}'],static fn():array=>[],static function()use(&$resumeCalls):array {$resumeCalls[]='unsafe';return ['status'=>'spawn_requested'];});
companionCheck($rejected['status']==='rejected'&&count($resumeCalls)===1,'invalid companion receipt cannot trigger worker');
$heartbeat=['request','102',(string)$gamets,'(Context location: synthetic)','60'];
$bound=[];
$poll=correspondenceCompanionPollNativeRequest($heartbeat,static function()use(&$bound):array{return [
    'store'=>new class($bound) { public function __construct(public array &$bound) {} public function bindRequestClock(array $request):void {$this->bound[]=$request;} },
    'companion'=>new class {public function poll():array{return ['status'=>'queued','reason'=>'poll_issued'];}},
];});
companionCheck($poll['status']==='queued'&&$bound===[$heartbeat],'idle native request polls without dialogue or NPC presence');
companionCheck(!correspondenceOffscreenNativeObservation($heartbeat),'idle request does not become a general BGL source');
$badHeartbeat=$heartbeat;$badHeartbeat[1]='bad';$factoryCalled=false;
$badPoll=correspondenceCompanionPollNativeRequest($badHeartbeat,static function()use(&$factoryCalled):array{$factoryCalled=true;return [];});
companionCheck(in_array($badPoll['status'],['skipped','failed'],true)&&!$factoryCalled,'malformed idle clock cannot open a runtime');
echo "server_companion_policy PASS\n";
