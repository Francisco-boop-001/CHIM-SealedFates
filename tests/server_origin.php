<?php
declare(strict_types=1);
function originCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
require_once dirname(__DIR__).'/server/lib/view.php';

foreach(['localhost','LOCALHOST:8080','127.0.0.1','127.0.0.1:65535','[::1]','[::1]:8000'] as $host)
    originCheck(correspondenceAccess(['REMOTE_ADDR'=>'127.0.0.1','HTTP_HOST'=>$host]),'allowed loopback authority rejected: '.$host);
foreach(['','attacker.example','localhost.evil','evil.localhost','localhost:0','localhost:65536','localhost:123456','localhost:abc','localhost:','localhost,attacker','localhost:8080,attacker:80','[::1','::1','localhost/path','LOCALHOST@evil'] as $host)
    originCheck(!correspondenceAccess(['REMOTE_ADDR'=>'127.0.0.1','HTTP_HOST'=>$host]),'untrusted loopback Host accepted: '.$host);
originCheck(!correspondenceAccess(['REMOTE_ADDR'=>'198.51.100.10','HTTP_HOST'=>'localhost']),'Host alone must not authenticate a remote connection');
originCheck(!correspondenceAccess(['REMOTE_ADDR'=>'127.0.0.1','HTTP_HOST'=>'localhost','HTTP_X_FORWARDED_FOR'=>'127.0.0.1']),'forwarded headers must not confer trust');
originCheck(correspondenceAccess(['REMOTE_ADDR'=>'198.51.100.10','REMOTE_USER'=>'operator']),'REMOTE_USER authentication must preserve supported remote access');

$http=['REQUEST_METHOD'=>'POST','HTTP_HOST'=>'localhost:8080','SERVER_PORT'=>'8080'];
originCheck(correspondencePostOriginAllowed($http+['HTTP_ORIGIN'=>'http://localhost:8080','HTTP_SEC_FETCH_SITE'=>'same-origin']),'same-origin POST rejected');
originCheck(correspondencePostOriginAllowed($http+['HTTP_ORIGIN'=>'http://LOCALHOST:8080']),'origin hostname comparison must ignore DNS case');
// Apache's canonical vhost port can differ from the browser-facing listener.
$canonical=['REQUEST_METHOD'=>'POST','HTTP_HOST'=>'localhost:8081','SERVER_PORT'=>'80','HTTP_ORIGIN'=>'http://localhost:8081','HTTP_SEC_FETCH_SITE'=>'same-origin'];
originCheck(correspondencePostOriginAllowed($canonical),'same-origin localhost POST must survive canonical port 80 on listener 8081');
originCheck(!correspondencePostOriginAllowed(array_replace($canonical,['HTTP_ORIGIN'=>'http://localhost'])),'canonical port must not authorize a different browser origin');
originCheck(!correspondencePostOriginAllowed(array_replace($canonical,['HTTP_ORIGIN'=>'http://localhost:8082'])),'different browser-facing port remains cross-origin');
originCheck(!correspondencePostOriginAllowed(array_replace($canonical,['HTTP_ORIGIN'=>'http://attacker.example:8081'])),'matching port must not authorize a foreign host');
originCheck(correspondencePostOriginAllowed(['REQUEST_METHOD'=>'POST','HTTP_HOST'=>'localhost','SERVER_PORT'=>'8081','HTTP_ORIGIN'=>'http://localhost']),'an omitted browser port uses the scheme default rather than the internal listener');
originCheck(correspondencePostOriginAllowed(['REQUEST_METHOD'=>'POST','HTTPS'=>'on','HTTP_HOST'=>'operator.example:8443','SERVER_PORT'=>'443','HTTP_ORIGIN'=>'https://operator.example:8443']),'authenticated HTTPS topology can have a different canonical port');
originCheck(!correspondencePostOriginAllowed(array_replace($canonical,['HTTP_ORIGIN'=>'https://localhost:8081'])),'canonical-port support must not trust a different scheme');
originCheck(correspondencePostOriginAllowed(['REQUEST_METHOD'=>'POST','HTTP_HOST'=>'localhost','SERVER_PORT'=>'80','HTTP_ORIGIN'=>'http://localhost']),'default HTTP origin rejected');
originCheck(correspondencePostOriginAllowed(['REQUEST_METHOD'=>'POST','HTTP_HOST'=>'localhost','SERVER_PORT'=>'80','HTTP_ORIGIN'=>'http://localhost:80']),'explicit default HTTP port rejected');
originCheck(correspondencePostOriginAllowed(['REQUEST_METHOD'=>'POST','HTTPS'=>'on','SERVER_PORT'=>'443','HTTP_HOST'=>'operator.example','REMOTE_USER'=>'operator','HTTP_ORIGIN'=>'https://operator.example']),'authenticated HTTPS operator origin rejected');
originCheck(correspondencePostOriginAllowed(['REQUEST_METHOD'=>'POST','HTTPS'=>'on','SERVER_PORT'=>'8443','HTTP_HOST'=>'operator.example:8443','REMOTE_USER'=>'operator','HTTP_ORIGIN'=>'https://operator.example:8443']),'authenticated HTTPS operator with custom port rejected');
originCheck(correspondencePostOriginAllowed(['REQUEST_METHOD'=>'POST','HTTPS'=>'on','SERVER_PORT'=>'443','HTTP_HOST'=>'operator_node.example','REMOTE_USER'=>'operator','HTTP_ORIGIN'=>'https://operator_node.example']),'authenticated operator hostname with an underscore rejected');
foreach(['null','http://attacker.example:8080','https://localhost:8080','http://localhost:8081','http://localhost:8080/','http://user@localhost:8080','http://localhost:99999','http://localhost:8080 http://attacker','http://localhost:8080,http://attacker','http://localhost:8080?x=1','localhost:8080'] as $origin)
    originCheck(!correspondencePostOriginAllowed($http+['HTTP_ORIGIN'=>$origin]),'invalid or foreign Origin accepted: '.$origin);
originCheck(!correspondencePostOriginAllowed($http+['HTTP_ORIGIN'=>'http://localhost:8080','HTTP_SEC_FETCH_SITE'=>'cross-site']),'cross-site Fetch Metadata accepted');
originCheck(correspondencePostOriginAllowed($http+['HTTP_SEC_FETCH_SITE'=>'same-site']),'same-site POST without Origin must remain CSRF-protected and compatible');
originCheck(!correspondencePostOriginAllowed($http+['HTTP_X_FORWARDED_PROTO'=>'https','HTTP_ORIGIN'=>'https://localhost:8080']),'forwarded proto must not set the trusted request scheme');

$index=file_get_contents(dirname(__DIR__).'/server/index.php');
originCheck(is_string($index)&&str_contains($index,"header('Referrer-Policy: same-origin')"),'same-origin forms must retain their browser Origin while external referrers remain suppressed');
originCheck(is_string($index)&&str_contains($index,'correspondencePostOriginAllowed($_SERVER)'),'operator POST route must apply origin validation');
originCheck(is_string($index)&&str_contains($index,'correspondencePost($_POST,$csrf,$choices)'),'origin validation must retain existing CSRF parsing');
echo "PASS server origin: loopback Host, authenticated operator, same-origin POST and Fetch Metadata\n";
