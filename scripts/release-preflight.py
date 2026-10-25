"""Require an explicit Department-interface decision for a release build."""
import argparse
import hashlib
import importlib.util
import json
import re
import sys
from pathlib import Path, PurePosixPath
from typing import Optional

ROOT = Path(__file__).resolve().parents[1]
OWNER_VERSION = '0.1.0-dev.24'
FREEZE_SCHEMA = 'department.dev20.runtime-source-bindings.v1'
REQUIRED_COVERAGE = {
    'bootstrap', 'catalog', 'mailbox', 'detail', 'compose', 'reconcile',
    'restore_overlap', 'stale_authority', 'read_fence_lease_ownership',
    'operator_close_pruned', 'late_receipt', 'signed_unknown_partial',
    'no_resend', 'legacy_pins', 'full_forward_body',
}

spec = importlib.util.spec_from_file_location('package', ROOT / 'scripts' / 'build-package.py')
pkg = importlib.util.module_from_spec(spec)
spec.loader.exec_module(pkg)

def _hash(data):
    return hashlib.sha256(data).hexdigest()

def _object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError('Duplicate JSON field')
        result[key] = value
    return result

def _invalid_constant(_):
    raise ValueError('Invalid JSON number')

def _reject_symlink_components(path, description):
    absolute = Path(path).absolute()
    current = Path(absolute.anchor)
    for part in absolute.parts[1:]:
        current = current / part
        if current.is_symlink():
            raise ValueError('Symlink in ' + description)

def _read_json(path, description):
    path = Path(path)
    _reject_symlink_components(path, description)
    if not path.is_file():
        raise ValueError('Missing or unsafe ' + description)
    try:
        raw = path.read_bytes()
        value = json.loads(raw.decode('utf-8'), object_pairs_hook=_object,
                           parse_constant=_invalid_constant)
    except (OSError, UnicodeError, json.JSONDecodeError, ValueError) as error:
        raise ValueError('Missing or invalid ' + description) from error
    if not isinstance(value, dict):
        raise ValueError('Invalid ' + description)
    return value, raw

def _relative(value, description, sibling=False):
    if (not isinstance(value, str) or not value or '\\' in value or ':' in value or
            value.startswith('/') or value.startswith('//')):
        raise ValueError('Unsafe ' + description)
    path = PurePosixPath(value)
    if (path.is_absolute() or path.as_posix() != value or
            any(part in {'.', '..'} for part in path.parts) or
            (sibling and len(path.parts) != 1)):
        raise ValueError('Unsafe ' + description)
    return value

def _sha_value(value, description):
    if not isinstance(value, str) or not re.fullmatch(r'[0-9a-fA-F]{64}', value):
        raise ValueError('Invalid ' + description)
    return value

def _file_map(value, description):
    if not isinstance(value, dict) or not value:
        raise ValueError('Missing ' + description)
    result = {}
    for path, item in value.items():
        path = _relative(path, description + ' path')
        if not path.startswith('server/'):
            raise ValueError('Invalid ' + description + ' path')
        if not isinstance(item, dict) or set(item) != {'bytes', 'sha256'}:
            raise ValueError('Invalid ' + description + ' entry')
        if type(item['bytes']) is not int or item['bytes'] < 0:
            raise ValueError('Invalid ' + description + ' byte count')
        _sha_value(item['sha256'], description + ' hash')
        result[path] = {'bytes': item['bytes'], 'sha256': item['sha256']}
    return result

def _owner_source(root, relative):
    current = root
    for part in PurePosixPath(relative).parts:
        current = current / part
        if current.is_symlink():
            raise ValueError('Symlink in owner source closure')
    if not current.is_file() or not current.resolve().is_relative_to(root):
        raise ValueError('Missing or unsafe owner source closure file')
    return current

def _verify_owner_map(payload, owner_files):
    if 'server/manifest.json' not in owner_files:
        raise ValueError('Owner source closure omits its manifest')
    for relative, expected in owner_files.items():
        data = payload.get(relative)
        if not isinstance(data, bytes):
            raise ValueError('Missing or unsafe owner source closure file')
        if len(data) != expected['bytes'] or _hash(data) != expected['sha256'].lower():
            raise ValueError('Owner source closure changed')

def _sibling(directory, value, expected_name, description):
    path_name = _relative(value, description + ' filename', sibling=True)
    if path_name != expected_name:
        raise ValueError('Unexpected ' + description + ' filename')
    path = directory / path_name
    if path.is_symlink() or not path.is_file():
        raise ValueError('Missing or unsafe ' + description)
    return path

