<?php
declare(strict_types=1);

final class RestoreOwnershipPgResult
{
    public function __construct(public array $rows) {}
}

if (!defined('PGSQL_TRANSACTION_IDLE')) define('PGSQL_TRANSACTION_IDLE', 0);
$GLOBALS['restoreOwnershipDb'] = ['epoch'=>'11111111-1111-4111-8111-111111111111','pending'=>false,'floor'=>'0'];
$GLOBALS['restoreOwnershipTimeout'] = '250ms';
$GLOBALS['restoreOwnershipTransaction'] = PGSQL_TRANSACTION_IDLE;
$GLOBALS['restoreOwnershipReadOnly'] = false;
$GLOBALS['restoreOwnershipPgMode'] = 'normal';
$GLOBALS['restoreOwnershipEmptyState'] = false;
$GLOBALS['restoreOwnershipSql'] = [];
$GLOBALS['restoreOwnershipClosed'] = 0;
$GLOBALS['restoreOwnershipConnected'] = 0;
$GLOBALS['restoreOwnershipNoteCalls'] = 0;

function pg_query_params(mixed $connection,string $sql,array $parameters=[]):mixed
{
    $sql=trim($sql);$GLOBALS['restoreOwnershipSql'][]=$sql;
    if($GLOBALS['restoreOwnershipPgMode']==='fail')return false;
    if(str_starts_with($sql,'SELECT current_setting('))return new RestoreOwnershipPgResult([['lock_timeout'=>$GLOBALS['restoreOwnershipTimeout']]]);
    if(str_starts_with($sql,'SELECT set_config(')){$GLOBALS['restoreOwnershipTimeout']=$parameters[0];return new RestoreOwnershipPgResult([['set_config'=>$parameters[0]]]);}
    if(str_starts_with($sql,'SET lock_timeout=')){$GLOBALS['restoreOwnershipTimeout']='3s';return new RestoreOwnershipPgResult([]);}
    if(str_starts_with($sql,'BEGIN')){$beforeBegin=$GLOBALS['restoreOwnershipBeforeBegin']??null;unset($GLOBALS['restoreOwnershipBeforeBegin']);if(is_callable($beforeBegin))$beforeBegin();$GLOBALS['restoreOwnershipTransaction']=1;$GLOBALS['restoreOwnershipReadOnly']=str_contains($sql,'READ ONLY');return new RestoreOwnershipPgResult([]);}
    if(str_starts_with($sql,'SELECT pg_advisory_xact_lock')){$afterPrecheck=$GLOBALS['restoreOwnershipAfterMarkerPrecheck']??null;unset($GLOBALS['restoreOwnershipAfterMarkerPrecheck']);if(is_callable($afterPrecheck))$afterPrecheck();return new RestoreOwnershipPgResult([]);}
    if($sql==='COMMIT'||$sql==='ROLLBACK'){$GLOBALS['restoreOwnershipTransaction']=PGSQL_TRANSACTION_IDLE;$GLOBALS['restoreOwnershipReadOnly']=false;return new RestoreOwnershipPgResult([]);}
    if(str_contains($sql,'pg_advisory_unlock_shared'))return new RestoreOwnershipPgResult([['released'=>'t']]);
    if(str_contains($sql,'pg_advisory_unlock'))return new RestoreOwnershipPgResult([['released'=>'t']]);
    if(str_starts_with($sql,"SELECT to_regclass('plugins.correspondence_documents')"))return new RestoreOwnershipPgResult([['documents'=>'t','operations'=>'t','presence'=>'t','restore_state'=>'t','restore_floor'=>'t']]);
    if(str_starts_with($sql,"SELECT to_regclass('chim_meta.playthrough_profiles')"))return new RestoreOwnershipPgResult([['profile_table'=>null]]);
    if(str_starts_with($sql,'SELECT table_name,column_name FROM information_schema.columns')){
        $rows=[];foreach(['correspondence_documents'=>['document_id','playthrough_id','request_id','payload','created_at'],'correspondence_operations'=>['operation_id','document_id','payload','created_at'],'correspondence_presence'=>['playthrough_id','payload'],'correspondence_restore_state'=>['singleton','epoch','pending','native_ts_floor']]as$table=>$columns)foreach($columns as$column)$rows[]=['table_name'=>$table,'column_name'=>$column];
        return new RestoreOwnershipPgResult($rows);
    }
    if(str_contains($sql,'SELECT epoch::text,pending FROM plugins.correspondence_restore_state')||str_contains($sql,'SELECT epoch::text,pending,native_ts_floor FROM plugins.correspondence_restore_state')){
        if($GLOBALS['restoreOwnershipEmptyState'])return new RestoreOwnershipPgResult([]);
        return new RestoreOwnershipPgResult([['epoch'=>$GLOBALS['restoreOwnershipDb']['epoch'],'pending'=>$GLOBALS['restoreOwnershipDb']['pending']?'t':'f','native_ts_floor'=>$GLOBALS['restoreOwnershipDb']['floor']]]);
    }
    if(str_starts_with($sql,'SELECT native_ts_floor FROM plugins.correspondence_restore_state'))return new RestoreOwnershipPgResult([['native_ts_floor'=>$GLOBALS['restoreOwnershipDb']['floor']]]);
    if(str_starts_with($sql,'SELECT epoch FROM plugins.correspondence_restore_state'))return new RestoreOwnershipPgResult([['epoch'=>$GLOBALS['restoreOwnershipDb']['epoch']]]);
    if(str_starts_with($sql,'UPDATE plugins.correspondence_restore_state SET pending=FALSE')){
        $beforeClear=$GLOBALS['restoreOwnershipBeforeClear']??null;unset($GLOBALS['restoreOwnershipBeforeClear']);if(is_callable($beforeClear))$beforeClear();
        $epoch=$parameters[0]??null;$db=&$GLOBALS['restoreOwnershipDb'];
        if($db['epoch']===$epoch&&$db['pending']){$db['pending']=false;return new RestoreOwnershipPgResult([['epoch'=>$epoch]]);}return new RestoreOwnershipPgResult([]);
    }
    if(str_starts_with($sql,'UPDATE plugins.correspondence_restore_state SET epoch=')){
        $db=&$GLOBALS['restoreOwnershipDb'];$db=['epoch'=>$parameters[0],'pending'=>true,'floor'=>$parameters[1]];return new RestoreOwnershipPgResult([['epoch'=>$parameters[0]]]);
    }
    if(str_starts_with($sql,'INSERT INTO plugins.correspondence_restore_state')){if($GLOBALS['restoreOwnershipEmptyState']){$GLOBALS['restoreOwnershipDb']=['epoch'=>$parameters[0],'pending'=>($parameters[1]??'false')==='true','floor'=>$parameters[2]??'0'];$GLOBALS['restoreOwnershipEmptyState']=false;}return new RestoreOwnershipPgResult([]);}
    if(str_starts_with($sql,'INSERT INTO public.eventlog('))return new RestoreOwnershipPgResult([['rowid'=>'1','ts'=>$parameters[0],'gamets'=>$parameters[1],'type'=>$parameters[2],'data'=>$parameters[3],'sess'=>$parameters[4],'localts'=>$parameters[5],'people'=>$parameters[6]]]);
    if($GLOBALS['restoreOwnershipReadOnly']&&preg_match('/\A(?:INSERT|UPDATE|DELETE|TRUNCATE)\b/i',$sql)===1)return false;
    if(str_starts_with($sql,'SELECT 1 AS fixture'))return new RestoreOwnershipPgResult([['fixture'=>'1']]);
    if(str_contains($sql,'pg_advisory_lock_shared'))return new RestoreOwnershipPgResult([]);
    return new RestoreOwnershipPgResult([]);
}
function pg_num_fields(RestoreOwnershipPgResult $result):int{return $result->rows===[]?0:count($result->rows[0]);}
function pg_fetch_all(RestoreOwnershipPgResult $result):array|false{return $result->rows===[]?false:$result->rows;}
function pg_free_result(RestoreOwnershipPgResult $result):bool{return true;}
function pg_close(mixed $connection):bool{$GLOBALS['restoreOwnershipClosed']++;return true;}
function pg_transaction_status(mixed $connection):int{return $GLOBALS['restoreOwnershipTransaction'];}
function ptp_connect():mixed{$GLOBALS['restoreOwnershipConnected']++;return new stdClass();}
function ptr_runtime_enter():void{}
final class Logger
{
    public static array $rows=[];
    public static function info(string $message):void{self::$rows[]=$message;}
    public static function warn(string $message):void{self::$rows[]=$message;}
    public static function error(string $message):void{self::$rows[]=$message;}
}
require_once dirname(__DIR__).'/server/lib/bootstrap.php';

