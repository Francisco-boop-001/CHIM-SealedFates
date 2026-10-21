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
        with patch.object(release, 'build') as build, contextlib.redirect_stderr(io.StringIO()):
            with self.assertRaises(SystemExit) as error:
                release.main([])
        self.assertEqual(error.exception.code, 2)
        build.assert_not_called()

    def test_failed_gate_does_not_build(self):
        with patch.object(release.gate, 'preflight', side_effect=ValueError('stale pairing')), \
                patch.object(release, 'build') as build, contextlib.redirect_stderr(io.StringIO()):
            with self.assertRaises(SystemExit) as error:
                release.main(['--department-interface', 'tested-paired', '--receipt', 'pairing.json'])
        self.assertEqual(error.exception.code, 2)
        build.assert_not_called()

    def test_decision_precedes_build_and_is_recorded(self):
        order = []
        decision = {'status': 'web-only', 'ownerVersion': '0.1.0-dev.20'}
        def check(*args):
            order.append('check')
            return decision
        def build():
            order.append('build')
            return {'fixture.zip': {'sha256': 'example'}}
        output = io.StringIO()
        with patch.object(release.gate, 'preflight', side_effect=check) as preflight, \
                patch.object(release, 'build', side_effect=build), contextlib.redirect_stdout(output):
            release.main(['--department-interface', 'web-only'])
        preflight.assert_called_once_with(ROOT, 'web-only', None)
        self.assertEqual(order, ['check', 'build'])
        self.assertIn('"compatibility"', output.getvalue())
        self.assertIn('"web-only"', output.getvalue())


if __name__ == '__main__':
    unittest.main()
