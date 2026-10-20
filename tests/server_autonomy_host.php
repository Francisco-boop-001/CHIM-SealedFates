<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$hostPath = $root . '/server/lib/autonomy_host.php';
if (!is_file($hostPath)) {
    fwrite(STDERR, "RED: native correspondence autonomy host missing\n");
    exit(1);
}
require_once $root . '/server/lib/runtime.php';
require_once $hostPath;

function correspondenceEngineRoot(): string { return (string)($GLOBALS['autonomyHostEngineRoot'] ?? ''); }
function chimIsGlobalLlmConnectorEnabled(string $field): bool
{
    $GLOBALS['autonomyHostEnableFields'][] = $field;
    return ($GLOBALS['autonomyHostGlobalEnabled'] ?? false) === true;
}
function chimIsDecisionConnector($connector): bool
{
    $GLOBALS['autonomyHostDecisionRows'][] = $connector;
    return ($GLOBALS['autonomyHostDecisionOnly'] ?? false) === true;
}

final class AutonomyHostConfiguredConnectorFixture
{
    public function fast_request(array $messages, array $options, string $service): string
    {
        $GLOBALS['autonomyHostFastRequests'][] = [$messages, $options, $service];
        $GLOBALS['CONNECTOR']['fakeconnector']['API_KEY'] = 'test-secret';
        $GLOBALS['HERIKA_PERS'] = 'mutated persona';
        $GLOBALS['DEBUG_DATA']['full'] = ['messages' => $messages];
        $GLOBALS['FUNC_LIST'] = ['provider action'];
        $GLOBALS['responseTemplate'] = ['action' => 'provider action'];
        $GLOBALS['structuredOutputTemplate'] = ['provider' => true];
        $GLOBALS['grammar'] = 'provider grammar';
        $GLOBALS['PROMPT_ACTIONS_LIST'] = 'provider actions';
        $GLOBALS['FUNCTIONS_ARE_ENABLED'] = true;
        $GLOBALS['CHIM_JSON_RESPONSE_EXT_LOADED'] = true;
        return '{"action":"none","recipient":null,"body":"","reason":"No useful response yet."}';
    }
}

final class LLMConnector
{
    public static int $getByIdCalls = 0;
    public static int $setOldGlobalsCalls = 0;
    public static int $getConnectorCalls = 0;
    public function getById(int $id): array
    {
        self::$getByIdCalls++;
        if ($id !== 77) throw new RuntimeException('unexpected connector id');
        return ['id' => 77, 'driver' => 'fakeconnector', 'model' => 'fixture-dialogue-model', 'api_badge_id' => 9];
    }
    public function setOldGlobals(array $data): void
    {
        self::$setOldGlobalsCalls++;
        $GLOBALS['CONNECTOR']['fakeconnector']['API_KEY'] = 'test-secret';
        $GLOBALS['PATCH_PROMPT_ENFORCE_ACTIONS'] = false;
        $GLOBALS['COMMAND_PROMPT_ENFORCE_ACTIONS'] = '';
        $GLOBALS['PLAYER_NAME'] = 'mutated player';
    }
    public function getConnector(array $data): AutonomyHostConfiguredConnectorFixture
    {
        self::$getConnectorCalls++;
        $GLOBALS['PATCH_PROMPT_ENFORCE_ACTIONS'] = false;
        $GLOBALS['COMMAND_PROMPT_ENFORCE_ACTIONS'] = '';
        return new AutonomyHostConfiguredConnectorFixture();
    }
}

function autonomyHostCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

final class AutonomyHostClockFixture
{
    public ?array $clock = null;
    public function bindRequestClock(array $request): void { $this->clock = correspondenceRequestClock($request); }
}

