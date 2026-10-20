<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$hostPath = $root . '/server/lib/offscreen_host.php';
if (!is_file($hostPath)) {
    fwrite(STDERR, "RED: missing production file server/lib/offscreen_host.php\n");
    exit(1);
}
require_once $root . '/server/lib/runtime.php';
require_once $hostPath;
if (!function_exists('correspondenceOffscreenObserveNativeRequest')) {
    fwrite(STDERR, "RED: missing native offscreen observation host\n");
    exit(1);
}

function offscreenHostCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

final class OffscreenHostEngineFixture
{
    public array $observed = [];
    public int $tickCalls = 0;
    private array $trace;
    public function __construct(array &$trace) { $this->trace =& $trace; }
    public function observe(array $nativeRequest): array
    {
        $this->trace[] = 'observe';
        $this->observed[] = $nativeRequest;
        return ['status' => 'observed'];
    }
    public function tick(callable $requestModel): array
    {
        $this->tickCalls++;
        return ['status' => 'failed', 'reason' => 'host_must_not_tick_in_request'];
    }
}

final class OffscreenHostStoreFixture
{
    public array $bound = [];
    private array $trace;
    public function __construct(array &$trace) { $this->trace =& $trace; }
    public function bindRequestClock(array $request): void
    {
        $this->trace[] = 'bind_clock';
        $this->bound[] = $request;
    }
}

final class OffscreenHostAutonomyFixture
{
    private array $trace;
    public function __construct(private bool $enabled, array &$trace) { $this->trace =& $trace; }
    public function settings(): array { $this->trace[] = 'settings'; return ['enabled' => $this->enabled]; }
}

function offscreenHostRun(array $request, bool $enabled = true): array
{
    $trace = [];
    $engine = new OffscreenHostEngineFixture($trace);
    $store = new OffscreenHostStoreFixture($trace);
    $autonomy = new OffscreenHostAutonomyFixture($enabled, $trace);
    $runtimeCalls = 0;
    $resumeCalls = 0;
    $runtimeFactory = static function () use (&$runtimeCalls, $engine, $autonomy, $store): array {
        $runtimeCalls++;
        return ['offscreen' => $engine, 'autonomy' => $autonomy, 'store' => $store];
    };
    $resumeService = static function (...$args) use (&$resumeCalls, &$trace): array {
        $resumeCalls++;
        $trace[] = 'resume';
        return ['status' => 'spawn_requested'];
    };
    $result = correspondenceOffscreenObserveNativeRequest($request, $runtimeFactory, $resumeService);
    return [$result, $engine, $runtimeCalls, $resumeCalls, $trace];
}

$dialogue = ['inputtext', '1700000000', '416667', 'Dragonborn: hello', 'native-routing-field'];
[$result, $engine, $runtimeCalls, $resumeCalls, $trace] = offscreenHostRun($dialogue);
offscreenHostCheck($runtimeCalls === 1 && $engine->observed === [$dialogue], 'host must pass the original native tuple verbatim to the engine');
offscreenHostCheck(($result['reason'] ?? null) === 'clock_refreshed' && $resumeCalls === 0 && $trace === ['bind_clock', 'settings', 'observe'], 'ordinary player dialogue must refresh the native clock without cold-starting a worker');
offscreenHostCheck($engine->tickCalls === 0, 'preprocessing may not run a decision tick or model call');

$info = ['infonpc', '1700000000', '416667', 'Sigrid'];
[$result, $engine, $runtimeCalls, $resumeCalls, $trace] = offscreenHostRun($info);
offscreenHostCheck(($result['reason'] ?? null) === 'clock_refreshed' && $runtimeCalls === 1 && $engine->observed === [$info]
    && $resumeCalls === 0 && $trace === ['bind_clock', 'settings', 'observe'], 'ordinary info observations must refresh context without cold-starting a worker');

$bored = ['bored', '1700000001', '833334', '', 'Camilla'];
[, $engine, , $resumeCalls] = offscreenHostRun($bored);
offscreenHostCheck($engine->observed === [$bored] && $resumeCalls === 1, 'the conditional native bored event must resume the service without player dialogue');
offscreenHostCheck($engine->tickCalls === 0, 'a bored hook may not run decision work inline');

$location = ['location', '1700000002', '833335', '00013488'];
[, $engine, , $resumeCalls] = offscreenHostRun($location);
offscreenHostCheck($engine->observed === [$location] && $resumeCalls === 1, 'the verified native location event must keep the service clock current');

