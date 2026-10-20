"""Real HTTP endpoint + native PostgreSQL; game receipts and context host are explicit fixture seams."""
import http.cookiejar
import json
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
import uuid

base=sys.argv[1]
jar=http.cookiejar.CookieJar()
client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
def request(path,data=None,headers=None):
    raw=None if data is None else urllib.parse.urlencode(data,doseq=True).encode()
    try:
        response=client.open(urllib.request.Request(base+path,data=raw,headers=headers or {}),timeout=15)
    except urllib.error.HTTPError as e: response=e
    return response.status,response.read().decode()
def summary():
    code,body=request('/fixture/summary');assert code==200,(code,body)
    return json.loads(body)
code,page=request('/index.php');assert code==200,(code,page)
csrf=re.search(r'name="csrf" value="([a-f0-9]{64})"',page)[1]
assert request('/index.php',headers={'X-Forwarded-For':'127.0.0.1'})[0]==403
before=len(summary()['documents'])
assert request('/index.php',{'csrf':'bad','action':'dispatch','document_id':str(uuid.uuid4())})[0]==400
assert len(summary()['documents'])==before
assert request('/fixture/presence')[0]==200
request_id=str(uuid.uuid4())
body='Camilla, the attached gold is for you.\nSigned, Jarl Balgruuf.\nRef '+request_id
data={'csrf':csrf,'action':'compose','request_id':request_id,'author':'player','payer':'player','recipient':'npc:900001','claimed_sender':'npc:900002','body':body,'item[]':['Skyrim.esm|0000000F']+['']*7,'quantity[]':['5']+['0']*7}
code,page=request('/index.php',data);assert code==200 and 'physical receipt is pending' in page,(code,page)
s=summary();doc=next(d for d in s['documents'] if d['body']==body)
assert doc['state']=='dispatched'
book=next(row for row in s['books'] if row['title']=='letter '+doc['document_id'])
assert book['content']=='From: Jarl Balgruuf\nTo: Camilla\n\n'+body, 'physical note must visibly identify its claimed sender while preserving original body'
assert 'Dragonborn' not in book['content'], 'hidden author must not appear in physical header'
assert all(body not in row['data'] for row in s['sources'])
assert request('/fixture/spoof')[0]==200
assert request('/fixture/receipt?id='+doc['document_id'])[0]==200
assert next(d for d in summary()['documents'] if d['document_id']==doc['document_id'])['state']=='delivered'
code,text=request('/fixture/context?owner=Faendal');assert code==200 and json.loads(text)['context']==''
code,text=request('/fixture/context?owner=Camilla');assert code==200,(code,text)
context=json.loads(text)['context'];assert 'Signed, Jarl Balgruuf' in context
assert all(secret not in context for secret in ['Dragonborn','scope_token','nonce','payer','actual_author'])
s=summary();sources=[json.loads(r['data']) for r in s['sources'] if json.loads(r['data'])['document_id']==doc['document_id']]
assert len(sources)==1 and sources[0]['receipt']['exposure']=='context'
request('/fixture/context?owner=Camilla')
assert len(summary()['commands'])==len(s['commands'])
assert len([r for r in summary()['sources'] if json.loads(r['data'])['document_id']==doc['document_id']])==1
# Receiving an NPC-signed letter uses the ordinary Player payer when nothing is attached.
player_body='Dragonborn, a letter from the jarl. <img src=x> Ref '+str(uuid.uuid4())
player_data=dict(data,request_id=str(uuid.uuid4()),author='npc:900002',recipient='player',body=player_body)
player_data['item[]']=['']*8
player_data['quantity[]']=['0']*8
code,page=request('/index.php',player_data);assert code==200 and 'physical receipt is pending' in page,(code,page)
pdoc=next(d for d in summary()['documents'] if d['body']==player_body)
request('/fixture/receipt?id='+pdoc['document_id'])
assert all(json.loads(row['data'])['document_id']!=pdoc['document_id'] for row in summary()['sources'])
code,page=request('/index.php',{'csrf':csrf,'action':'read','document_id':pdoc['document_id']})
assert code==200 and '&lt;img src=x&gt;' in page and '<img src=x>' not in page
psources=[json.loads(row['data']) for row in summary()['sources'] if json.loads(row['data'])['document_id']==pdoc['document_id']]
assert psources==[], 'browser Player view has no native game clock and must publish no neutral source'
assert next(d for d in summary()['documents'] if d['document_id']==pdoc['document_id'])['state']=='delivered'
print('PASS real HTTP+Pg: operator access, CSRF, composer, native renderer/book/queue, receipt interception, recipient exposure, Player inbox view without invented native evidence, HTML escaping, privacy and replay; simulated game/context host')
