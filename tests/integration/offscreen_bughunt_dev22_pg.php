<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/support/offscreen_dev19_pg_fixture.php';
$fixture = offscreenDev19PgSetup();
extract($fixture, EXTR_SKIP);
$mode = $argv[1] ?? 'arrival';
$private = static function (string $method, array $args=[]) use (&$engine): mixed { return offscreenDev19PgPrivate($engine,$method,$args); };
$sql = static fn(string $query, array $args=[]): array => offscreenDev19PgSql($connection,$query,$args);
$silent = static function (): string { throw new RuntimeException('this maintenance tick must not invoke a model'); };
$actors = static fn(): array => $private('npcMap',[$private('npcRows')]);
offscreenDev19PgObserve($engine,$ts,$gamets);

if ($mode === 'arrival') {
    $map = $actors();
    $body = 'A whole letter must survive temporarily unreadable recipient flags.';
    $first = $store->transaction(fn() => $private('createMail',['unprofiled',$map[900002],$map[900001],$body,$gamets,null,'letter']));
    offscreenDev19PgAdvance($engine,$ts,$gamets,1);
    $second = $store->transaction(fn() => $private('createMail',['unprofiled',$map[900002],$map[900003],'An unrelated valid letter.',$gamets,null,'letter']));
    $before = $private('mailById',['unprofiled',$first['message_id']]);
    $sql("UPDATE public.core_npc_master SET extended_data=jsonb_set(extended_data,'{background_life_letters}','\"unknown\"') WHERE id=900001");
    offscreenDev19PgAdvance($engine,$ts,$gamets,(int)$second['due_gamets']-$gamets+1);
    $engine->tick($silent);
    $deferred = $private('mailById',['unprofiled',$first['message_id']]);
    offscreenDev19PgCheck($deferred['status']==='in_transit' && $deferred['body']===$body
        && $deferred['payload_array']['creation_anchor']===$before['payload_array']['creation_anchor']
        && ($deferred['payload_array']['reason_code'] ?? null)==='profile_construction_failed'
        && (int)($deferred['payload_array']['recheck_after_gamets'] ?? 0)>$gamets,
        'RED: temporarily unreadable arrival must defer intact rather than become permanently stale');
    $deadline = (int)$deferred['payload_array']['recheck_after_gamets'];
    offscreenDev19PgAdvance($engine,$ts,$gamets,1);
    $engine->tick($silent);
    offscreenDev19PgCheck($private('mailById',['unprofiled',$second['message_id']])['status']==='arrived',
        'a deferred first arrival must not block later valid due mail');
    offscreenDev19PgCheck((int)$private('mailById',['unprofiled',$first['message_id']])['payload_array']['recheck_after_gamets']===$deadline,
        'the arrival retry deadline must not slide on unrelated ticks');
    $sql("UPDATE public.core_npc_master SET extended_data=jsonb_set(extended_data,'{background_life_letters}','true') WHERE id=900001");
    offscreenDev19PgAdvance($engine,$ts,$gamets,$deadline-$gamets);
    $engine->tick($silent);
    offscreenDev19PgCheck($private('mailById',['unprofiled',$first['message_id']])['status']==='arrived',
        'the original letter must arrive after the same profile recovers');
    $terminal = $store->transaction(fn() => $private('createMail',['unprofiled',$actors()[900002],$actors()[900001],'Explicit opt-out stays terminal.',$gamets,null,'letter']));
    $sql("UPDATE public.core_npc_master SET extended_data=jsonb_set(extended_data,'{background_life_letters}','false') WHERE id=900001");
    offscreenDev19PgAdvance($engine,$ts,$gamets,(int)$terminal['due_gamets']-$gamets+1);
    $engine->tick($silent);
    offscreenDev19PgCheck($private('mailById',['unprofiled',$terminal['message_id']])['status']==='stale',
        'an explicit opt-out must not be mistaken for a temporary unreadable flag');
} elseif ($mode === 'restore-initiation') {
    $sql("UPDATE public.core_npc_master SET extended_data=jsonb_set(extended_data,'{background_life_goals}','\"Ask Sigrid about the harvest.\"') WHERE id=900001");
    $result = $engine->tick(static function (): string {
        $GLOBALS['ptr_runtime_generation'] = 'runtime-generation-dev22-restored';
        return '{"action":"letter","recipient":"npc:900002","body":"This old-generation output must be ignored.","reason":"Ask about the harvest."}';
    });
    offscreenDev19PgCheck($result['status']==='stale' && $result['model_calls']===1, 'fixture must interrupt a real initiation claim with restore');
    offscreenDev19PgAdvance($engine,$ts,$gamets,1);
    $engine->tick($silent);
    $row = $sql("SELECT status,payload->>'reason_code' AS reason FROM plugins.correspondence_offscreen_jobs ORDER BY created_at LIMIT 1")[0];
    offscreenDev19PgCheck($row['status']==='stale' && $row['reason']==='restore_changed'
        && $sql('SELECT message_id FROM plugins.correspondence_offscreen_mail')===[],
        'RED: restore must retire an initiation claim without a source letter or a new model call');
} elseif ($mode === 'eligibility') {
    $mail = offscreenDev19PgArrivedMail($engine,$store,900002,900001,'Retained history is not permanent permission to expose mail.',$ts,$gamets);
    $source = $private('sourceForMail',[$mail,$actors()]);
    $store->transaction(fn() => $private('exposeMail',[$mail,$source]));
    $before = $private('mailById',['unprofiled',$mail['message_id']]);
    offscreenDev19PgCheck(str_contains($engine->prompt('Camilla'),$mail['body']), 'eligible sender mail is initially visible');
    foreach (['death','background_life_enabled','background_life_letters'] as $policy) {
        $sql("UPDATE public.core_npc_master SET metadata=jsonb_set(metadata,'{stats}','{}'),extended_data=extended_data||'{\"background_life_enabled\":true,\"background_life_letters\":true}'::jsonb WHERE id=900002");
        if ($policy==='death') $sql("UPDATE public.core_npc_master SET metadata=jsonb_set(metadata,'{stats}','{\"is_dead\":true}') WHERE id=900002");
        else $sql('UPDATE public.core_npc_master SET extended_data=jsonb_set(extended_data,$1::text[],\'false\') WHERE id=900002',['{'.$policy.'}']);
        offscreenDev19PgCheck($engine->prompt('Camilla')==='', 'sender death or explicit opt-out must suppress current prompt exposure');
        offscreenDev19PgCheck($private('mailById',['unprofiled',$mail['message_id']])===$before, 'suppression must preserve the exposed audit row and its full body/anchors');
    }
    $sql("UPDATE public.core_npc_master SET metadata=jsonb_set(metadata,'{stats}','{}'),extended_data=extended_data||'{\"background_life_enabled\":true,\"background_life_letters\":true}'::jsonb WHERE id=900002");
    offscreenDev19PgCheck(str_contains($engine->prompt('Camilla'),$mail['body']), 'restored eligibility must revalidate and show the same retained letter');
} elseif (in_array($mode, ['due-quarantine','arrived-quarantine'], true)) {
    for ($i=0;$i<16;$i++) {
        $mail = offscreenDev19PgArrivedMail($engine,$store,900002,900001,'Older valid history '.$i,$ts,$gamets);
        $source = $private('sourceForMail',[$mail,$actors()]);
        $store->transaction(fn() => $private('exposeMail',[$mail,$source]));
    }
    $map = $actors();
    if ($mode==='due-quarantine') {
        $bad = $store->transaction(fn() => $private('createMail',['unprofiled',$map[900002],$map[900001],'Newest due letter.',$gamets,null,'letter']));
    } else {
        $bad = offscreenDev19PgArrivedMail($engine,$store,900002,900001,'Newest arrived letter.',$ts,$gamets);
    }
    offscreenDev19PgAdvance($engine,$ts,$gamets,1);
    $good = $store->transaction(fn() => $private('createMail',['unprofiled',$map[900002],$map[900003],'Later valid delivery.',$gamets,null,'letter']));
    $sql("UPDATE plugins.correspondence_offscreen_mail SET body='Tampered selected candidate' WHERE message_id=$1",[$bad['message_id']]);
    offscreenDev19PgAdvance($engine,$ts,$gamets,(int)$good['due_gamets']-$gamets+1);
    if ($mode==='arrived-quarantine') {
        // Let the valid due delivery finish first; reset only the scheduling cursor, never its proof.
        $engine->tick($silent);
        offscreenDev19PgCheck($private('mailById',['unprofiled',$good['message_id']])['status']==='arrived',
            'the valid due delivery must progress before checking arrived-source quarantine');
        $sql("UPDATE plugins.correspondence_offscreen_mail SET payload=payload-'validation_checked_at'");
        offscreenDev19PgAdvance($engine,$ts,$gamets,correspondenceAutonomyGamets(2));
    }
    $result = $engine->tick($silent);
    offscreenDev19PgCheck($result['status']==='processed' && $result['reason']==='mail_quarantined'
        && $private('mailById',['unprofiled',$bad['message_id']])['status']==='stale',
        'RED: a corrupt selected candidate beyond the sweep batch must quarantine without rolling back progress');
    offscreenDev19PgCheck((int)$sql("SELECT count(*)::int AS n FROM plugins.correspondence_offscreen_mail WHERE payload ? 'validation_checked_at'")[0]['n']>=16,
        'selected-candidate quarantine must commit the bounded integrity sweep progress');
    if ($mode==='arrived-quarantine') {
        $good = $store->transaction(fn() => $private('createMail',['unprofiled',$map[900002],$map[900003],'Delivery after arrived-source quarantine.',$gamets,null,'letter']));
    }
    $engine = new CorrespondenceOffscreenPg($store);
    offscreenDev19PgAdvance($engine,$ts,$gamets,max(1,(int)$good['due_gamets']-$gamets+1));
    $engine->tick($silent);
    offscreenDev19PgCheck($private('mailById',['unprofiled',$good['message_id']])['status']==='arrived',
        'a fresh worker must progress a later valid delivery after quarantine');
} elseif ($mode === 'sweep') {
    $last = null;
    for ($i=0;$i<20;$i++) {
        $mail = offscreenDev19PgArrivedMail($engine,$store,900002,900001,'A retained complete letter '.$i,$ts,$gamets);
        $source = $private('sourceForMail',[$mail,$actors()]);
        $store->transaction(fn() => $private('exposeMail',[$mail,$source]));
        $last = $mail;
    }
    $sql("UPDATE plugins.correspondence_offscreen_mail SET body='Tampered history' WHERE message_id=$1",[$last['message_id']]);
    $map = $actors();
    $future = $store->transaction(fn() => $private('createMail',['unprofiled',$map[900002],$map[900003],'Future transit also needs eventual quarantine.',$gamets,null,'letter']));
    $sql("UPDATE plugins.correspondence_offscreen_mail SET body='Tampered future' WHERE message_id=$1",[$future['message_id']]);
    offscreenDev19PgAdvance($engine,$ts,$gamets,1);
    $engine->tick($silent);
    $checked = (int)$sql("SELECT count(*)::int AS n FROM plugins.correspondence_offscreen_mail WHERE payload ? 'validation_checked_at'")[0]['n'];
    offscreenDev19PgCheck($checked===16 && $private('mailById',['unprofiled',$last['message_id']])['status']==='exposed',
        'RED: the integrity sweep must validate a bounded first batch instead of scanning all exposed history');
    for ($i=0;$i<3;$i++) {
        $engine = new CorrespondenceOffscreenPg($store);
        offscreenDev19PgAdvance($engine,$ts,$gamets,1);
        $engine->tick($silent);
    }
    offscreenDev19PgCheck($private('mailById',['unprofiled',$last['message_id']])['status']==='stale'
        && $private('mailById',['unprofiled',$future['message_id']])['status']==='stale',
        'durable rotation across fresh workers must eventually quarantine corrupt history and future transit');
    offscreenDev19PgCheck(str_contains($engine->prompt('Camilla'),'A retained complete letter'), 'valid retained mail remains readable while maintenance progresses');
} else throw new InvalidArgumentException('unknown_dev22_case');
echo 'PASS dev22 offscreen '.$mode."\n";
