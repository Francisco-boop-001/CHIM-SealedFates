**0.1.0-dev.22 — PRE-ALPHA.** Fewer lost letters, fewer stuck claims, same catastrophically overworked courier. Web-only release; the optional PRISMA pairing remains separate. [Release and downloads](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/tag/correspondence-v0.1.0-dev.22) · [Release notes](docs/RELEASE.md).

# CHIM Sealed Fates

Part of the World of Drama-Llama.

![A Nordic courier carries sealed letters past an affectionate reader and an outraged reader, watched by an emo llama above a doorway.](assets/dashboard-art-source.png)

Skyrim has one courier, and he is VERY good at his job. Let's reward him with love letters, forged apologies, and forwarded insults. I call it 'emergent storytelling' because 'I have made this man's working conditions worse' looks dreadful on a feature list.

Sealed Fates adds physical letters and optional item or gold transfers to CHIM, private written context, and bounded NPC correspondence. Dev.21 stops unusual NPC profile metadata from stalling background letters; dev.20 repaired closed-letter recovery and marker-failure quarantine. Strict claim and transfer checks are unchanged. Start with the [installation guide](docs/INSTALL.md); read the [limits and verification notes](docs/VERIFICATION.md) before using it in a save you care about.

## Pick your interface

**The new in-game Skyrim interface is optional. The existing Sealed Fates web plugin page still works.** Keep using CHIM's web page to compose letters, browse your inbox and outbox, check delivery status, and enable or pause autonomous correspondence. You do not need the new Skyrim interface to use those controls. Your browser has not been fired; I lack the qualifications to conduct that meeting.

The interface is separate from the **matching Sealed Fates Papyrus bridge**, which is still required for physical note delivery and item/gold transfers. Skipping the optional interface does not mean skipping the delivery bridge.

The optional [Department/PRISMA prealpha.5](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/tag/v0.1.0-prealpha.5) adds dev.21 source compatibility; the dev.20/prealpha.4 pair has the earlier disposable server-facade proof. Neither published adapter accepts dev.22. Keep your existing pair if you use PRISMA, or use the existing web plugin page for dev.22 until an exact pairing is released. No sibling plugins are bundled, and installed Skyrim behavior remains unverified. Read [the source identities and verification limits](docs/PRISMA-COMPATIBILITY.md).

## Download

Download the [dev.22 PRE-ALPHA release](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/tag/correspondence-v0.1.0-dev.22). See the [release notes](docs/RELEASE.md) for the current and historical checksums.

Use the [paired MO2 and CHIM package](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.22/CHIM-Sealed-Fates-0.1.0-dev.22.zip) for the main install path; it contains the matching bridge PEX and server package. The [server-only package](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.22/correspondence-0.1.0-dev.22.dwpkg) is for CHIM's package manager. The server-only maintenance [tar archive](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.22/correspondence.tar.gz) is not automatically listed by CHIM.

## The web page

The existing web plugin page now wears the same parchment, serif type, rust-red controls and slate-blue accents as its siblings, with the full emo-llama illustration. It follows your system light/dark preference, keeps the artwork uncropped, and gives wide mail tables their own scroll area on small screens. Your letters, form actions and delivery rules are unchanged. The courier has acquired branding. He has not acquired an assistant.

## What it does

The player can write a letter, address it, and attach selected items or gold. The paired Papyrus bridge records physical delivery; delivery does not mean the NPC has read the letter. An NPC is exposed to a letter only when its own context render includes it. Opening the player's browser inbox is not a game read event.

Claimed sender, actual author, and inventory payer are separate values. The printed attribution or a signature in the letter is not proof of who wrote it. Forwarding retains the complete visible original under attribution labels and a separate cover note; the combined text must fit within 2,000 codepoints and 8,000 bytes. Oversized forwards are unavailable instead of clipped.

When compatible Mind Poisoning or Sworn & Scorned integrations are enabled, recipient exposure may be interpreted under those mods' normal rules and affect opinions, grievances, gratitude, or evidence-backed obligations. Neutral outcomes remain valid; there is no fixed gratitude bonus, and a signature does not prove authorship.

Global autonomous correspondence is off by default. For an NPC to make a decision, enable the global control and that NPC's Background Life and Send letters settings. Decisions run on eligible ordinary native dialogue postrequests, using the in-game clock; opening a browser page does not trigger one. Autonomy sources get at most one model-callback attempt, with a two-game-hour wait after exposure, at most one scope decision per game-hour, a six-game-hour per-NPC cooldown, and at most three generated letters in a thread.

When the connector is missing, disabled or invalid, due queued letters can still dispatch, while a new source remains eligible without a decision or cooldown. A later valid connector can process that source once. Exceptions after entering a valid model callback remain terminal. Older `failed/model_unavailable` rows are not retried automatically because their reason and `model_calls` value cannot establish that no callback ran; `model_calls` records callback attempts, not provider billing or receipt.

Offscreen NPC mail is a separate text-only service. After opt-in, a fresh native bored or location observation can start or resume its bounded worker, so an NPC may process mail while the player is elsewhere. It waits for fresh native clock observations, pauses when those stop or scope checks fail, and its supervisor has a 30-minute lifetime cap; each child makes at most one bounded decision tick. It does not deliver physical notes or items.

Recipient context contains up to the three newest valid exposed letters found in the latest 32 inbox entries. A sender must still be alive and have Background Life and Letters enabled for that mail to appear in current NPC prompts. Death or an explicit opt-out suppresses prompt exposure; the complete stored letter and its audit evidence remain. Restoring eligibility lets the same valid letter appear again. Failed, expired, or invalid work stays inert or is retired under its proof checks. Newly recorded, proven transient offscreen reaction failures may be reconsidered once with a fresh claim; historical ambiguous failures remain terminal. Recovery preserves the full original body and never resends physical transfers.

## Compatibility and limits

The server and bridge manifests list source-level compatibility references. They describe APIs and versions targeted by this build; they do not verify an installed CHIM, Skyrim, or mod setup. The bridge supports base-form item quantities, not selection of exact enchanted or renamed instances.

Existing transaction and identity safeguards remain. Dev.18 replaces global `MAX(eventlog.ts)` freshness checks with restore epochs and native timestamp floors; ordinary concurrent events no longer invalidate valid completion. Empty, queued and already-exposed prompt paths remain read-only, while first exposure revalidates the complete body and references. Effect writes remain serialized. Historical dev.16–17 scan timings do not describe dev.18 freshness, and no campaign-scale performance claim or core-table index is shipped. SQL savepoints protect database staging only; they do not roll back rendered files, co-save state, or Skyrim inventory. Removing the code does not automatically remove retained database rows, eventlog evidence, books, PNGs, co-save data, notes, or items. See [installation and removal](docs/INSTALL.md) and [verification limits](docs/VERIFICATION.md).

This release does not add romance or debt collection mechanics, authenticate signatures, or establish that a model can reliably detect forgery. It makes no new provider-evaluation or Skyrim-gameplay claim. The dev.14 recovery kit remains historical evidence for its exact older pairing only, not dev.18 integration proof.

## Project documents

- [Changelog](CHANGELOG.md)
- [Installation and removal](docs/INSTALL.md)
- [Verification and limits](docs/VERIFICATION.md)
- [Build and package commands](docs/BUILD.md)
- [Credits and licensing status](NOTICE.md)
