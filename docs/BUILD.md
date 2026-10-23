# Build and package

Run these commands from a clean checkout with Python 3 and a new **dist/** directory. The builder refuses to overwrite different release bytes; retained outputs from another release are not current build inputs.

    python scripts/build-package.py
    python scripts/release-assets.py --department-interface web-only
    python -B tests/release_preflight_test.py
    python -B tests/release_assets_preflight_test.py
    python -B tests/package_test.py
    python -B tests/bridge-parser.py
    python -B tests/bridge-state.py

These package, release-gate, parser, and host-state commands run with the repository's Python files and do not require WSL. Release-gate tests use temporary project/evidence fixtures; they do not establish a real Department pairing. The parser and host-state checks use fake native/JContainers boundaries.

The release command requires an explicit Department/PRISMA compatibility decision before it creates assets. `web-only` records the exact owner version and source identity without claiming the optional Skyrim interface works. A tested pairing instead uses `--department-interface tested-paired --receipt <verified-pairing.json>`; the gate rejects missing, changed or mismatched proof. Ordinary `build-package.py` builds remain independent of Department and its test server. See [the pairing procedure](PRISMA-COMPATIBILITY.md) before preparing a release.

When --pex is omitted, the CLI and release builder both use the manifest to select the exact source-bound PEX in either the portable checkout or canonical evidence layout. The first command builds the schema-4 server package and paired MO2 ZIP in **dist/**. For dev.15 through dev.22, the second builds those two assets, server-only **correspondence.tar.gz**, and **SHA256SUMS.txt**. The four-file output allowlist is version-specific; unexpected names, unsafe paths and conflicting bytes are rejected before replacement. The explicit payload includes the web stylesheet and artwork. It verifies the source-bound, sanitized compiled bridge. The dev.14 tag retains its historical exact-kit reproduction path; dev.15 through dev.22 do not repackage that old recovery kit as new evidence.

Dev.17 includes a newly compiled, metadata-sanitized PEX; the package command verifies and reuses it. The private compile run retains raw output and logs. Its portable derived receipt explicitly records private retention and non-distribution; the builder rejects that receipt if the raw output is present under the distribution evidence root. Historical receipts that record actual raw removal remain supported. It does not compile Papyrus. A fresh bridge compile requires Caprica and the matching external import/dependency files; those SDK and compiler packages are not included here. See the compatibility references in [game/bridge-manifest.json](../game/bridge-manifest.json). Integration, bridge-wire, HTTP, and native PostgreSQL fixture scripts use external retained synthetic inputs and guarded local-cluster/artifact paths; they are fixture-source evidence, not standalone portable test commands. Package tests use fresh temporary project fixtures and can be directed to an evidence directory with `CHIM_PACKAGE_TEST_TMP`; they do not write to the checkout's **dist/**. Documentation, static checks, and package checks are not Skyrim gameplay results.

For dev.20, `release-assets.py` builds the two packages, the server-only maintenance archive, and checksum list in a fresh local **dist/**. It does not publish or tag a release. The strict four-output allowlist refuses stale names and changed immutable bytes. Keep each checkout's **dist/** separate from historical release outputs. The compatibility preflight was added after the dev.20 tag: use the reviewed current checkout to run it against the unchanged dev.20 runtime. Historical tags retain their original CLI and bundled build instructions; neither their tags nor their assets are replaced.

The standalone asset is named **correspondence-0.1.0-dev.22.dwpkg** for downloading. Inside the MO2 bundle its member name is **CHIM/server-plugins/correspondence/0.1.0-dev.22.dwpkg**. The bytes are identical. CHIM advertises the entire filename stem as the package version; prefixing it with the plugin name makes the server reject the final upload chunk. The package regression checks that the embedded filename equals its declared version. Historical dev.14 reconstruction explicitly retains its original frozen layout and output-containment guard.

The server-only tar archive contains the plugin under **correspondence/**, including **correspondence/manifest.json**; it is a maintenance/manual-administration archive. No catalog import or self-registration path is claimed; use the DWPkg through CHIM's package manager.

## Web presentation checks

With PHP 8.2 or later available, the pure checks run without core or database startup:

    php tests/server_ui.php
    php tests/server_autonomy_ui.php
    php tests/server_recovery_runtime.php

**tests/render_ui.php** renders synthetic **populated**, **empty**, **opened**, **error**, and **long-unicode** fixtures; choose the state as the first CLI argument. The HTTP fixture uses the **fixture** query parameter and local server assets. These fixture views do not access production storage or send letters.
