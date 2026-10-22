# CHIM Sealed Fates 0.1.0-dev.22 — PRE-ALPHA

Skyrim has one courier. I gave him everybody's emotional baggage, then discovered the paperwork was eating his letters. A visionary management decision, if your vision is terrible. The paperwork has been corrected. His pension remains theoretical.

Temporarily unreadable NPC settings now postpone delivery instead of losing the letter, and a temporarily unavailable player identity no longer destroys an already queued reply. Scheduler-owned letters no longer offer a manual Retry that strands them. A renderer refusal leaves no phantom web-composed document; an unavailable executor still leaves a legitimate queued letter.

Claims interrupted by a restore retire safely, including NPCs beginning a new conversation. If the live caller can prove its callback never started, a failed attempt-counter write releases only that exact claim and records why. Historical zero counters do not grant permission to resend. Status checks no longer make a starting supervisor mistake a brief lock probe for another courier.

Integrity checks rotate through 16 letters per observation, while every consumed letter still validates its full body and anchors. Corrupt selected mail is quarantined without undoing that progress. Numeric game-time ordering keeps delivery chronological. This bounds proof work; it does not establish constant-time database queries or campaign-scale performance. Complete forwarded letters, original receipts and unknown/no-resend protections remain intact.

**The Skyrim interface is optional; the existing web plugin page still works. This is a web-only release.** Published Department prealpha.5 adds dev.21 source compatibility, while dev.20/prealpha.4 retains the earlier disposable facade proof. Neither published adapter accepts dev.22. Keep your existing pair for PRISMA, or use the web page for dev.22 until an exact pairing is released. The physical delivery bridge is unchanged from dev.17 and remains required for notes, items and gold.

Sender death or an explicit Background Life/Letters opt-out still suppresses current NPC prompt exposure. The complete stored letter and audit evidence remain; restored eligibility allows the same valid letter again. No rules have been relaxed to make the post office look productive.

Verification: all **34 PostgreSQL jobs**, **37 Linux pure checks**, **94 PHP lints**, **35 package checks**, **20 release-gate checks**, **60 translated parser checks** and **41 fake-native state checks** pass. Five original PostgreSQL regressions fail on dev.21 and pass after repair; two additional regressions reproduce and fix the bounded-sweep rollback problem found during fresh independent review. UI ownership, compose rollback and the status/start race have causal host checks. The final independent review found no remaining actionable issues. Earlier failed/superseded fixtures are retained, not counted as passing evidence.

Testing used a separate system-ID-guarded, socket-only PostgreSQL cluster in a network-isolated namespace inside the disposable TEST server. Only that marked cluster was stopped; existing TEST services/jobs remained alone. Protected live stayed stopped and its VHD metadata is unchanged. No provider, installed game, CHIM core or native recompilation was involved. Actual Skyrim inventory/co-save/gameplay, fresh model quality and campaign-scale performance remain unverified. If both persistence attempts fail, a claim can still require conservative recovery after storage repair. See [the report assessment, proof limits and recovery details](DEV22-RECOVERY.md), [installation](INSTALL.md) and [PRISMA compatibility](PRISMA-COMPATIBILITY.md).

## Dev.22 assets

[Download this PRE-ALPHA release](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/tag/correspondence-v0.1.0-dev.22). Keep one correspondence version enabled and use the matching paired ZIP for physical delivery.

| File | SHA-256 | Bytes |
| --- | --- | ---: |
| [CHIM-Sealed-Fates-0.1.0-dev.22.zip](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.22/CHIM-Sealed-Fates-0.1.0-dev.22.zip) | `d871f8e85509c18fc361ffb54242683c15da20e9057c6706a2e790c37f063acd` | 2834804 |
| [SHA256SUMS.txt](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.22/SHA256SUMS.txt) | `6763189aad99e1f4804d3d25e3e5745eabd139e2ce8c0083fed461c927813428` | 289 |
| [correspondence-0.1.0-dev.22.dwpkg](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.22/correspondence-0.1.0-dev.22.dwpkg) | `4e307116232a8dbd2a7f91eda57d1aae3cdea25e773b39ff3b657fda380d9b79` | 2814613 |
| [correspondence.tar.gz](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.22/correspondence.tar.gz) | `c0471e24d5248ab6f296f463044649b599f96d343b90ada9ef1b79b936123f6e` | 2427321 |

