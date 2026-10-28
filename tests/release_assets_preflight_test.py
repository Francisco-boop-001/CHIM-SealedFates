"""Release entry refuses to package before an explicit compatibility decision."""
import contextlib
import importlib.util
import io
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('release_assets', ROOT / 'scripts/release-assets.py')
release = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(release)


class ReleasePreflightEntryTest(unittest.TestCase):
    def test_missing_decision_does_not_build(self):
        with patch.object(release, 'build') as build, \
                patch.object(release, 'build_current_release') as build_current, \
                contextlib.redirect_stderr(io.StringIO()):
            with self.assertRaises(SystemExit) as error:
                release.main([])
        self.assertEqual(error.exception.code, 2)
        build.assert_not_called()
        build_current.assert_not_called()

    def test_failed_gate_does_not_build(self):
        with patch.object(release.gate, 'preflight', side_effect=ValueError('stale pairing')), \
                patch.object(release, 'build') as build, \
                patch.object(release, 'build_current_release') as build_current, \
                contextlib.redirect_stderr(io.StringIO()):
            with self.assertRaises(SystemExit) as error:
                release.main(['--department-interface', 'tested-paired', '--receipt', 'pairing.json'])
        self.assertEqual(error.exception.code, 2)
        build.assert_not_called()
        build_current.assert_not_called()

    def test_decision_precedes_build_and_is_recorded(self):
        order = []
        decision = {
            'schemaVersion': 1,
            'status': 'pass',
            'departmentInterface': 'web-only',
            'owner': {
                'version': '0.1.0-dev.29',
                'sourceClosureSha256': 'a' * 64,
            },
            'pairing': {'status': 'not-claimed'},
        }
        def check(*args):
            order.append('check')
            return decision
        def build_current(version, source_closure):
            order.append('build-current')
            self.assertEqual(version, '0.1.0-dev.29')
            self.assertEqual(source_closure, 'a' * 64)
            return {'fixture.zip': {'sha256': 'example'}}
        output = io.StringIO()
        with patch.object(release.gate, 'preflight', side_effect=check) as preflight, \
                patch.object(release, 'build') as build, \
                patch.object(release, 'build_current_release', side_effect=build_current) as build_current_mock, \
                contextlib.redirect_stdout(output):
            build.return_value = {'legacy.zip': {'sha256': 'example'}}
            release.main(['--department-interface', 'web-only'])
        preflight.assert_called_once_with(ROOT, 'web-only', None)
        build.assert_not_called()
        build_current_mock.assert_called_once_with('0.1.0-dev.29', 'a' * 64)
        self.assertEqual(order, ['check', 'build-current'])
        self.assertIn('"compatibility"', output.getvalue())
        self.assertIn('"web-only"', output.getvalue())


if __name__ == '__main__':
    unittest.main()
