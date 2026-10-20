<?php
declare(strict_types=1);
$project=dirname(__DIR__,2);require $project.'/server/lib/store.php';require $project.'/server/lib/boundaries.php';
if(getenv('WSL_DISTRO_NAME')!=='DwemerAI4Skyrim3-test')throw new RuntimeException('clone only');
$c=pg_connect('host=/var/run/postgresql dbname=chim_correspondence_it_snapshot1 user=postgres');
$guard=pg_fetch_assoc(pg_query($c,"SELECT current_database() db,current_setting('listen_addresses') listeners"));
if($guard!==['db'=>'chim_correspondence_it_snapshot1','listeners'=>''])throw new RuntimeException('owned synthetic snapshot only');
$store=new CorrespondencePgStore($c,fn($s)=>$s->observedExecutor(),fn($title,$body)=>true);$store->bindRequestClock(['inputtext','100','100','synthetic native fixture']);$service=new CorrespondenceService($store);
$out='/mnt/k/ActorwrightExchange/artifacts/chim-correspondence-implementation-20261019/carrier-roundtrip';
$playerMode=in_array($argv[1]??'',['setup-player','receive-player'],true);$phase=str_replace('-player','',$argv[1]??'');
if($phase==='setup'){
    $store->observeManaged(base64_encode(correspondenceJson(['source'=>'plugin_player_routing_v2','present_actors'=>[['form_id'=>hexdec('000A2C94'),'name'=>'Lydia','distance'=>10,'managed'=>true,'creature'=>false]]])));
    $doc=$service->compose(['request_id'=>correspondenceUuid(),'author'=>$playerMode?'npc:900002':'player','payer'=>'player','recipient'=>$playerMode?'player':'npc:900001','claimed_sender'=>'npc:900002','body'=>'Bridge receipt interoperability fixture.','attachments'=>$playerMode?[]:[['kind'=>'gold','form_id'=>'Skyrim.esm|0000000F','quantity'=>5]]]);
    $result=$service->dispatch($doc['document_id']);if($result['state']!=='dispatched')throw new RuntimeException('dispatch failed');
    $rows=$store->query("SELECT action FROM responselog WHERE action LIKE $1",['command|ExtCmdCHIMCorrespondenceBridge_Deliver@%'.$doc['document_id'].'%']);
    if(count($rows)!==1)throw new RuntimeException('exact queued wire missing');
    file_put_contents($out.($playerMode?'/server-wire-player.json':'/server-wire-exact.json'),explode('@',$rows[0]['action'],2)[1]);
    echo "PASS actual Pg producer setup; exact wire exported without record newline\n";
}elseif($phase==='receive'){
    $receipt=json_decode(file_get_contents($out.($playerMode?'/bridge/player-roundtrip/actual-server-wire-fake-native-receipt.json':'/bridge/actual-server-wire-fake-native-receipt.json')),true,512,JSON_THROW_ON_ERROR);
    $result=$service->receive($receipt);if(!in_array($result['state'],['delivered','exposed'],true))throw new RuntimeException('real service rejected translated bridge receipt');
    if($service->receive($receipt)!==$result)throw new RuntimeException('receipt replay mismatch');
    if($playerMode){$visible=$service->readPlayer($receipt['document_id']);if($visible['body']!=='Bridge receipt interoperability fixture.'||$visible['attachments']!==[])throw new RuntimeException('confirmed browser projection missing');}else $service->prompt('Camilla');
    $rows=$store->query("WITH sources AS MATERIALIZED (SELECT data FROM eventlog WHERE type='info' AND sess='written_evidence_v1') SELECT data FROM sources WHERE data::jsonb->>'document_id'=$1",[$receipt['document_id']]);
    if($playerMode?$rows!==[]:(count($rows)!==1||json_decode($rows[0]['data'],true)['attachments'][0]['quantity']!==5))throw new RuntimeException('incorrect exposure authority');
    echo "PASS actual Pg producer wire -> translated production bridge/fake natives -> real Pg receipt ingestion/replay -> recipient projection; Player browser invents no native-read evidence; no native gameplay\n";
}else throw new RuntimeException('setup or receive phase required');
