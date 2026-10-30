# Sealed Fates dev.30 / Department prealpha.28

[Department prealpha.28](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/tag/v0.1.0-prealpha.28) adds recovery for a saved manual letter that stayed queued. **Sealed Fates dev.30 itself is unchanged.** The Skyrim interface is optional; the existing web plugin page still works independently.

1. Exit Skyrim completely. Update the existing Department mod using [its single MO2 ZIP](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/download/v0.1.0-prealpha.28/WorldOfDramaLlama-0.1.0-prealpha.28.zip), then restart normally. This includes native 0.1.18.0; do not replace the DLL while Skyrim is running. Already on Sealed Fates dev.30? No additional Sealed Fates download or new migration for this interface update.
2. Open **Sealed Fates → Refresh waiting letters**. Use **Continue checking waiting letters** if shown; a long history may need several explicit pages.
3. Select the saved letter. **Reconcile same operation** checks status; **Resume delivery** makes an explicit guarded delivery attempt when the owner confirms eligibility. If it is waiting for a nearby executor, have a normal CHIM interaction with a managed NPC and try the same saved letter again. That NPC need not be the recipient; the existing 45-second presence freshness check remains.

Resume uses the saved recipient, body and attachments, including any original gold; the current draft is not used. Send remains locked for another transfer while an unresolved transfer is known. Dispatched, unknown, partial and closed-unconfirmed operations are retained for status checking and cannot be blindly resent. Loading, refreshing and reconciling never dispatch a letter. Discovery is a point-in-time check for this view, not a global lock against another client deliberately composing a different letter later.

Exact pair: Sealed Fates `correspondence-v0.1.0-dev.30`, commit `1eb58d999698002f8aeaa05b21464f7608717794`; Department `v0.1.0-prealpha.28`, commit `b66568de0b9411bacd114a15e99e118776e81764`. Department retains Private Conversations 0.1.25 and bundled companion prealpha.13, Mind Poisoning 0.1.30, Sworn & Scorned 0.1.12 and Malpractice Makes Perfect dev.4. All 20 dev.30 source pins and older supported version maps remain guarded.

Verification: **6 actual PostgreSQL 15 groups, 83 assertions and 45 child-process records** on disposable test2; **16 focused browser checks**, **6 native host tests**, **16 package checks**, and **4 final PHP bootstrap responses accepted by the emitted DLL's pure validator**, plus an extra-capability refusal. This includes unprofiled synthetic 500-gold resume, duplicate/concurrent attempts, stale/null restore authority refusal, restart discovery, long history, and backdated/concurrent-event cursor invalidation. The final independent bughunt found no additional unresolved defect in that scope. The test2 fixture used only private loopback inside a new network namespace; its owned database stopped, scratch was removed and test2 returned to its initial stopped state. No live data, actual player letter, installed game or providers were used.

Physical Skyrim delivery, actual gold/item movement, native PRISMA rendering/input, and save/co-save behavior still need gameplay testing. The separate mouse-control fault is not fixed here. The optional host interaction gate was source/contract checked because that helper is absent from the captured core; it was not exercised on an actual host. Historical broader stress/overlap tests were not rerun. This focused proof does **not** satisfy the broader `tested-paired` release gate or change the original dev.30 web-only build decision. [Portable source and proof record](../release-inputs/dev30-department-resume-compatibility.json).

## Historical dev.30 / Department prealpha.26 service pairing

[Department prealpha.26](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/tag/v0.1.0-prealpha.26) is the optional interface update for the exact Sealed Fates dev.30 source. **If dev.30 is already installed, update only Department's single MO2 ZIP after ending your game session.** The existing Sealed Fates web plugin page still works. The required delivery bridge and optional interface remain separate components.

The exact pair passed **16 focused test2 service cases and 71 assertions**. That verifies the production adapter and owner services against PostgreSQL; it does not certify native Skyrim UI, physical letters/items/gold or save/co-save behavior. No live, installed-game or provider access occurred.

