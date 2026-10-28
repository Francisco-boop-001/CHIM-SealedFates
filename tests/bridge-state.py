"""Production Papyrus control flow with deliberately fake native inventory/JDB operations.
No Skyrim VM, native concurrency, co-save, renderer or gameplay result is claimed.
"""
import importlib.util
import copy
import json
from pathlib import Path
from types import SimpleNamespace
spec=importlib.util.spec_from_file_location('bridge_parser', Path(__file__).with_name('bridge-parser.py'))
v=importlib.util.module_from_spec(spec); spec.loader.exec_module(v)

class PString(str):
    def __add__(self, other): return PString(str(self)+str(other))
    def __radd__(self, other): return PString(str(other)+str(self))
class PList(list):
    @property
    def Length(self): return len(self)
    def Find(self, item): return self.index(item) if item in self else -1

class Form:
    def __init__(self, fid, name='item', playable=True): self.fid,self.name,self.playable=fid,name,playable
    def GetFormID(self): return self.fid
    def GetName(self): return self.name
    def IsPlayable(self): return self.playable
class ActorBase(Form):
    pass

class Note(Form):
    def __init__(self, fid, base): super().__init__(fid); self.owner=None; self.actor_owner=None; self.base=base
    def SetDisplayName(self, name, force): self.name=name
    def GetDisplayName(self): return self.name
    def SetFactionOwner(self, owner): self.owner=owner
    def GetFactionOwner(self): return self.owner
    def SetActorOwner(self, owner): self.actor_owner=owner
    def GetActorOwner(self): return self.actor_owner
    def Enable(self): pass
class Actor(Form):
    def __init__(self, fid):
        super().__init__(fid); self.base=ActorBase(fid+100000); self.note_owner_override=None; self.inventory={}; self.dead=False; self.disabled=False
        self.equipped=[]; self.quest=PList(); self.moves=0; self.notes=0; self.partial=False; self.reject_note=False; self.counter_adjustment=0
    def GetActorBase(self): return self.base
    def GetItemCount(self, form):
        base=getattr(form,'base',form)
        return sum(count for held,count in self.inventory.items() if getattr(held,'base',held)==base)
    def IsDead(self): return self.dead
    def IsDisabled(self): return self.disabled
    def IsDeleted(self): return False
    def IsEquipped(self, form): return form in self.equipped
    def PlaceAtMe(self, template, count, persist, disabled): self.notes+=1; return Note(-16777216+self.notes, template)
    def AddItem(self, form, count, silent):
        if self.reject_note and isinstance(form,Note): return
        if self.note_owner_override is not None and isinstance(form,Note): form.SetActorOwner(self.note_owner_override)
        self.inventory[form]=self.inventory.get(form,0)+count
    def RemoveItem(self, form, count, silent, recipient):
        self.moves+=1
        moved=count-1 if self.partial else count
        self.inventory[form]=self.GetItemCount(form)-moved
        recipient.AddItem(form, moved, silent)
        if recipient.counter_adjustment:
            recipient.inventory[form]=recipient.GetItemCount(form)+recipient.counter_adjustment
            recipient.counter_adjustment=0

original_get_str=v.JMap.getStr
v.JMap.getStr=lambda *args: PString(original_get_str(*args))
v.JMap.object=lambda: {}
v.JMap.setStr=lambda obj,key,value: obj.__setitem__(key,value)
v.JMap.setInt=v.JMap.setStr
v.JMap.setForm=v.JMap.setStr
v.JValue.retain=lambda obj: obj
v.JValue.release=lambda obj: 0
def parse_object(raw):
    try:
        value=json.loads(raw)
        return value if isinstance(value,dict) else 0
    except (TypeError,ValueError):
        return 0
v.JValue.objectFromPrototype=parse_object
journal={}; logs=[]; forms={}; renderer=[True]; extender=[PList([5,10,0])]; api_version=[3]; feature_version=[3]; debug_messages=[]
def claim(root,path,candidate,comparand,create,error):
    if path in root: return root[path]
    root[path]=candidate; return 0
