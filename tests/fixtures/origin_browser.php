<?php
declare(strict_types=1);
// Standalone loopback fixture: load only pure view helpers, never production bootstrap.
require_once dirname(__DIR__,2).'/server/lib/view.php';
$index=file_get_contents(dirname(__DIR__,2).'/server/index.php');
if(!is_string($index)||preg_match("/header\('Referrer-Policy: ([a-z-]+)'\)/",$index,$policy)!==1)throw new RuntimeException('production_referrer_policy_missing');
header('Referrer-Policy: '.$policy[1]);
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'self'; form-action 'self'; frame-ancestors 'self'");
header('Cache-Control: no-store');
if(($_SERVER['REQUEST_METHOD']??'')==='GET'){
    header('Content-Type: text/html; charset=UTF-8');
    $outside='http://localhost:'.$_SERVER['SERVER_PORT'].'/external';
    echo '<!doctype html><html lang="en"><title>Isolated origin fixture</title><form method="post"><input name="csrf" value="fixture-token"><input name="action" value="autonomy"><input name="enabled" value="0"><button>Submit fixture</button></form><a href="'.$outside.'">External fixture</a></html>';
    exit;
}
// Mirror Apache canonical port mismatch without touching Apache or the real server.
$server=$_SERVER;$server['SERVER_PORT']='80';
$access=correspondenceAccess($server);$origin=correspondencePostOriginAllowed($server);
$csrf=false;
if($access&&$origin){try{correspondencePost($_POST,'fixture-token',[]);$csrf=true;}catch(DomainException|InvalidArgumentException){}}
http_response_code(!$access||!$origin?403:($csrf?200:400));
header('Content-Type: application/json');
echo json_encode(['accessAllowed'=>$access,'originAllowed'=>$origin,'csrfAllowed'=>$csrf,'origin'=>$server['HTTP_ORIGIN']??null,'fetchSite'=>$server['HTTP_SEC_FETCH_SITE']??null,'referer'=>$server['HTTP_REFERER']??null,'policy'=>$policy[1]],JSON_THROW_ON_ERROR);