function restoreOwnershipCheck(bool $ok,string $reason):void{if(!$ok)throw new RuntimeException($reason);}
function restoreOwnershipStore(?string $path=null):CorrespondencePgStore
{
    return new CorrespondencePgStore(new stdClass(),static fn($unused)=>null,static function($title,$body){$GLOBALS['restoreOwnershipNoteCalls']++;return true;},$path);
}
function restoreOwnershipMarker(string $path):?array
{
    if(!is_file($path))return null;$value=json_decode((string)file_get_contents($path),true,8,JSON_THROW_ON_ERROR);return ['schema'=>$value['schema']??null,'state'=>$value['state']??null,'epoch'=>$value['epoch']??null];
}
function restoreOwnershipDir():string
{
    $path=sys_get_temp_dir().DIRECTORY_SEPARATOR.'correspondence-restore-owner-'.bin2hex(random_bytes(6));if(!mkdir($path,0700,true))throw new RuntimeException('restore_owner_tempdir_failed');return $path;
}
function restoreOwnershipExpectFailure(callable $call,string $message):Throwable
{
    try{$call();}catch(Throwable $error){return $error;}throw new RuntimeException($message);
}

$case=$argv[1]??null;
if($case==='init_lock_failure'){
    $root=restoreOwnershipDir();$runDir=$root.DIRECTORY_SEPARATOR.'log'.DIRECTORY_SEPARATOR.'playthrough_runtime';mkdir($runDir,0700,true);
    $GLOBALS['ENGINE_PATH']=$root;$GLOBALS['restoreOwnershipPgMode']='fail';
    $marker=$runDir.DIRECTORY_SEPARATOR.'correspondence.restore.json';$lock=@fopen($marker.'.lock','c+b');restoreOwnershipCheck(is_resource($lock)&&flock($lock,LOCK_EX|LOCK_NB),'init_fixture_lock_failed');
    $GLOBALS['gameRequest']=['init','1800000000000000100','42','fixture'];
    include dirname(__DIR__).'/server/preprocessing.php';
    $coreContinued=true;
    restoreOwnershipCheck($coreContinued&&($GLOBALS['CORRESPONDENCE_RESTORE_INIT_UNSAFE']??false)===true,'init_marker_failure_must_continue_core_and_mark_local_mail_unsafe');
    restoreOwnershipCheck(!is_file($marker),'locked_marker_must_not_be_replaced');
    flock($lock,LOCK_UN);fclose($lock);
    $GLOBALS['restoreOwnershipPgMode']='normal';$GLOBALS['restoreOwnershipDb']=['epoch'=>'11111111-1111-4111-8111-111111111111','pending'=>false,'floor'=>'0'];
    $store=restoreOwnershipStore($marker);$store->ensureSchema();
    $mailRejected=false;try{$store->assertRestoreToken();}catch(DomainException $error){$mailRejected=$error->getMessage()==='restore_epoch_pending';}
    restoreOwnershipCheck($mailRejected,'unsafe_init_must_quarantine_mail_after_core_continues');
    @unlink($marker);@unlink($marker.'.lock');@rmdir($runDir);@rmdir(dirname($runDir));@rmdir($root);
    echo "PASS init marker-lock failure: core hook returns and mail remains quarantined\n";exit(0);
}

