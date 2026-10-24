<?php
declare(strict_types=1);

require_once __DIR__.'/runtime.php';

function correspondenceOffscreenNativeTypes(): array
{
    return [
        'inputtext', 'inputtext_s', 'ginputtext', 'ginputtext_s',
        'bored', 'location', 'infoloc', 'infonpc', 'infonpc_close', 'infoitems', 'infoplayer',
        'newgame', 'playerinfo',
    ];
}

function correspondenceOffscreenBoundaryType(string $type): bool
{
    return in_array($type, ['newgame', 'playerinfo'], true);
}

function correspondenceOffscreenNativeObservation(array $request): bool
{
    $type = $request[0] ?? null;
    if (!is_string($type) || !in_array($type, correspondenceOffscreenNativeTypes(), true)) return false;
    try {
        correspondenceRequestClock($request);
    } catch (Throwable) {
        return false;
    }
    return true;
}

function correspondenceOffscreenReservedNativeRequest(array $request): bool
{
    $type = $request[0] ?? null;
    if (!is_string($type)) return true;
    if (in_array($type, [
        'written_evidence', 'correspondence_anchor', 'correspondence_dispatch',
        'correspondence_receipt_confirmed', 'correspondence_presence', 'correspondence_companion_observation',
    ], true)) return true;
    return $type === 'info' && in_array($request[4] ?? null, ['written_evidence_v1', 'correspondence_v1'], true);
}

function correspondenceOffscreenHostResult(string $status, string $reason): array
{
    return ['status' => $status, 'reason' => $reason];
}

function correspondenceOffscreenWorkerChildResult(mixed $result, ?int $exitCode): array
{
    if ($exitCode === 124 || $exitCode === 137) return ['status'=>'failed','reason'=>'child_timeout','event'=>'offscreen_worker_result'];
    if (!is_array($result) || array_is_list($result)
        || !in_array($result['status'] ?? null, ['processed','idle','paused','stale','failed','unavailable'], true)) {
        return ['status'=>'unavailable','reason'=>$exitCode === 0 ? 'invalid_child_result' : 'child_failed','event'=>'offscreen_worker_result'];
    }
    $reason = $result['reason'] ?? 'unknown';
    if (!is_string($reason) || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $reason) !== 1) $reason = 'unknown';
    $calls = $result['model_calls'] ?? null;
    if (!is_int($calls) || $calls < 0 || $calls > 1) $calls = 0;
    $workerResult = ['status'=>$result['status'],'reason'=>$reason,'model_calls'=>$calls,'event'=>'offscreen_worker_result'];
    if (is_string($result['correlation_id'] ?? null) && preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $result['correlation_id']) === 1) $workerResult['correlation_id'] = $result['correlation_id'];
    if (is_int($result['elapsed_ms'] ?? null) && $result['elapsed_ms'] >= 0 && $result['elapsed_ms'] <= 180000) $workerResult['elapsed_ms'] = $result['elapsed_ms'];
    $sourceIds = $result['source_ids'] ?? null;
    if (is_array($sourceIds) && array_is_list($sourceIds) && count($sourceIds) === 1 && is_int($sourceIds[0]) && $sourceIds[0] > 0) $workerResult['source_ids'] = $sourceIds;
    $outcomes = $result['social_outcomes'] ?? null;
    $consumerStatuses = ['invalid','pending','queued','paused','failed','abstained','processed','running','existing_worker','spawn_requested','tick_finished','child_exited','unavailable'];
    if (is_array($outcomes) && !array_is_list($outcomes)) {
        $safeOutcomes = [];
        foreach (['mind_poisoning','sworn_and_scorned'] as $owner) if (in_array($outcomes[$owner] ?? null, $consumerStatuses, true)) $safeOutcomes[$owner] = $outcomes[$owner];
        if ($safeOutcomes !== []) $workerResult['social_outcomes'] = $safeOutcomes;
    }
    return $workerResult;
}

/** Observe one exact native game clock tuple and request a detached service resume. */
function correspondenceOffscreenObserveNativeRequest(array $request, callable $runtimeFactory, callable $resumeService): array
{
    if (correspondenceOffscreenReservedNativeRequest($request)) return correspondenceOffscreenHostResult('skipped', 'reserved_native_request');
    if (!correspondenceOffscreenNativeObservation($request)) return correspondenceOffscreenHostResult('skipped', 'native_clock_unavailable');

    try {
        $runtime = $runtimeFactory();
        $engine = is_array($runtime) ? ($runtime['offscreen'] ?? null) : null;
        $autonomy = is_array($runtime) ? ($runtime['autonomy'] ?? null) : null;
        $store = is_array($runtime) ? ($runtime['store'] ?? null) : null;
        if (!is_object($engine) || !method_exists($engine, 'observe') || !is_object($autonomy) || !method_exists($autonomy, 'settings')
            || !is_object($store) || !method_exists($store, 'bindRequestClock')) {
            return correspondenceOffscreenHostResult('unavailable', 'host_unavailable');
        }

        // Bind the exact tuple so the per-playthrough opt-in can be read first.
        // Default-off telemetry must not append an observation anchor per event.
        $store->bindRequestClock($request);
        $settings = $autonomy->settings();
        if (!is_array($settings) || ($settings['enabled'] ?? false) !== true) {
            return correspondenceOffscreenHostResult('paused', 'disabled');
        }

        // The engine resolves scope/generation and the persisted eventlog anchor itself.
        $engine->observe($request);
        if (correspondenceOffscreenBoundaryType($request[0])) return correspondenceOffscreenHostResult('observed', 'identity_boundary');
        if (!in_array($request[0], ['bored', 'location'], true)) return correspondenceOffscreenHostResult('observed', 'clock_refreshed');

        $result = $resumeService($request);
        if (!is_array($result) || !is_string($result['status'] ?? null)) return correspondenceOffscreenHostResult('unavailable', 'resume_unavailable');
        return correspondenceOffscreenHostResult($result['status'], is_string($result['reason'] ?? null) ? $result['reason'] : 'resume_requested');
    } catch (Throwable) {
        return correspondenceOffscreenHostResult('unavailable', 'host_unavailable');
    }
}

