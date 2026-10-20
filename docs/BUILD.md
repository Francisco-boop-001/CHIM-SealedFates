# Build and package

Run these commands from the repository root with Python 3:

    python scripts/build-package.py --pex bridge/20261020T011358116Z/Data/Scripts/CHIMCorrespondenceBridge.pex
    python scripts/release-assets.py
    python -B tests/package_test.py
    python -B tests/bridge-parser.py
    python -B tests/bridge-state.py

These package, parser, and host-state commands run with the repository's Python files and do not require WSL. The parser and host-state checks use fake native/JContainers boundaries.

The first command builds the schema-4 server package and paired MO2 ZIP in **dist/**. The second reproduces the pinned server package, paired ZIP, optional recovery playtest kit, server-only **correspondence.tar.gz**, and **SHA256SUMS.txt**. It verifies that server source and the compiled bridge match the pinned kit and refuses to replace different immutable bytes.

The package build reuses the already compiled, metadata-sanitized PEX. It does not compile Papyrus. A fresh bridge compile requires Caprica and the matching external import/dependency files; those SDK and compiler packages are not included here. See the compatibility references in [game/bridge-manifest.json](../game/bridge-manifest.json). Integration, bridge-wire, HTTP, and native PostgreSQL fixture scripts use external retained synthetic inputs and guarded local-cluster/artifact paths; they are fixture-source evidence, not standalone portable test commands. Documentation, static checks, and package checks are not Skyrim gameplay results.

The server-only tar archive contains the plugin under **correspondence/**, including **correspondence/manifest.json**; it is a catalog input and does not self-register with CHIM.