final class AutonomyHostWorkerFixture
{
    public int $tickCalls = 0;
    public int $modelCalls = 0;
    public array $messages = [];
    public function __construct(private AutonomyHostClockFixture $store, private bool $enabled, private bool $dispatchDue = false) {}
    public function settings(): array { return ['enabled' => $this->enabled]; }
    public function tick(callable $requestModel): array
    {
        $this->tickCalls++;
        if ($this->store->clock !== ['ts' => '100', 'gamets' => '416667']) throw new RuntimeException('clock was not bound before tick');
        if ($this->dispatchDue) return ['status' => 'dispatched', 'reason' => 'dispatch_queued', 'model_calls' => 0, 'input_chars' => 0, 'output_chars' => 0, 'elapsed_ms' => 1];
        $this->messages = [['role' => 'system', 'content' => 'bounded test message']];
        try {
            $raw = $requestModel($this->messages);
            $this->modelCalls++;
            return ['status' => 'silent', 'reason' => 'no_response', 'model_calls' => 1, 'input_chars' => 23, 'output_chars' => strlen($raw), 'elapsed_ms' => 1];
        } catch (Throwable) {
            return ['status' => 'failed', 'reason' => 'model_unavailable', 'model_calls' => 1, 'input_chars' => 23, 'output_chars' => 0, 'elapsed_ms' => 1];
        }
    }
}

function autonomyHostProtectedGlobals(): array
{
    $keys = ['gameRequest', 'HERIKA_NAME', 'HERIKA_PERS', 'PLAYER_NAME', 'response', 'PCV_REQUEST_SCOPE', 'COMMAND_PROMPT', 'all_fast_commands', 'external_fast_commands', 'CHIM_CHAT_SHORTCUT_ROUTED', 'QUEST_COMMENT_SPEAKER', 'RECHAT_PREVIOUS_SPEAKER', 'CONNECTOR', 'PATCH_PROMPT_ENFORCE_ACTIONS', 'COMMAND_PROMPT_ENFORCE_ACTIONS', 'DEBUG_DATA', 'FUNC_LIST', 'responseTemplate', 'structuredOutputTemplate', 'grammar', 'PROMPT_ACTIONS_LIST', 'FUNCTIONS_ARE_ENABLED', 'CHIM_JSON_RESPONSE_EXT_LOADED'];
    $values = [];
    foreach ($keys as $key) $values[$key] = array_key_exists($key, $GLOBALS) ? ['present' => true, 'value' => $GLOBALS[$key]] : ['present' => false];
    return $values;
}

function autonomyHostSeedGlobals(array $request): void
{
    $GLOBALS['gameRequest'] = $request;
    $GLOBALS['HERIKA_NAME'] = 'Camilla';
    $GLOBALS['HERIKA_PERS'] = 'ordinary persona';
    $GLOBALS['PLAYER_NAME'] = 'ordinary player';
    $GLOBALS['response'] = 'original response';
    $GLOBALS['PCV_REQUEST_SCOPE'] = ['status' => 'active', 'route' => 'solo_reflection'];
    $GLOBALS['COMMAND_PROMPT'] = 'ordinary route prompt';
    $GLOBALS['all_fast_commands'] = ['Follow'];
    $GLOBALS['external_fast_commands'] = ['CHIM'];
    $GLOBALS['CHIM_CHAT_SHORTCUT_ROUTED'] = true;
    $GLOBALS['QUEST_COMMENT_SPEAKER'] = 'ordinary speaker';
    $GLOBALS['RECHAT_PREVIOUS_SPEAKER'] = 'ordinary previous speaker';
    $GLOBALS['CONNECTOR'] = ['openaijson' => ['API_KEY' => 'old-key', 'max_tokens' => 222]];
    $GLOBALS['PATCH_PROMPT_ENFORCE_ACTIONS'] = true;
    $GLOBALS['COMMAND_PROMPT_ENFORCE_ACTIONS'] = 'old-command-policy';
    $GLOBALS['DEBUG_DATA'] = ['native' => 'debug'];
    $GLOBALS['FUNC_LIST'] = ['native action'];
    $GLOBALS['responseTemplate'] = ['action' => 'native action'];
    $GLOBALS['structuredOutputTemplate'] = ['native' => true];
    $GLOBALS['grammar'] = 'native grammar';
    $GLOBALS['PROMPT_ACTIONS_LIST'] = 'native actions';
    $GLOBALS['FUNCTIONS_ARE_ENABLED'] = false;
    $GLOBALS['CHIM_JSON_RESPONSE_EXT_LOADED'] = false;
}

