<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/support/offscreen_dev19_pg_fixture.php';

$fixture = offscreenDev19PgSetup();
$connection = $fixture['connection'];
$store = $fixture['store'];
$engine = $fixture['engine'];
$ts = $fixture['ts'];
$gamets = $fixture['gamets'];

// A mutable same-name ambiguity makes every early source unconstructible without making either actor ineligible.
offscreenDev19PgSql($connection,"UPDATE public.core_npc_master SET npc_name='Camilla' WHERE id=900002");
$invalidMail = [];
for ($i=1; $i<=32; $i++) {
    $invalidMail[] = offscreenDev19PgArrivedMail($engine,$store,900002,900001,'Ambiguous source '.$i.'.',$ts,$gamets);
}
$laterMail = offscreenDev19PgArrivedMail($engine,$store,900003,900001,'A later source with an eligible sender name.',$ts,$gamets);
$readyAt = max((int)$laterMail['arrived_gamets'],(int)$invalidMail[0]['arrived_gamets']) + correspondenceAutonomyGamets(2);
offscreenDev19PgAdvance($engine,$ts,$gamets,max(1,$readyAt-$gamets));

$firstResult = $engine->tick(static function (): string { throw new RuntimeException('invalid_candidate_must_not_call_model'); });
$firstState = offscreenDev19PgSql($connection,"SELECT status,due_gamets::text,arrived_gamets::text,payload->>'reconsiderable' AS reconsiderable,payload->>'reason_code' AS reason_code,payload->>'recheck_after_gamets' AS recheck_after_gamets,payload->'arrival_anchor'->>'rowid' AS arrival_anchor FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=$1",[$invalidMail[0]['message_id']])[0] ?? [];
if ($firstResult['status']!=='idle' || $firstResult['model_calls']!==0) {
    $firstJobs = offscreenDev19PgSql($connection,"SELECT count(*)::int AS amount FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id='unprofiled'");
    fwrite(STDERR, "F3 first-tick diagnostic ".json_encode([
        'result'=>$firstResult,'first_invalid_mail'=>$firstState,'jobs'=>$firstJobs,
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PARTIAL_OUTPUT_ON_ERROR)."\n");
}
offscreenDev19PgCheck($firstResult['status']==='idle' && $firstResult['model_calls']===0,
    'mutable unconstructible arrivals must be skipped before a model claim');
$firstStatePreserved = ($firstState['status'] ?? null)==='arrived'
    && ($firstState['due_gamets'] ?? null)===(string)$invalidMail[0]['due_gamets']
    && ($firstState['arrived_gamets'] ?? null)===(string)$invalidMail[0]['arrived_gamets']
    && ($firstState['reconsiderable'] ?? null)==='true'
    && ($firstState['reason_code'] ?? null)==='no_eligible_contact'
    && (int)($firstState['recheck_after_gamets'] ?? '0')>$gamets
    && ($firstState['arrival_anchor'] ?? null)===($invalidMail[0]['payload_array']['arrival_anchor']['rowid'] ?? null);

$malformedRechecks = [null,'not-a-gamets','-1',str_repeat('9',20)];
$malformedSources = array_slice($invalidMail,1,4);
foreach ($malformedRechecks as $index => $value) {
    if ($value === null) {
        offscreenDev19PgSql($connection,"UPDATE plugins.correspondence_offscreen_mail SET payload=jsonb_set(payload,'{recheck_after_gamets}','null'::jsonb,TRUE) WHERE playthrough_id='unprofiled' AND message_id=$1",[$invalidMail[$index+1]['message_id']]);
    } else {
        offscreenDev19PgSql($connection,"UPDATE plugins.correspondence_offscreen_mail SET payload=jsonb_set(payload,'{recheck_after_gamets}',to_jsonb($2::text),TRUE) WHERE playthrough_id='unprofiled' AND message_id=$1",[$invalidMail[$index+1]['message_id'],$value]);
    }
}

$successful = null;
$malformedAfter = null;
for ($attempt=1; $attempt<=6; $attempt++) {
    offscreenDev19PgAdvance($engine,$ts,$gamets,correspondenceAutonomyGamets(1));
    $calls = 0;
    $result = $engine->tick(static function () use (&$calls): string {
        $calls++;
        return json_encode([
            'action'=>'reply','recipient'=>'npc:900003','body'=>'I appreciate your note.','reason'=>'This response addresses the later source.',
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    });
    if ($result['model_calls']===1) {
        offscreenDev19PgCheck($result['status']==='processed' && $result['reason']==='replied' && $calls===1,
            'the later valid candidate must be processed with one model call');
        $successful = $result;
        break;
    }
    offscreenDev19PgCheck($calls===0 && $result['status']==='idle', 'an invalid mutable candidate must not claim or call the model');
    if ($attempt===1) {
        $malformedAfter = offscreenDev19PgSql($connection,"SELECT message_id::text,status,payload->>'recheck_after_gamets' AS recheck_after_gamets,payload->>'reason_code' AS reason_code FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=ANY($1::uuid[])",['{'.implode(',',array_map(static fn(array $mail): string => $mail['message_id'],$malformedSources)).'}']);
    }
}

$sourceIds = '{'.implode(',',array_map(static fn(array $mail): string => $mail['message_id'],$invalidMail)).'}';
$invalidRows = offscreenDev19PgSql($connection,"SELECT message_id::text,status,due_gamets::text,arrived_gamets::text,payload->>'reconsiderable' AS reconsiderable,payload->>'reason_code' AS reason_code FROM plugins.correspondence_offscreen_mail WHERE playthrough_id='unprofiled' AND message_id=ANY($1::uuid[])",[$sourceIds]);
$expectedInvalid = [];
foreach ($invalidMail as $mail) $expectedInvalid[$mail['message_id']] = ['due_gamets'=>(string)$mail['due_gamets'],'arrived_gamets'=>(string)$mail['arrived_gamets']];
$allInvalidPreserved = count($invalidRows)===32;
foreach ($invalidRows as $row) {
    $expected = $expectedInvalid[$row['message_id'] ?? ''] ?? null;
    // The first due recheck repairs malformed schedule metadata. A later due pass
    // revalidates the still-ambiguous profile and replaces the reason with this diagnosis.
    $allInvalidPreserved = $allInvalidPreserved && $expected !== null
        && ($row['status'] ?? null)==='arrived'
        && ($row['reconsiderable'] ?? null)==='true'
        && ($row['reason_code'] ?? null)==='no_eligible_contact'
        && ($row['due_gamets'] ?? null)===$expected['due_gamets']
        && ($row['arrived_gamets'] ?? null)===$expected['arrived_gamets'];
}
$laterJob = offscreenDev19PgSql($connection,"SELECT actor_id::text,status,action FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id='unprofiled' AND source_message_id=$1",[$laterMail['message_id']]);
$malformedSourceIds = '{'.implode(',',array_map(static fn(array $mail): string => $mail['message_id'],array_slice($invalidMail,1,4))).'}';
$malformedJobs = offscreenDev19PgSql($connection,"SELECT count(*)::int AS amount FROM plugins.correspondence_offscreen_jobs WHERE playthrough_id='unprofiled' AND source_message_id=ANY($1::uuid[])",[$malformedSourceIds]);

offscreenDev19PgCheck($successful!==null,
    'later valid mail must progress despite more than 16 earlier mutable arrivals');
offscreenDev19PgCheck($firstStatePreserved && $allInvalidPreserved,
    'mutable ambiguity must remain arrived with unchanged anchor-bound schedule and a due recheck marker');
offscreenDev19PgCheck(count($malformedAfter ?? [])===4,
    'malformed reconsideration metadata must be selected for safe revalidation');
foreach ($malformedAfter ?? [] as $row) offscreenDev19PgCheck(($row['status'] ?? null)==='arrived'
    && preg_match('/\A[0-9]{1,19}\z/D',(string)($row['recheck_after_gamets'] ?? ''))===1,
    'null, text, negative, and oversized advisory timestamps must be normalized without source effects');
foreach ($malformedAfter ?? [] as $row) offscreenDev19PgCheck(($row['reason_code'] ?? null)==='recheck_metadata_invalid',
    'malformed advisory timestamps must be repaired with the dedicated reason before source validation');
offscreenDev19PgCheck(count($laterJob)===1 && ($laterJob[0]['actor_id'] ?? null)==='900001'
    && ($laterJob[0]['status'] ?? null)==='sent' && ($laterJob[0]['action'] ?? null)==='reply',
    'the later valid mail must not be starved behind reconsiderable arrivals');
offscreenDev19PgCheck((int)($malformedJobs[0]['amount'] ?? -1)===0,
    'malformed advisory timestamps cannot bypass source, actor, or prompt construction checks and create claims');

echo "PASS dev19 F3 PostgreSQL fixture: mutable construction failures are recheckable and cannot pin the 16-row candidate window\n";