$markerDir=restoreOwnershipDir();$marker=$markerDir.DIRECTORY_SEPARATOR.'correspondence.restore.json';
$epochA='11111111-1111-4111-8111-111111111111';$epochB='22222222-2222-4222-8222-222222222222';
restoreOwnershipCheck(CorrespondencePgStore::writeRestoreSentinelFile($marker,'pending',$epochB),'pending_marker_write_failed');
$GLOBALS['restoreOwnershipDb']=['epoch'=>$epochA,'pending'=>true,'floor'=>'0'];$GLOBALS['restoreOwnershipSql']=[];
restoreOwnershipCheck(CorrespondencePgStore::writeRestoreSentinelFile($marker,'pending',$epochA),'A_marker_before_pg_wait_failed');
$GLOBALS['restoreOwnershipAfterMarkerPrecheck']=static function()use($marker,$epochB):void{
    restoreOwnershipCheck(CorrespondencePgStore::writeRestoreSentinelFile($marker,'pending',$epochB),'B_pending_after_A_precheck_failed');
    $GLOBALS['restoreOwnershipDb']=['epoch'=>$epochB,'pending'=>true,'floor'=>'0'];
    restoreOwnershipCheck(CorrespondencePgStore::writeRestoreSentinelFile($marker,'clear',$epochB),'B_clear_after_A_precheck_failed');
    $GLOBALS['restoreOwnershipDb']['pending']=false;
};
$blockedBeginError=restoreOwnershipExpectFailure(fn()=>restoreOwnershipStore($marker)->beginRestoreEpoch($epochA,'10'),'begin_after_pg_wait_must_not_overwrite_B');
restoreOwnershipCheck($blockedBeginError instanceof DomainException&&$blockedBeginError->getMessage()==='restore_epoch_changed'&&$GLOBALS['restoreOwnershipDb']['epoch']===$epochB&&!$GLOBALS['restoreOwnershipDb']['pending']&&restoreOwnershipMarker($marker)===['schema'=>'correspondence.restore.v1','state'=>'clear','epoch'=>$epochB],'begin_after_pg_wait_must_preserve_B_completed_authority');
restoreOwnershipCheck(!array_filter($GLOBALS['restoreOwnershipSql'],static fn($sql)=>str_starts_with($sql,'UPDATE plugins.correspondence_restore_state SET epoch=')),'begin_after_pg_wait_must_reject_before_database_update');
restoreOwnershipCheck(CorrespondencePgStore::writeRestoreSentinelFile($marker,'pending',$epochB),'pending_marker_before_old_A_checks_failed');
$GLOBALS['restoreOwnershipDb']=['epoch'=>$epochA,'pending'=>true,'floor'=>'0'];$GLOBALS['restoreOwnershipSql']=[];
$beginError=restoreOwnershipExpectFailure(fn()=>restoreOwnershipStore($marker)->beginRestoreEpoch($epochA,'10'),'delayed_A_begin_must_reject_B_marker');
restoreOwnershipCheck($beginError instanceof DomainException&&in_array($beginError->getMessage(),['restore_epoch_changed','restore_sentinel_unavailable'],true),'delayed_A_begin_wrong_error');
restoreOwnershipCheck(restoreOwnershipMarker($marker)===['schema'=>'correspondence.restore.v1','state'=>'pending','epoch'=>$epochB],'delayed_A_begin_must_preserve_B_marker');
restoreOwnershipCheck(!in_array('BEGIN',$GLOBALS['restoreOwnershipSql'],true),'delayed_A_begin_must_not_touch_database');

