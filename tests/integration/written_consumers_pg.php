<?php
declare(strict_types=1);
// Real producer/store and owner public API; deterministic decision backend only, no provider.
$project=dirname(__DIR__,2);$sas=dirname($project).'/CHIM-SwornAndScorned-dev';
require $project.'/server/lib/store.php';require $project.'/server/lib/boundaries.php';require $project.'/server/lib/consumers.php';
require $sas.'/server/written_source.php';require $sas.'/server/queue.php';require $sas.'/tests/support/scripted_backend.php';
use ChimSwornAndScorned\{Pg,PerspectiveStore,JobDeps};
use function ChimSwornAndScorned\{ensureSchema,saveSetting,currentPlaythrough,readEvidenceLine,normalizeLedger,claimJob,processJob,writtenEvidenceStatus};
use const ChimSwornAndScorned\POLICY_DECISION_MODEL;
function crossCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
crossCheck(getenv('WSL_DISTRO_NAME')==='DwemerAI4Skyrim3-test','clone only');
$c=pg_connect('host=/var/run/postgresql dbname=chim_correspondence_cross_it user=postgres');
$guard=pg_fetch_assoc(pg_query($c,"SELECT current_database() db,current_user role,current_setting('listen_addresses') listeners"));
crossCheck($guard===['db'=>'chim_correspondence_cross_it','role'=>'postgres','listeners'=>''],'isolated peer DB only');
$pg=new Pg($c);
crossCheck($pg->all("SELECT table_name FROM information_schema.tables WHERE table_schema IN ('public','plugins')")===[],'blank owned fixture required');
$ddl=<<<'SQL'
CREATE TABLE eventlog(rowid bigserial PRIMARY KEY,ts bigint,gamets numeric,type text,data text,sess text,localts bigint,people text,location text,party text,delivery_state text);
CREATE TABLE core_npc_master(id bigint PRIMARY KEY,npc_name text,refid text,profile_id bigint,lock_profile integer DEFAULT 0,extended_data jsonb DEFAULT '{}',plugin_extended_data jsonb DEFAULT '{}',gamets_last_updated numeric DEFAULT 0);
CREATE TABLE core_npc_master_history(history_id bigserial PRIMARY KEY,npc_id bigint,npc_name text,refid text,profile_id bigint,lock_profile integer,extended_data jsonb,plugin_extended_data jsonb,gamets_last_updated numeric,created timestamp);
CREATE TABLE core_player(id text PRIMARY KEY,value text);
CREATE TABLE game_plugins(plugin_name text,formid_prefix text);
CREATE TABLE books(ts bigint,gamets numeric,content text,sess text,localts bigint,title text);
CREATE TABLE responselog(localts bigint,sent integer,actor text,text text,action text,tag text);
INSERT INTO eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(100,100,'inputtext','synthetic seed','',100,'','','');
INSERT INTO core_player VALUES('player_name','Dragonborn');
INSERT INTO core_npc_master(id,npc_name,refid,profile_id) VALUES(900001,'Camilla','00013488',1),(900002,'Jarl Balgruuf','0001A677',1),(900003,'Lydia','000A2C94',1);
INSERT INTO game_plugins VALUES('Skyrim.esm','00');
SQL;
crossCheck(pg_query($c,$ddl)!==false,'synthetic native table shape');
crossCheck(pg_query($c,file_get_contents($project.'/server/migrations/001_initial.sql'))!==false,'real producer migration');