---

# CHIM Sealed Fates 0.1.0-dev.21 — PRE-ALPHA

The courier had stopped delivering to anyone whose paperwork had a smudge on it. Smudges are not a crime. He has been reminded.

Background letters no longer stall when an NPC's profile metadata has an unusual shape: a death flag that is missing or not a clear true/false, metadata or stats that are not JSON objects, or relationships stored as a list or text. Those NPCs were already treated as eligible correspondents, but letters to or from them were deferred indefinitely and their senders could not start new letters. Explicit opt-outs and explicit deaths still stop mail, and unreadable Background Life or Letters settings still wait for repair.

**Web-only release.** The optional Department/PRISMA interface prealpha.4 is paired with dev.20 and refuses dev.21. If you use PRISMA, stay on dev.20 until a dev.21 pairing is released; the existing web plugin page works with dev.21. The matching delivery bridge is unchanged from dev.17 and is still required for physical notes, items and gold.

Verification: a new regression failed before the change and passes after it; all 29 pure PHP suites pass; all 28 PostgreSQL jobs pass on a fresh, network-isolated disposable cluster against the exact executable server bytes. Only manifest version metadata changes from those bytes. Release-gate and package tests pass, and the assets reproduce from the clean tag. Actual Skyrim inventory/co-save/gameplay, provider/model quality and campaign-scale performance remain unverified.

## Dev.21 assets

[Download this PRE-ALPHA release](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/tag/correspondence-v0.1.0-dev.21). Use one documented installation route and keep only one correspondence version enabled.

| File | SHA-256 | Bytes |
| --- | --- | ---: |
| [CHIM-Sealed-Fates-0.1.0-dev.21.zip](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.21/CHIM-Sealed-Fates-0.1.0-dev.21.zip) | `f68e6b56dc3986026eb2d6848fe68595c8d577215b24362ffa4687f26fbd1f16` | 2827550 |
| [SHA256SUMS.txt](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.21/SHA256SUMS.txt) | `27c68d9f23ab40ddece8b4cc3cd4932ba716c1a0f407d3973b49d7823160dfa1` | 289 |
| [correspondence-0.1.0-dev.21.dwpkg](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.21/correspondence-0.1.0-dev.21.dwpkg) | `70b60d31762ac0b2edbac86964414ee64fd58878266bc3e09deb96797ada1345` | 2807359 |
| [correspondence.tar.gz](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.21/correspondence.tar.gz) | `e54cfa87684a149b96d7662dfb8224b9786d223c461a67e7bc9f8dc3687993c1` | 2426004 |

---

# CHIM Sealed Fates 0.1.0-dev.20 — PRE-ALPHA

Skyrim has one courier. I gave him emotional blackmail, gold transfers and forwarded insults, then misplaced his filing stamp. He kept the letter. The software should have done the same. It does now. His union has not returned my calls.

Closed letters remain visible when their later close event is pruned. The page flags the missing proof while dispatch/retry stays blocked. A matching original receipt or read-only reconciliation can recover delivery only with surviving original document and dispatch evidence. Closing the paperwork does not recall a letter, refund money or prevent genuine late delivery from causing social consequences.

A failed restore-marker write now attempts persistent database quarantine for the clear epoch observed before the attempt. It preserves the native floor and any newer initializer, including one completed before token capture. Existing restore, identity, inherited-transaction and unknown/no-resend protections remain strict. Unusable biographies normalize at prompt projection, and clean re-claimed decisions age from the new attempt. Full forwarded letters remain intact.

**The new Skyrim interface is optional; the existing web plugin page still works.** See [the exact Department/PRISMA pairing and its publication status](PRISMA-COMPATIBILITY.md). The matching delivery bridge is still required for physical notes, items and gold; its PEX and original build provenance are unchanged from dev.17. No sibling plugin is bundled.

