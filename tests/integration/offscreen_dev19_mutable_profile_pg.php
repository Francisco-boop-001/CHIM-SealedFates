<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/support/offscreen_dev19_pg_fixture.php';

$fixture = offscreenDev19PgSetup();
$connection = $fixture['connection'];
$store = $fixture['store'];
$engine = $fixture['engine'];
$ts = $fixture['ts'];
$gamets = $fixture['gamets'];

$mail = offscreenDev19PgArrivedMail($engine,$store,900002,900001,'A note that must wait for its recipient profile to recover.',$ts,$gamets);
offscreenDev19PgSql($connection,"UPDATE public.core_npc_master SET extended_data='[]'::jsonb WHERE id=900001");
offscreenDev19PgAdvance($engine,$ts,$gamets,correspondenceAutonomyGamets(2));

$calls = 0;
$result = $engine->tick(static function () use (&$calls): string {
    $calls++;
    throw new RuntimeException('invalid_profile_must_not_call_model');
});
$afterDeferral = offscreenDev19PgSql($connection,"SELECT status,created_gamets::text,due_gamets::text,arrived_gamets::text,source_event_id::text,source_event_sha256,payload->>'reconsiderable' AS reconsiderable,payload->>'reason_code' AS reason_code,payload->>'recheck_after_gamets' AS recheck_after_gamets,payload->'creation_anchor' AS creation_anchor,payload->'arrival_anchor' AS arrival_anchor FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=$1",[$mail['message_id']])[0] ?? [];
$jobs = offscreenDev19PgSql($connection,"SELECT count(*)::int AS amount FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id='unprofiled' AND source_message_id=$1",[$mail['message_id']]);
$deferred = $result['status']==='idle' && $result['model_calls']===0 && $calls===0
    && ($afterDeferral['status'] ?? null)==='arrived'
    && ($afterDeferral['created_gamets'] ?? null)===(string)$mail['created_gamets']
    && ($afterDeferral['due_gamets'] ?? null)===(string)$mail['due_gamets']
    && ($afterDeferral['arrived_gamets'] ?? null)===(string)$mail['arrived_gamets']
    && ($afterDeferral['source_event_id'] ?? null)===null
    && ($afterDeferral['source_event_sha256'] ?? null)===null
    && ($afterDeferral['reconsiderable'] ?? null)==='true'
    && ($afterDeferral['reason_code'] ?? null)==='profile_construction_failed'
    && (int)($afterDeferral['recheck_after_gamets'] ?? '0')>$gamets
    && correspondenceCanonicalJson(json_decode((string)($afterDeferral['creation_anchor'] ?? ''),true,64,JSON_THROW_ON_ERROR))===correspondenceCanonicalJson($mail['payload_array']['creation_anchor'])
    && correspondenceCanonicalJson(json_decode((string)($afterDeferral['arrival_anchor'] ?? ''),true,64,JSON_THROW_ON_ERROR))===correspondenceCanonicalJson($mail['payload_array']['arrival_anchor'])
    && (int)($jobs[0]['amount'] ?? -1)===0;
if (!$deferred) {
    fwrite(STDERR, "Mutable-profile deferral diagnostic ".json_encode([
        'result'=>$result,'model_calls'=>$calls,'mail'=>$afterDeferral,'jobs'=>$jobs,
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PARTIAL_OUTPUT_ON_ERROR)."\n");
}
offscreenDev19PgCheck($deferred,
    'malformed actor settings must remain arrived and recheckable without consuming a model claim');
$retryDeadline = (string)($afterDeferral['recheck_after_gamets'] ?? '0');
offscreenDev19PgAdvance($engine,$ts,$gamets,1);
$backoffTick = $engine->tick(static function () use (&$calls): string {
    $calls++;
    throw new RuntimeException('invalid_profile_retry_backoff_must_not_call_model');
});
$afterBackoff = offscreenDev19PgSql($connection,"SELECT payload->>'recheck_after_gamets' AS recheck_after_gamets,payload->>'reason_code' AS reason_code,status FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=$1",[$mail['message_id']])[0] ?? [];
$backoffPreserved = $backoffTick['status']==='idle' && $backoffTick['model_calls']===0 && $calls===0
    && ($afterBackoff['status'] ?? null)==='arrived'
    && ($afterBackoff['reason_code'] ?? null)==='profile_construction_failed'
    && ($afterBackoff['recheck_after_gamets'] ?? null)===$retryDeadline;
offscreenDev19PgCheck($backoffPreserved,
    'malformed profile retry deadlines must not slide forward on every native tick');

offscreenDev19PgSql($connection,"UPDATE public.core_npc_master SET extended_data=$1::jsonb WHERE id=900001",[
    '{"background_life_enabled":true,"background_life_letters":true,"background_life_goals":"","relationships":{"Sigrid":{"aff":-31.5}}}',
]);
$recheckAt = (int)$afterDeferral['recheck_after_gamets'];
offscreenDev19PgAdvance($engine,$ts,$gamets,max(1,$recheckAt-$gamets));
$calls = 0;
$retried = $engine->tick(static function () use (&$calls): string {
    $calls++;
    return json_encode([
        'action'=>'reply','recipient'=>'npc:900002','body'=>'Thank you for writing.','reason'=>'I wanted to answer after reading your note.',
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
});
offscreenDev19PgCheck($retried['status']==='processed' && $retried['model_calls']===1 && $calls===1,
    'a repaired profile must be revalidated and may process on a later tick');

echo "PASS dev19 mutable profile PostgreSQL fixture: malformed still-present actor settings defer without retirement or claim\n";