$store=new CorrespondencePgStore($c,fn($s)=>$s->observedExecutor(),fn($title,$body)=>true);$store->ensureSchema();$store->bindRequestClock(['inputtext','100','100','synthetic native fixture']);$service=new CorrespondenceService($store);
$store->observeManaged(base64_encode(correspondenceJson(['source'=>'plugin_player_routing_v2','present_actors'=>[['form_id'=>hexdec('000A2C94'),'name'=>'Lydia','distance'=>10,'managed'=>true,'creature'=>false]]])));
$body="Camilla, you are a disgrace and I despise you.\nSigned, Jarl Balgruuf.";
$doc=$service->compose(['request_id'=>correspondenceUuid(),'author'=>'player','payer'=>'player','recipient'=>'npc:900001','claimed_sender'=>'npc:900002','body'=>$body,'attachments'=>[['kind'=>'gold','form_id'=>'Skyrim.esm|0000000F','quantity'=>5]]]);
$sent=$service->dispatch($doc['document_id']);$op=$store->loadOperation($sent['operation_id']);
$receipt=array_intersect_key($op,array_flip(['protocol_version','document_id','operation_id','receipt_id','playthrough_id','scope_token','nonce']));
$receipt+=['status'=>'delivered','reason'=>'physical_only','note_form_id'=>12345,'attachments'=>[['form_id'=>15,'quantity'=>5,'before_source'=>10,'after_source'=>5,'before_recipient'=>0,'after_recipient'=>5]]];
$service->receive($receipt);crossCheck($pg->all("SELECT rowid FROM eventlog WHERE type='info' AND sess='written_evidence_v1'")===[],'possession not exposure');
$context=$service->prompt('Camilla');crossCheck(str_contains($context,$body)===false && str_contains($context,'Jarl Balgruuf'),'context contains JSON escaped original');
$eventId=$service->sourceReferences[0];$event=$pg->one('SELECT data,people FROM eventlog WHERE rowid=$1',[$eventId]);$source=json_decode($event['data'],true);
crossCheck($source['body']===$body && $source['attachments'][0]['quantity']===5 && $event['people']==='|Camilla|','exact source from real service');
// S&S ownership rule: its synthetic writes stay in sas_it, with an exact neutral-row transfer seam.
crossCheck($pg->one("SELECT to_regnamespace('sas_it') s")['s']===null,'owned SAS fixture schema must be absent');
$pg->exec('CREATE SCHEMA sas_it');
foreach(['core_npc_master','core_npc_master_history','core_player','eventlog'] as $table){
    $pg->exec('CREATE TABLE sas_it.'.$table.' (LIKE public.'.$table.' INCLUDING ALL)');
    $pg->exec('INSERT INTO sas_it.'.$table.' SELECT * FROM public.'.$table);
}
$pg->exec('CREATE SEQUENCE sas_it.history_id_seq');
$pg->exec("ALTER TABLE sas_it.core_npc_master_history ALTER history_id SET DEFAULT nextval('sas_it.history_id_seq')");
$pg->exec('SET search_path=sas_it,public');putenv('SAS_TEST_SCHEMA=sas_it');
crossCheck($pg->one('SELECT data FROM sas_it.eventlog WHERE rowid=$1',[$eventId])['data']===$event['data'],'exact persisted neutral source transferred');
ensureSchema($pg);saveSetting($pg,'enabled',true);saveSetting($pg,'cast',[900001,900002]);$pt=currentPlaythrough($pg);
$entry=static fn(mixed $v):array=>['value'=>$v,'p'=>0.99];
$answers=['commitment'=>$entry(false),'wronged'=>$entry(true),'written.attribution'=>$entry(true),'grievance.line'=>$entry('L1'),'grievance.wants'=>$entry('unknown'),'grievance.matter'=>$entry('L1'),'grievance.nature'=>$entry('insult'),'grievance.settlement'=>$entry('unknown')];
$status=correspondenceCallConsumer($eventId,'ChimSwornAndScorned\\writtenEvidenceCapability',fn($id)=>ChimSwornAndScorned\enqueueWrittenEvidence($pg,$id));
crossCheck($status['status']==='pending'&&$status['reason']==='awaiting_scan','fresh letter must settle before queue; '.correspondenceJson($status));
sleep(21);
$status=correspondenceCallConsumer($eventId,'ChimSwornAndScorned\\writtenEvidenceCapability',fn($id)=>ChimSwornAndScorned\enqueueWrittenEvidence($pg,$id));
crossCheck($status['status']==='queued','settled public consumer queues source, not processed; '.correspondenceJson($status));
$deps=new class($pg,$pt,$answers) implements JobDeps {
    public function __construct(private Pg $pg,private array $pt,private array $answers){}
    public function currentPlaythroughId():?string{return currentPlaythrough($this->pg)['id']??null;}
    public function linesFor(array $ids):array{$out=[];foreach($ids as$id){$line=readEvidenceLine($this->pg,$id,$this->pt);if($line!==null)$out[]=$line;}return $out;}
    public function ledgerFor(int $id,string $pt):array{$row=$this->pg->one("SELECT (plugin_extended_data->'sworn_and_scorned')::text l FROM core_npc_master WHERE id=$1",[$id]);return normalizeLedger($row['l']===null?null:json_decode($row['l'],true),$pt);}
    public function counterpartKey(string $name):?string{return $name==='Jarl Balgruuf'?'npc:900002':null;}
    public function backend(string $kind):?array{return [new ScriptedBackend($this->answers),POLICY_DECISION_MODEL];}
    public function commitLedger(array $request,callable $mutate):string{return (new PerspectiveStore($this->pg))->commitLedger($request,$mutate);}
};
$job=claimJob($pg,'unprofiled');crossCheck($job['owner_npc_id']===900001,'recipient job only');
crossCheck(processJob($job,$deps)===['outcome'=>'done','reason'=>'committed'],'actual locked owner commit');
$ledger=$deps->ledgerFor(900001,'unprofiled');$issue=$ledger['issues'][0];
crossCheck($issue['kind']==='grievance'&&$issue['status']==='open'&&$issue['counterpart']==='npc:900002','persisted issue attribution');
crossCheck($issue['substance']['matter']['text']===$body && correspondenceCanonicalJson($issue['substance']['matter']['written']['claimed_sender'])===correspondenceCanonicalJson($source['claimed_sender']),'exact original evidence retained');
crossCheck($deps->ledgerFor(900002,'unprofiled')['issues']===[],'absent signature owner gets no issue');
crossCheck($pg->one('SELECT extended_data::text v FROM core_npc_master WHERE id=900001')['v']==='{}','new grievance/gift does not automatically change affinity');
crossCheck(writtenEvidenceStatus($pg,$eventId)['status']==='processed','public readback confirms persisted result');
$history=$pg->one("SELECT (plugin_extended_data->'sworn_and_scorned')::text l FROM core_npc_master_history WHERE npc_id=900001 ORDER BY history_id DESC LIMIT 1");
crossCheck(normalizeLedger(json_decode($history['l'],true),'unprofiled')===$ledger,'restore history includes exact ledger/replay');
$before=count($pg->all('SELECT * FROM core_npc_master_history'));ChimSwornAndScorned\enqueueWrittenEvidence($pg,$eventId);
crossCheck(count($pg->all('SELECT * FROM core_npc_master_history'))===$before,'replay changes neither ledger nor history');
echo "PASS cross-plugin native Pg: real correspondence source -> exact neutral-row transfer into guarded sas_it -> capability adapter -> S&S queue -> deterministic decision -> locked ledger/history -> public processed readback; no provider or game execution\n";
