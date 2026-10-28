<?php
declare(strict_types=1);
require_once __DIR__.'/runtime.php';

function correspondenceAutonomyNativeRequest(array $request): bool
{
    $type = $request[0] ?? null;
    return is_string($type) && in_array($type, ['inputtext', 'inputtext_s', 'ginputtext', 'ginputtext_s'], true);
}

function correspondenceAutonomyHostResult(string $status, string $reason): array
{
    return ['status' => $status, 'reason' => $reason, 'model_calls' => 0, 'input_chars' => 0, 'output_chars' => 0, 'elapsed_ms' => 0];
}

function correspondenceAutonomyPreserveNativeGlobals(callable $work): mixed
{
    $keys = [
        'gameRequest', 'HERIKA_NAME', 'HERIKA_PERS', 'PLAYER_NAME', 'response', 'PCV_REQUEST_SCOPE',
        'COMMAND_PROMPT', 'all_fast_commands', 'external_fast_commands', 'CHIM_CHAT_SHORTCUT_ROUTED',
        'QUEST_COMMENT_SPEAKER', 'RECHAT_PREVIOUS_SPEAKER', 'CONNECTOR',
        'PATCH_PROMPT_ENFORCE_ACTIONS', 'COMMAND_PROMPT_ENFORCE_ACTIONS',
        'DEBUG_DATA', 'FUNC_LIST', 'responseTemplate', 'structuredOutputTemplate', 'grammar',
        'PROMPT_ACTIONS_LIST', 'FUNCTIONS_ARE_ENABLED', 'CHIM_JSON_RESPONSE_EXT_LOADED',
    ];
    $before = [];
    foreach ($keys as $key) {
        $before[$key] = array_key_exists($key, $GLOBALS)
            ? ['present' => true, 'value' => $GLOBALS[$key]]
            : ['present' => false];
    }
    try {
        return $work();
    } finally {
        foreach ($before as $key => $entry) {
            if ($entry['present']) $GLOBALS[$key] = $entry['value'];
            else unset($GLOBALS[$key]);
        }
    }
}

function correspondenceAutonomyCoreEnabledGate(?callable $globalGate): bool
{
    if ($globalGate === null) return false;
    try {
        return $globalGate('CORE_CONNECTOR_BGL') === true;
    } catch (Throwable) {
        return false;
    }
}

function correspondenceAutonomyCoreSupportsDialogue(?callable $decisionClassifier, array $connectorData): bool
{
    if ($decisionClassifier === null) return false;
    try {
        return $decisionClassifier($connectorData) === false;
    } catch (Throwable) {
        return false;
    }
}

// The legacy core lacks a classifier, so only source-verified dialogue drivers are allowed.
function correspondenceAutonomyCoreSupportsLegacyDialogueDrivers(array $connectorData): bool
{
    if (!in_array($connectorData['driver'] ?? null, ['openaijson', 'openrouterjson'], true)) return false;
    $model = $connectorData['model'] ?? null;
    $url = $connectorData['url'] ?? null;
    if (!is_string($model) || trim($model) === '' || preg_match('/[\s\x00-\x1F\x7F]/', $model) === 1
        || !is_string($url) || trim($url) === '') return false;

    $url = trim($url);
    if (filter_var($url, FILTER_VALIDATE_URL) === false) return false;
    $parts = parse_url($url);
    if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
        || !is_string($parts['host'] ?? null) || trim($parts['host']) === '') return false;

    $normalizedModel = strtolower(trim($model));
    $path = (string)($parts['path'] ?? '');
    return preg_match('#^~?typesafe/jev(-|$)#', $normalizedModel) !== 1
        && preg_match('#/decisions/?$#i', $path) !== 1;
}

/**
 * Run one bounded scheduler tick only from a native, ordinary dialogue hook.
 * Factories are injectable for static tests; browser code must never call this.
 */
