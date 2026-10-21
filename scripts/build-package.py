"""Build local schema-4 server and paired MO2 candidates from explicit payloads."""
import argparse
import hashlib
import io
import json
import re
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_STORED

SERVER_FILES = (
    '.htaccess', 'manifest.json', 'index.php', 'preprocessing.php', 'context_pre.php',
    'lib/bootstrap.php', 'lib/boundaries.php', 'lib/runtime.php', 'lib/store.php',
    'lib/view.php', 'lib/consumers.php', 'migrations/001_initial.sql', 'migrations/004_restore_state.sql',
    'postrequest.php', 'lib/autonomy_policy.php', 'lib/autonomy_store.php',
    'lib/autonomy_host.php', 'migrations/002_autonomy.sql',
    'offscreen-worker.php', 'offscreen-service.php', 'lib/offscreen_host.php',
    'lib/offscreen.php', 'lib/offscreen_policy.php', 'migrations/003_offscreen.sql',
    'dashboard-art.webp', 'assets/style.css',
)
PEX_MAGIC = bytes.fromhex('FA57C0DE')
BRIDGE_SOURCE = 'game/Source/Scripts/CHIMCorrespondenceBridge.psc'
BRIDGE_SCRIPT = 'CHIMCorrespondenceBridge.psc'
ANONYMOUS = 'anonymous'

def pex_header(data):
    if len(data) < 16 or data[:4] != PEX_MAGIC or int.from_bytes(data[6:8], 'big') != 1:
        raise ValueError('A complete Skyrim PEX is required')
    offset = 16
    strings = []
    for _ in range(3):
        if offset + 2 > len(data):
            raise ValueError('Truncated PEX metadata length')
        size = int.from_bytes(data[offset:offset + 2], 'big')
        offset += 2
        if size > 4096 or offset + size > len(data):
            raise ValueError('Invalid PEX metadata length')
        try:
            strings.append(data[offset:offset + size].decode('utf-8'))
        except UnicodeDecodeError as error:
            raise ValueError('Invalid UTF-8 in PEX metadata') from error
        offset += size
    if offset + 2 > len(data):
        raise ValueError('Truncated PEX string-table header')
    return {'source': strings[0], 'user': strings[1], 'computer': strings[2], 'end': offset}

def pex_string(value):
    raw = value.encode('utf-8')
    if len(raw) > 4096:
        raise ValueError('PEX metadata string is too long')
    return len(raw).to_bytes(2, 'big') + raw

def anonymize_pex(data):
    header = pex_header(data)
    if Path(header['source'].replace('\\', '/')).name.casefold() != BRIDGE_SCRIPT.casefold():
        raise ValueError('PEX source metadata does not name the bridge script')
    result = data[:16] + pex_string(BRIDGE_SCRIPT) + pex_string(ANONYMOUS) + pex_string(ANONYMOUS) + data[header['end']:]
    clean = pex_header(result)
    if (clean['source'], clean['user'], clean['computer']) != (BRIDGE_SCRIPT, ANONYMOUS, ANONYMOUS):
        raise ValueError('PEX metadata anonymization failed')
    if data[:16] != result[:16] or data[header['end']:] != result[clean['end']:]:
        raise ValueError('PEX code/debug payload changed during metadata anonymization')
    return result

def hash_bytes(data):
    return hashlib.sha256(data).hexdigest()

def bridge_directory(evidence_root):
    evidence_root = Path(evidence_root)
    if not evidence_root.is_absolute() or evidence_root.is_symlink() or not evidence_root.is_dir():
        raise ValueError('Bridge evidence root must be an existing absolute directory')
    bridge_path = evidence_root / 'bridge'
    if bridge_path.is_symlink() or not bridge_path.is_dir():
        raise ValueError('Bridge evidence root must contain a real bridge directory')
    bridge = bridge_path.resolve()
    if not bridge.is_relative_to(evidence_root.resolve()):
        raise ValueError('Bridge evidence directory must remain under its manifest evidence root')
    return bridge

def e8_artifact(evidence_root, relative):
    bridge = bridge_directory(evidence_root)
    evidence_root = Path(evidence_root)
    if (not isinstance(relative, str) or not relative or Path(relative).is_absolute() or
            re.match(r'^[A-Za-z]:', relative) or '..' in Path(relative).parts):
        raise ValueError('Bridge evidence paths must be relative and contained')
    current = evidence_root
    for part in Path(relative).parts:
        current = current / part
        if current.is_symlink():
            raise ValueError('Bridge evidence paths cannot contain symlinks')
    path = current.resolve()
    if not path.is_relative_to(bridge):
        raise ValueError('Bridge evidence must remain under the manifest evidence root bridge directory')
    return path

