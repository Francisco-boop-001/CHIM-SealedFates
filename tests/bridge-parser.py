"""Execute the production bridge's pure validation functions after mechanical translation.
This is host-side validator evidence, not Papyrus VM, native inventory, or gameplay proof.
"""
import json
import re
from pathlib import Path
from types import SimpleNamespace

SOURCE = Path(__file__).resolve().parents[1] / 'game/Source/Scripts/CHIMCorrespondenceBridge.psc'
text = SOURCE.read_text(encoding='utf-8-sig')

class JMap:
    @staticmethod
    def count(value): return len(value) if isinstance(value, dict) else 0
    @staticmethod
    def getStr(value, key, default=''):
        item = value.get(key, default) if isinstance(value, dict) else default
        return item if isinstance(item, str) else default
    @staticmethod
    def getInt(value, key, default=0):
        item = value.get(key, default) if isinstance(value, dict) else default
        return item if type(item) in (int, bool) else default
    @staticmethod
    def getObj(value, key):
        item = value.get(key, 0) if isinstance(value, dict) else 0
        return item if isinstance(item, (dict, list)) else 0
    @staticmethod
    def valueType(value, key):
        item = value.get(key) if isinstance(value, dict) else None
        return 2 if type(item) in (int, bool) else 6 if isinstance(item, str) else 5 if isinstance(item, (dict, list)) else 0

StringUtil = SimpleNamespace(
    GetLength=len, GetNthChar=lambda s, i: s[i:i+1], AsOrd=lambda s: ord(s[0]) if s else 0,
    Find=lambda s, part, start=0: s.find(part, start),
    Substring=lambda s, start, count=0: s[start:start+count] if count else s[start:])
JValue = SimpleNamespace(isMap=lambda v: isinstance(v, dict), isArray=lambda v: isinstance(v, list))
JArray = SimpleNamespace(count=len, getObj=lambda v, i: v[i])
namespace = dict(JMap=JMap, JArray=JArray, JValue=JValue, StringUtil=StringUtil)

# Translate declarations/control flow only; all expressions and branch order come from the production source.
def translate(name, string_coercion=False):
    match = re.search(r'(?im)^(?:\w+ )?Function ' + name + r'\((.*?)\) global\r?\n(.*?)^EndFunction', text, re.S | re.M)
    assert match, name
    params = ', '.join(re.sub(r'^\w+\s+', '', p.strip()) for p in match[1].split(','))
    output = ['def ' + name + '(' + params + '):']
    depth = 1
    for raw in match[2].splitlines():
        line = raw.strip()
        if not line or line.startswith(';'): continue
        if line.lower() in ('endif', 'endwhile'):
            depth -= 1
            continue
        if line.startswith(('ElseIf ', 'Else')): depth -= 1
        line = re.sub(r'^(Int|Bool|String|Form|Actor|Book|Faction|ObjectReference)(?:\[\])?\s+', '', line)
        line = re.sub(r'\s+as\s+(Actor|Book|Faction|Form)\b', '', line)
        if string_coercion:
            line = re.sub(r'"(?:[^"\\]|\\.)*"', lambda m: 'PString(' + m[0] + ')', line)
        line = re.sub(r'\btrue\b', 'True', line)
        line = re.sub(r'\bfalse\b', 'False', line)
        line = line.replace('&&', ' and ').replace('||', ' or ')
        line = re.sub(r'!(?!=)', 'not ', line)
        opens = False
        for source, target in [('ElseIf ', 'elif '), ('If ', 'if '), ('While ', 'while ')]:
            if line.startswith(source):
                line = target + line[len(source):] + ':'
                opens = True
                break
        if line == 'Else': line, opens = 'else:', True
        line = re.sub(r'^Return\b', 'return', line)
        output.append('    ' * depth + line)
        if opens: depth += 1
    code = '\n'.join(output)
    exec(compile(code, str(SOURCE) + ':' + name, 'exec'), namespace)
    return code