function correspondenceAutonomyRunNativePostrequest(array $request, callable $runtimeFactory, callable $modelFactory): array
{
    if (!correspondenceAutonomyNativeRequest($request)) return correspondenceAutonomyHostResult('skipped', 'native_request_required');
    try {
        correspondenceRequestClock($request);
    } catch (Throwable) {
        return correspondenceAutonomyHostResult('skipped', 'native_clock_unavailable');
    }

    try {
        $runtime = $runtimeFactory();
        $store = is_array($runtime) ? ($runtime['store'] ?? null) : null;
        $autonomy = is_array($runtime) ? ($runtime['autonomy'] ?? null) : null;
        if (!is_object($store) || !method_exists($store, 'bindRequestClock') || !is_object($autonomy)
            || !method_exists($autonomy, 'settings') || !method_exists($autonomy, 'tick')) {
            return correspondenceAutonomyHostResult('failed', 'host_unavailable');
        }

        // Bind the exact native clock before any candidate lookup or decision work.
        $store->bindRequestClock($request);
        $settings = $autonomy->settings();
        if (!is_array($settings) || ($settings['enabled'] ?? false) !== true) {
            return correspondenceAutonomyHostResult('paused', 'disabled');
        }

        $model = null;
        try {
            $model = correspondenceAutonomyPreserveNativeGlobals(static fn() => $modelFactory());
        } catch (Throwable) {
            $model = null;
        }
        $requestModel = null;
        if (is_callable($model)) {
            $requestModel = static function (array $messages) use ($model): string {
                return correspondenceAutonomyPreserveNativeGlobals(static function () use ($model, $messages): string {
                    $raw = $model($messages);
                    if (!is_string($raw)) throw new DomainException('model_response_unavailable');
                    return $raw;
                });
            };
        }
        $result = $autonomy->tick($requestModel);
        return is_array($result) ? $result : correspondenceAutonomyHostResult('failed', 'host_unavailable');
    } catch (Throwable) {
        return correspondenceAutonomyHostResult('failed', 'host_unavailable');
    }
}

/** Return a preflighted request-model callback after a non-secret core configuration check. */
function correspondenceAutonomyCoreModelFactory(): ?callable
{
    try {
        if (!function_exists('correspondenceEngineRoot')) return null;
        $globalGate = function_exists('chimIsGlobalLlmConnectorEnabled')
            ? static fn(string $field): bool => chimIsGlobalLlmConnectorEnabled($field)
            : null;
        if (!correspondenceAutonomyCoreEnabledGate($globalGate)) return null;

        $connectorId = $GLOBALS['CORE_CONNECTOR_BGL'] ?? null;
        if (!is_int($connectorId) && !(is_string($connectorId) && preg_match('/\A[1-9][0-9]{0,8}\z/D', $connectorId) === 1)) return null;
        $connectorId = (int)$connectorId;
        if ($connectorId < 1) return null;

        $root = correspondenceEngineRoot();
        $managerFile = $root.'/lib/core/llm_connector.class.php';
        if (!class_exists('LLMConnector', false)) {
            if (!is_file($managerFile)) return null;
            require_once $managerFile;
        }
        if (!class_exists('LLMConnector', false)) return null;
        $decisionClassifier = function_exists('chimIsDecisionConnector')
            ? static fn(array $connector): bool => chimIsDecisionConnector($connector)
            : null;
        $manager = new LLMConnector();
        $data = $manager->getById($connectorId);
        if (!is_array($data)) return null;
        $supportsDialogue = $decisionClassifier !== null
            ? correspondenceAutonomyCoreSupportsDialogue($decisionClassifier, $data)
            : correspondenceAutonomyCoreSupportsLegacyDialogueDrivers($data);
        if (!$supportsDialogue
            || !is_string($data['driver'] ?? null)
            || preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,63}\z/D', $data['driver']) !== 1) return null;
        $driverFile = $root.'/connector/'.$data['driver'].'.php';
        if (!is_file($driverFile)) return null;

        $manager->setOldGlobals($data);
        $connector = $manager->getConnector($data);
        if (!is_object($connector) || !is_callable([$connector, 'fast_request'])) return null;

        return static function (array $messages) use ($manager, $data): string {
            $manager->setOldGlobals($data);
            $connector = $manager->getConnector($data);
            if (!is_object($connector) || !is_callable([$connector, 'fast_request'])) {
                throw new DomainException('model_unavailable');
            }
            $raw = $connector->fast_request($messages, ['MAX_TOKENS' => 4096], 'backgroundlife');
            if (!is_string($raw)) throw new DomainException('model_response_unavailable');
            return $raw;
        };
    } catch (Throwable) {
        return null;
    }
}