function autonomyHostCase(array $request, bool $enabled, bool $throwModel = false, bool $missingModel = false, bool $dispatchDue = false): array
{
    $store = new AutonomyHostClockFixture();
    $worker = new AutonomyHostWorkerFixture($store, $enabled, $dispatchDue);
    $runtimeCalls = 0;
    $factoryCalls = 0;
    $modelCalls = 0;
    $runtimeFactory = static function () use ($store, $worker, &$runtimeCalls): array {
        $runtimeCalls++;
        return ['store' => $store, 'autonomy' => $worker];
    };
    $modelFactory = static function () use (&$factoryCalls, &$modelCalls, $throwModel, $missingModel): ?callable {
        $factoryCalls++;
        if ($missingModel) return null;
        return static function (array $messages) use (&$modelCalls, $throwModel): string {
            $modelCalls++;
            autonomyHostCheck($messages === [['role' => 'system', 'content' => 'bounded test message']], 'tick must pass only its bounded model messages');
            $GLOBALS['CONNECTOR']['openaijson']['API_KEY'] = 'changed-secret';
            $GLOBALS['DEBUG_DATA']['full'] = ['messages' => $messages];
            $GLOBALS['FUNC_LIST'] = ['provider action'];
            $GLOBALS['responseTemplate'] = ['action' => 'provider action'];
            $GLOBALS['structuredOutputTemplate'] = ['provider' => true];
            $GLOBALS['grammar'] = 'provider grammar';
            $GLOBALS['PROMPT_ACTIONS_LIST'] = 'provider actions';
            $GLOBALS['FUNCTIONS_ARE_ENABLED'] = true;
            $GLOBALS['CHIM_JSON_RESPONSE_EXT_LOADED'] = true;
            $GLOBALS['PATCH_PROMPT_ENFORCE_ACTIONS'] = false;
            $GLOBALS['COMMAND_PROMPT_ENFORCE_ACTIONS'] = 'changed';
            $GLOBALS['gameRequest'] = ['info', '999', '999', 'spoof'];
            $GLOBALS['HERIKA_NAME'] = 'Narrator';
            $GLOBALS['response'] = 'changed response';
            $GLOBALS['PCV_REQUEST_SCOPE'] = ['status' => 'changed'];
            $GLOBALS['COMMAND_PROMPT'] = 'changed route';
            $GLOBALS['all_fast_commands'] = [];
            $GLOBALS['external_fast_commands'] = [];
            $GLOBALS['CHIM_CHAT_SHORTCUT_ROUTED'] = false;
            if ($throwModel) throw new RuntimeException('private provider error must not escape');
            return '{"action":"none","recipient":null,"body":"","reason":"No useful response yet."}';
        };
    };
    $result = correspondenceAutonomyRunNativePostrequest($request, $runtimeFactory, $modelFactory);
    return [$result, $store, $worker, $runtimeCalls, $factoryCalls, $modelCalls];
}

$normal = ['inputtext', '100', '416667', 'ordinary dialogue'];
foreach (['before', 'after'] as $siblingOrder) {
    autonomyHostSeedGlobals($normal);
    $before = autonomyHostProtectedGlobals();
    $siblings = [];
    $siblingHook = static function () use (&$siblings): void { $siblings[] = autonomyHostProtectedGlobals(); };
    $hostHook = static function () use ($normal): array { return autonomyHostCase($normal, true); };
    if ($siblingOrder === 'before') { $siblingHook(); $hostHook(); }
    else { $hostHook(); $siblingHook(); }
    autonomyHostCheck($siblings === [$before], 'sibling postrequest hook must see unchanged request, response, PCV route, and connector globals (' . $siblingOrder . ')');
    autonomyHostCheck(autonomyHostProtectedGlobals() === $before, 'native and routing globals must be restored after host callback (' . $siblingOrder . ')');
}

autonomyHostSeedGlobals($normal);
[$result, $store, $worker, $runtimeCalls, $factoryCalls, $modelCalls] = autonomyHostCase($normal, true);
autonomyHostCheck($store->clock === ['ts' => '100', 'gamets' => '416667'], 'the actual request clock must bind before decision work');
autonomyHostCheck($worker->tickCalls === 1 && $worker->modelCalls === 1 && $factoryCalls === 1 && $modelCalls === 1, 'one native request may perform at most one model call');

