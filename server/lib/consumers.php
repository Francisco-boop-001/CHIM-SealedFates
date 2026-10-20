<?php
declare(strict_types=1);
function correspondenceCallConsumer(int $eventId,callable $capability,callable $request):array
{
    if($eventId<1)return ['status'=>'invalid','reason'=>'invalid_reference'];
    try{
        $cap=$capability();
        if(!is_array($cap)||($cap['version']??null)!==1||($cap['input_type']??null)!=='written_evidence'||($cap['source_marker']??null)!=='written_evidence_v1'||($cap['source_event_type']??null)!=='info'||($cap['recipient_only']??null)!==true||($cap['max_body_codepoints']??0)<2000)return ['status'=>'unsupported','reason'=>'capability_mismatch'];
        $result=$request($eventId);
        if(!is_array($result)||!in_array($result['status']??null,['invalid','pending','queued','paused','failed','abstained','processed'],true)||!is_string($result['reason']??null)||preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D',$result['reason'])!==1)return ['status'=>'unavailable','reason'=>'invalid_consumer_result'];
        return ['status'=>$result['status'],'reason'=>$result['reason']];
    }catch(Throwable){return ['status'=>'unavailable','reason'=>'consumer_unavailable'];}
}
function correspondenceConsumeReferences(string $root,mixed $connection,array $eventIds,bool $readOnly=false,?array $mpReferences=null):array
{
    foreach(['/ext/sworn_and_scorned/written_source.php','/ext/mind_poisoning/written_evidence.php']as$entry){$path=$root.$entry;if(is_file($path)){try{require_once $path;}catch(Throwable){}}}
    $ids=array_values(array_unique(array_filter($eventIds,fn($id)=>is_int($id)&&$id>0)));
    $mpIds=array_values(array_unique(array_filter($mpReferences??$ids,fn($id)=>is_int($id)&&$id>0)));sort($mpIds,SORT_NUMERIC);
    $results=[];$mpStore=null;
    $mpAvailable=function_exists('ChimMindPoisoning\\writtenEvidenceCapability')&&function_exists('ChimMindPoisoning\\writtenEvidenceStatus')&&function_exists('ChimMindPoisoning\\enqueueWrittenEvidence')&&class_exists('ChimMindPoisoning\\PostgresStoreDb');
    foreach(array_values(array_unique(array_merge($ids,$mpIds)))as$id){
        $results[$id]=[];
        if(in_array($id,$ids,true)){
            if(function_exists('ChimSwornAndScorned\\writtenEvidenceCapability')&&function_exists('ChimSwornAndScorned\\enqueueWrittenEvidence')&&class_exists('ChimSwornAndScorned\\Pg')){
                $results[$id]['sworn_and_scorned']=correspondenceCallConsumer($id,'ChimSwornAndScorned\\writtenEvidenceCapability',fn($ref)=>$readOnly?\ChimSwornAndScorned\writtenEvidenceStatus(new \ChimSwornAndScorned\Pg($connection),$ref):\ChimSwornAndScorned\enqueueWrittenEvidence(new \ChimSwornAndScorned\Pg($connection),$ref));
            }else $results[$id]['sworn_and_scorned']=['status'=>'unavailable','reason'=>'consumer_absent'];
        }
        if(in_array($id,$mpIds,true)&&$mpAvailable){
            $results[$id]['mind_poisoning']=correspondenceCallConsumer($id,'ChimMindPoisoning\\writtenEvidenceCapability',function($ref)use($connection,&$mpStore){$mpStore??=new \ChimMindPoisoning\PostgresStoreDb(null,$connection);return \ChimMindPoisoning\writtenEvidenceStatus($ref,$mpStore);});
        }else $results[$id]['mind_poisoning']=['status'=>'unavailable','reason'=>'consumer_absent'];
    }
    if($readOnly||!$mpAvailable)return $results;
    foreach($mpIds as$id)if($results[$id]['mind_poisoning']['status']==='queued')return $results;
    foreach($mpIds as$id)if($results[$id]['mind_poisoning']['status']==='pending'){
        // MP drains one request-local hint; already-exposed backlog does not need to be reprinted.
        $results[$id]['mind_poisoning']=correspondenceCallConsumer($id,'ChimMindPoisoning\\writtenEvidenceCapability',fn($ref)=>\ChimMindPoisoning\enqueueWrittenEvidence($ref,$mpStore));break;
    }
    return $results;
}