function correspondenceOffscreenServicePaths(): array
{
    $stateDirectory = correspondenceOffscreenStateDirectory(false);
    if ($stateDirectory === null) return array_fill_keys(['lock', 'stop', 'status', 'wake', 'cursor', 'signal_lock'], '');
    $base = rtrim($stateDirectory, '/\\');
    return [
        'lock' => $base.'.lock', 'stop' => $base.'.stop', 'status' => $base.'.status.json',
        'wake' => $base.'.wake', 'cursor' => $base.'.cursor', 'signal_lock' => $base.'.signal.lock',
    ];
}

function correspondenceOffscreenStateDirectory(bool $create): ?string
{
    $pluginRoot = dirname(__DIR__);
    $engineRoot = $GLOBALS['ENGINE_PATH'] ?? null;
    if (!is_string($engineRoot) || $engineRoot === '') {
        foreach ([dirname($pluginRoot, 2), dirname($pluginRoot, 3)] as $candidate) {
            if (is_file($candidate.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'runtime_bootstrap.php')) { $engineRoot = $candidate; break; }
        }
    }
    if (!is_string($engineRoot) || $engineRoot === '') return null;
    $log = rtrim($engineRoot, '/\\').DIRECTORY_SEPARATOR.'log';
    $correspondence = $log.DIRECTORY_SEPARATOR.'correspondence';
    $identity = substr(hash('sha256', realpath($pluginRoot) ?: $pluginRoot), 0, 24);
    $state = $correspondence.DIRECTORY_SEPARATOR.'offscreen-'.$identity;
    if (is_link($log) || !is_dir($log)) return null;
    foreach ([[$correspondence, $create], [$state, $create]] as [$directory, $make]) {
        if (is_link($directory)) return null;
        if (!is_dir($directory) && $make && !@mkdir($directory, 0770) && !is_dir($directory)) return null;
        if (!is_dir($directory)) return null;
    }
    $logReal = realpath($log);
    $correspondenceReal = realpath($correspondence);
    $stateReal = realpath($state);
    if (!is_string($logReal) || !is_string($correspondenceReal) || !is_string($stateReal)
        || $correspondenceReal !== $logReal.DIRECTORY_SEPARATOR.'correspondence'
        || $stateReal !== $correspondenceReal.DIRECTORY_SEPARATOR.'offscreen-'.$identity
        || !is_writable($stateReal)) return null;
    return $stateReal;
}

function correspondenceOffscreenSafePath(string $path): bool
{
    return $path !== '' && !str_contains($path, "\0") && !is_link($path);
}

function correspondenceOffscreenPluginFileAt(string $pluginRoot, string $name): ?string
{
    if (!in_array($name, ['offscreen-worker.php', 'offscreen-service.php'], true)) return null;
    foreach ([$pluginRoot.DIRECTORY_SEPARATOR.$name, $pluginRoot.DIRECTORY_SEPARATOR.'server'.DIRECTORY_SEPARATOR.$name] as $candidate) {
        if (!is_link($candidate) && is_file($candidate)) return $candidate;
    }
    return null;
}

function correspondenceOffscreenPluginFile(string $name): ?string
{
    return correspondenceOffscreenPluginFileAt(dirname(__DIR__), $name);
}

function correspondenceOffscreenCliPhpBinary(): ?string
{
    $binary = '/usr/bin/php';
    return is_file($binary) && is_executable($binary) ? $binary : null;
}

