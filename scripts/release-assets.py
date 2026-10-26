"""Reproduce the pinned dev.14 release and bounded later package candidates."""
from pathlib import Path
from zipfile import ZipFile
import argparse
import gzip
import hashlib
import importlib.util
import io
import json
import tarfile
import tempfile

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('package', ROOT / 'scripts/build-package.py')
pkg = importlib.util.module_from_spec(spec)
spec.loader.exec_module(pkg)
gate_spec = importlib.util.spec_from_file_location('release_preflight', ROOT / 'scripts/release-preflight.py')
gate = importlib.util.module_from_spec(gate_spec)
gate_spec.loader.exec_module(gate)
EXPECTED = {
    'correspondence-0.1.0-dev.14.dwpkg': '20d3da4117479957471491d502126154e054b4543fdf32b03d4fb3f35ab6bd8c',
    'CHIM-Sealed-Fates-0.1.0-dev.14.zip': 'e6ef362295da9ffa1a22959f2ab7714ac18fc172f6404a4d930f458626937b1f',
    'CHIM-Sealed-Fates-Recovery-Playtest-20261019-v1.zip': '95e7d0dc429e7cb33ed8fb669d54973daffad8f39cebad08ca566e689c6790e3',
}

def sha(data):
    return hashlib.sha256(data).hexdigest()

def save(path, data):
    if path.exists() and path.read_bytes() != data:
        raise ValueError('Refusing to overwrite different release bytes: ' + path.name)
    path.write_bytes(data)

def preflight_current_output(out, expected, release_label):
    if out.is_symlink():
        raise ValueError(f'{release_label.capitalize()} output directory cannot be a symlink')
    if out.exists() and not out.is_dir():
        raise ValueError(f'{release_label.capitalize()} output path must be a directory')
    if not out.exists():
        return
    existing = list(out.iterdir())
    unexpected = {path.name for path in existing} - expected
    if unexpected:
        raise ValueError(f'Unexpected {release_label} output: ' + ', '.join(sorted(unexpected)))
    for path in existing:
        if path.is_symlink() or not path.is_file():
            raise ValueError(f'Unsafe {release_label} output: ' + path.name)

def build_current_release(release_version, expected_source_closure=None):
    out = ROOT / 'dist'
    manifest, payload = pkg.entries(ROOT)
    if manifest['version'] != release_version:
        raise ValueError('Release identity differs')
    source_closure = sha(payload['checksums.sha256'])
    if expected_source_closure is not None and source_closure != expected_source_closure:
        raise ValueError('Owner source changed after compatibility preflight')
    expected_server = pkg.packed(payload)
    release_label = 'dev' + release_version.rsplit('.', 1)[-1]
    names = {
        'correspondence-' + manifest['version'] + '.dwpkg',
        'CHIM-Sealed-Fates-' + manifest['version'] + '.zip',
        'correspondence.tar.gz',
        'SHA256SUMS.txt',
    }
    preflight_current_output(out, names, release_label)
    with tempfile.TemporaryDirectory(prefix=f'.{release_label}-release-', dir=ROOT) as temporary:
        staging = Path(temporary)
        result = pkg.build(ROOT, None, staging)
        server_path = Path(result['server'])
        if server_path.read_bytes() != expected_server:
            raise ValueError('Staged server package differs from the validated source snapshot')
        with ZipFile(result['bundle']) as archive:
            embedded = 'CHIM/server-plugins/correspondence/' + manifest['version'] + '.dwpkg'
            if archive.testzip() is not None or archive.read(embedded) != expected_server:
                raise ValueError('Embedded server package differs from the validated source snapshot')
        stream = io.BytesIO()
        with tarfile.open(fileobj=stream, mode='w', format=tarfile.USTAR_FORMAT) as archive:
            for name in sorted(pkg.SERVER_FILES):
                data = payload['server/' + name]
                entry = tarfile.TarInfo('correspondence/' + name)
                entry.size = len(data)
                entry.mode = 0o644
                entry.mtime = 0
                archive.addfile(entry, io.BytesIO(data))
        compressed = io.BytesIO()
        with gzip.GzipFile(fileobj=compressed, mode='wb', filename='', mtime=0, compresslevel=9) as archive:
            archive.write(stream.getvalue())
        save(staging / 'correspondence.tar.gz', compressed.getvalue())
        with tarfile.open(staging / 'correspondence.tar.gz', 'r:gz') as archive:
            if archive.getnames() != ['correspondence/' + n for n in sorted(pkg.SERVER_FILES)]:
                raise ValueError('Catalog root layout differs')
            for member in archive.getmembers():
                if not member.isfile() or archive.extractfile(member).read() != payload['server/' + member.name.removeprefix('correspondence/')]:
                    raise ValueError('Catalog source bytes differ')
        package_names = sorted([Path(result['server']).name, Path(result['bundle']).name, 'correspondence.tar.gz'])
        checksums = ''.join(sha((staging / n).read_bytes()) + '  ' + n + '\n' for n in package_names).encode('ascii')
        save(staging / 'SHA256SUMS.txt', checksums)
        staged = {name: (staging / name).read_bytes() for name in sorted(names)}
        if {path.name for path in staging.iterdir()} != names:
            raise ValueError(f'{release_label.capitalize()} staging output differs')

    preflight_current_output(out, names, release_label)
    if not out.exists():
        out.mkdir()
    preflight_current_output(out, names, release_label)
    for name, data in staged.items():
        target = out / name
        if target.exists() and target.read_bytes() != data:
            raise ValueError('Refusing to overwrite different release bytes: ' + target.name)
    for name, data in staged.items():
        target = out / name
        if not target.exists():
            with target.open('xb') as stream:
                stream.write(data)
    return {name: {'sha256': sha(data), 'bytes': len(data)} for name, data in staged.items()}