- Sealed Fates tag `correspondence-v0.1.0-dev.30`, commit `1eb58d999698002f8aeaa05b21464f7608717794`; its four published assets are unchanged.
- Department tag `v0.1.0-prealpha.26`, commit `562f77f94f2aeb29d038d3824d087c92fe405abf`.
- All 20 owner pins match exact dev.30. The 15 older version maps remain unchanged; unknown or altered sources still refuse. Freshness, restore authority, scheduler ownership and uncertain-receipt/no-resend checks stay in place.
- Tests cover temporary BGL/player/form failures and recovery, changed identities/episodes/restore epochs, confirmed opt-out/death, manual scheduler bypass, repeated requests, unknown/partial receipts and full UTF-8 forwarding. Terminal stale letters do not revive when names or fresh observations return.
- Independent package checks match 258 source files to immutable Git. Only the Department server DWPkg changes; the other 21 payloads, including native DLL and UI, match prealpha.25 byte-for-byte. Eight generated PCV overlay files also match the previous package. Native remains 0.1.17.0, DLL SHA256 `5b55122b8916629c1130260a24781b709e0192a34049211a67f91e337177df6a`.
- The unchanged Private Conversations pairing remains **0.1.24** with bundled companion prealpha.12. Private Conversations 0.1.25 requires its separate owner update; this release does not claim that pairing.

The test2 run used synthetic data in a new PostgreSQL 15 database, with a private loopback listener inside an isolated network namespace. All owned processes stopped, scratch was removed, leases were released and test2 returned to its initial stopped state. No copied campaign was used. No HTTP transport, installed-server, model or Skyrim gameplay acceptance was performed. Retained overlap/stress/overshoot evidence is historical and is not counted as a fresh rerun. The focused follow-up does **not** satisfy the broader `tested-paired` release gate or relabel the original Sealed Fates web-only build decision.

The [portable service compatibility record](../release-inputs/dev30-department-test2-compatibility.json) binds exact releases, case IDs, receipt hashes and limits. Migration006's existing owner/admin and maintenance prerequisites still apply to older upgrades; no new migration or installation is performed by publication.

## Historical initial dev.30 publication — web-only

The existing CHIM web plugin page remains available. Department prealpha.25 binds the exact dev.29 files and is **not a matching dev.30 interface release**. These companion/runtime fixes change that closure; do not bypass its version/hash checks. No Department/native/UI payload is included or changed here.

Dev.30 has actual synthetic recovery/database tests on isolated Windows PostgreSQL and test2, but no new Department pairing receipt, installed HTTP send or Skyrim UI/VM acceptance. The release gate deliberately uses `--department-interface web-only`; this is not `tested-paired` certification.

The dev.29/prealpha.25 record below remains valid only for those immutable versions. [Current release and gameplay limits](RELEASE.md).

# Department / PRISMA compatibility

## Sealed Fates dev.29 / Department prealpha.25 — offline source/package compatibility

[Department prealpha.25](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/tag/v0.1.0-prealpha.25) is the optional interface update for Sealed Fates dev.29. Install each project's own single MO2 ZIP after ending the game session; the existing Sealed Fates web page remains independent. Do not use the older prealpha.24/dev.28 pins with changed dev.29 source.

- Sealed Fates immutable runtime tag: `correspondence-v0.1.0-dev.29`, commit `dd128bb7568e0095423c49e02c0e70a395208a18`.
- Department immutable source tag: `v0.1.0-prealpha.25`, commit `224d2bb473b86e6087a9711d6a42d716e46d9ba0`.
- The owner closure now has 20 exact files, including migration006. Both production Department Stores and its source fixture implement `transactionMemo(string $key, callable $build): mixed`. Writer wrapper memo access does not enter commit; read adapter retention exists only inside its own callback lifetime, with finally cleanup. Direct calls rebuild.
- Department's focused actual-service/source/memo tests and retained dev.28/dev.27 contracts pass offline. Its 14 package guards reject incorrect owner hashes even when adapter/proof agree on the same wrong hash. No unknown-source guard was bypassed.
- Root independently downloaded all four public Department assets, matched every one of the 250 shipped source files to the immutable Git commit, checked all 20 owner pins against public Sealed Fates, and compared the installable against prealpha.24. Only the Department server DWPkg changes; the other 21 payloads, including native DLL and all UI files, are byte-identical. The source archive excludes the non-product `tasks/normal-chim-20261027.md` note.
- Native remains 0.1.17.0, DLL SHA256 `5b55122b8916629c1130260a24781b709e0192a34049211a67f91e337177df6a`. No native/UI rebuild was needed.