def _verify_readiness(readiness, owner_files, department_files):
    if type(readiness.get('schemaVersion')) is not int or readiness['schemaVersion'] != 1:
        raise ValueError('Unsupported source-readiness receipt')
    if readiness.get('ready') is not True or readiness.get('errorReason') is not None:
        raise ValueError('Department source readiness did not pass')
    owner = readiness.get('owner')
    if not isinstance(owner, dict) or owner.get('version') != OWNER_VERSION:
        raise ValueError('Source readiness owner identity differs')
    pins = owner.get('selectedClosurePins')
    if not isinstance(pins, list) or not pins:
        raise ValueError('Source readiness closure is missing')
    pin_map = {}
    for pin in pins:
        if not isinstance(pin, dict) or set(pin) != {'file', 'expectedSha256', 'actualSha256', 'matches'}:
            raise ValueError('Invalid source readiness pin')
        relative = _relative(pin['file'], 'source readiness path')
        if relative.startswith('server/') or pin['matches'] is not True:
            raise ValueError('Source readiness closure did not pass')
        path = 'server/' + relative
        expected = _sha_value(pin['expectedSha256'], 'source readiness hash')
        actual = _sha_value(pin['actualSha256'], 'source readiness hash')
        if expected.lower() != actual.lower() or path in pin_map:
            raise ValueError('Source readiness closure differs')
        pin_map[path] = actual
    if set(pin_map) != set(owner_files):
        raise ValueError('Source readiness closure differs from pairing receipt')
    if any(pin_map[path].lower() != item['sha256'].lower() for path, item in owner_files.items()):
        raise ValueError('Source readiness closure differs from pairing receipt')
    manifest_hash = _sha_value(owner.get('manifestSha256'), 'source readiness manifest hash')
    if manifest_hash.lower() != owner_files['server/manifest.json']['sha256'].lower():
        raise ValueError('Source readiness manifest differs')

    department = readiness.get('department')
    if not isinstance(department, dict):
        raise ValueError('Source readiness Department identity is missing')
    for key, relative in (('protocol', 'server/lib/protocol.php'),
                          ('adapter', 'server/adapters/correspondence.php')):
        pin = department.get(key)
        if not isinstance(pin, dict) or pin.get('file') != relative.removeprefix('server/'):
            raise ValueError('Source readiness Department identity differs')
        digest = _sha_value(pin.get('sha256'), 'Department source hash')
        if relative not in department_files or digest.lower() != department_files[relative]['sha256'].lower():
            raise ValueError('Source readiness Department identity differs')

    checks = readiness.get('checks')
    required_checks = {'adapterLoaded', 'manifestReady', 'sourceIdentityReady', 'runtimeReady', 'stagedClosureMatches'}
    if (not isinstance(checks, dict) or not required_checks.issubset(checks) or
            any(value is not True for value in checks.values())):
        raise ValueError('Department source-readiness checks did not pass')
    limits = readiness.get('limits')
    expected_limits = {
        'sourceOnly': True, 'database': False, 'http': False,
        'skyrim': False, 'ownerRuntimeLoaded': False,
    }
    if (not isinstance(limits, dict) or not set(expected_limits).issubset(limits) or
            any(type(value) is not bool for value in limits.values()) or
            any(limits[key] is not value for key, value in expected_limits.items())):
        raise ValueError('Department source-readiness limits differ')

def _verify_freeze(freeze, owner_files, department_files):
    if freeze.get('schema') != FREEZE_SCHEMA:
        raise ValueError('Unsupported runtime input freeze')
    entries = freeze.get('files')
    if not isinstance(entries, list) or not entries:
        raise ValueError('Runtime input freeze has no source files')
    owner_group = {}
    department_group = {}
    seen = set()
    for entry in entries:
        if not isinstance(entry, dict) or set(entry) != {'rootKey', 'path', 'bytes', 'sha256'}:
            raise ValueError('Invalid runtime input freeze entry')
        root_key = entry['rootKey']
        path = _relative(entry['path'], 'runtime input freeze path')
        if not isinstance(root_key, str) or not root_key.strip():
            raise ValueError('Invalid runtime input freeze root')
        identity = (root_key, path)
        if identity in seen:
            raise ValueError('Duplicate runtime input freeze entry')
        seen.add(identity)
        if type(entry['bytes']) is not int or entry['bytes'] < 0:
            raise ValueError('Invalid runtime input freeze byte count')
        digest = _sha_value(entry['sha256'], 'runtime input freeze hash')
        item = {'bytes': entry['bytes'], 'sha256': digest}
        if root_key == 'owner':
            if not path.startswith('server/'):
                raise ValueError('Invalid owner input-freeze path')
            owner_group[path] = item
        elif root_key == 'departmentServer':
            if path.startswith('server/'):
                raise ValueError('Invalid Department input-freeze path')
            department_group['server/' + path] = item
    if type(freeze.get('ownerClosureCount')) is not int or freeze['ownerClosureCount'] != len(owner_group):
        raise ValueError('Runtime input freeze owner count differs')
    if owner_group != owner_files or department_group != department_files:
        raise ValueError('Runtime input freeze source maps differ')

