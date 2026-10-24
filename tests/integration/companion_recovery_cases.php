<?php
declare(strict_types=1);
$case=getenv('CORRESPONDENCE_COMPANION_RECOVERY_CASE');
companionPgCheck(in_array($case,['bgl-head','due-head','claim-owner'],true),'explicit_recovery_case_required');
$now=30000000+60*416667;
$request=['request','1000',(string)$now,'synthetic audit','60'];
$store->bindRequestClock($request);$peerStore->bindRequestClock($request);
$epoch=correspondenceCompanionEpoch($store->restoreGeneration());
companionPgSql($db,"INSERT INTO public.core_npc_master(id,npc_name,refid,profile_id,personality,extended_data,metadata) VALUES(900002,'Lydia','000A2C94',1,'Direct and patient.','{\"background_life_enabled\":true,\"background_life_letters\":true}','{\"stats\":{\"is_dead\":false}}')");
function companionRecoveryEpisode(int $id,int $sampleHour):string{
    global $db,$store,$epoch;
    $ep=correspondenceUuid();$identity=['id'=>(string)$id]+$store->physicalActor(['kind'=>'npc','id'=>$id]);
    $first=correspondenceCompanionGameMinute(30000000);$sample=30000000+$sampleHour*416667;
    companionPgSql($db,'INSERT INTO plugins.correspondence_companion_episode(playthrough_id,epoch,actor_id,episode_id,first_game_minute,native_first_game_minute,game_minute,sample_gamets,identity) VALUES(\'unprofiled\',$1::uuid,$2,$3::uuid,$4,$4,$5,$6,$7::jsonb)',[$epoch,(string)$id,$ep,(string)$first,(string)correspondenceCompanionGameMinute($sample),(string)$sample,correspondenceJson($identity)]);
    return $ep;
}
function companionRecoveryOwned(int $id,string $ep,int $dueHour):string{
    global $db,$service,$epoch;
    $requestId=correspondenceUuid();
    $doc=$service->compose(['request_id'=>$requestId,'author'=>'npc:'.$id,'payer'=>'npc:'.$id,'recipient'=>'player','claimed_sender'=>'npc:'.$id,'body'=>'Dragonborn, I am waiting for you. What should I do?','attachments'=>[]]);
    companionPgSql($db,'INSERT INTO plugins.correspondence_companion_decision(playthrough_id,epoch,actor_id,episode_id,request_id,attempted,status,document_id,due_gamets,claimed_gamets,reason_code) VALUES(\'unprofiled\',$1::uuid,$2,$3::uuid,$4::uuid,TRUE,\'queued\',$5::uuid,$6,$7,\'queued_for_transit\')',[$epoch,(string)$id,$ep,$requestId,$doc['document_id'],(string)(30000000+$dueHour*416667),'30000000']);
    return $doc['document_id'];
}
if($case==='bgl-head'){
    companionRecoveryEpisode(900001,60);companionRecoveryEpisode(900002,60);
    companionPgSql($db,"INSERT INTO public.bgl_history(npc,ts,gamets,localts,data,category) VALUES('Camilla',1,$1,1,'synthetic recent player letter','letter')",[(string)($now-416667)]);
    $calls=0;$result=$companion->tick(static function()use(&$calls):string{$calls++;return '{"action":"letter","recipient":"player","body":"Dragonborn, I am waiting for you. Lydia"}';});
    companionPgCheck($result['status']==='queued'&&$calls===1,'BH-02: skip per-NPC BGL deferral and queue the other eligible companion');
    $row=companionPgSql($db,'SELECT actor_id::text FROM plugins.correspondence_companion_decision')[0];
    companionPgCheck($row['actor_id']==='900002','only non-deferred actor owns the decision');
    $repeat=$companion->tick(static function():string{throw new RuntimeException('global cooldown must prevent callback');});
    companionPgCheck($repeat['status']==='idle'&&$repeat['reason']==='cooldown','global cooldown remains enforced after skipping an actor');
}
if($case==='due-head'){
    $a=companionRecoveryEpisode(900001,50);$b=companionRecoveryEpisode(900002,60);
    $first=companionRecoveryOwned(900001,$a,55);$second=companionRecoveryOwned(900002,$b,59);
    $result=$companion->tick(null);
    companionPgCheck($result['status']==='dispatched','BH-01: dispatch the later fresh due letter despite older unready mail');
    companionPgCheck(count(companionPgSql($db,'SELECT 1 FROM plugins.correspondence_operations WHERE document_id=$1',[$second]))===1,'fresh letter dispatched exactly once');
    companionPgCheck(companionPgSql($db,'SELECT 1 FROM plugins.correspondence_operations WHERE document_id=$1',[$first])===[],'unready letter never dispatched');
    companionPgCheck((companionPgSql($db,'SELECT status FROM plugins.correspondence_companion_decision WHERE document_id=$1',[$first])[0]['status']??null)==='queued','unready letter retained pending');
    companionPgSql($db,"INSERT INTO public.core_npc_master SELECT 900003,'Serana','0002B74C',profile_id,personality,extended_data,metadata FROM public.core_npc_master WHERE id=900002");
    companionRecoveryEpisode(900003,60);
    $calls=0;$next=$companion->tick(static function()use(&$calls):string{$calls++;return '{"action":"letter","recipient":"player","body":"Dragonborn, I am waiting for you. Serana"}';});
    companionPgCheck($next['status']==='queued'&&$calls===1,'unready due mail does not block another eligible episode');
    companionPgCheck(count(companionPgSql($db,'SELECT 1 FROM plugins.correspondence_operations'))===1,'subsequent tick does not resend delivered operation');
    companionPgSql($db,'UPDATE plugins.correspondence_companion_episode SET sample_gamets=$1 WHERE actor_id=900001',[(string)($now+416667)]);
    companionPgCheck($companion->tick(null)['status']==='idle','future sample cannot authorize physical dispatch');
    companionPgCheck(count(companionPgSql($db,'SELECT 1 FROM plugins.correspondence_operations'))===1,'future or stale sample creates no extra physical operation');
}
if($case==='claim-owner'){
    companionRecoveryEpisode(900001,60);
    $code=file_get_contents($root.'/server/lib/companion.php');
    $needle='        $marked=$this->store->transaction(function()use($work):bool {';
    companionPgCheck(is_string($code)&&substr_count($code,$needle)===1,'exact pre-mark seam required');
    $code=str_replace(['final class CorrespondenceCompanionPg',"require_once __DIR__.'/companion_policy.php';"],['final class CorrespondenceCompanionRecoveryPg',"require_once ".var_export($root.'/server/lib/companion_policy.php',true).';'],$code);
    $code=str_replace($needle,"        if(isset(\$GLOBALS['COMPANION_RECOVERY_BEFORE_MARK']))(\$GLOBALS['COMPANION_RECOVERY_BEFORE_MARK'])(\$work);\n".$needle,$code);
    eval(substr($code,5)); // Test-only scheduling hook; product SQL and conditions remain unchanged.
    $oldWorker=new CorrespondenceCompanionRecoveryPg($store,$service);
    $newWorker=new CorrespondenceCompanionRecoveryPg($peerStore,$peerService);
    $oldWork=null;$newWork=null;$oldCalls=0;
    $GLOBALS['COMPANION_RECOVERY_BEFORE_MARK']=static function(array $work)use(&$oldWork,&$newWork,$newWorker,$db):void{
        $oldWork=$work;
        companionPgSql($db,"UPDATE plugins.correspondence_companion_decision SET created_at=clock_timestamp()-INTERVAL '121 seconds' WHERE request_id=$1",[$work['request_id']]);
        $GLOBALS['COMPANION_RECOVERY_BEFORE_MARK']=static function(array $replacement)use(&$newWork):void{$newWork=$replacement;throw new RuntimeException('simulated_new_worker_pause');};
        try{$newWorker->tick(static function():string{throw new RuntimeException('paused replacement must not call model');});}
        catch(RuntimeException $error){companionPgCheck($error->getMessage()==='simulated_new_worker_pause','controlled pause only');}
        unset($GLOBALS['COMPANION_RECOVERY_BEFORE_MARK']);
    };
    $result=$oldWorker->tick(static function()use(&$oldCalls):string{$oldCalls++;return '{"action":"letter","recipient":"player","body":"Dragonborn, I am waiting for you. Camilla"}';});
    companionPgCheck($oldWork['request_id']!==$newWork['request_id']&&$result['status']==='stale'&&$oldCalls===0,'BH-03: displaced owner cannot mark replacement claim or call model');
    $row=companionPgSql($db,'SELECT request_id::text,attempted,status,document_id::text FROM plugins.correspondence_companion_decision')[0];
    companionPgCheck($row['request_id']===$newWork['request_id']&&$row['attempted']==='f'&&$row['status']==='claimed'&&$row['document_id']===null,'replacement claim remains untouched');
    companionPgSql($db,"UPDATE plugins.correspondence_companion_decision SET created_at=clock_timestamp()-INTERVAL '121 seconds'");
    $recovered=$companion->tick(static fn():string=>'{"action":"letter","recipient":"player","body":"Dragonborn, I am waiting for you. Camilla"}');
    $row=companionPgSql($db,'SELECT request_id::text,document_id::text FROM plugins.correspondence_companion_decision')[0];
    companionPgCheck($recovered['status']==='queued'&&$store->loadDocument($row['document_id'])['request_id']===$row['request_id'],'valid recovery preserves request ownership');
    companionPgSql($db,'DELETE FROM plugins.correspondence_companion_decision');
    $before=count(companionPgSql($db,'SELECT 1 FROM plugins.correspondence_documents'));
    $late=$companion->tick(static function()use($peer):string{
        companionPgSql($peer,'UPDATE plugins.correspondence_companion_decision SET request_id=$1::uuid',[correspondenceUuid()]);
        return '{"action":"letter","recipient":"player","body":"Dragonborn, I am waiting for you. Camilla"}';
    });
    companionPgCheck($late['status']==='stale'&&count(companionPgSql($db,'SELECT 1 FROM plugins.correspondence_documents'))===$before,'late completion cannot write a replacement request');
}
echo 'companion_recovery_cases PASS '.$case."\n";
