<?php
declare(strict_types=1);
require_once __DIR__.'/lib/bootstrap.php';

$request = $GLOBALS['gameRequest'] ?? null;
if (!is_array($request) || !correspondenceAutonomyNativeRequest($request)) return;

try {
    correspondenceAutonomyRunNativePostrequest(
        $request,
        'correspondenceAutonomyProduction',
        'correspondenceAutonomyCoreModelFactory'
    );
} catch (Throwable) {
    correspondenceDiagnostic('autonomy_unavailable');
}

