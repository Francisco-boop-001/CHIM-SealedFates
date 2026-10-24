"""Translated policy checks for CHIMCorrespondenceCompanion.psc; not Papyrus VM proof."""
import importlib.util
import json
import re
from pathlib import Path
from types import SimpleNamespace

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / 'game/Source/Scripts/CHIMCorrespondenceCompanion.psc'
if not SOURCE.is_file():
    raise AssertionError('production companion script is missing')
source = SOURCE.read_text(encoding='utf-8-sig')

bridge_spec = importlib.util.spec_from_file_location('bridge_parser', ROOT / 'tests/bridge-parser.py')
bridge_parser = importlib.util.module_from_spec(bridge_spec)
bridge_spec.loader.exec_module(bridge_parser)
bridge = bridge_parser.namespace


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
    @staticmethod
    def setStr(value, key, item): value[key] = item
    @staticmethod
    def setInt(value, key, item): value[key] = item
    @staticmethod
    def removeKey(value, key): return value.pop(key, None) is not None
    @staticmethod
    def clear(value): value.clear()
    @staticmethod
    def object(): return {}


JArray = SimpleNamespace(count=len, getObj=lambda value, index: value[index])
JValue = SimpleNamespace(
    isMap=lambda value: isinstance(value, dict),
    isArray=lambda value: isinstance(value, list),
    retain=lambda value: value,
    release=lambda value: 0,
)
StringUtil = SimpleNamespace(
    GetLength=len,
    GetNthChar=lambda value, index: value[index:index + 1],
    AsOrd=lambda value: ord(value[0]) if value else 0,
    Find=lambda value, part, start=0: value.find(part, start),
    Substring=lambda value, start, count=0: value[start:start + count] if count else value[start:],
)
CHIMCorrespondenceBridge = SimpleNamespace(
    IsUUID=bridge['IsUUID'],
    IsHex=bridge['IsHex'],
    IdentityValid=bridge['IdentityValid'],
    JsonFieldCount=bridge['JsonFieldCount'],
)
def compare_exchange_obj(container, path, value, comparand, create_missing=False, on_error=0):
    # The pinned JAtomic API resolves object paths only when they begin with '.';
    # an absent path is installed only when create_missing is true.
    if not isinstance(container, dict) or not path.startswith('.'):
        return on_error
    key = path[1:]
    if '.' in key:
        return on_error
    if key in container:
        previous = container[key]
        if previous == comparand:
            container[key] = value
        return previous
    if not create_missing:
        return on_error
    container[key] = value
    return 0

JAtomic = SimpleNamespace(compareExchangeObj=compare_exchange_obj)
namespace = dict(
    JMap=JMap, JArray=JArray, JValue=JValue, JAtomic=JAtomic, StringUtil=StringUtil,
    CHIMCorrespondenceBridge=CHIMCorrespondenceBridge,
)


def translate(name):
    match = re.search(
        r'(?im)^(?:\w+ )?Function ' + re.escape(name) + r'\((.*?)\) global\r?\n(.*?)^EndFunction',
        source, re.S | re.M,
    )
    assert match, f'production function missing: {name}'
    params = ', '.join(re.sub(r'^\w+\s+', '', param.strip()) for param in match[1].split(','))
    output = ['def ' + name + '(' + params + '):']
    depth = 1
    for raw in match[2].splitlines():
        line = raw.strip()
        if not line or line.startswith(';'):
            continue
        if line.lower() in ('endif', 'endwhile'):
            depth -= 1
            continue
        if line.startswith(('ElseIf ', 'Else')):
            depth -= 1
        line = re.sub(r'^(Int|Bool|String|Float|Form|Actor|Cell|Faction|Package)(?:\[\])?\s+', '', line)
        line = line.replace('&&', ' and ').replace('||', ' or ')
        line = re.sub(r'!(?!=)', 'not ', line)
        line = re.sub(r'\btrue\b', 'True', line, flags=re.I)
        line = re.sub(r'\bfalse\b', 'False', line, flags=re.I)
        opens = False
        for papyrus, python in (('ElseIf ', 'elif '), ('If ', 'if '), ('While ', 'while ')):
            if line.startswith(papyrus):
                line = python + line[len(papyrus):] + ':'
                opens = True
                break
        if line == 'Else':
            line, opens = 'else:', True
        line = re.sub(r'^Return\b', 'return', line)
        output.append('    ' * depth + line)
        if opens:
            depth += 1
    code = '\n'.join(output)
    exec(compile(code, str(SOURCE) + ':' + name, 'exec'), namespace)
    return code


for function in (
    'ActorIdValid', 'DecimalAtMostSigned64', 'ScopeValid', 'LowerHexValid',
    'RequestValid', 'ClassifyActorState',
    'EpisodeCanContinue', 'RecordWaitingEpisode', 'PrepareStateNamespace',
    'ScopeEpochMatches', 'ClearEpisode', 'StatePath', 'GetEpisodeState',
):
    translate(function)

