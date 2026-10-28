<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/server/lib/store.php';

function historyPgCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function historyPgEnv(string $name):string
{
    $value=getenv($name);
    if(!is_string($value)||$value==='')throw new RuntimeException('missing_explicit_fixture_guard_'.$name);
    return $value;
}
function historyPgSql(mixed $connection,string $sql,array $parameters=[]):array
{
    $result=$parameters===[]?@pg_query($connection,$sql):@pg_query_params($connection,$sql,$parameters);
    if($result===false)throw new RuntimeException('offline_fixture_sql_failed');
    try{return pg_num_fields($result)>0?(pg_fetch_all($result)?:[]):[];}finally{pg_free_result($result);}
}
function historyPgUuid():string{return correspondenceUuid();}
function historyPgSource(string $documentId):string
{
    $source=json_decode(file_get_contents(dirname(__DIR__).'/contracts/written-evidence-v1.json'),true,512,JSON_THROW_ON_ERROR);
    $source['document_id']=$documentId;
    return correspondenceJson($source);
}
function historyPgAppend(CorrespondencePgStore $store,string $documentId):array
{
    $data=historyPgSource($documentId);
    return $store->transaction(fn()=>$store->appendEvent('written_evidence',$data,'|Camilla|','written_evidence_v1'));
}
function historyPgDuplicate(CorrespondencePgStore $store,string $documentId,string $reason='duplicate_written_source'):void
{
    try{historyPgAppend($store,$documentId);}catch(DomainException $e){historyPgCheck($e->getMessage()===$reason,'wrong_duplicate_or_quarantine_reason');return;}
    throw new RuntimeException('duplicate_written_source_accepted');
}
function historyPgRejectIndexDefinition(CorrespondencePgStore $store,mixed $connection,string $sql):void
{
    historyPgSql($connection,'DROP INDEX IF EXISTS public.correspondence_written_evidence_document_id_idx');
    historyPgSql($connection,$sql);
    try{$store->ensureSchema();throw new RuntimeException('invalid_same_named_source_index_accepted');}catch(DomainException $error){historyPgCheck($error->getMessage()==='written_source_index_unavailable','invalid_index_signature_must_fail_closed');}
    historyPgSql($connection,'DROP INDEX public.correspondence_written_evidence_document_id_idx');
}
function historyPgNormalizePath(string $path):string{return strtolower(rtrim(str_replace('/','\\',$path),'\\'));}
function historyPgConcurrentIndexInstall(mixed $connection,string $dsn,string $signalToken):void
{
    historyPgCheck(function_exists('proc_open'),'concurrent_index_regression_requires_proc_open');
    $migration=@file_get_contents(dirname(__DIR__).'/server/migrations/006_history_indexes.sql');
    historyPgCheck(is_string($migration)&&$migration!=='','source_index_migration_unavailable');
    historyPgSql($connection,'BEGIN');
    $child=null;$pipes=[];$parentTransaction=true;
    try{
        historyPgSql($connection,'SELECT pg_advisory_xact_lock(18459814,1)');
        historyPgSql($connection,'LOCK TABLE public.eventlog IN SHARE ROW EXCLUSIVE MODE');
        $childCode=<<<'PHP'
$connection=@pg_connect(getenv('CORRESPONDENCE_OFFLINE_PG_DSN'),PGSQL_CONNECT_FORCE_NEW);
if($connection===false){fwrite(STDERR,"child_connect_failed\n");exit(31);}
$ready=pg_query($connection,"SELECT to_regclass('public.correspondence_written_evidence_document_id_idx') IS NULL AS missing");
if($ready===false||pg_fetch_result($ready,0,0)!=='t'){fwrite(STDERR,"stale_read_not_reproduced\n");exit(32);}
$signal=__SIGNAL__;
if(pg_query_params($connection,'INSERT INTO plugins.correspondence_history_test_signal(token) VALUES($1)',[$signal])===false){fwrite(STDERR,pg_last_error($connection)."\n");exit(33);}
echo "seen_missing\n";fflush(STDOUT);
foreach(['BEGIN',"SET LOCAL lock_timeout='3s'",'SELECT pg_advisory_xact_lock(18459814,1)','LOCK TABLE public.eventlog IN SHARE ROW EXCLUSIVE MODE'] as $sql){if(pg_query($connection,$sql)===false){fwrite(STDERR,$sql.':'.pg_last_error($connection)."\n");exit(34);}}
$migration=__MIGRATION__;
if(pg_query_params($connection,$migration,[])===false){fwrite(STDERR,pg_last_error($connection)."\n");pg_query($connection,'ROLLBACK');exit(35);}
if(pg_query($connection,'COMMIT')===false){fwrite(STDERR,pg_last_error($connection)."\n");exit(36);}
echo "schema_ready\n";
PHP;
        $childCode=str_replace('__MIGRATION__',var_export($migration,true),$childCode);
        $childCode=str_replace('__SIGNAL__',var_export($signalToken,true),$childCode);
        $extensionDir=dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'ext';
        $command=[PHP_BINARY,'-n','-d','extension='.rtrim($extensionDir,'\\/').DIRECTORY_SEPARATOR.'php_pgsql.dll','-r',$childCode];
        $child=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
        historyPgCheck(is_resource($child),'concurrent_schema_worker_start_failed');
        fclose($pipes[0]);
        stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
        $out='';$staleRead=false;
        for($attempt=0;$attempt<50;$attempt++){
            $rows=historyPgSql($connection,'SELECT EXISTS(SELECT 1 FROM plugins.correspondence_history_test_signal WHERE token=$1) AS signaled',[$signalToken]);
            if(($rows[0]['signaled']??null)==='t'){$staleRead=true;break;}
            $status=proc_get_status($child);
            if(!$status['running'])break;
            usleep(100000);
        }
        if(!$staleRead){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);throw new RuntimeException('second_schema_installer_must_observe_first_uncommitted_index:'.$out.':'.$err);}
        historyPgSql($connection,$migration);
        historyPgSql($connection,'COMMIT');$parentTransaction=false;
        stream_set_blocking($pipes[1],true);stream_set_blocking($pipes[2],true);$out.=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        $exit=proc_close($child);$child=null;
        historyPgCheck($exit===0&&str_contains($out,'schema_ready')&&$err==='','concurrent_schema_installer_failed:'.$out.':'.$err);
    }catch(Throwable $error){
        if($parentTransaction)@pg_query($connection,'ROLLBACK');
        if(is_resource($child)){@proc_terminate($child);foreach($pipes as $pipe)if(is_resource($pipe))@fclose($pipe);@proc_close($child);}
        throw $error;
    }
}

