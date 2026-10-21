<?php
declare(strict_types=1);
final class Logger{
    public static array $rows=[];
    public static function info(string $message):void{self::$rows[]=['info',$message];}
    public static function warn(string $message):void{self::$rows[]=['warn',$message];}
    public static function error(string $message):void{self::$rows[]=['error',$message];}
}
require_once dirname(__DIR__).'/server/lib/bootstrap.php';
function intakeCheck(bool $ok,string $reason):void{if(!$ok)throw new RuntimeException($reason);}
intakeCheck(function_exists('correspondenceReceiveWithRetry'),'receipt_intake_needs_bounded_storage_recovery');
final class IntakeStore{
    public array $bound=[];
    public function bindRequestClock(array $request):void{$this->bound[]=$request;}
}
final class IntakeService{
    public int $calls=0;
    public function __construct(private string $failure,private int $failures=1){}
    public function receive(array $receipt):array{
        $this->calls++;
        if($this->calls<=$this->failures){
            if($this->failure==='identity')throw new DomainException('receipt_identity_mismatch');
            throw new RuntimeException('storage_failed');
        }
        return ['state'=>'delivered','operation_id'=>'synthetic_operation'];
    }
}
$request=['correspondence_receipt','300','150','synthetic'];
$receipt=['receipt_id'=>'11111111-1111-4111-8111-111111111111','nonce'=>'never log this'];
$store=new IntakeStore();$service=new IntakeService('storage');
$result=correspondenceReceiveWithRetry($request,$receipt,fn()=>['store'=>$store,'service'=>$service]);
intakeCheck($result['state']==='delivered'&&$service->calls===2&&$store->bound===[$request,$request],'single_storage_retry_must_rebind_same_native_clock');
intakeCheck(count(Logger::$rows)===2&&Logger::$rows[0][0]==='warn'&&Logger::$rows[1][0]==='info','retry_and_recovery_need_distinct_severity');
intakeCheck(!str_contains(json_encode(Logger::$rows),'never log this'),'retry_log_must_not_include_packet_secrets');
foreach(['identity'=>1,'storage'=>2] as $failure=>$calls){
    $store=new IntakeStore();$service=new IntakeService($failure,5);
    try{correspondenceReceiveWithRetry($request,$receipt,fn()=>['store'=>$store,'service'=>$service]);throw new LogicException('failure_swallowed');}
    catch(DomainException|RuntimeException $e){intakeCheck($service->calls===$calls,'only_storage_failure_may_retry_once');}
}
Logger::$rows=[];
$untrustedId=str_repeat('a',64);
try{correspondenceReceiveWithRetry($request,['receipt_id'=>$untrustedId],static function(){throw new RuntimeException('storage_failed');});throw new LogicException('factory_failure_swallowed');}
catch(RuntimeException $expected){intakeCheck($expected->getMessage()==='storage_failed','factory_storage_failure_must_stay_bounded');}
intakeCheck(count(Logger::$rows)===1&&!str_contains(json_encode(Logger::$rows),$untrustedId)&&!str_contains(json_encode(Logger::$rows),'correlation_id'),'unvalidated_receipt_id_must_not_enter_retry_log');

echo "PASS receipt intake: one bounded storage retry, native clock retained, identity failures final, safe retry/recovery logs\n";