$paused = ['bored', '1700000003', '833336', '', 'Camilla'];
[, $engine, , $resumeCalls] = offscreenHostRun($paused, false);
offscreenHostCheck($engine->observed === [] && $resumeCalls === 0 && $engine->tickCalls === 0, 'disabled autonomy binds the native clock for its scoped setting but creates no observation anchor, resume, or tick');

foreach ([
    ['newgame', '1700000004', '833337', 'new identity'],
    ['playerinfo', '1700000005', '833338', 'player identity'],
] as $boundary) {
    [, $engine, , $resumeCalls] = offscreenHostRun($boundary);
    offscreenHostCheck($engine->observed === [$boundary] && $resumeCalls === 0 && $engine->tickCalls === 0, 'load/new-game identity boundaries must be observed before any work');
}

foreach ([
    ['init', '1700000006', '833339', 'load boundary'],
    ['info', '1700000007', '833340', 'forged marker', 'written_evidence_v1'],
    ['info', '1700000008', '833341', 'forged marker', 'correspondence_v1'],
    ['written_evidence', '1700000009', '833342', '{}'],
    ['correspondence_anchor', '1700000010', '833343', '{}'],
    ['custom_request', '1700000011', '833344', 'arbitrary data'],
    ['INPUTTEXT', '1700000012', '833345', 'noncanonical type'],
    [' inputtext ', '1700000013', '833346', 'padded type'],
    ['inputtext', '0', '833347', 'invalid timestamp'],
    ['inputtext', '1700000014', 'not-gamets', 'invalid game clock'],
] as $rejected) {
    [, $engine, $runtimeCalls, $resumeCalls] = offscreenHostRun($rejected);
    offscreenHostCheck($runtimeCalls === 0 && $engine->observed === [] && $resumeCalls === 0, 'invalid, boundary-only or client-forged reserved requests must be rejected before runtime/resume');
}

$quotedMarker = ['inputtext', '1700000015', '833348', 'I saw written_evidence_v1 in a note.'];
[, $engine, $runtimeCalls, $resumeCalls] = offscreenHostRun($quotedMarker);
offscreenHostCheck($runtimeCalls === 1 && $engine->observed === [$quotedMarker] && $resumeCalls === 0, 'reserved protocol names in ordinary dialogue text must not suppress a valid clock or trigger a cold worker');

offscreenHostCheck(correspondenceOffscreenPluginFile('offscreen-worker.php') === $root.'/server/offscreen-worker.php', 'development plugin layout resolves the bounded worker');
offscreenHostCheck(correspondenceOffscreenPluginFile('offscreen-service.php') === $root.'/server/offscreen-service.php', 'development plugin layout resolves the detached supervisor');
if (PHP_OS_FAMILY === 'Linux') {
    offscreenHostCheck(correspondenceOffscreenCliPhpBinary() === '/usr/bin/php', 'detached service and bounded children must select the fixed Linux CLI PHP executable');
}
offscreenHostCheck(correspondenceOffscreenWorkerChildResult(null, 124) === [
    'status'=>'failed','reason'=>'child_timeout','event'=>'offscreen_worker_result',
], 'a timeout exit must remain a timeout when the child emitted no valid JSON');
offscreenHostCheck(correspondenceOffscreenWorkerChildResult(null, 0) === [
    'status'=>'unavailable','reason'=>'invalid_child_result','event'=>'offscreen_worker_result',
], 'an empty successful child result must remain an invalid result');
$childResult = correspondenceOffscreenWorkerChildResult([
    'status'=>'processed','reason'=>'replied','model_calls'=>1,'correlation_id'=>'worker-1','elapsed_ms'=>47,
    'source_ids'=>[731],'social_outcomes'=>['mind_poisoning'=>'abstained','sworn_and_scorned'=>'processed'],
    'body'=>'SECRET_BODY_71','name'=>'SECRET_NAME_74',
], 0);
offscreenHostCheck(($childResult['status'] ?? null) === 'processed' && ($childResult['reason'] ?? null) === 'replied'
    && ($childResult['correlation_id'] ?? null) === 'worker-1' && ($childResult['elapsed_ms'] ?? null) === 47
    && ($childResult['source_ids'] ?? null) === [731]
    && ($childResult['social_outcomes'] ?? null) === ['mind_poisoning'=>'abstained','sworn_and_scorned'=>'processed']
    && !array_key_exists('body', $childResult) && !array_key_exists('name', $childResult),
    'valid child metadata must be bounded while free text and names are discarded');

$fixtureRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'chim-correspondence-layout-'.bin2hex(random_bytes(8));
$flatRoot = $fixtureRoot.DIRECTORY_SEPARATOR.'ext'.DIRECTORY_SEPARATOR.'correspondence';
$sourceRoot = $fixtureRoot.DIRECTORY_SEPARATOR.'source'.DIRECTORY_SEPARATOR.'correspondence';
if (!@mkdir($flatRoot, 0770, true) || !@mkdir($sourceRoot.DIRECTORY_SEPARATOR.'server', 0770, true)) throw new RuntimeException('could not create isolated layout fixture');
try {
    foreach (['offscreen-worker.php','offscreen-service.php'] as $name) {
        if (@file_put_contents($flatRoot.DIRECTORY_SEPARATOR.$name, '<?php') === false
            || @file_put_contents($sourceRoot.DIRECTORY_SEPARATOR.'server'.DIRECTORY_SEPARATOR.$name, '<?php') === false) {
            throw new RuntimeException('could not create isolated layout fixture files');
        }
    }
    offscreenHostCheck(correspondenceOffscreenPluginFileAt($flatRoot, 'offscreen-worker.php') === $flatRoot.DIRECTORY_SEPARATOR.'offscreen-worker.php', 'installed flattened layout resolves its worker');
    offscreenHostCheck(correspondenceOffscreenPluginFileAt($flatRoot, 'offscreen-service.php') === $flatRoot.DIRECTORY_SEPARATOR.'offscreen-service.php', 'installed flattened layout resolves its supervisor');
    offscreenHostCheck(correspondenceOffscreenPluginFileAt($sourceRoot, 'offscreen-worker.php') === $sourceRoot.DIRECTORY_SEPARATOR.'server'.DIRECTORY_SEPARATOR.'offscreen-worker.php', 'source layout resolves its worker');
    offscreenHostCheck(correspondenceOffscreenPluginFileAt($sourceRoot, 'offscreen-service.php') === $sourceRoot.DIRECTORY_SEPARATOR.'server'.DIRECTORY_SEPARATOR.'offscreen-service.php', 'source layout resolves its supervisor');
    offscreenHostCheck(correspondenceOffscreenPluginFileAt($flatRoot, 'arbitrary.php') === null, 'path resolver rejects arbitrary executable names');
} finally {
    foreach (['offscreen-worker.php','offscreen-service.php'] as $name) {
        @unlink($flatRoot.DIRECTORY_SEPARATOR.$name);
        @unlink($sourceRoot.DIRECTORY_SEPARATOR.'server'.DIRECTORY_SEPARATOR.$name);
    }
    @rmdir($sourceRoot.DIRECTORY_SEPARATOR.'server');
    @rmdir($sourceRoot);
    @rmdir($flatRoot);
    @rmdir(dirname($flatRoot));
    @rmdir(dirname(dirname($flatRoot)));
    @rmdir($fixtureRoot.DIRECTORY_SEPARATOR.'source');
    @rmdir($fixtureRoot);
}