historyPgCheck(PHP_OS_FAMILY==='Windows','Windows-owned PostgreSQL fixture required');
$dsn=historyPgEnv('CORRESPONDENCE_OFFLINE_PG_DSN');
$expectedDatabase=historyPgEnv('CORRESPONDENCE_OFFLINE_PG_DATABASE');
$expectedRole=historyPgEnv('CORRESPONDENCE_OFFLINE_PG_ROLE');
$expectedSystemId=historyPgEnv('CORRESPONDENCE_OFFLINE_PG_SYSTEM_ID');
$expectedDataDirectory=historyPgEnv('CORRESPONDENCE_OFFLINE_PG_DATA_DIRECTORY');
$expectedPort=historyPgEnv('CORRESPONDENCE_OFFLINE_PG_PORT');
$expectedListenAddresses=historyPgEnv('CORRESPONDENCE_OFFLINE_PG_LISTEN_ADDRESSES');
$connection=@pg_connect($dsn,PGSQL_CONNECT_FORCE_NEW);
historyPgCheck($connection!==false,'owned_postgresql_connection_required');
$fixtureCreated=false;$helperRole=null;$helperMembership=false;$blocker=null;
try{
    $identity=historyPgSql($connection,"SELECT current_database() AS database,current_user AS role,current_setting('data_directory') AS data_directory,current_setting('port') AS port,current_setting('listen_addresses') AS listen_addresses,current_setting('server_version_num') AS version,system_identifier::text AS system_id FROM pg_control_system()")[0]??[];
    historyPgCheck(($identity['database']??null)===$expectedDatabase&&($identity['role']??null)===$expectedRole&&($identity['system_id']??null)===$expectedSystemId,'owned_postgresql_identity_mismatch');
    historyPgCheck(historyPgNormalizePath((string)($identity['data_directory']??''))===historyPgNormalizePath($expectedDataDirectory),'owned_postgresql_data_directory_mismatch');
    historyPgCheck(($identity['port']??null)===$expectedPort&&($identity['listen_addresses']??null)===$expectedListenAddresses,'owned_postgresql_listener_mismatch');
    historyPgCheck((int)($identity['version']??0)>=170000&&(int)($identity['version']??0)<180000,'postgresql_17_required');
    $existing=historyPgSql($connection,"SELECT to_regclass('public.eventlog') AS eventlog,to_regclass('plugins.correspondence_documents') AS documents,to_regclass('public.correspondence_written_evidence_document_id_idx') AS written_source_index")[0]??[];
    $schemas=historyPgSql($connection,"SELECT to_regnamespace('plugins') AS plugins,to_regnamespace('chim_meta') AS chim_meta")[0]??[];
    historyPgCheck(($existing['eventlog']??null)===null&&($existing['documents']??null)===null&&($existing['written_source_index']??null)===null&&($schemas['plugins']??null)===null&&($schemas['chim_meta']??null)===null,'fixture_database_must_be_fresh');

    $fixtureCreated=true;
    historyPgSql($connection,<<<'SQL'
CREATE TABLE public.eventlog(rowid bigserial PRIMARY KEY,ts bigint NOT NULL,gamets bigint NOT NULL,type varchar NOT NULL,data text NOT NULL,sess varchar NOT NULL,localts bigint NOT NULL,people text NOT NULL,location text NOT NULL,party text NOT NULL);
CREATE TABLE public.core_npc_master(id bigint PRIMARY KEY,npc_name text,refid text,profile_id bigint);
CREATE TABLE public.core_player(id text PRIMARY KEY,value text);
CREATE SCHEMA chim_meta;
CREATE TABLE chim_meta.playthrough_profiles(id text PRIMARY KEY,is_active boolean NOT NULL DEFAULT false);
INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(100,100,'inputtext','fixture seed','',100,'','','');
SQL
    );
    $legacyId=historyPgUuid();
    $legacyData=correspondenceJson(['document_id'=>$legacyId]);
    historyPgSql($connection,"INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(100,100,'info',$1,'written_evidence_v1',100,'|Camilla|','',''),(100,100,'info',$1,'written_evidence_v1',100,'|Camilla|','','')",[$legacyData]);
    historyPgSql($connection,"INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(100,100,'info','ordinary non-json telemetry','other',100,'','',''),(100,100,'inputtext',$1,'written_evidence_v1',100,'','',''),(100,100,'info','{}','written_evidence_v1',100,'','','')",[$legacyData]);
    historyPgSql($connection,"INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) SELECT 100,100,'info',json_build_object('document_id','plan-'||g)::text,'written_evidence_v1',100,'','','' FROM generate_series(1,4096) AS g");
    historyPgSql($connection,'ANALYZE public.eventlog');
    $lookupSql="SELECT rowid FROM public.eventlog WHERE type='info' AND sess='written_evidence_v1' AND CASE WHEN type='info' AND sess='written_evidence_v1' THEN data::jsonb->>'document_id' END='plan-2048' LIMIT 1";
    $beforePlan=implode("\n",array_column(historyPgSql($connection,'EXPLAIN (ANALYZE,BUFFERS,TIMING OFF) '.$lookupSql),'QUERY PLAN'));
    historyPgCheck(str_contains($beforePlan,'Seq Scan on eventlog')&&preg_match('/Rows Removed by Filter: [1-9]/',$beforePlan)===1,'baseline_duplicate_lookup_must_show_history_scan');

    $store=new CorrespondencePgStore($connection,fn($s)=>null,fn($title,$body)=>true);
    $store->ensureSchema();
    historyPgSql($connection,'CREATE TABLE plugins.correspondence_history_test_signal(token text PRIMARY KEY)');
    $indexReady=historyPgSql($connection,"SELECT i.indisvalid,i.indisready,pg_get_userbyid(c.relowner) AS owner FROM pg_index i JOIN pg_class c ON c.oid=i.indexrelid WHERE c.oid=to_regclass('public.correspondence_written_evidence_document_id_idx')")[0]??[];
    historyPgCheck(($indexReady['indisvalid']??null)==='t'&&($indexReady['indisready']??null)==='t'&&($indexReady['owner']??null)===$expectedRole,'partial_source_index_must_be_valid_and_owned_by_migration_role');
    historyPgSql($connection,'ANALYZE public.eventlog');
    $store->bindRequestClock(['inputtext','200','200']);
    historyPgDuplicate($store,$legacyId);

    $exactMarkerId=historyPgUuid();
    historyPgSql($connection,"INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(100,100,'inputtext',$1,'written_evidence_v1',100,'','',''),(100,100,'info',$1,'other',100,'','','')",[correspondenceJson(['document_id'=>$exactMarkerId])]);
    $exact=$store->transaction(fn()=>$store->appendEvent('written_evidence',historyPgSource($exactMarkerId),'|Camilla|','written_evidence_v1'));
    historyPgCheck(($exact['type']??null)==='info'&&($exact['sess']??null)==='written_evidence_v1','new_source_physical_marker_changed');

    $newId=historyPgUuid();
    $beforeCount=(int)(historyPgSql($connection,"SELECT count(*)::int AS count FROM public.eventlog WHERE type='info' AND sess='written_evidence_v1'")[0]['count']??0);
    historyPgAppend($store,$newId);
    historyPgDuplicate($store,$newId);
    $afterCount=(int)(historyPgSql($connection,"SELECT count(*)::int AS count FROM public.eventlog WHERE type='info' AND sess='written_evidence_v1'")[0]['count']??0);
    historyPgCheck($afterCount===$beforeCount+1,'replay_must_not_publish_twice');

    $rollbackId=historyPgUuid();
    try{$store->transaction(function()use($store,$rollbackId):void{$store->appendEvent('written_evidence',historyPgSource($rollbackId),'|Camilla|','written_evidence_v1');throw new RuntimeException('fixture_rollback');});}catch(RuntimeException $e){historyPgCheck($e->getMessage()==='fixture_rollback','wrong_rollback_exception');}
    historyPgCheck(historyPgSql($connection,'SELECT 1 FROM public.eventlog WHERE data=$1',[historyPgSource($rollbackId)])===[],'rollback_must_remove_event');
    historyPgAppend($store,$rollbackId);

    $prunedId=historyPgUuid();
    $pruned=historyPgAppend($store,$prunedId);
    historyPgSql($connection,'DELETE FROM public.eventlog WHERE rowid=$1',[(string)$pruned['rowid']]);
    $reexposed=historyPgAppend($store,$prunedId);
    historyPgCheck((int)$reexposed['rowid']>(int)$pruned['rowid'],'pruned_source_must_remain_reexposable');

    $reusedId=historyPgUuid();
    $reused=historyPgAppend($store,$reusedId);
    historyPgSql($connection,'DELETE FROM public.eventlog WHERE rowid=$1',[(string)$reused['rowid']]);
    historyPgSql($connection,"INSERT INTO public.eventlog(rowid,ts,gamets,type,data,sess,localts,people,location,party) VALUES($1,200,200,'inputtext','reused unrelated rowid','',200,'','','')",[(string)$reused['rowid']]);
    $afterReuse=historyPgAppend($store,$reusedId);
    historyPgCheck((int)$afterReuse['rowid']>(int)$reused['rowid'],'unrelated_reused_rowid_must_not_block_source');

    $externalId=historyPgUuid();
    historyPgSql($connection,"INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(100,100,'info',$1,'written_evidence_v1',100,'|Camilla|','','')",[historyPgSource($externalId)]);
    historyPgDuplicate($store,$externalId);

    // Worker B sees the missing index before worker A commits, then must accept A's index after serialization.
    historyPgSql($connection,'DROP INDEX public.correspondence_written_evidence_document_id_idx');
    historyPgConcurrentIndexInstall($connection,$dsn,historyPgUuid());
    historyPgCheck(historyPgSql($connection,"SELECT to_regclass('public.correspondence_written_evidence_document_id_idx') AS idx")[0]['idx']!==null,'concurrent_schema_install_must_leave_index');

    $plan=implode("\n",array_column(historyPgSql($connection,'EXPLAIN (ANALYZE,BUFFERS,TIMING OFF) '.$lookupSql),'QUERY PLAN'));
    historyPgCheck(str_contains($plan,'correspondence_written_evidence_document_id_idx')&&preg_match('/actual rows=1 loops=1/',$plan)===1,'exact_duplicate_lookup_must_use_index_for_one_row');
    $indexCatalog=historyPgSql($connection,"SELECT pg_get_indexdef(c.oid) AS definition,pg_relation_size(c.oid)::bigint::text AS bytes,i.indisvalid,i.indisready,i.indisunique FROM pg_class c JOIN pg_index i ON i.indexrelid=c.oid WHERE c.oid=to_regclass('public.correspondence_written_evidence_document_id_idx')")[0]??[];
    echo "BASELINE UNINDEXED\n".$beforePlan.PHP_EOL.'INDEX '.json_encode($indexCatalog,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL."INDEXED\n".$plan.PHP_EOL;

    historyPgRejectIndexDefinition($store,$connection,'CREATE INDEX correspondence_written_evidence_document_id_idx ON public.eventlog(rowid)');
    historyPgRejectIndexDefinition($store,$connection,"CREATE INDEX correspondence_written_evidence_document_id_idx ON public.eventlog ((CASE WHEN type='info' AND sess='written_evidence_v1' THEN lower(data::jsonb->>'document_id') END)) WHERE type='info' AND sess='written_evidence_v1'");
    historyPgRejectIndexDefinition($store,$connection,"CREATE INDEX correspondence_written_evidence_document_id_idx ON public.eventlog USING hash ((CASE WHEN type='info' AND sess='written_evidence_v1' THEN data::jsonb->>'document_id' END)) WHERE type='info' AND sess='written_evidence_v1'");
    historyPgRejectIndexDefinition($store,$connection,"CREATE INDEX correspondence_written_evidence_document_id_idx ON public.eventlog ((CASE WHEN type='INFO' AND sess='written_evidence_v1' THEN data::jsonb->>'document_id' END)) WHERE type='INFO' AND sess='written_evidence_v1'");
    historyPgRejectIndexDefinition($store,$connection,"CREATE INDEX correspondence_written_evidence_document_id_idx ON public.eventlog ((CASE WHEN type='info' AND sess='written_evidence_v1' THEN data::jsonb->>'document_id::text' END)) WHERE type='info' AND sess='written_evidence_v1'");
    historyPgRejectIndexDefinition($store,$connection,"CREATE INDEX correspondence_written_evidence_document_id_idx ON public.eventlog ((CASE WHEN type='info' AND sess='written_evidence_v1' THEN data::jsonb->>'document_id' END)) WHERE type='info' AND sess='written_evidence_v1 '");
    historyPgSql($connection,"DELETE FROM public.eventlog WHERE rowid=(SELECT max(rowid) FROM public.eventlog WHERE type='info' AND sess='written_evidence_v1' AND data::jsonb->>'document_id'=$1)",[$legacyId]);
    historyPgRejectIndexDefinition($store,$connection,"CREATE UNIQUE INDEX correspondence_written_evidence_document_id_idx ON public.eventlog ((CASE WHEN type='info' AND sess='written_evidence_v1' THEN data::jsonb->>'document_id' END)) WHERE type='info' AND sess='written_evidence_v1'");
    $store->ensureSchema();

    // The bounded writer lock fails before migration DDL and can be retried cleanly.
    historyPgSql($connection,'DROP INDEX public.correspondence_written_evidence_document_id_idx');
    $blocker=@pg_connect($dsn,PGSQL_CONNECT_FORCE_NEW);
    historyPgCheck($blocker!==false,'owned_secondary_postgresql_connection_required');
    historyPgSql($blocker,'BEGIN');
    historyPgSql($blocker,"INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(100,100,'inputtext','lock holder','',100,'','','')");
    $lockStart=microtime(true);
    try{$store->ensureSchema();throw new RuntimeException('bounded_index_migration_lock_did_not_fail');}catch(DomainException $error){historyPgCheck($error->getMessage()==='written_source_index_unavailable','lock_timeout_must_fail_index_migration_closed');}
    $lockElapsed=microtime(true)-$lockStart;
    historyPgCheck($lockElapsed>=2.5&&$lockElapsed<10,'writer_lock_timeout_must_be_bounded');
    historyPgCheck(historyPgSql($connection,"SELECT to_regclass('public.correspondence_written_evidence_document_id_idx') AS idx")[0]['idx']===null,'lock_timeout_must_leave_no_partial_index');
    historyPgSql($blocker,'ROLLBACK');pg_close($blocker);$blocker=null;
    $store->ensureSchema();
    historyPgCheck(historyPgSql($connection,"SELECT to_regclass('public.correspondence_written_evidence_document_id_idx') AS idx")[0]['idx']!==null,'index_migration_must_retry_after_lock_release');

    // A role that can read but does not own eventlog must not bypass the index requirement.
    $roleCaps=historyPgSql($connection,'SELECT rolsuper,rolcreaterole FROM pg_roles WHERE rolname=current_user')[0]??[];
    historyPgCheck(($roleCaps['rolsuper']??null)==='t'||($roleCaps['rolcreaterole']??null)==='t','fixture_role_must_create_temporary_permission_probe');
    $helperRole='sf_history_index_probe_'.bin2hex(random_bytes(6));
    historyPgSql($connection,'CREATE ROLE '.$helperRole.' NOLOGIN');
    if(($roleCaps['rolsuper']??null)!=='t'){
        historyPgSql($connection,'GRANT '.$helperRole.' TO CURRENT_USER');$helperMembership=true;
    }
    historyPgSql($connection,'GRANT USAGE ON SCHEMA plugins TO '.$helperRole);
    historyPgSql($connection,'GRANT SELECT ON ALL TABLES IN SCHEMA plugins TO '.$helperRole);
    historyPgSql($connection,'GRANT USAGE,CREATE ON SCHEMA public TO '.$helperRole);
    historyPgSql($connection,'GRANT SELECT,INSERT,UPDATE,DELETE ON public.eventlog TO '.$helperRole);
    historyPgSql($connection,'DROP INDEX public.correspondence_written_evidence_document_id_idx');
    historyPgSql($connection,'SET ROLE '.$helperRole);
    try{$store->ensureSchema();throw new RuntimeException('nonowner_index_migration_accepted');}catch(DomainException $error){historyPgCheck($error->getMessage()==='written_source_index_unavailable','nonowner_index_migration_must_fail_closed');}
    historyPgSql($connection,'RESET ROLE');
    historyPgCheck(historyPgSql($connection,"SELECT to_regclass('public.correspondence_written_evidence_document_id_idx') AS idx")[0]['idx']===null,'permission_failure_must_leave_no_partial_index');
    if($helperMembership){historyPgSql($connection,'REVOKE '.$helperRole.' FROM CURRENT_USER');$helperMembership=false;}
    historyPgSql($connection,'DROP OWNED BY '.$helperRole);
    historyPgSql($connection,'DROP ROLE '.$helperRole);$helperRole=null;
    $store->ensureSchema();
    historyPgCheck(historyPgSql($connection,"SELECT to_regclass('public.correspondence_written_evidence_document_id_idx') AS idx")[0]['idx']!==null,'index_migration_must_retry_after_owner_restored');

    // Marked malformed legacy JSON used to make the old duplicate scan fail. The index migration must too.
    historyPgSql($connection,'DROP INDEX public.correspondence_written_evidence_document_id_idx');
    historyPgSql($connection,"INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(100,100,'info','not json','written_evidence_v1',100,'','','')");
    try{$store->ensureSchema();throw new RuntimeException('malformed_marked_history_index_build_accepted');}catch(DomainException $error){historyPgCheck($error->getMessage()==='written_source_index_unavailable','malformed_index_build_must_fail_closed');}
    historyPgCheck(historyPgSql($connection,"SELECT to_regclass('public.correspondence_written_evidence_document_id_idx') AS idx")[0]['idx']===null,'failed_index_migration_must_rollback_ddl');
    historyPgSql($connection,"DELETE FROM public.eventlog WHERE type='info' AND sess='written_evidence_v1' AND data='not json'");
    $store->ensureSchema();

    echo "PASS owned PostgreSQL 17 source-index regression: legacy rows, exact marker, external inserts, retry, rollback, prune/re-exposure, reused rowid, indexed lookup, bounded lock failure, nonowner failure, malformed migration fail-closed\n";
}finally{
    if($blocker!==null){@pg_query($blocker,'ROLLBACK');@pg_close($blocker);}
    if($helperRole!==null){@pg_query($connection,'RESET ROLE');if($helperMembership)@pg_query($connection,'REVOKE '.$helperRole.' FROM CURRENT_USER');@pg_query($connection,'DROP OWNED BY '.$helperRole);@pg_query($connection,'DROP ROLE '.$helperRole);}
    if($fixtureCreated)historyPgSql($connection,'DROP SCHEMA IF EXISTS plugins CASCADE; DROP SCHEMA IF EXISTS chim_meta CASCADE; DROP INDEX IF EXISTS public.correspondence_written_evidence_document_id_idx; DROP TABLE IF EXISTS public.eventlog,public.core_npc_master,public.core_player CASCADE');
    pg_close($connection);
}