function correspondenceOffscreenReadCounterLocked(string $path): int
{
    if (!correspondenceOffscreenSafePath($path)) return 0;
    $raw = @file_get_contents($path);
    if (!is_string($raw) || preg_match('/\A(?:0|[1-9][0-9]{0,17})\z/D', trim($raw)) !== 1) return 0;
    $value = filter_var(trim($raw), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    return is_int($value) ? $value : 0;
}

function correspondenceOffscreenWithSignalLock(callable $callback): mixed
{
    if (correspondenceOffscreenStateDirectory(false) === null) return null;
    $paths = correspondenceOffscreenServicePaths();
    if (!correspondenceOffscreenSafePath($paths['signal_lock'])) return null;
    $handle = @fopen($paths['signal_lock'], 'c+e');
    if (!is_resource($handle) || !@flock($handle, LOCK_EX)) {
        if (is_resource($handle)) @fclose($handle);
        return null;
    }
    try { return $callback($paths); }
    finally { @flock($handle, LOCK_UN); @fclose($handle); }
}

function correspondenceOffscreenSignalNativeObservation(): bool
{
    $value = correspondenceOffscreenWithSignalLock(static function (array $paths): bool {
        $current = correspondenceOffscreenReadCounterLocked($paths['wake']);
        if ($current >= PHP_INT_MAX - 1) return false;
        $next = (string)($current + 1);
        $tmp = $paths['wake'].'.'.getmypid().'.tmp';
        if (!correspondenceOffscreenSafePath($tmp) || !correspondenceOffscreenSafePath($paths['wake'])) return false;
        if (@file_put_contents($tmp, $next, LOCK_EX) === false) return false;
        return @rename($tmp, $paths['wake']);
    });
    return $value === true;
}

function correspondenceOffscreenReadSignalCounters(): ?array
{
    if (correspondenceOffscreenStateDirectory(false) === null) return null;
    return correspondenceOffscreenWithSignalLock(static fn(array $paths): array => [
        'wake' => correspondenceOffscreenReadCounterLocked($paths['wake']),
        'cursor' => correspondenceOffscreenReadCounterLocked($paths['cursor']),
    ]);
}

function correspondenceOffscreenAdvanceSignalCursor(int $sequence): bool
{
    if ($sequence < 0 || correspondenceOffscreenStateDirectory(false) === null) return false;
    $value = correspondenceOffscreenWithSignalLock(static function (array $paths) use ($sequence): bool {
        $current = correspondenceOffscreenReadCounterLocked($paths['cursor']);
        return correspondenceOffscreenWriteCounterLocked($paths['cursor'], max($current, $sequence));
    });
    return $value === true;
}

function correspondenceOffscreenWriteCounterLocked(string $path, int $value): bool
{
    if ($value < 0 || !correspondenceOffscreenSafePath($path)) return false;
    $tmp = $path.'.'.getmypid().'.tmp';
    if (!correspondenceOffscreenSafePath($tmp) || @file_put_contents($tmp, (string)$value, LOCK_EX) === false) return false;
    return @rename($tmp, $path);
}

/** Discard clock wakeups predating an operator-only listener start. */
function correspondenceOffscreenAcknowledgeExistingWakeups(): bool
{
    if (correspondenceOffscreenStateDirectory(false) === null) return false;
    $value = correspondenceOffscreenWithSignalLock(static function (array $paths): bool {
        $wake = correspondenceOffscreenReadCounterLocked($paths['wake']);
        $cursor = correspondenceOffscreenReadCounterLocked($paths['cursor']);
        return correspondenceOffscreenWriteCounterLocked($paths['cursor'], max($wake, $cursor));
    });
    return $value === true;
}

function correspondenceOffscreenServiceReadLastStatus(): array
{
    if (correspondenceOffscreenStateDirectory(false) === null) return [];
    $paths = correspondenceOffscreenServicePaths();
    if (!correspondenceOffscreenSafePath($paths['status'])) return [];
    $raw = @file_get_contents($paths['status']);
    if (!is_string($raw) || strlen($raw) > 2048) return [];
    try { $value = json_decode($raw, true, 8, JSON_THROW_ON_ERROR); }
    catch (JsonException) { return []; }
    if (!is_array($value) || array_is_list($value)) return [];
    $status = $value['status'] ?? null;
    $reason = $value['reason'] ?? null;
    if (!is_string($status) || !in_array($status, ['running','spawn_requested','processed','idle','paused','stale','failed','unavailable'], true)) return [];
    if (!is_string($reason) || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $reason) !== 1) $reason = 'unknown';
    $result = ['status' => $status, 'reason' => $reason];
    if (($value['schema'] ?? null) === 'correspondence.status.v1') $result['schema'] = $value['schema'];
    if (in_array($value['event'] ?? null, ['offscreen_worker_result','offscreen_service_status'], true)) $result['event'] = $value['event'];
    if (is_string($value['at_utc'] ?? null) && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $value['at_utc']) === 1) $result['at_utc'] = $value['at_utc'];
    if (is_string($value['correlation_id'] ?? null) && preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $value['correlation_id']) === 1) $result['correlation_id'] = $value['correlation_id'];
    if (is_int($value['elapsed_ms'] ?? null) && $value['elapsed_ms'] >= 0 && $value['elapsed_ms'] <= 180000) $result['elapsed_ms'] = $value['elapsed_ms'];
    if (is_int($value['model_calls'] ?? null) && $value['model_calls'] >= 0 && $value['model_calls'] <= 1) $result['model_calls'] = $value['model_calls'];
    $sourceIds = $value['source_ids'] ?? null;
    if (is_array($sourceIds) && array_is_list($sourceIds) && count($sourceIds) === 1 && is_int($sourceIds[0]) && $sourceIds[0] > 0) $result['source_ids'] = $sourceIds;
    $outcomes = $value['social_outcomes'] ?? null;
    $consumerStatuses = ['invalid','pending','queued','paused','failed','abstained','processed','running','existing_worker','spawn_requested','tick_finished','child_exited','unavailable'];
    if (is_array($outcomes) && !array_is_list($outcomes)) {
        $safeOutcomes = [];
        foreach (['mind_poisoning','sworn_and_scorned'] as $owner) if (in_array($outcomes[$owner] ?? null, $consumerStatuses, true)) $safeOutcomes[$owner] = $outcomes[$owner];
        if ($safeOutcomes !== []) $result['social_outcomes'] = $safeOutcomes;
    }
    return $result;
}

