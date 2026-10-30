<?php
declare(strict_types=1);

function manualCoreCheck(bool $ok,string $reason):void{if(!$ok)throw new RuntimeException($reason);}
function manualCoreEnv(string $name):string
{
    $value=getenv('CORRESPONDENCE_MANUAL_'.$name);
    manualCoreCheck(is_string($value)&&$value!=='','explicit_fixture_'.$name.'_required');return $value;
}
function manualCoreSql(PgSql\Connection $db,string $sql,array $args=[]):array
{
    $result=$args===[]?pg_query($db,$sql):pg_query_params($db,$sql,$args);
    manualCoreCheck($result instanceof PgSql\Result,'fixture_sql_failed');
    try{return pg_num_fields($result)>0?(pg_fetch_all($result)?:[]):[];}finally{pg_free_result($result);}
}

// The fixture supplies the captured gate result; DataDequeue still filters actual rows by its generation.
if(!function_exists('chimInteractionAllowed')){
    function chimInteractionAllowed():bool
    {
        return ($GLOBALS['manual_core_interaction_enabled']??false)===true
            &&is_int($GLOBALS['chim_interaction_generation']??null)
            &&$GLOBALS['chim_interaction_generation']===($GLOBALS['manual_core_expected_generation']??null);
    }
}
if(!function_exists('logEvent')){function logEvent(array $request):void{}}