UUID = '12345678-1234-1234-1234-123456789abc'
EPOCH = 'abcdefab-cdef-abcd-efab-cdefabcdefab'
WAIT_FACTION = object()
WAIT_PACKAGE = object()
WAIT_PACKAGE_SOFT = object()
checks = []


def check(name, condition):
    assert condition, name
    checks.append(name)


def request(actor_ids=('27',)):
    actors = [
        {'id': actor_id, 'plugin': 'Skyrim.esm', 'local_id': 0x12345 + index, 'refid': 0x01000800 + index}
        for index, actor_id in enumerate(actor_ids)
    ]
    return {
        'version': 1, 'observation_id': UUID, 'playthrough_id': '1234',
        'epoch': EPOCH, 'nonce': 'a' * 64, 'actors': actors,
    }


def valid(payload, raw=None):
    raw = json.dumps(payload, separators=(',', ':')) if raw is None else raw
    return namespace['RequestValid'](payload, raw)


check('canonical_positive_decimal_actor_ids', all(namespace['ActorIdValid'](value) for value in ('1', '27', '9223372036854775807')))
for value in ('', '0', '00', '01', '-1', '+1', '1.0', '1e3', '9223372036854775808', '10000000000000000000'):
    check('reject_actor_id_' + (value or 'empty'), not namespace['ActorIdValid'](value))
check('safe_playthrough_scope', all(namespace['ScopeValid'](value) for value in ('1', 'unprofiled', 'playthrough_A-2')))
for value in ('', '.', '..', '1/2', 'a:b', 'has space', 'x' * 65):
    check('reject_scope_' + (value or 'empty'), not namespace['ScopeValid'](value))

check('one_actor_request', valid(request()))
check('four_actor_request', valid(request(('1', '2', '3', '4'))))
for actors in ((), ('1', '2', '3', '4', '5')):
    check('reject_actor_count_' + str(len(actors)), not valid(request(actors)))
case = request()
case['actors'][0]['refid'] = -1
check('signed_runtime_form_id', valid(case))
case = request()
case['extra'] = 'unexpected'
check('reject_unknown_top_level_field', not valid(case))
case = request()
case['actors'][0]['extra'] = 1
check('reject_unknown_actor_field', not valid(case))
case = request()
case['actors'][0]['id'] = '01'
check('reject_noncanonical_actor_id', not valid(case))
case = request()
case['nonce'] = 'A' * 64
check('reject_uppercase_nonce', not valid(case))
case = request()
case['nonce'] = 'g' * 64
check('reject_nonhex_nonce', not valid(case))
case = request()
case['epoch'] = 'bad'
check('reject_bad_epoch', not valid(case))
case = request()
case['actors'].append(dict(case['actors'][0]))
case['actors'][1]['id'] = '28'
check('reject_duplicate_reference_identity', not valid(case))
case = request()
raw = json.dumps(case, separators=(',', ':')).replace('"version":1', '"version":1,"version":1')
check('reject_duplicate_json_key', not valid(case, raw))
case = request()
raw = json.dumps(case, separators=(',', ':')).replace('"version":1', '"version":true')
check('reject_boolean_integer_lexeme', not valid(case, raw))
case = request()
refid = case['actors'][0]['refid']
raw = json.dumps(case, separators=(',', ':')).replace('"refid":' + str(refid), '"refid":' + str(refid) + '.0')
check('reject_float_runtime_id', not valid(case, raw))


class Cell:
    def __init__(self, name): self.name = name


class Actor:
    def __init__(self, cell, distance=3000.0, loaded=True, dead=False, teammate=True,
                 vanilla_wait=0.0, wait_faction=False, package=None):
        self.cell, self.distance, self.loaded, self.dead = cell, distance, loaded, dead
        self.teammate, self.vanilla_wait, self.wait_faction, self.package = teammate, vanilla_wait, wait_faction, package

    def IsDead(self): return self.dead
    def GetParentCell(self): return self.cell
    def Is3DLoaded(self): return self.loaded
    def GetDistance(self, other): return self.distance
    def IsPlayerTeammate(self): return self.teammate
    def GetActorValue(self, name):
        assert name == 'WaitingForPlayer'
        return self.vanilla_wait
    def IsInFaction(self, faction): return self.wait_faction and faction is WAIT_FACTION
    def GetCurrentPackage(self): return self.package


player_cell = Cell('player')
same_cell = player_cell
other_cell = Cell('other')
player = Actor(player_cell)
classify = namespace['ClassifyActorState']


def state(actor, player_actor=player):
    return classify(actor, player_actor, WAIT_FACTION, WAIT_PACKAGE, WAIT_PACKAGE_SOFT)