foreach ([
    ['info', '100', '416667', 'arbitrary info'],
    ['init', '100', '416667', 'init payload'],
    ['custom_request', '100', '416667', 'arbitrary payload'],
    ['INPUTTEXT', '100', '416667', 'noncanonical request type'],
    [' inputtext ', '100', '416667', 'padded request type'],
    ['inputtext', '0', '416667', 'bad timestamp'],
    ['inputtext', '100', 'not-gamets', 'bad game clock'],
] as $rejectedRequest) {
    [$ignored, , $rejectedWorker, $rejectedRuntimeCalls, $rejectedFactoryCalls] = autonomyHostCase($rejectedRequest, true);
    autonomyHostCheck($rejectedRuntimeCalls === 0 && $rejectedFactoryCalls === 0 && $rejectedWorker->tickCalls === 0, 'non-normal or invalid-clock requests must not reach runtime, connector, or tick');
}

autonomyHostSeedGlobals($normal);
[, , $disabledWorker, $disabledRuntimeCalls, $disabledFactoryCalls] = autonomyHostCase($normal, false);
autonomyHostCheck($disabledRuntimeCalls === 1 && $disabledWorker->tickCalls === 0 && $disabledFactoryCalls === 0, 'default-disabled/pause must stop generation before connector setup');

autonomyHostSeedGlobals($normal);
[$dispatchResult, , $dispatchWorker, , $dispatchFactoryCalls, $dispatchModelCalls] = autonomyHostCase($normal, true, false, false, true);
autonomyHostCheck(($dispatchResult['status'] ?? null) === 'dispatched' && $dispatchWorker->tickCalls === 1 && $dispatchFactoryCalls === 0 && $dispatchModelCalls === 0, 'due queued dispatch must reach tick without constructing a model');

$connectorConfig = ['driver' => 'openai'];
$enabled = static fn(string $field): bool => $field === 'CORE_CONNECTOR_BGL';
$notDecisionOnly = static fn(array $data): bool => false;
$decisionOnly = static fn(array $data): bool => true;
autonomyHostCheck(!correspondenceAutonomyCoreEnabledGate(null), 'missing global connector gate must fail closed');
autonomyHostCheck(!correspondenceAutonomyCoreEnabledGate(static fn(string $field): bool => false), 'disabled global BGL connector must fail closed');
autonomyHostCheck(correspondenceAutonomyCoreEnabledGate($enabled), 'enabled global BGL connector must pass the first configuration gate');
autonomyHostCheck(!correspondenceAutonomyCoreSupportsDialogue(null, $connectorConfig), 'missing decision connector classifier must fail closed');
autonomyHostCheck(!correspondenceAutonomyCoreSupportsDialogue($decisionOnly, $connectorConfig), 'decision-only connector must not be used for dialogue');
autonomyHostCheck(correspondenceAutonomyCoreSupportsDialogue($notDecisionOnly, $connectorConfig), 'ordinary connector must pass the dialogue-format gate');

$engineFixtureRoot = rtrim(sys_get_temp_dir(), "\\/") . DIRECTORY_SEPARATOR . 'chim-autonomy-connector-' . bin2hex(random_bytes(8));
$driverDirectory = $engineFixtureRoot . DIRECTORY_SEPARATOR . 'connector';
if (!mkdir($driverDirectory, 0700, true) || file_put_contents($driverDirectory . DIRECTORY_SEPARATOR . 'fakeconnector.php', "<?php\n") === false) {
    throw new RuntimeException('could not prepare isolated connector fixture');
}
$GLOBALS['autonomyHostEngineRoot'] = $engineFixtureRoot;
$GLOBALS['CORE_CONNECTOR_BGL'] = 77;
$GLOBALS['autonomyHostGlobalEnabled'] = false;
$GLOBALS['autonomyHostDecisionOnly'] = false;
$GLOBALS['autonomyHostEnableFields'] = [];
$GLOBALS['autonomyHostDecisionRows'] = [];
$GLOBALS['autonomyHostFastRequests'] = [];
LLMConnector::$getByIdCalls = 0;
LLMConnector::$setOldGlobalsCalls = 0;
LLMConnector::$getConnectorCalls = 0;
autonomyHostSeedGlobals($normal);
autonomyHostCheck(correspondenceAutonomyCoreModelFactory() === null, 'production model factory must skip when core BGL connector is disabled');
autonomyHostCheck($GLOBALS['autonomyHostEnableFields'] === ['CORE_CONNECTOR_BGL'] && LLMConnector::$getByIdCalls === 0 && LLMConnector::$setOldGlobalsCalls === 0, 'disabled production connector gate must stop before config lookup or credentials');