Verification: accepted disposable evidence covers 28 PostgreSQL jobs, 35 Linux pure cases and two targeted causal PG RED/GREEN comparisons. The exact executable server and contract bytes are preserved; only manifest version metadata changes. Fresh Windows checks passed 91 PHP lints, 34 pure cases, 60 translated parser checks and 41 fake-native state checks; all 30 package tests pass. A clean annotated-tag export reproduces the four assets below. One synthetic operation fixture was corrected to include its real playthrough identity; its identity-race assertion is unchanged. See [recovery and evidence](DEV20-RECOVERY.md), [installation](INSTALL.md) and [verification limits](VERIFICATION.md).

This is PRE-ALPHA server recovery and packaging evidence. Actual Skyrim inventory/co-save/gameplay, installed native interface behavior, new real-model quality and campaign-scale performance remain unverified. Subsequent exact Department dev.20 server pairing passed all 33 facade cases and 11 prechecks; the four dev.20 assets and tag are unchanged. If no trustworthy prior clear epoch/token exists, or both persistence channels fail while core continues, cross-process quarantine cannot be guaranteed; repair prerequisites and perform another supported init. No live data, installed game, core or provider is changed. The original dev.20 publication did not start TEST or contact the coordinator.

## Dev.20 assets

[Download this PRE-ALPHA release](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/tag/correspondence-v0.1.0-dev.20). Use one documented installation route and keep only one correspondence version enabled.

| File | SHA-256 | Bytes |
| --- | --- | ---: |
| [CHIM-Sealed-Fates-0.1.0-dev.20.zip](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.20/CHIM-Sealed-Fates-0.1.0-dev.20.zip) | `3674e2eba435a947bf17e6be733bae202830ea11446ece36c57011327a9aa89f` | 2828316 |
| [SHA256SUMS.txt](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.20/SHA256SUMS.txt) | `f398aaea3773ff5adbb96391acfaf32908c2c9a915357f4c3b71ba3c2c210f91` | 289 |
| [correspondence-0.1.0-dev.20.dwpkg](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.20/correspondence-0.1.0-dev.20.dwpkg) | `4ba2336d7a5fe2bea9b1120f06557fe8c4197006bd449e4e4b64f097ea0ebb14` | 2808125 |
| [correspondence.tar.gz](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.20/correspondence.tar.gz) | `1acbbb4c85edf71733f8fb805f7cc8b887a00811a61abe9131e99fdf48f650ac` | 2426039 |

Historical release sections and their original checksums remain below. Dated dev.14–18 advisories preserve their old tags/downloads.

---

# CHIM Sealed Fates 0.1.0-dev.19 — PRE-ALPHA

Skyrim has one courier. I gave him more paperwork, then discovered the paperwork had learned to block the queue. He has filed a grievance. I have fixed the queue. Neither of us has received a raise.

Overlapping initializers can no longer clear another initializer's pending restore intent. Cooling-down recipients and temporarily invalid sources no longer pin later eligible correspondence. Deferred sources get bounded fresh/recheck capacity, canonical contact names and prompt validation before a model claim. Reconsideration cannot erase prior claim anchors, counters or effects.

Transaction and connection boundaries are checked, initialization failures are bounded, and diagnostics retain exact refusal reasons. The web page adds **Close without resending or refunding** for manual letters: an anchored administrative closure that preserves the actual physical state. It cannot refund money, cancel a queued game command, invent failure, or make an unknown transfer safe to resend. Genuine late receipts remain valid. Full forwarded letters are preserved.

**The Skyrim interface is optional, and the existing web plugin page still works.** The matching delivery bridge is still required for physical notes, items and gold; its compiled PEX is unchanged from dev.17. The Department Skyrim interface is optional. Its published prealpha.1 targets dev.17; a separate dev.18 adapter candidate does not certify dev.19. Use the existing web plugin page until an exact dev.19 Department pairing is verified. No sibling plugins are bundled.