def verify_raw_pex_provenance(evidence_root, raw_pex, anonymization):
    if not isinstance(raw_pex, dict) or not isinstance(anonymization, dict):
        raise ValueError('Compiler receipt raw PEX provenance is invalid')
    removed = anonymization.get('raw_pex_removed') is True
    retained_privately = (anonymization.get('raw_pex_not_distributed') is True and
                          anonymization.get('raw_pex_retained_for_private_review') is True)
    raw_path = raw_pex.get('path')
    if raw_path is not None:
        raw_path = e8_artifact(evidence_root, raw_path)
        if raw_path.exists():
            raise ValueError('raw PEX is present in distributed evidence')
    elif retained_privately:
        raise ValueError('Compiler receipt has no raw PEX path for private review')
    if not removed and not retained_privately:
        raise ValueError('Compiler receipt does not prove raw PEX removal or private retention')

def sanitizer_paths(evidence_root, raw_pex, clean_pex):
    bridge = bridge_directory(evidence_root)
    raw_path = Path(raw_pex)
    clean_path = Path(clean_pex)
    if not raw_path.is_absolute() or not clean_path.is_absolute():
        raise ValueError('PEX sanitation paths must be absolute')
    raw_path = raw_path.resolve()
    clean_path = clean_path.resolve()
    if not raw_path.is_relative_to(bridge) or not clean_path.is_relative_to(bridge) or raw_path == clean_path:
        raise ValueError('PEX sanitation input and output must be distinct files under the evidence bridge directory')
    if not raw_path.is_file():
        raise ValueError('PEX sanitation input must be an existing file')
    if clean_path.exists():
        raise ValueError('Sanitized output already exists; use a fresh bridge run directory')
    return raw_path, clean_path

