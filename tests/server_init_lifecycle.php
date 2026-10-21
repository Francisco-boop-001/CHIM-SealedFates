<?php
declare(strict_types=1);

function initLifecycleCheck(bool $ok,string $reason):void { if(!$ok)throw new RuntimeException($reason); }
$case=$argv[1]??null;
if($case===null){
    foreach(['dependency_failure','before_model','unlock_failure'] as $child){
        $command=[PHP_BINARY,'-n',__FILE__,$child];
        $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        initLifecycleCheck(is_resource($process),'child_start_failed');fclose($pipes[0]);
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        $code=proc_close($process);initLifecycleCheck($code===0,$child.': '.$out.$err);
    }
    echo "PASS init lifecycle: dependency failure continues core, release before model, explicit unlock and cleanup\n";return;
}
$GLOBALS['ENGINE_PATH']=__DIR__.'/missing-init-engine';
function terminate():never { throw new RuntimeException('host_init_terminated'); }
if($case==='dependency_failure'){
    $GLOBALS['gameRequest']=['init','300','150','fixture'];
    include dirname(__DIR__).'/server/preprocessing.php';
    initLifecycleCheck(!isset($GLOBALS['CORRESPONDENCE_INIT_FENCE']),'failed_fence_retained');
    echo "core_init_continues\n";return;
}
$GLOBALS['initQueries']=[];$GLOBALS['initClosed']=0;
if($case!=='dependency_failure'){
function ptp_connect():mixed{return new stdClass();}
function ptr_runtime_enter():void{}
function pg_query_params(mixed $connection,string $sql,array $parameters=[]):mixed {
    $GLOBALS['initQueries'][]=$sql;
    if(($GLOBALS['initUnlockFails']??false)&&str_contains($sql,'pg_advisory_unlock'))return false;
    return true;
}
function pg_close(mixed $connection):bool{$GLOBALS['initClosed']++;return true;}
function chimRegisterPromptInjection(string $slot,string $key,callable $callback,int $priority):void{}
}
require_once dirname(__DIR__).'/server/lib/bootstrap.php';
correspondenceHoldInitFence();
initLifecycleCheck(isset($GLOBALS['CORRESPONDENCE_INIT_FENCE']),'init_not_fenced');
if($case==='unlock_failure')$GLOBALS['initUnlockFails']=true;
// Core comm.php has completed its prune/marker before this supported hook.
include dirname(__DIR__).'/server/context_pre.php';
initLifecycleCheck(!isset($GLOBALS['CORRESPONDENCE_INIT_FENCE']),'fence_still_held_during_model');
initLifecycleCheck(count(array_filter($GLOBALS['initQueries'],fn($q)=>str_contains($q,'pg_advisory_unlock')))==1,'explicit_unlock_missing');
initLifecycleCheck($GLOBALS['initClosed']===1,'connection_not_closed');
correspondenceReleaseInitFence();
initLifecycleCheck($GLOBALS['initClosed']===1,'release_not_idempotent');
echo "init_fence_released_before_model\n";
