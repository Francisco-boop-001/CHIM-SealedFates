<?php
declare(strict_types=1);

require_once __DIR__.'/lib/bootstrap.php';

function correspondenceOffscreenServiceEmit(array $result): never
{
    $safe = [
        'status' => is_string($result['status'] ?? null) ? $result['status'] : 'unavailable',
        'reason' => is_string($result['reason'] ?? null) && preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $result['reason']) === 1 ? $result['reason'] : 'service_unavailable',
    ];
    echo json_encode($safe, JSON_UNESCAPED_SLASHES)."\n";
    exit;
}

if (PHP_SAPI !== 'cli') correspondenceOffscreenServiceEmit(['status'=>'unavailable','reason'=>'cli_required']);

$command = $argv[1] ?? '';
if ($command === '--correspondence-background' && $argc === 2) exit(correspondenceOffscreenRunSupervisor());
if ($argc !== 2 || !in_array($command, ['start','stop','status'], true)) {
    correspondenceOffscreenServiceEmit(['status'=>'unavailable','reason'=>'usage']);
}

$result = match ($command) {
    'start' => correspondenceOffscreenServiceStart(),
    'stop' => correspondenceOffscreenServiceStop(),
    default => correspondenceOffscreenServiceStatus(),
};
correspondenceOffscreenServiceEmit($result);
