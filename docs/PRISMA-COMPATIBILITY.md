# Department / PRISMA compatibility

The Department Skyrim interface is optional. The Sealed Fates web plugin page remains available; the separate delivery bridge is still required for physical notes, items and gold. The courier's workload is terrible. Making the settings page mandatory would be adding insult to occupational injury.

Compatibility belongs to an exact pair of sources. A successful Sealed Fates test run, unchanged bridge bytes, or a version added to Department's allowlist does not establish that its adapter understands the new server behavior. Unknown or changed sources must continue to be refused.

The preflight currently targets dev.20 explicitly. A future version bump must advance that reviewed release identity and obtain its own pairing evidence or an explicit web-only decision. Editing an old receipt's version does not establish compatibility.

## Verified dev.20 server pairing — 2026-10-21

[Department v0.1.0-prealpha.4](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/tag/v0.1.0-prealpha.4) is the released match for this exact server pairing; prealpha.3 and earlier refuse dev.20. All 11 public assets and all 128 public source files were downloaded anonymously and matched the reviewed candidate and committed source.

- Sealed Fates: `0.1.0-dev.20`, source commit `39d797673dbede1147cc990dd7b23f25668bb4c6`; its tag, runtime, bridge and four published assets are unchanged.
- Department adapter SHA-256: `d73282f06687de09f283057f49807f2403d1231e545b560a209ca420490a5ddb`.
- Department protocol SHA-256: `5617966a3da686c0a6a687fa560ba5b421de0ecbe9eb54abbb573a83cd9808a8`.
- Department source commit: `08680e31635a4ed91bd9efcb46d7d9b8f30d95e1`; package input-freeze SHA-256 `f537cff24382715ce702ed9820190589f71f2cc9cffe59cdfd16411ad3fb85da` and outer package SHA-256 `58ab2204870f9e93aa6c33166ded48ab6b82526b0c74cd99b8c4505c0bd0f5c6`. All 128 committed source files match the source archive; all 47 bound server files match the accepted run. Native/UI/private-route payloads are unchanged from prealpha.3.
- Disposable run: `20261021T215250Z-ed346ab8`, **33/33 actual facade cases plus 11 prechecks**, under 3,155 frozen inputs. Source postchecks, owned PostgreSQL/TEST shutdown and protected-state checks passed; no provider was called.
- Independent review matched raw SQL, newer-init/stale-completion behavior, original close/dispatch/receipt links, uncertain transfers and no-resend safety. A complete 775-character forwarded body survives dispatch and receipt-confirmed detail unchanged; a separate 1,845-character body stays whole, queued and private when the stock layout refuses it.

The actual paired release CLI accepts the four-file evidence bundle and reproduces all four published dev.20 assets exactly. Twenty release-gate tests and 33 package tests pass, including causal source-edit regressions. Receipt validation and staged packages use the same captured source bytes; the tar uses that snapshot too. This is direct server-facade proof with synthetic receipts accepted through the real owner path. It does not establish HTTP transport, installed Skyrim behavior, actual inventory delivery or autonomous NPC forwarding.

Keep these evidence files together when replaying the preflight:

The separate [Department-SealedFates-dev20-pairing.zip](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/download/v0.1.0-prealpha.4/Department-SealedFates-dev20-pairing.zip) verification download has SHA-256 `5113bd50d9a40fae3ac97ce81a9823003441263544eaddb2573f1d9cd6013651`. Extract it into its **own folder**: its internal `SHA256SUMS.txt` checks the four JSON files below. Department's original release checksum file covers the original nine payloads and remains unchanged. The extra proof archive installs no mod files.

| File | SHA-256 |
| --- | --- |
| `department-owner-pairing.json` | `863b73addbb40546d4cad7623ab8b7ef0e29c91a42f55553afebc8cd6d91312f` |
| `source-readiness.json` | `07f56283f56e1a7994d4172695eade21f631d66e8700c2b253c9bdb9d7af69e3` |
| `runtime-release.json` | `f11c4c3e90abe3b7e5490fb14f04d15d1bc110b9060742cafd9fc5f90f95562d` |
| `runtime-input-freeze.json` | `9d7c4d8dbe960622279eca105d8280a71bca7a2f85c081fb530e846a5ef011e0` |

## Before each release

1. Freeze the Sealed Fates candidate and give the Department author its version, source revision and hashes. Review changed store interfaces, document fields, restore authority, transaction ownership and receipt projections together. Do not just add a manifest pin.
2. Run Department's source-pairing check against that exact candidate. Keep the JSON result. An accepted older version is a positive control; an unknown version and a changed source file must be refused. Readiness is only a source check.
3. For a compatibility claim, run the adapter's focused PostgreSQL facade cases on the reserved disposable server. Validate fixture initialization against that release's public restore lifecycle first; copied private helpers or forced state resets can invalidate the test setup. Bind results to the frozen owner and Department sources. Cover catalog, mailbox, detail, compose, reconciliation, restore overlap, caller-owned transactions/leases, closed letters and uncertain/partial receipts. Keep original transfer anchors and no-resend behavior intact.
4. Pass the resulting reviewed pairing receipt to the release entry:

       python scripts/release-assets.py --department-interface tested-paired --receipt <verified-pairing.json>

   If that pairing is unavailable, deliberately select `--department-interface web-only` and say so in the README and release notes. Keep urgent server fixes possible without claiming an untested optional interface works.
5. Retain the preflight output with the build evidence and identify the exact supported Department build in the release notes. Changing either bound source invalidates the pairing; rerun the relevant checks instead of editing the old receipt. Do not replace published assets in place.

The release entry checks the decision before packaging and binds it to a captured payload. It refuses a changed staged package and builds the catalog from the same snapshot before publishing files to `dist/`. `build-package.py` still builds local install packages without another repository, credentials or TEST. Neither command publishes or installs anything externally. A receipt is evidence from a reviewed run, not independent proof of its truth; its source and test provenance still need review.

Physical notes must also fit the owner's page layout. The 2,000-codepoint input bound does not guarantee that every body fits: the measured stock renderer rejects the original 1,845-character forwarded test letter. Such text must remain intact in owner storage and queued/unconfirmed, with no delivery operation, note or transfer command. Department public detail access still requires confirmed delivery. Pairing acceptance must separately verify this overflow path and the complete public-detail roundtrip of a substantial, naturally written forwarded letter that fits the unchanged renderer. It must report both sizes and never claim maximum-length physical delivery from the shorter case.

PostgreSQL and source checks do not prove that a DLL loads in Skyrim, that the installed native interface works, or that a physical transfer occurred in game. Report those limits separately. Keep live data, installed game files, provider settings and existing releases untouched during pairing work.
