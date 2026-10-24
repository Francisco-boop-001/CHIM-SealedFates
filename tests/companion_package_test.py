import hashlib
import importlib.util
import json
import tempfile
import unittest
from pathlib import Path
from zipfile import ZipFile

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('companion_package', ROOT / 'scripts/build-package.py')
pkg = importlib.util.module_from_spec(spec)
spec.loader.exec_module(pkg)


class CompanionPackageTest(unittest.TestCase):
    def test_server_payload_includes_companion_runtime(self):
        required = {'lib/companion.php', 'lib/companion_policy.php', 'lib/companion_host.php', 'migrations/005_companion.sql'}
        self.assertTrue(required.issubset(pkg.SERVER_FILES), required - set(pkg.SERVER_FILES))

    def test_companion_binary_is_source_bound_and_anonymous(self):
        data = pkg.verify_companion(ROOT)
        manifest = json.loads((ROOT / 'game/companion-manifest.json').read_text(encoding='utf8'))
        header = pkg.pex_header(data)
        self.assertEqual((header['source'], header['user'], header['computer']), ('CHIMCorrespondenceCompanion.psc', 'anonymous', 'anonymous'))
        self.assertEqual(hashlib.sha256(data).hexdigest(), manifest['pex']['sha256'])
        with tempfile.TemporaryDirectory(dir=ROOT / 'dist') as temporary:
            fixture = Path(temporary)
            for name in (manifest['source']['path'], manifest['pex']['path'], manifest['build_receipt']):
                target = fixture / name
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes((ROOT / name).read_bytes())
            (fixture / 'game/companion-manifest.json').write_text(json.dumps(manifest), encoding='utf8')
            (fixture / manifest['source']['path']).write_text('changed source', encoding='utf8')
            with self.assertRaisesRegex(ValueError, 'source'):
                pkg.verify_companion(fixture)

    def test_one_zip_contains_both_scripts_and_version_only_server_package(self):
        manifest = json.loads((ROOT / 'server/manifest.json').read_text(encoding='utf8'))
        with tempfile.TemporaryDirectory(dir=ROOT / 'dist') as temporary:
            result = pkg.build(ROOT, None, Path(temporary))
            with ZipFile(result['bundle']) as archive:
                self.assertEqual(set(archive.namelist()), {'Scripts/CHIMCorrespondenceBridge.pex', 'Scripts/CHIMCorrespondenceCompanion.pex', 'CHIM/server-plugins/correspondence/' + manifest['version'] + '.dwpkg'})
                self.assertEqual(archive.read('Scripts/CHIMCorrespondenceCompanion.pex'), pkg.verify_companion(ROOT))


if __name__ == '__main__':
    unittest.main()
