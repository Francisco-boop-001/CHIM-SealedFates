<?php
declare(strict_types=1);

function correspondenceRecoveryFixture(string $database): PgSql\Connection
{
    if (!in_array($database, ['chim_corr_recovery_offscreen', 'chim_corr_recovery_autonomy', 'chim_corr_recovery_store'], true)) {
        throw new RuntimeException('refusing_unowned_recovery_database');
    }
    if (PHP_SAPI !== 'cli' || getenv('WSL_DISTRO_NAME') !== 'DwemerAI4Skyrim3-test') {
        throw new RuntimeException('refusing_outside_registered_disposable_clone');
    }
    if (!function_exists('pg_connect')) throw new RuntimeException('native_postgresql_extension_required');

    $environment = getenv();
    if (!is_array($environment)) throw new RuntimeException('environment_unavailable');
    foreach (array_keys($environment) as $name) {
        if (preg_match('/\APG/i', (string)$name) === 1) throw new RuntimeException('refusing_pg_environment_override');
    }

    $clusterFile = '/mnt/k/ActorwrightExchange/artifacts/chim-sealed-fates-recovery-20261019/private-cluster.json';
    $cluster = json_decode((string)@file_get_contents($clusterFile), true, 16);
    $systemIdentifier = is_array($cluster) ? ($cluster['system_identifier'] ?? null) : null;
    if (!is_string($systemIdentifier) || preg_match('/\A[0-9]{1,20}\z/D', $systemIdentifier) !== 1) {
        throw new RuntimeException('private_cluster_identity_unavailable');
    }

    $connection = @pg_connect(
        'host=/var/tmp/chim-sealed-fates-recovery-20261019/socket port=55441 dbname='.$database.' user=postgres',
        PGSQL_CONNECT_FORCE_NEW
    );
    if (!$connection instanceof PgSql\Connection) throw new RuntimeException('fixture_peer_connection_failed');

    $result = @pg_query($connection, "SELECT current_database() AS name,current_user AS role,current_setting('data_directory') AS data_directory,current_setting('port') AS port,current_setting('unix_socket_directories') AS socket_directory,current_setting('listen_addresses') AS listeners,current_setting('correspondence.disposable_marker',TRUE) AS marker,(inet_server_addr() IS NULL)::int AS socket_only,(pg_control_system()).system_identifier::text AS system_identifier");
    if ($result === false) {
        pg_close($connection);
        throw new RuntimeException('private_cluster_guard_query_failed');
    }
    $guard = pg_fetch_assoc($result) ?: [];
    pg_free_result($result);
    $expected = [
        'name'=>$database,
        'role'=>'postgres',
        'data_directory'=>'/var/tmp/chim-sealed-fates-recovery-20261019/pgdata',
        'port'=>'55441',
        'socket_directory'=>'/var/tmp/chim-sealed-fates-recovery-20261019/socket',
        'listeners'=>'',
        'marker'=>'recovery-20261019-private',
        'socket_only'=>'1',
        'system_identifier'=>$systemIdentifier,
    ];
    if ($guard !== $expected) {
        pg_close($connection);
        throw new RuntimeException('refusing_non_private_fixture_database');
    }
    return $connection;
}
