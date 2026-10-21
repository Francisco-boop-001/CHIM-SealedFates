<?php
declare(strict_types=1);

final class Logger
{
    public static array $records = [];
    public static bool $throw = false;

    private static function write(string $level, string $message): void
    {
        if (self::$throw) throw new RuntimeException('logger fixture failure');
        self::$records[] = ['level'=>$level,'message'=>$message];
    }

    public static function info(string $message): void { self::write('info', $message); }
    public static function warn(string $message): void { self::write('warn', $message); }
    public static function error(string $message): void { self::write('error', $message); }
}

require_once dirname(__DIR__).'/server/lib/bootstrap.php';

function diagnosticLifecycleCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

correspondenceDiagnostic('offscreen_worker_result', [
    'status'=>'processed','reason'=>'replied','correlation_id'=>'worker-correlation-1','elapsed_ms'=>47,
    'model_calls'=>1,'source_ids'=>[731],
    'social_outcomes'=>['mind_poisoning'=>'abstained','sworn_and_scorned'=>'processed'],
    'body'=>'SECRET_BODY_71','prompt'=>'SECRET_PROMPT_72','model'=>'SECRET_MODEL_73','name'=>'SECRET_NAME_74','api_key'=>'SECRET_KEY_75',
]);
$first = Logger::$records[0] ?? [];
$firstMessage = $first['message'] ?? '';
$jsonStart = strpos($firstMessage, '{');
$record = $jsonStart === false ? null : json_decode(substr($firstMessage, $jsonStart), true);
diagnosticLifecycleCheck(($first['level'] ?? null) === 'info' && is_array($record), 'completed worker outcomes must use the existing logger with structured JSON at informational severity');
diagnosticLifecycleCheck(($record['schema'] ?? null) === 'correspondence.diagnostic.v1'
    && ($record['event'] ?? null) === 'offscreen_worker_result' && ($record['status'] ?? null) === 'processed'
    && ($record['reason'] ?? null) === 'replied' && ($record['correlation_id'] ?? null) === 'worker-correlation-1'
    && ($record['elapsed_ms'] ?? null) === 47 && ($record['model_calls'] ?? null) === 1
    && ($record['source_ids'] ?? null) === [731]
    && ($record['social_outcomes'] ?? null) === ['mind_poisoning'=>'abstained','sworn_and_scorned'=>'processed'],
    'diagnostic records must retain UTC lifecycle, correlation, timing, source, model-attempt and separate owner outcomes');
diagnosticLifecycleCheck(is_string($record['at_utc'] ?? null)
    && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $record['at_utc']) === 1,
    'diagnostic records must use an explicit UTC timestamp');
foreach (['SECRET_BODY_71','SECRET_PROMPT_72','SECRET_MODEL_73','SECRET_NAME_74','SECRET_KEY_75'] as $secret) {
    diagnosticLifecycleCheck(!str_contains($firstMessage, $secret), 'diagnostics must omit free text and secret-like fields');
}
foreach (['body','prompt','model','name','api_key'] as $key) diagnosticLifecycleCheck(!array_key_exists($key, $record), 'unknown diagnostic fields must be dropped');

correspondenceDiagnostic('offscreen_worker_result', ['status'=>'stale','reason'=>'scope_changed']);
$staleMessage = Logger::$records[1]['message'] ?? '';
$staleStart = strpos($staleMessage, '{');
$staleRecord = $staleStart === false ? null : json_decode(substr($staleMessage, $staleStart), true);
diagnosticLifecycleCheck((Logger::$records[1]['level'] ?? null) === 'info' && ($staleRecord['status'] ?? null) === 'stale',
    'expected stale outcomes must be informational');
correspondenceDiagnostic('offscreen_worker_result', ['status'=>'idle','reason'=>'no_pending_source']);
correspondenceDiagnostic('offscreen_worker_result', ['status'=>'paused','reason'=>'disabled']);
diagnosticLifecycleCheck(count(Logger::$records) === 2, 'idle and disabled outcomes must not append diagnostic spam');

correspondenceDiagnostic('offscreen_worker_result', [
    'status'=>'processed','reason'=>"replied\n[WARN] forged record",'correlation_id'=>"id\n[WARN] forged record",
    'elapsed_ms'=>"47\n[WARN] forged record",'source_ids'=>[731,"732\n[WARN] forged record"],
    'social_outcomes'=>['mind_poisoning'=>"processed\n[WARN] forged record"],
]);
$injected = Logger::$records[2]['message'] ?? '';
$injectedStart = strpos($injected, '{');
$injectedRecord = $injectedStart === false ? null : json_decode(substr($injected, $injectedStart), true);
diagnosticLifecycleCheck(is_array($injectedRecord) && !str_contains($injected, "\n[WARN] forged record")
    && !str_contains($injected, '[WARN] forged record'), 'untrusted diagnostic metadata must not inject log lines');

correspondenceDiagnostic('offscreen_worker_result', ['status'=>'failed','reason'=>'provider_timeout']);
diagnosticLifecycleCheck((Logger::$records[3]['level'] ?? null) === 'error', 'failed worker outcomes must use error severity when the existing logger provides it');

