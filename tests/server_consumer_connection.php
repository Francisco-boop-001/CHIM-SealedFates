<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/server/lib/consumers.php';
if(extension_loaded('pgsql'))throw new RuntimeException('run this pure seam test without pgsql');
define('PGSQL_TRANSACTION_IDLE',0);define('PGSQL_TRANSACTION_INTRANS',2);
function pg_transaction_status(mixed $connection):int{if(!is_object($connection))throw new TypeError();return $connection->status;}
function connectionCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$connection=(object)['status'=>PGSQL_TRANSACTION_INTRANS];$calls=0;
$cap=['version'=>1,'input_type'=>'written_evidence','source_marker'=>'written_evidence_v1','source_event_type'=>'info','recipient_only'=>true,'max_body_codepoints'=>2000];
$request=function($id)use(&$calls){$calls++;return ['status'=>'processed','reason'=>'no_change'];};
$result=correspondenceCallConsumer(42,fn()=>$cap,$request,$connection);
connectionCheck($calls===0&&$result===['status'=>'unavailable','reason'=>'consumer_connection_busy'],'RED: consumer must refuse an inherited transaction');
$connection->status=PGSQL_TRANSACTION_IDLE;
$result=correspondenceCallConsumer(42,fn()=>$cap,function($id)use($connection){$connection->status=PGSQL_TRANSACTION_INTRANS;return ['status'=>'processed','reason'=>'no_change'];},$connection);
connectionCheck($result===['status'=>'unavailable','reason'=>'consumer_connection_busy'],'consumer cannot claim success with an open transaction');
$connection->status=PGSQL_TRANSACTION_IDLE;
$result=correspondenceCallConsumer(42,function()use($connection,$cap){$connection->status=PGSQL_TRANSACTION_INTRANS;return $cap;},$request,$connection);
connectionCheck($calls===0&&$result['reason']==='consumer_connection_busy','capability cannot leak a transaction into request');
$connection->status=PGSQL_TRANSACTION_IDLE;
connectionCheck(correspondenceCallConsumer(42,fn()=>$cap,$request,$connection)['status']==='processed'&&$calls===1,'idle call still works');
echo "PASS consumer connection: idle before/after capability and request, no commit or rollback\n";
