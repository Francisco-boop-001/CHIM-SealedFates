<?php
declare(strict_types=1);

/*
 * Upstream compatibility evidence: HerikaServer 3.4.2 tree d3945ecb65d4338a63978c81c3d9b09a936da585;
 * lib/core/llm_connector.class.php SHA-256 0171f1360b24f66ed94d94d725d3fa6d929637c0eadf8b344f98ed1b9df03b3b;
 * connector/openaijson.php SHA-256 978dcecfbdc1df0cf7bc68e2cde311e032f6955a16ebce707e57af4d8248853c.
 * connector/openrouterjson.php SHA-256 86665e122d6c426622e81c6f51bcf144804f960edc2dd6bf08530cc526e3c5c7;
 * lib/settings.php SHA-256 2669df28be3e546221ca5e2e0565b54b28ad06bec8e545b1e1565944a63dcd60.
 * The newer classifier is from tree 0ec590b42c8087860138bb4e9aab470049ff9a2b,
 * lib/core/llm_connector.class.php SHA-256 6041653dc6f2fa0e2560bc05a7b5ce3b72f5b14defa69189477bdb9c122ba5fa.
 */

$root = dirname(__DIR__);
require_once $root . '/server/lib/runtime.php';
require_once $root . '/server/lib/autonomy_host.php';

function legacyConnectorCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

if (function_exists('chimIsDecisionConnector')) {
    throw new RuntimeException('legacy fixture must run in an isolated process without the newer classifier');
}

function correspondenceEngineRoot(): string { return (string)($GLOBALS['legacyConnectorEngineRoot'] ?? ''); }
function chimIsGlobalLlmConnectorEnabled(string $field): bool
{
    $GLOBALS['legacyConnectorEnableFields'][] = $field;
    return ($GLOBALS['legacyConnectorEnabled'] ?? false) === true;
}

final class LegacyAutonomyDialogueFixture
{
    public function __construct(private string $driver) {}

    public function fast_request($contextData, $customParms, $callName = '')
    {
        $GLOBALS['legacyConnectorRequests'][] = [$contextData, $customParms, $callName];
        $GLOBALS['CONNECTOR'][$this->driver]['API_KEY'] = 'fixture-secret';
        $GLOBALS['HERIKA_PERS'] = 'fixture-persona';
        $GLOBALS['DEBUG_DATA'] = ['fixture' => 'driver mutation'];
        return '{"action":"none","recipient":null,"body":"","reason":"silent"}';
    }
}

final class LLMConnector
{
    public static array $row = [];
    public static array $events = [];
    public static bool $missingRequestApi = false;

    public function getById($id)
    {
        self::$events[] = ['getById', $id];
        return self::$row;
    }

    public function setOldGlobals($currentConnectorData)
    {
        if (!array_key_exists('api_badge_id', $currentConnectorData)) throw new RuntimeException('legacy connector row omitted api_badge_id');
        self::$events[] = ['setOldGlobals', $currentConnectorData['driver'] ?? null];
        $GLOBALS['CONNECTOR'][$currentConnectorData['driver']]['API_KEY'] = 'configured-secret';
        $GLOBALS['HERIKA_PERS'] = 'configured-persona';
    }

    public function getConnector($currentConnectorData)
    {
        self::$events[] = ['getConnector', $currentConnectorData['driver'] ?? null];
        $GLOBALS['PATCH_PROMPT_ENFORCE_ACTIONS'] = false;
        $GLOBALS['COMMAND_PROMPT_ENFORCE_ACTIONS'] = '';
        return self::$missingRequestApi ? new stdClass() : new LegacyAutonomyDialogueFixture($currentConnectorData['driver']);
    }
}

foreach ([
    'getById' => ['id'],
    'setOldGlobals' => ['currentConnectorData'],
    'getConnector' => ['currentConnectorData'],
] as $method => $parameterNames) {
    $reflection = new ReflectionMethod(LLMConnector::class, $method);
    $actualNames = array_map(static fn(ReflectionParameter $parameter): string => $parameter->getName(), $reflection->getParameters());
    legacyConnectorCheck($actualNames === $parameterNames && !$reflection->hasReturnType(), 'legacy manager fixture signature must match pinned source: ' . $method);
}
$fastRequestReflection = new ReflectionMethod(LegacyAutonomyDialogueFixture::class, 'fast_request');
$fastRequestNames = array_map(static fn(ReflectionParameter $parameter): string => $parameter->getName(), $fastRequestReflection->getParameters());
legacyConnectorCheck(
    $fastRequestNames === ['contextData', 'customParms', 'callName']
        && $fastRequestReflection->getNumberOfRequiredParameters() === 2
        && !$fastRequestReflection->hasReturnType(),
    'legacy dialogue fixture signature must match the 3.4.2 fast_request source'
);

