"""Reproduce the exact tested dev.14 assets and the server-only catalog archive."""
from pathlib import Path
from zipfile import ZipFile
import gzip
import hashlib
import importlib.util
import io
import json
import tarfile

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('package', ROOT / 'scripts/build-package.py')
pkg = importlib.util.module_from_spec(spec)
spec.loader.exec_module(pkg)
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

def build():
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
    pkg.build(ROOT, None, out)
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

if __name__ == '__main__':
    print(json.dumps(build(), indent=2))
