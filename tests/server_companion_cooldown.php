<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/server/lib/runtime.php';

// Query-contract fixture only; PostgreSQL integration is a separate test2 check.
final class CompanionCooldownFixture
{
    public bool $tableExists=true;
    public ?string $token='11111111-1111-4111-8111-111111111111:current';
    public array $claims=[];
    public array $aggregateQueries=[];
    public function restoreGeneration(): ?string { return $this->token; }
    public function query(string $sql,array $args=[]): array
    {
        if(str_contains($sql,'to_regclass'))return [['companion'=>$this->tableExists?'plugins.correspondence_companion_decision':null]];
        if(!str_contains($sql,'MAX(claimed_gamets)')||!str_contains($sql,'WHERE playthrough_id=$1 AND epoch=$2::uuid'))throw new RuntimeException('scope/restore-filter contract missing');
        $this->aggregateQueries[]=[$sql,$args];
        $actorBound=preg_match('/AND actor_id=\$([0-9]+)/',$sql,$actor)===1;
        $timeBound=preg_match('/AND claimed_gamets<=\$([0-9]+)::numeric/',$sql,$time)===1;
        $latest=0;
        foreach($this->claims as $row){
            if($row['scope']!==$args[0]||$row['epoch']!==$args[1])continue;
            if($actorBound&&$row['actor']!==(int)$args[(int)$actor[1]-1])continue;
            if($timeBound&&$row['at']>(int)$args[(int)$time[1]-1])continue;
            $latest=max($latest,$row['at']);
        }
        return [['attempted_at'=>(string)$latest]];
    }
}

$checks=0;
function cooldownCheck(bool $ok,string $why): void
{
    global $checks;
    $checks++;
    if(!$ok)throw new RuntimeException($why);
}
$store=new CompanionCooldownFixture();
$now=35833334;
$scope='1';
$epoch=correspondenceCompanionEpoch($store->token);
$claim=static fn(int $at,int $actor=900001,string $playthrough='1',?string $generation=null): array=>[
    'at'=>$at,'actor'=>$actor,'scope'=>$playthrough,'epoch'=>$generation??$epoch,
];

$store->claims=[$claim($now-208333)];
$control=correspondenceCompanionCooldownUntil($store,$scope,null,$now);
cooldownCheck($control===$now+208334,'recent global claim must impose a cooldown');
$store->claims[]=$claim($now+250000,900002);
cooldownCheck(correspondenceCompanionCooldownUntil($store,$scope,null,$now)===$control,'future global claim must not erase an active cooldown');
[$sql,$args]=end($store->aggregateQueries);
cooldownCheck(str_contains($sql,'AND claimed_gamets<=$3::numeric')&&$args===[$scope,$epoch,(string)$now],'global upper bound must be parameterized before MAX');

$store->claims=[$claim($now-1250000),$claim($now+1250001)];
cooldownCheck(correspondenceCompanionCooldownUntil($store,$scope,900001,$now)===$now+1250000,'future actor claim must not erase the six-hour cooldown');
[$sql,$args]=end($store->aggregateQueries);
cooldownCheck(str_contains($sql,'AND actor_id=$3')&&str_contains($sql,'AND claimed_gamets<=$4::numeric')
    &&$args===[$scope,$epoch,'900001',(string)$now],'actor and time parameter positions must stay distinct');
$store->claims=[$claim($now-1250000),$claim($now,900002)];
cooldownCheck(correspondenceCompanionCooldownUntil($store,$scope,900001,$now)===$now+1250000,'another actor must not extend the actor cooldown');

$store->claims=[$claim($now-208333),$claim($now,900001,'another-playthrough'),$claim($now,900001,$scope,correspondenceCompanionEpoch('older-restore'))];
cooldownCheck(correspondenceCompanionCooldownUntil($store,$scope,null,$now)===$control,'other playthrough/restore claims must remain excluded');
$store->token='22222222-2222-4222-8222-222222222222:new';
cooldownCheck(correspondenceCompanionCooldownUntil($store,$scope,null,$now)===0,'restored generation must not inherit old claims');
$store->token=null;
$queries=count($store->aggregateQueries);
cooldownCheck(correspondenceCompanionCooldownUntil($store,$scope,null,$now)===PHP_INT_MAX
    &&count($store->aggregateQueries)===$queries,'unavailable restore authority must still fail closed');
$store->token='11111111-1111-4111-8111-111111111111:current';

$store->claims=[$claim($now+1)];
cooldownCheck(correspondenceCompanionCooldownUntil($store,$scope,null,$now)===0,'future-only claims retain the existing ignore policy');
$store->claims=[$claim($now)];
cooldownCheck(correspondenceCompanionCooldownUntil($store,$scope,null,$now)===$now+416667,'claim exactly at request time must count');
$store->claims=[$claim($now-5000000)];
cooldownCheck(correspondenceCompanionCooldownUntil($store,$scope,null,$now)<=$now,'expired claim must not block');
$store->claims=[];
cooldownCheck(correspondenceCompanionCooldownUntil($store,$scope,null,$now)===0,'no claims must not block');
$store->tableExists=false;
cooldownCheck(correspondenceCompanionCooldownUntil($store,$scope,null,$now)===0,'missing companion schema retains compatibility');
cooldownCheck(correspondenceCompanionCooldownUntil(new stdClass(),$scope,null,$now)===0,'non-PG stores retain compatibility');
echo "server_companion_cooldown: $checks checks passed (local query contract; no PostgreSQL claim)\n";
