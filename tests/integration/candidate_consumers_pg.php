<?php
declare(strict_types=1);
// Three actual packages, official installer, same recipient row and normal MP/S&S public APIs.
function combinedCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function combinedRow(mixed $c,string $sql,array $args=[]):array{$r=pg_query_params($c,$sql,$args);combinedCheck($r!==false,'fixture_sql_failed');$row=pg_fetch_assoc($r);combinedCheck(is_array($row),'fixture_row_missing');return $row;}
function chimIsGlobalLlmConnectorEnabled(string $field):bool{return $field==='RELLLM_CONNECTOR'&&($GLOBALS['RELLLM_CONNECTOR']??null)===1;}
combinedCheck(PHP_SAPI==='cli'&&getenv('WSL_DISTRO_NAME')==='DwemerAI4Skyrim3-test','clone_cli_only');
foreach(getenv()as$name=>$_value)combinedCheck(!str_starts_with(strtoupper((string)$name),'PG'),'postgres_environment_forbidden');
$out='/mnt/k/ActorwrightExchange/artifacts/chim-correspondence-implementation-20261019';
$receiptPath=$out.'/final-test-set.json';combinedCheck(is_file($receiptPath),'final_candidate_set_not_ready');
$receipt=json_decode(file_get_contents($receiptPath),true,64,JSON_THROW_ON_ERROR);
$candidates=$receipt['candidates']??[];$names=array_keys($candidates);sort($names);combinedCheck($names===['correspondence','mind_poisoning','sworn_and_scorned'],'exact_three_candidates_required');
$c=pg_connect('host=/var/run/postgresql dbname=chim_correspondence_combined_it user=postgres connect_timeout=3',PGSQL_CONNECT_FORCE_NEW);
combinedCheck($c instanceof PgSql\Connection,'peer_connection_required');
$identity=combinedRow($c,"SELECT current_database() db,current_user role,current_setting('listen_addresses') listeners,(inet_server_addr() IS NULL)::int socket_only");
combinedCheck($identity===['db'=>'chim_correspondence_combined_it','role'=>'postgres','listeners'=>'','socket_only'=>'1'],'owned_socket_only_database_required');
combinedCheck(combinedRow($c,"SELECT to_regnamespace('sas_it') IS NOT NULL AS ready")['ready']==='t','real_sas_fixture_required');
require $out.'/core-extra/lib/plugin_package_manager.php';
$engine=$out.'/combined-final-'.bin2hex(random_bytes(5));
$manager=new DwemerPluginPackageManager($engine,null,function($target,$name,$paths)use($c){combinedCheck(pg_query($c,'BEGIN')!==false,'migration_begin');try{foreach($paths as$path)combinedCheck(pg_query($c,file_get_contents($path))!==false,'migration_failed');combinedCheck(pg_query($c,'COMMIT')!==false,'migration_commit');}catch(Throwable $e){pg_query($c,'ROLLBACK');throw $e;}});
$installed=[];
foreach($candidates as$name=>$candidate){
    $archive=$candidate['wsl_path']??'';$version=$candidate['version']??'';
    combinedCheck(is_string($archive)&&str_starts_with($archive,'/mnt/k/ActorwrightExchange/')&&is_file($archive)&&str_ends_with($archive,'.dwpkg'),'candidate_path_invalid');
    combinedCheck(is_string($version)&&preg_match('/\A[0-9A-Za-z][0-9A-Za-z._+-]{0,63}\z/D',$version)===1,'candidate_version_invalid');
    combinedCheck(hash_file('sha256',$archive)===($candidate['sha256']??null),'candidate_hash_differs');
    $job=$manager->installArchive($archive,basename($archive),$name,$version);combinedCheck($job['status']==='completed','official_install_failed');
    $zip=new ZipArchive();combinedCheck($zip->open($archive)===true,'archive_open_failed');$files=0;
    for($i=0;$i<$zip->numFiles;$i++){$path=$zip->getNameIndex($i);if(!str_starts_with($path,'server/'))continue;$target=$engine.'/ext/'.$name.'/'.substr($path,7);combinedCheck(is_file($target)&&hash_file('sha256',$target)===hash('sha256',$zip->getFromIndex($i)),'installed_source_differs');$files++;}$zip->close();
    combinedCheck(json_decode(file_get_contents($engine.'/ext/'.$name.'/manifest.json'),true)['version']===$version,'installed_version_differs');$installed[$name]=['version'=>$version,'sha256'=>$candidate['sha256'],'files'=>$files];
}
combinedCheck(pg_query($c,'SET search_path=sas_it,public')!==false,'owned_search_path_failed');putenv('SAS_TEST_SCHEMA=sas_it');
$core=$out.'/mp-core-stage';$GLOBALS['ENGINE_PATH']=$core.'/';$GLOBALS['RELLLM_CONNECTOR']=1;$GLOBALS['PLAYER_NAME']='Dragonborn';$GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA']=false;
$helpers=['logger.php'=>'63b9f6ff8d99335f6c92991120030f89038641435d5f39408721fde1fa988f54','relationship_manager.php'=>'4497443c9480c7d7ff5d5deabcec9ad5ab4cdec6f28f1fd044f89594d71e06ab','chim_interaction.php'=>'0f85286f47da7f02b8527ba9099e3d93452185399a9c0f66918072b877ccb6de','semaphore_manager.class.php'=>'7e4463adbd42ea4cbaf960ccd5ff55beb623646d17da9e1b063d1a130983b4a6'];
foreach($helpers as$file=>$hash){combinedCheck(hash_file('sha256',$core.'/lib/'.$file)===$hash,'pinned_helper_differs');require_once $core.'/lib/'.$file;}
Logger::setCustomLog($core.'/log/combined-final.log');
$source=combinedRow($c,"SELECT rowid,data FROM eventlog WHERE type='info' AND sess='written_evidence_v1' ORDER BY rowid LIMIT 1");$id=(int)$source['rowid'];$envelope=json_decode($source['data'],true,64,JSON_THROW_ON_ERROR);
$ownerBefore=combinedRow($c,'SELECT extended_data::text extended,plugin_extended_data::text plugins FROM core_npc_master WHERE id=900001');
$pluginsBefore=json_decode($ownerBefore['plugins'],true,64,JSON_THROW_ON_ERROR);combinedCheck(isset($pluginsBefore['sworn_and_scorned']['issues'][0])&&!isset($pluginsBefore['mind_poisoning']['written_receipts'][$envelope['document_id']]),'fresh_real_issue_and_no_mp_receipt_required');
$historyBefore=(int)combinedRow($c,'SELECT count(*) n FROM core_npc_master_history')['n'];$absentBefore=combinedRow($c,'SELECT extended_data::text extended,plugin_extended_data::text plugins FROM core_npc_master WHERE id=900002');
require $engine.'/ext/correspondence/lib/consumers.php';
$readonly=correspondenceConsumeReferences($engine,$c,[$id],true);
combinedCheck($readonly[$id]['sworn_and_scorned']['status']==='processed'&&$readonly[$id]['mind_poisoning']['status']==='pending','packaged_public_readback_failed:'.json_encode($readonly));
combinedCheck(combinedRow($c,'SELECT extended_data::text extended,plugin_extended_data::text plugins FROM core_npc_master WHERE id=900001')===$ownerBefore&&(int)combinedRow($c,'SELECT count(*) n FROM core_npc_master_history')['n']===$historyBefore,'readonly_status_wrote_state');
$offered=correspondenceConsumeReferences($engine,$c,[$id],false);
combinedCheck($offered[$id]['sworn_and_scorned']['status']==='processed'&&$offered[$id]['mind_poisoning']['status']==='pending','actual_public_hint_offer_failed');
$native=new ChimMindPoisoning\PostgresStoreDb(null,$c);$modelCalls=0;
$model=static function(array $messages)use(&$modelCalls,$envelope):string{$modelCalls++;$data=json_decode($messages[1]['content'],true,64,JSON_THROW_ON_ERROR)['untrusted_data'];combinedCheck($data['written_evidence']['body']===$envelope['body']&&array_keys($data['candidates'])===['npc:900002'],'packaged_model_source_or_subject_differs');return json_encode(['judgments'=>[['subject'=>'npc:900002','subject_mentioned'=>true,'delta'=>-2,'reason'=>'Recipient resents the attributed insult.','evidence'=>'you are a disgrace and I despise you.','type_change'=>['from'=>'neutral','to'=>'suspicious']]]],JSON_THROW_ON_ERROR);};
$processed=ChimMindPoisoning\drainWrittenEvidenceHints($native,$model);combinedCheck(($processed['status']??null)==='processed'&&$modelCalls===1,'packaged_normal_drain_commit_failed:'.json_encode($processed));
$ownerAfter=combinedRow($c,'SELECT extended_data::text extended,plugin_extended_data::text plugins FROM core_npc_master WHERE id=900001');$pluginsAfter=json_decode($ownerAfter['plugins'],true,64,JSON_THROW_ON_ERROR);$extendedAfter=json_decode($ownerAfter['extended'],true,64,JSON_THROW_ON_ERROR);
combinedCheck($pluginsAfter['sworn_and_scorned']===$pluginsBefore['sworn_and_scorned'],'real_sas_issue_changed_by_mp');
combinedCheck($extendedAfter['relationships']['Jarl Balgruuf']['aff']===-2&&$extendedAfter['relationships']['Jarl Balgruuf']['type']==='suspicious','packaged_real_opinion_not_persisted');
combinedCheck((int)combinedRow($c,'SELECT count(*) n FROM core_npc_master_history')['n']===$historyBefore+1,'normal_history_snapshot_missing');
$snapshot=combinedRow($c,'SELECT plugin_extended_data::text plugins FROM core_npc_master_history WHERE npc_id=900001 ORDER BY history_id DESC LIMIT 1');combinedCheck(json_decode($snapshot['plugins'],true,64,JSON_THROW_ON_ERROR)===$pluginsAfter,'latest_history_does_not_contain_both_namespaces');
combinedCheck(combinedRow($c,'SELECT extended_data::text extended,plugin_extended_data::text plugins FROM core_npc_master WHERE id=900002')===$absentBefore,'absent_sender_gained_state');
$after=correspondenceConsumeReferences($engine,$c,[$id],true);$replay=correspondenceConsumeReferences($engine,$c,[$id],false);$drainReplay=ChimMindPoisoning\drainWrittenEvidenceHints($native,$model);
combinedCheck($after[$id]['sworn_and_scorned']['status']==='processed'&&$after[$id]['mind_poisoning']['status']==='processed'&&$replay===$after&&$drainReplay===[]&&$modelCalls===1,'packaged_readback_or_replay_failed');
combinedCheck(combinedRow($c,'SELECT extended_data::text extended,plugin_extended_data::text plugins FROM core_npc_master WHERE id=900001')===$ownerAfter&&(int)combinedRow($c,'SELECT count(*) n FROM core_npc_master_history')['n']===$historyBefore+1,'replay_changed_state_or_history');
echo json_encode(['status'=>'PASS','kind'=>'three exact candidates installed by unchanged official manager; actual public correspondence/SAS/MP mapping; same recipient row, normal MP hint drain and native opinion/history preserve real SAS issue; deterministic model only','engine'=>$engine,'installed'=>$installed,'source_event_id'=>$id,'results'=>$after[$id],'model_calls'=>$modelCalls,'provider_calls'=>0,'same_recipient_namespaces_preserved'=>true,'replay_unchanged'=>true],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";