function correspondenceOffscreenServiceWriteLastStatus(string $status, string $reason, array $metadata = []): void
{
    if (!in_array($status, ['running','spawn_requested','processed','idle','paused','stale','failed','unavailable'], true)
        || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $reason) !== 1) return;
    if (correspondenceOffscreenStateDirectory(false) === null) return;
    $paths = correspondenceOffscreenServicePaths();
    if (!correspondenceOffscreenSafePath($paths['status'])) return;
    $event = ($metadata['event'] ?? null) === 'offscreen_worker_result' ? 'offscreen_worker_result' : 'offscreen_service_status';
    $record = ['schema'=>'correspondence.status.v1','event'=>$event,'status'=>$status,'reason'=>$reason,'at_utc'=>gmdate('Y-m-d\TH:i:s\Z')];
    if (is_string($metadata['correlation_id'] ?? null) && preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $metadata['correlation_id']) === 1) $record['correlation_id'] = $metadata['correlation_id'];
    if (is_int($metadata['elapsed_ms'] ?? null) && $metadata['elapsed_ms'] >= 0 && $metadata['elapsed_ms'] <= 180000) $record['elapsed_ms'] = $metadata['elapsed_ms'];
    if (is_int($metadata['model_calls'] ?? null) && $metadata['model_calls'] >= 0 && $metadata['model_calls'] <= 1) $record['model_calls'] = $metadata['model_calls'];
    $sourceIds = $metadata['source_ids'] ?? null;
    if (is_array($sourceIds) && array_is_list($sourceIds) && count($sourceIds) === 1 && is_int($sourceIds[0]) && $sourceIds[0] > 0) $record['source_ids'] = $sourceIds;
    $outcomes = $metadata['social_outcomes'] ?? null;
    $consumerStatuses = ['invalid','pending','queued','paused','failed','abstained','processed','running','existing_worker','spawn_requested','tick_finished','child_exited','unavailable'];
    if (is_array($outcomes) && !array_is_list($outcomes)) {
        $safeOutcomes = [];
        foreach (['mind_poisoning','sworn_and_scorned'] as $owner) if (in_array($outcomes[$owner] ?? null, $consumerStatuses, true)) $safeOutcomes[$owner] = $outcomes[$owner];
        if ($safeOutcomes !== []) $record['social_outcomes'] = $safeOutcomes;
    }
    $json = json_encode($record, JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) return;
    $tmp = $paths['status'].'.'.getmypid().'.tmp';
    if (!correspondenceOffscreenSafePath($tmp)) return;
    try {
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) return;
        @rename($tmp, $paths['status']);
    } catch (Throwable) {}
}

/** Return only plugin-process state; never bootstrap CHIM or read source bodies. */
function correspondenceOffscreenServiceStatus(): array
{
    if (!function_exists('flock')) return ['status' => 'unavailable', 'reason' => 'lock_unavailable'];
    if (correspondenceOffscreenStateDirectory(false) === null) return ['status' => 'unavailable', 'reason' => 'state_directory_unavailable'];
    $paths = correspondenceOffscreenServicePaths();
    if (!correspondenceOffscreenSafePath($paths['lock'])) return ['status' => 'unavailable', 'reason' => 'lock_unavailable'];
    $free = correspondenceOffscreenWithSignalLock(static function (array $paths): ?bool {
        $handle = @fopen($paths['lock'], 'c+e');
        if (!is_resource($handle)) return null;
        try {
            if (!@flock($handle, LOCK_EX | LOCK_NB)) return false;
            @flock($handle, LOCK_UN);
            return true;
        } finally { @fclose($handle); }
    });
    if ($free === null) return ['status' => 'unavailable', 'reason' => 'lock_unavailable'];
    if ($free === false) return ['status' => 'running', 'reason' => 'supervisor_active'];
    $last = correspondenceOffscreenServiceReadLastStatus();
    if (in_array($last['status'] ?? null, ['failed','unavailable'], true)) return ['status' => 'unavailable', 'reason' => $last['reason'] ?? 'service_unavailable'];
    if (($last['status'] ?? null) === 'running') return ['status' => 'paused', 'reason' => 'supervisor_exited'];
    if (($last['status'] ?? null) === 'spawn_requested') return ['status' => 'paused', 'reason' => 'start_pending'];
    return ['status' => 'paused', 'reason' => $last['reason'] ?? 'waiting_for_native_clock'];
}

function correspondenceOffscreenNativeStartTrigger(array $request, bool $validatedCompanion = false): bool
{
    if (correspondenceOffscreenNativeObservation($request)) return true;
    if (!$validatedCompanion || !is_string($request[0] ?? null) || strtolower($request[0]) !== 'correspondence_companion') return false;
    try { correspondenceRequestClock($request); return true; }
    catch (Throwable) { return false; }
}

function correspondenceOffscreenServiceStart(?array $nativeRequest = null, bool $validatedCompanion = false): array
{
    if (correspondenceOffscreenStateDirectory(true) === null) return ['status' => 'unavailable', 'reason' => 'state_directory_unavailable'];
    if ($nativeRequest !== null) {
        if (!correspondenceOffscreenNativeStartTrigger($nativeRequest, $validatedCompanion)) return ['status' => 'unavailable', 'reason' => 'native_clock_unavailable'];
        if (!correspondenceOffscreenSignalNativeObservation()) return ['status' => 'unavailable', 'reason' => 'wake_signal_unavailable'];
    }
    $paths = correspondenceOffscreenServicePaths();
    if (!correspondenceOffscreenSafePath($paths['stop'])) return ['status' => 'unavailable', 'reason' => 'stop_marker_unavailable'];
    // A fresh start/resume wins over a prior stop request, including while the old
    // supervisor still owns the lock and is between its final check and exit.
    @unlink($paths['stop']);
    $state = correspondenceOffscreenServiceStatus();
    if (($state['status'] ?? null) === 'running') return ['status' => 'running', 'reason' => 'supervisor_active'];
    if (($state['status'] ?? null) === 'unavailable' && ($state['reason'] ?? null) === 'lock_unavailable') return $state;
    if ($nativeRequest === null && !correspondenceOffscreenAcknowledgeExistingWakeups()) return ['status' => 'unavailable', 'reason' => 'wake_cursor_unavailable'];
    if (!function_exists('exec') || PHP_OS_FAMILY === 'Windows') return ['status' => 'unavailable', 'reason' => 'process_start_unavailable'];
    $disabled = array_filter(array_map('trim', explode(',', (string)ini_get('disable_functions'))));
    if (in_array('exec', $disabled, true)) return ['status' => 'unavailable', 'reason' => 'process_start_unavailable'];

    correspondenceOffscreenServiceWriteLastStatus('spawn_requested', 'service_start_requested');
    $script = correspondenceOffscreenPluginFile('offscreen-service.php');
    $phpCli = correspondenceOffscreenCliPhpBinary();
    if (!is_string($script) || !is_string($phpCli)) return ['status' => 'unavailable', 'reason' => 'service_entry_unavailable'];
    $command = escapeshellarg($phpCli).' '.escapeshellarg($script).' --correspondence-background';
    $command = 'nohup '.$command.' >/dev/null 2>&1 </dev/null &';
    $output = [];
    $exitCode = 1;
    @exec($command, $output, $exitCode);
    if ($exitCode !== 0) return ['status' => 'unavailable', 'reason' => 'process_start_failed'];
    return ['status' => 'spawn_requested', 'reason' => 'service_start_requested'];
}