function legacyConnectorSnapshot(): array
{
    $keys = ['CONNECTOR', 'HERIKA_PERS', 'PLAYER_NAME', 'PATCH_PROMPT_ENFORCE_ACTIONS', 'COMMAND_PROMPT_ENFORCE_ACTIONS', 'DEBUG_DATA'];
    $snapshot = [];
    foreach ($keys as $key) {
        $snapshot[$key] = array_key_exists($key, $GLOBALS)
            ? ['present' => true, 'value' => $GLOBALS[$key]]
            : ['present' => false];
    }
    return $snapshot;
}

function legacyConnectorSeed(string $driver = 'openaijson'): void
{
    $GLOBALS['CONNECTOR'] = ['native' => ['API_KEY' => 'preserve-me']];
    $GLOBALS['HERIKA_PERS'] = 'native-persona';
    $GLOBALS['PLAYER_NAME'] = 'native-player';
    $GLOBALS['PATCH_PROMPT_ENFORCE_ACTIONS'] = true;
    $GLOBALS['COMMAND_PROMPT_ENFORCE_ACTIONS'] = 'native-command-policy';
    $GLOBALS['DEBUG_DATA'] = ['native' => 'preserve-me'];
    $GLOBALS['legacyConnectorEnabled'] = true;
    $GLOBALS['legacyConnectorEnableFields'] = [];
    $GLOBALS['legacyConnectorRequests'] = [];
    $GLOBALS['CORE_CONNECTOR_BGL'] = 77;
    LLMConnector::$events = [];
    LLMConnector::$missingRequestApi = false;
    LLMConnector::$row = [
        'id' => 77,
        'driver' => $driver,
        'model' => 'fixture-dialogue-model',
        'url' => 'https://example.invalid/v1/chat/completions',
        'api_badge_id' => null,
    ];
}

function legacyConnectorFactory(): ?callable
{
    return correspondenceAutonomyPreserveNativeGlobals(static fn() => correspondenceAutonomyCoreModelFactory());
}

$engineRoot = rtrim(sys_get_temp_dir(), "\\/") . DIRECTORY_SEPARATOR . 'chim-autonomy-legacy-' . bin2hex(random_bytes(8));
$driverRoot = $engineRoot . DIRECTORY_SEPARATOR . 'connector';
if (!mkdir($driverRoot, 0700, true)
    || file_put_contents($driverRoot . DIRECTORY_SEPARATOR . 'openaijson.php', "<?php\n") === false
    || file_put_contents($driverRoot . DIRECTORY_SEPARATOR . 'openrouterjson.php', "<?php\n") === false) {
    throw new RuntimeException('could not prepare isolated legacy connector fixture');
}
$GLOBALS['legacyConnectorEngineRoot'] = $engineRoot;

