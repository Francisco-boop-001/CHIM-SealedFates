<?php
declare(strict_types=1);
// Included only after companion_pg.php has verified its disposable database identity.
require_once $root.'/server/lib/offscreen.php';
$offscreen=new CorrespondenceOffscreenPg($store);$offscreen->ensureSchema();
$clock=$store->scope();$scope=$clock['playthrough_id'];$now=(int)$clock['gamets']+10000000;
$epoch=correspondenceCompanionEpoch($store->restoreGeneration());
$store->bindRequestClock(['bored',(string)((int)$clock['ts']+1),(string)$now,'synthetic']);
foreach([null,900001] as $actorId){
    $requestIds=[];
    $insert=static function(int $at,int $actor,string $playthrough,string $generation)use($store,&$requestIds):void{
        $request=correspondenceUuid();$episode=correspondenceUuid();
        $store->transaction(static function()use($store,$at,$actor,$playthrough,$generation,$request,$episode):void{
            $store->query("INSERT INTO plugins.correspondence_companion_decision(playthrough_id,epoch,actor_id,episode_id,request_id,attempted,status,claimed_gamets,reason_code) VALUES($1,$2::uuid,$3::bigint,$4::uuid,$5::uuid,TRUE,'silent',$6::numeric,'silence')",
                [$playthrough,$generation,(string)$actor,$episode,$request,(string)$at]);
        });
        $requestIds[]=$request;
    };
    try{
        $past=$now-($actorId===null?208333:1250000);
        $future=$now+($actorId===null?250000:1250001);
        $expected=$past+($actorId===null?416667:2500000);
        $insert($past,900001,$scope,$epoch);
        companionPgCheck(correspondenceCompanionCooldownUntil($store,$scope,$actorId,$now)===$expected,'recent-only cooldown control');
        $modelCalls=0;
        $model=static function()use(&$modelCalls):string{$modelCalls++;return '{"action":"none","recipient":null,"body":"","reason":"No reply is needed."}';};
        if($actorId===null)companionPgCheck($autonomy->tick($model)['reason']==='cooldown','recent claim blocks actual scheduler');
        $insert($future,$actorId===null?900002:900001,$scope,$epoch);
        companionPgCheck(correspondenceCompanionCooldownUntil($store,$scope,$actorId,$now)===$expected,'future claim cannot hide recent claim in PostgreSQL MAX');
        foreach([[$autonomy,'globalCooldownUntil','actorCooldownUntil'],[$offscreen,'globalCooldown','actorCooldown']] as [$scheduler,$globalMethod,$actorMethod]){
            $method=new ReflectionMethod($scheduler,$actorId===null?$globalMethod:$actorMethod);
            $args=$actorId===null?[$scope,$now]:[$scope,$actorId,$now];
            companionPgCheck($method->invoke($scheduler,...$args)===$expected,'both real schedulers preserve companion cooldown');
        }
        if($actorId===null)companionPgCheck($autonomy->tick($model)['reason']==='cooldown'&&$modelCalls===0,'future claim cannot bypass actual scheduler admission');
        $insert($now,900001,'other-playthrough',$epoch);
        $insert($now,900001,$scope,correspondenceCompanionEpoch('older-restore'));
        companionPgCheck(correspondenceCompanionCooldownUntil($store,$scope,$actorId,$now)===$expected,'foreign playthrough and restore epoch excluded in PostgreSQL');
    }finally{
        $store->transaction(static function()use($store,$requestIds):void{
            foreach($requestIds as $request)$store->query('DELETE FROM plugins.correspondence_companion_decision WHERE request_id=$1::uuid',[$request]);
        });
    }
}
$store->bindRequestClock(['bored',(string)$clock['ts'],(string)$clock['gamets'],'synthetic']);
echo "companion_cooldown_pg PASS: bounded global/actor MAX, both schedulers, admission and scope/epoch isolation\n";
