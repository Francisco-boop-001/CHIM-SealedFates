# CHIM Sealed Fates

Part of the World of Drama-Llama.

![A Nordic courier carries sealed letters past an affectionate reader and an outraged reader, watched by an emo llama above a doorway.](assets/dashboard-art-source.png)

Skyrim has one courier, and he is VERY good at his job. Let's reward him with love letters, forged apologies, and forwarded insults. I call it 'emergent storytelling' because 'I have made this man's working conditions worse' looks dreadful on a feature list.

**0.1.0-dev.16 — PRE-ALPHA.** Sealed Fates adds physical letters and optional item or gold transfers to CHIM, plus private written context and bounded NPC correspondence. This maintenance release defers autonomy work when its Background Life connector is unavailable while preserving queued delivery. Start with the [installation guide](docs/INSTALL.md); read the [limits and verification notes](docs/VERIFICATION.md) before using it in a save you care about.

## Pick your interface

**The new in-game Skyrim interface is optional. The existing Sealed Fates web plugin page still works.** Keep using CHIM's web page to compose letters, browse your inbox and outbox, check delivery status, and enable or pause autonomous correspondence. You do not need the new Skyrim interface to use those controls. Your browser has not been fired; I lack the qualifications to conduct that meeting.

The interface is separate from the **matching Sealed Fates Papyrus bridge**, which is still required for physical note delivery and item/gold transfers. Skipping the optional interface does not mean skipping the delivery bridge.

Exact compatibility pins for the optional Department-owned interface are awaiting owner confirmation. No dev.16 sibling bundle is included. Normal CHIM update checks may replace the historical kit's tested sibling candidates and invalidate that pairing. An older enabled Mind Poisoning MO2 package may also synchronize an older build back. Confirm the active files before upgrading, and do not stack old and new routes.

## Download

See the [release notes](docs/RELEASE.md) for the dev.16 asset SHA-256 digests and matching checksum list.

Use the [paired MO2 and CHIM package](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.16/CHIM-Sealed-Fates-0.1.0-dev.16.zip) for the main install path; it contains the matching bridge PEX and server package. The [server-only package](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.16/correspondence-0.1.0-dev.16.dwpkg) is for CHIM's package manager. The server-catalog-only [tar archive](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.16/correspondence.tar.gz) is not automatically listed by CHIM.

## The web page

The existing web plugin page now wears the same parchment, serif type, rust-red controls and slate-blue accents as its siblings, with the full emo-llama illustration. It follows your system light/dark preference, keeps the artwork uncropped, and gives wide mail tables their own scroll area on small screens. Your letters, form actions and delivery rules are unchanged. The courier has acquired branding. He has not acquired an assistant.

## What it does

The player can write a letter, address it, and attach selected items or gold. The paired Papyrus bridge records physical delivery; delivery does not mean the NPC has read the letter. An NPC is exposed to a letter only when its own context render includes it. Opening the player's browser inbox is not a game read event.

Claimed sender, actual author, and inventory payer are separate values. The printed attribution or a signature in the letter is not proof of who wrote it. Forwarding retains the complete visible original under attribution labels and a separate cover note; the combined text must fit within 2,000 codepoints and 8,000 bytes. Oversized forwards are unavailable instead of clipped.

When compatible Mind Poisoning or Sworn & Scorned integrations are enabled, recipient exposure may be interpreted under those mods' normal rules and affect opinions, grievances, gratitude, or evidence-backed obligations. Neutral outcomes remain valid; there is no fixed gratitude bonus, and a signature does not prove authorship.

Global autonomous correspondence is off by default. For an NPC to make a decision, enable the global control and that NPC's Background Life and Send letters settings. Decisions run on eligible ordinary native dialogue postrequests, using the in-game clock; opening a browser page does not trigger one. Each source gets at most one model-callback attempt, with a two-game-hour wait after exposure, at most one scope decision per game-hour, a six-game-hour per-NPC cooldown, and at most three generated letters in a thread.

When the connector is missing, disabled or invalid, due queued letters can still dispatch, while a new source remains eligible without a decision or cooldown. A later valid connector can process that source once. Exceptions after entering a valid model callback remain terminal. Older `failed/model_unavailable` rows are not retried automatically because their reason and `model_calls` value cannot establish that no callback ran; `model_calls` records callback attempts, not provider billing or receipt.

Offscreen NPC mail is a separate text-only service. After opt-in, a fresh native bored or location observation can start or resume its bounded worker, so an NPC may process mail while the player is elsewhere. It waits for fresh native clock observations, pauses when those stop or scope checks fail, and its supervisor has a 30-minute lifetime cap; each child makes at most one bounded decision tick. It does not deliver physical notes or items.

Recipient context contains up to the three newest valid exposed letters found in the latest 32 inbox entries. Failed, expired, or invalid work stays inert or is retired under its proof checks; it is never automatically resent. Recovery preserves the original body.

## Compatibility and limits

The server and bridge manifests list source-level compatibility references. They describe APIs and versions targeted by this build; they do not verify an installed CHIM, Skyrim, or mod setup. The bridge supports base-form item quantities, not selection of exact enchanted or renamed instances.

The dev.14 transaction and identity safeguards remain in dev.16. F-05 work narrows prompt reads to empty, queued, and already-exposed paths; first exposure still revalidates the complete body and references. Synthetic PostgreSQL timings show that `MAX(ts)` and a no-hit `EXISTS` query do not scale away the scan cost, and the faster synthetic `ts` index is not shipped. One read-only prompt fixture completed in about 46 ms while a peer insert was uncommitted; this is a single fixture, not a campaign-scale performance claim. First-exposure and effect writes remain serialized, and `MAX(ts)` and `observeManaged` costs remain. SQL savepoints protect database staging only; they do not roll back rendered files, co-save state, or Skyrim inventory. Removing the code does not automatically remove retained database rows, eventlog evidence, books, PNGs, co-save data, notes, or items. See [installation and removal](docs/INSTALL.md) and [verification limits](docs/VERIFICATION.md).

This release does not add romance or debt collection mechanics, authenticate signatures, or establish that a model can reliably detect forgery. It makes no new provider-evaluation or Skyrim-gameplay claim. The dev.14 recovery kit remains historical evidence for its exact older pairing only, not dev.16 integration proof.

## Project documents

- [Changelog](CHANGELOG.md)
- [Installation and removal](docs/INSTALL.md)
- [Verification and limits](docs/VERIFICATION.md)
- [Build and package commands](docs/BUILD.md)
- [Credits and licensing status](NOTICE.md)
