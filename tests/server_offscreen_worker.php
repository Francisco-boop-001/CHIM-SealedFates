<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$workerPath = $root.'/server/offscreen-worker.php';
if (!is_file($workerPath)) {
    fwrite(STDERR, "RED: missing production file server/offscreen-worker.php\n");
    exit(1);
}
require_once $workerPath;

function offscreenWorkerContractCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

offscreenWorkerContractCheck(correspondenceOffscreenWorkerElapsedMilliseconds(1_000_000, 48_000_000) === 47,
    'worker completion timing must use monotonic elapsed milliseconds');
offscreenWorkerContractCheck(correspondenceOffscreenWorkerElapsedMilliseconds(48_000_000, 1_000_000) === 0,
    'invalid monotonic timing order must remain bounded');

$calls = [];
$failed = correspondenceOffscreenWorkerFinalizeTick(
    ['status'=>'failed','reason'=>'invalid_decision','model_calls'=>1],
    [731],
    static function (array $sourceIds) use (&$calls): array {
        $calls[] = $sourceIds;
        return ['status'=>'unavailable','reason'=>'consumer_unavailable','sources'=>[]];
    },
);
offscreenWorkerContractCheck($calls === [[731]], 'a newly exposed source must run social consumers even when the mail decision failed');
offscreenWorkerContractCheck($failed === ['status'=>'failed','reason'=>'invalid_decision','model_calls'=>1,'source_ids'=>[731]], 'social consumer failure must preserve the committed mail failure status and reason while retaining its bounded source ID');

$calls = [];
$pending = correspondenceOffscreenWorkerFinalizeTick(
    ['status'=>'processed','reason'=>'replied','model_calls'=>1,'elapsed_ms'=>47],
    [732],
    static function (array $sourceIds) use (&$calls): array {
        $calls[] = $sourceIds;
        return ['status'=>'processed','reason'=>'social_attempted','sources'=>[['mind_poisoning'=>['status'=>'pending'],'sworn_and_scorned'=>['status'=>'abstained']]]];
    },
);
offscreenWorkerContractCheck($calls === [[732]], 'the bounded source ID must be passed to the public consumer adapter');
offscreenWorkerContractCheck($pending === ['status'=>'processed','reason'=>'social_consumer_pending','model_calls'=>1,'elapsed_ms'=>47,'source_ids'=>[732],
    'social_outcomes'=>['mind_poisoning'=>'pending','sworn_and_scorned'=>'abstained']], 'a committed mail must retain elapsed time and report separate bounded social outcomes');

$failedWithSocialSuccess = correspondenceOffscreenWorkerFinalizeTick(
    ['status'=>'failed','reason'=>'invalid_decision','model_calls'=>1,'elapsed_ms'=>51],
    [736],
    static fn(): array => ['status'=>'processed','sources'=>[['mind_poisoning'=>['status'=>'abstained'],'sworn_and_scorned'=>['status'=>'processed']]]],
);
offscreenWorkerContractCheck(($failedWithSocialSuccess['status'] ?? null) === 'failed'
    && ($failedWithSocialSuccess['reason'] ?? null) === 'invalid_decision'
    && ($failedWithSocialSuccess['social_outcomes'] ?? null) === ['mind_poisoning'=>'abstained','sworn_and_scorned'=>'processed'],
    'successful social consumers must not relabel a failed mail decision as a successful worker outcome');

$calls = [];
$unchanged = correspondenceOffscreenWorkerFinalizeTick(
    ['status'=>'failed','reason'=>'provider_timeout','model_calls'=>1],
    [],
    static function (array $sourceIds) use (&$calls): array { $calls[] = $sourceIds; return ['status'=>'processed']; },
);
offscreenWorkerContractCheck($calls === [] && $unchanged === ['status'=>'failed','reason'=>'provider_timeout','model_calls'=>1], 'a failure without a newly exposed source must not invoke consumers or change its outcome');

$calls = [];
$invalid = correspondenceOffscreenWorkerFinalizeTick(
    ['status'=>'failed','reason'=>'invalid_decision','model_calls'=>1],
    [733,734],
    static function (array $sourceIds) use (&$calls): array { $calls[] = $sourceIds; return ['status'=>'processed']; },
);
offscreenWorkerContractCheck($calls === [] && $invalid === ['status'=>'failed','reason'=>'invalid_decision','model_calls'=>1], 'invalid multi-source results must not start unbounded consumer work or hide the tick failure');

$clockNs = 36_000_000_000;
$deadlineNs = 100_000_000_000;
$wakeCalls = 0;
$lateWake = correspondenceOffscreenWakeSasWithinBudget(
    static fn(): int => correspondenceOffscreenRemainingBudgetSeconds($deadlineNs, static fn(): int => $clockNs),
    static function () use (&$wakeCalls): array { $wakeCalls++; return ['status'=>'spawn_requested','reason'=>'started']; },
);
offscreenWorkerContractCheck($wakeCalls === 0 && $lateWake === ['status'=>'pending','reason'=>'worker_deadline_budget'],
    'a SAS wake must be deferred when the injected monotonic deadline leaves fewer than 65 seconds');

$clockNs = 35_000_000_000;
$ampleWake = correspondenceOffscreenWakeSasWithinBudget(
    static fn(): int => correspondenceOffscreenRemainingBudgetSeconds($deadlineNs, static fn(): int => $clockNs),
    static function () use (&$wakeCalls): array { $wakeCalls++; return ['status'=>'spawn_requested','reason'=>'started']; },
);
offscreenWorkerContractCheck($wakeCalls === 1 && $ampleWake === ['status'=>'spawn_requested','reason'=>'started'],
    'a SAS wake may run once when the injected monotonic deadline leaves the 65-second bound plus safety');

$budgetPending = correspondenceOffscreenWorkerFinalizeTick(
    ['status'=>'processed','reason'=>'replied','model_calls'=>1],
    [735],
    static fn(): array => ['status'=>'processed','sources'=>[[
        'sworn_and_scorned'=>['status'=>'queued'],
        'sworn_and_scorned_wake'=>['status'=>'pending','reason'=>'worker_deadline_budget'],
    ]]],
);
offscreenWorkerContractCheck($budgetPending === ['status'=>'processed','reason'=>'social_consumer_budget','model_calls'=>1,
    'source_ids'=>[735],'social_outcomes'=>['sworn_and_scorned'=>'queued']],
    'a committed letter must stay processed while a late social wake reports budget-deferred pending work');

echo "PASS server offscreen worker: social consumers run for newly exposed failed decisions, preserve committed outcomes, enforce one-source bounds, and defer late SAS wakes\n";
