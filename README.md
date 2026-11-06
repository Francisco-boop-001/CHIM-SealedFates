**0.1.0-dev.32 — PRE-ALPHA.** The web page now tells you whether **Check outcome** queued a request. Optional [Department prealpha.34](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/tag/v0.1.0-prealpha.34) accepts the exact dev.32 package in offline checks. [Release notes and Skyrim testing limits](docs/RELEASE.md).

# CHIM Sealed Fates

Part of the World of Drama-Llama.

![A Nordic courier carries sealed letters past an affectionate reader and an outraged reader, watched by an emo llama above a doorway.](assets/dashboard-art-source.png)

Skyrim has one courier, and he is VERY good at his job. Let's reward him with love letters, forged apologies, and forwarded insults. I call it 'emergent storytelling' because 'I have made this man's working conditions worse' looks dreadful on a feature list.

Sealed Fates is Skyrim correspondence with consequences: addressed letters, optional item/gold transfers, claimed signatures, replies, forwarding, mediation and bounded NPC correspondence. Optional waiting-companion check-ins use observed game circumstances; unknown state and silence remain valid. It runs as a CHIM server plugin with the matching Papyrus delivery bridge. **The existing plugin does not require the proposed Background Life reading hook.** Start with the [installation guide](docs/INSTALL.md); read the [limits and verification notes](docs/VERIFICATION.md) before using it in a save you care about.

**BGL reading-bridge status, recorded 2026-11-01:** RANGROO reports no known current API for feeding into letters/BGL and says the request will join the system revamp list. The user has **abandoned this bridge project**. No prototype core hook or BGL-reading migration is included in dev.31, and no further bridge development or model testing is planned. Existing BGL participation controls and correspondence workers remain available. [Maintainer answer and exact boundary](docs/BGL-STATUS.md).

## Pick your interface

**Dev.32 Skyrim interface:** [Department prealpha.34](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/tag/v0.1.0-prealpha.34) is the optional companion for dev.32 and retains dev.31 support. Update Department with its own MO2 ZIP after closing Skyrim. The Sealed Fates web page works independently. The pairing passed offline package and adapter checks. [Compatibility details and gameplay limits](docs/PRISMA-COMPATIBILITY.md).

