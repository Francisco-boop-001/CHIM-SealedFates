"""Actual HTTP/UI/Pg/renderer plus explicit native clock/receipt/model host seams; no provider/game."""
import http.cookiejar,json,re,sys,urllib.request,urllib.parse,urllib.error
base=sys.argv[1];client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def request(path,data=None):
    raw=None if data is None else urllib.parse.urlencode(data).encode()
    try:r=client.open(urllib.request.Request(base+path,data=raw),timeout=20)
    except urllib.error.HTTPError as e:r=e
    return r.status,r.read().decode()
def get(path):
    code,body=request(path);assert code==200,(code,body);return json.loads(body)
code,page=request('/index.php');assert code==200,(code,page);csrf=re.search(r'name="csrf" value="([a-f0-9]{64})"',page)[1]
a=get('/fixture/autonomy-summary');assert a['settings']=={'enabled':False} and a['decision_count']==0
assert request('/index.php',{'csrf':'bad','action':'autonomy','enabled':'1'})[0]==400
assert get('/fixture/autonomy-summary')['settings']=={'enabled':False}
code,page=request('/index.php',{'csrf':csrf,'action':'autonomy','enabled':'1'});assert code==200 and 'enabled' in page
assert get('/fixture/autonomy-summary')['decision_count']==0,'browser enable/GET must not generate letters'
original=next(d for d in get('/fixture/summary')['documents'] if d['recipient'].get('id')==900001 and d['state']=='exposed')
now=100+833334
r=get(f'/fixture/autonomy-tick?ts=200&gamets={now}');assert r['status']=='queued' and r['model_calls']==1,r
row=get('/fixture/autonomy-summary')['decisions'][0];child=row['child_document_id'];assert child
s=get('/fixture/summary');letter=next(d for d in s['documents'] if d['document_id']==child)
assert letter['state']=='queued' and letter['claimed_sender']['name']=='Camilla' and letter['recipient']['name']=='Lydia' and letter['attachments']==[]
assert original['body'] in letter['body'] and 'Claimed sender: Jarl Balgruuf' in letter['body'] and 'Original recipient: Camilla' in letter['body']
commands=len(s['commands']);assert request('/index.php',{'csrf':csrf,'action':'dispatch','document_id':child})[0]==400,'forged form must not bypass autonomous transit'
assert len(get('/fixture/summary')['commands'])==commands
code,page=request('/index.php',{'csrf':csrf,'action':'autonomy','enabled':'0'});assert code==200
late=now+5000000
r=get(f'/fixture/autonomy-tick?ts=300&gamets={late}');assert r['model_calls']==0 and r['reason']=='disabled',r
assert len(get('/fixture/summary')['commands'])==commands,'pause must prevent queued dispatch'
code,page=request('/index.php',{'csrf':csrf,'action':'autonomy','enabled':'1'});assert code==200
r=get(f'/fixture/autonomy-tick?ts=400&gamets={late}');assert r['status']=='dispatched' and r['model_calls']==0,r
s=get('/fixture/summary');assert len(s['commands'])==commands+2
assert next(d for d in s['documents'] if d['document_id']==child)['state']=='dispatched'
assert all(json.loads(e['data'])['document_id']!=child for e in s['sources']),'queued physical command is not exposure'
assert request(f'/fixture/autonomy-receipt?id={child}&ts=500&gamets={late}')[0]==200
s=get('/fixture/summary');assert next(d for d in s['documents'] if d['document_id']==child)['state']=='delivered'
assert all(json.loads(e['data'])['document_id']!=child for e in s['sources']),'physical receipt is not knowledge'
other=get(f'/fixture/autonomy-context?owner=Jarl%20Balgruuf&ts=600&gamets={late}')['context'];assert child not in other
text=get(f'/fixture/autonomy-context?owner=Lydia&ts=600&gamets={late}')['context'];projected=[json.loads(line) for line in text.splitlines() if line.startswith('{')];assert any(row.get('document_id')==child and original['body'] in row['body'] for row in projected) and 'nonce' not in text and 'payer' not in text
s=get('/fixture/summary');source=next(json.loads(e['data']) for e in s['sources'] if json.loads(e['data'])['document_id']==child)
assert source['body']==letter['body'] and source['claimed_sender']['name']=='Camilla' and source['recipient']['name']=='Lydia' and source['attachments']==[]
book=next(b for b in s['books'] if b['title']=='letter '+child);assert book['content']=='From: Camilla\nTo: Lydia\n\n'+letter['body']
assert get('/fixture/autonomy-summary')['decision_count']==1
print(json.dumps({'status':'PASS','actual_http_pg_renderer':True,'csrf_optin_pause':True,'browser_generation_calls':0,'forward_callback_calls':1,'full_copy_exact':True,'column_personality_only':True,'manual_transit_bypass_rejected':True,'receipt_and_exposure_separate':True,'provider_calls':0,'native_gameplay':'not_run'}))
