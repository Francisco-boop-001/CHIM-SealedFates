<?php
declare(strict_types=1);
require_once __DIR__.'/runtime.php';
function correspondenceAccess(array $server):bool
{
    if(is_string($server['REMOTE_USER']??null)&&trim($server['REMOTE_USER'])!=='')return true;
    foreach(['HTTP_FORWARDED','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP'] as $header)if(array_key_exists($header,$server))return false;
    if(!in_array($server['REMOTE_ADDR']??null,['127.0.0.1','::1'],true)||!is_string($server['HTTP_HOST']??null))return false;
    if(preg_match('/\A(?:localhost|127\.0\.0\.1|\[::1\])(?::([0-9]{1,5}))?\z/iD',$server['HTTP_HOST'],$matches)!==1)return false;
    if(($matches[1]??'')!==''){$port=(int)$matches[1];if($port<1||$port>65535)return false;}
    return true;
}
function correspondenceAuthorityParts(string $authority):?array
{
    $label='[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?';
    if(preg_match('/\A(?:\[([0-9a-f:.]+)\]|('.$label.'(?:\.'.$label.')*\.?))(?::([0-9]{1,5}))?\z/iD',$authority,$matches)!==1)return null;
    if(($matches[1]??'')!==''){$packed=inet_pton($matches[1]);if(!is_string($packed))return null;$host='ipv6:'.bin2hex($packed);}
    else{$host=strtolower($matches[2]);if(strlen($host)>254||(preg_match('/\A[0-9.]+\z/D',$host)===1&&filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)===false))return null;$host='dns:'.$host;}
    $port=null;if(($matches[3]??'')!==''){$port=(int)$matches[3];if($port<1||$port>65535)return null;}
    return ['host'=>$host,'port'=>$port];
}
function correspondencePostOriginAllowed(array $server):bool
{
    if(array_key_exists('HTTP_SEC_FETCH_SITE',$server)){
        if(!is_string($server['HTTP_SEC_FETCH_SITE']))return false;
        $site=strtolower(trim($server['HTTP_SEC_FETCH_SITE']));
        if(!in_array($site,['same-origin','same-site','cross-site','none'],true)||$site==='cross-site')return false;
    }
    // Missing Origin remains protected by the required session CSRF token.
    if(!array_key_exists('HTTP_ORIGIN',$server))return true;
    if(!is_string($server['HTTP_ORIGIN'])||preg_match('/\A(https?):\/\/(.+)\z/iD',$server['HTTP_ORIGIN'],$origin)!==1)return false;
    $scheme=strtolower($origin[1]);
    $https=$server['HTTPS']??'';if(!is_string($https))return false;$https=strtolower($https);
    if($https===''||$https==='off')$requestScheme='http';elseif(in_array($https,['on','1'],true))$requestScheme='https';else return false;
    if($scheme!==$requestScheme)return false;
    $port=$server['SERVER_PORT']??null;if(!is_string($port)&&!is_int($port))return false;$port=(string)$port;
    if(preg_match('/\A[0-9]{1,5}\z/D',$port)!==1)return false;$port=(int)$port;if($port<1||$port>65535)return false;
    if(!is_string($server['HTTP_HOST']??null))return false;
    $requestAuthority=correspondenceAuthorityParts($server['HTTP_HOST']);$originAuthority=correspondenceAuthorityParts($origin[2]);
    if($requestAuthority===null||$originAuthority===null||($requestAuthority['port']!==null&&$requestAuthority['port']!==$port))return false;
    $originPort=$originAuthority['port']??($scheme==='https'?443:80);
    return $originAuthority['host']===$requestAuthority['host']&&$originPort===$port;
}
function correspondenceHtml(mixed $value):string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function correspondencePost(array $post,string $csrf,array $choices):array
{
    if(!is_string($post['csrf']??null)||$csrf===''||!hash_equals($csrf,$post['csrf']))throw new DomainException('csrf_failed');
    $action=$post['action']??null;
    if(in_array($action,['dispatch','reconcile','read','retry','close'],true)){
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
    if(!in_array($request['action']??null,['dispatch','retry','close'],true))return $request;
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
    $h='correspondenceHtml';
    $token=$h($csrf);
    $options='';
    foreach($actors as $actor)$options.='<option value="'.$h($actor['key']).'">'.$h($actor['name']).' ('.$h($actor['key']).')</option>';
    $items='<option value="">No attachment</option>';
    foreach($choices as $item)$items.='<option value="'.$h($item['form_id']).'">'.$h($item['name']).' — '.$h($item['form_id']).'</option>';

    $rows='';
    foreach($letters as $letter){
        $social=[];
        foreach(($letter['social_status']??[]) as $consumer=>$status)$social[]=str_replace('_',' ',$consumer).': '.$status['status'].' ('.str_replace('_',' ',$status['reason']).')';
        $socialText=($letter['state']??null)==='identity_changed'?'Identity changed; actions unavailable':($social===[]?($letter['recipient']['kind']==='player'?'NPC interpretation not applicable':'Awaiting recipient exposure'):implode('; ',$social));
        $id=$h($letter['document_id']);
        $managedChild=in_array($letter['document_id'],$managedChildren,true);
        $closed=($letter['operator_closed']??false)===true;
        $rows.='<tr><td>'.$h($letter['claimed_sender']['name']).'</td><td>'.$h($letter['recipient']['name']).'</td><td>'.$h($letter['state']).'</td><td>'.$h($socialText).'</td><td><form method="post"><input type="hidden" name="csrf" value="'.$token.'"><input type="hidden" name="document_id" value="'.$id.'">';
        $rows.='<button name="action" value="read">Open letter</button>';
        if($closed)$rows.=($letter['operator_close_evidence_unavailable']??false)===true?'<span>Closure evidence unavailable; remains closed.</span>':'<span>Closed by operator; delivery outcome unchanged.</span>';
        elseif(!$managedChild&&$letter['recipient']['kind']==='player'&&$letter['state']==='failed')$rows.='<button name="action" value="retry">Retry failed letter (new operation)</button>';
        elseif($letter['state']==='queued'&&$managedChild)$rows.='<span>Awaiting autonomous scheduler</span>';
        elseif($letter['state']==='queued')$rows.='<button name="action" value="dispatch">Dispatch queued letter</button>';
        if(in_array($letter['state'],['unknown','dispatched'],true))$rows.='<button name="action" value="reconcile">Check outcome (read only)</button>';
        if(!$closed&&!$managedChild&&in_array($letter['state'],['queued','dispatched','unknown','failed'],true))$rows.='<button name="action" value="close">Close without resending or refunding</button>';
        $rows.='</form></td></tr>';
    }
    if($rows==='')$rows='<tr><td colspan="5" class="empty-state">No letters yet.</td></tr>';

    $attachmentInputs='';
    for($i=0;$i<8;$i++)$attachmentInputs.='<div class="attachment"><label>Attachment '.($i+1).'<select name="item[]">'.$items.'</select></label><label>Quantity<input name="quantity[]" type="number" min="0" max="2147483647" value="0"></label></div>';

    $read='';
    if($opened!==null){
        $attachments='';
        foreach($opened['attachments'] as $item)$attachments.='<li>'.$h($item['quantity']).' × '.$h($item['form_id']).'</li>';
        $read='<section class="letter-read" aria-labelledby="opened-letter-title"><h2 id="opened-letter-title">From '.$h($opened['claimed_sender']['name']).' to '.$h($opened['recipient']['name']).'</h2><p>Outcome: '.$h($opened['state']).'</p><p class="body">'.$h($opened['body']).'</p><ul>'.$attachments.'</ul><small>Operator view only; opening this letter does not expose it to an NPC.</small></section>';
    }

    $autonomyEnabled=($autonomySettings['enabled']??false)===true;
    $autonomyStatus=$autonomyEnabled?'Enabled':'Paused';
    $autonomyRowsHtml=correspondenceAutonomyRowsHtml($autonomyRows);
    if($autonomyRowsHtml==='')$autonomyRowsHtml='<tr><td colspan="6" class="empty-state">No automated outcomes yet.</td></tr>';
    $autonomy='<section aria-labelledby="autonomy-title"><h2 id="autonomy-title">Autonomous correspondence</h2><p>State: '.$h($autonomyStatus).'. NPCs with Background Life and Letters enabled can initiate and answer offscreen text mail while fresh game-clock observations arrive. Physical letters keep their confirmed delivery path. Browser viewing does not create NPC exposure.</p><form method="post" class="button-row"><input type="hidden" name="csrf" value="'.$token.'"><input type="hidden" name="action" value="autonomy"><button name="enabled" value="1">Enable</button><button name="enabled" value="0">Pause</button></form><h3 id="autonomy-outcomes-title">Automated outcomes</h3><div class="table-wrap" tabindex="0" role="region" aria-labelledby="autonomy-outcomes-title"><table aria-labelledby="autonomy-outcomes-title"><thead><tr><th scope="col">Source document</th><th scope="col">Child document</th><th scope="col">Outcome</th><th scope="col">Action</th><th scope="col">Delivery timing</th><th scope="col">Reason</th></tr></thead><tbody>'.$autonomyRowsHtml.'</tbody></table></div></section>';

    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sealed Fates</title><link rel="stylesheet" href="assets/style.css"></head><body><a class="skip-link" href="#main-content">Skip to main content</a><main class="page" id="main-content"><header class="hero"><div class="hero-copy"><h1>Sealed Fates</h1><p class="subtitle">Part of the World of Drama-Llama</p><p class="hero-note">The new Skyrim interface is optional; this web page still works. The courier is required to cope.</p><p class="lede">Write a letter as any discovered sender. Actual author and attachment payer are private operator choices. The signature is a claim; attachments come from the payer’s real inventory.</p></div><figure class="hero-art"><img src="dashboard-art.webp" alt="Nordic woodcut scene with a courier carrying letters, an affectionate woman and an outraged man below an emo llama over a doorway." width="1536" height="1024"></figure></header><p class="status-message" role="status">'.$h($message).'</p>'.$autonomy.$read.'<section aria-labelledby="mailbox-title"><h2 id="mailbox-title">Inbox and outbox</h2><div class="table-wrap" tabindex="0" role="region" aria-labelledby="mailbox-title"><table aria-labelledby="mailbox-title"><thead><tr><th scope="col">Claimed sender</th><th scope="col">Recipient</th><th scope="col">Outcome</th><th scope="col">Social interpretation</th><th scope="col">Action</th></tr></thead><tbody>'.$rows.'</tbody></table></div></section><section aria-labelledby="compose-title"><h2 id="compose-title">Compose a letter</h2><form method="post"><input type="hidden" name="csrf" value="'.$token.'"><input type="hidden" name="action" value="compose"><input type="hidden" name="request_id" value="'.$h(correspondenceUuid()).'"><div class="identities"><label>Recipient<select name="recipient">'.$options.'</select></label><label>Claimed sender (signature)<select name="claimed_sender">'.$options.'</select></label><label>Actual author (private)<select name="author">'.$options.'</select></label><label>Attachment payer (private)<select name="payer">'.$options.'</select></label></div><label>Original letter text<textarea name="body" maxlength="2000" required></textarea></label><small>Up to 2000 characters. Preserve any terms or promises in the original wording.</small><fieldset><legend>Optional attachments</legend><p>Items listed below were observed by CHIM. Availability and protected items are checked in game before transfer.</p><div class="attachment-grid">'.$attachmentInputs.'</div></fieldset><button type="submit">Send letter</button></form><p><small>Possession, context exposure, and social interpretation are separate outcomes. Pending or unknown outcomes are never automatically sent again.</small></p></section></main></body></html>';
}
function correspondenceAccessDeniedPage(array $server):string
{
    $authority=is_string($server['HTTP_HOST']??null)?correspondenceAuthorityParts($server['HTTP_HOST']):null;
    $host=$authority['host']??'';
    $local=in_array($host,['dns:localhost','dns:127.0.0.1','ipv6:00000000000000000000000000000001'],true);
    if(str_starts_with($host,'dns:')){
        $ip=substr($host,4);
        $local=$local||(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)!==false&&filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE)===false);
    }
    $https=$server['HTTPS']??'';
    $direct=count(array_intersect(['HTTP_FORWARDED','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP'],array_keys($server)))===0;
    $path=$server['SCRIPT_NAME']??null;
    $navigation='<p>No localhost link can be determined safely for this address. Open your configured local CHIM address manually, or use authenticated operator access.</p>';
    // Offer navigation only; a private address never grants operator access.
    if($local&&$direct&&is_string($https)&&in_array(strtolower($https),['','off'],true)&&is_string($path)&&preg_match('/\A((?:\/[A-Za-z0-9_-]+)*)\/ext\/correspondence\/index\.php\z/D',$path,$matches)===1){
        $port=$authority['port']??80;
        $url='http://localhost'.($port===80?'':':'.$port).$matches[1].'/ui/server_plugins.php';
        $navigation='<p><a target="_top" href="'.correspondenceHtml($url).'">Open CHIM Plugins through localhost</a></p><p>This link uses the same port and installation path as this request. If it does not reach your CHIM instance, use your configured local address.</p>';
    }
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sealed Fates</title><link rel="stylesheet" href="assets/style.css"></head><body><main class="page page-error" id="main-content"><h1>Sealed Fates</h1><section aria-labelledby="error-title"><h2 id="error-title">Operator access required</h2><p class="notice" role="alert">This page requires a local connection or an operator authenticated by the web server.</p><p>On the computer running CHIM, open CHIM itself through <strong>localhost</strong>, choose <strong>Plugins</strong>, then press <strong>Sealed Fates &rarr; Plugin Page</strong>. The button follows the address used to open CHIM; bookmark the localhost address for next time.</p>'.$navigation.'<p>No redirect happens automatically, and following a link does not resend a letter.</p><p>Using another computer? Localhost points to that computer, not your CHIM server. Use a server address with web-server-authenticated operator access instead. If your local route is still refused, ask the server administrator to check the connection and authentication; do not disable the access checks.</p></section></main></body></html>';
}
function correspondenceErrorPage(string $message):string
{
    $h='correspondenceHtml';
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sealed Fates</title><link rel="stylesheet" href="assets/style.css"></head><body><main class="page page-error" id="main-content"><h1>Sealed Fates</h1><section aria-labelledby="error-title"><h2 id="error-title">Request unavailable</h2><p class="notice" role="alert">'.$h($message).'</p><p><a href="index.php">Return to Sealed Fates</a></p></section></main></body></html>';
}