def _verify_behavior(behavior, owner, department, freeze_hash):
    if (type(behavior.get('schemaVersion')) is not int or behavior['schemaVersion'] != 1 or
            behavior.get('gate') != 'runtimeRelease' or behavior.get('status') != 'accepted' or
            behavior.get('currentCheckExecuted') is not True or
            not isinstance(behavior.get('runId'), str) or not behavior['runId'].strip()):
        raise ValueError('Runtime behavior gate did not pass')
    cases = behavior.get('cases')
    case_count = behavior.get('facadeCases')
    passed = behavior.get('passedCases')
    failed = behavior.get('failedCases')
    if (type(case_count) is not int or case_count <= 0 or type(passed) is not int or
            passed != case_count or type(failed) is not int or failed != 0 or
            not isinstance(cases, list) or len(cases) != case_count or
            any(not isinstance(case, dict) or case.get('status') != 'passed' or
                not isinstance(case.get('id'), str) or not case['id'].strip()
                for case in cases)):
        raise ValueError('Runtime behavior cases did not pass')
    case_ids = [case['id'] for case in cases]
    if len(case_ids) != len(set(case_ids)):
        raise ValueError('Runtime behavior case IDs are missing or duplicated')
    if behavior.get('ownerVersion') != OWNER_VERSION:
        raise ValueError('Runtime behavior owner version differs')
    gate_owner = _file_map(behavior.get('ownerFiles'), 'runtime behavior owner files')
    gate_department = _file_map(behavior.get('departmentFiles'), 'runtime behavior Department files')
    if gate_owner != owner or gate_department != department:
        raise ValueError('Runtime behavior source maps differ')
    coverage = behavior.get('behaviorCoverage')
    if (not isinstance(coverage, list) or any(not isinstance(item, str) for item in coverage) or
            len(coverage) != len(set(coverage)) or not REQUIRED_COVERAGE.issubset(coverage)):
        raise ValueError('Runtime behavior coverage is incomplete')
    required_true = (
        'sourcePreservationPassed', 'ownedPostgresStopVerified',
        'protectedBaselinesMatched',
    )
    if any(behavior.get(field) is not True for field in required_true):
        raise ValueError('Runtime behavior preservation or cleanup did not pass')
    # A shared TEST distro may already be running; the run's own PostgreSQL still must stop.
    if behavior.get('testDistroStopVerified') is not True:
        expected = {
            'schemaVersion': 1, 'distro': 'DwemerAI4Skyrim3-test2',
            'initialState': 'running', 'finalState': 'running',
            'startedByRun': False, 'stopRequested': False,
            'preservationVerified': True,
        }
        actual = behavior.get('testDistroLifecycle')
        if (behavior.get('testDistroStopVerified') is not False or
                not isinstance(actual, dict) or set(actual) != set(expected) or
                any(type(actual[key]) is not type(value) or actual[key] != value
                    for key, value in expected.items())):
            raise ValueError('Runtime behavior test distro lifecycle did not pass')
    if type(behavior.get('providerCalls')) is not int or behavior['providerCalls'] != 0:
        raise ValueError('Runtime behavior gate used a provider')
    manifest_hash = _sha_value(behavior.get('runtimeSourceManifestSha256'), 'runtime source manifest hash')
    if manifest_hash.lower() != freeze_hash.lower():
        raise ValueError('Runtime behavior source manifest hash differs')
    return case_count, coverage