correspondenceDiagnostic('autonomy_outcome', [
    'status'=>'failed','reason'=>'storage_error','model_calls'=>1,'input_chars'=>4567,'output_chars'=>765,'elapsed_ms'=>91,
    'prompt'=>'SECRET_PROMPT_76','body'=>'SECRET_BODY_77','model'=>'SECRET_MODEL_78','model_reason'=>'SECRET_MODEL_REASON_79','api_key'=>'SECRET_KEY_80',
]);
$autonomyMessage = Logger::$records[4]['message'] ?? '';
$autonomyStart = strpos($autonomyMessage, '{');
$autonomyRecord = $autonomyStart === false ? null : json_decode(substr($autonomyMessage, $autonomyStart), true);
diagnosticLifecycleCheck((Logger::$records[4]['level'] ?? null) === 'error' && is_array($autonomyRecord)
    && ($autonomyRecord['event'] ?? null) === 'autonomy_outcome' && ($autonomyRecord['status'] ?? null) === 'failed'
    && ($autonomyRecord['reason'] ?? null) === 'storage_error' && ($autonomyRecord['model_calls'] ?? null) === 1
    && ($autonomyRecord['input_chars'] ?? null) === 4567 && ($autonomyRecord['output_chars'] ?? null) === 765
    && ($autonomyRecord['elapsed_ms'] ?? null) === 91,
    'autonomy outcomes must log allowlisted status, reason, attempted-call and character counts, and elapsed time');
foreach (['SECRET_PROMPT_76','SECRET_BODY_77','SECRET_MODEL_78','SECRET_MODEL_REASON_79','SECRET_KEY_80'] as $secret) {
    diagnosticLifecycleCheck(!str_contains($autonomyMessage, $secret), 'autonomy diagnostics must omit prompts, bodies, model reasons, model names, and secrets');
}
correspondenceDiagnostic('autonomy_outcome', ['status'=>'idle','reason'=>'cooldown']);
correspondenceDiagnostic('autonomy_outcome', ['status'=>'paused','reason'=>'disabled']);
diagnosticLifecycleCheck(count(Logger::$records) === 5, 'idle and paused autonomy outcomes must not append diagnostic spam');

foreach (['model_unavailable','actor_identity_changed','player_unavailable','source_not_due'] as $reason) {
    correspondenceDiagnostic('autonomy_outcome', ['status'=>'idle','reason'=>$reason]);
}
diagnosticLifecycleCheck(count(Logger::$records) === 9, 'actionable autonomy availability and source deferrals must remain visible');
foreach (array_values(['model_unavailable','actor_identity_changed','player_unavailable','source_not_due']) as $index=>$reason) {
    $recordStart = strpos(Logger::$records[5 + $index]['message'] ?? '', '{');
    $idleRecord = $recordStart === false ? null : json_decode(substr(Logger::$records[5 + $index]['message'], $recordStart), true);
    diagnosticLifecycleCheck((Logger::$records[5 + $index]['level'] ?? null) === 'warn' && ($idleRecord['status'] ?? null) === 'idle'
        && ($idleRecord['reason'] ?? null) === $reason, 'actionable idle outcomes must use warning severity with safe reason codes');
}
correspondenceDiagnostic('autonomy_outcome', ['status'=>'idle','reason'=>'no_eligible_source']);
correspondenceDiagnostic('autonomy_outcome', ['status'=>'idle','reason'=>'cooldown']);
diagnosticLifecycleCheck(count(Logger::$records) === 9, 'ordinary no-source and cooldown outcomes must remain quiet');

correspondenceDiagnostic('autonomy_outcome', ['status'=>'failed','reason'=>'SECRET_MODEL_REASON_81']);
$unsafeAutonomyMessage = Logger::$records[9]['message'] ?? '';
$unsafeAutonomyStart = strpos($unsafeAutonomyMessage, '{');
$unsafeAutonomyRecord = $unsafeAutonomyStart === false ? null : json_decode(substr($unsafeAutonomyMessage, $unsafeAutonomyStart), true);
diagnosticLifecycleCheck(is_array($unsafeAutonomyRecord) && !str_contains($unsafeAutonomyMessage, 'SECRET_MODEL_REASON_81')
    && ($unsafeAutonomyRecord['reason'] ?? null) !== 'SECRET_MODEL_REASON_81',
    'autonomy diagnostic reasons must be allowlisted instead of accepting arbitrary model text');

correspondenceDiagnostic('init_fence_unavailable');
$initStart = strpos(Logger::$records[10]['message'] ?? '', '{');
$initRecord = $initStart === false ? null : json_decode(substr(Logger::$records[10]['message'], $initStart), true);
diagnosticLifecycleCheck((Logger::$records[10]['level'] ?? null) === 'error'
    && ($initRecord['event'] ?? null) === 'diagnostic_failure' && ($initRecord['reason'] ?? null) === 'init_fence_unavailable',
    'ordinary init-fence failures must retain their existing error severity');

$claimId = '123e4567-e89b-12d3-a456-426614174000';
correspondenceDiagnostic('autonomy_outcome', [
    'status'=>'failed','reason'=>'storage_error','correlation_id'=>$claimId,'source_ids'=>[731],
    'claim_id'=>'SECRET_CLAIM_82','source_event_id'=>731,'source_data'=>'SECRET_SOURCE_83',
]);
$claimStart = strpos(Logger::$records[11]['message'] ?? '', '{');
$claimRecord = $claimStart === false ? null : json_decode(substr(Logger::$records[11]['message'], $claimStart), true);
diagnosticLifecycleCheck(is_array($claimRecord) && ($claimRecord['correlation_id'] ?? null) === $claimId
    && ($claimRecord['source_ids'] ?? null) === [731]
    && !array_key_exists('claim_id', $claimRecord) && !array_key_exists('source_event_id', $claimRecord)
    && !str_contains(Logger::$records[11]['message'] ?? '', 'SECRET_CLAIM_82')
    && !str_contains(Logger::$records[11]['message'] ?? '', 'SECRET_SOURCE_83'),
    'autonomy diagnostics must include only the allowlisted claim correlation and source event identifiers');

Logger::$throw = true;
correspondenceDiagnostic('runtime_unavailable');
Logger::$throw = false;

echo "PASS diagnostic lifecycle: structured bounded records, UTC/correlation/timing, privacy, injection rejection, and nonfatal logger errors\n";
