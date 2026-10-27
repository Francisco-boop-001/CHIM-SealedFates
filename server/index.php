<?php
declare(strict_types=1);
require_once __DIR__.'/lib/view.php';
require_once __DIR__.'/lib/bootstrap.php';
require_once __DIR__.'/lib/consumers.php';
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: same-origin');header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'self'; form-action 'self'; frame-ancestors 'self'");
if(!correspondenceAccess($_SERVER)){http_response_code(403);header('Content-Type: text/html; charset=UTF-8');echo correspondenceAccessDeniedPage($_SERVER);exit;}
if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','POST'],true)){http_response_code(405);exit;}
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&!correspondencePostOriginAllowed($_SERVER)){http_response_code(403);header('Content-Type: text/plain; charset=UTF-8');echo 'Operator origin required.';exit;}
if(session_status()!==PHP_SESSION_ACTIVE&&!session_start(['cookie_httponly'=>true,'cookie_samesite'=>'Strict','use_strict_mode'=>true])){http_response_code(503);header('Content-Type: text/html; charset=UTF-8');echo correspondenceErrorPage('Sealed Fates is unavailable.');exit;}
if(!is_string($_SESSION['correspondence_csrf']??null))$_SESSION['correspondence_csrf']=bin2hex(random_bytes(32));
$csrf=$_SESSION['correspondence_csrf'];$message='';$opened=null;
try{
    $runtime=correspondenceCompanionProduction();$service=$runtime['service'];$autonomy=$runtime['autonomy'];$choices=$runtime['store']->itemChoices();$managedChildren=$autonomy->managedChildren();$companionDocuments=$runtime['companion']->ownedDocuments();
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
        $request=correspondenceAutonomyGuardManualDispatch(correspondencePost($_POST,$csrf,$choices),$managedChildren,$companionDocuments);
        if($request['action']==='autonomy'){
            $autonomy->setEnabled($request['enabled']);
            $serviceState=$request['enabled']?correspondenceOffscreenServiceStart():correspondenceOffscreenServiceStop();
            $message=$request['enabled']?'Sealed Fates autonomous mode enabled.':'Sealed Fates autonomous mode paused.';
            if(($serviceState['status']??null)==='unavailable')$message.=' The background service is unavailable; check its status and installation.';
        }
        else{
            if($request['action']==='compose')$result=$service->composeAndDispatch($request['input']);
            elseif($request['action']==='read'){$opened=correspondenceOperatorRead($service->list(),$request['document_id']);$result=['state'=>'read'];}
            elseif($request['action']==='retry')$result=$service->retry($request['document_id']);
            elseif($request['action']==='close')$result=$service->close($request['document_id']);
            else $result=$service->dispatch($request['document_id'],$request['action']==='reconcile');
            $message=match($result['state']){'queued'=>'Letter queued; a recent managed game actor is needed before dispatch.','dispatched'=>'Letter dispatched; physical receipt is pending.','read'=>'Letter opened in the operator view; no NPC exposure was created.','delivered'=>'Physical delivery confirmed.','exposed'=>'Recipient context has received this letter.','unknown'=>'Outcome unknown. Use the read-only check; do not send another copy.','failed'=>'Delivery failed; no social result has been claimed.',default=>'Request recorded.'};
            if(($result['operator_closed']??false)===true)$message='Closed by operator. The delivery outcome is unchanged; nothing was resent or refunded. Read-only checks remain available for pending or unknown delivery.';
        }
    }
    $letters=$service->list();$references=[];foreach($letters as$letter)if($letter['recipient']['kind']==='npc'&&is_int($letter['source_event_id']??null))$references[]=$letter['source_event_id'];
    $statuses=correspondenceConsumeReferences(correspondenceEngineRoot(),$runtime['connection'],$references,true);foreach($letters as&$letter)if(isset($statuses[$letter['source_event_id']??0]))$letter['social_status']=$statuses[$letter['source_event_id']];unset($letter);
    $autonomySettings=$autonomy->settings();$autonomyRows=$autonomy->status();$offscreenStatus=[];
    try{$offscreenStatus=correspondenceOffscreenProduction()['offscreen']->status();}catch(Throwable){$offscreenStatus=[];}
    $panel=correspondenceOffscreenPanelHtml(($autonomySettings['enabled']??false)===true,correspondenceOffscreenServiceStatus(),$offscreenStatus);
    $html=correspondencePage($service->actors(),$choices,$letters,$csrf,$message,$opened,$autonomySettings,$autonomyRows,$managedChildren,$companionDocuments);
    header('Content-Type: text/html; charset=UTF-8');echo str_replace('</main>',$panel.'</main>',$html);
}catch(DomainException|InvalidArgumentException $e){
    correspondenceDiagnostic('ui_rejected');http_response_code(400);header('Content-Type: text/html; charset=UTF-8');
    $known=['csrf_failed','invalid_body','invalid_attachments','invalid_quantity','invalid_enabled','item_unavailable','renderer_content_overflow','renderer_dependency_unavailable','document_unavailable','delivery_unconfirmed','scope_unavailable','actor_unavailable','autonomous_dispatch_owned','companion_scheduler_owned','autonomy_status_unavailable','operator_closed','closure_unavailable'];
    $message=in_array($e->getMessage(),$known,true)?str_replace('_',' ',$e->getMessage()):'The request could not be accepted.';
    echo correspondenceErrorPage($message);
}catch(Throwable){correspondenceDiagnostic('runtime_unavailable');http_response_code(503);header('Content-Type: text/html; charset=UTF-8');echo correspondenceErrorPage('Sealed Fates is unavailable. Check installation, migrations and core compatibility.');}


