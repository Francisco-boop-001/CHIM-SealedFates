<?php
declare(strict_types=1);
function boundaryCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
boundaryCheck(is_file(dirname(__DIR__).'/server/lib/boundaries.php'),'RED: source-backed managed snapshot and renderer boundary missing');
require_once dirname(__DIR__).'/server/lib/boundaries.php';
$snapshot=base64_encode(json_encode(['source'=>'plugin_player_routing_v2','present_actors'=>[['form_id'=>80000,'name'=>'Lydia','distance'=>10,'managed'=>true,'creature'=>false]]],JSON_THROW_ON_ERROR));
$actors=[['id'=>10,'npc_name'=>'Lydia','refid'=>'00013880']];
boundaryCheck(correspondenceExecutorFromSnapshot($snapshot,$actors)==='Lydia','managed exact refid and unique name must match');
$actors[]=$actors[0];boundaryCheck(correspondenceExecutorFromSnapshot($snapshot,$actors)===null,'duplicate managed name must fail closed');
boundaryCheck(correspondenceExecutorFromSnapshot(base64_encode('{"source":"other","present_actors":[]}'),[])===null,'unknown source must fail');
$measure=fn(int $size,string $text)=>[0,-$size,0,0,strlen($text)*$size/2,0,0,0];
boundaryCheck(correspondenceNoteFits('A brief letter.',400,500,$measure),'short complete note should fit');
boundaryCheck(!correspondenceNoteFits(str_repeat('impossible',100),400,500,$measure),'overwide word must reject');
boundaryCheck(!correspondenceNoteFits(str_repeat("one\n",150),400,500,$measure),'overflowing paragraphs must reject');
require_once dirname(__DIR__).'/server/lib/runtime.php';
boundaryCheck(function_exists('correspondenceRequestClock'),'RED: validated native request clock boundary missing');
boundaryCheck(correspondenceRequestClock(['inputtext','200','210'])===['ts'=>'200','gamets'=>'210'],'native canonical clocks must be retained exactly');
foreach([['inputtext','2e2','200'],['inputtext',200.0,'200'],['inputtext','0200','200'],['inputtext','200','-1'],['inputtext','200'],['inputtext','9223372036854775808','200']]as$badClock){try{correspondenceRequestClock($badClock);throw new RuntimeException('invalid native clock accepted');}catch(InvalidArgumentException){}}
echo "PASS server boundaries: native managed/refid matching and complete renderer glyph bounds\n";