function correspondenceOffscreenServiceStop(): array
{
    if (correspondenceOffscreenStateDirectory(false) === null) return ['status' => 'paused', 'reason' => 'service_stopped'];
    $paths = correspondenceOffscreenServicePaths();
    if (!correspondenceOffscreenSafePath($paths['stop']) || @file_put_contents($paths['stop'], 'stop', LOCK_EX) === false) return ['status' => 'unavailable', 'reason' => 'stop_request_failed'];
    $state = correspondenceOffscreenServiceStatus();
    if (($state['status'] ?? null) !== 'running') {
        correspondenceOffscreenServiceWriteLastStatus('paused', 'service_stopped');
        return ['status' => 'paused', 'reason' => 'service_stopped'];
    }
    correspondenceOffscreenServiceWriteLastStatus('paused', 'stop_requested');
    return ['status' => 'paused', 'reason' => 'stop_requested'];
}

function correspondenceOffscreenAcquireSupervisorLock(): mixed
{
    if (correspondenceOffscreenStateDirectory(true) === null) return null;
    $paths = correspondenceOffscreenServicePaths();
    if (!correspondenceOffscreenSafePath($paths['lock'])) return null;
    return correspondenceOffscreenWithSignalLock(static function (array $paths): mixed {
        $handle = @fopen($paths['lock'], 'c+e');
        if (!is_resource($handle) || !@flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) @fclose($handle);
            return null;
        }
        @ftruncate($handle, 0);
        @rewind($handle);
        @fwrite($handle, (string)getmypid());
        @fflush($handle);
        return $handle;
    });
}

function correspondenceOffscreenStopRequested(): bool
{
    $path = correspondenceOffscreenServicePaths()['stop'];
    return correspondenceOffscreenSafePath($path) && is_file($path);
}

function correspondenceOffscreenRunWorkerChild(): array
{
    if (!function_exists('proc_open') || !function_exists('proc_get_status')) return ['status' => 'unavailable', 'reason' => 'child_process_unavailable'];
    $script = correspondenceOffscreenPluginFile('offscreen-worker.php');
    $timeout = '/usr/bin/timeout';
    $phpCli = correspondenceOffscreenCliPhpBinary();
    if (!is_string($script) || !is_file($timeout) || !is_string($phpCli) || PHP_OS_FAMILY === 'Windows') return ['status' => 'unavailable', 'reason' => 'worker_entry_unavailable'];
    $pipes = [];
    $process = @proc_open([$timeout, '--signal=TERM', '--kill-after=5s', '150s', $phpCli, $script, '--once'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname($script), null, ['bypass_shell' => true]);
    if (!is_resource($process)) return ['status' => 'unavailable', 'reason' => 'child_start_failed'];
    foreach ($pipes as $index => $pipe) if ($index > 0) @stream_set_blocking($pipe, false);
    $stdout = '';
    $exitCode = null;
    try {
        do {
            $chunk = @stream_get_contents($pipes[1]);
            if (is_string($chunk)) $stdout = substr($stdout.$chunk, 0, 8192);
            if (isset($pipes[2])) @stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!is_array($status)) break;
            if (!$status['running']) { $exitCode = is_int($status['exitcode'] ?? null) ? $status['exitcode'] : null; break; }
            usleep(100_000);
        } while (true);
        $chunk = @stream_get_contents($pipes[1]);
        if (is_string($chunk)) $stdout = substr($stdout.$chunk, 0, 8192);
    } finally {
        foreach ($pipes as $pipe) if (is_resource($pipe)) @fclose($pipe);
        @proc_close($process);
    }
    try { $result = json_decode(trim($stdout), true, 8, JSON_THROW_ON_ERROR); }
    catch (JsonException) { $result = null; }
    return correspondenceOffscreenWorkerChildResult($result, $exitCode);
}