Verification: 27 PostgreSQL jobs against the exact final server bytes, 29 Linux pure suites (32 cases), and eight causal RED comparisons. Fresh host checks and a clean annotated-tag rebuild bind these assets to the tested source. Two legacy/coverage PostgreSQL fixtures were strengthened and rerun; setup errors and superseded runs are excluded. See [recovery and evidence](DEV19-RECOVERY.md), [upgrade/removal instructions](INSTALL.md) and [verification limits](VERIFICATION.md).

Actual providers/model quality, Skyrim inventory/co-save/gameplay, campaign-scale performance and an exact Department dev.19 adapter remain unverified. If an init can persist neither filesystem intent nor its database transition while core continues, cross-process quarantine cannot be guaranteed; restore the prerequisites and complete a supported init. No live server, installed game, core or provider was changed.

## Dev.19 assets

[Download this PRE-ALPHA release](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/tag/correspondence-v0.1.0-dev.19). Use the paired ZIP or the server package for its documented route; keep only one correspondence version enabled.

| File | SHA-256 | Bytes |
| --- | --- | ---: |
| [CHIM-Sealed-Fates-0.1.0-dev.19.zip](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.19/CHIM-Sealed-Fates-0.1.0-dev.19.zip) | `d70b4f74b83678722a632581faf970a4b92dba2e7f1f4cb532449e8756c0188e` | 2823285 |
| [SHA256SUMS.txt](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.19/SHA256SUMS.txt) | `c332b95db1e4506f6329fd10e498de7610df4cc92e7fe06c2dd321b6dcd9d1ae` | 289 |
| [correspondence-0.1.0-dev.19.dwpkg](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.19/correspondence-0.1.0-dev.19.dwpkg) | `8035df863bb442453af6683675f07fa1d1d9b811cb479cf8464f7a9a4289db99` | 2803094 |
| [correspondence.tar.gz](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.19/correspondence.tar.gz) | `b0ea4dbc1a4c003159ff1112b1d0bd2a3959996e1c60f85353203656d467ad4b` | 2425380 |

The older release sections below retain their original evidence and checksums.

---

# CHIM Sealed Fates 0.1.0-dev.18 — PRE-ALPHA

The courier has learned to survive simultaneous paperwork. I still have not learned to hire a second courier.

Ordinary concurrent events no longer eat valid replies. Abandoned claims recover or become safely inert; transient completion retries reuse the same model result without another model call or transfer. Restore epochs, native timestamp floors, exact source/body checks and unknown/no-resend rules remain strict. Sender/contact selection, receipt casing, operator-origin checks and the documented package command are repaired.

The Department Skyrim interface is optional. Published Department prealpha.1 targets dev.17 and refuses dev.18. A separate dev.18 adapter candidate is being developed and requires its own restore-fencing and signed-unknown receipt tests. Use the existing web plugin page until an exact dev.18 Department pairing is verified; adding version hashes alone is insufficient. No sibling plugins are bundled.

All 21 integrated PostgreSQL jobs and 23 pure suites (25 cases) passed on the exact frozen disposable candidate. Fresh host/package checks and clean-tag rebuilds bind this publication to those bytes. The bridge PEX is unchanged from dev.17; its original compile descriptor remains intact. No fresh native/gameplay, provider/model-quality or live-installation claim is added. See [recovery details](DEV18-RECOVERY.md), [installation](INSTALL.md) and [verification limits](VERIFICATION.md).

Documentation clarification — 2026-10-21: dev.18 removes the global event-clock freshness scan. A retained dev.17 performance sentence in the tagged README is superseded by the corrected main README and dev.18 recovery notes. Release runtime, immutable tag and all asset checksums are unchanged.

## Dev.18 assets

[Download this PRE-ALPHA release](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/tag/correspondence-v0.1.0-dev.18). Install the paired ZIP or use the server package for its intended route; avoid multiple enabled correspondence versions.