$completeError=restoreOwnershipExpectFailure(fn()=>restoreOwnershipStore($marker)->completeRestoreEpoch($epochA),'old_A_completion_must_reject_B_marker');
restoreOwnershipCheck($completeError instanceof DomainException,'old_A_completion_wrong_error');
restoreOwnershipCheck($GLOBALS['restoreOwnershipDb']['epoch']===$epochA&&$GLOBALS['restoreOwnershipDb']['pending'],'old_A_completion_must_not_clear_database_pending');
restoreOwnershipCheck(restoreOwnershipMarker($marker)['epoch']===$epochB&&restoreOwnershipMarker($marker)['state']==='pending','old_A_completion_must_not_erase_B_marker');

$lock=@fopen($marker.'.lock','c+b');restoreOwnershipCheck(is_resource($lock)&&flock($lock,LOCK_EX|LOCK_NB),'restore_marker_lock_fixture_failed');
$lockError=restoreOwnershipExpectFailure(fn()=>restoreOwnershipStore($marker)->completeRestoreEpoch($epochA),'completion_must_fail_when_marker_lock_is_unavailable');
restoreOwnershipCheck($lockError instanceof DomainException&&$GLOBALS['restoreOwnershipDb']['pending'],'lock_failure_must_leave_database_pending');
restoreOwnershipCheck(restoreOwnershipMarker($marker)['epoch']===$epochB,'lock_failure_must_preserve_newer_marker');flock($lock,LOCK_UN);fclose($lock);