**This is offline source/package acceptance, not a new test2 runtime pairing.** No actual HTTP, installed server, provider, Skyrim UI/VM, physical transfer or save/co-save acceptance was run for this changed pairing. The Sealed Fates release gate records `web-only`; this proof does not replace the stricter `tested-paired` runtime receipt. Earlier test2 results remain evidence only for their exact old source.

Migration006 needs eventlog-owner/admin rights and a maintenance window. The 3 s timeout bounds lock acquisition only; an uncapped first index build holds the eventlog lock until it finishes. Prepare that prerequisite before resuming active work. Publishing does not run a migration or install either package.

The [portable offline compatibility record](../release-inputs/dev29-department-offline-compatibility.json) binds both public releases and their proof limits. [Publication results](DEV29-PUBLICATION.md).


## Historical dev.28 / Department prealpha.24

**Historical optional interface:** [Department prealpha.24](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/tag/v0.1.0-prealpha.24) pins exact Sealed Fates dev.28 and includes native **0.1.17.0**, which replaces both crashing gold-count callers. Its offline regressions, native build, emitted DLL call paths, package/source contents and public downloads were checked. This is not an actual Skyrim1.5.97, installed-server send, restore/save-load or physical-transfer acceptance. The web page remains available independently. No compatibility guard was bypassed.


**Historical dev.27: web interface only; no new Department pairing.** This release repairs web-origin comparison for a different Apache canonical port. Historical pairing evidence does not cover dev.27; exact-source refusals remain intact.


**Historical dev.26: web interface only; no new Department pairing.** Companion polling and web ownership/closure change in this release. Historical exact-source pairing evidence does not cover dev.26; pins and refusals remain unchanged.


**Historical dev.25: web interface only; no new Department pairing.** This release changes the web refusal message and navigation. Historical exact-source pairing evidence below does not cover dev.25; no pins or safety checks were bypassed.


**Historical dev.24: web interface only; Department pairing is unverified.** The optional prealpha.13 adapter pins unchanged dev.23. Its historical proof below does not cover dev.24, and its exact-source refusal remains in force. No Department work or contact was requested for this release.

## Historical dev.23 and earlier pairings

**Dev.23 PRE-ALPHA:** [Department prealpha.13](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/tag/v0.1.0-prealpha.13) is the exact-source, server-tested optional interface pairing. The web page still works. Earlier dev.22/dev.20 records below retain their original scope; exact-source rejection remains enforced.

The Department Skyrim interface is optional. The Sealed Fates web plugin page remains available; the separate delivery bridge is still required for physical notes, items and gold. The courier's workload is terrible. Making the settings page mandatory would be adding insult to occupational injury.

Compatibility belongs to an exact pair of sources. A successful Sealed Fates test run, unchanged bridge bytes, or a version added to Department's allowlist does not establish that its adapter understands the new server behavior. Unknown or changed sources must continue to be refused.

The released dev.22 preflight targets dev.22 explicitly. Published Department prealpha.7 adds exact dev.22 source/package support and remains TEST-only; it does not add new database, HTTP or Skyrim gameplay acceptance. Packaging fix 1 preserves the entire dev.22 server source closure and bridge, so no owner pin changes are needed for that download. The release builder deliberately uses a web-only decision unless the separate runtime-pairing receipt required by its gate is available. Older dev.20/prealpha.4 server-facade evidence remains historical, not dev.22 runtime certification.

## Verified dev.23 server pairing — 2026-10-25

[Department v0.1.0-prealpha.13](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/tag/v0.1.0-prealpha.13) is the optional interface match for the unchanged Sealed Fates dev.23 payload at `01d62982d9b10ca4fce040af9d97fe7111231ba3`. Existing dev.23 users need only update the optional Department mod, using its single MO2 ZIP. The web page still works.

