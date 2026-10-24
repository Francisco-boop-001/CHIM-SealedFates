<?php
declare(strict_types=1);
require_once __DIR__.'/companion_policy.php';

final class CorrespondenceCompanionPg
{
    private const HOUR = 416667;
    private const SIX_HOURS = 2500000;
    private const TWELVE_HOURS = 5000000;

    public function __construct(private CorrespondencePgStore $store, private CorrespondenceService $service) {}

    public function ensureSchema(): void
    {
        $ready=$this->store->query("SELECT to_regclass('plugins.correspondence_companion_poll') AS poll,to_regclass('plugins.correspondence_companion_episode') AS episode,to_regclass('plugins.correspondence_companion_decision') AS decision")[0]??[];
        if ($ready['poll']!==null && $ready['episode']!==null && $ready['decision']!==null) return;
        $migration=file_get_contents(dirname(__DIR__).'/migrations/005_companion.sql');
        if (!is_string($migration)||$migration==='') throw new DomainException('schema_migration_unavailable');
        $this->store->transaction(function()use($migration):void { foreach(explode(';',$migration) as $sql) if(trim($sql)!=='') $this->store->query($sql); });
    }

    private function context(): array
    {
        $clock=$this->store->scope();
        $token=$this->store->restoreGeneration();
        if (!is_string($token) || $token==='' || $this->store->requestRestoreToken()!==$token) throw new DomainException('restore_unavailable');
        return [$clock['playthrough_id'],correspondenceCompanionEpoch($token),(int)$clock['gamets'],$token];
    }

    private function enabled(string $scope): bool
    {
        $row=$this->store->query('SELECT enabled FROM plugins.correspondence_autonomy_settings WHERE playthrough_id=$1',[$scope])[0]??[];
        return in_array($row['enabled']??null,[true,'t','true','1',1],true);
    }

