# Dev.25 publication — 2026-10-26

Published **Sealed Fates dev.25 PRE-ALPHA** at immutable tag `correspondence-v0.1.0-dev.25`, source commit `a28cb8333582c59a92c25f67f72073899cd0e63c`. The release changes the web-access refusal message and local navigation. Authorization, origin, CSRF and native scripts retain their dev.24 bytes; correspondence behavior and migrations are unchanged.

Install the single **[CHIM-Sealed-Fates-0.1.0-dev.25.zip](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.25/CHIM-Sealed-Fates-0.1.0-dev.25.zip)** after ending the current game session, replacing the previous Sealed Fates MO2 mod. Keep one version enabled. The bundle includes both native scripts and `CHIM/server-plugins/correspondence/0.1.0-dev.25.dwpkg`; the other release files are administrator alternatives. Confirm the installed version after normal synchronization. Publication does not install the mod.

## Verification

- Fresh denied GET/POST probes return 403 before session/runtime initialization. Local access-help, origin and UI suites pass, including malformed/forwarded input, conditional port/path-preserving localhost navigation and no redirect or resend.
- The reviewed source and a clean tag export each pass 21 of 22 PHP suites, all 66 package/preflight tests and seven syntax checks. The one Windows offscreen-host path failure matches the prior baseline; its Linux rerun remains pending.
- All four reviewed assets reproduce byte-for-byte from the clean tag. All four authenticated draft downloads and anonymous public downloads match their expected hashes, ZIP CRCs, embedded version-only DWPkg identity and server source payload. The maintenance tar matches the same source.
- Both PEX files match dev.24. Older release tags and assets are preserved. Main documentation may advance beyond the immutable runtime tag without changing that tag's source identity.

## Boundaries

No live or test2 access, installed game/MO2 change, provider use or Department contact. Test2 remains reserved; the dev.24 PostgreSQL cooldown reproduction and companion freshness/restore/replay reruns remain pending. No browser-rendering or new Skyrim gameplay proof is claimed. Department/PRISMA compatibility remains unclaimed for dev.25; use the web interface. Historical dev.23 pairing evidence remains limited to its exact source.

The portable source verification is [dev25-verification.json](../release-inputs/dev25-verification.json). Raw local/source/tag/draft/public receipts are retained separately under `artifacts/chim-sealed-fates-dev25-publication-20261026` in the author workspace.