The independent run `20261025T011803Z-95a75e6d` on disposable test2 passed **38/38 case groups: 33 existing facade/recovery groups and five companion groups, plus eight prechecks**. All 3,231 frozen input entries, all 38 raw SQL chunks and their dynamic table-row deltas were independently checked after execution. The integrated UI passed 51/51 functional checks and a separate fifth-tab check. Department's runtime source was frozen at `79b7e2c3e74b8a05285744c4f5f0d489743468d9`; published source commit `ada38ef84f7cb4e8a17684c4a5dfda4cd528d5a6` adds only documentation to those tested bytes. The [portable compatibility record](../release-inputs/dev23-department-compatibility.json) binds the public downloads and evidence.

- Companion-authored, NPC-funded mail is recognized only through its exact document/request/playthrough/actor ownership record, with the player as recipient and no attachments. Inactive ownership records do not turn companion mail into a manual draft. Ordinary player-funded impersonation with items and gold remains supported.
- Manual companion dispatch/retry is refused. Original-operation reconciliation stays read-only; unknown, partial and closed-unconfirmed deliveries remain locked. Mailbox/detail visibility still requires confirmed delivery. Ownership changes invalidate old mailbox cursors.
- Restore overlap, freshness, leases, concurrent replay, late receipts, source drift and no-resend checks pass. The 19 facade-transitive owner pins include the new companion dependencies; the complete owner payload remains 30 files. Unknown or changed sources continue to fail closed.
- A complete 775-character forwarded letter matches source, persisted and receipt-confirmed facade bodies. The 1,845-character overflow case remains whole and queued, with no delivery operation, note or transfer command; unconfirmed public detail remains unavailable.

The private PostgreSQL instance stopped only after exact PID, system identifier, datadir and namespace checks. Test2 was already running and remained running; the launcher did not start or stop it. Protected sources matched; provider calls were zero. No live/game/provider installation or production configuration changed.

These are direct PHP facade and PostgreSQL results with synthetic game observations and signed test receipts. They do not establish HTTP transport, actual companion detection, model quality, installed native UI/Papyrus execution, co-save/save-load behavior or physical note/item/gold delivery. The fixture pins CHIM `0ec590b42c8087860138bb4e9aab470049ff9a2b`; a bounded comparison found eight selected current test2 PHP inputs LF-equivalent and two stock assets byte-identical. That comparison is not a full execution of test2's installed CHIM revision.

The actual `tested-paired` release command accepts the four-file proof and reproduces all four published Sealed Fates dev.23 assets byte-for-byte. Its tag and runtime/native payload remain unchanged. Replay the kept-running test2 receipt with the [verification gate at commit 29866b2](https://github.com/Francisco-boop-001/CHIM-SealedFates/tree/29866b20925c4d1b3e0aa49b6014332dcc868e7f), which adds strict lifecycle validation and three regression tests (20 preflight and three asset-gate tests pass). The original immutable dev.23 tag predates that additive verification-tool change; do not relabel or replace it.

The optional [pairing-proof download](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/download/v0.1.0-prealpha.13/WorldOfDramaLlama-0.1.0-prealpha.13-pairing.zip) (SHA-256 `2f4f5e8e076de27e3bc8ad01c0b662b0e62dadd2395c9c2d233dcf462812e977`) contains only these four JSON files. It installs nothing and is not another step for players. Keep the files together for developer replay:

| File | SHA-256 |
| --- | --- |
| `department-owner-pairing.json` | `d55ed02d53a00890dced142a39fa8557bc55d52546cb390f4effd63bdd72a8d2` |
| `source-readiness.json` | `c6b7f951510cded65387133580185cd6c1f8cf940d6cb14a0064eb33077e1ca7` |
| `runtime-release.json` | `8c3e8e1ffcc86207b24ff4a8de951084510c7925e83e86d2b289c06b54377c9b` |
| `runtime-input-freeze.json` | `06ccd015eda271136ec5d590a0f2e8045593ce74d820b7efacb948d5cbba2657` |

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