translated = {name: translate(name) for name in ('IsHex', 'IsUUID', 'IntegerLexemeValid', 'JsonFieldCount', 'IdentityValid', 'CorrelationValid', 'RequestValid')}
uuid = '12345678-1234-1234-1234-123456789abc'
actor = dict(refid=20, plugin='Skyrim.esm', local_id=20)
request = dict(protocol_version=1, document_id=uuid, operation_id=uuid, receipt_id=uuid,
               playthrough_id='unprofiled', scope_token=uuid, nonce='a'*64,
               recipient=actor, payer=dict(actor, refid=21, local_id=21),
               title='letter '+uuid, renderer_key='b'*32, attachments=[])
checks = []
def check(name, condition):
    assert condition, name
    checks.append(name)

def valid(value, raw=None):
    raw = json.dumps(value, separators=(',', ':')) if raw is None else raw
    return namespace['CorrelationValid'](value) and namespace['RequestValid'](value, raw)

def clone(): return json.loads(json.dumps(request))
check('canonical_empty_attachments', valid(request))
for scope in ('1', '100', '2147483647', '9223372036854775807'):
    case=clone(); case['playthrough_id']=scope
    check('positive_profile_'+scope, valid(case))
for scope in ('', '0', '01', '-1', '1.0', 'a', '1"x', '10000000000000000000'):
    case=clone(); case['playthrough_id']=scope
    check('invalid_scope_'+scope, not valid(case))
case=clone(); case['attachments']=[dict(kind='gold',form_id=15,plugin='Skyrim.esm',local_id=15,quantity=7)]
check('gold_attachment', valid(case))
case['attachments'][0]['quantity']=True
check('boolean_quantity_rejected_before_native_normalization', not valid(case))
case['attachments'][0]['quantity']=0
check('zero_quantity', not valid(case))
case['attachments'][0]['quantity']=-1
check('negative_quantity', not valid(case))
case['attachments'][0]['quantity']=1.0
check('float_quantity', not valid(case))
case['attachments'][0]['quantity']=7
case['attachments'].append(dict(case['attachments'][0]))
check('duplicate_attachment', not valid(case))
case=clone(); case['attachments']=[dict(kind='item',form_id=16+i,plugin='Skyrim.esm',local_id=16+i,quantity=1) for i in range(8)]
check('eight_attachments', valid(case))
case['attachments'].append(dict(kind='item',form_id=24,plugin='Skyrim.esm',local_id=24,quantity=1))
check('ninth_attachment', not valid(case))
for field in tuple(request):
    case=clone(); del case[field]
    check('missing_'+field, not valid(case))
case=clone(); case['extra']='x'
check('unknown_top_field', not valid(case))
raw=json.dumps(request, separators=(',', ':'))
check('duplicate_key', not valid(request, raw.replace('"protocol_version":1', '"protocol_version":1,"protocol_version":1')))
check('boolean_protocol', not valid(dict(request, protocol_version=True)))
check('uppercase_nonce', not valid(dict(request, nonce='A'*64)))
check('bad_renderer', not valid(dict(request, renderer_key='.'*32)))
check('hidden_provenance_title', not valid(dict(request, title='payer: player')))
check('escaped_protocol_delimiters_do_not_count_as_fields', namespace['JsonFieldCount']('{"x":"a:b@c|d\\\"e"}')==1)
check('raw_boolean', namespace['JsonFieldCount']('{"x": true}')==-1)
check('raw_null', namespace['JsonFieldCount']('{"x":null}')==-1)
check('raw_exponent', namespace['JsonFieldCount']('{"x":1e2}')==-1)
check('raw_decimal', namespace['JsonFieldCount']('{"x":1.2}')==-1)
for token in ('2147483647', '-2147483648', '0', '1', '-1'):
    check('int32_accept_'+token, namespace['JsonFieldCount']('{"x":'+token+'}')==1)
for token in ('2147483648', '-2147483649', '4294967297', '999999999999999999999', '-0', '01', '-01'):
    check('int32_reject_'+token, namespace['JsonFieldCount']('{"x":'+token+'}')==-1)
def report():
    print(json.dumps(dict(status='PASS', evidence_kind='mechanically translated production pure validators; not Papyrus/native execution', checks=len(checks), cases=checks), indent=2))

if __name__ == '__main__': report()



