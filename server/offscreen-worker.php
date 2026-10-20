<?php
declare(strict_types=1);

require_once __DIR__.'/lib/bootstrap.php';

function correspondenceOffscreenWorkerResult(string $status, string $reason, int $modelCalls = 0, array $metadata = []): array
{
    if (!in_array($status, ['processed','idle','paused','stale','failed','unavailable'], true)) $status = 'unavailable';
    if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $reason) !== 1) $reason = 'worker_unavailable';
    $result = ['status'=>$status,'reason'=>$reason,'model_calls'=>max(0,min(1,$modelCalls))];
    if (is_int($metadata['elapsed_ms'] ?? null) && $metadata['elapsed_ms'] >= 0 && $metadata['elapsed_ms'] <= 180000) $result['elapsed_ms'] = $metadata['elapsed_ms'];
    if (is_string($metadata['correlation_id'] ?? null) && preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $metadata['correlation_id']) === 1) $result['correlation_id'] = $metadata['correlation_id'];
    $sourceIds = $metadata['source_ids'] ?? null;
    if (is_array($sourceIds) && array_is_list($sourceIds) && count($sourceIds) === 1 && is_int($sourceIds[0]) && $sourceIds[0] > 0) $result['source_ids'] = $sourceIds;
    $outcomes = $metadata['social_outcomes'] ?? null;
    $consumerStatuses = ['invalid','pending','queued','paused','failed','abstained','processed','running','existing_worker','spawn_requested','tick_finished','child_exited','unavailable'];
    if (is_array($outcomes) && !array_is_list($outcomes)) {
        $safeOutcomes = [];
        foreach (['mind_poisoning','sworn_and_scorned'] as $owner) if (in_array($outcomes[$owner] ?? null, $consumerStatuses, true)) $safeOutcomes[$owner] = $outcomes[$owner];
        if ($safeOutcomes !== []) $result['social_outcomes'] = $safeOutcomes;
    }
    return $result;
}

function correspondenceOffscreenWorkerElapsedMilliseconds(int $startedNanoseconds, int $finishedNanoseconds): int
{
    return $finishedNanoseconds >= $startedNanoseconds
        ? intdiv($finishedNanoseconds - $startedNanoseconds, 1_000_000)
        : 0;
}