/** Poll only for a newly persisted native observation; each tick gets a fresh bounded child. */
function correspondenceOffscreenRunSupervisor(): int
{
    $lock = correspondenceOffscreenAcquireSupervisorLock();
    if (!is_resource($lock)) return 0;
    $paths = correspondenceOffscreenServicePaths();
    correspondenceOffscreenServiceWriteLastStatus('running', 'supervisor_active');
    $started = hrtime(true);
    $failures = 0;
    try {
        // Leave the full 150-second child plus its five-second kill grace inside 30 minutes.
        while ((hrtime(true) - $started) < 1_645_000_000_000) {
            if (correspondenceOffscreenStopRequested()) {
                correspondenceOffscreenServiceWriteLastStatus('paused', 'stop_requested');
                return 0;
            }
            $counters = correspondenceOffscreenReadSignalCounters();
            if (!is_array($counters)) {
                correspondenceOffscreenServiceWriteLastStatus('unavailable', 'wake_signal_unavailable');
                return 1;
            }
            if ($counters['wake'] <= $counters['cursor']) { usleep(2_000_000); continue; }

            while (isset($lastChildStarted) && (hrtime(true) - $lastChildStarted) < 2_000_000_000) {
                if (correspondenceOffscreenStopRequested()) {
                    correspondenceOffscreenServiceWriteLastStatus('paused', 'stop_requested');
                    return 0;
                }
                usleep(100_000);
            }
            if ((hrtime(true) - $started) >= 1_645_000_000_000) {
                correspondenceOffscreenServiceWriteLastStatus('paused', 'service_lifetime_elapsed');
                return 0;
            }
            $latest = correspondenceOffscreenReadSignalCounters();
            if (!is_array($latest)) {
                correspondenceOffscreenServiceWriteLastStatus('unavailable', 'wake_signal_unavailable');
                return 1;
            }
            if ($latest['wake'] <= $latest['cursor']) continue;
            $sequence = $latest['wake'];
            $lastChildStarted = hrtime(true);
            $result = correspondenceOffscreenRunWorkerChild();
            $status = $result['status'] ?? 'unavailable';
            $reason = $result['reason'] ?? 'unknown';
            correspondenceOffscreenServiceWriteLastStatus($status, $reason, $result);
            if (!correspondenceOffscreenAdvanceSignalCursor($sequence)) {
                correspondenceOffscreenServiceWriteLastStatus('unavailable', 'wake_cursor_unavailable');
                return 1;
            }
            if ($status === 'paused') return 0;
            if ($status === 'stale') {
                $latest = correspondenceOffscreenReadSignalCounters();
                if (!is_array($latest) || $latest['wake'] <= $sequence) return 0;
                // A native observation arrived while the previous child was
                // validating an expired anchor. Consume that fresh opportunity.
                continue;
            }
            if (in_array($status, ['failed','unavailable'], true)) {
                if (++$failures >= 3) return 1;
            } else $failures = 0;
        }
        correspondenceOffscreenServiceWriteLastStatus('paused', 'service_lifetime_elapsed');
        return 0;
    } catch (Throwable) {
        correspondenceOffscreenServiceWriteLastStatus('unavailable', 'supervisor_failed');
        return 1;
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}

function correspondenceOffscreenValidateConsumerResult(mixed $result): array
{
    $statuses = ['invalid','pending','queued','paused','failed','abstained','processed','running','existing_worker','spawn_requested','tick_finished','child_exited','unavailable'];
    if (!is_array($result) || !in_array($result['status'] ?? null, $statuses, true)) return ['status' => 'unavailable', 'reason' => 'invalid_consumer_result'];
    $reason = $result['reason'] ?? 'unknown';
    if (!is_string($reason) || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $reason) !== 1) $reason = 'unknown';
    return ['status' => $result['status'], 'reason' => $reason];
}

function correspondenceOffscreenRemainingBudgetSeconds(int $deadlineNanoseconds, ?callable $monotonicNow = null): int
{
    try { $now = $monotonicNow === null ? hrtime(true) : $monotonicNow(); }
    catch (Throwable) { return 0; }
    if (!is_int($now) || $deadlineNanoseconds <= $now) return 0;
    return intdiv($deadlineNanoseconds - $now, 1_000_000_000);
}

function correspondenceOffscreenWakeSasWithinBudget(?callable $remainingBudget, callable $wake): array
{
    try { $remaining = $remainingBudget === null ? null : $remainingBudget(); }
    catch (Throwable) { $remaining = null; }
    if (!is_int($remaining) || $remaining < 65) return ['status' => 'pending', 'reason' => 'worker_deadline_budget'];
    return correspondenceOffscreenValidateConsumerResult($wake());
}

function correspondenceOffscreenInteractionMatches(int $capturedGeneration): bool
{
    if (!function_exists('chimInteractionState') || !array_key_exists('chim_interaction_generation', $GLOBALS)
        || !is_int($GLOBALS['chim_interaction_generation'])) return false;
    try {
        $state = chimInteractionState();
        return is_array($state) && ($state['enabled'] ?? null) === true
            && ($state['generation'] ?? null) === $capturedGeneration
            && $GLOBALS['chim_interaction_generation'] === $capturedGeneration
            && ($_SERVER['HTTP_X_CHIM_PASSIVE'] ?? '') !== '1';
    } catch (Throwable) { return false; }
}

function correspondenceOffscreenConnectionIdle(mixed $connection): bool
{
    if (!function_exists('pg_transaction_status') || !defined('PGSQL_TRANSACTION_IDLE')) return false;
    try { return pg_transaction_status($connection) === PGSQL_TRANSACTION_IDLE; }
    catch (Throwable) { return false; }
}