v.namespace.update(PString=PString, JAtomic=SimpleNamespace(compareExchangeObj=claim),
    JDB=SimpleNamespace(root=lambda:journal,solveObj=lambda path:journal.get(path,0)),
    JContainers=SimpleNamespace(APIVersion=lambda:api_version[0],featureVersion=lambda:feature_version[0],fileExistsAtPath=lambda p:renderer[0]),
    Game=SimpleNamespace(GetFormFromFile=lambda local,plugin:forms.get((plugin.lower(),local))),
    PO3_SKSEFunctions=SimpleNamespace(GetQuestItems=lambda actor:actor.quest,GetPapyrusExtenderVersion=lambda:extender[0]),
    AIAgentFunctions=SimpleNamespace(logMessage=lambda payload,event:logs.append(json.loads(payload))),
    Debug=SimpleNamespace(Trace=lambda message:debug_messages.append(str(message))))
for name in ('Resolve','JournalPath','ReceiptJSON','UUIDCaseEqual','TraceReconcileDiagnostic','Emit','Finish','Replay','Preflight','Deliver','ClaimAndDeliver','Reconcile','DispatchExternalCommand'):
    v.translate(name,string_coercion=True)

checks=[]
def check(name, condition): assert condition,name; checks.append(name)
def reset():
    journal.clear(); logs.clear(); forms.clear(); debug_messages.clear(); renderer[0]=True; extender[0]=PList([5,10,0]); api_version[0]=3; feature_version[0]=3
    payer=Actor(21); recipient=Actor(20); item=Form(15,'Gold')
    forms.update({('skyrim.esm',20):recipient,('skyrim.esm',21):payer,('skyrim.esm',15):item,
                  ('aiagent.esp',0x22D30):Form(0x123,'Generic Note')})
    payer.inventory[item]=20
    request=v.clone(); request['attachments']=[dict(kind='gold',form_id=15,plugin='Skyrim.esm',local_id=15,quantity=7)]
    return request,payer,recipient,item

def dispatch(request, action='Deliver'):
    v.namespace['DispatchExternalCommand']('managed-executor','ExtCmdCHIMCorrespondenceBridge_'+action,json.dumps(request,separators=(',',':')))
    return logs[-1]

request,payer,recipient,item=reset(); forms.pop(('aiagent.esp',0x21D0B),None); receipt=dispatch(request)
note=journal[v.namespace['JournalPath'](request)].get('note')
check('recipient_note_does_not_depend_on_aia_assisted_faction',
      receipt['status']=='delivered' and note is not None and note.GetActorOwner() is recipient.GetActorBase()
      and note.GetFactionOwner() is None and receipt['note_form_id']==note.GetFormID())
request,payer,recipient,item=reset(); receipt=dispatch(request)
legacy_path='.chim_correspondence_v1.p'+request['playthrough_id']+'.s'+request['scope_token']+'.o'+request['operation_id']
check('legacy_journal_path_format_preserved',v.namespace['JournalPath'](request)==legacy_path)
check('production_removeitem_branch',receipt['status']=='delivered' and payer.GetItemCount(item)==13 and recipient.GetItemCount(item)==7)
check('note_created_once',recipient.notes==1)
note=journal[legacy_path]['note']
template=forms[('aiagent.esp',0x22D30)]
check('recipient_actor_owner_preserves_note_identity_title_and_count',
      note.GetActorOwner() is recipient.GetActorBase() and note.GetDisplayName()==request['title']
      and receipt['note_form_id']==note.GetFormID() and recipient.GetItemCount(template)==1)
check('exact_both_deltas',receipt['attachments']==[dict(form_id=15,quantity=7,before_source=20,after_source=13,before_recipient=0,after_recipient=7)])
check('no_exposure_or_private_provenance',not any(key in receipt for key in ('exposure','body','payer','author','actual_author','title')))
replay=dispatch(request)
check('legacy_exact_wire_replay_without_mutation',replay==receipt and payer.moves==1 and recipient.notes==1)
check('reconcile_cached_without_mutation',dispatch(request,'Reconcile')==receipt and payer.moves==1 and recipient.notes==1)
changed=json.loads(json.dumps(request)); changed['attachments'][0]['quantity']=8
check('same_operation_changed_payload',dispatch(changed)['reason']=='operation_conflict' and payer.moves==1)
request,payer,recipient,item=reset(); receipt=dispatch(request)
moves=payer.moves; notes=recipient.notes
journal=copy.deepcopy(journal); logs.clear()
restored=dispatch(request,'Reconcile')
check('restored_cosave_replays_cached_receipt_without_mutation',
      restored==receipt and payer.moves==moves==1 and recipient.notes==notes==1 and recipient.GetItemCount(item)==7)