function correspondenceOffscreenWorkerFinalizeTick(array $tick, mixed $pendingSources, callable $consumeSources): array
{
    $status = $tick['status'] ?? null;
    $modelCalls = (int)($tick['model_calls'] ?? 0);
    $metadata = [];
    foreach (['elapsed_ms','correlation_id'] as $key) if (array_key_exists($key, $tick)) $metadata[$key] = $tick[$key];
    if (!in_array($status, ['processed','idle','paused','stale','failed'], true)) {
        return correspondenceOffscreenWorkerResult('failed', 'invalid_tick_result', $modelCalls, $metadata);
    }
    $reason = is_string($tick['reason'] ?? null) && preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $tick['reason']) === 1
        ? $tick['reason'] : 'unknown';
    $sourceIds = is_array($pendingSources) && array_is_list($pendingSources) && count($pendingSources) === 1
        && is_int($pendingSources[0]) && $pendingSources[0] > 0 ? $pendingSources : [];
    if ($sourceIds !== []) $metadata['source_ids'] = $sourceIds;
    $unchanged = static function () use ($status, $reason, $modelCalls, &$metadata): array {
        return correspondenceOffscreenWorkerResult($status, $reason, $modelCalls, $metadata);
    };

    if (!is_array($pendingSources) || !array_is_list($pendingSources) || count($pendingSources) > 1) {
        return $status === 'processed'
            ? correspondenceOffscreenWorkerResult('processed', 'social_consumer_pending', $modelCalls, $metadata)
            : $unchanged();
    }
    foreach ($pendingSources as $eventId) if (!is_int($eventId) || $eventId < 1) {
        return $status === 'processed'
            ? correspondenceOffscreenWorkerResult('processed', 'social_consumer_pending', $modelCalls, $metadata)
            : $unchanged();
    }
    if ($pendingSources === []) return $unchanged();

    try { $social = $consumeSources($pendingSources); }
    catch (Throwable) { $social = ['status'=>'unavailable']; }
    $source = is_array($social) && is_array($social['sources'][0] ?? null) ? $social['sources'][0] : null;
    $socialOutcomes = [];
    if ($source !== null) foreach (['mind_poisoning','sworn_and_scorned'] as $owner) {
        $consumerStatus = $source[$owner]['status'] ?? null;
        if (in_array($consumerStatus, ['invalid','pending','queued','paused','failed','abstained','processed','running','existing_worker','spawn_requested','tick_finished','child_exited','unavailable'], true)) {
            $socialOutcomes[$owner] = $consumerStatus;
        }
    }
    if ($socialOutcomes !== []) $metadata['social_outcomes'] = $socialOutcomes;

    // A recipient-context failure may still expose a valid written source.
    // Run its consumers, but never relabel the already committed tick result.
    if ($status !== 'processed') return $unchanged();
    if (!is_array($social) || ($social['status'] ?? null) === 'unavailable') {
        return correspondenceOffscreenWorkerResult('processed', 'social_consumer_pending', $modelCalls, $metadata);
    }
    if (($social['status'] ?? null) === 'stale') {
        return correspondenceOffscreenWorkerResult('processed', 'social_context_stale', $modelCalls, $metadata);
    }
    foreach (($social['sources'] ?? []) as $source) {
        if (!is_array($source)) continue;
        $budgetDeferred = ($source['sworn_and_scorned_wake']['reason'] ?? null) === 'worker_deadline_budget';
        foreach (['mind_poisoning','sworn_and_scorned'] as $consumer) {
            $consumerStatus = $source[$consumer]['status'] ?? null;
            if (in_array($consumerStatus, ['failed','unavailable','invalid','paused','pending','queued','running'], true)) {
                return correspondenceOffscreenWorkerResult('processed', $budgetDeferred ? 'social_consumer_budget' : 'social_consumer_pending', $modelCalls, $metadata);
            }
        }
    }
    return correspondenceOffscreenWorkerResult($status, $reason, $modelCalls, $metadata);
}