check('dead_clears_before_other_world_tests', state(Actor(None, dead=True), None) == 'dead')
check('nearby_at_1024_cancels_before_wait_test', state(Actor(same_cell, distance=1024.0, teammate=False, vanilla_wait=1.0)) == 'nearby')
check('same_cell_1025_to_2048_is_unknown', state(Actor(same_cell, distance=1025.0)) == 'unknown')
check('same_cell_2048_is_unknown', state(Actor(same_cell, distance=2048.0)) == 'unknown')
check('same_cell_over_2048_waits_on_vanilla_av', state(Actor(same_cell, distance=2048.1, vanilla_wait=1.0)) == 'waiting')
check('same_cell_unloaded_is_unknown', state(Actor(same_cell, distance=3000.0, loaded=False, vanilla_wait=1.0)) == 'unknown')
check('different_known_cell_waits', state(Actor(other_cell, vanilla_wait=1.0)) == 'waiting')
check('different_known_cell_without_wait_is_other', state(Actor(other_cell)) == 'other')
check('vanilla_wait_requires_teammate', state(Actor(other_cell, teammate=False, vanilla_wait=1.0)) == 'other')
check('chim_wait_requires_faction_and_normal_package', state(Actor(other_cell, wait_faction=True, package=WAIT_PACKAGE)) == 'waiting')
check('chim_wait_accepts_soft_package', state(Actor(other_cell, wait_faction=True, package=WAIT_PACKAGE_SOFT)) == 'waiting')
check('chim_wait_faction_without_active_package_is_other', state(Actor(other_cell, wait_faction=True)) == 'other')
check('missing_normal_package_does_not_match_missing_current_package', classify(Actor(other_cell, wait_faction=True, package=None), player, WAIT_FACTION, None, WAIT_PACKAGE_SOFT) == 'other')
check('chim_wait_package_without_faction_is_other', state(Actor(other_cell, package=WAIT_PACKAGE)) == 'other')
check('unknown_cell_is_unknown', state(Actor(None, vanilla_wait=1.0)) == 'unknown')

record = namespace['RecordWaitingEpisode']
clear = namespace['ClearEpisode']
can_continue = namespace['EpisodeCanContinue']
first_state = {}
check('first_wait_sample_uses_observation_uuid', record(first_state, UUID, 100) == UUID and first_state['first_game_minute'] == 100)
check('sample_gap_at_120_minutes_continues', can_continue(UUID, 100, 220, 220))
check('sample_gap_over_120_resets', not can_continue(UUID, 100, 220, 341))
check('game_time_rewind_resets', not can_continue(UUID, 100, 220, 219))
check('invalid_saved_episode_resets', not can_continue('', 0, 0, 240))
check('valid_episode_keeps_first_bound', record(first_state, EPOCH, 160) == UUID and first_state['first_game_minute'] == 100)
check('valid_episode_advances_last_sample', first_state['last_game_minute'] == 160)
check('large_gap_starts_new_episode', record(first_state, EPOCH, 281) == EPOCH and first_state['first_game_minute'] == 281)
state_namespace = {namespace['StatePath']('1', UUID, '7'): first_state}
state_key = namespace['StatePath']('1', UUID, '7')
check('clearing_nonwaiting_episode_removes_actor_state', clear(state_namespace, state_key) and state_key not in state_namespace)
check('state_key_is_bound_to_scope_epoch_and_actor', len({namespace['StatePath']('1', UUID, '7'), namespace['StatePath']('2', UUID, '7'), namespace['StatePath']('1', EPOCH, '7'), namespace['StatePath']('1', UUID, '8')}) == 4)
new_actor_namespace = {}
new_actor_key = namespace['StatePath']('1', UUID, '7')
new_actor_state = namespace['GetEpisodeState'](new_actor_namespace, new_actor_key, True)
check('first_waiting_actor_state_is_created_through_dotted_atomic_path', isinstance(new_actor_state, dict) and new_actor_namespace.get(new_actor_key) is new_actor_state)
check('missing_actor_state_is_not_created_for_nonwaiting_sample', namespace['GetEpisodeState']({}, new_actor_key, False) == 0)
check('same_scope_and_epoch_preserves_state', namespace['ScopeEpochMatches']('1', UUID, '1', UUID))
check('changed_epoch_changes_scope_identity', not namespace['ScopeEpochMatches']('1', UUID, '1', EPOCH))
check('changed_playthrough_changes_scope_identity', not namespace['ScopeEpochMatches']('1', UUID, '2', UUID))
current_scope = {'active_playthrough_id': 'old', 'active_epoch': UUID, 'stale_state': {'episode': UUID}}
check('new_scope_clears_prior_cosave_state', namespace['PrepareStateNamespace'](current_scope, 'new', EPOCH) and current_scope == {'active_playthrough_id': 'new', 'active_epoch': EPOCH})
current_scope['current_state'] = {'episode': UUID}
check('same_scope_keeps_current_cosave_state', namespace['PrepareStateNamespace'](current_scope, 'new', EPOCH) and 'current_state' in current_scope)

print(json.dumps({
    'status': 'PASS',
    'evidence_kind': 'mechanically translated production policy functions with fake actor/JContainers surfaces; not Papyrus VM, CHIM native, co-save, or gameplay execution',
    'checks': len(checks),
    'cases': checks,
}, indent=2))
