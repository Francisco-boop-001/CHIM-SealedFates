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

Logger::$throw = true;
correspondenceDiagnostic('runtime_unavailable');
Logger::$throw = false;

echo "PASS diagnostic lifecycle: structured bounded records, UTC/correlation/timing, privacy, injection rejection, and nonfatal logger errors\n";