restoreOwnershipCheck(CorrespondencePgStore::writeRestoreSentinelFile($marker,'pending',$epochA),'race_fixture_marker_write_failed');
$GLOBALS['restoreOwnershipDb']=['epoch'=>$epochA,'pending'=>true,'floor'=>'0'];
$GLOBALS['restoreOwnershipBeforeClear']=static function()use($marker,$epochB):void{restoreOwnershipCheck(CorrespondencePgStore::writeRestoreSentinelFile($marker,'pending',$epochB),'racing_B_marker_write_failed');};
$raceError=restoreOwnershipExpectFailure(fn()=>restoreOwnershipStore($marker)->completeRestoreEpoch($epochA),'post_database_marker_recheck_must_reject_B');
restoreOwnershipCheck($raceError instanceof DomainException&&$GLOBALS['restoreOwnershipDb']['epoch']===$epochA&&!$GLOBALS['restoreOwnershipDb']['pending'],'A_database_clear_may_finish_but_must_not_claim_B_marker');
restoreOwnershipCheck(restoreOwnershipMarker($marker)['epoch']===$epochB&&restoreOwnershipMarker($marker)['state']==='pending','post_database_CAS_must_preserve_newer_pending_marker');
restoreOwnershipCheck(restoreOwnershipStore($marker)->restoreGeneration()===null,'newer_pending_marker_must_keep_every_effect_quarantined');

foreach(['missing','malformed']as$bad){
    $path=$markerDir.DIRECTORY_SEPARATOR.$bad.'.json';if($bad==='malformed')file_put_contents($path,'{broken');
    $GLOBALS['restoreOwnershipDb']=['epoch'=>$epochA,'pending'=>true,'floor'=>'0'];
    $error=restoreOwnershipExpectFailure(fn()=>restoreOwnershipStore($path)->beginRestoreEpoch($epochA,'10'),$bad.'_marker_must_fail_closed');
    restoreOwnershipCheck($error instanceof DomainException&&$GLOBALS['restoreOwnershipDb']['pending'],$bad.'_marker_must_not_change_database');@unlink($path);@unlink($path.'.lock');
}

$seedPath=$markerDir.DIRECTORY_SEPARATOR.'seed-race.json';
restoreOwnershipCheck(CorrespondencePgStore::writeRestoreSentinelFile($seedPath,'clear',$epochA),'seed_old_clear_marker_write_failed');
$GLOBALS['restoreOwnershipEmptyState']=true;$GLOBALS['restoreOwnershipDb']=['epoch'=>$epochA,'pending'=>false,'floor'=>'0'];$GLOBALS['restoreOwnershipTransaction']=PGSQL_TRANSACTION_IDLE;
$GLOBALS['restoreOwnershipBeforeBegin']=static function()use($seedPath,$epochB):void{restoreOwnershipCheck(CorrespondencePgStore::writeRestoreSentinelFile($seedPath,'pending',$epochB),'seed_racing_B_marker_write_failed');};
restoreOwnershipStore($seedPath)->ensureSchema();
restoreOwnershipCheck($GLOBALS['restoreOwnershipDb']['epoch']===$epochB&&$GLOBALS['restoreOwnershipDb']['pending']&&restoreOwnershipMarker($seedPath)===['schema'=>'correspondence.restore.v1','state'=>'pending','epoch'=>$epochB],'schema_seed_must_re_read_new_marker_inside_writer_transaction');
$GLOBALS['restoreOwnershipEmptyState']=false;$GLOBALS['restoreOwnershipDb']=['epoch'=>$epochA,'pending'=>false,'floor'=>'0'];
@unlink($seedPath);@unlink($seedPath.'.lock');