/** Run public social consumers once for event IDs newly exposed by this tick. */
function correspondenceOffscreenConsumeNewSources(string $root, mixed $connection, object $engine, ?array $pendingSourceIds = null, ?callable $remainingBudget = null): array
{
    $isPgConnection = is_resource($connection)
        || (class_exists('PgSql\\Connection', false) && $connection instanceof \PgSql\Connection);
    $interactionGeneration = $GLOBALS['chim_interaction_generation'] ?? null;
    if (!method_exists($engine, 'pendingSources') || !$isPgConnection || !array_key_exists('ptr_runtime_generation', $GLOBALS)
        || !is_int($interactionGeneration)
        || !correspondenceOffscreenInteractionMatches($interactionGeneration)) {
        return ['status' => 'unavailable', 'reason' => 'context_generation_unavailable', 'sources' => []];
    }
    if (!correspondenceOffscreenConnectionIdle($connection)) return ['status'=>'unavailable','reason'=>'transaction_active','sources'=>[]];
    $generation = $GLOBALS['ptr_runtime_generation'];
    try { $sources = $pendingSourceIds ?? $engine->pendingSources(); }
    catch (Throwable) { return ['status' => 'unavailable', 'reason' => 'source_list_unavailable', 'sources' => []]; }
    if (!is_array($sources) || !array_is_list($sources)) return ['status' => 'unavailable', 'reason' => 'invalid_source_list', 'sources' => []];
    $ids = [];
    foreach ($sources as $id) if (is_int($id) && $id > 0 && !in_array($id, $ids, true)) $ids[] = $id;
    if (count($ids) > 1) return ['status' => 'unavailable', 'reason' => 'source_count_exceeds_tick_contract', 'sources' => []];
    if ($ids === []) return ['status' => 'idle', 'reason' => 'no_new_sources', 'sources' => []];

    $result = ['status' => 'processed', 'reason' => 'social_attempted', 'sources' => []];
    foreach ($ids as $eventId) {
        if (($GLOBALS['ptr_runtime_generation'] ?? null) !== $generation || !correspondenceOffscreenInteractionMatches($interactionGeneration)
            || !correspondenceOffscreenConnectionIdle($connection)) {
            $result['status'] = 'stale';
            $result['reason'] = 'interaction_generation_changed';
            break;
        }
        $entry = ['event_id' => $eventId, 'mind_poisoning' => ['status' => 'unavailable', 'reason' => 'consumer_absent'], 'sworn_and_scorned' => ['status' => 'unavailable', 'reason' => 'consumer_absent'], 'sworn_and_scorned_wake'=>['status'=>'unavailable','reason'=>'consumer_absent']];
        $mpFile = $root.'/ext/mind_poisoning/written_evidence.php';
        if (is_file($mpFile)) { try { require_once $mpFile; } catch (Throwable) {} }
        $mpAvailable = function_exists('ChimMindPoisoning\\writtenEvidenceCapability')
            && function_exists('ChimMindPoisoning\\writtenEvidenceStatus')
            && function_exists('ChimMindPoisoning\\evaluateWrittenEvidence')
            && class_exists('ChimMindPoisoning\\PostgresStoreDb');
        if ($mpAvailable) {
            try {
                $capability = \ChimMindPoisoning\writtenEvidenceCapability();
                if (is_array($capability) && ($capability['version'] ?? null) === 1 && ($capability['input_type'] ?? null) === 'written_evidence'
                    && ($capability['source_marker'] ?? null) === 'written_evidence_v1' && ($capability['source_event_type'] ?? null) === 'info'
                    && ($capability['recipient_only'] ?? null) === true && ($capability['max_body_codepoints'] ?? 0) >= 2000) {
                    $semaphore = $root.'/lib/semaphore_manager.class.php';
                    if (!is_file($semaphore)) {
                        $entry['mind_poisoning'] = ['status'=>'unavailable','reason'=>'semaphore_unavailable'];
                    } else {
                        require_once $semaphore;
                        $mpStore = new \ChimMindPoisoning\PostgresStoreDb(null, $connection);
                        $before = correspondenceOffscreenValidateConsumerResult(\ChimMindPoisoning\writtenEvidenceStatus($eventId, $mpStore));
                        $entry['mind_poisoning'] = $before;
                        if ($before['status'] === 'pending' && ($GLOBALS['ptr_runtime_generation'] ?? null) === $generation
                            && correspondenceOffscreenInteractionMatches($interactionGeneration) && correspondenceOffscreenConnectionIdle($connection)) {
                            \ChimMindPoisoning\evaluateWrittenEvidence($eventId, $mpStore, null);
                            if (($GLOBALS['ptr_runtime_generation'] ?? null) !== $generation || !correspondenceOffscreenInteractionMatches($interactionGeneration)
                                || !correspondenceOffscreenConnectionIdle($connection)) {
                                $result['status'] = 'stale';
                                $result['reason'] = 'interaction_generation_changed';
                                $result['sources'][] = $entry;
                                break;
                            }
                            $entry['mind_poisoning'] = correspondenceOffscreenValidateConsumerResult(\ChimMindPoisoning\writtenEvidenceStatus($eventId, $mpStore));
                        }
                    }
                } else $entry['mind_poisoning'] = ['status' => 'unavailable', 'reason' => 'capability_mismatch'];
            } catch (Throwable) { $entry['mind_poisoning'] = ['status' => 'unavailable', 'reason' => 'consumer_unavailable']; }
        }

        if (($GLOBALS['ptr_runtime_generation'] ?? null) !== $generation || !correspondenceOffscreenInteractionMatches($interactionGeneration)
            || !correspondenceOffscreenConnectionIdle($connection)) {
            $result['status'] = 'stale';
            $result['reason'] = 'interaction_generation_changed';
            $result['sources'][] = $entry;
            break;
        }
        $sasSource = $root.'/ext/sworn_and_scorned/written_source.php';
        $sasWorker = $root.'/ext/sworn_and_scorned/written_worker.php';
        if (is_file($sasSource)) { try { require_once $sasSource; } catch (Throwable) {} }
        if (is_file($sasWorker)) { try { require_once $sasWorker; } catch (Throwable) {} }
        $sasAvailable = function_exists('ChimSwornAndScorned\\writtenEvidenceCapability')
            && function_exists('ChimSwornAndScorned\\writtenEvidenceStatus')
            && function_exists('ChimSwornAndScorned\\enqueueWrittenEvidence')
            && function_exists('ChimSwornAndScorned\\wakeWrittenEvidenceWorker')
            && class_exists('ChimSwornAndScorned\\Pg') && is_file($sasSource) && is_file($sasWorker);
        if ($sasAvailable) {
            try {
                $capability = \ChimSwornAndScorned\writtenEvidenceCapability();
                if (is_array($capability) && ($capability['version'] ?? null) === 1 && ($capability['input_type'] ?? null) === 'written_evidence'
                    && ($capability['source_marker'] ?? null) === 'written_evidence_v1' && ($capability['source_event_type'] ?? null) === 'info'
                    && ($capability['recipient_only'] ?? null) === true && ($capability['max_body_codepoints'] ?? 0) >= 2000) {
                    $sasStore = new \ChimSwornAndScorned\Pg($connection);
                    $before = correspondenceOffscreenValidateConsumerResult(\ChimSwornAndScorned\writtenEvidenceStatus($sasStore, $eventId));
                    $entry['sworn_and_scorned'] = $before;
                    if (in_array($before['status'], ['pending','queued'], true)
                        && ($GLOBALS['ptr_runtime_generation'] ?? null) === $generation
                        && correspondenceOffscreenInteractionMatches($interactionGeneration) && correspondenceOffscreenConnectionIdle($connection)) {
                        if ($before['status'] === 'pending') {
                            correspondenceOffscreenValidateConsumerResult(\ChimSwornAndScorned\enqueueWrittenEvidence($sasStore, $eventId));
                            if (($GLOBALS['ptr_runtime_generation'] ?? null) !== $generation || !correspondenceOffscreenInteractionMatches($interactionGeneration)
                                || !correspondenceOffscreenConnectionIdle($connection)) {
                                $result['status'] = 'stale';
                                $result['reason'] = 'interaction_generation_changed';
                                $result['sources'][] = $entry;
                                break;
                            }
                        }
                        $queued = correspondenceOffscreenValidateConsumerResult(\ChimSwornAndScorned\writtenEvidenceStatus($sasStore, $eventId));
                        if (in_array($queued['status'], ['queued','pending'], true)
                            && ($GLOBALS['ptr_runtime_generation'] ?? null) === $generation
                            && correspondenceOffscreenInteractionMatches($interactionGeneration) && correspondenceOffscreenConnectionIdle($connection)) {
                            $entry['sworn_and_scorned_wake'] = correspondenceOffscreenWakeSasWithinBudget(
                                $remainingBudget,
                                static fn(): array => \ChimSwornAndScorned\wakeWrittenEvidenceWorker($sasStore),
                            );
                            if (($GLOBALS['ptr_runtime_generation'] ?? null) !== $generation || !correspondenceOffscreenInteractionMatches($interactionGeneration)
                                || !correspondenceOffscreenConnectionIdle($connection)) {
                                $result['status'] = 'stale';
                                $result['reason'] = 'interaction_generation_changed';
                                $result['sources'][] = $entry;
                                break;
                            }
                        }
                        $entry['sworn_and_scorned'] = correspondenceOffscreenValidateConsumerResult(\ChimSwornAndScorned\writtenEvidenceStatus($sasStore, $eventId));
                    }
                } else $entry['sworn_and_scorned'] = ['status' => 'unavailable', 'reason' => 'capability_mismatch'];
            } catch (Throwable) { $entry['sworn_and_scorned'] = ['status' => 'unavailable', 'reason' => 'consumer_unavailable']; }
        }
        if (($GLOBALS['ptr_runtime_generation'] ?? null) !== $generation || !correspondenceOffscreenInteractionMatches($interactionGeneration)
            || !correspondenceOffscreenConnectionIdle($connection)) {
            $result['status'] = 'stale';
            $result['reason'] = 'interaction_generation_changed';
            $result['sources'][] = $entry;
            break;
        }
        $result['sources'][] = $entry;
    }
    return $result;
}

