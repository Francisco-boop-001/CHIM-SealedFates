<?php
declare(strict_types=1);

companionPgCheck(getenv('CORRESPONDENCE_COMPANION_REAL_APPROVED_HOST')==='api.deepseek.com','approved_model_host_required');
$callbackPath='/mnt/k/ActorwrightExchange/artifacts/chim-sealed-fates-companion-20261024/real-model-callback.php';
companionPgCheck(getenv('CORRESPONDENCE_COMPANION_REAL_CALLBACK')===$callbackPath&&is_file($callbackPath),'exact_private_callback_required');
$fixturePath=realpath($root.'/tests/fixtures/companion-held-out-v1.json');
companionPgCheck(is_string($fixturePath)&&getenv('CORRESPONDENCE_COMPANION_REAL_CASES')===$fixturePath,'exact_preregistered_cases_required');
$fixture=json_decode((string)file_get_contents($fixturePath),true,32,JSON_THROW_ON_ERROR);
$caseIds=['warm-independent','irritable-direct','reserved-silence','unverified-history-in-profile','affection-is-not-romance','profile-instruction-injection'];
companionPgCheck(($fixture['schema_version']??null)===1&&array_column($fixture['cases']??[],'id')===$caseIds,'six_preregistered_cases_required');
$model=require $callbackPath;
companionPgCheck(is_callable($model),'real_model_callback_required');
$failures=[];
foreach($fixture['cases'] as $index=>$case){
    $input=$case['public_input']??[];
    $name=$input['name']??null;$personality=$input['personality']??null;$affinity=$input['affinity']??null;
    companionPgCheck(is_string($name)&&correspondenceActorName($name)&&is_string($personality)&&strlen($personality)<=8000
        &&(is_int($affinity)||is_float($affinity))&&$affinity>=-100&&$affinity<=100,'invalid_preregistered_case');
    $extended=correspondenceJson(['background_life_enabled'=>true,'background_life_letters'=>true,'relationships'=>['Dragonborn'=>['aff'=>$affinity]]]);
    companionPgSql($db,'UPDATE public.core_npc_master SET npc_name=$1,personality=$2,extended_data=$3::jsonb WHERE id=900001',[$name,$personality,$extended]);
    $casePresence=base64_encode(correspondenceJson(['source'=>'plugin_player_routing_v2','present_actors'=>[['form_id'=>hexdec('00013488'),'name'=>$name,'distance'=>10,'managed'=>true,'creature'=>false]]]));
    $startHour=$index*70;$episode=null;$first=null;
    for($offset=0;$offset<=48;$offset++){
        $hour=$startHour+$offset;$gamets=30000000+$hour*416667;$ts=100+$hour*2;
        $store->bindRequestClock(['bored',(string)$ts,(string)$gamets,'synthetic real-model case']);$store->observeManaged($casePresence);
        $poll=$companion->poll();companionPgCheck($poll['status']==='queued','real_case_poll_required');
        $action=companionPgSql($db,"SELECT concat(action,chr(124),text) AS action FROM public.responselog WHERE concat(action,chr(124),text) LIKE 'command|ExtCmdCHIMCorrespondenceCompanion_Observe@%' ORDER BY id DESC LIMIT 1")[0]['action']??'';
        $issued=json_decode(substr($action,strpos($action,'@')+1),true,16,JSON_THROW_ON_ERROR);
        companionPgCheck(count($issued['actors'])===1&&$issued['actors'][0]['id']==='900001','real_case_actor_required');
        $minute=correspondenceCompanionGameMinute($gamets);$episode??=$issued['observation_id'];$first??=$minute;
        $reply=$issued;$reply['actors']=[['id'=>'900001','refid'=>$issued['actors'][0]['refid'],'state'=>'waiting','episode'=>$episode,'first_game_minute'=>$first,'game_minute'=>$minute]];
        $receipt=['correspondence_companion',(string)($ts+1),(string)$gamets,correspondenceJson($reply)];
        companionPgCheck($companion->receive($receipt,$reply)['status']==='processed','real_case_sample_required');
    }
    $raw=null;$calls=0;
    $result=$companion->tick(static function(array $messages)use($model,&$raw,&$calls):string{
        $calls++;$raw=$model($messages);return $raw;
    });
    $rows=companionPgSql($db,'SELECT status,reason_code,document_id::text,attempted FROM plugins.correspondence_companion_decision WHERE actor_id=900001 AND episode_id=$1::uuid',[$episode]);
    $decision=$rows[0]??[];$documentId=$decision['document_id']??null;$route=null;
    if(is_string($documentId)){
        $payload=companionPgSql($db,'SELECT payload::text FROM plugins.correspondence_documents WHERE document_id=$1::uuid',[$documentId])[0]['payload']??null;
        $document=is_string($payload)?json_decode($payload,true,64,JSON_THROW_ON_ERROR):null;
        if(is_array($document))$route=['author'=>$document['author']??null,'payer'=>$document['payer']??null,'recipient'=>$document['recipient']??null,
            'claimed_sender'=>$document['claimed_sender']??null,'attachments'=>$document['attachments']??null,'body'=>$document['body']??null,'operation_id'=>$document['operation_id']??null];
    }
    $record=['case_id'=>$case['id'],'status'=>$result['status']??null,'reason'=>$result['reason']??null,'model_calls'=>$calls,
        'attempted'=>$decision['attempted']??null,'raw_output'=>$raw,'decision_status'=>$decision['status']??null,'decision_reason'=>$decision['reason_code']??null,
        'document_id'=>$documentId,'route'=>$route];
    echo correspondenceJson($record)."\n";
    if($calls!==1||($decision['attempted']??null)!=='t')$failures[]=$case['id'].':model_attempt';
    if(!in_array($result['status']??null,['queued','silent'],true))$failures[]=$case['id'].':model_or_policy_failure';
    if($case['id']==='reserved-silence'&&($result['status']??null)!=='silent')$failures[]=$case['id'].':expected_silence';
    if(($result['status']??null)==='silent'&&$documentId!==null)$failures[]=$case['id'].':silent_document';
    if(($result['status']??null)==='queued'&&!is_string($documentId))$failures[]=$case['id'].':missing_document';
    if(is_string($documentId)&&(!is_array($route)||($route['recipient']['kind']??null)!=='player'||($route['author']['id']??null)!==900001
        ||($route['payer']['id']??null)!==900001||($route['attachments']??null)!==[]||($route['operation_id']??null)!==null))$failures[]=$case['id'].':physical_route';
    $repeatCalls=0;
    $companion->tick(static function()use(&$repeatCalls):string{$repeatCalls++;return '{"action":"letter","recipient":"player","body":"Repeat."}';});
    if($repeatCalls!==0)$failures[]=$case['id'].':second_model_attempt';
    $hour=$startHour+49;$gamets=30000000+$hour*416667;$ts=100+$hour*2;
    $store->bindRequestClock(['bored',(string)$ts,(string)$gamets,'synthetic real-model case']);$store->observeManaged($casePresence);
    companionPgCheck($companion->poll()['status']==='queued','real_case_reunion_poll_required');
    $action=companionPgSql($db,"SELECT concat(action,chr(124),text) AS action FROM public.responselog WHERE concat(action,chr(124),text) LIKE 'command|ExtCmdCHIMCorrespondenceCompanion_Observe@%' ORDER BY id DESC LIMIT 1")[0]['action']??'';
    $issued=json_decode(substr($action,strpos($action,'@')+1),true,16,JSON_THROW_ON_ERROR);
    $reply=$issued;$reply['actors']=[['id'=>'900001','refid'=>$issued['actors'][0]['refid'],'state'=>'nearby','episode'=>'','first_game_minute'=>0,'game_minute'=>correspondenceCompanionGameMinute($gamets)]];
    companionPgCheck($companion->receive(['correspondence_companion',(string)($ts+1),(string)$gamets,correspondenceJson($reply)],$reply)['status']==='processed','real_case_reunion_required');
    if(is_string($documentId)){
        $retired=companionPgSql($db,'SELECT status FROM plugins.correspondence_companion_decision WHERE document_id=$1::uuid',[$documentId])[0]['status']??null;
        if($retired!=='stale')$failures[]=$case['id'].':reunion_not_retired';
    }
}
companionPgCheck($failures===[],'real_model_evaluation_failed: '.implode(',',$failures));
echo "companion_real_cases PASS\n";