| File | SHA-256 | Bytes |
| --- | --- | ---: |
| [CHIM-Sealed-Fates-0.1.0-dev.18.zip](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.18/CHIM-Sealed-Fates-0.1.0-dev.18.zip) | `d5226ebe217e4801a95002bf7a620ca2da72a0fa18841bd5494eb1ec3ef85e70` | 2792708 |
| [SHA256SUMS.txt](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.18/SHA256SUMS.txt) | `594c18099dfb2ec6020448162631bb72aef7443163fdeff82710d76bbc330158` | 289 |
| [correspondence-0.1.0-dev.18.dwpkg](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.18/correspondence-0.1.0-dev.18.dwpkg) | `028161a4911c7ab3b33c61e7f109690c3ae1df2fcecc9e64f3e62a9290c3cbf1` | 2772517 |
| [correspondence.tar.gz](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.18/correspondence.tar.gz) | `dfa832e70901994704d8fe5a1c40e87a1ca2cfde45277133d6f4cb92515f3083` | 2420407 |

The web page remains available; the Skyrim interface is optional, while the matching delivery bridge remains required for physical notes/items/gold. Published dev.14–17 tags and assets are unchanged.

---

# CHIM Sealed Fates 0.1.0-dev.17 — PRE-ALPHA

The courier has acquired a recovery procedure. I briefly considered giving him a pension, but apparently I am the villain in this quest.

This release fixes stuck offscreen mail, current-generation abandoned claims, durable callback-attempt accounting, init failure isolation, and uncertain receipt validation. A proven transient offscreen reaction can be reconsidered once; old ambiguous failures cannot. Full forwarded letters and transfer idempotency remain required.

Receipt intake can retry the same packet once after a storage failure, including restore-lock contention. It never sends the item or gold transfer again. Two exhausted lock waits still need read-only Reconcile; this is bounded recovery, not a promise that storage cannot fail.

The matching bridge was rebuilt with the pinned Caprica compiler. Its sanitized PEX is **19,809 bytes**, SHA-256 `ee23f155d9417d6c68007613abade09cd218f5e6f11ea700413b3bb1ac4539c0`. Raw compiler output and logs remain private; only the anonymous PEX and portable provenance receipt belong in distributable source. Install the matching bridge and server package together.

The Department in-game interface is optional. The existing web plugin page still works. The updated adapter passed exact dev.17 synthetic owner/adapter checks on disposable TEST; its containing Department change was a local candidate at verification time. Older builds without dev.17 pins refuse it. See [the tested boundary and adapter digest](VERIFICATION.md). This release does not bundle sibling mods or certify Skyrim gameplay.

This PRE-ALPHA publication follows the completed local repair task; no live installation is included. Final disposable recovery results are recorded in [verification notes](VERIFICATION.md); older results below remain tied to their older source.

## Dev.17 assets

Download this [PRE-ALPHA release](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/tag/correspondence-v0.1.0-dev.17). Verify them with their matching SHA256SUMS.txt. All 14 disposable PostgreSQL suites and 20 pure suites (22 cases) passed; 25 package checks and the real bridge compile passed. Native gameplay and new provider/model quality remain unverified.

| File | SHA-256 | Bytes |
| --- | --- | ---: |
| [CHIM-Sealed-Fates-0.1.0-dev.17.zip](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.17/CHIM-Sealed-Fates-0.1.0-dev.17.zip) | `dd56b5d6c7dd6f6dbd5f411e39877021936af3ae8624bcfd531558e999dd1557` | 2,746,275 |
| [correspondence-0.1.0-dev.17.dwpkg](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.17/correspondence-0.1.0-dev.17.dwpkg) | `b0f4a51dd8dade1bad491d04b677c31d870b9cb85bb77d4e88569c7762445338` | 2,726,084 |
| [correspondence.tar.gz](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.17/correspondence.tar.gz) | `48390fb738775c35d0e9ecd10b0b24bb530a8afff7f1f089ac21882532980c24` | 2,412,058 |
| [SHA256SUMS.txt](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.17/SHA256SUMS.txt) | `832607f79002d2071591044235dd16017a76ee5f09c81f42f18b2bf8cc8badca` | 289 |

---

# CHIM Sealed Fates 0.1.0-dev.16 -- PRE-ALPHA