function correspondenceOffscreenPanelHtml(bool $enabled, array $service, array $engine): string
{
    $safe = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $serviceState = $service['status'] ?? 'unavailable';
    $status = !$enabled ? 'paused' : (in_array($serviceState, ['running'], true) ? 'running' : (in_array($serviceState, ['unavailable'], true) ? 'unavailable' : 'paused'));
    $last = $engine['last_observation'] ?? null;
    $clock = 'No native game clock observed yet.';
    if (is_array($last) && is_int($last['gamets'] ?? null) && $last['gamets'] > 0 && is_string($last['type'] ?? null)) {
        $clock = 'Last native clock: '.$last['type'].' at game time '.$last['gamets'].'.';
    }
    $messages = is_array($engine['messages'] ?? null) ? $engine['messages'] : [];
    $counts = [];
    foreach (['in_transit','arrived','exposed','stale'] as $key) if (is_int($messages[$key] ?? null) && $messages[$key] >= 0) $counts[] = $key.' '.$messages[$key];
    $detail = $counts === [] ? '' : '<p>Text inbox counts: '.$safe(implode(', ', $counts)).'.</p>';
    return '<section><h2>Offscreen correspondence</h2><p>Service: '.$safe($status).'. '.$safe($clock).'</p>'.$detail.'<p>NPC letters use a private text-only inbox. They do not claim physical transfer, a Papyrus note, witnesses, or spoken events. Player physical delivery continues through the CHIM bridge and requires its real receipt.</p></section>';
}

