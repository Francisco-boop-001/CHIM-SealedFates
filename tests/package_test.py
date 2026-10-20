import hashlib
import importlib.util
import json
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from zipfile import ZipFile

ROOT = Path(__file__).resolve().parents[1]
TEST_TMP_ROOT = ROOT.parent
spec = importlib.util.spec_from_file_location('package', ROOT / 'scripts' / 'build-package.py')
pkg = importlib.util.module_from_spec(spec)
spec.loader.exec_module(pkg)
release_spec = importlib.util.spec_from_file_location('release_assets', ROOT / 'scripts' / 'release-assets.py')
release = importlib.util.module_from_spec(release_spec)
release_spec.loader.exec_module(release)

class PackageTest(unittest.TestCase):
    def setUp(self):
        TEST_TMP_ROOT.mkdir(parents=True, exist_ok=True)
        self.temp = tempfile.TemporaryDirectory(dir=TEST_TMP_ROOT)
        self.addCleanup(self.temp.cleanup)
        self.e8 = Path(self.temp.name) / 'e8'
        # The project's parent differs from the evidence root, as in the canonical layout.
        self.root = self.e8 / 'projects' / 'CHIM-Correspondence-dev'
        self.root.mkdir(parents=True)
        for name in pkg.SERVER_FILES:
            path = self.root / 'server' / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text('{}' if name.endswith('.json') else 'fixture', encoding='utf-8')
        (self.root / 'server/manifest.json').write_text(json.dumps({'name':'correspondence','version':'0.1.0-dev.1'}))
        source = ROOT / 'game' / 'Source' / 'Scripts' / 'CHIMCorrespondenceBridge.psc'
        source_copy = self.root / 'game' / 'Source' / 'Scripts' / source.name
        source_copy.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(source, source_copy)
        original_manifest = json.loads((ROOT / 'game' / 'bridge-manifest.json').read_text(encoding='utf-8'))
        original_pex = self.resolve_evidence(original_manifest, original_manifest['pex']['path'])
        original_receipt = self.resolve_evidence(original_manifest, original_manifest['build_receipt'])
        self.assertTrue(original_pex.is_file(), 'A real Caprica PEX is required; arbitrary-byte positive fixtures are forbidden')
        self.assertTrue(original_receipt.is_file(), 'The paired Caprica build receipt is required')
        run = self.e8 / 'bridge' / 'test-run'
        self.pex = run / 'Data' / 'Scripts' / 'CHIMCorrespondenceBridge.pex'
        self.pex.parent.mkdir(parents=True)
        shutil.copyfile(original_pex, self.pex)
        self.receipt = run / 'build-receipt.json'
        receipt = json.loads(original_receipt.read_text(encoding='utf-8'))
        receipt['pex']['path'] = self.pex.relative_to(self.e8).as_posix()
        self.receipt.write_text(json.dumps(receipt, indent=2) + '\n', encoding='utf-8')
        self.bridge_manifest = original_manifest
        self.bridge_manifest['build_evidence_root'] = str(self.e8)
        self.bridge_manifest['pex']['path'] = self.pex.relative_to(self.e8).as_posix()
        self.bridge_manifest['build_receipt'] = self.receipt.relative_to(self.e8).as_posix()
        self.bridge_manifest['build_receipt_sha256'] = hashlib.sha256(self.receipt.read_bytes()).hexdigest()
        manifest_path = self.root / 'game' / 'bridge-manifest.json'
        manifest_path.parent.mkdir(parents=True, exist_ok=True)
        manifest_path.write_text(json.dumps(self.bridge_manifest, indent=2) + '\n', encoding='utf-8')

    @staticmethod
    def resolve_evidence(original_manifest, path):
        path = Path(path)
        evidence_root = Path(original_manifest['build_evidence_root'])
        if evidence_root == Path('.'):
            evidence_root = ROOT
        return path if path.is_absolute() else evidence_root / path

    @staticmethod
    def pex_header(data):
        if len(data) < 16 or data[:4] != bytes.fromhex('FA57C0DE'):
            raise ValueError('not a PEX header')
        offset = 16
        strings = []
        for _ in range(3):
            if offset + 2 > len(data):
                raise ValueError('truncated PEX header string length')
            size = int.from_bytes(data[offset:offset + 2], 'big')
            offset += 2
            if offset + size > len(data):
                raise ValueError('truncated PEX header string')
            strings.append(data[offset:offset + size].decode('utf-8'))
            offset += size
        return {'source': strings[0], 'user': strings[1], 'computer': strings[2], 'end': offset}
    def test_autonomy_runtime_membership(self):
        required={'postrequest.php','lib/autonomy_policy.php','lib/autonomy_store.php','lib/autonomy_host.php','migrations/002_autonomy.sql'}
        self.assertTrue(required.issubset(set(pkg.SERVER_FILES)), 'Autonomous mail runtime and migration must ship together')
    def test_offscreen_runtime_membership(self):
        required={'offscreen-worker.php','offscreen-service.php','lib/offscreen_host.php','lib/offscreen.php','lib/offscreen_policy.php','migrations/003_offscreen.sql'}
        self.assertTrue(required.issubset(set(pkg.SERVER_FILES)), 'Offscreen helper, engine and schema must ship together')
    def test_bridge_manifest_binds_source_compiler_and_exact_pex(self):
        manifest=json.loads((self.root/'game/bridge-manifest.json').read_text(encoding='utf-8'))
        source=(self.root/'game/Source/Scripts/CHIMCorrespondenceBridge.psc').read_bytes()
        pex=self.pex.read_bytes()
        self.assertEqual(manifest['source']['sha256'],hashlib.sha256(source).hexdigest())
        self.assertEqual(manifest['pex']['sha256'],hashlib.sha256(pex).hexdigest())
        self.assertEqual(manifest['build_receipt_sha256'],hashlib.sha256(self.receipt.read_bytes()).hexdigest())
        self.assertRegex(manifest['compiler']['sha256'],r'^[0-9A-Fa-f]{64}$')
    def test_canonical_project_relocates_from_explicit_evidence_root(self):
        self.assertEqual(self.root.parent, self.e8 / 'projects')
        self.assertEqual(pkg.verify_bridge(self.root, self.pex), self.pex.read_bytes())
    def test_public_checkout_rebuilds_from_relative_evidence(self):
        checkout = Path(self.temp.name) / 'public-checkout'
        checkout.mkdir()
        manifest = json.loads((ROOT / 'game' / 'bridge-manifest.json').read_text(encoding='utf-8'))
        self.assertEqual(manifest['build_evidence_root'], '.')
        files = [
            *(('server/' + name) for name in pkg.SERVER_FILES),
            'scripts/build-package.py',
            'game/Source/Scripts/CHIMCorrespondenceBridge.psc',
            'game/bridge-manifest.json',
            manifest['pex']['path'],
            manifest['build_receipt'],
        ]
        for relative in files:
            source = ROOT / relative
            target = checkout / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(source, target)
        result = subprocess.run(
            [sys.executable, str(checkout / 'scripts' / 'build-package.py'),
             '--pex', manifest['pex']['path'], '--output', 'dist'],
            cwd=checkout, capture_output=True, text=True, check=False)
        self.assertEqual(result.returncode, 0, result.stderr)
        artifacts = json.loads(result.stdout)
        server_version = json.loads((checkout / 'server/manifest.json').read_text(encoding='utf-8'))['version']
        if server_version == '0.1.0-dev.14':
            self.assertEqual(artifacts['server']['sha256'], '20d3da4117479957471491d502126154e054b4543fdf32b03d4fb3f35ab6bd8c')
            self.assertEqual(artifacts['bundle']['sha256'], 'e6ef362295da9ffa1a22959f2ab7714ac18fc172f6404a4d930f458626937b1f')
        else:
            self.assertEqual(Path(artifacts['server']['path']).name, 'correspondence-' + server_version + '.dwpkg')
            with ZipFile(artifacts['server']['path']) as archive:
                for name in pkg.SERVER_FILES:
                    self.assertEqual(archive.read('server/' + name), (checkout / 'server' / name).read_bytes())
    def test_pex_sanitizer_uses_explicit_evidence_root_after_relocation(self):
        clean = self.e8 / 'bridge' / 'test-run' / 'sanitized-copy.pex'
        result = subprocess.run(
            [sys.executable, str(ROOT / 'scripts' / 'build-package.py'),
             '--evidence-root', str(self.e8), '--sanitize-pex', str(self.pex),
             '--sanitized-output', str(clean)],
            capture_output=True, text=True, check=False)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(clean.read_bytes(), self.pex.read_bytes())
    def test_metadata_sanitizer_preserves_every_nonheader_byte(self):
        original=self.pex.read_bytes()
        original_header=self.pex_header(original)
        private_header=(original[:16] + pkg.pex_string(r'C:\synthetic\CHIMCorrespondenceBridge.psc') +
                        pkg.pex_string('synthetic-user') + pkg.pex_string('synthetic-computer') +
                        original[original_header['end']:])
        clean=pkg.anonymize_pex(private_header)
        before=self.pex_header(private_header)
        after=self.pex_header(clean)
        self.assertTrue(before['source'].startswith('C:'))
        self.assertNotEqual(before['user'], 'anonymous')
        self.assertEqual((after['source'],after['user'],after['computer']),('CHIMCorrespondenceBridge.psc','anonymous','anonymous'))
        self.assertEqual(original[original_header['end']:],private_header[before['end']:])
        self.assertEqual(private_header[before['end']:],clean[after['end']:])
    def test_magic_only_and_unpaired_bridge_are_rejected(self):
        original=self.pex.read_bytes()
        self.pex.write_bytes(bytes.fromhex('FA57C0DE'))
        with self.assertRaises(ValueError): pkg.build(self.root,self.pex,self.root/'magic-out')
        self.pex.write_bytes(original)
        manifest_path=self.root/'game/bridge-manifest.json'
        manifest=json.loads(manifest_path.read_text(encoding='utf-8'))
        manifest['pex']['sha256']='0'*64
        manifest_path.write_text(json.dumps(manifest),encoding='utf-8')
        with self.assertRaises(ValueError): pkg.build(self.root,self.pex,self.root/'unpaired-out')
    def test_manifest_rejects_tampered_provenance_receipt(self):
        self.receipt.write_text(self.receipt.read_text(encoding='utf-8') + ' ', encoding='utf-8')
        with self.assertRaisesRegex(ValueError, 'receipt hash'):
            pkg.verify_bridge(self.root, self.pex)
    def test_private_pex_metadata_is_not_packaged(self):
        result=pkg.build(self.root,self.pex,self.root/'out')
        with ZipFile(result['bundle']) as archive:
            metadata=self.pex_header(archive.read('Scripts/CHIMCorrespondenceBridge.pex'))
        self.assertNotRegex(metadata['source'],r'(?i)([a-z]:[\\/]|\\\\)')
        self.assertEqual(metadata['user'],'anonymous')
        self.assertEqual(metadata['computer'],'anonymous')
    def test_art_and_style_are_packaged_byte_for_byte(self):
        expected = {}
        for name in ('dashboard-art.webp', 'assets/style.css'):
            data = (ROOT / 'server' / name).read_bytes()
            expected['server/' + name] = data
            (self.root / 'server' / name).write_bytes(data)
        result = pkg.build(self.root, self.pex, self.root / 'asset-out')
        with ZipFile(result['server']) as archive:
            for name, data in expected.items():
                self.assertEqual(archive.read(name), data)
        with ZipFile(result['bundle']) as archive:
            nested = archive.read('CHIM/server-plugins/correspondence/' + Path(result['server']).name)
        self.assertEqual(nested, Path(result['server']).read_bytes())

    def test_dev15_release_has_only_current_four_assets(self):
        manifest_path = self.root / 'server/manifest.json'
        manifest = json.loads(manifest_path.read_text(encoding='utf-8'))
        manifest['version'] = '0.1.0-dev.15'
        manifest_path.write_text(json.dumps(manifest), encoding='utf-8')
        original_root = release.ROOT
        release.ROOT = self.root
        try:
            artifacts = release.build()
        finally:
            release.ROOT = original_root
        expected = {
            'correspondence-0.1.0-dev.15.dwpkg',
            'CHIM-Sealed-Fates-0.1.0-dev.15.zip',
            'correspondence.tar.gz',
            'SHA256SUMS.txt',
        }
        out = self.root / 'dist'
        self.assertEqual({path.name for path in out.iterdir()}, expected)
        self.assertEqual(set(artifacts), expected)
        checksum_names = [line.split('  ', 1)[1] for line in (out / 'SHA256SUMS.txt').read_text(encoding='ascii').splitlines()]
        self.assertEqual(checksum_names, sorted(expected - {'SHA256SUMS.txt'}))

    def test_dev15_preflight_preserves_legacy_and_conflicting_outputs(self):
        manifest_path = self.root / 'server/manifest.json'
        manifest = json.loads(manifest_path.read_text(encoding='utf-8'))
        manifest['version'] = '0.1.0-dev.15'
        manifest_path.write_text(json.dumps(manifest), encoding='utf-8')
        original_root = release.ROOT
        release.ROOT = self.root
        out = self.root / 'dist'
        out.mkdir()
        legacy = {
            out / 'CHIM-Sealed-Fates-Recovery-Playtest-20261019-v1.zip': b'old recovery kit',
            out / 'correspondence-0.1.0-dev.14.dwpkg': b'old dev14 package',
        }
        try:
            for path, data in legacy.items():
                path.write_bytes(data)
            with self.assertRaisesRegex(ValueError, 'Unexpected dev15 output'):
                release.build()
            self.assertEqual({path.name for path in out.iterdir()}, {path.name for path in legacy})
            self.assertEqual({path: path.read_bytes() for path in legacy}, legacy)

            for path in legacy:
                path.unlink()
            stale_catalog = out / 'correspondence.tar.gz'
            stale_catalog.write_bytes(b'old dev14 catalog')
            with self.assertRaisesRegex(ValueError, 'different release bytes'):
                release.build()
            self.assertEqual([path.name for path in out.iterdir()], [stale_catalog.name])
            self.assertEqual(stale_catalog.read_bytes(), b'old dev14 catalog')
        finally:
            release.ROOT = original_root

    def test_release_rejects_unsupported_version(self):
        manifest_path = self.root / 'server/manifest.json'
        manifest = json.loads(manifest_path.read_text(encoding='utf-8'))
        manifest['version'] = '0.1.0-dev.16'
        manifest_path.write_text(json.dumps(manifest), encoding='utf-8')
        original_root = release.ROOT
        release.ROOT = self.root
        try:
            with self.assertRaisesRegex(ValueError, 'Unsupported release identity'):
                release.build()
        finally:
            release.ROOT = original_root
        self.assertFalse((self.root / 'dist').exists())

    def test_exact_deterministic_payload_and_tamper(self):
        a = pkg.build(self.root, self.pex, self.root/'out')
        first = {name:Path(path).read_bytes() for name,path in a.items()}
        b = pkg.build(self.root, self.pex, self.root/'out')
        self.assertEqual(first, {name:Path(path).read_bytes() for name,path in b.items()})
        self.assertEqual(Path(a['server']).name, 'correspondence-0.1.0-dev.1.dwpkg')
        self.assertEqual(Path(a['bundle']).name, 'CHIM-Sealed-Fates-0.1.0-dev.1.zip')
        with ZipFile(a['server']) as z:
            self.assertEqual(json.loads(z.read('manifest.json'))['name'], 'correspondence')
        with ZipFile(a['bundle']) as z:
            self.assertEqual(set(z.namelist()), {'Scripts/CHIMCorrespondenceBridge.pex', 'CHIM/server-plugins/correspondence/'+Path(a['server']).name})
        archive = Path(a['server'])
        with ZipFile(archive,'a') as z: z.writestr('server/conf.php','private')
        with self.assertRaises(ValueError): pkg.verify(archive,self.root)
    def test_changed_candidate_cannot_reuse_version(self):
        result=pkg.build(self.root,self.pex,self.root/'out')
        original={key:Path(path).read_bytes() for key,path in result.items()}
        changed=self.root/'server'/pkg.SERVER_FILES[-1]
        changed.write_text('changed payload')
        with self.assertRaises(ValueError): pkg.build(self.root,self.pex,self.root/'out')
        self.assertEqual(original,{key:Path(path).read_bytes() for key,path in result.items()})
    def test_missing_source_and_wrong_bridge_fail(self):
        (self.root/'server'/pkg.SERVER_FILES[0]).unlink()
        with self.assertRaises(ValueError): pkg.build(self.root,self.pex,self.root/'out')
    def test_unallowlisted_secrets_stay_out(self):
        (self.root/'server/secret.txt').write_text('secret')
        a=pkg.build(self.root,self.pex,self.root/'out')
        with ZipFile(a['server']) as z: self.assertNotIn('server/secret.txt',z.namelist())
        self.pex.write_bytes(bytes.fromhex('FA57C0DE'))
        with self.assertRaises(ValueError): pkg.build(self.root,self.pex,self.root/'out')

if __name__=='__main__': unittest.main()
