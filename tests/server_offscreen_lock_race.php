<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/server/lib/offscreen_host.php';
function lockRaceCheck(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
if (($argv[1] ?? null)==='child') {
    $GLOBALS['ENGINE_PATH'] = $argv[2];
    $prefix = $argv[2].DIRECTORY_SEPARATOR.'child-'.$argv[3];
    file_put_contents($prefix.'.ready','ready');
    $lock = correspondenceOffscreenAcquireSupervisorLock();
    file_put_contents($prefix.'.result',is_resource($lock) ? 'acquired' : 'refused');
    if (is_resource($lock)) { flock($lock,LOCK_UN); fclose($lock); }
    exit;
}
lockRaceCheck(function_exists('proc_open'), 'this process-only regression requires proc_open');
$engineRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sealed-fates-lock-race-'.bin2hex(random_bytes(8));
lockRaceCheck(mkdir($engineRoot) && mkdir($engineRoot.DIRECTORY_SEPARATOR.'log'), 'fixture directory creation failed');
$GLOBALS['ENGINE_PATH'] = $engineRoot;
$state = correspondenceOffscreenStateDirectory(true);
lockRaceCheck(is_string($state), 'fixture state directory unavailable');
$paths = correspondenceOffscreenServicePaths();
$signal = fopen($paths['signal_lock'],'c+e');
$main = fopen($paths['lock'],'c+e');
$children = [];
$read = static function (string $path): string {
    $end = hrtime(true)+3_000_000_000;
    do {
        clearstatcache(true,$path);
        if (is_file($path)) { $value = file_get_contents($path); if (is_string($value) && $value!=='') return $value; }
        usleep(10_000);
    } while (hrtime(true)<$end);
    throw new RuntimeException('child marker did not arrive within the fixture deadline');
};
$start = static function () use ($engineRoot,&$children,$read): array {
    $id = count($children)+1;
    $prefix = $engineRoot.DIRECTORY_SEPARATOR.'child-'.$id;
    $pipes = [];
    $child = proc_open([PHP_BINARY,'-n',__FILE__,'child',$engineRoot,(string)$id],[0=>['pipe','r'],1=>['file',$prefix.'.stdout','w'],2=>['file',$prefix.'.stderr','w']],$pipes);
    lockRaceCheck(is_resource($child), 'child could not start');
    $children[] = [$child,$prefix];
    fclose($pipes[0]);
    lockRaceCheck($read($prefix.'.ready')==='ready', 'child readiness handshake failed');
    return [$child,$prefix.'.result'];
};
try {
    lockRaceCheck(flock($signal,LOCK_EX) && flock($main,LOCK_EX), 'parent must own both probe locks');
    [, $resultPath] = $start();
    usleep(200_000);
    clearstatcache(true,$resultPath);
    $premature = is_file($resultPath);
    flock($main,LOCK_UN); flock($signal,LOCK_UN);
    lockRaceCheck(!$premature, 'RED: supervisor startup must wait for the short status probe, not exit as if another supervisor owned it');
    lockRaceCheck($read($resultPath)==='acquired', 'supervisor must acquire after the status probe releases');
    lockRaceCheck(flock($main,LOCK_EX), 'fixture must hold the real supervisor lock');
    [, $resultPath] = $start();
    lockRaceCheck($read($resultPath)==='refused', 'a genuinely active supervisor must still refuse duplicate startup promptly');
    flock($main,LOCK_UN);
    echo "PASS offscreen startup/status race: short probes serialize; active supervisor still refuses duplicates\n";
} finally {
    flock($main,LOCK_UN); flock($signal,LOCK_UN); fclose($main); fclose($signal);
    foreach ($children as [$child,$prefix]) {
        if (proc_get_status($child)['running']) proc_terminate($child);
        proc_close($child);
        foreach (['ready','result','stdout','stderr'] as $suffix) if (is_file($prefix.'.'.$suffix)) unlink($prefix.'.'.$suffix);
    }
    foreach (['lock','signal_lock'] as $name) if (is_file($paths[$name])) unlink($paths[$name]);
    foreach ([$state,dirname($state),$engineRoot.DIRECTORY_SEPARATOR.'log',$engineRoot] as $directory) rmdir($directory);
}
