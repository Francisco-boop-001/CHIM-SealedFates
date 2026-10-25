# Dev.24 personal-playtest publication — 2026-10-25

Published PRE-ALPHA from commit `80821db59d3e1c299bee56b4496cf20a144984fd`, immutable tag `correspondence-v0.1.0-dev.24`.

**Install one file:** [CHIM-Sealed-Fates-0.1.0-dev.24.zip](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.24/CHIM-Sealed-Fates-0.1.0-dev.24.zip). Replace the old Sealed Fates MO2 mod after ending the game session. Keep one version enabled. No second archive or checksum calculation is required for installation.

The ZIP carries both unchanged native scripts and `CHIM/server-plugins/correspondence/0.1.0-dev.24.dwpkg`, whose filename matches its manifest version. The embedded package is byte-identical to the administrator-only standalone DWPkg.

| Asset | SHA-256 | Bytes |
| --- | --- | ---: |
| CHIM-Sealed-Fates-0.1.0-dev.24.zip | `d066b1693000e18f014228c5aa2cbb1ae1ddd6d32e8e93ea7b43cf5451a975a6` | 2889309 |
| correspondence-0.1.0-dev.24.dwpkg | `cf4ac760346ce27b1226ec3b592d13f5b12b531d323976a5797ee32d9ea92f23` | 2856590 |
| correspondence.tar.gz | `ecce5f5b90429283386d4bf4fa7a9d63874d6e805f118b635be4be0069083898` | 2436634 |
| SHA256SUMS.txt | `97b15ac5a4635e85b4b61aec7759df4be2f10d3afe0a42e1000fa98d20744081` | 289 |

The reviewed source and clean tagged export pass the new 15-check local regression, 18 PHP suites and 65 package/preflight tests. The existing Windows host-path assertion also fails on unchanged dev.23 and remains pending on Linux. All four clean-tag asset bytes match the source build, draft downloads and anonymous public downloads. Native script bytes match dev.23. The old dev.23 tag and assets were preserved.

**Use the existing CHIM web plugin page. Department compatibility is not claimed for dev.24.** The prealpha.13 exact dev.23 pairing and its receipt do not certify this payload; the old receipt is explicitly refused. No Department files, pins or owner contact were involved.

**Test2 PostgreSQL reproduction and companion freshness/restore/replay regressions remain unrun**, as requested while the user plays. Publication was explicitly requested for personal testing despite that limit. No new model evaluation, actual Skyrim observation, physical delivery, inventory or real save/co-save rollback acceptance is claimed. Neither server, installed game/MO2 files, provider configuration nor campaign data was accessed or changed during publication.

[Release notes](RELEASE.md) · [Portable local verification record](../release-inputs/dev24-verification.json) · [Compatibility limits](PRISMA-COMPATIBILITY.md).
