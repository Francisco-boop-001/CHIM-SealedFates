# Dev.26 publication — 2026-10-26

Published **Sealed Fates dev.26 PRE-ALPHA** at immutable tag `correspondence-v0.1.0-dev.26`, source commit `7fd78802348d5ee7dabee9506ff6ede1c25c0657`. This includes PR #5's companion polling/ownership changes and safe-close correction `5994b1166c8c42633f00e3bacc869e87a5036bd9`, pushed to the PR branch without force. Old releases remain immutable.

Install the single **[CHIM-Sealed-Fates-0.1.0-dev.26.zip](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.26/CHIM-Sealed-Fates-0.1.0-dev.26.zip)** through the [installation/update guide](INSTALL.md), replacing the previous Sealed Fates MO2 mod. Keep one version enabled. The bundle contains both unchanged native scripts and `CHIM/server-plugins/correspondence/0.1.0-dev.26.dwpkg`. The other assets are administrator alternatives; no overlay archive or manual checksum calculation is required. Publication does not install the mod.

## Changes

Disabled/cooled-down companion polls use the existing restore-protected read phase before entering the eventlog-locking writer; due polls repeat the checks inside the writer. The web page treats companion Dispatch/Retry as scheduler-owned, while preserving safe per-letter **Close without resending or refunding** and reconciliation. NPC-to-NPC scheduler closure policy remains unchanged. There are no migrations or native-script changes, and access/origin/CSRF protections retain their dev.25 bytes.

## Verification

- Reviewed-source and clean-tag checks each pass 23 of 24 PHP suites, all 67 package/preflight tests and ten syntax checks. The sole PHP failure matches the existing Windows offscreen-host path baseline; its Linux rerun remains pending.
- The safe-close regression failed before repair and passes after it. Local checks cover queued/failed/unknown/in-flight UI states, malformed ownership, no new transfer/native command, unchanged autonomy closure policy, idempotent close, late genuine proof and restore/no-resend safeguards.
- All four source-build assets reproduce byte-for-byte from the clean tag. All four authenticated draft downloads and anonymous public downloads match hashes, ZIP CRCs, embedded DWPkg identity and the frozen server payload. The maintenance tar matches that same source.
- Both PEX files match dev.25. Strict PostgreSQL fixture identity checks are unchanged. Later documentation-only main commits do not change the immutable runtime tag.

## Unperformed gates

Test2 remains reserved and was not accessed. PostgreSQL poll-lock, concurrent-event and final safe-close/repeated-scheduler recovery cases were not independently rerun; the original PR author's reported separate PostgreSQL results do not certify this final repaired release. The earlier cooldown/restore/replay reruns also remain pending. No new real-model, browser-rendering, installed-server or Skyrim gameplay claim is made.

Department/PRISMA compatibility remains unclaimed; use the existing web page. Historical prealpha.13 pairing proof remains limited to exact dev.23. No live/test2, installed game/MO2, provider, production configuration or Department contact occurred.

The portable source receipt is [dev26-verification.json](../release-inputs/dev26-verification.json). Raw local, source/tag-build and draft/public receipts remain in `artifacts/chim-sealed-fates-dev26-publication-20261026` in the author workspace.
