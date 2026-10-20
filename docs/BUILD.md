# Build and package

Run these commands from a clean checkout with Python 3 and a new **dist/** directory. The builder refuses to overwrite different release bytes; retained outputs from another release are not current build inputs.

    python scripts/build-package.py --pex bridge/20261020T011358116Z/Data/Scripts/CHIMCorrespondenceBridge.pex
    python scripts/release-assets.py
    python -B tests/package_test.py
    python -B tests/bridge-parser.py
    python -B tests/bridge-state.py

These package, parser, and host-state commands run with the repository's Python files and do not require WSL. The parser and host-state checks use fake native/JContainers boundaries.

The first command builds the schema-4 server package and paired MO2 ZIP in **dist/**. For dev.15 or dev.16, the second builds those two assets, server-only **correspondence.tar.gz**, and **SHA256SUMS.txt**. The four-file output allowlist is version-specific; unexpected names, unsafe paths and conflicting bytes are rejected before replacement. The explicit payload includes the web stylesheet and artwork. It verifies the unchanged compiled bridge. The dev.14 tag retains its historical exact-kit reproduction path; dev.15 and dev.16 do not repackage that old recovery kit as new evidence.

The package build reuses the already compiled, metadata-sanitized PEX. It does not compile Papyrus. A fresh bridge compile requires Caprica and the matching external import/dependency files; those SDK and compiler packages are not included here. See the compatibility references in [game/bridge-manifest.json](../game/bridge-manifest.json). Integration, bridge-wire, HTTP, and native PostgreSQL fixture scripts use external retained synthetic inputs and guarded local-cluster/artifact paths; they are fixture-source evidence, not standalone portable test commands. Package tests use fresh temporary project fixtures and can be directed to an evidence directory with `CHIM_PACKAGE_TEST_TMP`; they do not write to the checkout's **dist/**. Documentation, static checks, and package checks are not Skyrim gameplay results.

For dev.16, `release-assets.py` builds the two packages, the server-only catalog archive, and checksum list in a fresh local **dist/**. It does not publish or tag a release. The strict four-output allowlist refuses stale names and changed immutable bytes. Build from the candidate source snapshot and keep its **dist/** separate from historical release outputs.

The server-only tar archive contains the plugin under **correspondence/**, including **correspondence/manifest.json**; it is a catalog input and does not self-register with CHIM.

## Web presentation checks

With PHP 8.2 or later available, the pure checks run without core or database startup:

    php tests/server_ui.php
    php tests/server_autonomy_ui.php
    php tests/server_recovery_runtime.php

**tests/render_ui.php** renders synthetic **populated**, **empty**, **opened**, **error**, and **long-unicode** fixtures; choose the state as the first CLI argument. The HTTP fixture uses the **fixture** query parameter and local server assets. These fixture views do not access production storage or send letters.
