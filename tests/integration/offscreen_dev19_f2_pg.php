<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/support/offscreen_dev19_pg_fixture.php';

$fixture = offscreenDev19PgSetup();
$connection = $fixture['connection'];
$store = $fixture['store'];
$engine = $fixture['engine'];
$ts = $fixture['ts'];
$gamets = $fixture['gamets'];

$seedMail = offscreenDev19PgArrivedMail($engine,$store,900003,900001,'An earlier source seeds the recipient cooldown.',$ts,$gamets);
$cooledMail = offscreenDev19PgArrivedMail($engine,$store,900002,900001,'The oldest unclaimed source belongs to a cooled recipient.',$ts,$gamets);
$eligibleMail = offscreenDev19PgArrivedMail($engine,$store,900003,900002,'The next source belongs to an available recipient.',$ts,$gamets);
offscreenDev19PgCheck((int)$seedMail['arrived_gamets'] < (int)$cooledMail['arrived_gamets']
    && (int)$cooledMail['arrived_gamets'] < (int)$eligibleMail['arrived_gamets'],
    'dev19_fixture_reaction_arrival_order_failed');
$readyAt = max((int)$seedMail['arrived_gamets'],(int)$cooledMail['arrived_gamets'],(int)$eligibleMail['arrived_gamets']) + correspondenceAutonomyGamets(2);
offscreenDev19PgAdvance($engine,$ts,$gamets,max(1,$readyAt-$gamets));

$actors = offscreenDev19PgPrivate($engine,'npcMap',[offscreenDev19PgPrivate($engine,'npcRows')]);
offscreenDev19PgClaimSilent($engine,$store,$seedMail,$actors);

offscreenDev19PgAdvance($engine,$ts,$gamets,correspondenceAutonomyGamets(1)+1);
$calls = 0;
$result = $engine->tick(static function (array $messages) use (&$calls): string {
    $calls++;
    return json_encode([
        'action'=>'reply','recipient'=>'npc:900003','body'=>'Thank you for writing.','reason'=>'This reply answers the available letter.',
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
});

$jobs = offscreenDev19PgSql($connection,"SELECT actor_id::text,source_message_id::text,status,action FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id='unprofiled' ORDER BY created_at,job_id");
$cooledAfter = offscreenDev19PgSql($connection,"SELECT status,due_gamets::text,arrived_gamets::text,payload->>'reconsiderable' AS reconsiderable,payload->>'reason_code' AS reason_code,payload->>'recheck_after_gamets' AS recheck_after_gamets,payload->'arrival_anchor'->>'rowid' AS arrival_anchor FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=$1",[$cooledMail['message_id']])[0] ?? [];

$firstPass = $result['status']==='processed' && $result['reason']==='replied' && $result['model_calls']===1 && $calls===1;
if (!$firstPass) {
    fwrite(STDERR, "F2 failure diagnostic ".json_encode([
        'result'=>$result,'model_calls'=>$calls,'jobs'=>$jobs,'cooled_mail'=>$cooledAfter,
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PARTIAL_OUTPUT_ON_ERROR)."\n");
}
offscreenDev19PgCheck($firstPass,
    'a cooled oldest reaction recipient must not suppress another eligible reaction');
offscreenDev19PgCheck(count($jobs)===2
    && ($jobs[1]['actor_id'] ?? null)==='900002'
    && ($jobs[1]['source_message_id'] ?? null)===$eligibleMail['message_id']
    && ($jobs[1]['status'] ?? null)==='sent'
    && ($jobs[1]['action'] ?? null)==='reply',
    'the available later source must receive the sole model call');
offscreenDev19PgCheck(($cooledAfter['status'] ?? null)==='arrived'
    && ($cooledAfter['due_gamets'] ?? null)===(string)$cooledMail['due_gamets']
    && ($cooledAfter['arrived_gamets'] ?? null)===(string)$cooledMail['arrived_gamets']
    && ($cooledAfter['reconsiderable'] ?? null)==='true'
    && ($cooledAfter['reason_code'] ?? null)==='actor_cooldown'
    && (int)($cooledAfter['recheck_after_gamets'] ?? '0')>$gamets
    && ($cooledAfter['arrival_anchor'] ?? null)===($cooledMail['payload_array']['arrival_anchor']['rowid'] ?? null),
    'cooldown deferral must preserve the arrived row and immutable arrival/due evidence');

echo "PASS dev19 F2 PostgreSQL fixture: cooled oldest recipient is skipped without consuming the eligible mail slot\n";
