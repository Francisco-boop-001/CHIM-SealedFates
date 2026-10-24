<?php
declare(strict_types=1);
// Disposable fixture router only: substitutes the core connection/context seams, never shipped.
if(getenv('WSL_DISTRO_NAME')!=='DwemerAI4Skyrim3-test') {http_response_code(503);exit;}
$GLOBALS['ENGINE_PATH']=getenv('CORRESPONDENCE_HTTP_ENGINE');
function ptp_connect():mixed {
    $db=getenv('CORRESPONDENCE_HTTP_DB')?:'chim_correspondence_it';
    if(!in_array($db,['chim_correspondence_it','chim_correspondence_http_it'],true))throw new RuntimeException('fixture_database_guard');
    $c=pg_connect('host=/var/run/postgresql dbname='.$db.' user=postgres');
    $r=pg_fetch_assoc(pg_query($c,"SELECT current_database() db,current_setting('listen_addresses') listeners"));
    if($r!==['db'=>$db,'listeners'=>''])throw new RuntimeException('fixture_guard');
    return $c;
}
$base=$GLOBALS['ENGINE_PATH'].'/ext/correspondence';
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/index.php'){require $base.'/index.php';exit;}
if(!str_starts_with($path,'/fixture/')){http_response_code(404);exit;}
require_once $base.'/lib/bootstrap.php';
$runtime=correspondenceProduction();$store=$runtime['store'];$service=$runtime['service'];
header('Content-Type: application/json');
if($path==='/fixture/presence'){
    $raw=base64_encode(correspondenceJson(['source'=>'plugin_player_routing_v2','present_actors'=>[['form_id'=>hexdec('000A2C94'),'name'=>'Lydia','distance'=>10,'managed'=>true,'creature'=>false]]]));
    $gameRequest=['inputtext',100,100,'synthetic fixture',$raw];require $base.'/preprocessing.php';echo '{"ok":true}';exit;
}
if($path==='/fixture/summary'){
    echo correspondenceJson(['documents'=>$service->list(),'sources'=>$store->query("SELECT rowid,data,people FROM public.eventlog WHERE type='info' AND sess='written_evidence_v1'"),'commands'=>$store->query('SELECT actor,concat(action,chr(124),text) AS action FROM public.responselog'),'books'=>$store->query('SELECT title,content FROM public.books')]);exit;
}
if($path==='/fixture/receipt'){
    $id=$_GET['id']??'';$doc=$store->loadDocument($id);$op=$store->loadOperation($doc['operation_id']);
    $receipt=array_intersect_key($op,array_flip(['protocol_version','document_id','operation_id','receipt_id','playthrough_id','scope_token','nonce']));
    $receipt+=['status'=>'delivered','reason'=>'physical_only','note_form_id'=>12345,'attachments'=>[]];
    foreach($op['attachments'] as $item)$receipt['attachments'][]=['form_id'=>$item['form_id'],'quantity'=>$item['quantity'],'before_source'=>100,'after_source'=>100-$item['quantity'],'before_recipient'=>0,'after_recipient'=>$item['quantity']];
    $gameRequest=['correspondence_receipt',100,100,correspondenceJson($receipt)];require $base.'/preprocessing.php';exit;
}
if($path==='/fixture/spoof'){$gameRequest=['written_evidence',100,100,'forged'];require $base.'/preprocessing.php';exit;}
if($path==='/fixture/context'){
    function chimRegisterPromptInjection(string $slot,string $id,callable $callback,int $priority):void{$GLOBALS['fixture_callback']=$callback;}
    require $base.'/context_pre.php';$text=($GLOBALS['fixture_callback'])('character_bottom',['herika_name'=>$_GET['owner']??'','narrator_name'=>'The Narrator','game_request'=>['inputtext','100','100','Dragonborn: fixture context']]);
    echo correspondenceJson(['context'=>$text,'social'=>$GLOBALS['CORRESPONDENCE_SOCIAL_STATUS']??[]]);exit;
}
http_response_code(404);