request,payer,recipient,item=reset(); wrong_actor=Actor(22); recipient.note_owner_override=wrong_actor.GetActorBase(); receipt=dispatch(request)
note=journal[v.namespace['JournalPath'](request)]['note']
check('wrong_actor_owner_is_unknown_before_attachment_transfer',
      receipt['status']=='unknown' and receipt['reason']=='note_ownership_unknown' and payer.moves==0
      and note.GetActorOwner() is wrong_actor.GetActorBase() and note.GetDisplayName()==request['title']
      and receipt['note_form_id']==note.GetFormID() and recipient.GetItemCount(forms[('aiagent.esp',0x22D30)])==1)
request,payer,recipient,item=reset(); receipt=dispatch(request,'Reconcile')
check('missing_journal_read_only',receipt['status']=='unknown' and receipt['reason']=='journal_missing' and not journal and payer.moves==recipient.notes==0)
path=v.namespace['JournalPath'](request)
journal[path]=dict(payload=json.dumps(request,separators=(',',':')),status='in_progress',note_form_id=-10,actual='[]')
check('pending_replay_no_retry',dispatch(request)['reason']=='in_progress' and payer.moves==recipient.notes==0)
check('pending_reconcile_no_retry',dispatch(request,'Reconcile')['reason']=='in_progress' and payer.moves==recipient.notes==0)
for reason,prepare in (
 ('insufficient_inventory',lambda payer,recipient,item: payer.inventory.__setitem__(item,6)),
 ('protected_item',lambda payer,recipient,item: payer.equipped.append(item)),
 ('protected_item',lambda payer,recipient,item: payer.quest.append(item)),
 ('actor_unavailable',lambda payer,recipient,item: setattr(recipient,'dead',True)),
 ('actor_unavailable',lambda payer,recipient,item: setattr(payer,'disabled',True)),
 ('inventory_overflow',lambda payer,recipient,item: recipient.inventory.__setitem__(item,2147483646)),
 ('renderer_not_ready',lambda payer,recipient,item: renderer.__setitem__(0,False)),
 ('papyrus_extender_unavailable',lambda payer,recipient,item: extender.__setitem__(0,PList())),
):
    request,payer,recipient,item=reset(); prepare(payer,recipient,item); receipt=dispatch(request)
    check('preflight_'+reason+'_'+str(len(checks)),receipt['status']=='failed' and receipt['reason']==reason and payer.moves==recipient.notes==0)
request,payer,recipient,item=reset(); request['recipient']['refid']=22
check('identity_mismatch_no_mutation',dispatch(request)['reason']=='invalid_actor' and payer.moves==recipient.notes==0)
request,payer,recipient,item=reset(); payer.partial=True; receipt=dispatch(request)
check('partial_transfer_unknown',receipt['status']=='unknown' and receipt['reason']=='transfer_mismatch' and receipt['attachments'][0]['quantity']==6)
check('partial_transfer_never_retries',dispatch(request)==receipt and payer.moves==1 and recipient.notes==1)
request,payer,recipient,item=reset(); recipient.inventory[item]=10; recipient.counter_adjustment=-12; receipt=dispatch(request)
check('negative_recipient_delta_preserved_in_unknown',receipt['status']=='unknown' and receipt['attachments'][0]==dict(form_id=15,quantity=-5,before_source=20,after_source=13,before_recipient=10,after_recipient=5))
check('negative_unknown_replay_never_repeats_transfer',dispatch(request)==receipt and payer.moves==1 and recipient.notes==1)
request,payer,recipient,item=reset(); request['attachments']=[]
check('letter_without_attachments',dispatch(request)['status']=='delivered' and payer.moves==0 and recipient.notes==1)
request,payer,recipient,item=reset(); request['attachments'][0]['quantity']=True
check('malformed_never_claims_or_mutates',dispatch(request)['reason']=='invalid_payload' and not journal and payer.moves==recipient.notes==0)
request,payer,recipient,item=reset()
existing=Note(-99,forms[('aiagent.esp',0x22D30)]); recipient.inventory[existing]=1
check('existing_generic_note_does_not_mask_delivery',dispatch(request)['status']=='delivered' and recipient.GetItemCount(existing)==2)
request,payer,recipient,item=reset()
existing=Note(-99,forms[('aiagent.esp',0x22D30)]); recipient.inventory[existing]=1; recipient.reject_note=True
check('old_note_cannot_mask_failed_note_add',dispatch(request)['reason']=='note_ownership_unknown' and payer.moves==0)
request,payer,recipient,item=reset(); template=forms[('aiagent.esp',0x22D30)]; payer.inventory[template]=3
request['attachments']=[dict(kind='item',form_id=template.fid,plugin='AIAgent.esp',local_id=0x22D30,quantity=2)]
check('explicit_template_attachment_count_accounted',dispatch(request)['status']=='delivered' and recipient.GetItemCount(template)==3)
request,payer,recipient,item=reset(); second=Form(16); forms[('skyrim.esm',16)]=second; payer.inventory[second]=3
request['attachments'].append(dict(kind='item',form_id=16,plugin='Skyrim.esm',local_id=16,quantity=2))
payer.quest.append(second)
check('all_attachment_preflight_before_note_or_first_move',dispatch(request)['reason']=='protected_item' and payer.moves==recipient.notes==0)
request,payer,recipient,item=reset(); second=Form(16); forms[('skyrim.esm',16)]=second; payer.inventory[second]=3
request['attachments'].append(dict(kind='item',form_id=16,plugin='Skyrim.esm',local_id=16,quantity=2)); payer.partial=True
check('partial_stops_remaining_attachment_mutation',dispatch(request)['reason']=='transfer_mismatch' and payer.moves==1 and payer.GetItemCount(second)==3 and recipient.GetItemCount(second)==0)
request,payer,recipient,item=reset(); request['payer']=dict(request['recipient']); request['attachments']=[]
check('same_payer_recipient_letter_without_attachments',dispatch(request)['status']=='delivered' and recipient.notes==1 and recipient.moves==0 and payer.moves==0)
request,payer,recipient,item=reset(); request['payer']=dict(request['recipient'])
check('same_payer_recipient_transfer_rejected',dispatch(request)['reason']=='invalid_actor' and recipient.notes==recipient.moves==0)
request,payer,recipient,item=reset()
for field in ('document_id','operation_id','receipt_id','scope_token','nonce'):
    request[field]=request[field].upper()