try {
    foreach (['openaijson', 'openrouterjson'] as $driver) {
        legacyConnectorSeed($driver);
        $before = legacyConnectorSnapshot();
        $model = legacyConnectorFactory();
        legacyConnectorCheck(is_callable($model), 'known 3.4.2 ' . $driver . ' dialogue connector must pass the legacy contract');
        legacyConnectorCheck(
            !str_contains(var_export((new ReflectionFunction($model))->getStaticVariables(), true), 'configured-secret'),
            'preflighted model callback must not capture connector credentials'
        );
        legacyConnectorCheck(
            LLMConnector::$events === [['getById', 77], ['setOldGlobals', $driver], ['getConnector', $driver]],
            'legacy preflight must preserve getById then setOldGlobals then getConnector order'
        );
        legacyConnectorCheck($GLOBALS['legacyConnectorRequests'] === [], 'legacy preflight must not make a provider request');
        legacyConnectorCheck(legacyConnectorSnapshot() === $before, 'legacy preflight must restore core connector and character globals');

        $raw = correspondenceAutonomyPreserveNativeGlobals(static fn() => $model([['role' => 'user', 'content' => 'bounded payload']]));
        legacyConnectorCheck(is_string($raw) && $GLOBALS['legacyConnectorRequests'] === [[
            [['role' => 'user', 'content' => 'bounded payload']], ['MAX_TOKENS' => 4096], 'backgroundlife',
        ]], 'legacy callback must make one bounded dialogue request with the standard API');
        legacyConnectorCheck(
            LLMConnector::$events === [
                ['getById', 77], ['setOldGlobals', $driver], ['getConnector', $driver],
                ['setOldGlobals', $driver], ['getConnector', $driver],
            ],
            'legacy request must reapply setOldGlobals before reconstructing the connector'
        );
        legacyConnectorCheck(legacyConnectorSnapshot() === $before, 'legacy request callback must restore connector, character and debug globals');
    }

    foreach ([
        [['id' => 77, 'driver' => 'openrouterjson', 'model' => 'typesafe/jev-1', 'url' => 'https://example.invalid/v1/chat/completions'], 'decision-only driver'],
        [['id' => 77, 'driver' => 'openaijson', 'model' => 'fixture-dialogue-model', 'url' => 'https://example.invalid/v1/decisions'], 'decision-only endpoint'],
        [['id' => 77, 'driver' => 'openaijson', 'model' => 'typesafe/jev-1', 'url' => 'https://example.invalid/v1/chat/completions'], 'decision-only model marker'],
        [['id' => 77, 'driver' => 'openrouterjson', 'model' => 'fixture-dialogue-model', 'url' => 'https://example.invalid/v1/decisions'], 'openrouter decision-only endpoint'],
        [['id' => 77, 'driver' => 'openrouterjson', 'model' => '~typesafe/jev-1', 'url' => 'https://example.invalid/v1/chat/completions'], 'openrouter decision-only model marker'],
        [['id' => 77, 'driver' => 'unknownjson', 'model' => 'fixture-dialogue-model', 'url' => 'https://example.invalid/v1/chat/completions'], 'unknown driver'],
        [['id' => 77, 'driver' => '../openaijson', 'model' => 'fixture-dialogue-model', 'url' => 'https://example.invalid/v1/chat/completions'], 'malformed driver'],
        [['id' => 77, 'driver' => 'openaijson', 'model' => '', 'url' => 'https://example.invalid/v1/chat/completions'], 'empty model'],
        [['id' => 77, 'driver' => 'openaijson', 'model' => 42, 'url' => 'https://example.invalid/v1/chat/completions'], 'non-string model'],
        [['id' => 77, 'driver' => 'openaijson', 'model' => 'fixture model', 'url' => 'https://example.invalid/v1/chat/completions'], 'model whitespace'],
        [['id' => 77, 'driver' => 'openaijson', 'model' => "fixture\nmodel", 'url' => 'https://example.invalid/v1/chat/completions'], 'model control'],
        [['id' => 77, 'driver' => 'openaijson', 'model' => 'fixture-dialogue-model', 'url' => 'not-a-url'], 'malformed URL'],
        [['id' => 77, 'driver' => 'openaijson', 'model' => 'fixture-dialogue-model', 'url' => 'https://%zz/v1/chat/completions'], 'malformed URL escape'],
        [['id' => 77, 'driver' => 'openaijson', 'model' => 'fixture-dialogue-model', 'url' => 'https://bad host/v1/chat/completions'], 'URL whitespace'],
        [['id' => 77, 'driver' => 'openaijson', 'model' => 'fixture-dialogue-model', 'url' => "https://example.invalid/v1/chat\ncompletions"], 'URL control'],
        [['id' => 77, 'driver' => 'openaijson', 'model' => 'fixture-dialogue-model', 'url' => 'ftp://example.invalid/v1/chat/completions'], 'unsupported URL scheme'],
    ] as [$row, $reason]) {
        legacyConnectorSeed();
        LLMConnector::$row = $row;
        LLMConnector::$row['api_badge_id'] = null;
        legacyConnectorCheck(legacyConnectorFactory() === null, $reason . ' must fail closed without the newer classifier');
        legacyConnectorCheck($GLOBALS['legacyConnectorRequests'] === [], $reason . ' must not make a provider request');
        legacyConnectorCheck(count(LLMConnector::$events) === 1 && LLMConnector::$events[0] === ['getById', 77], $reason . ' must fail before credential setup');
    }

    legacyConnectorSeed();
    $GLOBALS['legacyConnectorEnabled'] = false;
    legacyConnectorCheck(legacyConnectorFactory() === null && LLMConnector::$events === [], 'disabled BGL must stop before connector lookup');

    legacyConnectorSeed();
    $GLOBALS['CORE_CONNECTOR_BGL'] = 'invalid';
    legacyConnectorCheck(legacyConnectorFactory() === null && LLMConnector::$events === [], 'invalid BGL ID must stop before connector lookup');

    legacyConnectorSeed();
    LLMConnector::$missingRequestApi = true;
    $beforeMissingApi = legacyConnectorSnapshot();
    legacyConnectorCheck(
        legacyConnectorFactory() === null && legacyConnectorSnapshot() === $beforeMissingApi,
        'missing fast_request API must remain unavailable and restore globals'
    );
    legacyConnectorCheck($GLOBALS['legacyConnectorRequests'] === [], 'missing request API must not make a provider request');
    legacyConnectorCheck(
        LLMConnector::$events === [['getById', 77], ['setOldGlobals', 'openaijson'], ['getConnector', 'openaijson']],
        'missing request API must be rejected after the validated non-secret connector setup'
    );
} finally {
    @unlink($driverRoot . DIRECTORY_SEPARATOR . 'openaijson.php');
    @unlink($driverRoot . DIRECTORY_SEPARATOR . 'openrouterjson.php');
    @rmdir($driverRoot);
    @rmdir($engineRoot);
}

echo "PASS server legacy autonomy connector: pinned 3.4.2 dialogue API, strict known-driver gating, decision/malformed refusal, preflight no-request, global restoration\n";
