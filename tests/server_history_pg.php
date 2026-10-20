<?php
declare(strict_types=1);
require_once __DIR__.'/support/correspondence_core_function.php';require_once dirname(__DIR__).'/server/lib/store.php';
function historyCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
historyCheck(getenv('WSL_DISTRO_NAME')==='DwemerAI4Skyrim3-test','registered disposable clone only');
$c=pg_connect('host=/var/run/postgresql dbname=chim_correspondence_it user=postgres',PGSQL_CONNECT_FORCE_NEW);historyCheck($c!==false,'peer fixture connection required');
$guard=pg_fetch_assoc(pg_query($c,"SELECT current_database() AS db,current_user AS role,current_setting('listen_addresses') AS listeners"));historyCheck($guard['db']==='chim_correspondence_it'&&$guard['role']==='postgres'&&$guard['listeners']==='','isolated socket-only fixture required');
$sourcePath=getenv('CORRESPONDENCE_TEST_CORE_DATA_FUNCTIONS');historyCheck(is_string($sourcePath),'exact pinned core history source required');correspondenceLoadPinnedFunction($sourcePath,'1111b724c65b017dbbc7baec6dc32a0cee0277222dc6f3bdb6b7a7414e547552','buildHistoricContext');
function chimBuildChatDeliveryStateSql(string $column):string{return "($column IS DISTINCT FROM 'failed')";}
final class CorrespondenceHistoryQueryCaptured extends RuntimeException{}
final class CorrespondenceHistoryRollback extends RuntimeException{}
final class CorrespondenceHistoryQueryDb
{
    public string $query='';public function __construct(private mixed $connection){}
    public function escape(string $value):string{return pg_escape_string($this->connection,$value);}
    public function fetchAll(string $query):never{$this->query=$query;throw new CorrespondenceHistoryQueryCaptured();}
}
$store=new CorrespondencePgStore($c,fn($s)=>null,fn($title,$body)=>true);$clock=(int)($store->query('SELECT MAX(ts) AS ts FROM public.eventlog')[0]['ts']??100)+10;$store->bindRequestClock(['inputtext',(string)$clock,(string)$clock]);
try{$store->transaction(function()use($store,$c){
    // All mutations use the real public-table producer and roll back together on success.
    $store->query('ALTER TABLE public.eventlog ADD COLUMN IF NOT EXISTS private_thought text, ADD COLUMN IF NOT EXISTS delivery_state text');
    $ids=[];$insert=function(string $data,int $gamets=100,string $type='inputtext')use($store,&$ids){$row=$store->query("INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(100,$1,$2,$3,'pending',100,'|Camilla|Lydia|','','') RETURNING rowid",[$gamets,$type,$data])[0];$ids[]=(int)$row['rowid'];};
    $insert('Ordinary speech stays visible');$insert('Prior filter hides this');$insert('Prior clock filter hides this',0);$insert('Ordinary non-JSON info telemetry',100,'info');
    foreach(['correspondence_anchor','correspondence_dispatch','correspondence_receipt_confirmed','correspondence_presence']as$type){$row=$store->appendEvent($type,json_encode(['private'=>$type,'scope_token'=>'private_nonce'],JSON_THROW_ON_ERROR),'|Camilla|Lydia|','correspondence_v1');historyCheck($row['type']==='info'&&$row['sess']==='correspondence_v1','private anchors must use log-only physical carrier');$ids[]=(int)$row['rowid'];}
    $fixture=json_decode(file_get_contents(dirname(__DIR__).'/contracts/written-evidence-v1.json'),true,512,JSON_THROW_ON_ERROR);$fixture['document_id']=correspondenceUuid();$row=$store->appendEvent('written_evidence',correspondenceJson($fixture),'|Camilla|','written_evidence_v1');historyCheck($row['type']==='info'&&$row['sess']==='written_evidence_v1','neutral source must use exact safe physical carrier');$ids[]=(int)$row['rowid'];
    try{$store->appendEvent('written_evidence',correspondenceJson($fixture),'|Camilla|','written_evidence_v1');throw new RuntimeException('duplicate source accepted');}catch(DomainException $e){historyCheck($e->getMessage()==='duplicate_written_source','materialized exact-marker duplicate query must ignore ordinary non-JSON info');}
    $GLOBALS['gameRequest']=['inputtext','100','100','fixture'];$GLOBALS['db']=$db=new CorrespondenceHistoryQueryDb($c);$fixtureFilter=' AND a.rowid IN ('.implode(',',$ids).')';
    foreach([false,true]as$priorFilters){$GLOBALS['EXT_CONTEXT_SQL_FILTER1']=$priorFilters?" AND a.data <> 'Prior filter hides this'":'';$GLOBALS['EXT_CONTEXT_SQL_FILTER2']=$priorFilters?' AND a.gamets > 0':'';
        // No correspondence preprocessing/prerequest hook: this is the BGL entry-point condition.
        foreach([''=>'Narrator','Camilla'=>'recipient','Lydia'=>'unrelated NPC']as$actor=>$label){try{buildHistoricContext($actor,-10,$fixtureFilter);throw new RuntimeException('history query not intercepted');}catch(CorrespondenceHistoryQueryCaptured){}$r=pg_query($c,$db->query);historyCheck($r!==false,'actual core query must execute natively');$rows=pg_fetch_all($r)?:[];$data=array_column($rows,'data');historyCheck(count($rows)===($priorFilters?1:3)&&in_array('Ordinary speech stays visible',$data,true)&&!str_contains(json_encode($rows),'private_nonce')&&!str_contains(json_encode($rows),$fixture['document_id']),'generic '.$label.' history must exclude actual producer carriers before raw logging/compaction while preserving ordinary speech/prior filters');echo 'SQL '.($priorFilters?'prior-filtered ':'unhooked-BGL ').$label.': '.preg_replace('/\s+/',' ',trim($db->query))."\n";}
    }
    throw new CorrespondenceHistoryRollback();
});}catch(CorrespondenceHistoryRollback){echo "PASS native core history privacy: actual PgStore info carriers, exact pinned buildHistoricContext queries without extension hooks (BGL), Narrator/recipient/unrelated NPC, ordinary speech and prior filters before logging/compaction, materialized duplicate check with non-JSON info; rolled back fixture\n";}finally{pg_close($c);}