def build():
    version = json.loads((ROOT / 'server/manifest.json').read_text(encoding='utf-8')).get('version')
    if version in {'0.1.0-dev.15', '0.1.0-dev.16', '0.1.0-dev.17', '0.1.0-dev.18', '0.1.0-dev.19', '0.1.0-dev.20', '0.1.0-dev.21', '0.1.0-dev.22', '0.1.0-dev.23', '0.1.0-dev.24', '0.1.0-dev.25', '0.1.0-dev.26'}:
        return build_current_release(version)
    if version != '0.1.0-dev.14':
        raise ValueError('Unsupported release identity')
    out = ROOT / 'dist'
    source_kit = ROOT / 'release-inputs/CHIM-Sealed-Fates-Recovery-Playtest-20261019-v1.zip'
    kit = source_kit.read_bytes()
    if sha(kit) != EXPECTED[source_kit.name]:
        raise ValueError('Pinned companion kit differs')
    manifest, payload = pkg.entries(ROOT)
    if manifest['version'] != '0.1.0-dev.14':
        raise ValueError('Release identity differs')
    with ZipFile(io.BytesIO(kit)) as archive:
        if archive.testzip() is not None:
            raise ValueError('Companion kit CRC failure')
        if archive.read('CHIM/server-plugins/correspondence/correspondence-0.1.0-dev.14.dwpkg') != pkg.packed(payload):
            raise ValueError('Server source differs from the tested kit')
        if archive.read('Scripts/CHIMCorrespondenceBridge.pex') != pkg.verify_bridge(ROOT):
            raise ValueError('Bridge differs from the tested kit')
    # Reproduce the frozen dev.14 layout; new bundles use CHIM's version-only filename.
    if not out.resolve().is_relative_to(ROOT.resolve()):
        raise ValueError('Output must stay inside this project')
    out.mkdir(parents=True, exist_ok=True)
    server_name = 'correspondence-' + version + '.dwpkg'
    server_bytes = pkg.packed(payload)
    save(out / server_name, server_bytes)
    save(out / ('CHIM-Sealed-Fates-' + version + '.zip'), pkg.packed({
        'Scripts/CHIMCorrespondenceBridge.pex': pkg.verify_bridge(ROOT),
        'CHIM/server-plugins/correspondence/' + server_name: server_bytes,
    }))
    save(out / source_kit.name, kit)
    for name, expected in EXPECTED.items():
        if sha((out / name).read_bytes()) != expected:
            raise ValueError('Tested asset differs: ' + name)
    stream = io.BytesIO()
    with tarfile.open(fileobj=stream, mode='w', format=tarfile.USTAR_FORMAT) as archive:
        for name in sorted(pkg.SERVER_FILES):
            data = (ROOT / 'server' / name).read_bytes()
            entry = tarfile.TarInfo('correspondence/' + name)
            entry.size = len(data)
            entry.mode = 0o644
            entry.mtime = 0
            archive.addfile(entry, io.BytesIO(data))
    compressed = io.BytesIO()
    with gzip.GzipFile(fileobj=compressed, mode='wb', filename='', mtime=0, compresslevel=9) as archive:
        archive.write(stream.getvalue())
    save(out / 'correspondence.tar.gz', compressed.getvalue())
    with tarfile.open(out / 'correspondence.tar.gz', 'r:gz') as archive:
        if archive.getnames() != ['correspondence/' + n for n in sorted(pkg.SERVER_FILES)]:
            raise ValueError('Catalog root layout differs')
        for member in archive.getmembers():
            if not member.isfile() or archive.extractfile(member).read() != (ROOT / 'server' / member.name.removeprefix('correspondence/')).read_bytes():
                raise ValueError('Catalog source bytes differ')
    names = sorted([*EXPECTED, 'correspondence.tar.gz'])
    checksums = ''.join(sha((out / n).read_bytes()) + '  ' + n + '\n' for n in names).encode('ascii')
    save(out / 'SHA256SUMS.txt', checksums)
    return {n: {'sha256': sha((out / n).read_bytes()), 'bytes': (out / n).stat().st_size} for n in names + ['SHA256SUMS.txt']}

def main(argv=None):
    parser = argparse.ArgumentParser(description='Build release assets after an explicit Department compatibility decision.')
    parser.add_argument('--department-interface', choices=('web-only', 'tested-paired'), required=True)
    parser.add_argument('--receipt', type=Path)
    args = parser.parse_args(argv)
    try:
        compatibility = gate.preflight(ROOT, args.department_interface, args.receipt)
    except (ValueError, OSError) as error:
        parser.error(str(error))
    assets = build_current_release(
        compatibility['owner']['version'],
        compatibility['owner']['sourceClosureSha256'],
    )
    print(json.dumps({'compatibility': compatibility, 'assets': assets}, indent=2))


if __name__ == '__main__':
    main()
