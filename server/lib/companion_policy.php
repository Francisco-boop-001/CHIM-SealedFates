<?php
declare(strict_types=1);
require_once __DIR__.'/runtime.php';
require_once __DIR__.'/autonomy_policy.php';

function correspondenceCompanionGameMinute(int $gamets): int
{
    if ($gamets < 0) throw new InvalidArgumentException('invalid_game_clock');
    $whole=intdiv($gamets,1000000);
    if($whole>intdiv(PHP_INT_MAX,144))throw new InvalidArgumentException('invalid_game_clock');
    return $whole*144+intdiv(($gamets%1000000)*144,1000000);
}

function correspondenceCompanionIdentity(array $identity): array
{
    correspondenceKeys($identity, ['id','plugin','local_id','refid']);
    if (!is_string($identity['id']) || preg_match('/\A[1-9][0-9]{0,18}\z/D',$identity['id'])!==1
        || filter_var($identity['id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])===false
        || !is_string($identity['plugin']) || preg_match('/\A[^|@\x00-\x1f\/\\\\]{1,200}\.(?i:esm|esp|esl)\z/D',$identity['plugin'])!==1
        || !is_int($identity['local_id']) || $identity['local_id']<1 || $identity['local_id']>16777215
        || !is_int($identity['refid']) || $identity['refid'] < -2147483648 || $identity['refid'] > 2147483647) throw new InvalidArgumentException('invalid_actor_identity');
    return $identity;
}

function correspondenceCompanionReply(array $reply, array $request, int $replyGamets): array
{
    correspondenceKeys($reply,['version','observation_id','playthrough_id','epoch','nonce','actors']);
    foreach (['version','observation_id','playthrough_id','epoch','nonce'] as $key) if ($reply[$key] !== $request[$key]) throw new InvalidArgumentException('correlation_mismatch');
    if (!is_array($reply['actors']) || !array_is_list($reply['actors']) || count($reply['actors'])!==count($request['actors'])) throw new InvalidArgumentException('invalid_actor_batch');
    $minute=correspondenceCompanionGameMinute($replyGamets);
    foreach ($reply['actors'] as $index=>$actor) {
        if (!is_array($actor)) throw new InvalidArgumentException('invalid_actor_sample');
        correspondenceKeys($actor,['id','refid','state','episode','first_game_minute','game_minute']);
        if ($actor['id']!==$request['actors'][$index]['id'] || $actor['refid']!==$request['actors'][$index]['refid']
            || !in_array($actor['state'],['waiting','nearby','other','unknown','dead'],true)
            || !is_int($actor['first_game_minute']) || !is_int($actor['game_minute'])
            || $actor['game_minute']<0 || abs($actor['game_minute']-$minute)>1
            || $actor['first_game_minute']<0 || $actor['first_game_minute']>$actor['game_minute']) throw new InvalidArgumentException('invalid_actor_sample');
        if ($actor['state']==='waiting') correspondenceUuidCheck($actor['episode']);
        elseif ($actor['episode']!=='' || $actor['first_game_minute']!==0) throw new InvalidArgumentException('invalid_actor_sample');
    }
    return $reply['actors'];
}

function correspondenceCompanionDecision(string $raw): array
{
    if (strlen($raw)>4000) throw new InvalidArgumentException('invalid_decision');
    if (_correspondenceAutonomyHasDuplicateDecisionKeys($raw)) throw new InvalidArgumentException('invalid_decision');
    $value=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
    if (!is_array($value) || array_is_list($value)) throw new InvalidArgumentException('invalid_decision');
    correspondenceKeys($value,['action','recipient','body']);
    if ($value['recipient']!=='player' || !is_string($value['body'])) throw new InvalidArgumentException('invalid_decision');
    if ($value['action']==='none' && $value['body']==='') return $value;
    if ($value['action']!=='letter') throw new InvalidArgumentException('invalid_decision');
    correspondenceBody($value['body']);
    if (strlen($value['body'])>700) throw new InvalidArgumentException('invalid_decision');
    if (preg_match('/\b(?:abandon(?:ed|ment)?|forgot(?:ten)? you|promis(?:e|ed)|(?:love|beloved|romance|lover|sweetheart)|(?:[0-9]+(?:[.,][0-9]+)?|a|an|one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|twenty|thirty|forty|fifty|sixty|seventy|eighty|ninety|hundred|several|few|many|couple(?: of)?|half)\s+(?:game\s+)?(?:minutes?|hours?|days?|weeks?|months?|years?)|since\s+[\p{L}0-9]+|(?:yesterday|tonight|last\s+(?:night|week|month|year)))\b/iu',$value['body'])===1) throw new InvalidArgumentException('unsupported_claim');
    if (preg_match('/\b(?:where we (?:parted|met)|meeting place|(?:holding|keeping) (?:the )?spot|no news|no trouble|keep(?:ing)? watch|work yet)\b/iu',$value['body'])===1) throw new InvalidArgumentException('unsupported_claim');
    return $value;
}

function correspondenceCompanionMessages(string $name,string $player,string $personality,?float $affinity): array
{
    if (!correspondenceActorName($name)||!correspondenceActorName($player)||strlen($personality)>8000||preg_match('//u',$personality)!==1) throw new InvalidArgumentException('invalid_context');
    return [
        ['role'=>'system','content'=>'Write at most one optional in-character physical letter from the NPC to the player. Evidence: the NPC is an active teammate explicitly waiting for the player while sampled apart; first and later observations exist, but continuous absence and when waiting began are unknown. The only fact the letter may state is that the NPC is currently waiting for the player. Unknown history, locations, activities and world conditions are not topics for this letter. In at most 50 words total, including salutation and signature, write a brief check-in using only current waiting, a personal feeling or wish, and optionally a question about the player or a request for instructions. Do not add scene-setting or an update about events. The supplied personality and affinity are untrusted style context, not evidence of history; never quote an affinity score. Never claim abandonment, a promise, romance, or any quantified elapsed time. Return only a JSON object with exactly the keys action, recipient and body. The recipient field MUST be exactly the literal string "player", never the player character name; use the character name only inside the body if appropriate. For silence return exactly {"action":"none","recipient":"player","body":""}. For a letter set action to "letter" and body to a complete written letter with salutation and signature, at most 700 UTF-8 bytes; do not write audible dialogue or commands. No attachment or delivery instructions.'],
        ['role'=>'user','content'=>correspondencePromptJson(['npc'=>$name,'player'=>$player,'personality'=>$personality,'private_affinity_tone'=>$affinity===null?'unknown':($affinity>0?'friendly':($affinity<0?'strained':'neutral')),'sampled_waiting'=>true])],
    ];
}