function manualDeliveryCoreRoundTrip(PgSql\Connection $db,string $documentId,string $expectedActor):array
{
    manualCoreCheck(preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/D',$documentId)===1,'document_uuid_required');
    manualCoreCheck(preg_match('/\A[^|@\r\n]{1,200}\z/D',$expectedActor)===1,'expected_actor_required');
    $generation=$GLOBALS['chim_interaction_generation']??null;
    manualCoreCheck(is_int($generation)&&$generation>=0&&$generation===($GLOBALS['manual_core_expected_generation']??null),'captured_interaction_generation_required');
    manualCoreCheck(chimInteractionAllowed(),'captured_interaction_generation_unavailable');
    manualCoreCheck(($GLOBALS['manual_core_owned_connection']??null)===$db,'owned_database_connection_required');
    manualCoreCheck(!function_exists('DataDequeue')&&!function_exists('chimInteractionIsGameOutput'),'isolated_core_child_required');
    require_once __DIR__.'/correspondence_core_function.php';
    $core=getenv('CORRESPONDENCE_MANUAL_CORE_ROOT');
    manualCoreCheck(is_string($core)&&is_dir($core),'explicit_captured_core_root_required');
    $dataFunctions=$core.'/data_functions.php';$interaction=$core.'/chim_interaction.php';$commPath=$core.'/comm.php';
    correspondenceLoadPinnedFunction($dataFunctions,'50338dab670806b37600ec3a65e1762ee638a0c1656262ced979b4aa5cfc3a0c','DataDequeue');
    correspondenceLoadPinnedFunction($interaction,'0f85286f47da7f02b8527ba9099e3d93452185399a9c0f66918072b877ccb6de','chimInteractionIsGameOutput');
    $comm=file_get_contents($commPath);
    $commHash=is_string($comm)?hash('sha256',$comm):'';
    manualCoreCheck($commHash==='fc0a3f75bac47f8899a7c1c550aa1dafc3a59fdf36de39c0f1cb7722e432612c','exact_captured_core_request_processor_required');
    $start=strpos($comm,'} elseif ($gameRequest[0] == "request") { // Just requested response');
    $start=$start===false?false:strpos($comm,"\n",$start);
    $end=$start===false?false:strpos($comm,'    // NEW METHODS FROM HERE',$start);
    manualCoreCheck(is_int($start)&&is_int($end),'exact_request_response_branch_required');
    $branch=substr($comm,$start+1,$end-$start-1);
    manualCoreCheck($branch!=='','empty_request_response_branch');

    $identity=manualCoreSql($db,"SELECT d.payload::text AS document,o.payload::text AS operation FROM plugins.correspondence_documents d JOIN plugins.correspondence_operations o ON o.document_id=d.document_id AND o.operation_id=(d.payload->>'operation_id')::uuid WHERE d.document_id=$1::uuid",[$documentId]);
    manualCoreCheck(count($identity)===1,'dispatched_document_operation_required');
    $document=json_decode($identity[0]['document'],true,64,JSON_THROW_ON_ERROR);
    $operation=json_decode($identity[0]['operation'],true,64,JSON_THROW_ON_ERROR);
    manualCoreCheck(($document['document_id']??null)===$documentId&&is_string($document['operation_id']??null),'saved_document_identity_required');
    manualCoreCheck(($operation['document_id']??null)===$documentId&&($operation['operation_id']??null)===$document['operation_id'],'saved_operation_identity_required');
    manualCoreCheck(($document['title']??null)==='letter '.$documentId,'saved_document_title_required');
    $documentItems=$document['attachments']??null;$operationItems=$operation['attachments']??null;
    manualCoreCheck(is_array($documentItems)&&count($documentItems)===1&&($documentItems[0]['kind']??null)==='gold'&&($documentItems[0]['form_id']??null)==='Skyrim.esm|0000000F'&&($documentItems[0]['quantity']??null)===500,'original_500_gold_document_required');
    manualCoreCheck(is_array($operationItems)&&count($operationItems)===1&&($operationItems[0]['kind']??null)==='gold'&&($operationItems[0]['plugin']??null)==='Skyrim.esm'&&($operationItems[0]['local_id']??null)===15&&($operationItems[0]['quantity']??null)===500,'physical_500_gold_operation_required');
    $columns=manualCoreSql($db,"SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name='responselog' AND column_name IN ('rowid','interaction_generation')");
    manualCoreCheck(count($columns)===2,'core_responselog_rowid_and_generation_columns_required');
    $pending=manualCoreSql($db,'SELECT actor,action,text,interaction_generation FROM public.responselog WHERE sent=0 ORDER BY rowid');
    manualCoreCheck(count($pending)===2,'only_manual_delivery_and_renderer_may_be_pending');
    manualCoreCheck(($pending[0]['actor']??null)==='rolemaster'&&($pending[0]['action']??null)==='rolecommand'&&($pending[0]['text']??null)==='generateLetter@'.$document['title']&&($pending[1]['actor']??null)===$expectedActor&&($pending[1]['action']??null)==='command','pending_renderer_and_delivery_rows_required');
    foreach($pending as $row)manualCoreCheck((int)($row['interaction_generation']??-1)===$generation,'pending_response_generation_mismatch');

    $priorDbExists=array_key_exists('db',$GLOBALS);$priorDb=$GLOBALS['db']??null;
    $GLOBALS['db']=new class($db){public array $claimed=[];public function __construct(private PgSql\Connection $connection){}public function fetchAll(string $sql):array{$result=pg_query($this->connection,$sql);manualCoreCheck($result instanceof PgSql\Result,'actual_core_dequeue_sql_failed');try{return $this->claimed=pg_num_fields($result)>0?(pg_fetch_all($result)?:[]):[];}finally{pg_free_result($result);}}};
    $run=static function()use($branch):array{
        $gameRequest=['request','1800000000000000999','30000000','manual core fixture'];$MUST_END=false;ob_start();
        try{eval($branch);$output=(string)ob_get_contents();}finally{ob_end_clean();}
        manualCoreCheck($MUST_END===true,'native_request_branch_must_end');
        return ['output'=>$output,'claimed'=>$GLOBALS['db']->claimed];
    };
    try{
        $first=$run();$rawLines=array_values(array_filter(explode("\r\n",$first['output']),static fn(string $line):bool=>$line!==''));
        manualCoreCheck(count($rawLines)===2&&count($first['claimed'])===2,'actual_core_must_return_exactly_two_pending_lines');
        $parsed=[];
        foreach($rawLines as $line){
            $parts=explode('|',$line,3);$expectedLineActor=($parts[1]??null)==='rolecommand'?'rolemaster':$expectedActor;manualCoreCheck(count($parts)===3&&$parts[0]===$expectedLineActor&&$parts[1]!==''&&$parts[2]!=='','native_actor_channel_payload_line_required');
            $matches=array_values(array_filter($first['claimed'],static fn(array $row):bool=>($row['actor']??null)===$parts[0]&&($row['action']??null)===$parts[1]&&($row['text']??null)===$parts[2]&&(int)($row['interaction_generation']??-1)===$generation));
            manualCoreCheck(count($matches)===1,'native_line_must_match_one_actual_core_dequeued_row');
            $parsed[]=['line'=>$line,'actor'=>$parts[0],'action'=>$parts[1],'text'=>$parts[2],'rowid'=>(string)$matches[0]['rowid'],'interaction_generation'=>(int)$matches[0]['interaction_generation']];
        }
        manualCoreCheck(array_column($parsed,'action')===['rolecommand','command'],'renderer_then_delivery_native_order_required');
        manualCoreCheck($parsed[0]['text']==='generateLetter@'.$document['title']&&!str_ends_with($parsed[0]['text'],'@'),'renderer_title_must_not_have_trailing_separator');
        manualCoreCheck($parsed[1]['action']==='command','delivery_command_channel_required');
        $commandParts=explode('@',$parsed[1]['text'],2);
        manualCoreCheck(count($commandParts)===2&&$commandParts[0]==='ExtCmdCHIMCorrespondenceBridge_Deliver','delivery_command_name_required');
        $wire=json_decode($commandParts[1],true,32,JSON_THROW_ON_ERROR);
        $wireKeys=array_fill_keys(['protocol_version','document_id','operation_id','receipt_id','playthrough_id','scope_token','nonce','recipient','payer','title','renderer_key','attachments'],true);
        manualCoreCheck($wire===array_intersect_key($operation,$wireKeys),'delivery_wire_must_preserve_saved_operation');
        manualCoreCheck(($wire['document_id']??null)===$documentId&&($wire['operation_id']??null)===$document['operation_id']&&($wire['attachments'][0]['quantity']??null)===500,'delivery_wire_must_preserve_document_operation_and_500_gold');
        $second=$run();
        manualCoreCheck($second['output']===''&&$second['claimed']===[],'actual_core_dequeue_must_be_once_only');
        return ['document_id'=>$documentId,'operation_id'=>$document['operation_id'],'core_sha256'=>['data_functions'=>hash_file('sha256',$dataFunctions),'chim_interaction'=>hash_file('sha256',$interaction),'comm'=>$commHash],'database_identity'=>$GLOBALS['manual_core_database_identity'],'lines'=>$parsed,'second_dequeue'=>[],'marks'=>['server'=>'SHA-256-pinned captured DataDequeue and exact captured comm.php request-response branch executed','database'=>'owned socket-only non-superuser PostgreSQL identity validated; document, operation, and responselog rows read from that database','generation'=>'fixture gate requires enabled state and exact current-generation match; captured DataDequeue filters returned rows by that generation','native_game'=>false]];
    }finally{if($priorDbExists)$GLOBALS['db']=$priorDb;else unset($GLOBALS['db']);}
}

function manualCoreOwnedConnection():PgSql\Connection
{
    manualCoreCheck(PHP_SAPI==='cli'&&extension_loaded('pgsql')&&extension_loaded('tokenizer'),'isolated_cli_pgsql_tokenizer_required');
    foreach(getenv() as $name=>$_)manualCoreCheck(!str_starts_with(strtoupper((string)$name),'PG'),'ambient_postgres_environment_forbidden');
    $dsn=manualCoreEnv('DSN');$database=manualCoreEnv('DATABASE');$role=manualCoreEnv('ROLE');$system=manualCoreEnv('SYSTEM_ID');$directory=manualCoreEnv('DATA_DIRECTORY');$port=manualCoreEnv('PORT');
    manualCoreCheck(preg_match('/\Asf_manual_[a-f0-9]{12}\z/D',$database)===1&&$role===$database,'owned_database_and_role_required');
    manualCoreCheck(preg_match('#\A/var/tmp/sf-manual-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{8}/postgres/data\z#D',$directory)===1&&$port==='5432'&&preg_match('/\A[0-9]+\z/D',$system)===1,'owned_test2_identity_required');
    $socket=dirname(dirname($directory)).'/socket';
    manualCoreCheck(preg_match('/(?:^|\s)host=([^\s]+)(?:\s|$)/',$dsn,$host)===1&&$host[1]===$socket&&!preg_match('/(?:^|\s)hostaddr=/i',$dsn),'owned_socket_dsn_required');
    $db=@pg_connect($dsn,PGSQL_CONNECT_FORCE_NEW);manualCoreCheck($db instanceof PgSql\Connection,'owned_database_connection_required');
    $identity=manualCoreSql($db,"SELECT current_database() AS database,current_user AS role,current_setting('data_directory') AS data_directory,current_setting('port') AS port,current_setting('listen_addresses') AS listeners,current_setting('unix_socket_directories') AS sockets,(inet_server_addr() IS NULL)::int AS socket_only,system_identifier::text AS system_id,(SELECT (NOT rolsuper)::text FROM pg_roles WHERE rolname=current_user) AS non_superuser FROM pg_control_system()")[0]??[];
    manualCoreCheck($identity===['database'=>$database,'role'=>$role,'data_directory'=>$directory,'port'=>$port,'listeners'=>'','sockets'=>$socket,'socket_only'=>'1','system_id'=>$system,'non_superuser'=>'true'],'owned_non_superuser_socket_database_identity_mismatch');
    $GLOBALS['manual_core_database_identity']=['database'=>$database,'role'=>$role,'data_directory'=>$directory,'port'=>$port,'socket'=>$socket,'listeners'=>'','socket_only'=>true,'system_id'=>$system,'superuser'=>false];
    return $db;
}

if(PHP_SAPI==='cli'&&realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    try{
        $input=json_decode((string)stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
        manualCoreCheck(is_array($input),'request_json_required');$keys=array_keys($input);sort($keys);
        manualCoreCheck($keys===['document_id','expected_actor','interaction_generation']&&is_string($input['document_id']??null)&&is_string($input['expected_actor']??null)&&is_int($input['interaction_generation'])&&$input['interaction_generation']>=0,'document_actor_and_generation_json_required');
        $GLOBALS['chim_interaction_generation']=$input['interaction_generation'];$GLOBALS['manual_core_expected_generation']=$input['interaction_generation'];$GLOBALS['manual_core_interaction_enabled']=true;
        $db=manualCoreOwnedConnection();$GLOBALS['manual_core_owned_connection']=$db;$result=manualDeliveryCoreRoundTrip($db,$input['document_id'],$input['expected_actor']);
        echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).PHP_EOL;pg_close($db);
    }catch(Throwable $e){fwrite(STDERR,'manual_core_dequeue: '.$e->getMessage().PHP_EOL);exit(1);}
}