function correspondenceOffscreenRunOneWorkerTick(): array
{
    if (PHP_SAPI !== 'cli' || ($GLOBALS['argc'] ?? 0) !== 2 || ($GLOBALS['argv'][1] ?? null) !== '--once') {
        return correspondenceOffscreenWorkerResult('unavailable', 'cli_once_required');
    }
    $startedNanoseconds = hrtime(true);
    try { $correlationId = bin2hex(random_bytes(12)); }
    catch (Throwable) { $correlationId = substr(hash('sha256', getmypid().':'.$startedNanoseconds), 0, 24); }
    $finish = static function (array $result) use ($startedNanoseconds, $correlationId): array {
        $now = hrtime(true);
        $metadata = $result;
        $metadata['correlation_id'] = $correlationId;
        $metadata['elapsed_ms'] = is_int($now)
            ? correspondenceOffscreenWorkerElapsedMilliseconds($startedNanoseconds, $now) : 0;
        $result = correspondenceOffscreenWorkerResult(
            is_string($result['status'] ?? null) ? $result['status'] : 'unavailable',
            is_string($result['reason'] ?? null) ? $result['reason'] : 'worker_unavailable',
            (int)($result['model_calls'] ?? 0),
            $metadata,
        );
        if (!in_array($result['status'], ['idle','paused'], true)) correspondenceDiagnostic('offscreen_worker_result', $result);
        return $result;
    };
    $deadlineNanoseconds = is_int($startedNanoseconds) ? $startedNanoseconds + 145_000_000_000 : 0;
    $remainingBudget = static fn(): int => correspondenceOffscreenRemainingBudgetSeconds($deadlineNanoseconds);
    @ini_set('display_errors', '0');
    @ini_set('max_execution_time', '140');
    @set_time_limit(140);

    $runtime = null;
    $modelCalls = 0;
    try {
        $root = correspondenceEngineRoot();
        $bootstrap = $root.'/lib/runtime_bootstrap.php';
        if (!is_file($bootstrap)) return $finish(correspondenceOffscreenWorkerResult('unavailable', 'core_bootstrap_unavailable'));
        require_once $bootstrap;
        if (!function_exists('chimRuntimeBootstrap')) return $finish(correspondenceOffscreenWorkerResult('unavailable', 'core_bootstrap_unavailable'));
        chimRuntimeBootstrap($root, [
            'run_db_updates'=>false,
            'load_general_settings'=>true,
            'load_stt_connector'=>false,
            'load_itt_connector'=>false,
            'load_tts_connector'=>false,
        ]);

        $runtime = correspondenceOffscreenProduction();
        $engine = $runtime['offscreen'] ?? null;
        $autonomy = $runtime['autonomy'] ?? null;
        if (!is_object($engine) || !method_exists($engine, 'tick') || !method_exists($engine, 'pendingSources')
            || !is_object($autonomy) || !method_exists($autonomy, 'settings')) {
            return $finish(correspondenceOffscreenWorkerResult('unavailable', 'offscreen_runtime_unavailable'));
        }
        $settings = $autonomy->settings();
        if (!is_array($settings) || ($settings['enabled'] ?? false) !== true) {
            return $finish(correspondenceOffscreenWorkerResult('paused', 'disabled'));
        }

        $model = correspondenceAutonomyPreserveNativeGlobals(static fn() => correspondenceAutonomyCoreModelFactory());
        if (!is_callable($model)) return $finish(correspondenceOffscreenWorkerResult('unavailable', 'model_unavailable'));
        $guardedModel = static function (array $messages) use ($model, &$modelCalls): string {
            if ($modelCalls !== 0) throw new DomainException('model_call_limit');
            $modelCalls = 1;
            return correspondenceAutonomyPreserveNativeGlobals(static function () use ($model, $messages): string {
                $response = $model($messages);
                if (!is_string($response)) throw new DomainException('model_response_unavailable');
                return $response;
            });
        };

        $result = $engine->tick($guardedModel);
        if (!is_array($result) || !in_array($result['status'] ?? null, ['processed','idle','paused','stale','failed'], true)) {
            return $finish(correspondenceOffscreenWorkerResult('failed', 'invalid_tick_result', $modelCalls));
        }
        $status = $result['status'];
        $reason = is_string($result['reason'] ?? null) ? $result['reason'] : 'unknown';
        $tick = ['status'=>$status,'reason'=>$reason,'model_calls'=>$modelCalls];
        if (is_int($result['elapsed_ms'] ?? null)) $tick['elapsed_ms'] = $result['elapsed_ms'];
        try { $pendingSources = $engine->pendingSources(); }
        catch (Throwable) { $pendingSources = null; }
        return $finish(correspondenceOffscreenWorkerFinalizeTick($tick, $pendingSources,
            static function (array $eventIds) use ($root, $runtime, $engine, $remainingBudget): array {
                return correspondenceOffscreenConsumeNewSources($root, $runtime['connection'] ?? null, $engine, $eventIds, $remainingBudget);
            }));
    } catch (Throwable) {
        return $finish(correspondenceOffscreenWorkerResult('unavailable', 'worker_unavailable', $modelCalls));
    } finally {
        $connection = is_array($runtime) ? ($runtime['connection'] ?? null) : null;
        if (is_resource($connection) || (class_exists('PgSql\\Connection', false) && $connection instanceof \PgSql\Connection)) {
            try { pg_close($connection); } catch (Throwable) {}
        }
    }
}

$invokedFile = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
if ($invokedFile !== false && $invokedFile === realpath(__FILE__)) {
    if (PHP_SAPI !== 'cli') {
        echo "{\"status\":\"unavailable\",\"reason\":\"cli_required\",\"model_calls\":0}\n";
        exit(1);
    }
    $result = correspondenceOffscreenRunOneWorkerTick();
    echo json_encode($result, JSON_UNESCAPED_SLASHES)."\n";
    exit(in_array($result['status'], ['failed','unavailable'], true) ? 1 : 0);
}
