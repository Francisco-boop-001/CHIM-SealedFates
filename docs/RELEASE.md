# Sealed Fates dev.29 — PRE-ALPHA: the courier hires an accountant

The post office has reviewed its procedures. Apparently giving every letter to a faction nobody belongs to, dating every parcel 1980, and rereading the entire filing cabinet were not operational best practices. My managerial brilliance continues to require witnesses.

## Install one file

Download **[CHIM-Sealed-Fates-0.1.0-dev.29.zip](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.29/CHIM-Sealed-Fates-0.1.0-dev.29.zip)**. After ending the game session and following [Updating](INSTALL.md#updating), replace the previous Sealed Fates MO2 mod; keep one version enabled. This ZIP contains the matching delivery bridge, unchanged companion observer and `CHIM/server-plugins/correspondence/0.1.0-dev.29.dwpkg`. No second Sealed Fates archive or manual checksum calculation is required. Confirm dev.29 in CHIM after its normal synchronization. Publishing is not installation.

**Upgrade prerequisite:** migration006 creates the written-letter index on `public.eventlog`. CHIM's database role must own that table or an administrator must apply the bundled migration first. Missing rights, a conflicting index, malformed marked history or a build-lock timeout refuse setup; do not bypass the checks. The three-second timeout covers lock acquisition only: a large index build can hold the eventlog lock for longer, without a build-time cap. Schedule a maintenance window with game/workers stopped, or have the table owner pre-create the index before resuming. [Administrator details](INSTALL.md).

## What changes for you

- A delivered note is owned by its addressed actor base, with ownership checked before and after attachment transfers. NPCs are not added to a shared faction. Existing notes and cached receipts are not rewritten. This repairs the source of the stolen-note risk; actual Skyrim stolen flags still need a gameplay check.
- Older CHIM 3.4.2 can preflight its known `openaijson` and `openrouterjson` dialogue connectors without the newer decision-classifier helper. A valid configured model/HTTP(S) endpoint, enabled BGL gate and request API remain required. Decision-only endpoints/models and unknown drivers remain refused. No provider settings are changed and no call is made during readiness checks.
- Repeated actor-catalog construction and written-source duplicate searches do less work. Full stored letters and all validated known references remain available. This is not a constant-time mailbox or a campaign-scale performance claim.
- ZIP members have the explicit release date 2026-10-28, so CHIM's modification-time selector can distinguish the new package from earlier playtests. The archive remains reproducible. Keep one version enabled; actual MO2 timestamp handling is still an installation check.
- The same emo llama is about 79% lighter over the wire. His feelings remain extremely heavy. Original artwork is retained; code/docs are MIT, artwork is CC BY 4.0 with credit. All distributions carry the notices.

**The Skyrim interface is optional. The existing CHIM web plugin page still works.** [Department prealpha.25](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/tag/v0.1.0-prealpha.25) supplies the exact dev.29 source pins and memo adapter, with unchanged native/UI bytes. Its offline source/package verification is documented in the [compatibility record](PRISMA-COMPATIBILITY.md); no new runtime/gameplay pairing is claimed. Older prealpha.24 pins must not be bypassed. Use CHIM through localhost on the server computer and choose **Plugins → Sealed Fates → Plugin Page**.

## What deliberately stays strict

Fresh native observations, playthrough/restore authority, original-operation identity, claim ownership, full forwarded letters, origin/access/CSRF checks and unknown/no-resend behavior remain required. No automatic replay, refund or physical resend is added.

The JContainers co-save journal still retains claims, exact dispatch packets and cached receipts indefinitely. The available API offers no verified lossless compression/digest primitive. Deleting old claims, substituting an approximate hash or moving authority to an unrelated file would weaken duplicate-transfer and restore protection. That item remains open; it has not been renamed into a fix. Bridge packets contain transfer/correlation data, not full letter bodies.

## Verification boundary

The release uses offline regression fixtures, source-translated fake-native checks, a real source-bound Caprica compilation, source/package review and deterministic asset verification. The source-backed legacy-core preflight uses the actual retained 3.4.2 manager and both dialogue drivers with fake database/config boundaries; its provider callback is not invoked. Detailed results and remaining history costs are in the [findings assessment](DEV29-FINDINGS.md).

No live server, test2/WSL, installed game/MO2, campaign, provider or production configuration is used for these tests. Actual Skyrim stolen-item UI, note rendering, physical item/gold movement, companion observations/follower frameworks, save/co-save restoration, installed HTTP sending and real-model behavior remain unverified by this release. Earlier test2 evidence stays historical and does not certify these changed bytes. This remains **PRE-ALPHA**.

Read [installation](INSTALL.md), [verification limits](VERIFICATION.md), [companion behavior](COMPANION-CHECKINS.md) and [interface compatibility](PRISMA-COMPATIBILITY.md).

## Historical dev.28 — PRE-ALPHA: the courier remembers his return address

The web page was hiding its own return address, then refusing the letter for arriving without one. An impressive administrative achievement. We have reduced the paperwork by one self-inflicted problem.

## Install one file — after your game session

Download **[CHIM-Sealed-Fates-0.1.0-dev.28.zip](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.28/CHIM-Sealed-Fates-0.1.0-dev.28.zip)**. Follow the [Updating instructions](INSTALL.md), replace your previous Sealed Fates MO2 mod and keep one version enabled. The ZIP includes both unchanged Papyrus scripts and `CHIM/server-plugins/correspondence/0.1.0-dev.28.dwpkg`. No second download or manual checksum calculation is required. Confirm **0.1.0-dev.28** in CHIM's server Plugin Manager after normal synchronization. Publishing does not update your running game or server.

## What changed

Dev.27 fixed Apache's canonical-port mismatch, but a real browser could still refuse Send with **Operator origin required**. The page's `Referrer-Policy: no-referrer` caused Chromium to send `Origin: null` on its ordinary form POST. Our origin guard correctly rejected that opaque value.

The page now sends `Referrer-Policy: same-origin`. A normal same-origin form retains its real browser Origin; cross-origin navigation still suppresses its referrer. The access, origin and session CSRF checks are unchanged. Null, malformed and foreign origins remain refused, as do cross-site requests, wrong browser-facing ports/schemes, and unauthenticated remote access. There is no automatic resend, permission bypass, database migration or delivery-script change.

## How to use it

On the computer running CHIM, open CHIM through **localhost**, retaining your configured port and path. Choose **Plugins → Sealed Fates → Plugin Page**. Confirm the server has dev.28, then compose normally. A page-level origin refusal happens before composition; other pending or unknown outcomes still need their original read-only status check, not another copy of the letter.

**The Skyrim interface is optional; the existing CHIM web page remains available.** The native PRISMA crash is a separate Department bug in its gold-count helper, used both by the inventory snapshot and the funds recheck before compose. This Sealed Fates ZIP does not replace `WorldOfDramaLlama.dll` or fix that native crash. Department owns its tested repair and exact dev.28 pairing; use only its explicitly verified release. No compatibility pin has been bypassed here.

## Verification and remaining limits

A standalone loopback PHP fixture loads the production pure view helpers and extracts the real response policy from `index.php`; it never loads CHIM bootstrap or connects to a campaign database. Headless Edge/Chromium reproduces the old policy's natural form POST as HTTP403 with `Origin: null`, then passes the repaired request as HTTP200. A wrong CSRF token remains HTTP400, and an intercepted cross-origin navigation carries no referrer. Existing origin/access/UI/ownership/closure and package/preflight checks are run separately, with clean-source asset reproduction and download verification recorded at publication.

The installed live dev.27 files and bounded refusal metadata were inspected read-only with explicit permission. **No test depends on live. No live HTTP request, send, database change, deployment or restart was performed.** Exact live request headers were not instrumented; the policy failure is independently reproduced locally. Test2, installed game/MO2 files and provider settings remain untouched.

The inherited Windows offscreen-host path assertion remains a known baseline failure. PostgreSQL poll-lock/concurrent-event recovery and installed-server end-to-end sending were not rerun. There is no new real-model, native delivery, item/gold transfer, companion/save/co-save or Skyrim gameplay acceptance. Department's native build and host tests must not be presented as an actual Skyrim1.5.97 crash reproduction. This remains **PRE-ALPHA**.

Read [installation](INSTALL.md), [verification limits](VERIFICATION.md) and [interface compatibility](PRISMA-COMPATIBILITY.md).

## Historical dev.27 — PRE-ALPHA: the courier discovers ports

The letter was addressed correctly. Unfortunately, the web form and Apache disagreed about which door it had come through. The courier has been reassigned from network administration. This is probably for the best.

## Install one file — after you finish playing

Download **[CHIM-Sealed-Fates-0.1.0-dev.27.zip](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.27/CHIM-Sealed-Fates-0.1.0-dev.27.zip)**. When your game session has ended, follow the [Updating instructions](INSTALL.md), replace the previous Sealed Fates MO2 mod and keep exactly one version enabled. This one ZIP contains both unchanged Papyrus scripts and `CHIM/server-plugins/correspondence/0.1.0-dev.27.dwpkg`. No second archive or manual checksum calculation is required. Confirm **0.1.0-dev.27** in CHIM's Plugin Manager after normal synchronization. Publication does not update your installed server or game.

## What changed

A same-origin form submission such as `http://localhost:8081` could be refused with **Operator origin required** when Apache reported its canonical virtual-host port as `80`. Opening the page worked; pressing Send did not.

The origin check now compares the submitted Origin against the validated browser-facing Host and effective scheme port. Apache's internal/canonical port can differ. Authorization and session CSRF protection remain mandatory; foreign hosts, wrong browser-facing ports, changed schemes, malformed authorities and cross-site Fetch Metadata remain refused. Forwarded protocol headers still grant no trust. Server-port sanity checks remain. There is no automatic resend, new permission, migration, native-script change or provider/configuration change.

## How to use it

On the computer running CHIM, open **CHIM itself through localhost**, keeping your configured port and installation path. Choose **Plugins → Sealed Fates → Plugin Page**. After the updated version is confirmed installed, compose and send normally. A refused origin-check POST is rejected before composition; confirm the mailbox/status before deciding whether to retry a submission with any other error. Pending or unknown delivery is never a reason to create a duplicate.

**The Skyrim interface is optional; the CHIM web plugin page remains available.** Dev.27 has no verified Department/PRISMA pairing. Historical Department prealpha.13 evidence remains limited to exact dev.23 source. No pins were bypassed or interface-owner changes made. Dev.26's companion polling/ownership and safe Close repairs remain included.

## Verification and remaining limits

The new local regression fails on the old origin helper and passes with this fix. It covers localhost:8081 with canonical port 80, default ports, HTTPS topology and wrong host/port/scheme refusal. Existing access, CSRF, UI, scheduler ownership and safe-close checks pass. Clean-tag builds and all draft/public downloads are verified separately during publication.

The known Windows offscreen-host path assertion remains a baseline failure; its Linux rerun is pending. **Neither live nor test2 was accessed for this publication. The user is still playing.** A prior, separately authorized read-only observation supported the diagnosis, but no live request instrumentation or successful installed POST retest is claimed. The fixed files have not been installed by this publication.

Test2 PostgreSQL poll-lock, concurrent-event, safe-close/repeated-tick and earlier cooldown/restore/replay checks remain pending. There is no new real-model, browser-rendering, installed-server or Skyrim gameplay proof. Native delivery, companion observations and save/co-save behavior retain their existing acceptance limits. This remains **PRE-ALPHA**.

Read [installation](INSTALL.md), [verification limits](VERIFICATION.md) and [interface compatibility](PRISMA-COMPATIBILITY.md). Live/test2, installed game/MO2 files, providers and production configuration were left untouched; the Department owner was not contacted.

## Historical dev.26 — PRE-ALPHA: the courier learns to let go

The courier no longer grabs the eventlog writer lock just to announce he has nothing to do. Companion letters also stop offering forbidden manual sends, while keeping the useful button: closing one letter without mailing it again. Apparently "do less" required a departmental meeting.

## Install one file

Download **[CHIM-Sealed-Fates-0.1.0-dev.26.zip](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.26/CHIM-Sealed-Fates-0.1.0-dev.26.zip)**. After ending your game session, replace the previous Sealed Fates MO2 mod and keep one version enabled. The ZIP includes both unchanged Papyrus scripts and `CHIM/server-plugins/correspondence/0.1.0-dev.26.dwpkg`. No second archive or manual checksum calculation is required. Confirm **0.1.0-dev.26** in CHIM's Plugin Manager after normal synchronization. Publication does not install anything.

## What this repairs

- Disabled and cooled-down companion polls check their state in the existing restore-protected read transaction before entering the eventlog-locking writer. Due polls still enter the writer and repeat the enabled/cooldown/context checks there. This is the narrow polling change from PR #5, not the removal of restore or freshness safeguards.
- The web page recognizes companion-owned letters. Manual Dispatch and Retry remain scheduler-only, instead of presenting buttons the backend refuses.
- Companion letters retain **Close without resending or refunding**. Closing one letter creates no transfer or delivery claim, and does not enable a manual resend. Unknown/in-flight letters retain read-only reconciliation, and a genuine late receipt can still resolve their delivery status. Existing NPC-to-NPC scheduler closure rules remain unchanged.

The dev.25 localhost guidance and dev.24 cooldown repair remain included. The web interface is opened through **CHIM Plugins → Sealed Fates → Plugin Page**; on the computer running CHIM, use CHIM's localhost address with your configured port/path.

## Compatibility and limits

**The Skyrim interface is optional. Use the existing CHIM web page for dev.26.** No Department/PRISMA pairing is claimed. Historical Department prealpha.13 proof remains limited to exact dev.23 bytes; no pins were bypassed and the Department owner was not contacted.

Access, origin and CSRF protection remain intact. There is no database migration, native-script change or provider configuration change. This release addresses issue #2 and item 1 of issue #4 through reviewed PR #5 plus its safe-close correction. It does not claim to fix the other GitHub issues, stolen-note behavior or older-core compatibility.

## Verification — read before testing a cherished save

Fresh local ownership, closure, access, restore-policy and package/preflight checks pass apart from the existing Windows offscreen-host path assertion, which also fails on the unchanged baseline. The safe-close regression failed before repair and passes after it; it covers queued, failed, unknown and in-flight UI states, malformed ownership lists, no new sends, and preserved NPC-to-NPC policy. Clean-tag builds and draft/public downloads are independently checked during publication.

**The PostgreSQL poll-lock, concurrent-event and safe-close/repeated-scheduler recovery cases were not rerun here. Test2 remains reserved and untouched.** The PR author reports successful separate disposable PostgreSQL tests for the original PR, but those reports do not independently verify this final repaired release. Its strict test2/cluster identity checks were retained; the extended recovery test remains pending. The earlier cooldown/restore/replay reruns also remain pending.

No new real-model, browser-rendering, installed-server or Skyrim gameplay proof is claimed. Actual companion observations, physical note/item/gold delivery, follower frameworks, save/co-save restoration and live lock behavior still need their stated runtime proof. This is **PRE-ALPHA**, with local verification and explicit pending server/gameplay checks.

Live, test2, installed game/MO2 files, providers and production configuration were not accessed or changed for this publication. See [installation](INSTALL.md), [verification](VERIFICATION.md), [companion behavior](COMPANION-CHECKINS.md) and [interface compatibility](PRISMA-COMPATIBILITY.md).

## Historical dev.25 — PRE-ALPHA: the courier asks for directions

The web page used to say "Operator access required" and leave you to negotiate with a WSL address. The courier has now been issued directions. Management considers this an extravagant employee benefit.

## Install one file

Download **[CHIM-Sealed-Fates-0.1.0-dev.25.zip](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.25/CHIM-Sealed-Fates-0.1.0-dev.25.zip)**. After ending your current game session, replace the previous Sealed Fates MO2 mod and keep exactly one version enabled. This one ZIP contains both unchanged Papyrus scripts and `CHIM/server-plugins/correspondence/0.1.0-dev.25.dwpkg`. No second archive or manual checksum calculation is required. Confirm **0.1.0-dev.25** in CHIM's Plugin Manager after the next normal synchronization; downloading it does not install it.

## Open the web interface

On the computer running CHIM, open **CHIM itself through localhost**, using your configured port and installation path. Go to **Plugins**, then press **Sealed Fates → Plugin Page**. Bookmark that local CHIM address: its Plugin Page button inherits the address you used to open CHIM.

For example, `http://172.17.226.57:8081/HerikaServer/` becomes `http://localhost:8081/HerikaServer/ui/server_plugins.php` for local browser access. That is an example, not a universal port or installation path.

If access is refused, the page now explains this route and offers a clickable localhost CHIM Plugins link for recognized direct HTTP private/loopback addresses and installation paths. Public, forwarded, HTTPS or unrecognized routes get instructions without a guessed link. From another computer, use web-server-authenticated operator access: localhost points to the computer running the browser. The link does not redirect automatically or resend a letter. A wrong or unavailable local route remains an administrator routing/authentication issue.

## Security and compatibility

The existing authorization, origin and CSRF checks are unchanged. Private addresses do not grant access. The denied route still returns **403** and exits before starting a session or correspondence runtime. There are no database migrations, provider changes, native-script changes or new correspondence behaviors in this fix. The dev.24 cooldown repair remains included.

**The Skyrim interface is optional. Use the existing CHIM web plugin page for dev.25.** No new Department/PRISMA pairing is claimed; Department prealpha.13 remains an exact-source pairing for unchanged dev.23. Its refusal checks were not bypassed or updated.

## Verification and remaining limits

The refusal regression failed before implementation and passes after it. Fresh local GET/POST probes execute the actual denied route and confirm 403, no session and no resend form. Local checks cover safe port/path retention, malformed and forwarded requests, remote fallback, origin/CSRF protections, the static refusal fixture, package/preflight behavior and PHP syntax. Clean-tag packages and downloaded release bytes are verified separately during publication.

The existing Windows offscreen-host path assertion also fails on the unchanged baseline; its Linux rerun remains pending. **Test2 remains reserved and was not accessed.** The dev.24 PostgreSQL cooldown reproduction and freshness/restore/replay reruns remain pending. No new real-model, installed-server, browser-rendering or Skyrim gameplay verification is claimed. Companion detection, physical letters/items/gold, follower frameworks and save/co-save behavior still require their stated gameplay proof. This remains PRE-ALPHA.

Neither live nor test2, the installed game, providers or production configuration was accessed or changed for this release. Read [installation](INSTALL.md), [verification limits](VERIFICATION.md) and [interface compatibility](PRISMA-COMPATIBILITY.md).

## Historical dev.24 — PRE-ALPHA: the courier discovers a calendar

One courier. Several strongly worded letters. A scheduling bug that looked at a newer claim, forgot an older cooldown, and apparently decided working conditions were a suggestion. Fixed locally; now available for an explicitly limited personal playtest.

## Install one file

Download **[CHIM-Sealed-Fates-0.1.0-dev.24.zip](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.24/CHIM-Sealed-Fates-0.1.0-dev.24.zip)**. Replace the previous Sealed Fates MO2 mod after ending the current game session; keep exactly one version enabled. The ZIP contains the delivery bridge, companion observer and `CHIM/server-plugins/correspondence/0.1.0-dev.24.dwpkg` together. You do not need to install another archive over it or calculate a checksum. Other assets are administrator alternatives.

On the next normal CHIM startup/synchronization, confirm **0.1.0-dev.24** in the server Plugin Manager before testing. An MO2 checkbox is not a successful upload receipt. The embedded version-only filename avoids the dev.22 package naming mismatch that could produce HTTP 400 during synchronization.

## Interface compatibility — read this bit

**Use the existing Sealed Fates CHIM web plugin page for dev.24.** The optional Department/PRISMA interface is separate. Department prealpha.13 was verified for unchanged dev.23 and pins its exact source; **dev.24 is not verified with Department and should be refused by that adapter**. No Department files or safety checks were changed. If you require that exact optional pairing, retain dev.23 and its matching interface. The courier can survive a browser tab.

## What changed

A newer companion claim could hide an earlier active cooldown from an older in-flight request. The shared query now excludes future claims before choosing the latest eligible claim. Global and per-NPC durations remain the same; both autonomous schedulers use this helper. Restore/playthrough filters, unavailable-authority refusal, observation freshness, native timestamp floors, claim ownership and duplicate-transfer safeguards are unchanged. There is no schema migration, prompt change or native-script change.

## Verified locally

The new regression fails on the old helper and passes all 15 checks with this repair. Eighteen PHP regression suites pass. All 65 package/preflight tests pass, and the modified PHP files pass syntax checks. The existing Windows host-path test fails at the same assertion on unchanged dev.23; its Linux rerun remains pending. Release packages are rebuilt from a clean tagged source and checked before publication.

## Explicitly not tested for this release

**The new PostgreSQL reproduction and companion freshness/restore/replay regression runs on test2 have not run.** The user reserved test2 while playing and requested publication for personal testing. Earlier dev.23 test2 and model results are historical; they do not certify this new payload. No new real-model evaluation was performed.

Actual Skyrim observation of unloaded companions/follower frameworks, first-game Narrator initialization, physical letter/item/gold delivery, rendering and real save/co-save restoration still need gameplay proof. A server inbox or queued command is not a physical delivery receipt. This remains PRE-ALPHA.

Neither server, the installed game/MO2 files, provider settings nor campaign data was accessed or changed to prepare this release. Publication does not install it. Read [installation](INSTALL.md), [companion behavior](COMPANION-CHECKINS.md) and [verification limits](VERIFICATION.md).

## Historical dev.23 — PRE-ALPHA: the courier clocks in

Your companion can now consider sending a written "are we still doing this?" while you are elsewhere conducting important Dragonborn business. Such as stealing every cheese wheel in Falkreath. The courier has been informed. His resignation has been declined.

## Install one file

Download **[CHIM-Sealed-Fates-0.1.0-dev.23.zip](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.23/CHIM-Sealed-Fates-0.1.0-dev.23.zip)** and install it as your Sealed Fates MO2 mod, replacing the previous version. It contains the matching delivery bridge, new companion observer and server package together. Keep one correspondence package enabled. Start with a disposable game profile and server: this remains PRE-ALPHA. Checksums are supplied for optional integrity verification.

**The in-game Department/PRISMA interface is optional; the existing web plugin page still works.** [Department prealpha.13](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/tag/v0.1.0-prealpha.13) is now the server-tested match for dev.23: 38/38 server case groups and 51/51 browser checks pass. This pairing was verified after the original Sealed Fates publication; its four assets and release tag remain unchanged. Existing dev.23 users need only update the optional Department mod using its single MO2 ZIP. [Exact proof and remaining gameplay limits](PRISMA-COMPATIBILITY.md). The delivery/observer scripts remain separate from that interface.

## What changed

With global autonomous correspondence enabled and the NPC's Background Life and Send letters enabled, an active teammate explicitly waiting apart can consider one attachment-free letter after two observed game-days. Silence is valid. Personality and relationship shape the tone; they do not establish romance, promises, abandonment or exact continuous absence. The first supported signals are vanilla WaitingForPlayer and CHIM's active wait package/faction; unsupported follower frameworks remain unknown.

CHIM's existing idle request heartbeat and player-backed Narrator carry companion observations and scheduler-owned delivery/reconciliation. Recent NPC dialogue or another nearby NPC is no longer required. CHIM must be active and initialized; paused/suppressed heartbeats and long observation gaps delay or reset the evidence. Server wall-clock waiting does not count. Transit uses twelve game-hours and is rechecked before dispatch.

The bughunt repairs keep an unready letter pending while other fresh mail and eligible companions progress, skip per-NPC BGL/cooldown deferrals without bypassing global limits, and require the exact request ID when marking/completing a reclaimed claim. Command framing and interaction-generation stamping are also corrected. Freshness, restore/playthrough, identity and no-resend transfer safeguards remain enforced.

## Verified, and what that actually means

All three bug reproductions fail before repair and pass after repair on the isolated test2 server. The full companion recovery/concurrency/restore suite, actual-core dequeue/response idle test, 18 affected pure suites and 60 package/release checks pass. The source-bound observer has 66 translated policy checks and Caprica compilation evidence. The source/tag build and distributed assets are checked separately. These are server, queue, static and package results.

Six earlier synthetic real-model regression cases produced five queued letters and one silence after recipient-schema and fabricated-history failures were fixed. Those same cases helped refine the prompt, so they are **not an untouched held-out evaluation**. They used a private callback with the configured provider, not CHIM's full installed connector bootstrap. This repair changes no model prompt and makes no new model-quality claim.

## Remaining gameplay testing — explicitly unverified

- Actual Skyrim DLL-to-Papyrus command execution and first-ever new-game Narrator initialization.
- Reading unloaded actors, follower-framework compatibility and real reunion/wait-state observations.
- Actual co-save/save-load behavior and end-to-end restoration in a running game.
- Renderer downloads, note appearance and physical letter/item/gold delivery in Skyrim. A queued server document is not proof that a physical note arrived.

A release upload is not a gameplay playtest, however flattering that interpretation would be to my workload. Test on a disposable setup before trusting a cherished save. No live server, installed game, provider settings or campaign data was changed to prepare or publish this release. The previous delivery bridge remains unchanged; the read-only companion observer is new in dev.23.

[Companion behavior](COMPANION-CHECKINS.md) · [Idle route and limits](COMPANION-IDLE.md) · [Verification receipt](../release-inputs/dev23-verification.json) · [Installation](INSTALL.md)

# Sealed Fates dev.22 — packaging fix 1 (PRE-ALPHA)

Apparently the courier can cope with forged love letters, but naming a file is where my genius ends.

The original MO2 ZIP named its bundled server package `correspondence-0.1.0-dev.22.dwpkg`. CHIM treats the entire filename stem as its version, so the server rejected the mismatch with the package's declared `0.1.0-dev.22` on the final upload chunk. This download fixes that member to `CHIM/server-plugins/correspondence/0.1.0-dev.22.dwpkg`.

**No server code, schema, bridge or owner version changes.** The DWPkg bytes, bridge PEX and maintenance tar are identical to the original dev.22 release. The ZIP and checksum list change. This is a separate corrected download; original tags/assets are preserved. Department prealpha.7's exact dev.22 source pins remain applicable; its TEST-only source/package evidence does not establish new runtime or gameplay acceptance. The in-game interface is optional and the web page still works.

Install the corrected paired ZIP as your single Sealed Fates MO2 mod. If you already have the original dev.22 payload, renaming that one bundled DWPkg to `0.1.0-dev.22.dwpkg` is equivalent; retain only one package in the folder. Do not reset your database or replace the identical bridge. CHIM synchronizes on a normal full game restart/save load. The exhausted startup retry is not reset by refreshing the browser; no server restart is needed for this filename correction. A live installation can run migrations, so use your normal backup and approval procedure before triggering it.

Verification: the original mismatch was reproduced offline against a byte-exact copy of the installed server validator. The corrected name clears that check; the fixture deliberately stops at the migration boundary without a database connection. All 37 package tests and 20 release-gate checks pass, including filename/declared-version consistency, unchanged embedded bytes, reproducibility, immutable output refusal and preserved historical dev.14 reconstruction. Independent review is clear after restoring the historical output-containment guard and adding its causal regression. No game/server restart, live-data/database/provider action, native compile or gameplay test was performed for this correction.

[Download packaging fix 1](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/tag/correspondence-v0.1.0-dev.22-syncfix.1). Use this release's checksum list for the corrected ZIP.

| File | SHA-256 | Bytes |
| --- | --- | ---: |
| [CHIM-Sealed-Fates-0.1.0-dev.22.zip](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.22-syncfix.1/CHIM-Sealed-Fates-0.1.0-dev.22.zip) | `7f2f81df1db9bf3db2a13440f84d8baea9acb723e7759bd25d4ef1b141a0f6ac` | 2834774 |
| [SHA256SUMS.txt](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.22-syncfix.1/SHA256SUMS.txt) | `1ec547d5324bcb2df1a1b09419641947151789acbe8850acfb24439c8c50bcca` | 289 |
| [correspondence-0.1.0-dev.22.dwpkg](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.22-syncfix.1/correspondence-0.1.0-dev.22.dwpkg) | `4e307116232a8dbd2a7f91eda57d1aae3cdea25e773b39ff3b657fda380d9b79` | 2814613 |
| [correspondence.tar.gz](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.22-syncfix.1/correspondence.tar.gz) | `c0471e24d5248ab6f296f463044649b599f96d343b90ada9ef1b79b936123f6e` | 2427321 |

---

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
