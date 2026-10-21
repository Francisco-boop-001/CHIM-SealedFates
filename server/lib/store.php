<?php
declare(strict_types=1);
require_once __DIR__.'/runtime.php';

final class CorrespondencePgStore implements CorrespondenceStore
{
    private bool $active=false;
    private bool $writerActive=false;
    private bool $schemaSetup=false;
    private bool $restoreStateReady=false;
    private bool $restoreAdmin=false;
    private bool $restoreProbe=false;
    private bool $sessionSharedLock=false;
    private bool $connectionClosed=false;
    private ?array $actorCatalog=null;
    private ?array $offscreenActorRows=null;
    private ?string $playerName=null;
    private ?array $requestClock=null;
    private ?string $requestRestoreToken=null;
    private ?string $requestNativeTs=null;
    /** The connection must be dedicated to this store; failed advisory cleanup closes it. */
    public function __construct(private mixed $connection, private Closure $executorResolver, private Closure $notePreparer, private ?string $restoreSentinelPath=null) {}
    private function clearTransactionCache():void{$this->actorCatalog=null;$this->offscreenActorRows=null;$this->playerName=null;}
    public function bindRequestClock(array $request):void
    {
        if($this->active)throw new LogicException('clock_rebind_during_transaction');
        $this->requestClock=correspondenceRequestClock($request);
        if(!empty($GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_CAPTURED'])){
            $this->requestRestoreToken=is_string($GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_TOKEN']??null)?$GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_TOKEN']:null;
            $this->requestNativeTs=is_string($GLOBALS['CORRESPONDENCE_REQUEST_NATIVE_TS']??null)?$GLOBALS['CORRESPONDENCE_REQUEST_NATIVE_TS']:$this->requestNativeTs;
        }else{
            $this->requestNativeTs=$this->requestClock['ts'];
            if(!$this->restoreStateReady)$this->requestRestoreToken=null;
            else $this->requestRestoreToken=$this->restoreGeneration();
        }
    }
    public function captureRequestRestoreToken(?string $nativeTs=null,bool $newIngress=false):?string
    {
        if($this->active)throw new LogicException('restore_token_capture_during_transaction');
        if($newIngress){$this->requestNativeTs=$nativeTs;$this->requestRestoreToken=null;}
        if(!empty($GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_CAPTURED'])){
            $this->requestNativeTs=is_string($GLOBALS['CORRESPONDENCE_REQUEST_NATIVE_TS']??null)?$GLOBALS['CORRESPONDENCE_REQUEST_NATIVE_TS']:$this->requestNativeTs;
            return $this->requestRestoreToken=is_string($GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_TOKEN']??null)?$GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_TOKEN']:null;
        }
        if(!$newIngress&&$this->requestClock!==null)$this->requestNativeTs=$this->requestClock['ts'];
        return $this->requestRestoreToken=$this->restoreGeneration();
    }
    public function refreshRequestRestoreTokenAfterInit():?string
    {
        if($this->active)throw new LogicException('restore_token_capture_during_transaction');
        // The completed init request is trusted only for the lifecycle handoff;
        // its timestamp is the floor itself, not a fresh post-init observation.
        $this->requestNativeTs=null;
        return $this->requestRestoreToken=$this->restoreGeneration();
    }
    public function requestRestoreToken():?string{return $this->requestRestoreToken;}
    public function restoreGeneration():?string
    {
        if(!$this->restoreStateReady)return null;
        if($this->active)return $this->currentRestoreGeneration();
        return $this->readTransactionInternal(fn()=>$this->currentRestoreGeneration(),false);
    }
    public function assertRestoreToken(?string $expectedRestoreToken=null):void
    {
        if(!$this->restoreStateReady)throw new DomainException('restore_epoch_state_uninitialized');
        if(!empty($GLOBALS['CORRESPONDENCE_RESTORE_INIT_UNSAFE']))throw new DomainException('restore_epoch_pending');
        $expected=$expectedRestoreToken??$this->requestRestoreToken;
        if($this->requestRestoreToken!==null&&$expected!==$this->requestRestoreToken)throw new DomainException('restore_epoch_changed');
        if(($this->restoreSentinelPath!==null||$this->requestClock!==null)&&$this->requestRestoreToken===null)throw new DomainException('restore_token_unavailable');
        $current=$this->currentRestoreGeneration();
        if($current===null)throw new DomainException('restore_epoch_pending');
        if($expected!==null&&!hash_equals($expected,$current))throw new DomainException('restore_epoch_changed');
        if($this->requestNativeTs!==null){
            $rows=$this->queryRaw('SELECT native_ts_floor FROM plugins.correspondence_restore_state WHERE singleton IS TRUE');
            if(count($rows)!==1)throw new DomainException('restore_epoch_state_unavailable');
            $floor=$rows[0]['native_ts_floor']??null;
            if(!is_string($floor)||preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/D',$floor)!==1)throw new DomainException('restore_epoch_state_unavailable');
            if((int)$this->requestNativeTs<=(int)$floor)throw new DomainException('request_before_restore_epoch');
        }
    }
    public function beginRestoreEpoch(?string $epoch=null,?string $nativeTsFloor=null):string
    {
        if($this->active)throw new LogicException('restore_epoch_during_transaction');
        $this->assertConnectionIdle();
        $epoch=$epoch??correspondenceUuid();
        correspondenceUuidCheck($epoch);
        $nativeTsFloor=$nativeTsFloor??$this->requestNativeTs??'0';
        if(preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/D',$nativeTsFloor)!==1||filter_var($nativeTsFloor,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]])===false)throw new DomainException('invalid_restore_native_floor');
        if(!$this->restoreSentinelMatches('pending',$epoch))throw new DomainException('restore_epoch_changed');
        $this->restoreAdmin=true;
        try{
            $this->queryRaw('BEGIN');
            try{
                $this->queryRaw("SET LOCAL lock_timeout='3s'");
                $this->queryRaw('SELECT pg_advisory_xact_lock(18459814, 1)');
                if(!$this->restoreSentinelMatches('pending',$epoch))throw new DomainException('restore_epoch_changed');
                $rows=$this->queryRaw('UPDATE plugins.correspondence_restore_state SET epoch=$1::uuid,pending=TRUE,native_ts_floor=$2::bigint,updated_at=now() WHERE singleton IS TRUE RETURNING epoch::text',[$epoch,$nativeTsFloor]);
                if(count($rows)!==1)throw new DomainException('restore_epoch_state_unavailable');
                $this->queryRaw('COMMIT');
            }catch(Throwable $e){try{$this->queryRaw('ROLLBACK');}catch(Throwable){}throw $e;}
        }finally{$this->restoreAdmin=false;}
        return $epoch;
    }
    public static function captureClearRestoreEpoch(string $path):?string
    {
        try{$sentinel=self::readRestoreSentinelFile($path);}catch(Throwable){return null;}
        return $sentinel!==null&&$sentinel['state']==='clear'?$sentinel['epoch']:null;
    }
    public function quarantineRequestRestoreEpoch(?string $expectedRestoreToken,?string $expectedPriorEpoch):void
    {
        if($this->active)throw new LogicException('restore_epoch_during_transaction');
        if(!$this->restoreStateReady)throw new DomainException('restore_epoch_state_uninitialized');
        if(!is_string($expectedRestoreToken)||$this->requestRestoreToken===null||!hash_equals($this->requestRestoreToken,$expectedRestoreToken))throw new DomainException('restore_token_unavailable');
        $separator=strpos($expectedRestoreToken,':');
        if($separator!==36)throw new DomainException('restore_token_unavailable');
        try{$epoch=correspondenceUuidCheck(substr($expectedRestoreToken,0,$separator));$expectedPriorEpoch=correspondenceUuidCheck($expectedPriorEpoch);}catch(Throwable){throw new DomainException('restore_token_unavailable');}
        if(!hash_equals($expectedPriorEpoch,$epoch))throw new DomainException('restore_epoch_changed');
        $this->assertConnectionIdle();
        $this->restoreAdmin=true;
        try{
            $this->queryRaw('BEGIN');
            try{
                $this->queryRaw("SET LOCAL lock_timeout='3s'");
                $this->queryRaw('SELECT pg_advisory_xact_lock(18459814, 1)');
                $rows=$this->queryRaw('UPDATE plugins.correspondence_restore_state SET pending=TRUE,updated_at=now() WHERE singleton IS TRUE AND epoch=$1::uuid RETURNING epoch::text',[$expectedPriorEpoch]);
                if(count($rows)!==1)throw new DomainException('restore_epoch_changed');
                $this->queryRaw('COMMIT');
            }catch(Throwable $error){try{$this->queryRaw('ROLLBACK');}catch(Throwable){}throw $error;}
        }finally{$this->restoreAdmin=false;}
    }
    public function completeRestoreEpoch(string $epoch):void
    {
        correspondenceUuidCheck($epoch);
        if($this->active)throw new LogicException('restore_epoch_during_transaction');
        $this->assertConnectionIdle();
        if(!$this->restoreSentinelMatches('pending',$epoch))throw new DomainException('restore_epoch_changed');
        $this->restoreAdmin=true;
        try{
            $this->queryRaw('BEGIN');
            try{
                $this->queryRaw("SET LOCAL lock_timeout='3s'");
                $this->queryRaw('SELECT pg_advisory_xact_lock(18459814, 1)');
                if(!$this->restoreSentinelMatches('pending',$epoch))throw new DomainException('restore_epoch_changed');
                $rows=$this->queryRaw('UPDATE plugins.correspondence_restore_state SET pending=FALSE,updated_at=now() WHERE singleton IS TRUE AND epoch=$1::uuid AND pending IS TRUE RETURNING epoch::text',[$epoch]);
                if(count($rows)!==1)throw new DomainException('restore_epoch_state_changed');
                $this->queryRaw('COMMIT');
            }catch(Throwable $e){try{$this->queryRaw('ROLLBACK');}catch(Throwable){}throw $e;}
        }finally{$this->restoreAdmin=false;}
        if(!$this->compareAndWriteRestoreSentinel('pending',$epoch,'clear',$epoch))throw new DomainException('restore_epoch_changed');
    }
    public function initMarkerAfter(int $rowid,string $gamets):bool
    {
        if($rowid<0||!preg_match('/\A[0-9]{1,20}\z/D',$gamets))return false;
        $this->restoreProbe=true;
        try{return $this->queryRaw("SELECT EXISTS(SELECT 1 FROM public.eventlog WHERE rowid>$1::bigint AND type='init' AND gamets=$2::numeric) AS found",[(string)$rowid,$gamets])[0]['found']==='t';}
        finally{$this->restoreProbe=false;}
    }
    public function initMarkerStart():int
    {
        $this->restoreProbe=true;
        try{$rows=$this->queryRaw('SELECT COALESCE(MAX(rowid),0)::text AS rowid FROM public.eventlog');$value=$rows[0]['rowid']??null;if(!is_string($value)||preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/D',$value)!==1||filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]])===false)throw new DomainException('restore_init_marker_unavailable');return (int)$value;}
        finally{$this->restoreProbe=false;}
    }
    public function ensureSchema():void
    {
        if($this->active)throw new LogicException('schema_during_transaction');
        $this->assertConnectionIdle();
        $this->schemaSetup=true;$schemaComplete=false;
        try{
            $ready=$this->queryRaw("SELECT to_regclass('plugins.correspondence_documents') IS NOT NULL AS documents,to_regclass('plugins.correspondence_operations') IS NOT NULL AS operations,to_regclass('plugins.correspondence_presence') IS NOT NULL AS presence,to_regclass('plugins.correspondence_restore_state') IS NOT NULL AS restore_state,EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema='plugins' AND table_name='correspondence_restore_state' AND column_name='native_ts_floor') AS restore_floor")[0]??[];
            if(($ready['documents']??null)!=='t'||($ready['operations']??null)!=='t'||($ready['presence']??null)!=='t'){
                $migration=@file_get_contents(dirname(__DIR__).'/migrations/001_initial.sql');
                if(!is_string($migration)||$migration==='')throw new DomainException('schema_migration_unavailable');
                $this->transaction(function()use($migration){foreach(explode(';',$migration)as$statement)if(trim($statement)!=='')$this->queryRaw($statement);});
            }
            if(($ready['restore_state']??null)!=='t'||($ready['restore_floor']??null)!=='t'){
                $migration=@file_get_contents(dirname(__DIR__).'/migrations/004_restore_state.sql');
                if(!is_string($migration)||$migration==='')throw new DomainException('schema_migration_unavailable');
                $this->transaction(function()use($migration){foreach(explode(';',$migration)as$statement)if(trim($statement)!=='')$this->queryRaw($statement);});
            }
            $columns=$this->queryRaw("SELECT table_name,column_name FROM information_schema.columns WHERE table_schema='plugins' AND table_name IN ('correspondence_documents','correspondence_operations','correspondence_presence','correspondence_restore_state')");
            $expected=['correspondence_documents'=>['document_id','playthrough_id','request_id','payload','created_at'],'correspondence_operations'=>['operation_id','document_id','payload','created_at'],'correspondence_presence'=>['playthrough_id','payload'],'correspondence_restore_state'=>['singleton','epoch','pending','native_ts_floor']];
            foreach($expected as$table=>$names)foreach($names as$name)if(!array_filter($columns,fn($row)=>$row['table_name']===$table&&$row['column_name']===$name))throw new DomainException('schema_migration_incomplete');
            $state=$this->queryRaw('SELECT epoch::text,pending FROM plugins.correspondence_restore_state WHERE singleton IS TRUE');
            if($state===[]){
                $initStarting=!empty($GLOBALS['CORRESPONDENCE_RESTORE_INIT_START']);
                $epoch=is_string($GLOBALS['CORRESPONDENCE_RESTORE_INIT_EPOCH']??null)?$GLOBALS['CORRESPONDENCE_RESTORE_INIT_EPOCH']:correspondenceUuid();$pending=$initStarting;
                if($this->restoreSentinelPath!==null){
                    try{$sentinel=$this->readRestoreSentinel();}catch(Throwable){$sentinel=null;$pending=true;}
                    if($sentinel!==null){
                        $epoch=$sentinel['epoch'];
                        $pending=true;
                    }
                    $pending=true;
                }
                $floor=$initStarting&&is_string($GLOBALS['CORRESPONDENCE_RESTORE_INIT_NATIVE_TS']??null)?$GLOBALS['CORRESPONDENCE_RESTORE_INIT_NATIVE_TS']:'0';
                if(preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/D',$floor)!==1||filter_var($floor,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]])===false)$floor='0';
                $this->transaction(function()use(&$epoch,&$pending,$floor,$initStarting):void{
                    // Another initializer may have created and completed the row
                    // while this request waited for the writer lock. Preserve it.
                    if($this->restoreStateRow()!==null)return;
                    if($this->restoreSentinelPath!==null){
                        try{$sentinel=$this->readRestoreSentinel();}catch(Throwable){$sentinel=null;}
                        if($sentinel!==null){
                            $epoch=$sentinel['epoch'];$pending=true;
                            // A missing row cannot restore authority from an old clear
                            // marker. Revalidate under the database writer lock, then
                            // make that exact marker pending with a short file CAS.
                            if(!$initStarting&&$sentinel['state']==='clear'&&!$this->compareAndWriteRestoreSentinel('clear',$epoch,'pending',$epoch)){
                                try{$latest=$this->readRestoreSentinel();}catch(Throwable){$latest=null;}
                                if($latest!==null)$epoch=$latest['epoch'];
                            }
                        }else{$pending=true;}
                    }
                    $this->queryRaw('INSERT INTO plugins.correspondence_restore_state(singleton,epoch,pending,native_ts_floor) VALUES(TRUE,$1::uuid,$2::boolean,$3::bigint) ON CONFLICT(singleton) DO NOTHING',[$epoch,$pending?'true':'false',$floor]);
                });
            }
            $this->restoreStateReady=true;$schemaComplete=true;
        }finally{
            $this->schemaSetup=false;
            if($schemaComplete){
                if(!empty($GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_CAPTURED']))$this->requestRestoreToken=is_string($GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_TOKEN']??null)?$GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_TOKEN']:null;
                elseif($this->requestClock!==null&&$this->requestRestoreToken===null)$this->requestRestoreToken=$this->restoreGeneration();
            }
        }
    }
    public function query(string $sql,array $parameters=[]): array
    {
        if(!$this->active&&!$this->schemaSetup&&!$this->restoreAdmin&&!$this->restoreProbe){
            if(preg_match('/\A\s*(?:SELECT|WITH|SHOW)\b/i',$sql)===1)return $this->readTransaction(fn()=>$this->queryRaw($sql,$parameters));
            $this->assertRestoreToken();
        }
        return $this->queryRaw($sql,$parameters);
    }
    private function queryRaw(string $sql,array $parameters=[]):array
    {
        if($this->connectionClosed)throw new RuntimeException('storage_failed');
        $result=@pg_query_params($this->connection,$sql,$parameters);
        if($result===false)throw new RuntimeException('storage_failed');
        try{return pg_num_fields($result)>0?(pg_fetch_all($result)?:[]):[];}finally{pg_free_result($result);}
    }
    private function readRestoreSentinel():?array
    {
        if($this->restoreSentinelPath===null)return null;
        return self::readRestoreSentinelFile($this->restoreSentinelPath);
    }
    private static function readRestoreSentinelFile(string $path):?array
    {
        if(!is_file($path))return null;
        $raw=@file_get_contents($path);
        if(!is_string($raw))throw new DomainException('restore_sentinel_unavailable');
        try{$value=json_decode($raw,true,8,JSON_THROW_ON_ERROR);}catch(Throwable){throw new DomainException('restore_sentinel_unavailable');}
        if(!is_array($value)||($value['schema']??null)!=='correspondence.restore.v1'||!in_array($value['state']??null,['clear','pending'],true)||!is_string($value['epoch']??null))throw new DomainException('restore_sentinel_unavailable');
        try{$epoch=correspondenceUuidCheck($value['epoch']);}catch(Throwable){throw new DomainException('restore_sentinel_unavailable');}
        return ['state'=>$value['state'],'epoch'=>$epoch];
    }
    public static function writeRestoreSentinelFile(string $path,string $state,string $epoch):bool
    {
        if(!in_array($state,['clear','pending'],true))return false;
        try{correspondenceUuidCheck($epoch);}catch(Throwable){return false;}
        return self::withRestoreSentinelLock($path,fn()=>self::writeRestoreSentinelFileUnlocked($path,$state,$epoch));
    }
    private static function writeRestoreSentinelFileUnlocked(string $path,string $state,string $epoch):bool
    {
        $directory=dirname($path);
        if(!is_dir($directory)||!is_writable($directory))return false;
        try{$temporary=$path.'.'.bin2hex(random_bytes(8)).'.tmp';}catch(Throwable){return false;}
        $handle=@fopen($temporary,'xb');if($handle===false)return false;
        $bytes=json_encode(['schema'=>'correspondence.restore.v1','state'=>$state,'epoch'=>$epoch],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        $ok=false;
        try{
            $written=0;$length=strlen($bytes);
            while($written<$length){$part=fwrite($handle,substr($bytes,$written));if($part===false||$part===0)break;$written+=$part;}
            $ok=$written===$length&&fflush($handle)&&(function_exists('fsync')?@fsync($handle):true);
        }catch(Throwable){return false;}
        finally{fclose($handle);}
        if(!$ok||!@rename($temporary,$path)){@unlink($temporary);return false;}
        clearstatcache(true,$path);return true;
    }
    private static function withRestoreSentinelLock(string $path,Closure $work):bool
    {
        $lock=@fopen($path.'.lock','c+b');if($lock===false)return false;
        $acquired=false;
        try{
            for($attempt=0;$attempt<20;$attempt++){
                if(@flock($lock,LOCK_EX|LOCK_NB)){$acquired=true;break;}
                usleep(10000);
            }
            if(!$acquired)return false;
            return $work()===true;
        }catch(Throwable){return false;}
        finally{if($acquired)@flock($lock,LOCK_UN);fclose($lock);}
    }
    private function restoreSentinelMatches(string $state,string $epoch):bool
    {
        if($this->restoreSentinelPath===null)return true;
        return self::withRestoreSentinelLock($this->restoreSentinelPath,function()use($state,$epoch):bool{
            try{$sentinel=self::readRestoreSentinelFile($this->restoreSentinelPath);}catch(Throwable){return false;}
            return $sentinel!==null&&$sentinel['state']===$state&&hash_equals($epoch,$sentinel['epoch']);
        });
    }
    private function compareAndWriteRestoreSentinel(string $expectedState,string $expectedEpoch,string $state,string $epoch):bool
    {
        if($this->restoreSentinelPath===null)return true;
        try{correspondenceUuidCheck($epoch);}catch(Throwable){return false;}
        return self::withRestoreSentinelLock($this->restoreSentinelPath,function()use($expectedState,$expectedEpoch,$state,$epoch):bool{
            try{$sentinel=self::readRestoreSentinelFile($this->restoreSentinelPath);}catch(Throwable){return false;}
            if($sentinel===null||$sentinel['state']!==$expectedState||!hash_equals($expectedEpoch,$sentinel['epoch']))return false;
            return self::writeRestoreSentinelFileUnlocked($this->restoreSentinelPath,$state,$epoch);
        });
    }
    private function assertConnectionIdle():void
    {
        if(!function_exists('pg_transaction_status')||!defined('PGSQL_TRANSACTION_IDLE'))throw new DomainException('connection_transaction_status_unavailable');
        if(pg_transaction_status($this->connection)!==PGSQL_TRANSACTION_IDLE)throw new DomainException('connection_transaction_active');
    }
    private function requireWriterTransaction():void
    {
        if(!$this->active)throw new LogicException('transaction_required');
        if(!$this->writerActive)throw new LogicException('writer_in_reader');
    }
    private function closeConnection():void
    {
        if($this->connectionClosed)return;
        $this->connectionClosed=true;
        try{@pg_close($this->connection);}catch(Throwable){}
    }
    private function restoreStateRow():?array
    {
        $rows=$this->queryRaw('SELECT epoch::text,pending FROM plugins.correspondence_restore_state WHERE singleton IS TRUE');
        return count($rows)===1?$rows[0]:null;
    }
    private function currentRestoreGeneration():?string
    {
        $row=$this->restoreStateRow();if($row===null)throw new DomainException('restore_epoch_state_unavailable');
        if(($row['pending']??null)==='t')return null;
        if($this->restoreSentinelPath!==null){
            $sentinel=$this->readRestoreSentinel();
            if($sentinel===null)throw new DomainException('restore_sentinel_unavailable');
            if($sentinel['state']==='pending')return null;
            if(!hash_equals((string)$row['epoch'],$sentinel['epoch']))throw new DomainException('restore_epoch_changed');
        }
        $ptrGeneration=array_key_exists('ptr_runtime_generation',$GLOBALS)?$GLOBALS['ptr_runtime_generation']:($this->restoreSentinelPath===null?'db-only':null);
        if(!is_string($ptrGeneration))throw new DomainException('restore_generation_unavailable');
        return (string)$row['epoch'].':'.($ptrGeneration===''?'none':$ptrGeneration);
    }
    public function transaction(callable $work): mixed
    {
        return $this->writerTransaction($work,null);
    }
    public function completionTransaction(callable $work,?string $expectedRestoreToken=null):mixed
    {
        return $this->writerTransaction($work,$expectedRestoreToken);
    }
    private function writerTransaction(callable $work,?string $expectedRestoreToken):mixed
    {
        if($this->active){if(!$this->writerActive)throw new LogicException('writer_in_reader');$this->assertRestoreToken($expectedRestoreToken);return $work();}
        $this->assertConnectionIdle();
        $this->clearTransactionCache();
        $this->queryRaw('BEGIN'); $this->active=true; $this->writerActive=true;
        try{
            $this->queryRaw("SET LOCAL lock_timeout='3s'");
            $this->queryRaw('SELECT pg_advisory_xact_lock(18459814, 1)');
            // Core restore and source publication must not interleave with receipt validation.
            $this->queryRaw('LOCK TABLE public.eventlog IN SHARE ROW EXCLUSIVE MODE');
            if(!$this->schemaSetup){
                if(count($this->queryRaw('SELECT epoch FROM plugins.correspondence_restore_state WHERE singleton IS TRUE FOR SHARE'))!==1)throw new DomainException('restore_epoch_state_unavailable');
                $this->assertRestoreToken($expectedRestoreToken);
            }
            $result=$work();if(!$this->schemaSetup)$this->assertRestoreToken($expectedRestoreToken);$this->queryRaw('COMMIT');return $result;
        }catch(Throwable $e){try{$this->queryRaw('ROLLBACK');}catch(Throwable){}throw $e;}finally{$this->active=false;$this->writerActive=false;$this->clearTransactionCache();}
    }
    public function readTransaction(callable $work):mixed
    {
        return $this->readTransactionInternal($work,true);
    }
    private function readTransactionInternal(callable $work,bool $checkToken):mixed
    {
        if($this->active){if($checkToken)$this->assertRestoreToken();return $work();}
        $this->assertConnectionIdle();
        $this->clearTransactionCache();
        $begun=false;
        try{
            $timeoutRows=$this->queryRaw("SELECT current_setting('lock_timeout') AS lock_timeout");
            $priorLockTimeout=$timeoutRows[0]['lock_timeout']??null;
            if(!is_string($priorLockTimeout)||$priorLockTimeout==='')throw new DomainException('lock_timeout_unavailable');
            $this->queryRaw("SELECT set_config('lock_timeout',$1,FALSE)",['3s']);
            try{$this->queryRaw('SELECT pg_advisory_lock_shared(18459814, 1)');$this->sessionSharedLock=true;}
            finally{
                try{$this->queryRaw("SELECT set_config('lock_timeout',$1,FALSE)",[$priorLockTimeout]);}
                catch(Throwable $error){$this->closeConnection();$this->sessionSharedLock=false;throw new RuntimeException('storage_failed',0,$error);}
            }
            $this->queryRaw('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');$begun=true;$this->active=true;$this->writerActive=false;
            $this->queryRaw("SET LOCAL lock_timeout='3s'");
            if($checkToken)$this->assertRestoreToken();
            $result=$work();if($checkToken)$this->assertRestoreToken();$this->queryRaw('COMMIT');$begun=false;return $result;
        }
        catch(Throwable $e){if($begun)try{$this->queryRaw('ROLLBACK');}catch(Throwable){}throw $e;}
        finally{
            $this->active=false;$this->writerActive=false;$this->clearTransactionCache();
            if($this->sessionSharedLock){
                $released=false;
                try{$rows=$this->queryRaw('SELECT pg_advisory_unlock_shared(18459814, 1) AS released');$released=($rows[0]['released']??null)==='t';}catch(Throwable){}
                $this->sessionSharedLock=false;
                if(!$released){
                    $this->closeConnection();
                    error_log('[CORRESPONDENCE] restore_read_unlock_failed');
                    throw new RuntimeException('storage_failed');
                }
            }
        }
    }
    public function scope(): array
    {
        $exists=$this->query("SELECT to_regclass('chim_meta.playthrough_profiles') AS profile_table")[0]??null;
        if(!$exists || !array_key_exists('profile_table',$exists))throw new DomainException('scope_unavailable');
        $profiles=$exists['profile_table']===null?[]:$this->query('SELECT id FROM chim_meta.playthrough_profiles WHERE is_active IS TRUE LIMIT 2');
        if(count($profiles)>1)throw new DomainException('scope_ambiguous');
        $id=$profiles===[]?'unprofiled':(string)$profiles[0]['id'];
        $clock=$this->requestClock??($this->query('SELECT ts,gamets FROM public.eventlog ORDER BY rowid DESC LIMIT 1')[0]??null);
        if(!$clock || !is_numeric($clock['ts']) || !is_numeric($clock['gamets']))throw new DomainException('game_clock_unavailable');
        return ['playthrough_id'=>$id,'ts'=>$clock['ts'],'gamets'=>$clock['gamets']];
    }
    public function actors():array
    {
        if($this->active&&$this->actorCatalog!==null)return $this->actorCatalog;
        $rows=$this->query('SELECT id,npc_name,refid,profile_id FROM public.core_npc_master WHERE id>0 ORDER BY npc_name,id LIMIT 10000');
        if($this->active)$this->actorCatalog=$rows;
        return $rows;
    }
    public function offscreenActors():array
    {
        if($this->active&&$this->offscreenActorRows!==null)return $this->offscreenActorRows;
        $rows=$this->query('SELECT id,npc_name,personality,extended_data::text AS extended_data,metadata::text AS metadata FROM public.core_npc_master WHERE id>0 ORDER BY id LIMIT 10000');
        if($this->active)$this->offscreenActorRows=$rows;
        return $rows;
    }
    public function player():string
    {
        if($this->active&&$this->playerName!==null)$name=$this->playerName;
        else{
            $rows=$this->query('SELECT value FROM public.core_player WHERE id=$1 LIMIT 2',['player_name']);
            $name=count($rows)===1?($rows[0]['value']??''):'';
            if($this->active)$this->playerName=$name;
        }
        if(!is_string($name)||trim($name)===''||strlen($name)>200||preg_match('//u',$name)!==1||preg_match('/[\x00-\x1f\x7f|]/',$name))throw new DomainException('player_unavailable');return $name;
    }
    public function lockActorRows(array $npcIds,bool $includePlayer=false):array
    {
        if(!$this->active||!$this->writerActive)throw new LogicException('effect_identity_lock_requires_writer');
        $ids=[];
        foreach($npcIds as$id){if(!is_int($id)||$id<1)throw new InvalidArgumentException('invalid_actor_ids');$ids[$id]=$id;}
        if(count($ids)>4)throw new InvalidArgumentException('invalid_actor_ids');
        sort($ids,SORT_NUMERIC);
        $wanted=array_fill_keys($ids,true);
        $actors=[];
        if($ids!==[]){
            $array='{'.implode(',',$ids).'}';
            foreach($this->query('SELECT * FROM public.core_npc_master WHERE id=ANY($1::bigint[]) AND id>0 ORDER BY id FOR SHARE',[$array])as$row){
                if(!ctype_digit((string)($row['id']??'')))continue;
                $id=(int)$row['id'];if(isset($wanted[$id]))$actors[$id]=$row;
            }
        }
        $playerName=null;
        if($includePlayer){$rows=$this->query('SELECT value FROM public.core_player WHERE id=$1 FOR SHARE',['player_name']);if(count($rows)===1)$playerName=$rows[0]['value']??null;}
        return ['actors'=>$actors,'player_name'=>$playerName];
    }
    private function payload(array $rows):?array{return isset($rows[0]['payload'])?json_decode($rows[0]['payload'],true,512,JSON_THROW_ON_ERROR):null;}
    public function findRequest(string $scope,string $request):?array{return $this->payload($this->query('SELECT payload FROM plugins.correspondence_documents WHERE playthrough_id=$1 AND request_id=$2',[$scope,$request]));}
    public function loadDocument(string $id):?array{return $this->payload($this->query('SELECT payload FROM plugins.correspondence_documents WHERE document_id=$1',[$id]));}
    public function saveDocument(array $document):void
    {
        $rows=$this->query("INSERT INTO plugins.correspondence_documents(document_id,playthrough_id,request_id,payload) VALUES($1,$2,$3,$4::jsonb) ON CONFLICT(document_id) DO UPDATE SET payload=EXCLUDED.payload WHERE plugins.correspondence_documents.payload-ARRAY['operation_id','operator_close']=EXCLUDED.payload-ARRAY['operation_id','operator_close'] AND (NOT (EXCLUDED.payload ? 'operator_close') OR plugins.correspondence_documents.payload->>'operation_id' IS NOT DISTINCT FROM EXCLUDED.payload->>'operation_id') AND (NOT (plugins.correspondence_documents.payload ? 'operator_close') OR plugins.correspondence_documents.payload->'operator_close'=EXCLUDED.payload->'operator_close') RETURNING document_id",[$document['document_id'],$document['playthrough_id'],$document['request_id'],correspondenceJson($document)]);
        if(count($rows)!==1)throw new DomainException('immutable_document_conflict');
    }
    public function documents(string $scope, ?int $recipientId=null):array
    {
        $sql = 'SELECT payload FROM plugins.correspondence_documents WHERE playthrough_id=$1';
        $parameters = [$scope];
        if ($recipientId !== null) {
            if ($recipientId < 1) throw new InvalidArgumentException('invalid_recipient_id');
            $sql .= " AND payload->'recipient'->>'kind'='npc' AND payload->'recipient'->>'id'=\$2";
            $parameters[] = (string)$recipientId;
        }
        $sql .= ' ORDER BY created_at DESC,document_id';
        return array_map(fn($r)=>json_decode($r['payload'],true,512,JSON_THROW_ON_ERROR),$this->query($sql,$parameters));
    }
    public function loadOperation(string $id):?array{return $this->payload($this->query('SELECT payload FROM plugins.correspondence_operations WHERE operation_id=$1',[$id]));}
    public function saveOperation(array $operation):void
    {
        $rows=$this->query("INSERT INTO plugins.correspondence_operations(operation_id,document_id,payload) VALUES($1,$2,$3::jsonb) ON CONFLICT(operation_id) DO UPDATE SET payload=EXCLUDED.payload WHERE plugins.correspondence_operations.payload-ARRAY['receipt','receipt_anchor','source']=EXCLUDED.payload-ARRAY['receipt','receipt_anchor','source'] RETURNING operation_id",[$operation['operation_id'],$operation['document_id'],correspondenceJson($operation)]);
        if(count($rows)!==1)throw new DomainException('immutable_operation_conflict');
    }
    public function appendEvent(string $type,string $data,string $people,string $sess):array
    {
        $this->requireWriterTransaction();
        if(!in_array($type,['written_evidence','correspondence_anchor','correspondence_dispatch','correspondence_receipt_confirmed','correspondence_presence','correspondence_operator_closed'],true)||$sess!==($type==='written_evidence'?'written_evidence_v1':'correspondence_v1'))throw new InvalidArgumentException('invalid_event_carrier');
        if(in_array($type,['written_evidence','correspondence_receipt_confirmed','correspondence_presence'],true)&&$this->requestClock===null)throw new DomainException('request_clock_unavailable');
        if($type==='written_evidence'){
            $source=json_decode($data,true,512,JSON_THROW_ON_ERROR);
            $existing=$this->query("WITH marked AS MATERIALIZED (SELECT rowid,data FROM public.eventlog WHERE type='info' AND sess='written_evidence_v1') SELECT rowid FROM marked WHERE data::jsonb->>'document_id'=$1 LIMIT 1",[$source['document_id']]);
            if($existing!==[])throw new DomainException('duplicate_written_source');
        }
        $clock=$this->scope();
        $row=$this->query('INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES($1,$2,$3,$4,$5,$6,$7,$8,$9) RETURNING rowid,ts,gamets,type,data,sess,localts,people',[$clock['ts'],$clock['gamets'],'info',$data,$sess,time(),$people,'',''])[0]??null;
        if(!$row)throw new RuntimeException('event_write_failed');return $row;
    }
    public function eventMatches(array $event):bool
    {
        $rows=$this->query('SELECT rowid,ts,gamets,type,data,sess,localts,people FROM public.eventlog WHERE rowid=$1',[$event['rowid']??0]);
        return count($rows)===1 && $rows[0]==$event;
    }
    public function managedExecutor():?string
    {
        $name=($this->executorResolver)($this);
        if(!is_string($name)||$name===''||preg_match('/[|@\r\n]/',$name))return null;
        $matches=array_filter($this->actors(),fn($a)=>$a['npc_name']===$name);return count($matches)===1?$name:null;
    }
    public function prepareNote(string $title,string $body):void
    {
        $this->requireWriterTransaction();
        if(($this->notePreparer)($title,$body)!==true)throw new DomainException('renderer_unavailable');
        $this->query("INSERT INTO public.books(ts,gamets,content,sess,localts,title) VALUES(0,0,$1,'generated',$2,$3)",[$body,time(),$title]);
        $this->queueCommand('rolemaster','rolecommand|generateLetter@'.$title);
    }
    public function queueCommand(string $actor,string $action):void
    {
        $this->requireWriterTransaction();
        $this->query("INSERT INTO public.responselog(localts,sent,actor,text,action,tag) VALUES($1,0,$2,'',$3,'')",[time(),$actor,$action]);
    }
    private function pluginRows():array{return $this->query('SELECT plugin_name,formid_prefix FROM public.game_plugins');}
    private function signed(int $id):int{return $id>2147483647?$id-4294967296:$id;}
    private function strictPlugin(string $name):void{if(preg_match('/\A[^|@\x00-\x1f\/\\\\]{1,200}\.(?i:esm|esp|esl)\z/D',$name)!==1)throw new DomainException('invalid_plugin');}
    public function physicalActor(array $actor):array
    {
        if($actor['kind']==='player')$runtime='00000014';
        else{$rows=$this->query('SELECT refid FROM public.core_npc_master WHERE id=$1',[$actor['id']]);$runtime=$rows[0]['refid']??'';}
        if(!is_string($runtime)||preg_match('/\A(?:0x)?([0-9a-fA-F]{8})\z/D',$runtime,$m)!==1)throw new DomainException('actor_form_unavailable');
        $runtime=strtoupper($m[1]);if(str_starts_with($runtime,'FF'))throw new DomainException('dynamic_form_unsupported');
        $prefix=str_starts_with($runtime,'FE')?substr($runtime,0,5):substr($runtime,0,2);
        $rows=array_values(array_filter($this->pluginRows(),fn($p)=>strtoupper($p['formid_prefix'])===$prefix));
        if(count($rows)!==1)throw new DomainException('plugin_mapping_unavailable');$plugin=$rows[0]['plugin_name'];$this->strictPlugin($plugin);
        $id=(int)hexdec($runtime);$local=$id & (strlen($prefix)===5?4095:16777215);if($local===0)throw new DomainException('invalid_local_form');
        return ['refid'=>$this->signed($id),'plugin'=>$plugin,'local_id'=>$local];
    }
    public function physicalItem(array $item):array
    {
        correspondenceAttachments([$item]);[$plugin,$hex]=explode('|',$item['form_id']);$local=(int)hexdec($hex);
        $rows=array_values(array_filter($this->pluginRows(),fn($p)=>strcasecmp($p['plugin_name'],$plugin)===0));
        if(count($rows)!==1)throw new DomainException('plugin_mapping_unavailable');$row=$rows[0];$prefix=strtoupper($row['formid_prefix']);
        if($local<1 || (strlen($prefix)===5 ? $local>4095 : $local>16777215) || preg_match('/\A(?:[0-9A-E][0-9A-F]|F[0-9A-D]|FE[0-9A-F]{3})\z/D',$prefix)!==1)throw new DomainException('invalid_local_form');
        $runtime=strlen($prefix)===5?(hexdec($prefix)<<12)|$local:(hexdec($prefix)<<24)|$local;
        return ['kind'=>$item['kind'],'form_id'=>$this->signed((int)$runtime),'plugin'=>$row['plugin_name'],'local_id'=>$local,'quantity'=>$item['quantity']];
    }
    public function itemChoices():array
    {
        $choices=[['form_id'=>'Skyrim.esm|0000000F','kind'=>'gold','name'=>'Gold (septims)']];
        $seen=['skyrim.esm|0000000f'=>true];$plugins=$this->pluginRows();
        $cache=$this->query("SELECT to_regclass('public.market_cache') AS cache_table")[0]??[];
        if(!array_key_exists('cache_table',$cache))throw new DomainException('item_cache_unavailable');
        if($cache['cache_table']!==null){
            foreach($this->query('SELECT baseid,plugin,name FROM public.market_cache ORDER BY name,baseid,plugin')as$item){
                if(!is_string($item['baseid']??null)||preg_match('/\A(?:0[xX])?([0-9a-fA-F]{8})\z/D',$item['baseid'],$hex)!==1||!is_string($item['plugin']??null)||!is_string($item['name']??null)||trim($item['name'])===''||strlen($item['name'])>512||preg_match('//u',$item['name'])!==1||preg_match('/\p{Cc}/u',$item['name'])===1)continue;
                try{
                    $identity=$this->physicalActorRef($hex[1],$plugins);
                    // Cache runtime prefixes may belong to another plugin after load-order changes.
                    if(strcasecmp($identity['plugin'],$item['plugin'])!==0||$identity['local_id']===0)continue;
                    $key=$identity['plugin'].'|'.sprintf('%08X',$identity['local_id']);if(isset($seen[strtolower($key)]))continue;
                    correspondenceAttachments([['kind'=>'item','form_id'=>$key,'quantity'=>1]]);
                    $seen[strtolower($key)]=true;$choices[]=['form_id'=>$key,'kind'=>'item','name'=>$item['name']];
                }catch(DomainException|InvalidArgumentException){continue;}
            }
        }
        $row=$this->query("SELECT data FROM public.eventlog WHERE type='infoitems' ORDER BY rowid DESC LIMIT 1")[0]??[];
        if(!preg_match('/\(items in range:(.+)\)/us',$row['data']??'',$m))return $choices;
        foreach(array_slice(explode(',',$m[1]),0,300) as $entry){$parts=explode(':',trim($entry),3);if(count($parts)!==3||preg_match('/\A0x([0-9a-fA-F]{8})\z/D',$parts[1],$hex)!==1)continue;
            try{$a=$this->physicalActorRef($hex[1],$plugins);if($a['local_id']===0)continue;$key=$a['plugin'].'|'.sprintf('%08X',$a['local_id']);if(isset($seen[strtolower($key)]))continue;correspondenceAttachments([['kind'=>'item','form_id'=>$key,'quantity'=>1]]);$seen[strtolower($key)]=true;$choices[]=['form_id'=>$key,'kind'=>'item','name'=>$parts[2]];}catch(DomainException|InvalidArgumentException){continue;}
        }return $choices;
    }
    private function physicalActorRef(string $runtime,array $plugins):array
    {
        if(str_starts_with(strtoupper($runtime),'FF'))throw new DomainException('dynamic_form_unsupported');
        $prefix=str_starts_with(strtoupper($runtime),'FE')?strtoupper(substr($runtime,0,5)):strtoupper(substr($runtime,0,2));
        $rows=array_values(array_filter($plugins,fn($p)=>strtoupper($p['formid_prefix'])===$prefix));if(count($rows)!==1)throw new DomainException('plugin_mapping_unavailable');
        $this->strictPlugin($rows[0]['plugin_name']);return ['plugin'=>$rows[0]['plugin_name'],'local_id'=>(int)hexdec($runtime)&(strlen($prefix)===5?4095:16777215)];
    }
    public function observeManaged(string $raw):void
    {
        // An identical recent snapshot needs no new authority or writer lock.
        $payload=$this->payload($this->query('SELECT payload FROM plugins.correspondence_presence WHERE playthrough_id=$1',[$this->scope()['playthrough_id']]));
        if(is_array($payload)&&($payload['raw']??null)===$raw&&is_int($payload['observed_at']??null)
            &&$payload['observed_at']<=time()&&time()-$payload['observed_at']<15
            &&is_array($payload['anchor']??null)&&$this->eventMatches($payload['anchor']))return;
        $this->transaction(function()use($raw){
            $scope=$this->scope()['playthrough_id'];
            $anchor=$this->appendEvent('correspondence_presence',correspondenceJson(['token'=>correspondenceUuid()]),'','correspondence_v1');
            $payload=['raw'=>$raw,'observed_at'=>time(),'anchor'=>$anchor];
            $this->query('INSERT INTO plugins.correspondence_presence(playthrough_id,payload) VALUES($1,$2::jsonb) ON CONFLICT(playthrough_id) DO UPDATE SET payload=EXCLUDED.payload',[$scope,correspondenceJson($payload)]);
        });
    }
    public function observedExecutor():?string
    {
        $payload=$this->payload($this->query('SELECT payload FROM plugins.correspondence_presence WHERE playthrough_id=$1',[$this->scope()['playthrough_id']]));
        if(!$payload||!is_int($payload['observed_at']??null)||$payload['observed_at']>time()||time()-$payload['observed_at']>45||!$this->eventMatches($payload['anchor']))return null;
        return correspondenceExecutorFromSnapshot($payload['raw'],$this->actors());
    }}










