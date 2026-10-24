<?php
declare(strict_types=1);

function correspondenceCompanionNativeRequest(array $request): bool
{
    return is_string($request[0]??null) && strtolower($request[0])==='correspondence_companion';
}

function correspondenceCompanionReceiveNativeRequest(array $request, callable $runtimeFactory, callable $resume): array
{
    if(!correspondenceCompanionNativeRequest($request))return ['status'=>'skipped','reason'=>'native_request_required'];
    try{
        correspondenceRequestClock($request);
        $raw=$request[3]??null;
        if(!is_string($raw)||strlen($raw)>8192)throw new InvalidArgumentException('invalid_reply');
        $reply=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($reply)||array_is_list($reply))throw new InvalidArgumentException('invalid_reply');
        $runtime=$runtimeFactory();$result=$runtime['companion']->receive($request,$reply);
        $wake=$resume($request,true);
        if(!is_array($wake)||in_array($wake['status']??null,['unavailable','failed'],true))$result['worker_status']='unavailable';
        return $result;
    }catch(Throwable){return ['status'=>'rejected','reason'=>'observation_rejected'];}
}

function correspondenceCompanionPollNativeRequest(array $request, callable $runtimeFactory): array
{
    if(correspondenceCompanionNativeRequest($request)||(($request[0]??null)!=='request'&&!correspondenceOffscreenNativeObservation($request)))return ['status'=>'skipped','reason'=>'native_request_required'];
    try{correspondenceRequestClock($request);}catch(Throwable){return ['status'=>'skipped','reason'=>'native_clock_unavailable'];}
    if(function_exists('chimInteractionAllowed')&&!chimInteractionAllowed())return ['status'=>'paused','reason'=>'interaction_disabled'];
    try{$runtime=$runtimeFactory();$runtime['store']->bindRequestClock($request);return $runtime['companion']->poll();}
    catch(Throwable){return ['status'=>'failed','reason'=>'poll_unavailable'];}
}