$GLOBALS['autonomyHostGlobalEnabled'] = true;
$GLOBALS['autonomyHostDecisionOnly'] = true;
autonomyHostCheck(correspondenceAutonomyCoreModelFactory() === null, 'production model factory must reject the configured decision-only model');
autonomyHostCheck(LLMConnector::$getByIdCalls === 1 && LLMConnector::$setOldGlobalsCalls === 0 && $GLOBALS['autonomyHostFastRequests'] === [], 'decision-only rejection must happen before credential setup or provider call');

$GLOBALS['autonomyHostDecisionOnly'] = false;
$configuredModel = correspondenceAutonomyCoreModelFactory();
autonomyHostCheck(is_callable($configuredModel) && LLMConnector::$getByIdCalls === 2 && LLMConnector::$setOldGlobalsCalls === 0, 'ordinary configured model factory must remain lazy before a tick requests output');
$beforeConfiguredCall = autonomyHostProtectedGlobals();
$configuredRaw = correspondenceAutonomyPreserveNativeGlobals(static fn() => $configuredModel([['role' => 'user', 'content' => 'bounded payload']]));
autonomyHostCheck(is_string($configuredRaw) && LLMConnector::$setOldGlobalsCalls === 1 && LLMConnector::$getConnectorCalls === 1, 'configured adapter must call the public core connector methods once on demand');
autonomyHostCheck($GLOBALS['autonomyHostFastRequests'] === [[[['role' => 'user', 'content' => 'bounded payload']], ['MAX_TOKENS' => 1024], 'backgroundlife']], 'configured adapter must use the standard bounded dialogue request');
autonomyHostCheck(autonomyHostProtectedGlobals() === $beforeConfiguredCall, 'configured adapter must restore connector and character/routing globals after the call');
@unlink($driverDirectory . DIRECTORY_SEPARATOR . 'fakeconnector.php');
@rmdir($driverDirectory);
@rmdir($engineFixtureRoot);

autonomyHostSeedGlobals($normal);
[$missingResult, , $missingWorker, , $missingFactoryCalls] = autonomyHostCase($normal, true, false, true);
autonomyHostCheck(($missingResult['status'] ?? null) === 'failed' && ($missingResult['reason'] ?? null) === 'model_unavailable'
    && $missingWorker->tickCalls === 1 && $missingFactoryCalls === 1, 'missing configured connector must fail the selected model job after tick');

foreach (['before', 'after'] as $siblingOrder) {
    autonomyHostSeedGlobals($normal);
    $before = autonomyHostProtectedGlobals();
    $siblings = [];
    $siblingHook = static function () use (&$siblings): void { $siblings[] = autonomyHostProtectedGlobals(); };
    $hostHook = static function () use ($normal): array { return autonomyHostCase($normal, true, true); };
    if ($siblingOrder === 'before') { $siblingHook(); $hostHook(); }
    else { $hostHook(); $siblingHook(); }
    autonomyHostCheck($siblings === [$before] && autonomyHostProtectedGlobals() === $before, 'thrown connector must not leak changes into sibling postrequest routing (' . $siblingOrder . ')');
}

foreach ([false, true] as $throwModel) {
    autonomyHostSeedGlobals($normal);
    foreach (['DEBUG_DATA', 'FUNC_LIST', 'responseTemplate', 'structuredOutputTemplate', 'grammar', 'PROMPT_ACTIONS_LIST', 'FUNCTIONS_ARE_ENABLED', 'CHIM_JSON_RESPONSE_EXT_LOADED'] as $key) unset($GLOBALS[$key]);
    $before = autonomyHostProtectedGlobals();
    autonomyHostCase($normal, true, $throwModel);
    autonomyHostCheck(autonomyHostProtectedGlobals() === $before, 'absent provider/core JSON globals must remain absent after ' . ($throwModel ? 'a thrown' : 'a successful') . ' call');
}

echo "PASS server autonomy host: native request gating, clock-before-tick, lazy model factory, paused/missing connector gate, sibling hook order\n";
