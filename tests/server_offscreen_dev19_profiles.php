<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/server/lib/offscreen.php';

function offscreenDev19ProfileCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$engine = (new ReflectionClass(CorrespondenceOffscreenPg::class))->newInstanceWithoutConstructor();
$classifier = new ReflectionMethod($engine,'actorProfileConstructible');
$deferral = new ReflectionMethod($engine,'actorProfileNeedsDeferral');
$recheckValidator = new ReflectionMethod($engine,'validRecheckAfterGamets');
$futureRecheck = new ReflectionMethod($engine,'hasFutureProfileRecheck');
$row = [
    'id'=>'900001','npc_name'=>'Lydia','personality'=>'A clear and thoughtful profile.',
    'extended_data'=>'{"background_life_enabled":true,"background_life_letters":true,"background_life_goals":"","relationships":{}}',
    'metadata'=>'{"stats":{"is_dead":false}}',
];
offscreenDev19ProfileCheck($classifier->invoke($engine,$row)===true,
    'a valid actor profile must remain constructible');
$emptyMaps = $row;
$emptyMaps['extended_data']='{"background_life_enabled":true,"background_life_letters":true,"relationships":[]}';
$emptyMaps['metadata']='{"stats":[]}';
offscreenDev19ProfileCheck($classifier->invoke($engine,$emptyMaps)===true,
    'empty relationship and stats arrays are valid empty maps');
$nullableMaps = $row;
$nullableMaps['extended_data']='{"background_life_enabled":true,"background_life_letters":true,"relationships":null}';
$nullableMaps['metadata']='{"stats":null}';
offscreenDev19ProfileCheck($classifier->invoke($engine,$nullableMaps)===true,
    'null relationship and stats maps are valid absent values');
$missingMetadata = $row;
$missingMetadata['metadata']=null;
offscreenDev19ProfileCheck($classifier->invoke($engine,$missingMetadata)===true,
    'missing metadata remains constructible with unknown location and no stats');
$emptyMetadata = $row;
$emptyMetadata['metadata']='[]';
offscreenDev19ProfileCheck($classifier->invoke($engine,$emptyMetadata)===true,
    'empty metadata arrays remain constructible');

$disabled = $row;
$disabled['extended_data']='{"background_life_enabled":false,"background_life_letters":true}';
offscreenDev19ProfileCheck($classifier->invoke($engine,$disabled)===true,
    'explicit BGL opt-out must remain a valid terminal eligibility decision');
$lettersOff = $row;
$lettersOff['extended_data']='{"background_life_enabled":true,"background_life_letters":false}';
offscreenDev19ProfileCheck($classifier->invoke($engine,$lettersOff)===true,
    'explicit Letters opt-out must remain a valid terminal eligibility decision');
$dead = $row;
$dead['metadata']='{"stats":{"is_dead":true}}';
offscreenDev19ProfileCheck($classifier->invoke($engine,$dead)===true,
    'explicit dead status must remain a valid terminal eligibility decision');

$badName = $row;
$badName['npc_name']='';
$badPersonality = $row;
$badPersonality['personality']=str_repeat('x',8001);
$badSettings = $row;
$badSettings['extended_data']='[]';
$missingSettings = $row;
$missingSettings['extended_data']='{}';
$badSettingValue = $row;
$badSettingValue['extended_data']='{"background_life_enabled":"unknown","background_life_letters":true}';
$badMetadata = $row;
$badMetadata['metadata']='[1]';
$badDeadFlag = $row;
$badDeadFlag['metadata']='{"stats":{"is_dead":"unknown"}}';
foreach ([$badName,$badSettings,$missingSettings,$badSettingValue,$badMetadata,$badDeadFlag] as $invalid) {
    offscreenDev19ProfileCheck($classifier->invoke($engine,$invalid)===false,
        'invalid actor name, settings, or metadata must be recheckable rather than inferred disabled');
}

// npcMap() treats an unusable personality as an empty one and keeps the actor
// eligible and addressable. Construction must agree, or that actor's mail is
// deferred forever and its sender can never initiate again.
$npcMap = new ReflectionMethod($engine,'npcMap');
$nullPersonality = $row;
$nullPersonality['personality']=null;
$longPersonality = $row;
$longPersonality['personality']=str_repeat('é',2001);
$invalidUtf8Personality = $row;
$invalidUtf8Personality['personality']="Lydia\xC3";
$nulPersonality = $row;
$nulPersonality['personality']="Lydia\0bio";
foreach (['null'=>$nullPersonality,'over-long'=>$longPersonality,'oversized'=>$badPersonality,'invalid UTF-8'=>$invalidUtf8Personality,'NUL'=>$nulPersonality] as $label=>$profile) {
    $projected = $npcMap->invoke($engine,[$profile])[900001] ?? null;
    offscreenDev19ProfileCheck(is_array($projected) && $projected['personality']==='' && $projected['enabled']===true && $projected['letters']===true,
        "a {$label} personality must be projected as an empty personality on an eligible actor");
    offscreenDev19ProfileCheck($classifier->invoke($engine,$profile)===true,
        "a {$label} personality must stay constructible because npcMap already normalizes it");
    offscreenDev19ProfileCheck($deferral->invoke($engine,$profile)===false,
        "a {$label} personality must not defer mail forever");
}
$explicitOptOutWithBadPersonality = $badPersonality;
$explicitOptOutWithBadPersonality['extended_data']='{"background_life_enabled":false,"background_life_letters":true}';
offscreenDev19ProfileCheck($deferral->invoke($engine,$explicitOptOutWithBadPersonality)===false,
    'an explicit BGL opt-out remains terminal even when unrelated profile text is malformed');
$explicitDeadWithBadPersonality = $badPersonality;
$explicitDeadWithBadPersonality['metadata']='{"stats":{"is_dead":true}}';
offscreenDev19ProfileCheck($deferral->invoke($engine,$explicitDeadWithBadPersonality)===false,
    'an explicit dead status remains terminal even when unrelated profile text is malformed');
offscreenDev19ProfileCheck($deferral->invoke($engine,$badSettingValue)===true,
    'a mutable profile defect without explicit ineligibility must be recheckable');

offscreenDev19ProfileCheck($recheckValidator->invoke($engine,(string)PHP_INT_MAX)===true,
    'a native-range positive reconsideration timestamp must remain valid');
$futureMail = ['payload_array'=>['reconsiderable'=>true,'reason_code'=>'profile_construction_failed','recheck_after_gamets'=>'200']];
offscreenDev19ProfileCheck($futureRecheck->invoke($engine,$futureMail,100)===true
    && $futureRecheck->invoke($engine,$futureMail,200)===false,
    'a valid future profile retry deadline must be preserved only until it is due');
foreach ([null,'not-a-gamets','-1','0',str_repeat('9',20)] as $invalidTimestamp) {
    offscreenDev19ProfileCheck($recheckValidator->invoke($engine,$invalidTimestamp)===false,
        'null, text, negative, zero, and oversized reconsideration metadata must require repair');
}

echo "PASS dev19 offscreen profile classifier preserves explicit opt-outs and rejects malformed construction data\n";
