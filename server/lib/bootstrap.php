<?php
declare(strict_types=1);
require_once __DIR__.'/store.php';
require_once __DIR__.'/boundaries.php';
require_once __DIR__.'/autonomy_policy.php';
require_once __DIR__.'/autonomy_store.php';
require_once __DIR__.'/autonomy_host.php';
if(is_file(__DIR__.'/offscreen.php'))require_once __DIR__.'/offscreen.php';
require_once __DIR__.'/offscreen_host.php';
function correspondenceEngineRoot():string
{
    $root=$GLOBALS['ENGINE_PATH']??dirname(__DIR__,3);if(!is_string($root)||(!function_exists('ptp_connect')&&!is_file($root.'/lib/playthrough_preferences.php')))throw new DomainException('core_connection_helper_unavailable');return rtrim($root,'/\\');
}
function correspondenceEnterRuntime(string $root):void
{
    if(!function_exists('ptr_runtime_enter')){
        if(!is_file($root.'/lib/playthrough_runtime.php'))throw new DomainException('core_restore_barrier_unavailable');
        require_once $root.'/lib/playthrough_runtime.php';
    }
    if(!function_exists('ptr_runtime_enter'))throw new DomainException('core_restore_barrier_unavailable');
    ptr_runtime_enter();
}
function correspondenceReleaseInitFence():void
{
    $connection=$GLOBALS['CORRESPONDENCE_INIT_FENCE']??null;unset($GLOBALS['CORRESPONDENCE_INIT_FENCE']);
    if($connection!==null){
        try{if(@pg_query_params($connection,'SELECT pg_advisory_unlock(18459814,1)',[])===false)correspondenceDiagnostic('init_fence_unavailable');}catch(Throwable){correspondenceDiagnostic('init_fence_unavailable');}
        finally{try{pg_close($connection);}catch(Throwable){}}
    }
}
function correspondenceHoldInitFence():void
{
    if(isset($GLOBALS['CORRESPONDENCE_INIT_FENCE']))return;
    $connection=correspondenceNativeConnection();
    try{
        if(@pg_query_params($connection,"SET lock_timeout='3s'",[])===false||@pg_query_params($connection,'SELECT pg_advisory_lock(18459814,1)',[])===false)throw new DomainException('init_fence_unavailable');
        $GLOBALS['CORRESPONDENCE_INIT_FENCE']=$connection;register_shutdown_function('correspondenceReleaseInitFence');
    }catch(Throwable $e){try{pg_close($connection);}catch(Throwable){}throw $e;}
}function correspondenceNativeConnection():mixed
{
    $root=correspondenceEngineRoot();correspondenceEnterRuntime($root);if(!function_exists('ptp_connect'))require_once $root.'/lib/playthrough_preferences.php';
    if(!function_exists('ptp_connect')||!function_exists('pg_query_params'))throw new DomainException('core_connection_unavailable');
    $connection=ptp_connect();if(!$connection)throw new DomainException('storage_unavailable');return $connection;
}
function correspondenceProduction():array
{
    static $runtime=null;if($runtime!==null)return $runtime;
    $root=correspondenceEngineRoot();$connection=correspondenceNativeConnection();
    $store=new CorrespondencePgStore($connection,fn($s)=>$s->observedExecutor(),fn($title,$body)=>correspondencePrepareCoreNote($root,$title,$body));
    $store->ensureSchema();
    return $runtime=['service'=>new CorrespondenceService($store),'store'=>$store,'connection'=>$connection];
}
function correspondenceAutonomyProduction():array
{
    static $autonomy=null;$runtime=correspondenceProduction();
    if($autonomy===null){$autonomy=new CorrespondenceAutonomyPg($runtime['store'],$runtime['service']);$autonomy->ensureSchema();}
    $runtime['autonomy']=$autonomy;return $runtime;
}
function correspondenceOffscreenProduction():array
{
    static $offscreen=null;$runtime=correspondenceAutonomyProduction();
    if($offscreen===null){
        if(!class_exists('CorrespondenceOffscreenPg'))throw new DomainException('offscreen_engine_unavailable');
        $offscreen=new CorrespondenceOffscreenPg($runtime['store']);$offscreen->ensureSchema();
    }
    $runtime['offscreen']=$offscreen;return $runtime;
}
function correspondenceReceiveWithRetry(array $request,array $receipt,callable $runtimeFactory):array
{
    $receiptId=$receipt['receipt_id']??null;
    try{$correlationId=correspondenceUuidCheck(is_string($receiptId)?strtolower($receiptId):null);}
    catch(InvalidArgumentException){$correlationId=null;}
    for($attempt=0;$attempt<2;$attempt++){
        try{
            $runtime=$runtimeFactory();$runtime['store']->bindRequestClock($request);
            $result=$runtime['service']->receive($receipt);
            if($attempt===1)correspondenceDiagnostic('receipt_retry_succeeded',['correlation_id'=>$correlationId]);
            return $result;
        }catch(RuntimeException $error){
            if($attempt!==0||$error->getMessage()!=='storage_failed')throw $error;
            correspondenceDiagnostic('receipt_retry_pending',['correlation_id'=>$correlationId]);
            usleep(100000);
        }
    }
    throw new LogicException('receipt_retry_exhausted');
}
function correspondenceDiagnostic(string $code, array $details = []):void
{
    $allowed=['receipt_rejected','receipt_retry_pending','receipt_retry_succeeded','runtime_unavailable','presence_rejected','context_unavailable','social_unavailable','ui_rejected','core_prompt_api_unavailable','init_fence_unavailable','autonomy_unavailable','offscreen_unavailable','offscreen_worker_result','autonomy_outcome'];
    if(!in_array($code,$allowed,true))$code='runtime_unavailable';
    $workerResult=$code==='offscreen_worker_result';
    $autonomyOutcome=$code==='autonomy_outcome';
    $autonomyStatuses=['idle','paused','skipped','failed','stale','silent','queued','dispatched','unknown'];
    $autonomyReasons=['native_request_required','native_clock_unavailable','host_unavailable','model_response_unavailable','model_unavailable','policy_unavailable','disabled','abandoned_claim_retired','cooldown','no_eligible_source','invalid_candidate','source_unavailable','actor_unavailable','player_unavailable','identity_changed','actor_identity_changed','source_identity_changed','source_not_due','chain_limit','already_claimed','invalid_decision','model_error','storage_error','queued_for_transit','decision_missing','dispatch_already_claimed','dispatch_uncertain','dispatch_queued','executor_unavailable','dispatch_unknown','child_missing','decision_anchor_invalid','source_changed','child_changed','actor_changed','claim_anchor_invalid','clock_changed','setting_changed','cooldown_changed','silence','invalid_delay','abandoned_claim_expired'];
    $status=$workerResult&&in_array($details['status']??null,['processed','idle','paused','stale','failed','unavailable'],true)?$details['status']:($autonomyOutcome&&in_array($details['status']??null,$autonomyStatuses,true)?$details['status']:'failed');
    $reason=$workerResult&&is_string($details['reason']??null)&&preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D',$details['reason'])===1?$details['reason']:($autonomyOutcome&&in_array($details['reason']??null,$autonomyReasons,true)?$details['reason']:($autonomyOutcome?'reason_unavailable':$code));
    $record=['schema'=>'correspondence.diagnostic.v1','event'=>$workerResult?'offscreen_worker_result':($autonomyOutcome?'autonomy_outcome':'diagnostic_failure'),'status'=>$status,'reason'=>$reason,'at_utc'=>gmdate('Y-m-d\TH:i:s\Z')];
    $receiptOutcome=in_array($code,['receipt_retry_pending','receipt_retry_succeeded'],true);
    if($receiptOutcome){$status=$code==='receipt_retry_succeeded'?'processed':'deferred';$record['event']='receipt_intake';$record['status']=$status;$record['reason']=$code;$record['attempt']=2;}
    if(is_string($details['correlation_id']??null)&&preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D',$details['correlation_id'])===1)$record['correlation_id']=$details['correlation_id'];
    if(is_int($details['elapsed_ms']??null)&&$details['elapsed_ms']>=0&&$details['elapsed_ms']<=180000)$record['elapsed_ms']=$details['elapsed_ms'];
    if(is_int($details['model_calls']??null)&&$details['model_calls']>=0&&$details['model_calls']<=1)$record['model_calls']=$details['model_calls'];
    if($autonomyOutcome)foreach(['input_chars','output_chars']as$field)if(is_int($details[$field]??null)&&$details[$field]>=0&&$details[$field]<=2147483647)$record[$field]=$details[$field];
    $sourceIds=$details['source_ids']??null;
    if(is_array($sourceIds)&&array_is_list($sourceIds)&&count($sourceIds)<=1&&($sourceIds===[]||is_int($sourceIds[0])&&$sourceIds[0]>0))if($sourceIds!==[])$record['source_ids']=$sourceIds;
    $outcomes=$details['social_outcomes']??null;$consumerStatuses=['invalid','pending','queued','paused','failed','abstained','processed','running','existing_worker','spawn_requested','tick_finished','child_exited','unavailable'];
    if(is_array($outcomes)&&!array_is_list($outcomes)){
        $safeOutcomes=[];foreach(['mind_poisoning','sworn_and_scorned']as$owner)if(in_array($outcomes[$owner]??null,$consumerStatuses,true))$safeOutcomes[$owner]=$outcomes[$owner];
        if($safeOutcomes!==[])$record['social_outcomes']=$safeOutcomes;
    }
      if($workerResult&&in_array($status,['idle','paused'],true))return;
    $visibleIdleReasons=['model_unavailable','policy_unavailable','actor_identity_changed','identity_changed','source_identity_changed','player_unavailable','actor_unavailable','source_not_due','source_unavailable','chain_limit','abandoned_claim_retired'];
    if($autonomyOutcome&&($status==='paused'||$status==='idle'&&!in_array($reason,$visibleIdleReasons,true)))return;
    if(!class_exists('Logger'))return;
    $json=json_encode($record,JSON_UNESCAPED_SLASHES);if(!is_string($json))return;$message='[CORRESPONDENCE] '.$json;
    try{
          if(($workerResult&&in_array($status,['processed','stale'],true))||($autonomyOutcome&&in_array($status,['stale','silent','queued','dispatched'],true))||($receiptOutcome&&$status==='processed')){if(method_exists('Logger','info'))Logger::info($message);}
        elseif(($workerResult&&in_array($status,['failed','unavailable'],true))||($autonomyOutcome&&in_array($status,['failed','unknown'],true))||(!$workerResult&&!$autonomyOutcome&&in_array($status,['failed','unavailable'],true))){if(method_exists('Logger','error'))Logger::error($message);}
        elseif(method_exists('Logger','warn'))Logger::warn($message);
    }catch(Throwable){}
}