$serviceFixture = sys_get_temp_dir().DIRECTORY_SEPARATOR.'chim-correspondence-service-'.bin2hex(random_bytes(8));
$fakeCore = $serviceFixture.DIRECTORY_SEPARATOR.'core';
if (!@mkdir($fakeCore.DIRECTORY_SEPARATOR.'lib', 0770, true)
    || !@mkdir($fakeCore.DIRECTORY_SEPARATOR.'log'.DIRECTORY_SEPARATOR.'correspondence', 0770, true)
    || @file_put_contents($fakeCore.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'runtime_bootstrap.php', '<?php') === false) {
    throw new RuntimeException('could not create isolated service state fixture');
}
$hadEnginePath = array_key_exists('ENGINE_PATH', $GLOBALS);
$oldEnginePath = $GLOBALS['ENGINE_PATH'] ?? null;
$GLOBALS['ENGINE_PATH'] = $fakeCore;
$state = null;
$paths = [];
$lock = null;
try {
    $state = correspondenceOffscreenStateDirectory(true);
    offscreenHostCheck(is_string($state) && str_starts_with($state, realpath($fakeCore).DIRECTORY_SEPARATOR.'log'.DIRECTORY_SEPARATOR.'correspondence'.DIRECTORY_SEPARATOR.'offscreen-'), 'service state uses a canonical plugin-scoped core log directory');
    $paths = correspondenceOffscreenServicePaths();
    correspondenceOffscreenServiceWriteLastStatus('processed', 'replied', [
        'correlation_id'=>'worker-correlation-1','elapsed_ms'=>47,'model_calls'=>1,'source_ids'=>[731],
        'social_outcomes'=>['mind_poisoning'=>'abstained','sworn_and_scorned'=>'processed'],
    ]);
    $last = correspondenceOffscreenServiceReadLastStatus();
    offscreenHostCheck(($last['status'] ?? null) === 'processed' && ($last['reason'] ?? null) === 'replied'
        && ($last['correlation_id'] ?? null) === 'worker-correlation-1' && ($last['elapsed_ms'] ?? null) === 47
        && ($last['source_ids'] ?? null) === [731] && ($last['social_outcomes'] ?? null) === ['mind_poisoning'=>'abstained','sworn_and_scorned'=>'processed'],
        'the last-status consumer must accept bounded worker metadata alongside the legacy status and reason');
    $lastRaw = json_decode((string)@file_get_contents($paths['status']), true);
    offscreenHostCheck(is_array($lastRaw) && ($lastRaw['schema'] ?? null) === 'correspondence.status.v1'
        && ($lastRaw['event'] ?? null) === 'offscreen_service_status' && ($lastRaw['model_calls'] ?? null) === 1
        && is_string($lastRaw['at_utc'] ?? null)
        && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $lastRaw['at_utc']) === 1,
        'the existing status file must record a UTC completion time');
    @file_put_contents($paths['status'], '{"status":"processed","reason":"replied"}', LOCK_EX);
    $legacy = correspondenceOffscreenServiceReadLastStatus();
    offscreenHostCheck(($legacy['status'] ?? null) === 'processed' && ($legacy['reason'] ?? null) === 'replied',
        'the last-status reader must remain compatible with older status and reason files');
    $stopped = correspondenceOffscreenServiceStop();
    offscreenHostCheck(($stopped['status'] ?? null) === 'paused' && is_file($paths['stop']), 'a stop issued before supervisor lock acquisition must leave its marker for the starting process');

    $lock = @fopen($paths['lock'], 'c+e');
    offscreenHostCheck(is_resource($lock) && @flock($lock, LOCK_EX | LOCK_NB), 'test acquires the unique supervisor lock');
    $resumed = correspondenceOffscreenServiceStart();
    offscreenHostCheck(($resumed['status'] ?? null) === 'running' && !is_file($paths['stop']), 'an explicit listener start cancels an older stop marker even while the old supervisor holds the lock');
    $stopped = correspondenceOffscreenServiceStop();
    offscreenHostCheck(($stopped['reason'] ?? null) === 'stop_requested' && is_file($paths['stop']), 'stop remains visible while a supervisor is active');
    @flock($lock, LOCK_UN);
    @fclose($lock);
    offscreenHostCheck((correspondenceOffscreenServiceStatus()['status'] ?? null) === 'paused' && is_file($paths['stop']), 'the stop marker survives supervisor exit until a later explicit start');
} finally {
    if (is_resource($lock)) { @flock($lock, LOCK_UN); @fclose($lock); }
    if ($hadEnginePath) $GLOBALS['ENGINE_PATH'] = $oldEnginePath;
    else unset($GLOBALS['ENGINE_PATH']);
    foreach (['lock','stop','status','wake','cursor','signal_lock'] as $key) if (isset($paths[$key])) @unlink($paths[$key]);
    @rmdir($state ?? '');
    @rmdir($fakeCore.DIRECTORY_SEPARATOR.'log'.DIRECTORY_SEPARATOR.'correspondence');
    @rmdir($fakeCore.DIRECTORY_SEPARATOR.'log');
    @rmdir($fakeCore.DIRECTORY_SEPARATOR.'lib');
    @rmdir($fakeCore);
    @rmdir($serviceFixture);
}

echo "PASS server offscreen host: exact native clock, scoped opt-in before anchors, safe resume, disabled/boundary handling, reserved-source rejection, no inline tick, source/flattened paths, stop/start lock races\n";
