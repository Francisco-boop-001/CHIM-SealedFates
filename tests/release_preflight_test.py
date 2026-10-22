import importlib.util
import hashlib
import json
import copy
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]

def load_module(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module

pkg = load_module('package', ROOT / 'scripts' / 'build-package.py')
gate = load_module('release_preflight', ROOT / 'scripts' / 'release-preflight.py')

class ReleasePreflightTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name) / 'project'
        for name in pkg.SERVER_FILES:
            path = self.root / 'server' / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(b'fixture:' + name.encode('utf-8'))
        manifest = {'name': 'correspondence', 'version': '0.1.0-dev.22'}
        (self.root / 'server' / 'manifest.json').write_text(json.dumps(manifest), encoding='utf-8')

    @staticmethod
    def encoded(value):
        return json.dumps(value, sort_keys=True, separators=(',', ':')).encode('utf-8')

    @staticmethod
    def digest(data):
        return hashlib.sha256(data).hexdigest()

    def file_entry(self, relative):
        data = (self.root / relative).read_bytes()
        return {'bytes': len(data), 'sha256': self.digest(data)}

    def pairing_fixtures(self):
        owner_files = {
            'server/manifest.json': self.file_entry('server/manifest.json'),
            'server/lib/store.php': self.file_entry('server/lib/store.php'),
        }
        department_files = {
            'server/lib/protocol.php': {'bytes': 120, 'sha256': '1' * 64},
            'server/adapters/correspondence.php': {'bytes': 240, 'sha256': '2' * 64},
        }
        coverage = [
            'bootstrap', 'catalog', 'mailbox', 'detail', 'compose', 'reconcile',
            'restore_overlap', 'stale_authority', 'read_fence_lease_ownership',
            'operator_close_pruned', 'late_receipt', 'signed_unknown_partial',
            'no_resend', 'legacy_pins', 'full_forward_body',
        ]
        readiness = {
            'schemaVersion': 1,
            'ready': True,
            'errorReason': None,
            'owner': {
                'version': '0.1.0-dev.22',
                'manifestSha256': owner_files['server/manifest.json']['sha256'],
                'selectedClosurePins': [
                    {'file': path.removeprefix('server/'),
                     'expectedSha256': entry['sha256'], 'actualSha256': entry['sha256'], 'matches': True}
                    for path, entry in owner_files.items()
                ],
            },
            'department': {
                'protocol': {'file': 'lib/protocol.php', 'sha256': department_files['server/lib/protocol.php']['sha256']},
                'adapter': {'file': 'adapters/correspondence.php', 'sha256': department_files['server/adapters/correspondence.php']['sha256']},
            },
            'checks': {
                'adapterLoaded': True, 'manifestReady': True, 'sourceIdentityReady': True,
                'runtimeReady': True, 'stagedClosureMatches': True,
                'protocolLoaded': True, 'sourceStable': True, 'temporaryCleanup': True,
            },
            'limits': {
                'sourceOnly': True, 'database': False, 'http': False,
                'skyrim': False, 'ownerRuntimeLoaded': False, 'temporaryStaging': True,
            },
        }
        freeze = {
            'schema': 'department.dev20.runtime-source-bindings.v1',
            'ownerClosureCount': len(owner_files),
            'files': [
                {'rootKey': 'owner', 'path': path, **entry}
                for path, entry in owner_files.items()
            ] + [
                {'rootKey': 'departmentServer', 'path': path.removeprefix('server/'), **entry}
                for path, entry in department_files.items()
            ] + [
                {'rootKey': 'stockCore', 'path': 'lib/fixture.php', 'bytes': 16, 'sha256': '3' * 64},
            ],
        }
        behavior = {
            'schemaVersion': 1,
            'gate': 'runtimeRelease',
            'status': 'accepted',
            'currentCheckExecuted': True,
            'runId': 'fixture-run',
            'facadeCases': len(coverage),
            'passedCases': len(coverage),
            'failedCases': 0,
            'cases': [{'id': 'case-' + str(index), 'status': 'passed'}
                      for index, _ in enumerate(coverage)],
            'ownerVersion': '0.1.0-dev.22',
            'ownerFiles': copy.deepcopy(owner_files),
            'departmentFiles': copy.deepcopy(department_files),
            'behaviorCoverage': coverage,
            'sourcePreservationPassed': True,
            'ownedPostgresStopVerified': True,
            'testDistroStopVerified': True,
            'protectedBaselinesMatched': True,
            'providerCalls': 0,
        }
        envelope = {
            'schemaVersion': 1,
            'kind': 'department-owner-pairing',
            'status': 'pass',
            'owner': {'version': '0.1.0-dev.22', 'files': copy.deepcopy(owner_files)},
            'department': {'files': copy.deepcopy(department_files)},
            'sourceReadiness': {'receipt': 'source-readiness.json'},
            'behavior': {
                'receipt': 'runtime-release.json',
                'inputFreeze': 'runtime-input-freeze.json',
            },
        }
        return envelope, readiness, behavior, freeze

    def write_pairing(self, envelope, readiness, behavior, freeze, stale_hash=None):
        readiness_bytes = self.encoded(readiness)
        freeze_bytes = self.encoded(freeze)
        behavior['runtimeSourceManifestSha256'] = self.digest(freeze_bytes)
        behavior_bytes = self.encoded(behavior)
        hashes = {
            'sourceReadiness': self.digest(readiness_bytes),
            'behavior': self.digest(behavior_bytes),
            'inputFreeze': self.digest(freeze_bytes),
        }
        for key, value in hashes.items():
            if stale_hash != key:
                if key == 'sourceReadiness':
                    envelope['sourceReadiness']['sha256'] = value
                elif key == 'behavior':
                    envelope['behavior']['sha256'] = value
                else:
                    envelope['behavior']['inputFreezeSha256'] = value
        directory = self.root / 'evidence'
        directory.mkdir(exist_ok=True)
        (directory / 'source-readiness.json').write_bytes(readiness_bytes)
        (directory / 'runtime-release.json').write_bytes(behavior_bytes)
        (directory / 'runtime-input-freeze.json').write_bytes(freeze_bytes)
        receipt = directory / 'department-owner-pairing.json'
        receipt.write_bytes(self.encoded(envelope))
        return receipt

    def test_web_only_is_explicit_and_binds_current_owner_closure(self):
        result = gate.preflight(self.root, 'web-only', None)
        self.assertEqual(result['departmentInterface'], 'web-only')
        self.assertEqual(result['owner']['version'], '0.1.0-dev.22')
        first_hash = result['owner']['sourceClosureSha256']
        self.assertRegex(first_hash, r'^[0-9a-f]{64}$')

        source = self.root / 'server' / 'lib' / 'store.php'
        source.write_bytes(source.read_bytes() + b'changed')
        changed = gate.preflight(self.root, 'web-only', None)
        self.assertNotEqual(first_hash, changed['owner']['sourceClosureSha256'])

    def test_web_only_does_not_accept_a_pairing_receipt(self):
        with self.assertRaises(ValueError):
            gate.preflight(self.root, 'web-only', Path('receipt.json'))

    def test_tested_pair_requires_and_reports_a_valid_exact_pairing(self):
        fixtures = self.pairing_fixtures()
        receipt = self.write_pairing(*fixtures)
        result = gate.preflight(self.root, 'tested-paired', receipt)
        self.assertEqual(result['departmentInterface'], 'tested-paired')
        self.assertEqual(result['owner']['version'], '0.1.0-dev.22')
        self.assertEqual(result['pairing']['departmentFiles'], fixtures[0]['department']['files'])

    def test_tested_pair_refuses_source_changed_between_capture_and_receipt_verification(self):
        envelope, readiness, behavior, freeze = self.pairing_fixtures()
        receipt = self.write_pairing(envelope, readiness, behavior, freeze)
        source = self.root / 'server' / 'lib' / 'store.php'
        self.assertNotEqual(source.resolve(), (ROOT / 'server' / 'lib' / 'store.php').resolve())
        reviewed_bytes = source.read_bytes()
        changed_bytes = reviewed_bytes + b':unreviewed-A'
        source.write_bytes(changed_bytes)
        real_entries = gate.pkg.entries

        def capture_changed_payload_then_restore(root):
            manifest, payload = real_entries(root)
            self.assertEqual(payload['server/lib/store.php'], changed_bytes)
            source.write_bytes(reviewed_bytes)
            return manifest, payload

        try:
            with patch.object(gate.pkg, 'entries', side_effect=capture_changed_payload_then_restore):
                with self.assertRaises(ValueError):
                    gate.preflight(self.root, 'tested-paired', receipt)
        finally:
            source.write_bytes(reviewed_bytes)

    def test_tested_pair_refuses_missing_or_malformed_evidence(self):
        with self.assertRaises(ValueError):
            gate.preflight(self.root, 'tested-paired', None)
        receipt = self.root / 'broken.json'
        receipt.write_text('{', encoding='utf-8')
        with self.assertRaises(ValueError):
            gate.preflight(self.root, 'tested-paired', receipt)

    def test_tested_pair_refuses_stale_source_and_evidence_hashes(self):
        envelope, readiness, behavior, freeze = self.pairing_fixtures()
        receipt = self.write_pairing(envelope, readiness, behavior, freeze)
        source = self.root / 'server' / 'lib' / 'store.php'
        source.write_bytes(source.read_bytes() + b'changed')
        with self.assertRaises(ValueError):
            gate.preflight(self.root, 'tested-paired', receipt)

        for stale_hash in ('sourceReadiness', 'behavior', 'inputFreeze'):
            with self.subTest(stale_hash=stale_hash):
                envelope, readiness, behavior, freeze = self.pairing_fixtures()
                receipt = self.write_pairing(envelope, readiness, behavior, freeze, stale_hash=stale_hash)
                with self.assertRaises(ValueError):
                    gate.preflight(self.root, 'tested-paired', receipt)

    def test_tested_pair_refuses_missing_coverage_zero_cases_and_bool_as_integer(self):
        for mutation in ('missing_coverage', 'zero_cases', 'bool_cases'):
            with self.subTest(mutation=mutation):
                envelope, readiness, behavior, freeze = self.pairing_fixtures()
                if mutation == 'missing_coverage':
                    behavior['behaviorCoverage'].remove('operator_close_pruned')
                elif mutation == 'zero_cases':
                    behavior.update(facadeCases=0, passedCases=0, failedCases=0, cases=[])
                else:
                    behavior['facadeCases'] = True
                receipt = self.write_pairing(envelope, readiness, behavior, freeze)
                with self.assertRaises(ValueError):
                    gate.preflight(self.root, 'tested-paired', receipt)

    def test_tested_pair_requires_nonempty_unique_runtime_case_ids(self):
        for mutation in ('missing', 'empty', 'duplicate'):
            with self.subTest(mutation=mutation):
                envelope, readiness, behavior, freeze = self.pairing_fixtures()
                if mutation == 'missing':
                    del behavior['cases'][0]['id']
                elif mutation == 'empty':
                    behavior['cases'][0]['id'] = '  '
                else:
                    behavior['cases'][1]['id'] = behavior['cases'][0]['id']
                receipt = self.write_pairing(envelope, readiness, behavior, freeze)
                with self.assertRaises(ValueError):
                    gate.preflight(self.root, 'tested-paired', receipt)

    def test_tested_pair_refuses_symlinked_evidence_parent(self):
        envelope, readiness, behavior, freeze = self.pairing_fixtures()
        receipt = self.write_pairing(envelope, readiness, behavior, freeze)
        linked = self.root / 'linked-evidence'
        try:
            linked.symlink_to(receipt.parent, target_is_directory=True)
        except (OSError, NotImplementedError) as error:
            self.skipTest('Directory symlinks unavailable: ' + str(error))
        with self.assertRaises(ValueError):
            gate.preflight(self.root, 'tested-paired', linked / receipt.name)

    def test_tested_pair_refuses_inconsistent_department_maps_and_unsafe_sibling_paths(self):
        envelope, readiness, behavior, freeze = self.pairing_fixtures()
        behavior['departmentFiles']['server/lib/protocol.php']['sha256'] = '4' * 64
        receipt = self.write_pairing(envelope, readiness, behavior, freeze)
        with self.assertRaises(ValueError):
            gate.preflight(self.root, 'tested-paired', receipt)

        envelope, readiness, behavior, freeze = self.pairing_fixtures()
        freeze['files'][0]['sha256'] = '5' * 64
        receipt = self.write_pairing(envelope, readiness, behavior, freeze)
        with self.assertRaises(ValueError):
            gate.preflight(self.root, 'tested-paired', receipt)

        envelope, readiness, behavior, freeze = self.pairing_fixtures()
        readiness['owner']['selectedClosurePins'][1]['actualSha256'] = '6' * 64
        receipt = self.write_pairing(envelope, readiness, behavior, freeze)
        with self.assertRaises(ValueError):
            gate.preflight(self.root, 'tested-paired', receipt)

    def test_tested_pair_refuses_nonpass_readiness_and_behavior_claims(self):
        envelope, readiness, behavior, freeze = self.pairing_fixtures()
        readiness['checks']['runtimeReady'] = False
        receipt = self.write_pairing(envelope, readiness, behavior, freeze)
        with self.assertRaises(ValueError):
            gate.preflight(self.root, 'tested-paired', receipt)

        envelope, readiness, behavior, freeze = self.pairing_fixtures()
        behavior['status'] = 'failed'
        receipt = self.write_pairing(envelope, readiness, behavior, freeze)
        with self.assertRaises(ValueError):
            gate.preflight(self.root, 'tested-paired', receipt)

        envelope, readiness, behavior, freeze = self.pairing_fixtures()
        readiness['checks']['protocolLoaded'] = False
        receipt = self.write_pairing(envelope, readiness, behavior, freeze)
        with self.assertRaises(ValueError):
            gate.preflight(self.root, 'tested-paired', receipt)

        envelope, readiness, behavior, freeze = self.pairing_fixtures()
        readiness['limits']['database'] = 0
        receipt = self.write_pairing(envelope, readiness, behavior, freeze)
        with self.assertRaises(ValueError):
            gate.preflight(self.root, 'tested-paired', receipt)

        envelope, readiness, behavior, freeze = self.pairing_fixtures()
        envelope['sourceReadiness']['receipt'] = '../source-readiness.json'
        receipt = self.write_pairing(envelope, readiness, behavior, freeze)
        with self.assertRaises(ValueError):
            gate.preflight(self.root, 'tested-paired', receipt)

    def test_additional_readiness_check_must_pass(self):
        envelope, readiness, behavior, freeze = self.pairing_fixtures()
        readiness['checks']['futureCompatibilityCheck'] = True
        receipt = self.write_pairing(envelope, readiness, behavior, freeze)
        self.assertEqual(gate.preflight(self.root, 'tested-paired', receipt)['status'], 'pass')
        readiness['checks']['futureCompatibilityCheck'] = False
        receipt = self.write_pairing(envelope, readiness, behavior, freeze)
        with self.assertRaises(ValueError):
            gate.preflight(self.root, 'tested-paired', receipt)

    def test_unknown_owner_version_fails_closed_in_web_only_mode(self):
        path = self.root / 'server' / 'manifest.json'
        path.write_text(json.dumps({'name': 'correspondence', 'version': '0.1.0-dev.23'}), encoding='utf-8')
        with self.assertRaises(ValueError):
            gate.preflight(self.root, 'web-only', None)

    def test_unknown_owner_version_fails_closed_in_tested_pair_mode(self):
        path = self.root / 'server' / 'manifest.json'
        path.write_text(json.dumps({'name': 'correspondence', 'version': '0.1.0-dev.23'}), encoding='utf-8')
        with self.assertRaises(ValueError):
            gate.preflight(self.root, 'tested-paired', None)

    def test_invalid_interface_fails_closed(self):
        with self.assertRaises(ValueError):
            gate.preflight(self.root, 'automatic', None)

    def test_malformed_owner_manifest_is_a_controlled_refusal(self):
        fixture_manifest = Path(self.temp.name) / 'project/server/manifest.json'
        self.assertNotEqual(fixture_manifest.resolve(), (ROOT / 'server/manifest.json').resolve())
        original = fixture_manifest.read_bytes()
        try:
            for manifest in ([], None, {'name': 'correspondence', 'version': 20},
                             {'name': 'correspondence', 'version': None}):
                with self.subTest(manifest=manifest):
                    fixture_manifest.write_text(json.dumps(manifest), encoding='utf-8')
                    with self.assertRaises(ValueError):
                        gate.preflight(self.root, 'web-only', None)
        finally:
            fixture_manifest.write_bytes(original)

    def test_command_line_requires_an_explicit_interface_choice(self):
        import subprocess
        import sys

        run = subprocess.run([sys.executable, '-B', str(ROOT / 'scripts' / 'release-preflight.py')],
                             capture_output=True, text=True, timeout=15)
        self.assertEqual(run.returncode, 2)
        self.assertIn('--department-interface', run.stderr)

if __name__ == '__main__':
    unittest.main()
