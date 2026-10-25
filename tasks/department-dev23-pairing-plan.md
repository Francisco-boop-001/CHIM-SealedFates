# Department / Sealed Fates dev.23 pairing plan

Goal: publish an exact, server-tested optional Department interface for the immutable Sealed Fates dev.23 payload.

## Constraints and ownership

- Department owns interface code/build/release; this chat owns independent Sealed Fates acceptance and documentation.
- Use only `DwemerAI4Skyrim3-test2`, with a unique route-free namespace and owned synthetic PostgreSQL. Preserve the already-running distro; stop only the exact owned database after identity checks.
- Keep live data, installed game, providers, production configuration and existing releases untouched.
- Preserve exact-source rejection, restore/freshness fences, scheduler ownership, original-operation reconciliation, no-resend behavior and complete forwards.
- Sealed Fates release commit `01d62982d9b10ca4fce040af9d97fe7111231ba3`, tag and all four assets remain unchanged. Verification tools and documentation may change.

## Checklist

- [x] Agree on source ownership, immutable inputs, disposable fixture isolation and publisher handoff; preserve the prealpha.12 fifth tab/components.
- [x] Review the minimal adapter/UI change and all 19 selected owner pins, including new companion dependencies; reject unknown/drifting sources and retain older pins.
- [x] Add strict kept-running test2 receipt validation without replacing private-database cleanup; verify 20 preflight and three asset-gate tests.
- [x] Independently test the integrated UI: 51/51 functional checks plus the fifth-tab check; bind the final archive to those same bytes.
- [x] Execute the fresh full test2 run; both owners review raw SQL, dynamic table deltas, all frozen inputs and exact cleanup.
- [x] Validate the four-file pairing proof and reproduce all four immutable Sealed Fates assets through the actual tested-paired release entry.
- [x] Audit the exact Department source/install/proof archives, publish after acceptance, and verify anonymous downloads, tag identity and prior-release preservation.
- [x] Update compatibility/install/release notes and the portable record; synchronize canonical documentation while preserving historical differences.

## Review and evidence

Accepted run `20261025T011803Z-95a75e6d` passed 38/38 case groups (33 existing plus five companion groups) and eight prechecks. Both owners reviewed actual SQL and cleanup; all 3,231 frozen entries and all 38 SQL chunk hashes/table deltas match. Runtime source was frozen at `79b7e2c3e74b8a05285744c4f5f0d489743468d9`. Department prealpha.13 release source `ada38ef84f7cb4e8a17684c4a5dfda4cd528d5a6` adds only documentation. All four anonymous downloads match the accepted candidate; all 186 source members match Git, and the DLL plus 20 unchanged mod members match prealpha.12.

Three earlier fixture failures remain excluded and preserved with original freezes: wrong results-root argument, unprivileged init-namespace visibility, and a relocated controller-helper caller. Their corrections changed no product code, safety assertion or timeout. Private checker corrections were tested against positive and negative controls; no raw results were rewritten and no extra server run was needed after the final full pass.

The additive verification gate is public at `29866b20925c4d1b3e0aa49b6014332dcc868e7f`; the original dev.23 tag predates it. The actual paired release command reproduces all four original owner assets byte-for-byte. [Portable compatibility record](../release-inputs/dev23-department-compatibility.json) and [compatibility notes](../docs/PRISMA-COMPATIBILITY.md) bind public downloads, proof hashes and exact limits. Private evidence remains under `artifacts/chim-sealed-fates-department-dev23-pairing-20261024` and the Department owner's evidence folder.

Exact owned PostgreSQL PID 22699/system 7700409541917907104 stopped after identity verification. Test2 remains running; protected live VHD size/timestamp match. Provider calls were zero. No live/game/core/provider installation, restart or production configuration change occurred.

Limits: direct PHP facade rather than HTTP transport; pinned historical CHIM fixture rather than full installed-core execution; synthetic observations/receipts rather than Skyrim native UI/Papyrus, unloaded actors, co-save or physical delivery. No new model-quality or untouched hold-out claim.
