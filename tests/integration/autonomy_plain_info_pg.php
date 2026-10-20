<?php
declare(strict_types=1);
function plainCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
plainCheck(PHP_SAPI==='cli'&&getenv('WSL_DISTRO_NAME')==='DwemerAI4Skyrim3-test','clone_cli_only');
foreach(getenv() as $name=>$_)plainCheck(!str_starts_with(strtoupper($name),'PG'),'postgres_environment_forbidden');
$c=pg_connect('host=/var/run/postgresql dbname=chim_correspondence_autonomy_load_it user=postgres',PGSQL_CONNECT_FORCE_NEW);
$r=pg_fetch_assoc(pg_query($c,"SELECT current_database() db,current_user role,current_setting('listen_addresses') listeners,(inet_server_addr() IS NULL)::int socket_only"));
plainCheck($r===['db'=>'chim_correspondence_autonomy_load_it','role'=>'postgres','listeners'=>'','socket_only'=>'1'],'owned_socket_only');
plainCheck(pg_fetch_assoc(pg_query($c,'SELECT count(*) n FROM plugins.correspondence_autonomy_decisions'))['n']==='100','completed_synthetic_load_required');
$clock=pg_fetch_assoc(pg_query($c,'SELECT max(ts) ts,max(gamets) gamets FROM public.eventlog'));
plainCheck(pg_query_params($c,"INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES($1,$2,'info','ordinary plain text, not JSON','','1','','','')",[$clock['ts'],$clock['gamets']])!==false,'ordinary_info_insert');
$p=dirname(__DIR__,2);require $p.'/server/lib/store.php';require $p.'/server/lib/autonomy_policy.php';require $p.'/server/lib/autonomy_store.php';
$s=new CorrespondencePgStore($c,fn($s)=>null,fn($t,$b)=>true);$s->bindRequestClock(['inputtext',(string)((int)$clock['ts']+1),(string)((int)$clock['gamets']+correspondenceAutonomyGamets(12))]);$a=new CorrespondenceAutonomyPg($s,new CorrespondenceService($s));$result=$a->tick(static function():string{throw new RuntimeException('must_not_invoke_model');});
plainCheck($result['status']==='idle'&&$result['reason']==='no_eligible_source'&&$result['model_calls']===0,'ordinary_info_must_not_break_scheduler:'.json_encode($result));
echo json_encode(['status'=>'PASS','ordinary_non_json_info_does_not_break_native_queries'=>true,'model_calls'=>0,'provider_calls'=>0],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";