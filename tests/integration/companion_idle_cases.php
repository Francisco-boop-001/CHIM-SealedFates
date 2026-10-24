<?php
declare(strict_types=1);
require_once $root.'/server/lib/companion_host.php';
require_once $root.'/server/lib/offscreen_host.php';
companionPgCheck(getenv('CORRESPONDENCE_COMPANION_IDLE_MODE')==='1','explicit_idle_fixture_mode_required');
require_once $root.'/tests/support/correspondence_core_function.php';
$core=getenv('CORRESPONDENCE_COMPANION_IDLE_CORE');
companionPgCheck(is_string($core)&&is_dir($core),'explicit_copied_core_required');
correspondenceLoadPinnedFunction($core.'/data_functions.php','50338dab670806b37600ec3a65e1762ee638a0c1656262ced979b4aa5cfc3a0c','DataDequeue');
correspondenceLoadPinnedFunction($core.'/chim_interaction.php','0f85286f47da7f02b8527ba9099e3d93452185399a9c0f66918072b877ccb6de','chimInteractionIsGameOutput');
$GLOBALS['chim_interaction_generation']=7;
$GLOBALS['COMPANION_IDLE_INTERACTION_ENABLED']=true;
function chimInteractionAllowed():bool{return $GLOBALS['COMPANION_IDLE_INTERACTION_ENABLED']&&($GLOBALS['chim_interaction_generation']??null)===($GLOBALS['COMPANION_IDLE_EXPECTED_GENERATION']??7);}
function logEvent(array $request):void {} // The copied request branch's optional telemetry has no campaign destination.
function companionIdleDequeue(PgSql\Connection $connection):array
{
    $comm=file_get_contents(getenv('CORRESPONDENCE_COMPANION_IDLE_CORE').'/comm.php');
    companionPgCheck(is_string($comm)&&hash('sha256',$comm)==='fc0a3f75bac47f8899a7c1c550aa1dafc3a59fdf36de39c0f1cb7722e432612c','exact core request processor required');
    $start=strpos($comm,'} elseif ($gameRequest[0] == "request") { // Just requested response');
    $start=$start===false?false:strpos($comm,"\n",$start);
    $end=$start===false?false:strpos($comm,'    // NEW METHODS FROM HERE',$start);
    companionPgCheck(is_int($start)&&is_int($end),'exact request response branch found');
    $branch=substr($comm,$start+1,$end-$start-1);
    $prior=$GLOBALS['db'];
    $GLOBALS['db']=new class($connection){
        public array $claimed=[];
        public function __construct(private PgSql\Connection $connection){}
        public function fetchAll(string $sql):array{return $this->claimed=companionPgSql($this->connection,$sql);}
    };
    ob_start();
    try{
        $gameRequest=['request','100','30000000','synthetic','60'];$MUST_END=false;
        eval($branch);
        companionPgCheck($MUST_END===true,'core heartbeat remains a fast non-model response');
        $output=ob_get_contents();$rows=[];
        foreach(explode("\r\n",$output) as $line){
            if($line==='')continue;
            $parts=explode('|',$line,3);
            $matches=array_values(array_filter($GLOBALS['db']->claimed,static fn(array $r):bool=>$r['actor']===$parts[0]&&$r['action']===$parts[1]&&$r['text']===$parts[2]));
            companionPgCheck(count($matches)===1,'native-style split retains exactly one actual core-emitted row');
            $rows[]=$matches[0];
        }
        return $rows;
    }finally{ob_end_clean();$GLOBALS['db']=$prior;}
}
companionPgSql($db,'ALTER TABLE public.responselog ADD COLUMN rowid bigint GENERATED ALWAYS AS (id) STORED, ADD COLUMN interaction_generation bigint NOT NULL DEFAULT 0');
companionPgCheck($store->observedExecutor()===null,'no NPC dialogue presence exists');
$episode=null;$first=null;$document=null;$calls=0;
for($hour=0;$hour<=61;$hour++){
    $gamets=30000000+$hour*416667;$ts=100+$hour*2;
    $heartbeat=['request',(string)$ts,(string)$gamets,'(Context location: synthetic solo cell)','60'];
    $poll=correspondenceCompanionPollNativeRequest($heartbeat,static fn():array=>['store'=>$store,'companion'=>$companion]);
    companionPgCheck($poll['status']==='queued','idle heartbeat must issue a challenge with no fresh managed NPC');
    companionPgCheck(correspondenceCompanionPollNativeRequest($heartbeat,static fn():array=>['store'=>$store,'companion'=>$companion])['status']==='idle','duplicate heartbeat remains on poll cooldown');
    $queued=companionPgSql($db,"SELECT actor,action,text FROM public.responselog WHERE action='command' AND text LIKE 'ExtCmdCHIMCorrespondenceCompanion_Observe@%' ORDER BY id DESC LIMIT 1")[0]??[];
    companionPgCheck(($queued['actor']??null)==='The Narrator','observer uses the built-in player-backed carrier');
    $returned=companionIdleDequeue($db);
    companionPgCheck(count($returned)===1&&$returned[0]['actor']==='The Narrator'&&(int)$returned[0]['interaction_generation']===7,'actual core request dequeue returns the observer at current interaction generation');
    $line=$returned[0]['actor'].'|'.$returned[0]['action'].'|'.$returned[0]['text'];
    $nativeParts=explode('|',$line,3);
    companionPgCheck($nativeParts[1]==='command'&&$nativeParts[2]===$queued['text'],'core actor-channel-payload framing preserves exact observer JSON without trailing delimiter');
    $issued=json_decode(substr($nativeParts[2],strpos($nativeParts[2],'@')+1),true,16,JSON_THROW_ON_ERROR);
    $minute=correspondenceCompanionGameMinute($gamets);$episode??=$issued['observation_id'];$first??=$minute;
    $reply=$issued;$reply['actors']=[['id'=>'900001','refid'=>$issued['actors'][0]['refid'],'state'=>'waiting','episode'=>$episode,'first_game_minute'=>$first,'game_minute'=>$minute]];
    $receipt=['correspondence_companion',(string)($ts+1),(string)$gamets,correspondenceJson($reply)];
    companionPgCheck($companion->receive($receipt,$reply)['status']==='processed','idle correlated native sample accepted');
    if($hour===48){
        $decision=$companion->tick(static function()use(&$calls):string{$calls++;return '{"action":"letter","recipient":"player","body":"Dragonborn, I am waiting for you. What would you have me do? Camilla"}';});
        companionPgCheck($decision['status']==='queued'&&$calls===1,'48h idle episode queues one player letter');
        $document=companionPgSql($db,"SELECT document_id::text FROM plugins.correspondence_companion_decision WHERE status='queued'")[0]['document_id']??null;
        companionPgCheck(is_string($document),'idle owned document exists');
        companionPgReject(fn()=>$service->dispatch($document),'manual send bypassed scheduler');
        companionPgReject(fn()=>$service->retry($document),'manual retry bypassed scheduler');
    }
    if($hour===60){
        companionPgCheck($companion->tick(null)['status']==='dispatched','due idle letter dispatches without dialogue presence');
        $wire=companionPgSql($db,"SELECT actor,action,text FROM public.responselog WHERE action='command' AND text LIKE 'ExtCmdCHIMCorrespondenceBridge_Deliver@%' ORDER BY id DESC LIMIT 1")[0]??[];
        companionPgCheck(($wire['actor']??null)==='The Narrator','delivery uses player-backed carrier');
        $returned=companionIdleDequeue($db);
        companionPgCheck(count($returned)===2,'core returns renderer and physical delivery');
        foreach($returned as $entry){
            $parts=explode('|',$entry['actor'].'|'.$entry['action'].'|'.$entry['text'],3);
            if($parts[1]==='command'){
                $payload=json_decode(substr($parts[2],strpos($parts[2],'@')+1),true,16,JSON_THROW_ON_ERROR);
                companionPgCheck($payload['document_id']===$document,'delivery wire retains exact bound JSON');
            }else companionPgCheck($parts[1]==='rolecommand'&&$parts[2]==='generateLetter@letter '.$document,'renderer wire retains exact title without trailing delimiter');
        }
        $operation=companionPgSql($db,'SELECT operation_id::text FROM plugins.correspondence_operations WHERE document_id=$1',[$document])[0]['operation_id']??null;
        companionPgCheck(is_string($operation),'idle delivery has one durable operation');
        companionPgCheck(($service->dispatch($document,true)['operation_id']??null)===$operation,'idle read-only reconcile reuses the original operation');
        $returned=companionIdleDequeue($db);
        companionPgCheck(count($returned)===1&&str_starts_with($returned[0]['text'],'ExtCmdCHIMCorrespondenceBridge_Reconcile@'),'actual core returns one read-only reconciliation');
        $reconcile=json_decode(substr($returned[0]['text'],strpos($returned[0]['text'],'@')+1),true,16,JSON_THROW_ON_ERROR);
        companionPgCheck($reconcile['operation_id']===$operation,'reconciliation wire retains original operation');
    }
    if($hour===61){
        companionPgCheck($companion->tick(static function()use(&$calls):string{$calls++;return '{"action":"none","recipient":"player","body":""}';})['status']!=='dispatched'&&$calls===1,'idle retries do not resend or regenerate');
    }
}
companionPgCheck((companionPgSql($db,'SELECT count(*)::int n FROM plugins.correspondence_presence')[0]['n']??null)==='0','idle flow never fabricates a presence snapshot');
companionPgCheck((companionPgSql($db,'SELECT count(*)::int n FROM plugins.correspondence_operations')[0]['n']??null)==='1','idle flow creates exactly one operation');
$ordinary=$service->compose(['request_id'=>correspondenceUuid(),'author'=>'player','payer'=>'player','recipient'=>'npc:900001','claimed_sender'=>'player','body'=>'A normal manual letter.','attachments'=>[]]);
companionPgCheck(($service->dispatch($ordinary['document_id'],false,true)['reason']??null)==='executor_unavailable','scheduler flag cannot give an ordinary manual letter a Narrator bypass');
$service->dispatch($document,true);
$GLOBALS['chim_interaction_generation']=8;$GLOBALS['COMPANION_IDLE_EXPECTED_GENERATION']=8;
companionPgCheck(companionIdleDequeue($db)===[],'core drops previously queued output after Off/On generation change');
$service->dispatch($document,true);
$renewed=companionIdleDequeue($db);
companionPgCheck(count($renewed)===1&&(int)$renewed[0]['interaction_generation']===8,'new reconciliation is usable after generation changes');
$store->observeManaged($presence);
$expiredAt=time()-100;
companionPgSql($db,"UPDATE plugins.correspondence_presence SET payload=jsonb_set(payload,'{observed_at}',$1::jsonb)",[(string)$expiredAt]);
companionPgCheck($store->observedExecutor()===null,'fixture presence really expired');
$later=['request','500',(string)(30000000+70*416667),'synthetic solo cell','60'];
companionPgCheck(correspondenceCompanionPollNativeRequest($later,static fn():array=>['store'=>$store,'companion'=>$companion])['status']==='queued','expired NPC snapshot does not block a later idle challenge');
companionPgCheck((companionPgSql($db,"SELECT payload->>'observed_at' stamp FROM plugins.correspondence_presence")[0]['stamp']??null)===(string)$expiredAt,'idle polling does not renew stale NPC presence');
$GLOBALS['COMPANION_IDLE_INTERACTION_ENABLED']=false;
$noFactory=false;
$off=correspondenceCompanionPollNativeRequest(['request','999','90000000','synthetic','60'],static function()use(&$noFactory):array{$noFactory=true;return [];});
companionPgCheck($off['status']==='paused'&&!$noFactory,'interaction off does not enter companion runtime');
companionPgCheck($companion->tick(static function():string{throw new RuntimeException('no model while interaction off');})['status']==='paused','interaction off pauses worker before deciding or dispatching');
$queuedBefore=companionPgSql($db,'SELECT count(*)::int n FROM public.responselog')[0]['n'];
companionPgReject(static fn()=>$store->transaction(static fn()=>$store->queueCommand('The Narrator','command|blocked')), 'off queue writer must refuse');
$GLOBALS['COMPANION_IDLE_INTERACTION_ENABLED']=true;
$GLOBALS['chim_interaction_generation']=7;
companionPgReject(static fn()=>$store->transaction(static fn()=>$store->queueCommand('The Narrator','command|stale')), 'stale captured generation must refuse');
$GLOBALS['chim_interaction_generation']=null;$GLOBALS['COMPANION_IDLE_EXPECTED_GENERATION']=null;
companionPgReject(static fn()=>$store->transaction(static fn()=>$store->queueCommand('The Narrator','command|missing')), 'missing captured generation must refuse even if helper allows');
$GLOBALS['chim_interaction_generation']=8;$GLOBALS['COMPANION_IDLE_EXPECTED_GENERATION']=8;
companionPgReject(static fn()=>$store->transaction(static fn()=>$store->queueCommand('The Narrator','malformed')), 'malformed channel must refuse');
companionPgCheck(companionPgSql($db,'SELECT count(*)::int n FROM public.responselog')[0]['n']===$queuedBefore,'rejected writes never enqueue commands');

echo "companion_idle_cases PASS: solo heartbeat, no presence, Narrator observe/deliver/reconcile, one model and one operation\n";