    private function eligible(): array
    {
        $rows=$this->store->offscreenActors();$names=[];$eligible=[];
        foreach($rows as $row){
            $name=$row['npc_name']??null;$id=filter_var($row['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if($id===false||!correspondenceActorName($name))continue;
            $key=function_exists('mb_strtolower')?mb_strtolower($name,'UTF-8'):strtolower($name);
            $names[$key]=($names[$key]??0)+1;
            $extended=json_decode((string)($row['extended_data']??''),true);
            $metadata=json_decode((string)($row['metadata']??''),true);
            $enabled=is_array($extended)&&in_array($extended['background_life_enabled']??null,[true,1,'true','1','t','on'],true)
                &&in_array($extended['background_life_letters']??null,[true,1,'true','1','t','on'],true);
            $dead=is_array($metadata)&&is_array($metadata['stats']??null)&&in_array($metadata['stats']['is_dead']??null,[true,1,'true','1','t','on'],true);
            if(!$enabled||$dead)continue;
            $row['_name_key']=$key;$row['_extended']=is_array($extended)?$extended:[];
            $eligible[$id]=$row;
        }
        foreach($eligible as $id=>$row)if(($names[$row['_name_key']]??0)!==1)unset($eligible[$id]);
        ksort($eligible,SORT_NUMERIC);
        return $eligible;
    }

    private function identity(int $id): array
    {
        return ['id'=>(string)$id]+$this->store->physicalActor(['kind'=>'npc','id'=>$id]);
    }

    public function poll(): array
    {
        return $this->store->transaction(function():array {
            [$scope,$epoch,$gamets]=$this->context();
            if(!$this->enabled($scope))return ['status'=>'paused','reason'=>'disabled'];
            // The built-in player-backed agent carries the command; it is not companion identity evidence.
            $executor='The Narrator';
            $prior=$this->store->query('SELECT epoch::text,observation_id::text,nonce,actors::text,issued_gamets::text,last_poll_gamets::text,cursor_id::text FROM plugins.correspondence_companion_poll WHERE playthrough_id=$1 FOR UPDATE',[$scope])[0]??null;
            if(is_array($prior)&&$prior['epoch']===$epoch&&is_numeric($prior['last_poll_gamets'])
                &&$gamets-(int)$prior['last_poll_gamets']<self::HOUR)return ['status'=>'idle','reason'=>'poll_cooldown'];
            $eligible=$this->eligible();
            $queued=$this->store->query("SELECT actor_id::text,episode_id::text FROM plugins.correspondence_companion_decision WHERE playthrough_id=$1 AND epoch=$2::uuid AND status='queued'",[$scope,$epoch]);
            foreach($queued as $decision)if(!isset($eligible[(int)$decision['actor_id']])){
                $this->cancel($scope,$epoch,(int)$decision['actor_id'],$decision['episode_id'],'actor_opted_out');
                $this->store->query('DELETE FROM plugins.correspondence_companion_episode WHERE playthrough_id=$1 AND actor_id=$2',[$scope,$decision['actor_id']]);
            }
            if($eligible===[])return ['status'=>'idle','reason'=>'no_eligible_actor'];
            $active=$this->store->query('SELECT actor_id::text,sample_gamets::text FROM plugins.correspondence_companion_episode WHERE playthrough_id=$1 AND epoch=$2::uuid ORDER BY sample_gamets,actor_id',[$scope,$epoch]);
            $ids=[];foreach($active as $row){$id=(int)$row['actor_id'];if(isset($eligible[$id]))$ids[$id]=$id;if(count($ids)===3)break;}
            $cursor=is_array($prior)&&$prior['epoch']===$epoch?(int)$prior['cursor_id']:0;
            $discovery=$cursor;
            foreach([true,false] as $afterCursor)foreach(array_keys($eligible) as $id){
                if(count($ids)===4)break 2;
                if(($id>$cursor)!==$afterCursor||isset($ids[$id]))continue;
                $ids[$id]=$id;$discovery=$id;
            }
            if($ids===[])return ['status'=>'idle','reason'=>'no_eligible_actor'];
            $actors=[];foreach($ids as $id)try{$actors[]=correspondenceCompanionIdentity($this->identity($id));}catch(Throwable){continue;}
            if($actors===[])return ['status'=>'idle','reason'=>'actor_identity_unavailable'];
            $observation=correspondenceUuid();$nonce=bin2hex(random_bytes(32));
            $payload=['version'=>1,'observation_id'=>$observation,'playthrough_id'=>$scope,'epoch'=>$epoch,'nonce'=>$nonce,'actors'=>$actors];
            $json=str_replace(['|','@',"\r","\n"],['\\u007c','\\u0040','\\r','\\n'],correspondenceJson($payload));
            $this->store->query('INSERT INTO plugins.correspondence_companion_poll(playthrough_id,epoch,observation_id,nonce,actors,issued_gamets,issued_at,last_poll_gamets,cursor_id) VALUES($1,$2::uuid,$3::uuid,$4,$5::jsonb,$6::numeric,clock_timestamp(),$6::numeric,$7::bigint) ON CONFLICT(playthrough_id) DO UPDATE SET epoch=EXCLUDED.epoch,observation_id=EXCLUDED.observation_id,nonce=EXCLUDED.nonce,actors=EXCLUDED.actors,issued_gamets=EXCLUDED.issued_gamets,issued_at=EXCLUDED.issued_at,last_poll_gamets=EXCLUDED.last_poll_gamets,cursor_id=EXCLUDED.cursor_id',[$scope,$epoch,$observation,$nonce,correspondenceJson($actors),(string)$gamets,(string)$discovery]);
            $this->store->queueCommand($executor,'command|ExtCmdCHIMCorrespondenceCompanion_Observe@'.$json);
            return ['status'=>'queued','reason'=>'poll_issued','observation_id'=>$observation];
        });
    }

    public function receive(array $request,array $reply): array
    {
        $this->store->bindRequestClock($request);
        return $this->store->transaction(function()use($reply):array {
            [$scope,$epoch,$gamets]=$this->context();
            $row=$this->store->query('SELECT epoch::text,observation_id::text,nonce,actors::text,issued_gamets::text,EXTRACT(EPOCH FROM clock_timestamp()-issued_at)::int AS age FROM plugins.correspondence_companion_poll WHERE playthrough_id=$1 FOR UPDATE',[$scope])[0]??null;
            if(!is_array($row)||$row['epoch']!==$epoch||!is_string($row['observation_id'])||!is_string($row['nonce'])||$row['age']===null||(int)$row['age']<0||(int)$row['age']>120
                ||!is_numeric($row['issued_gamets'])||$gamets<(int)$row['issued_gamets']||$gamets-(int)$row['issued_gamets']>self::HOUR)throw new DomainException('observation_expired');
            $actors=json_decode($row['actors'],true,16,JSON_THROW_ON_ERROR);
            $issued=['version'=>1,'observation_id'=>$row['observation_id'],'playthrough_id'=>$scope,'epoch'=>$epoch,'nonce'=>$row['nonce'],'actors'=>$actors];
            $samples=correspondenceCompanionReply($reply,$issued,$gamets);
            if(!$this->enabled($scope))throw new DomainException('disabled');
            $eligible=$this->eligible();
            foreach($samples as $index=>$sample){
                $id=(int)$sample['id'];
                if(!isset($eligible[$id])||correspondenceCompanionIdentity($this->identity($id))!==$actors[$index])throw new DomainException('actor_identity_changed');
            }
            $this->store->query('UPDATE plugins.correspondence_companion_poll SET observation_id=NULL,nonce=NULL,actors=\'[]\'::jsonb,issued_gamets=NULL,issued_at=NULL WHERE playthrough_id=$1',[$scope]);
            foreach($samples as $index=>$sample){
                $id=(int)$sample['id'];
                $prior=$this->store->query('SELECT epoch::text,episode_id::text,first_game_minute::text,native_first_game_minute::text,game_minute::text,sample_gamets::text FROM plugins.correspondence_companion_episode WHERE playthrough_id=$1 AND actor_id=$2 FOR UPDATE',[$scope,(string)$id])[0]??null;
                if($sample['state']!=='waiting'){
                    $this->store->query('DELETE FROM plugins.correspondence_companion_episode WHERE playthrough_id=$1 AND actor_id=$2',[$scope,(string)$id]);
                    if(is_array($prior))$this->cancel($scope,$epoch,$id,$prior['episode_id'],'waiting_ended');
                    continue;
                }
                $same=is_array($prior)&&$prior['epoch']===$epoch&&$prior['episode_id']===$sample['episode']&&$sample['first_game_minute']===(int)$prior['native_first_game_minute'];
                if($same&&($sample['game_minute']<=(int)$prior['game_minute']||$gamets<=(int)$prior['sample_gamets']))throw new DomainException('sample_progression_invalid');
                $continuous=$same&&$sample['game_minute']-(int)$prior['game_minute']<=120;
                if(is_array($prior)&&!$continuous&&$prior['epoch']===$epoch)$this->cancel($scope,$epoch,$id,$prior['episode_id'],'episode_changed');
                $serverFirst=$continuous?(int)$prior['first_game_minute']:$sample['game_minute'];
                $this->store->query('INSERT INTO plugins.correspondence_companion_episode(playthrough_id,epoch,actor_id,episode_id,first_game_minute,native_first_game_minute,game_minute,sample_gamets,identity) VALUES($1,$2::uuid,$3::bigint,$4::uuid,$5::bigint,$6::bigint,$7::bigint,$8::numeric,$9::jsonb) ON CONFLICT(playthrough_id,actor_id) DO UPDATE SET epoch=EXCLUDED.epoch,episode_id=EXCLUDED.episode_id,first_game_minute=EXCLUDED.first_game_minute,native_first_game_minute=EXCLUDED.native_first_game_minute,game_minute=EXCLUDED.game_minute,sample_gamets=EXCLUDED.sample_gamets,identity=EXCLUDED.identity',[$scope,$epoch,(string)$id,$sample['episode'],(string)$serverFirst,(string)$sample['first_game_minute'],(string)$sample['game_minute'],(string)$gamets,correspondenceJson($actors[$index])]);
            }
            $this->store->appendEvent('correspondence_companion_observation',correspondenceJson(['kind'=>'companion_observation','observation_id'=>$issued['observation_id'],'playthrough_id'=>$scope,'epoch'=>$epoch,'actor_count'=>count($samples)]),'','correspondence_v1');
            return ['status'=>'processed','reason'=>'observation_accepted'];
        });
    }

    private function cancel(string $scope,string $epoch,int $actorId,string $episode,string $reason): void
    {
        $rows=$this->store->query("UPDATE plugins.correspondence_companion_decision SET status='stale',reason_code=$5 WHERE playthrough_id=$1 AND epoch=$2::uuid AND actor_id=$3::bigint AND episode_id=$4::uuid AND status='queued' RETURNING document_id::text",[$scope,$epoch,(string)$actorId,$episode,$reason]);
        foreach($rows as $row)if(is_string($row['document_id']??null))try{$this->service->close($row['document_id']);}catch(DomainException){}
    }

    public function ownsDocument(string $id): bool
    {
        return $this->store->query('SELECT 1 FROM plugins.correspondence_companion_decision WHERE document_id=$1::uuid LIMIT 1',[$id])!==[];
    }

    private function sharedCooldown(string $scope,int $actorId,int $gamets): bool
    {
        $rows=$this->store->query("SELECT COALESCE(MAX(claimed_gamets),0)::text AS global_at,COALESCE(MAX(claimed_gamets) FILTER (WHERE actor_id=$2),0)::text AS actor_at FROM plugins.correspondence_companion_decision WHERE playthrough_id=$1 AND epoch=$3::uuid",[$scope,(string)$actorId,correspondenceCompanionEpoch($this->store->restoreGeneration())]);
        $global=(int)($rows[0]['global_at']??0);$actor=(int)($rows[0]['actor_at']??0);
        if($global>0&&$gamets-$global<self::HOUR || $actor>0&&$gamets-$actor<self::SIX_HOURS)return true;
        foreach([
            ['plugins.correspondence_autonomy_decisions',"SELECT payload->>'claim_gamets' AS clock,payload->>'self_id' AS actor FROM plugins.correspondence_autonomy_decisions WHERE playthrough_id=$1 AND payload->>'restore_generation'=$2 AND payload->>'claim_gamets' ~ '^[0-9]{1,18}$'"],
            ['plugins.correspondence_offscreen_jobs',"SELECT payload->>'claim_gamets' AS clock,actor_id::text AS actor FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id=$1 AND payload->>'restore_generation'=$2 AND payload->>'claim_invalidated' IS DISTINCT FROM 'true' AND payload->>'claim_gamets' ~ '^[0-9]{1,18}$'"],
        ] as [$table,$sql]){
            if(($this->store->query('SELECT to_regclass($1) AS found',[$table])[0]['found']??null)===null)continue;
            foreach($this->store->query($sql,[$scope,$this->store->restoreGeneration()]) as $row){
            $at=(int)$row['clock'];if($at<1||$at>$gamets)continue;
            if($gamets-$at<self::HOUR || (int)$row['actor']===$actorId&&$gamets-$at<self::SIX_HOURS)return true;
            }
        }
        return false;
    }

    private function recentBglLetter(string $name,int $gamets): bool
    {
        $table=$this->store->query("SELECT to_regclass('public.bgl_history') AS history")[0]['history']??null;
        if($table===null)return false;
        $rows=$this->store->query("SELECT 1 FROM public.bgl_history WHERE npc=$1 AND category='letter' AND gamets>0 AND gamets<=$2::numeric AND gamets>$3::numeric LIMIT 1",[$name,(string)$gamets,(string)max(0,$gamets-self::SIX_HOURS)]);
        return $rows!==[];
    }

    private function affinity(array $actor,string $player): ?float
    {
        $relations=$actor['_extended']['relationships']??null;
        if(!is_array($relations))return null;
        foreach($relations as $name=>$relation){
            if(!is_string($name)||strcasecmp($name,$player)!==0||!is_array($relation))continue;
            $value=$relation['aff']??null;
            if((is_int($value)||is_float($value))&&is_finite((float)$value)&&$value>=-100&&$value<=100)return (float)$value;
        }
        return null;
    }

    private function due(string $scope,string $epoch,int $gamets,array $eligible): ?array
    {
        $rows=$this->store->query("SELECT d.actor_id::text,d.episode_id::text,d.document_id::text,d.due_gamets::text FROM plugins.correspondence_companion_decision d LEFT JOIN plugins.correspondence_companion_episode e ON e.playthrough_id=d.playthrough_id AND e.epoch=d.epoch AND e.actor_id=d.actor_id AND e.episode_id=d.episode_id WHERE d.playthrough_id=$1 AND d.epoch=$2::uuid AND d.status='queued' AND d.due_gamets<=$3::numeric ORDER BY (e.sample_gamets>=d.due_gamets AND e.sample_gamets BETWEEN $4::numeric AND $3::numeric) DESC NULLS LAST,d.due_gamets,d.actor_id LIMIT 1 FOR UPDATE OF d",[$scope,$epoch,(string)$gamets,(string)max(0,$gamets-self::HOUR)]);
        if($rows===[])return null;
        $decision=$rows[0];$actorId=(int)$decision['actor_id'];$episode=$this->store->query('SELECT episode_id::text,sample_gamets::text,identity::text FROM plugins.correspondence_companion_episode WHERE playthrough_id=$1 AND epoch=$2::uuid AND actor_id=$3',[$scope,$epoch,(string)$actorId])[0]??null;
        $document=$this->store->loadDocument($decision['document_id']);
        if(!is_array($document)||isset($document['operator_close'])){
            $this->cancel($scope,$epoch,$actorId,$decision['episode_id'],'operator_closed');
            return ['status'=>'stale','reason'=>'operator_closed'];
        }
        $identityMatches=false;
        if(isset($eligible[$actorId])&&is_array($episode)&&$episode['episode_id']===$decision['episode_id'])
            try{$identityMatches=json_decode($episode['identity'],true)===$this->identity($actorId);}catch(DomainException){}
        if($identityMatches)try{$this->service->get($decision['document_id']);}catch(DomainException){$identityMatches=false;}
        if(!$identityMatches){
            $this->cancel($scope,$epoch,$actorId,$decision['episode_id'],'dispatch_state_changed');
            return ['status'=>'stale','reason'=>'dispatch_state_changed'];
        }
        if($this->recentBglLetter($eligible[$actorId]['npc_name'],$gamets)){
            $this->cancel($scope,$epoch,$actorId,$decision['episode_id'],'recent_bgl_letter');
            return ['status'=>'stale','reason'=>'recent_bgl_letter'];
        }
        if((int)$episode['sample_gamets']<(int)$decision['due_gamets']||(int)$episode['sample_gamets']>$gamets||$gamets-(int)$episode['sample_gamets']>self::HOUR)
            return null;
        $result=$this->service->dispatch($decision['document_id'],false,true);
        if(($result['reason']??null)==='executor_unavailable')return ['status'=>'idle','reason'=>'executor_unavailable'];
        $state=($result['operation_id']??null)!==null?'dispatched':'unknown';
        $this->store->query('UPDATE plugins.correspondence_companion_decision SET status=$5,reason_code=$6 WHERE playthrough_id=$1 AND epoch=$2::uuid AND actor_id=$3::bigint AND episode_id=$4::uuid AND status=\'queued\'',[$scope,$epoch,(string)$actorId,$decision['episode_id'],$state,$state==='dispatched'?'dispatched':'dispatch_unknown']);
        return ['status'=>$state,'reason'=>$state==='dispatched'?'dispatched':'dispatch_unknown'];
    }

    public function tick(?callable $model): array
    {
        if(function_exists('chimInteractionAllowed')&&!chimInteractionAllowed())return ['status'=>'paused','reason'=>'interaction_disabled'];
        $work=$this->store->transaction(function()use($model):array {
            [$scope,$epoch,$gamets,$restoreToken]=$this->context();
            if(!$this->enabled($scope))return ['status'=>'paused','reason'=>'disabled'];
            $eligible=$this->eligible();
            $due=$this->due($scope,$epoch,$gamets,$eligible);if($due!==null)return $due;
            if($model===null)return ['status'=>'idle','reason'=>'model_unavailable'];
            $this->store->query("DELETE FROM plugins.correspondence_companion_decision WHERE playthrough_id=$1 AND epoch=$2::uuid AND status='claimed' AND attempted=FALSE AND created_at<clock_timestamp()-INTERVAL '120 seconds'",[$scope,$epoch]);
            $rows=$this->store->query("SELECT e.actor_id::text,e.episode_id::text,e.first_game_minute::text,e.game_minute::text,e.sample_gamets::text,e.identity::text FROM plugins.correspondence_companion_episode e WHERE e.playthrough_id=$1 AND e.epoch=$2::uuid AND e.game_minute-e.first_game_minute>=2880 AND e.sample_gamets<=$3::numeric AND e.sample_gamets>$4::numeric AND NOT EXISTS(SELECT 1 FROM plugins.correspondence_companion_decision d WHERE d.playthrough_id=e.playthrough_id AND d.epoch=e.epoch AND d.actor_id=e.actor_id AND d.episode_id=e.episode_id) ORDER BY e.sample_gamets,e.actor_id LIMIT 12",[$scope,$epoch,(string)$gamets,(string)max(0,$gamets-self::HOUR)]);
            $deferred='no_eligible_episode';
            foreach($rows as $row){
                $actorId=(int)$row['actor_id'];$actor=$eligible[$actorId]??null;
                if(!is_array($actor)||json_decode($row['identity'],true)!==$this->identity($actorId))continue;
                if($this->sharedCooldown($scope,$actorId,$gamets)){$deferred='cooldown';continue;}
                if($this->recentBglLetter($actor['npc_name'],$gamets)){$deferred='recent_bgl_letter';continue;}
                $player=$this->store->player();
                $personality=is_string($actor['personality']??null)?$actor['personality']:'';
                if(strlen($personality)>8000||preg_match('//u',$personality)!==1)$personality='';
                $messages=correspondenceCompanionMessages($actor['npc_name'],$player,$personality,$this->affinity($actor,$player));
                $requestId=correspondenceUuid();
                $insert=$this->store->query("INSERT INTO plugins.correspondence_companion_decision(playthrough_id,epoch,actor_id,episode_id,request_id,claimed_gamets,status,reason_code) VALUES($1,$2::uuid,$3::bigint,$4::uuid,$5::uuid,$6::numeric,'claimed','claimed') ON CONFLICT DO NOTHING RETURNING request_id::text",[$scope,$epoch,(string)$actorId,$row['episode_id'],$requestId,(string)$gamets]);
                if($insert===[])continue;
                return ['status'=>'claimed','reason'=>'claimed','scope'=>$scope,'epoch'=>$epoch,'restore_token'=>$restoreToken,'actor_id'=>$actorId,'episode'=>$row['episode_id'],'request_id'=>$requestId,'gamets'=>$gamets,'messages'=>$messages];
            }
            return ['status'=>'idle','reason'=>$deferred];
        });
        if(($work['status']??null)!=='claimed')return $work;
        $marked=$this->store->transaction(function()use($work):bool {
            [$scope,$epoch]=$this->context();
            if($scope!==$work['scope']||$epoch!==$work['epoch'])return false;
            return $this->store->query("UPDATE plugins.correspondence_companion_decision SET attempted=TRUE,reason_code='model_attempted' WHERE playthrough_id=$1 AND epoch=$2::uuid AND actor_id=$3::bigint AND episode_id=$4::uuid AND request_id=$5::uuid AND status='claimed' AND attempted=FALSE RETURNING request_id",[$scope,$epoch,(string)$work['actor_id'],$work['episode'],$work['request_id']])!==[];
        });
        if(!$marked)return ['status'=>'stale','reason'=>'claim_changed'];
        $decision=null;$failure=null;
        try{$raw=$model($work['messages']);$decision=correspondenceCompanionDecision($raw);}
        catch(Throwable $error){$failure=$error instanceof InvalidArgumentException||$error instanceof JsonException?'invalid_decision':'model_error';}
        try{return $this->store->completionTransaction(function()use($work,$decision,$failure):array {
            [$scope,$epoch,$gamets]=$this->context();
            if($scope!==$work['scope']||$epoch!==$work['epoch'])return ['status'=>'stale','reason'=>'restore_changed'];
            $row=$this->store->query("SELECT attempted,status FROM plugins.correspondence_companion_decision WHERE playthrough_id=$1 AND epoch=$2::uuid AND actor_id=$3::bigint AND episode_id=$4::uuid AND request_id=$5::uuid FOR UPDATE",[$scope,$epoch,(string)$work['actor_id'],$work['episode'],$work['request_id']])[0]??null;
            if(!is_array($row)||$row['status']!=='claimed'||!in_array($row['attempted'],[true,'t'],true))return ['status'=>'stale','reason'=>'claim_changed'];
            $status='failed';$reason=$failure??'invalid_decision';$document=null;$due=null;
            $episode=$this->store->query('SELECT episode_id::text,sample_gamets::text,identity::text FROM plugins.correspondence_companion_episode WHERE playthrough_id=$1 AND actor_id=$2',[$scope,(string)$work['actor_id']])[0]??null;
            $eligible=$this->eligible();$actor=$eligible[$work['actor_id']]??null;
            if(!is_array($episode)||$episode['episode_id']!==$work['episode']||$gamets-(int)$episode['sample_gamets']>self::HOUR||!is_array($actor)||json_decode($episode['identity'],true)!==$this->identity($work['actor_id'])||!$this->enabled($scope)){$status='stale';$reason='context_changed';}
            elseif($this->recentBglLetter($actor['npc_name'],$gamets)){$status='stale';$reason='recent_bgl_letter';}
            elseif($failure===null&&$decision['action']==='none'){$status='silent';$reason='silence';}
            elseif($failure===null){
                $input=['request_id'=>$work['request_id'],'author'=>'npc:'.$work['actor_id'],'payer'=>'npc:'.$work['actor_id'],'recipient'=>'player','claimed_sender'=>'npc:'.$work['actor_id'],'body'=>$decision['body'],'attachments'=>[]];
                $document=$this->service->compose($input)['document_id'];$due=$gamets+self::TWELVE_HOURS;$status='queued';$reason='queued_for_transit';
            }
            $this->store->query('UPDATE plugins.correspondence_companion_decision SET status=$5,reason_code=$6,document_id=$7::uuid,due_gamets=$8::numeric WHERE playthrough_id=$1 AND epoch=$2::uuid AND actor_id=$3::bigint AND episode_id=$4::uuid AND request_id=$9::uuid AND status=\'claimed\' AND attempted=TRUE',[$scope,$epoch,(string)$work['actor_id'],$work['episode'],$status,$reason,$document,$due===null?null:(string)$due,$work['request_id']]);
            return ['status'=>$status,'reason'=>$reason,'model_calls'=>1];
        },$work['restore_token']);}catch(Throwable){return ['status'=>'failed','reason'=>'completion_unavailable','model_calls'=>1];}
    }
}