def verify_bridge(root, pex=None):
    root = Path(root).resolve()
    manifest_path = root / 'game' / 'bridge-manifest.json'
    try:
        manifest = json.loads(manifest_path.read_text(encoding='utf-8'))
    except (OSError, json.JSONDecodeError) as error:
        raise ValueError('Missing or invalid bridge manifest') from error
    source_info = manifest.get('source', {})
    source_rel = source_info.get('path')
    if source_rel != BRIDGE_SOURCE:
        raise ValueError('Bridge manifest must bind the candidate bridge source')
    source = root / source_rel
    if source.is_symlink() or not source.is_file() or not source.resolve().is_relative_to(root):
        raise ValueError('Missing or unsafe bridge source')
    source_hash = hashlib.sha256(source.read_bytes()).hexdigest()
    if source_info.get('sha256') != source_hash:
        raise ValueError('Bridge source hash does not match its manifest')
    compiler = manifest.get('compiler', {})
    if not re.fullmatch(r'[0-9A-Fa-f]{64}', str(compiler.get('sha256', ''))):
        raise ValueError('Bridge manifest has no compiler hash')
    pex_info = manifest.get('pex', {})
    evidence_root = manifest.get('build_evidence_root')
    if not isinstance(evidence_root, str):
        raise ValueError('Bridge manifest must name "." or an absolute build evidence root')
    if evidence_root == '.':
        evidence_root = root
    elif not Path(evidence_root).is_absolute():
        raise ValueError('Bridge manifest must name "." or an absolute build evidence root')
    expected_pex = e8_artifact(evidence_root, pex_info.get('path'))
    expected_receipt = e8_artifact(evidence_root, manifest.get('build_receipt'))
    pex = expected_pex if pex is None else Path(pex).resolve()
    if pex != expected_pex or pex.is_symlink() or not pex.is_file():
        raise ValueError('PEX path does not match the bridge manifest')
    if expected_receipt.is_symlink() or not expected_receipt.is_file():
        raise ValueError('Missing or unsafe Caprica build receipt')
    data = pex.read_bytes()
    digest = hash_bytes(data)
    if pex_info.get('sha256') != digest or pex_info.get('bytes') != len(data):
        raise ValueError('PEX bytes do not match the bridge manifest')
    header = pex_header(data)
    if (header['source'], header['user'], header['computer']) != (BRIDGE_SCRIPT, ANONYMOUS, ANONYMOUS):
        raise ValueError('PEX header metadata is not anonymized')
    try:
        receipt = json.loads(expected_receipt.read_text(encoding='utf-8'))
    except (OSError, json.JSONDecodeError) as error:
        raise ValueError('Missing or invalid Caprica build receipt') from error
    receipt_hash = hash_bytes(expected_receipt.read_bytes())
    if manifest.get('build_receipt_sha256') != receipt_hash:
        raise ValueError('Caprica build receipt hash does not match the bridge manifest')
    if receipt.get('schema_version') != 1 or receipt.get('exit_code') != 0:
        raise ValueError('Caprica build receipt is not successful')
    receipt_source = receipt.get('source', {})
    receipt_compiler = receipt.get('compiler', {})
    receipt_pex = receipt.get('pex', {})
    raw_pex = receipt.get('raw_pex', {})
    if not isinstance(raw_pex, dict) or not isinstance(receipt_pex, dict):
        raise ValueError('Compiler receipt PEX provenance is invalid')
    if receipt_source.get('path') != source_rel or receipt_source.get('sha256') != source_hash:
        raise ValueError('Compiler receipt source does not match the candidate source')
    if receipt_compiler.get('version') != compiler.get('version') or str(receipt_compiler.get('sha256', '')).casefold() != str(compiler.get('sha256', '')).casefold():
        raise ValueError('Compiler receipt does not match the bridge manifest')
    if receipt_pex.get('path') != pex_info.get('path') or receipt_pex.get('sha256') != digest or receipt_pex.get('bytes') != len(data):
        raise ValueError('Compiler receipt does not match the packaged PEX')
    if receipt_pex.get('body_sha256') != hash_bytes(data[header['end']:]) or raw_pex.get('body_sha256') != receipt_pex.get('body_sha256'):
        raise ValueError('Compiler receipt does not preserve the compiled code/debug payload')
    args = receipt.get('arguments', [])
    if not isinstance(args, list) or not all(isinstance(arg, str) for arg in args):
        raise ValueError('Compiler receipt arguments are invalid')
    if args.count('--anonymize') != 1 or receipt.get('source_argument') != BRIDGE_SCRIPT or args[-1:] != [BRIDGE_SCRIPT]:
        raise ValueError('Compiler receipt does not prove an anonymized relative-source build')
    anonymization = receipt.get('anonymization', {})
    if (not isinstance(anonymization, dict) or anonymization.get('requested') is not True or
            anonymization.get('header_only') is not True or
            not isinstance(anonymization.get('caprica_header_clean'), bool) or
            not isinstance(anonymization.get('method'), str) or not anonymization.get('method')):
        raise ValueError('Compiler receipt does not prove bounded PEX header anonymization')
    verify_raw_pex_provenance(evidence_root, raw_pex, anonymization)
    inputs = receipt.get('inputs', [])
    if not isinstance(inputs, list) or any(not isinstance(item, dict) or not isinstance(item.get('role'), str) or
                                           not re.fullmatch(r'[0-9A-Fa-f]{64}', str(item.get('sha256', '')))
                                           for item in inputs):
        raise ValueError('Compiler receipt input provenance is invalid')
    compiler_inputs = [item for item in inputs if item['role'] == 'compiler']
    source_inputs = [item for item in inputs if item['role'] == 'source']
    if len(compiler_inputs) != 1 or compiler_inputs[0]['sha256'].casefold() != str(compiler.get('sha256', '')).casefold():
        raise ValueError('Compiler executable hash is missing from receipt inputs')
    if compiler_inputs[0].get('path') != receipt_compiler.get('path'):
        raise ValueError('Compiler executable path differs from receipt provenance')
    if len(source_inputs) != 1 or source_inputs[0]['sha256'].casefold() != source_hash:
        raise ValueError('Source hash is missing from receipt inputs')
    if not isinstance(receipt_pex.get('header'), dict) or (receipt_pex['header'].get('source'), receipt_pex['header'].get('user'), receipt_pex['header'].get('computer')) != (BRIDGE_SCRIPT, ANONYMOUS, ANONYMOUS):
        raise ValueError('Compiler receipt header does not match the sanitized PEX')
    return data

def packed(entries):
    stream=io.BytesIO()
    with ZipFile(stream,'w',compression=ZIP_STORED) as archive:
        for name,data in sorted(entries.items()):
            info=ZipInfo(name,date_time=(1980,1,1,0,0,0))
            info.create_system=3
            info.external_attr=0o100644<<16
            archive.writestr(info,data)
    return stream.getvalue()