raw=json.dumps(request,separators=(',',':')); before=len(logs)
v.namespace['DispatchExternalCommand']('managed-executor','ExtCmdCHIMCorrespondenceBridge_Deliver',raw)
receipt=logs[-1] if len(logs)>before else {}
check('uppercase_wire_receipt_preserves_correlated_identity',receipt.get('status')=='delivered' and all(receipt.get(field)==request[field] for field in ('document_id','operation_id','receipt_id','scope_token','nonce')))
note=next((form for form in recipient.inventory if isinstance(form,Note)),None)
check('uppercase_wire_preserves_generated_title_text',note is not None and note.GetDisplayName()==request['title'])
check('uppercase_wire_replays_without_second_transfer',dispatch(request)==receipt and payer.moves==1 and recipient.notes==1)

for label,api,feature,payload,reason in (
    ('unsupported_jcontainers',2,3,json.dumps(request,separators=(',',':')),'unsupported_jcontainers'),
    ('unsupported_jcontainers_feature',3,2,json.dumps(request,separators=(',',':')),'unsupported_jcontainers'),
    ('oversize_payload',3,3,'x'*16001,'payload_too_large'),
    ('malformed_json',3,3,'{bad json','malformed_payload'),
):
    request,payer,recipient,item=reset(); api_version[0]=api; feature_version[0]=feature
    v.namespace['DispatchExternalCommand']('managed-executor','ExtCmdCHIMCorrespondenceBridge_Deliver',payload)
    check(label+'_safe_reconcile_diagnostic_without_receipt',logs==[] and payer.moves==recipient.notes==0 and bool(debug_messages) and reason in debug_messages[-1] and 'check outcome (read-only reconcile)' in debug_messages[-1].lower() and 'do not resend' in debug_messages[-1] and payload not in debug_messages[-1])
request,payer,recipient,item=reset(); malformed=v.clone(); malformed['nonce']='bad'
v.namespace['DispatchExternalCommand']('managed-executor','ExtCmdCHIMCorrespondenceBridge_Deliver',json.dumps(malformed,separators=(',',':')))
check('invalid_correlation_emits_no_fabricated_receipt_and_safe_diagnostic',logs==[] and payer.moves==recipient.notes==0 and bool(debug_messages) and 'invalid_correlation' in debug_messages[-1] and 'check outcome (read-only reconcile)' in debug_messages[-1].lower() and 'do not resend' in debug_messages[-1])
print(json.dumps(dict(status='PASS',evidence_kind='production Papyrus mechanically translated; fake native inventory/JDB boundary, no native execution',checks=len(checks),cases=checks),indent=2))




