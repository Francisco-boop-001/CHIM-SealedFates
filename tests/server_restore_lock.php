<?php
declare(strict_types=1);

final class RestoreLockFakePgResult
{
    public function __construct(public array $rows) {}
}

$GLOBALS['restoreLockMode']='success';
$GLOBALS['restoreLockClosed']=0;
$GLOBALS['restoreLockTransaction']=0;
$GLOBALS['restoreLockTimeout']='250ms';
if(!defined('PGSQL_TRANSACTION_IDLE'))define('PGSQL_TRANSACTION_IDLE',0);
if(!function_exists('pg_transaction_status')){function pg_transaction_status(mixed $connection):int{return $GLOBALS['restoreLockTransaction'];}}

if(!function_exists('pg_query_params')){
    function pg_query_params(mixed $connection,string $sql,array $parameters=[]):mixed
    {
        if(str_starts_with($sql,"SELECT current_setting('lock_timeout')"))return new RestoreLockFakePgResult([['lock_timeout'=>$GLOBALS['restoreLockTimeout']]]);
        if(str_starts_with($sql,"SELECT set_config('lock_timeout'")){$GLOBALS['restoreLockTimeout']=$parameters[0];return new RestoreLockFakePgResult([['set_config'=>$parameters[0]]]);}
        if($sql==='BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY'){$GLOBALS['restoreLockTransaction']=1;return new RestoreLockFakePgResult([]);}
        if($sql==='COMMIT'||$sql==='ROLLBACK'){$GLOBALS['restoreLockTransaction']=PGSQL_TRANSACTION_IDLE;return new RestoreLockFakePgResult([]);}
        if(str_contains($sql,'pg_advisory_unlock_shared')){
            if($GLOBALS['restoreLockMode']==='query_failure')return false;
            return new RestoreLockFakePgResult([['released'=>$GLOBALS['restoreLockMode']==='false'?'f':'t']]);
        }
        if(str_contains($sql,"to_regclass('plugins.correspondence_documents')"))return new RestoreLockFakePgResult([['documents'=>'t','operations'=>'t','presence'=>'t','restore_state'=>'t','written_source_index'=>'t','restore_floor'=>'t']]);
        if(str_contains($sql,"WHERE c.oid=to_regclass('public.correspondence_written_evidence_document_id_idx')"))return new RestoreLockFakePgResult([['relkind'=>'i','access_method'=>'btree','indisvalid'=>'t','indisready'=>'t','indisunique'=>'f','indisprimary'=>'f','indnatts'=>'1','indnkeyatts'=>'1','on_eventlog'=>'t','expression'=>"CASE WHEN type='info' AND sess='written_evidence_v1' THEN data::jsonb->>'document_id' ELSE NULL::text END",'predicate'=>"type='info' AND sess='written_evidence_v1'"]]);
        if(str_contains($sql,'FROM information_schema.columns')){
            $rows=[];
            foreach([
                'correspondence_documents'=>['document_id','playthrough_id','request_id','payload','created_at'],
                'correspondence_operations'=>['operation_id','document_id','payload','created_at'],
                'correspondence_presence'=>['playthrough_id','payload'],
                'correspondence_restore_state'=>['singleton','epoch','pending','native_ts_floor'],
            ] as $table=>$columns)foreach($columns as $column)$rows[]=['table_name'=>$table,'column_name'=>$column];
            return new RestoreLockFakePgResult($rows);
        }
        if(str_contains($sql,'SELECT epoch::text,pending FROM plugins.correspondence_restore_state'))return new RestoreLockFakePgResult([['epoch'=>'11111111-1111-4111-8111-111111111111','pending'=>'f']]);
        if(str_contains($sql,'SELECT 1 AS fixture'))return new RestoreLockFakePgResult([['fixture'=>'1']]);
        return new RestoreLockFakePgResult([]);
    }
}
if(!function_exists('pg_num_fields')){function pg_num_fields(RestoreLockFakePgResult $result):int{return $result->rows===[]?0:count($result->rows[0]);}}
if(!function_exists('pg_fetch_all')){function pg_fetch_all(RestoreLockFakePgResult $result):array|false{return $result->rows===[]?false:$result->rows;}}
if(!function_exists('pg_free_result')){function pg_free_result(RestoreLockFakePgResult $result):bool{return true;}}
if(!function_exists('pg_close')){function pg_close(mixed $connection):bool{$GLOBALS['restoreLockClosed']++;return true;}}

require_once dirname(__DIR__).'/server/lib/store.php';

function restoreLockCheck(bool $ok,string $reason):void{if(!$ok)throw new RuntimeException($reason);}
function restoreLockStore():CorrespondencePgStore
{
    $store=new CorrespondencePgStore(new stdClass(),static fn($unused)=>null,static fn($title,$body)=>true);
    $store->ensureSchema();
    return $store;
}

foreach(['query_failure','false','success'] as $mode){
    $GLOBALS['restoreLockMode']=$mode;$GLOBALS['restoreLockClosed']=0;
    $store=restoreLockStore();$failed=false;
    try{$rows=$store->query('SELECT 1 AS fixture');}
    catch(RuntimeException $error){$failed=$error->getMessage()==='storage_failed';$rows=[];}
    if($mode==='success'){
        restoreLockCheck(!$failed&&($rows[0]['fixture']??null)==='1','successful_unlock_rejected');
        restoreLockCheck($GLOBALS['restoreLockClosed']===0,'successful_unlock_closed_connection');
    }else{
        restoreLockCheck($failed,'failed_unlock_returned_read_result');
        restoreLockCheck($GLOBALS['restoreLockClosed']===1,'failed_unlock_did_not_close_connection');
    }
}

echo "PASS restore read-lock unlock: query failure, false result, success\n";