**Historical dev.30 Skyrim interface:** [Department prealpha.28](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/tag/v0.1.0-prealpha.28) supports Sealed Fates dev.30 and adds **Resume delivery** for an eligible saved queued letter. It also finds waiting letters after reopening the interface. If dev.30 is already installed, update only [Department's single MO2 ZIP](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/download/v0.1.0-prealpha.28/WorldOfDramaLlama-0.1.0-prealpha.28.zip) after exiting Skyrim completely; this update includes a new native DLL. Six focused test2 groups passed 83 assertions. Actual Skyrim UI and physical delivery still need gameplay checks. The web plugin page still works. [Recovery steps and exact limits](docs/PRISMA-COMPATIBILITY.md).

**The new in-game Skyrim interface is optional. The existing Sealed Fates web plugin page still works.** Keep using CHIM's web page to compose letters, browse your inbox and outbox, check delivery status, and enable or pause autonomous correspondence. You do not need the new Skyrim interface to use those controls. Your browser has not been fired; I lack the qualifications to conduct that meeting.

The interface is separate from the **matching Sealed Fates Papyrus bridge**, which is still required for physical note delivery and item/gold transfers. Skipping the optional interface does not mean skipping the delivery bridge.

## Download

Download **[CHIM-Sealed-Fates-0.1.0-dev.32.zip](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.32/CHIM-Sealed-Fates-0.1.0-dev.32.zip)**. Close Skyrim, replace your existing Sealed Fates mod in MO2, then launch normally so CHIM can sync the update. This single ZIP includes the standard Papyrus scripts and server package. [Installation](docs/INSTALL.md) · [Release notes](docs/RELEASE.md).

The MO2 ZIP carries `CHIM/server-plugins/correspondence/0.1.0-dev.32.dwpkg`, the version-only filename CHIM expects. Earlier releases remain available as historical downloads.

## The web page

The existing web plugin page now wears the same parchment, serif type, rust-red controls and slate-blue accents as its siblings, with the full emo-llama illustration. It follows your system light/dark preference, keeps the artwork uncropped, and gives wide mail tables their own scroll area on small screens. Your letters, form actions and delivery rules are unchanged. The courier has acquired branding. He has not acquired an assistant.

## What it does

The player can write a letter, address it, and attach selected items or gold. The paired Papyrus bridge reports delivery; a queued command is not a confirmed receipt, and confirmed delivery does not mean the NPC has read the letter. Opening the player's browser inbox is not an NPC read event.

For an ordinary player-sent letter, the existing reading route is the recipient's **normal CHIM dialogue context**. For example, when you later speak to Alice and CHIM builds Alice's character prompt, Sealed Fates can include her eligible delivered letters. You need not mention the letter. Up to three letters are supplied, prioritising newly delivered ones, explicitly labelled as written documents whose attribution and contents remain claims. This is context supplied to the model, not proof of a physical reading animation or an automatic permanent biography update.

Dev.31 adds manual Dispatch/Resume through the existing player-backed **The Narrator** carrier, authorized by a fresh active native request no more than 45 seconds old. It needs no nearby managed NPC, NPC interaction or Background Life opt-in. The same saved letter supplies its recipient, body and attachments; no automatic retry or resend is added. Freshness, restore, receipt and duplicate protections remain. This source change does not prove a physical Skyrim note or item/gold transfer, installed PRISMA behavior, or save/co-save behavior.

Claimed sender, actual author, and inventory payer are separate values. The printed attribution or a signature in the letter is not proof of who wrote it. Forwarding retains the complete visible original under attribution labels and a separate cover note; the combined text must fit within 2,000 codepoints and 8,000 bytes. Oversized forwards are unavailable instead of clipped.

Compatible **Mind Poisoning** or **Sworn & Scorned** integrations receive the exposed letter's written-evidence reference when installed and enabled. Each integration is independent. Mind Poisoning drains eligible written-evidence work after the CHIM request; S&S evaluates supported issues through its background worker. Neither is guaranteed to finish before the NPC's first reply. Settings, cast selection, locks, model readiness and restore checks can defer or refuse evaluation.

**Successfully committed consequences survive the letter leaving the prompt.** Relationship effects are saved in CHIM's NPC relationship data; supported grievances/promises and their evidence are saved in S&S's ledger. Later interactions, issue resolution and normal restore/playthrough rules still apply. Source/replay records prevent reapplying the same evidence on every conversation. A spoken thank-you does not prove a saved affinity change or a permanent gratitude entry; there is no fixed gift bonus, automatic debt or guaranteed non-neutral result.

**What this does not yet do:** automatically insert an incoming ordinary Sealed Fates letter into the recipient's native BGL thought/action cycle while you are elsewhere. BGL-enabled NPCs are not excluded from existing correspondence. Manual delivery needs no BGL opt-in; autonomous features retain their own BGL/Letters eligibility. Sealed Fates' offscreen correspondence workers below are a separate route and do not establish that native BGL has read an incoming player letter. The proposed reading bridge would have connected those model boundaries; it is abandoned. [Details and RANGROO's answer](docs/BGL-STATUS.md).

Global autonomous correspondence is off by default. For an NPC to make a decision, enable the global control and that NPC's Background Life and Send letters settings. Decisions run on eligible ordinary native dialogue postrequests, using the in-game clock; opening a browser page does not trigger one. Autonomy sources get at most one model-callback attempt, with a two-game-hour wait after exposure, at most one scope decision per game-hour, a six-game-hour per-NPC cooldown, and at most three generated letters in a thread.

When the connector is missing, disabled or invalid, due queued letters can still dispatch, while a new source remains eligible without a decision or cooldown. A later valid connector can process that source once. Exceptions after entering a valid model callback remain terminal. Older `failed/model_unavailable` rows are not retried automatically because their reason and `model_calls` value cannot establish that no callback ran; `model_calls` records callback attempts, not provider billing or receipt.

Offscreen NPC mail is a separate text-only service. After opt-in, a fresh native bored or location observation can start or resume its bounded worker, so an NPC may process mail while the player is elsewhere. It waits for fresh native clock observations, pauses when those stop or scope checks fail, and its supervisor has a 30-minute lifetime cap; each child makes at most one bounded decision tick. It does not deliver physical notes or items.

Recipient context remains bounded to three letters. The complete known exposed-source reference set is retained for sibling-plugin backlog processing; its ID checks still grow with history. A sender must still be alive and have Background Life and Letters enabled for that mail to appear in current NPC prompts. Death or an explicit opt-out suppresses prompt exposure; the complete stored letter and its audit evidence remain. Restoring eligibility lets the same valid letter appear again. Failed, expired, or invalid work stays inert or is retired under its proof checks. Newly recorded, proven transient offscreen reaction failures may be reconsidered once with a fresh claim; historical ambiguous failures remain terminal. Recovery preserves the full original body and never resends physical transfers.

## Compatibility and limits

The server and bridge manifests list source-level compatibility references. They describe APIs and versions targeted by this build; they do not verify an installed CHIM, Skyrim, or mod setup. The bridge supports base-form item quantities, not selection of exact enchanted or renamed instances.

Existing transaction and identity safeguards remain. Dev.18 replaces global `MAX(eventlog.ts)` freshness checks with restore epochs and native timestamp floors; ordinary concurrent events no longer invalidate valid completion. Empty, queued and already-exposed prompt paths remain read-only, while first exposure revalidates the complete body and references. Effect writes remain serialized. Historical dev.16–17 scan timings do not describe current freshness. Dev.29 adds only the written-source lookup index on eventlog; no campaign-scale performance claim is made. SQL savepoints protect database staging only; they do not roll back rendered files, co-save state, or Skyrim inventory. Removing the code does not automatically remove retained database rows, eventlog evidence, books, PNGs, co-save data, notes, or items. See [installation and removal](docs/INSTALL.md) and [verification limits](docs/VERIFICATION.md).

This release does not add romance or debt collection mechanics, authenticate signatures, or establish that a model can reliably detect forgery. It makes no new provider-evaluation or Skyrim-gameplay claim. The dev.14 recovery kit remains historical evidence for its exact older pairing only, not dev.29 integration proof.

## Project documents

- [Changelog](CHANGELOG.md)
- [Installation and removal](docs/INSTALL.md)
- [Verification and limits](docs/VERIFICATION.md)
- [Build and package commands](docs/BUILD.md)
- [Credits](NOTICE.md) · [MIT code license](LICENSE) · [Artwork: CC BY 4.0, credit required](ARTWORK-LICENSE.txt)
