<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/server/lib/view.php';
function accessHelpCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
accessHelpCheck(function_exists('correspondenceAccessDeniedPage'),'refusal must provide an actionable HTML page');
$request=['REQUEST_METHOD'=>'GET','REMOTE_ADDR'=>'172.17.224.1','HTTP_HOST'=>'172.17.226.57:8081','SERVER_PORT'=>'8081','SCRIPT_NAME'=>'/HerikaServer/ext/correspondence/index.php'];
$html=correspondenceAccessDeniedPage($request);
accessHelpCheck(!correspondenceAccess($request),'help must not authorize the refused network request');
accessHelpCheck(str_contains($html,'href="http://localhost:8081/HerikaServer/ui/server_plugins.php"'),'private-address refusal must link to CHIM Plugins through localhost, retaining port and installation path');
accessHelpCheck(str_contains($html,'Sealed Fates')&&str_contains($html,'Plugin Page')&&str_contains($html,'computer running CHIM')&&str_contains($html,'another computer'),'help must explain the button and distinguish local from remote use');
accessHelpCheck(str_contains($html,'href="assets/style.css"')&&str_contains($html,'role="alert"')&&!str_contains($html,'http-equiv=')&&!str_contains($html,'<script')&&!str_contains($html,'<form'),'help must use the styled error shell without redirects, scripts or resend controls');
foreach([
    ['HTTP_HOST'=>'192.168.1.10','SCRIPT_NAME'=>'/ext/correspondence/index.php','expected'=>'http://localhost/ui/server_plugins.php'],
    ['HTTP_HOST'=>'10.0.0.1:9090','SCRIPT_NAME'=>'/nested/chim/ext/correspondence/index.php','expected'=>'http://localhost:9090/nested/chim/ui/server_plugins.php'],
    ['HTTP_HOST'=>'localhost:8081','expected'=>'http://localhost:8081/HerikaServer/ui/server_plugins.php'],
    ['HTTP_HOST'=>'[::1]:8081','expected'=>'http://localhost:8081/HerikaServer/ui/server_plugins.php'],
] as $case){
    $expected=$case['expected'];unset($case['expected']);
    accessHelpCheck(str_contains(correspondenceAccessDeniedPage(array_replace($request,$case)),'href="'.$expected.'"'),'valid local route must preserve its installation path and browser port');
}
foreach([
    ['HTTP_HOST'=>'203.0.113.10:8081'],['HTTP_HOST'=>'operator.example:8081'],
    ['HTTP_HOST'=>'172.17.226.57:65536'],['HTTP_HOST'=>'172.17.226.57:0'],
    ['HTTP_HOST'=>'172.17.226.57:8081@evil.example'],['HTTP_HOST'=>['172.17.226.57']],
    ['HTTP_X_FORWARDED_FOR'=>'127.0.0.1'],['HTTP_FORWARDED'=>'for=127.0.0.1'],['HTTP_X_REAL_IP'=>'127.0.0.1'],
    ['HTTPS'=>'on'],['HTTPS'=>['off']],['SCRIPT_NAME'=>'//evil.example/ext/correspondence/index.php'],
    ['SCRIPT_NAME'=>'/../ext/correspondence/index.php'],['SCRIPT_NAME'=>'/bad"><script>alert(1)</script>/ext/correspondence/index.php'],
    ['SCRIPT_NAME'=>'/HerikaServer/ext/correspondence/index.php?token=secret'],['SCRIPT_NAME'=>[]],
] as $case){
    $unsafe=correspondenceAccessDeniedPage(array_replace($request,$case));
    accessHelpCheck(!str_contains($unsafe,'href="http://localhost')&&!str_contains($unsafe,'href="https://localhost'),'unverified topology must not receive a guessed localhost link');
    accessHelpCheck(str_contains($unsafe,'authenticated')&&!str_contains($unsafe,'<script')&&!str_contains($unsafe,'secret'),'fallback must explain authenticated access without reflecting request data');
}
$post=correspondenceAccessDeniedPage(array_replace($request,['REQUEST_METHOD'=>'POST']));
accessHelpCheck(!str_contains($post,'<form')&&str_contains($post,'does not resend'),'refused POST must not replay the letter');
$index=file_get_contents(dirname(__DIR__).'/server/index.php');
accessHelpCheck(is_string($index)&&preg_match('/if\(!correspondenceAccess\(\$_SERVER\)\)\{http_response_code\(403\);header\(\'Content-Type: text\/html; charset=UTF-8\'\);echo correspondenceAccessDeniedPage\(\$_SERVER\);exit;\}/',$index)===1,'real access refusal must retain 403 and exit while rendering HTML help');
accessHelpCheck(strpos($index,'echo correspondenceAccessDeniedPage($_SERVER)')<strpos($index,'session_start(')&&strpos($index,'echo correspondenceAccessDeniedPage($_SERVER)')<strpos($index,'$runtime=correspondenceAutonomyProduction()'),'refusal must exit before session and production runtime initialization');
foreach(['GET','POST'] as $method){
    $probe='$_SERVER='.var_export(array_replace($request,['REQUEST_METHOD'=>$method]),true).';register_shutdown_function(static function(){fwrite(STDERR,json_encode(["status"=>http_response_code(),"session"=>session_status()]));});require $argv[1];';
    $process=proc_open([PHP_BINARY,'-n','-r',$probe,dirname(__DIR__).'/server/index.php'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    accessHelpCheck(is_resource($process),'local denied-route probe must start');
    $body=stream_get_contents($pipes[1]);$status=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
    accessHelpCheck($exit===0&&json_decode($status,true)===['status'=>403,'session'=>PHP_SESSION_NONE],'actual refused route must exit with 403 before starting a session or production runtime');
    accessHelpCheck(str_contains($body,'href="http://localhost:8081/HerikaServer/ui/server_plugins.php"')&&!str_contains($body,'<form'),'actual refused route must render the safe navigation, never a resend form');
}
echo "PASS server access help: local CHIM navigation, untrusted topology, unchanged refusal and no resend\n";
