"""Exact coordinator-exported PostgreSQL producer JSON through production bridge expressions.
Native inventory/JDB are deliberately fake. Export receipts are fixtures, never game proof.
"""
import contextlib
import hashlib
import importlib.util
import io
import json
import sys
from pathlib import Path

wire=Path(sys.argv[1]) if len(sys.argv)>1 else Path('K:/ActorwrightExchange/artifacts/chim-correspondence-implementation-20261019/server-wire.json')
out=Path(sys.argv[2]) if len(sys.argv)>2 else wire.parent/'bridge'
spec=importlib.util.spec_from_file_location('bridge_state',Path(__file__).with_name('bridge-state.py'))
b=importlib.util.module_from_spec(spec)
with contextlib.redirect_stdout(io.StringIO()): spec.loader.exec_module(b)
raw_bytes=wire.read_bytes()
raw=raw_bytes.decode('utf-8-sig')
request=json.loads(raw)
assert b.v.valid(request,raw), 'Actual persisted producer payload rejected by production validators'
assert request['renderer_key']==hashlib.md5(request['title'].strip().lower().encode()).hexdigest()
b.reset()
b.forms.clear()
payer=b.Actor(request['payer']['refid']); recipient=b.Actor(request['recipient']['refid'])
for identity, actor in ((request['payer'],payer),(request['recipient'],recipient)):
    b.forms[(identity['plugin'].lower(),identity['local_id'])]=actor
b.forms[('aiagent.esp',0x22D30)]=b.Form(0x123,'Generic Note')
for line in request['attachments']:
    item=b.Form(line['form_id'])
    b.forms[(line['plugin'].lower(),line['local_id'])]=item
    payer.inventory[item]=line['quantity']+20
    recipient.inventory[item]=3
execute=b.v.namespace['DispatchExternalCommand']
execute('managed-executor','ExtCmdCHIMCorrespondenceBridge_Deliver',raw)
receipt=b.logs[-1]
assert receipt['status']=='delivered',receipt
assert receipt['document_id']==request['document_id'] and receipt['nonce']==request['nonce']
assert len(receipt['attachments'])==len(request['attachments'])
assert all(actual['quantity']==expected['quantity'] for actual,expected in zip(receipt['attachments'],request['attachments']))
moves=payer.moves; notes=recipient.notes
execute('managed-executor','ExtCmdCHIMCorrespondenceBridge_Reconcile',raw)
assert b.logs[-1]==receipt and payer.moves==moves and recipient.notes==notes
execute('managed-executor','ExtCmdCHIMCorrespondenceBridge_Deliver',raw)
assert b.logs[-1]==receipt and payer.moves==moves and recipient.notes==notes
out.mkdir(parents=True,exist_ok=True)
receipt_path=out/'actual-server-wire-fake-native-receipt.json'
receipt_path.write_text(json.dumps(receipt,separators=(',',':')),encoding='utf-8')
result=dict(status='PASS',evidence_kind='actual persisted server JSON through production-source bridge translation; fake-native fixture, no game execution',
            checks=8,wire=str(wire),wire_sha256=hashlib.sha256(raw_bytes).hexdigest(),wire_bytes=len(raw_bytes),
            trailing_export_newline=raw_bytes.endswith(b'\n'),render_key_matches=True,parser_accepts=True,
            same_wire_reconcile_replays=True,duplicate_deliver_does_not_mutate=True,receipt_fixture=str(receipt_path),
            receipt_sha256=hashlib.sha256(receipt_path.read_bytes()).hexdigest())
(out/'actual-server-wire-check.json').write_text(json.dumps(result,indent=2),encoding='utf-8')
print(json.dumps(result,indent=2))
