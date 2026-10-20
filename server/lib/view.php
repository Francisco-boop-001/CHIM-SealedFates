<?php
declare(strict_types=1);
require_once __DIR__.'/runtime.php';
function correspondenceAccess(array $server):bool
{
    if(is_string($server['REMOTE_USER']??null)&&trim($server['REMOTE_USER'])!=='')return true;
    foreach(['HTTP_FORWARDED','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP'] as $header)if(array_key_exists($header,$server))return false;
    return in_array($server['REMOTE_ADDR']??null,['127.0.0.1','::1'],true);
}
function correspondenceHtml(mixed $value):string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function correspondencePost(array $post,string $csrf,array $choices):array
{
    if(!is_string($post['csrf']??null)||$csrf===''||!hash_equals($csrf,$post['csrf']))throw new DomainException('csrf_failed');
    $action=$post['action']??null;
    if(in_array($action,['dispatch','reconcile','read','retry'],true)){
        correspondenceKeys($post,['csrf','action','document_id']);return ['action'=>$action,'document_id'=>correspondenceUuidCheck($post['document_id'])];
    }
    if($action==='autonomy'){
        correspondenceKeys($post,['csrf','action','enabled']);
        if(!is_string($post['enabled'])||!in_array($post['enabled'],['0','1'],true))throw new InvalidArgumentException('invalid_enabled');
        return ['action'=>'autonomy','enabled'=>$post['enabled']==='1'];
    }
    if($action!=='compose')throw new DomainException('invalid_action');
    correspondenceKeys($post,['csrf','action','request_id','author','payer','recipient','claimed_sender','body','item','quantity']);
    if(!is_array($post['item'])||!array_is_list($post['item'])||!is_array($post['quantity'])||!array_is_list($post['quantity'])||count($post['item'])!==count($post['quantity'])||count($post['item'])>8)throw new InvalidArgumentException('invalid_attachments');
    $items=[];
    foreach($post['item'] as $i=>$key){
        $quantity=$post['quantity'][$i];if($key===''&&($quantity===''||$quantity==='0'))continue;
        if(!is_string($key)||!is_string($quantity)||preg_match('/\A[1-9][0-9]{0,9}\z/D',$quantity)!==1||(float)$quantity>2147483647)throw new InvalidArgumentException('invalid_quantity');
        $matches=array_values(array_filter($choices,fn($item)=>$item['form_id']===$key));if(count($matches)!==1)throw new InvalidArgumentException('item_unavailable');
        $items[]=['kind'=>$matches[0]['kind'],'form_id'=>$key,'quantity'=>(int)$quantity];
    }
    $input=array_intersect_key($post,array_flip(['request_id','author','payer','recipient','claimed_sender','body']));$input['attachments']=$items;
    return ['action'=>'compose','input'=>$input];
}
function correspondenceOperatorRead(array $letters,string $id):array
{
    correspondenceUuidCheck($id);
    foreach($letters as $letter){
        if(!is_array($letter)||($letter['document_id']??null)!==$id)continue;
        if(!is_array($letter['recipient']??null)||!is_array($letter['claimed_sender']??null)||!is_string($letter['body']??null)||!is_array($letter['attachments']??null))throw new DomainException('document_unavailable');
        correspondenceBody($letter['body']);
        return ['document_id'=>$id,'state'=>(string)($letter['state']??'unknown'),'recipient'=>$letter['recipient'],'claimed_sender'=>$letter['claimed_sender'],'body'=>$letter['body'],'attachments'=>$letter['attachments']];
    }
    throw new DomainException('document_unavailable');
}
function correspondenceAutonomyGuardManualDispatch(array $request,array $managedChildren):array
{
    if(($request['action']??null)!=='dispatch')return $request;
    if(!array_is_list($managedChildren))throw new DomainException('autonomy_status_unavailable');
    foreach($managedChildren as$id)if(!is_string($id)||preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D',$id)!==1)throw new DomainException('autonomy_status_unavailable');
    if(in_array($request['document_id']??null,$managedChildren,true))throw new DomainException('autonomous_dispatch_owned');
    return $request;
}
function correspondenceAutonomyRowsHtml(array $rows):string
{
    $h='correspondenceHtml';$html='';
    foreach(array_slice(array_is_list($rows)?$rows:[],0,20) as $row){
        if(!is_array($row))continue;
        $source=is_string($row['source_document_id']??null)&&preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D',$row['source_document_id'])===1?$row['source_document_id']:'';
        $child=is_string($row['child_document_id']??null)&&preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D',$row['child_document_id'])===1?$row['child_document_id']:'';
        $status=in_array($row['status']??null,['claimed','silent','queued','dispatched','failed','unknown','stale'],true)?$row['status']:'unknown';
        $action=in_array($row['action']??null,['none','reply','forward','mediate'],true)?$row['action']:'';
        $reason=is_string($row['reason']??null)&&preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D',$row['reason'])===1?str_replace('_',' ',$row['reason']):'';
        $due=is_int($row['due_gamets']??null)&&$row['due_gamets']>=0?'Location-paced game-time target '.$row['due_gamets'].' gamets':'No delivery target';
        $html.='<tr><td>'.$h($source).'</td><td>'.$h($child).'</td><td>'.$h($status).'</td><td>'.$h($action).'</td><td>'.$h($due).'</td><td>'.$h($reason).'</td></tr>';
    }
    return $html;
}
function correspondencePage(array $actors,array $choices,array $letters,string $csrf,string $message='',?array $opened=null,array $autonomySettings=[],array $autonomyRows=[],array $managedChildren=[]):string
{
    $h='correspondenceHtml';$token=$h($csrf);$options='';
    foreach($actors as $actor)$options.='<option value="'.$h($actor['key']).'">'.$h($actor['name']).' ('.$h($actor['key']).')</option>';
    $items='<option value="">No attachment</option>';foreach($choices as $item)$items.='<option value="'.$h($item['form_id']).'">'.$h($item['name']).' — '.$h($item['form_id']).'</option>';
    $rows='';foreach($letters as $letter){
        $social=[];foreach(($letter['social_status']??[])as$consumer=>$status)$social[]=str_replace('_',' ',$consumer).': '.$status['status'].' ('.str_replace('_',' ',$status['reason']).')';$socialText=($letter['state']??null)==='identity_changed'?'Identity changed; actions unavailable':($social===[]?($letter['recipient']['kind']==='player'?'NPC interpretation not applicable':'Awaiting recipient exposure'):implode('; ',$social));
        $id=$h($letter['document_id']);$managedChild=in_array($letter['document_id'],$managedChildren,true);$rows.='<tr><td>'.$h($letter['claimed_sender']['name']).'</td><td>'.$h($letter['recipient']['name']).'</td><td>'.$h($letter['state']).'</td><td>'.$h($socialText).'</td><td><form method="post"><input type="hidden" name="csrf" value="'.$token.'"><input type="hidden" name="document_id" value="'.$id.'">';
        $rows.='<button name="action" value="read">Open letter</button>';
        if($letter['recipient']['kind']==='player'&&$letter['state']==='failed')$rows.='<button name="action" value="retry">Retry failed letter (new operation)</button>';
        elseif($letter['state']==='queued'&&$managedChild)$rows.='<span>Awaiting autonomous scheduler</span>';
        elseif($letter['state']==='queued')$rows.='<button name="action" value="dispatch">Dispatch queued letter</button>';
        elseif(in_array($letter['state'],['unknown','dispatched'],true))$rows.='<button name="action" value="reconcile">Check outcome (read only)</button>';
        $rows.='</form></td></tr>';
    }
    $attachmentInputs='';for($i=0;$i<8;$i++)$attachmentInputs.='<div class="attachment"><label>Attachment '.($i+1).'<select name="item[]">'.$items.'</select></label><label>Quantity<input name="quantity[]" type="number" min="0" max="2147483647" value="0"></label></div>';
    $read='';if($opened!==null){$attachments='';foreach($opened['attachments']as$item)$attachments.='<li>'.$h($item['quantity']).' × '.$h($item['form_id']).'</li>';$read='<section><h2>From '.$h($opened['claimed_sender']['name']).' to '.$h($opened['recipient']['name']).'</h2><p>Outcome: '.$h($opened['state']).'</p><p class="body">'.$h($opened['body']).'</p><ul>'.$attachments.'</ul><small>Operator view only; opening this letter does not expose it to an NPC.</small></section>';}
    $autonomyEnabled=($autonomySettings['enabled']??false)===true;$autonomyRowsHtml=correspondenceAutonomyRowsHtml($autonomyRows);$autonomyStatus=$autonomyEnabled?'Enabled':'Paused';
    $autonomy='<section><h2>Autonomous correspondence</h2><p>State: '.$h($autonomyStatus).'. NPCs with Background Life and Letters enabled can initiate and answer offscreen text mail while fresh game-clock observations arrive. Physical letters keep their confirmed delivery path. Browser viewing does not create NPC exposure.</p><form method="post"><input type="hidden" name="csrf" value="'.$token.'"><input type="hidden" name="action" value="autonomy"><button name="enabled" value="1">Enable</button><button name="enabled" value="0">Pause</button></form><h3>Automated outcomes</h3><table><thead><tr><th>Source document</th><th>Child document</th><th>Outcome</th><th>Action</th><th>Delivery timing</th><th>Reason</th></tr></thead><tbody>'.$autonomyRowsHtml.'</tbody></table></section>';
    return '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sealed Fates</title><style>body{max-width:65rem;margin:2rem auto;padding:0 1rem;background:#171b20;color:#eef0eb;font:16px system-ui}a{color:#a9d3ff}label{display:block;margin:.7rem 0}input,select,textarea,button{font:inherit;padding:.5rem;border-radius:.3rem;border:1px solid #87929c;background:#252d35;color:#fff}textarea{box-sizing:border-box;width:100%;min-height:12rem}button{cursor:pointer}fieldset{margin:1rem 0;border:1px solid #68737d}.identities,.attachment{display:flex;gap:1rem;flex-wrap:wrap}table{width:100%;border-collapse:collapse}td,th{text-align:left;padding:.6rem;border-bottom:1px solid #68737d}.body{white-space:pre-wrap;overflow-wrap:anywhere}small{color:#c6c9cc}</style><body><h1>Sealed Fates</h1><p>Write a letter as any discovered sender. Actual author and attachment payer are private operator choices. The signature is a claim; attachments come from the payer’s real inventory.</p><p role="status">'.$h($message).'</p>'.$autonomy.$read.'<h2>Inbox and outbox</h2><table><thead><tr><th>Claimed sender</th><th>Recipient</th><th>Outcome</th><th>Social interpretation</th><th>Action</th></tr></thead><tbody>'.$rows.'</tbody></table><h2>Compose a letter</h2><form method="post"><input type="hidden" name="csrf" value="'.$token.'"><input type="hidden" name="action" value="compose"><input type="hidden" name="request_id" value="'.$h(correspondenceUuid()).'"><div class="identities"><label>Recipient<select name="recipient">'.$options.'</select></label><label>Claimed sender (signature)<select name="claimed_sender">'.$options.'</select></label><label>Actual author (private)<select name="author">'.$options.'</select></label><label>Attachment payer (private)<select name="payer">'.$options.'</select></label></div><label>Original letter text<textarea name="body" maxlength="2000" required></textarea></label><small>Up to 2000 characters. Preserve any terms or promises in the original wording.</small><fieldset><legend>Optional attachments</legend><p>Items listed below were observed by CHIM. Availability and protected items are checked in game before transfer.</p>'.$attachmentInputs.'</fieldset><button type="submit">Send letter</button></form><p><small>Possession, context exposure, and social interpretation are separate outcomes. Pending or unknown outcomes are never automatically sent again.</small></p></body></html>';
}