def entries(root):
    root=Path(root).resolve()
    base=root/'server'
    payload={}
    for name in SERVER_FILES:
        path=base/name
        if not path.is_file() or path.is_symlink() or not path.resolve().is_relative_to(base):
            raise ValueError('Missing or unsafe allowlisted source: '+name)
        payload['server/'+name]=path.read_bytes()
    manifest=json.loads(payload['server/manifest.json'])
    if (not isinstance(manifest,dict) or manifest.get('name')!='correspondence'
            or not isinstance(manifest.get('version'),str)
            or not re.fullmatch(r'[0-9A-Za-z][0-9A-Za-z._+-]{0,63}',manifest['version'])):
        raise ValueError('Invalid correspondence manifest')
    payload['manifest.json']=(json.dumps({'schema_version':4,'name':'correspondence','version':manifest['version'],'server':{'mutable_paths':[]}},sort_keys=True,indent=2)+'\n').encode()
    payload['checksums.sha256']=''.join(hashlib.sha256(data).hexdigest()+'  '+name+'\n' for name,data in sorted(payload.items())).encode('ascii')
    return manifest,payload

def verify(path,root):
    manifest,expected=entries(root)
    with ZipFile(path) as archive:
        if archive.namelist()!=sorted(expected) or archive.testzip() is not None:
            raise ValueError('Archive membership or CRC differs')
        if any(archive.read(name)!=data for name,data in expected.items()):
            raise ValueError('Archive source/checksum mismatch')
    return manifest

def build(root,pex,output):
    manifest,payload=entries(root)
    bridge=verify_bridge(root,pex)
    output=Path(output).resolve()
    if not output.is_relative_to(Path(root).resolve()):
        raise ValueError('Output must stay inside this project')
    output.mkdir(parents=True,exist_ok=True)
    name='correspondence-'+manifest['version']+'.dwpkg'
    server=output/name
    # Never reuse an immutable candidate version for changed bytes.
    raw=packed(payload)
    if server.exists() and server.read_bytes()!=raw:
        raise ValueError('Candidate version already contains different bytes; bump version')
    server.write_bytes(raw)
    verify(server,root)
    combined={'Scripts/CHIMCorrespondenceBridge.pex':bridge,'CHIM/server-plugins/correspondence/'+name:raw}
    bundle=output/('CHIM-Sealed-Fates-'+manifest['version']+'.zip')
    bundle_raw=packed(combined)
    if bundle.exists() and bundle.read_bytes()!=bundle_raw:
        raise ValueError('Paired candidate version already contains different bytes; bump version')
    bundle.write_bytes(bundle_raw)
    with ZipFile(bundle) as archive:
        if archive.namelist()!=sorted(combined) or archive.testzip() or any(archive.read(k)!=v for k,v in combined.items()):
            raise ValueError('Paired candidate verification failed')
    return {'server':str(server),'bundle':str(bundle)}

if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--pex',type=Path)
    parser.add_argument('--evidence-root',type=Path)
    parser.add_argument('--sanitize-pex',type=Path)
    parser.add_argument('--sanitized-output',type=Path)
    parser.add_argument('--output',type=Path)
    args=parser.parse_args()
    root=Path(__file__).resolve().parents[1]
    if args.sanitize_pex:
        if not args.evidence_root or not args.sanitized_output:
            parser.error('--sanitize-pex requires --evidence-root and --sanitized-output')
        try:
            raw_path, clean_path = sanitizer_paths(args.evidence_root, args.sanitize_pex, args.sanitized_output)
        except ValueError as error:
            parser.error(str(error))
        raw=args.sanitize_pex.read_bytes()
        original_header=pex_header(raw)
        caprica_metadata_clean=(original_header['source']==BRIDGE_SCRIPT and original_header['user'].casefold()==ANONYMOUS and original_header['computer'].casefold()==ANONYMOUS)
        data=anonymize_pex(raw)
        clean_path.parent.mkdir(parents=True,exist_ok=True)
        clean_path.write_bytes(data)
        header=pex_header(data)
        print(json.dumps({'path':str(clean_path),'sha256':hash_bytes(data),'bytes':len(data),'header':header,'body_sha256':hash_bytes(data[header['end']:]),'input_header_clean':caprica_metadata_clean},indent=2))
    else:
        result=build(root,args.pex,args.output or root/'dist')
        print(json.dumps({name:{'path':path,'sha256':hashlib.sha256(Path(path).read_bytes()).hexdigest()} for name,path in result.items()},indent=2))
