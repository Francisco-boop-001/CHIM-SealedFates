<?php
declare(strict_types=1);
namespace ChimMindPoisoning;
require_once __DIR__.'/correspondence_idle_pg_fixture.php';
interface StoreDb{}
final class PostgresStoreDb implements StoreDb
{
    public function __construct(?object $requestLog=null,mixed $trustedPgConnection=null){$GLOBALS['correspondence_mp_fixture']['connections'][]=$trustedPgConnection;}
}
function writtenEvidenceCapability():array{return ['version'=>$GLOBALS['correspondence_mp_fixture']['version']??1,'input_type'=>'written_evidence','source_marker'=>'written_evidence_v1','source_event_type'=>'info','max_body_codepoints'=>2000,'max_body_bytes'=>8000,'recipient_only'=>true];}
function writtenEvidenceStatus(int $eventId,?StoreDb $store=null):array
{
    $state=&$GLOBALS['correspondence_mp_fixture'];$state['status_calls'][]=$eventId;
    if(!empty($state['throw']))throw new \RuntimeException('Private payer and credential text');
    $status=isset($state['processed'][$eventId])?'processed':(isset($state['hints'][$eventId])?'queued':($state['statuses'][$eventId]??(!empty($state['paused'])?'paused':'pending')));
    return ['status'=>$status,'reason'=>'fixture_status','credential_readiness'=>'unchecked','actual_author'=>'Secret Author','payer'=>'Secret Payer'];
}
function enqueueWrittenEvidence(int $eventId,?StoreDb $store=null):array
{
    $state=&$GLOBALS['correspondence_mp_fixture'];if(!$store instanceof PostgresStoreDb)throw new \RuntimeException('wrong public store');
    $state['enqueued'][]=$eventId;$state['hints'][$eventId]=true;return ['status'=>'queued','reason'=>'hint_registered','credential_readiness'=>'unchecked','actual_author'=>'Secret Author'];
}
function drainWrittenEvidenceHints(?StoreDb $store=null,?callable $requestModel=null):array
{
    $state=&$GLOBALS['correspondence_mp_fixture'];foreach(array_keys($state['hints']??[])as$id){unset($state['hints'][$id]);if(!empty($state['paused']))continue;$state['processed'][$id]=true;return ['status'=>'processed','event_id'=>$id];}return ['status'=>'pending'];
}
function evaluateWrittenEvidence(int $eventId,?StoreDb $store=null,?callable $requestModel=null,?object $requestLog=null):array{$GLOBALS['correspondence_mp_fixture']['forbidden_evaluate']=true;throw new \RuntimeException('adapter must not evaluate');}
