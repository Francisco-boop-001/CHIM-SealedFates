# Dev.27 publication — 2026-10-26

Published **Sealed Fates dev.27 PRE-ALPHA** at immutable tag `correspondence-v0.1.0-dev.27`, source commit `d1eaf714a7661a1f785a4d8c2131c3565a6eddee`. This repairs same-origin form refusal when Apache reports a canonical port different from the browser-facing address. Existing releases are preserved.

Download the single **[CHIM-Sealed-Fates-0.1.0-dev.27.zip](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.27/CHIM-Sealed-Fates-0.1.0-dev.27.zip)**. After finishing your game session, follow [Updating](INSTALL.md), replace the previous Sealed Fates MO2 mod and keep one version enabled. It contains both unchanged native scripts and `CHIM/server-plugins/correspondence/0.1.0-dev.27.dwpkg`; the other release files are administrator alternatives. No second archive or manual checksum calculation is required. Confirm dev.27 after normal synchronization. Publication does not install or restart anything.

## Change and verification

The origin helper compares the validated Origin to the browser-facing Host authority and effective scheme port. A localhost:8081 POST with canonical SERVER_PORT=80 is accepted as same-origin; foreign hosts, wrong browser-facing ports, changed schemes, malformed authorities and cross-site metadata remain refused. Authorization and session CSRF functions, server-port sanity validation, index routing, all other server modules and native scripts are preserved. No migration or automatic resend is introduced.

The new local regression fails before repair and passes after it. Reviewed-source and clean-tag checks each pass 23 of 24 PHP suites, all 68 package/preflight tests and eleven syntax checks. The one Windows offscreen-host path failure matches the inherited baseline; its Linux rerun remains pending.

All four reviewed-source assets reproduce byte-for-byte from the clean tag. All four draft downloads and anonymous public downloads match their hashes, ZIP CRCs, embedded version-only DWPkg identity and the frozen server payload; the maintenance tar matches that same source. Both PEX files match dev.26. Later documentation-only main commits do not change the runtime tag.

## Unperformed gates and protected environments

Neither live nor test2 was accessed during this publication. The user is playing; no installed game/MO2 files, providers or production configuration were touched, and no Department contact occurred. The earlier authorized read-only observation is separate from this publication and does not prove a successful installed POST with the repair. The fixed files have not been installed or tested on that server by this publication.

The installed POST retest, PostgreSQL poll-lock/concurrent-event/safe-close/repeated-tick and earlier cooldown/restore/replay reruns remain pending. No new real-model, browser-rendering or Skyrim gameplay proof is claimed. Department/PRISMA pairing remains unclaimed for dev.27; use the existing CHIM web page. Historical prealpha.13 evidence remains limited to exact dev.23.

Portable source verification: [dev27-verification.json](../release-inputs/dev27-verification.json). Raw local/source/tag/draft/public receipts remain in `artifacts/chim-sealed-fates-dev27-publication-20261026` in the author workspace.