Skyrim still has one courier, and he is VERY good at his job. I continue to reward him with love letters, forged apologies, and forwarded insults; the maintenance work is intended to keep that tiny bureaucracy from eating its own paperwork.

## What changed

- Preflighted the Background Life connector before source claim. Due queued letters still dispatch when the connector is unavailable; a new source remains unclaimed and eligible until a valid connector can process it.
- Reapplied the full core connector setup inside the guarded request callback, so `fast_request` sees the connector's own action flags while native dialogue globals are restored afterward.
- Kept request exceptions terminal after entering the model callback. Existing `failed/model_unavailable` decisions remain terminal audit records because their historical reason and `model_calls` value cannot establish whether a callback ran. `model_calls` records callback attempts, not provider billing or receipt.
- Narrowed prompt reads to empty, queued, and already-exposed paths. First exposure still revalidates the complete body and references; effect writes remain serialized. F-05 measurements and their limits are in the [verification notes](VERIFICATION.md).
- Built deterministic dev.16 assets and passed the fresh-fixture package suite. This does not add a provider evaluation or Skyrim gameplay result.

## Interface and pairing

The in-game Department-owned interface is optional; the existing Sealed Fates web plugin page remains usable. Exact dev.16 compatibility pins await owner confirmation, and no dev.16 sibling bundle is included. The matching Papyrus bridge remains required for physical notes and item/gold transfers.

Normal CHIM update checks may replace the historical kit's tested sibling candidates and invalidate that pairing. An older enabled Mind Poisoning MO2 package may also synchronize an older build back. Do not stack old and new routes. The [dev.15 web presentation release](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/tag/correspondence-v0.1.0-dev.15) is historical dev.15 evidence only; the [dev.14 recovery kit](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/tag/correspondence-v0.1.0-dev.14) remains evidence for its exact older pairing only.

## Historical dev.16 assets

Verify downloaded files against these SHA-256 digests and SHA256SUMS.txt.

| Candidate asset | SHA-256 | Bytes |
| --- | --- | ---: |
| [Paired MO2 bridge and CHIM server package](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.16/CHIM-Sealed-Fates-0.1.0-dev.16.zip) | `53cd53f25825e06731d4f56427490f84400a1b4dbc43210a582f2affbad3d74d` | 2,718,035 |
| [Server package](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.16/correspondence-0.1.0-dev.16.dwpkg) | `4bce754c04e4285d9d02fe02db5eae10406197ceffc67ccf6b97b47236d1b4d1` | 2,699,157 |
| [Server-only maintenance archive](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.16/correspondence.tar.gz) | `652d939f28b820cdf5883dddf52b04d436cda906c21a992b840aefa2658db1aa` | 2,407,616 |
| [Checksum list](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.16/SHA256SUMS.txt) | `087ab06825cf30073dde25e7cb0e8ab6574fec43c37529376deb3487e33a5810` | 289 |

The paired ZIP reuses the unchanged, hash-verified bridge PEX pinned in [game/bridge-manifest.json](../game/bridge-manifest.json): SHA-256 `7020f4d7dca2c5d94628102540add58227d93b106ef619ae65a33c00ccc8fd8a`, 18,496 bytes. The package builder verifies the manifest, sanitized metadata, and preserved compiled body. No new Caprica build is part of dev.16.

## Verification and limits

The fresh-fixture Windows package suite passed 20 tests. Nine integrated PostgreSQL suites passed on owned disposable socket databases, including 41 autonomy-recovery checks and the F-05 store fixture. The pure host regression passed. The pure PHP batch's one environment issue and the complete F-05 measurements are described in [verification notes](VERIFICATION.md).

The isolated test cluster was stopped and its runtime lease returned. Protected live-VHD size and modification time matched the preflight metadata; this is not a whole-volume content hash. No provider or gameplay session was run. The measurements are synthetic and single-fixture: they do not establish campaign-scale performance, live mod compatibility, note display, inventory/co-save atomicity, or NPC interpretation quality.

---

PRE-ALPHA. No automatic retry of historical ambiguous failures, no provider receipt/billing claim, and no new Skyrim gameplay claim.