$completedSeedPath=$markerDir.DIRECTORY_SEPARATOR.'seed-complete-race.json';
restoreOwnershipCheck(CorrespondencePgStore::writeRestoreSentinelFile($completedSeedPath,'clear',$epochA),'seed_old_clear_before_B_completion_failed');
$GLOBALS['restoreOwnershipEmptyState']=true;$GLOBALS['restoreOwnershipDb']=['epoch'=>$epochA,'pending'=>false,'floor'=>'0'];$GLOBALS['restoreOwnershipTransaction']=PGSQL_TRANSACTION_IDLE;
$GLOBALS['restoreOwnershipBeforeBegin']=static function()use($completedSeedPath,$epochB):void{
    restoreOwnershipCheck(CorrespondencePgStore::writeRestoreSentinelFile($completedSeedPath,'pending',$epochB),'seed_B_pending_write_failed');
    $GLOBALS['restoreOwnershipDb']=['epoch'=>$epochB,'pending'=>true,'floor'=>'0'];$GLOBALS['restoreOwnershipEmptyState']=false;
    $GLOBALS['restoreOwnershipDb']['pending']=false;
    restoreOwnershipCheck(CorrespondencePgStore::writeRestoreSentinelFile($completedSeedPath,'clear',$epochB),'seed_B_clear_write_failed');
};
$seedSqlStart=count($GLOBALS['restoreOwnershipSql']);
restoreOwnershipStore($completedSeedPath)->ensureSchema();
restoreOwnershipCheck($GLOBALS['restoreOwnershipDb']['epoch']===$epochB&&!$GLOBALS['restoreOwnershipDb']['pending']&&restoreOwnershipMarker($completedSeedPath)===['schema'=>'correspondence.restore.v1','state'=>'clear','epoch'=>$epochB],'schema_seed_must_preserve_concurrently_completed_B');
$seedSql=array_slice($GLOBALS['restoreOwnershipSql'],$seedSqlStart);
restoreOwnershipCheck(!array_filter($seedSql,static fn($sql)=>str_starts_with($sql,'INSERT INTO plugins.correspondence_restore_state')),'schema_seed_must_skip_insert_when_B_row_exists');
$GLOBALS['restoreOwnershipEmptyState']=false;$GLOBALS['restoreOwnershipDb']=['epoch'=>$epochA,'pending'=>false,'floor'=>'0'];
@unlink($completedSeedPath);@unlink($completedSeedPath.'.lock');

$validPath=$markerDir.DIRECTORY_SEPARATOR.'valid.json';restoreOwnershipCheck(CorrespondencePgStore::writeRestoreSentinelFile($validPath,'pending',$epochA),'valid_marker_write_failed');
$GLOBALS['restoreOwnershipDb']=['epoch'=>$epochA,'pending'=>true,'floor'=>'0'];restoreOwnershipStore($validPath)->completeRestoreEpoch($epochA);
restoreOwnershipCheck($GLOBALS['restoreOwnershipDb']['pending']===false&&restoreOwnershipMarker($validPath)===['schema'=>'correspondence.restore.v1','state'=>'clear','epoch'=>$epochA],'matching_completion_must_clear_only_its_epoch');

