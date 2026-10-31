# Dev.31 — letters without a nearby NPC

Server/command-route verification complete; this remains PRE-ALPHA. The separately verified matching interface is [Department prealpha.30](PRISMA-COMPATIBILITY.md), with 11 focused actual API/database groups and 137 assertions. Physical Skyrim delivery and installed interface behavior remain unverified.

The post office previously wanted you to find someone to talk to before it would process a letter. I have been informed this is called going outside and rather defeats the point of correspondence.

Manual mail now has a source-supported path through CHIM's player-backed **The Narrator** carrier. It does not need a nearby managed NPC or a conversation with the recipient. The Narrator is transport; it is not the author, payer or recipient. The letter keeps its stored identities and attachments.

## What must still be running

Skyrim and CHIM must be active, normally initialized and able to process commands. Fresh native game polling supplies a separate activity checkpoint; opening or refreshing the web page or Department cannot create it. Interaction Off and passive native traffic cannot authorize this route. Pausing, recording, application menus or native maintenance suppression can delay new polling; missing or expired authority leaves the letter queued.

The freshness window stays **45 seconds**. The native timestamp is a high-resolution counter with unspecified epoch, not server Unix time. Counters are compared only within current restore authority and against the native restore floor. Unchanged-state heartbeats before the 15-second checkpoint interval are skipped rather than accepted; they do not refresh the existing proof. Equal/older accepted-counter replays cannot renew it. Valid new-counter active/passive transitions are recorded immediately. This is recent accepted native traffic, not proof that the game window is foreground or a human is present.

## The same saved letter

Compose normally in the web page or the matching Department release. If an earlier letter is still queued, select that saved letter and use the interface's **Dispatch** or **Resume delivery** action. Its saved body, recipient, items and gold are used; the current draft is not a replacement. Reconcile/status reads remain read-only. Unknown, partial and already-dispatched outcomes are not permission to send another copy. No automatic resend or refund was added.

Manual mail does not require global autonomous correspondence, Background Life or companion check-ins to be enabled. Those features retain their existing opt-ins, eligibility, pacing and ownership. Independent activity and managed-NPC snapshots share an existing table but preserve separate evidence; neither can silently refresh the other.

## Changes and proof boundary

No schema migration, new native DLL, Quest, timer or Papyrus binary is introduced by this owner change. Existing delivery/observer scripts and their provenance remain unchanged. Versioned packages and exact Department source pins must still match; older interfaces must refuse unfamiliar owner bytes rather than guess.

The final owner matrix passes **19/19 cases** on test2 at `5cf96facf30f128830c38ea2b9422f47bf1d8611` (run `20261030T224815Z-b3002d83`). All **138 staged server/test files** were independently matched to Git. Cold PHP children execute the actual hash-pinned captured Interaction helper against owned state; playthrough entry is a fixture stub, while owner restore checks use the actual store. The core command lane executes captured DataDequeue and the exact request-response branch, with its permission result fixture-controlled; saved document/operation/500-gold values and type shapes match and a second dequeue is empty. No physical renderer/PNG download is exercised.

Repeated/coalesced polls complete in about 3 ms in the controlled ROW EXCLUSIVE contention case. This is not a load benchmark: the existing shared restore advisory fence remains and can wait for an exclusive writer. All owned database resources stopped, scratch was removed, three leases were released and test2 returned to stopped. The isolated fixture had no external network route and no campaign/provider data.

The source-bound offline run passes 36/37 PHP suites plus all 77 Python package/preflight checks. The inherited Windows worker-path fixture failure remains explicit; missing clean-export fixture paths/output-directory prerequisites were corrected and those two tests rerun successfully. The independent bughunt resolved cold-request initialization and per-poll writer locking; final review found no remaining blocking product defect. [Portable proof and exact boundaries](../release-inputs/dev31-npc-independent-delivery.json). Matching Department pins and public asset verification are recorded separately; the owner release uses the explicit web-only gate. The original causal fixture demonstrated that dev.30 kept a zero-nearby-NPC 500-gold letter queued despite seeded activity evidence. That is a synthetic service reproduction, not an actual player letter.

Captured native source supports periodic polling, player-backed Narrator lookup, renderer download and global external-command dispatch with no nearby-NPC gate. Server/PostgreSQL/dequeue tests do not prove physical Skyrim letters, inventory transfers, installed PRISMA rendering/input, first-ever Narrator initialization or save/co-save behavior. The separate mouse-control issue is outside this delivery change. Live and the installed game remain untouched by development and tests.
