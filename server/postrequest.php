<?php
declare(strict_types=1);
require_once __DIR__.'/lib/bootstrap.php';

$request = $GLOBALS['gameRequest'] ?? null;
if (!is_array($request) || !correspondenceAutonomyNativeRequest($request)) return;

try {
    $result = correspondenceAutonomyRunNativePostrequest(
        $request,
        'correspondenceAutonomyProduction',
        'correspondenceAutonomyCoreModelFactory'
    );
    correspondenceDiagnostic('autonomy_outcome', $result);
} catch (Throwable) {
    correspondenceDiagnostic('autonomy_unavailable');
}