$GLOBALS['restoreOwnershipDb']=['epoch'=>$epochA,'pending'=>false,'floor'=>'0'];$GLOBALS['restoreOwnershipTransaction']=PGSQL_TRANSACTION_IDLE;$GLOBALS['restoreOwnershipTimeout']='250ms';
$store=restoreOwnershipStore();$store->ensureSchema();$store->bindRequestClock(['inputtext','1800000000000000200','100']);$GLOBALS['restoreOwnershipTimeout']='250ms';
$writerErrors=[];$nestedCalls=0;$readSqlStart=count($GLOBALS['restoreOwnershipSql']);
$store->readTransaction(function()use($store,&$writerErrors,&$nestedCalls):void{
    foreach([
        fn()=>$store->transaction(function()use(&$nestedCalls):void{$nestedCalls++;}),
        fn()=>$store->appendEvent('correspondence_anchor','{}','','correspondence_v1'),
        fn()=>$store->queueCommand('executor','synthetic'),
        fn()=>$store->prepareNote('title','body'),
    ]as$write){try{$write();$writerErrors[]='accepted';}catch(Throwable$error){$writerErrors[]=$error->getMessage();}}
    $store->query('SELECT 1 AS fixture');
});
restoreOwnershipCheck($writerErrors===['writer_in_reader','writer_in_reader','writer_in_reader','writer_in_reader'],'all_side_effecting_writers_must_refuse_reader_transactions');
restoreOwnershipCheck($nestedCalls===0&&$GLOBALS['restoreOwnershipNoteCalls']===0,'reader_rejection_must_precede_callbacks_and_file_side_effects');
$closeEvent=$store->transaction(fn()=>$store->appendEvent('correspondence_operator_closed','{}','','correspondence_v1'));
restoreOwnershipCheck(($closeEvent['type']??null)==='info'&&($closeEvent['sess']??null)==='correspondence_v1','operator_close_event_carrier_must_be_allowed');
restoreOwnershipCheck($GLOBALS['restoreOwnershipTimeout']==='250ms','read_must_restore_original_session_lock_timeout');
$readSql=array_slice($GLOBALS['restoreOwnershipSql'],$readSqlStart);$timeoutIndexes=array_keys(array_filter($readSql,static fn($sql)=>$sql==="SELECT set_config('lock_timeout',$1,FALSE)"));
$sharedLockIndex=array_search('SELECT pg_advisory_lock_shared(18459814, 1)',$readSql,true);$beginIndex=array_search('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY',$readSql,true);
restoreOwnershipCheck(count($timeoutIndexes)===2&&$timeoutIndexes[0]<$sharedLockIndex&&$sharedLockIndex<$timeoutIndexes[1]&&$timeoutIndexes[1]<$beginIndex,'session_timeout_must_be_bounded_for_lock_acquisition_then_restored_before_begin');

$GLOBALS['restoreOwnershipTransaction']=1;$GLOBALS['restoreOwnershipSql']=[];
$idleError=restoreOwnershipExpectFailure(fn()=>$store->transaction(static fn()=>true),'store_must_not_begin_inside_external_transaction');
restoreOwnershipCheck($idleError instanceof DomainException&&$GLOBALS['restoreOwnershipSql']===[],'external_transaction_must_not_be_committed_or_modified');$GLOBALS['restoreOwnershipTransaction']=PGSQL_TRANSACTION_IDLE;

foreach([['claim_changed','stale'],['restore_unavailable','idle'],['recheck_metadata_invalid','idle']]as[$reason,$status]){
    Logger::$rows=[];correspondenceDiagnostic('autonomy_outcome',['status'=>$status,'reason'=>$reason]);
    restoreOwnershipCheck(count(Logger::$rows)===1&&str_contains(Logger::$rows[0],'"reason":"'.$reason.'"'),'autonomy_reason_must_survive_diagnostic_allowlist:'.$reason);
}

$GLOBALS['ENGINE_PATH']=sys_get_temp_dir().DIRECTORY_SEPARATOR.'correspondence-factory-failure-'.bin2hex(random_bytes(5));$GLOBALS['restoreOwnershipPgMode']='fail';$GLOBALS['restoreOwnershipConnected']=0;$GLOBALS['restoreOwnershipClosed']=0;
for($attempt=0;$attempt<2;$attempt++){
    $error=restoreOwnershipExpectFailure(static fn()=>correspondenceProduction(),'factory_failure_must_propagate');
    restoreOwnershipCheck($error instanceof RuntimeException&&$error->getMessage()==='storage_failed','factory_failure_must_preserve_original_error');
}
restoreOwnershipCheck($GLOBALS['restoreOwnershipConnected']===1&&$GLOBALS['restoreOwnershipClosed']===1,'failed_factory_must_be_memoized_and_close_its_connection');

foreach(glob($markerDir.DIRECTORY_SEPARATOR.'*')?:[]as$file)if(is_file($file))@unlink($file);@rmdir($markerDir);
echo "PASS restore ownership, connection hygiene, reader writer guards, timeout restoration, and autonomy reason allowlist\n";