def _verify_pairing(payload, receipt_path):
    receipt, _ = _read_json(receipt_path, 'pairing receipt')
    if (type(receipt.get('schemaVersion')) is not int or receipt['schemaVersion'] != 1 or
            receipt.get('kind') != 'department-owner-pairing' or receipt.get('status') != 'pass'):
        raise ValueError('Pairing receipt did not pass')
    owner = receipt.get('owner')
    department = receipt.get('department')
    if not isinstance(owner, dict) or owner.get('version') != OWNER_VERSION or not isinstance(department, dict):
        raise ValueError('Pairing receipt owner or Department identity differs')
    owner_files = _file_map(owner.get('files'), 'pairing owner files')
    department_files = _file_map(department.get('files'), 'pairing Department files')
    _verify_owner_map(payload, owner_files)
    if 'server/lib/protocol.php' not in department_files or 'server/adapters/correspondence.php' not in department_files:
        raise ValueError('Pairing receipt omits Department adapter inputs')

    readiness_ref = receipt.get('sourceReadiness')
    behavior_ref = receipt.get('behavior')
    if not isinstance(readiness_ref, dict) or not isinstance(behavior_ref, dict):
        raise ValueError('Pairing receipt evidence references are missing')
    evidence_dir = receipt_path.parent
    readiness_path = _sibling(evidence_dir, readiness_ref.get('receipt'), 'source-readiness.json', 'source-readiness receipt')
    behavior_path = _sibling(evidence_dir, behavior_ref.get('receipt'), 'runtime-release.json', 'runtime behavior receipt')
    freeze_path = _sibling(evidence_dir, behavior_ref.get('inputFreeze'), 'runtime-input-freeze.json', 'runtime input freeze')
    readiness, readiness_bytes = _read_json(readiness_path, 'source-readiness receipt')
    behavior, behavior_bytes = _read_json(behavior_path, 'runtime behavior receipt')
    freeze, freeze_bytes = _read_json(freeze_path, 'runtime input freeze')
    for reference, raw, label in (
            (readiness_ref, readiness_bytes, 'source-readiness'),
            (behavior_ref, behavior_bytes, 'runtime behavior')):
        digest = _sha_value(reference.get('sha256'), label + ' receipt hash')
        if digest.lower() != _hash(raw):
            raise ValueError('Stale ' + label + ' receipt hash')
    freeze_hash = _sha_value(behavior_ref.get('inputFreezeSha256'), 'runtime input freeze hash')
    if freeze_hash.lower() != _hash(freeze_bytes):
        raise ValueError('Stale runtime input freeze hash')

    _verify_readiness(readiness, owner_files, department_files)
    _verify_freeze(freeze, owner_files, department_files)
    case_count, coverage = _verify_behavior(behavior, owner_files, department_files, _hash(freeze_bytes))
    return {
        'status': 'tested-paired',
        'departmentFiles': department_files,
        'runtimeSourceManifestSha256': _hash(freeze_bytes),
        'facadeCases': case_count,
        'behaviorCoverage': sorted(coverage),
    }

def preflight(root: Path, department_interface: str, receipt: Optional[Path]) -> dict:
    if department_interface not in {'web-only', 'tested-paired'}:
        raise ValueError('An explicit web-only or tested-paired decision is required')
    if department_interface == 'web-only' and receipt is not None:
        raise ValueError('web-only does not accept a pairing receipt')
    if department_interface == 'tested-paired' and receipt is None:
        raise ValueError('tested-paired requires a pairing receipt')

    root = Path(root)
    if root.is_symlink() or not root.is_dir():
        raise ValueError('Owner project root is missing or unsafe')
    root = root.resolve()
    try:
        for name in pkg.SERVER_FILES:
            _owner_source(root, 'server/' + name)
        manifest, payload = pkg.entries(root)
    except (OSError, ValueError, json.JSONDecodeError) as error:
        raise ValueError('Owner source closure is missing, unsafe, or invalid') from error
    if manifest['version'] != OWNER_VERSION:
        raise ValueError('Unsupported owner release identity')
    owner_result = {
        'version': manifest['version'],
        'sourceClosureSha256': _hash(payload['checksums.sha256']),
    }
    if department_interface == 'web-only':
        pairing = {'status': 'not-claimed'}
    else:
        receipt_path = Path(receipt)
        pairing = _verify_pairing(payload, receipt_path)
    return {
        'schemaVersion': 1,
        'status': 'pass',
        'departmentInterface': department_interface,
        'owner': owner_result,
        'pairing': pairing,
    }

def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--department-interface', required=True, choices=('web-only', 'tested-paired'))
    parser.add_argument('--receipt', type=Path)
    args = parser.parse_args(argv)
    try:
        result = preflight(ROOT, args.department_interface, args.receipt)
    except (OSError, ValueError) as error:
        print(json.dumps({'status': 'refused', 'reason': str(error)}, sort_keys=True))
        return 1
    print(json.dumps(result, sort_keys=True))
    return 0

if __name__ == '__main__':
    sys.exit(main())
