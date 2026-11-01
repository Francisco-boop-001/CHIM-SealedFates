# Native BGL reading bridge: abandoned

Recorded: 2026-11-01. This concerns the unreleased bridge into native Background Life requests, not the existing Sealed Fates plugin or its BGL participation controls.

## Maintainer answer

The user supplied this Discord response from RANGROO (displayed time 12:13):

> Yeah I don't think right now we have an API for feeding into letters/BGL. We are currently working on revamping the system a bit so will add to the list.
>
> But cool plugins man!

Source: transcript pasted by the user in this development chat. No Discord permalink was supplied; the message date and identity have not been independently checked through Discord. The record date above is not a claimed Discord timestamp.

This is a maintainer assessment of the current gap and acknowledgement of a request. It is not a promised API, delivery date, settled design, accepted patch or authorisation to modify CHIM.

## What the existing plugin does

- Compose and address letters through the web page or a matching optional Department/PRISMA interface; include items/gold and a claimed signature. The matching Papyrus bridge remains required for physical delivery and transfers.
- Dispatch manual letters using fresh native authority, including the existing player-backed Narrator carrier without finding a nearby NPC. Receipt, restore and duplicate-transfer protections remain required.
- Supply eligible delivered letters as explicitly written evidence during the recipient's ordinary CHIM dialogue-context render. Up to three letters enter that prompt, with newly delivered ones prioritised. Supplying context is not an automatic biography or permanent-memory write.
- Submit exposed letter sources to compatible Mind Poisoning/S&S integrations. Mind Poisoning evaluates eligible work postrequest; S&S uses its background worker. Successful relationship and supported ledger changes are stored independently of the letter's presence in a later prompt. Pending, paused, failed, abstained and processed/no-change outcomes are distinct from a committed social change.
- Run bounded Sealed Fates autonomy/offscreen correspondence and observed waiting-companion check-ins under their existing participation, freshness, cooldown, chain and restore controls. These are not the same as injecting an incoming player letter into native BGL.

A BGL-enabled NPC is not excluded from these existing routes. Manual delivery does not require BGL. Some autonomous features require BGL and Send letters enabled. A conversation with the recipient is one concrete existing route for exposing an ordinary player-sent letter; delivery alone does not establish reading or adjudication.

## What required a missing host interface

The inspected native BGL normal/lightweight thought and action calls do not use the ordinary dialogue prompt hook. The proposed generic host interface would have supplied recipient-scoped context at those boundaries, acknowledged successful consumption or rejected stale contributions before effects, and coordinated native BGL mail ownership to avoid duplicate responses in that cycle.

No such proposed core hook, bridge adapter or migration007 is included in the existing dev.31 release. Installing Sealed Fates alone does not add this native BGL reading capability.

## Decision and verification boundary

The user explicitly abandoned this BGL bridge work on 2026-11-01. Further bridge coding, core integration, paid model calls and bridge testing are stopped. Historical prototype source and failed/passing evidence remain preserved separately; they are not merged into or counted as proof of the current product. A future CHIM revamp does not automatically resume the project; any new bridge effort requires a new explicit decision.

The abandoned staged model tests had unresolved grounding failures and did not demonstrate full installed BGL/gameplay integration. Those findings must not be advertised as solved, as defects proven in the current dev.31 runtime, or as successful installed-host proof.

Existing Sealed Fates remains PRE-ALPHA. Current-source offline checks, package integrity and retained exact-source service receipts are separate from installed HTTP/PRISMA, physical Skyrim notes or transfers, native crash recovery and save/co-save gameplay proof. Publishing documentation does not install anything or touch live data.


## Existing-plugin verification - 2026-11-01

The clean public dev.31 source was rechecked independently of the abandoned prototype:

- All 37 existing offline PHP regression suites pass. The initial local run exposed a missing `mbstring` test prerequisite and two Windows path-separator expectations. Loading the existing extension for the contact fixture and using `DIRECTORY_SEPARATOR` in the two test expectations resolves them; no production path/safety check was changed. Both original failures remain in the development evidence.
- All 77 Python package/preflight tests and all 21 server PHP syntax checks pass. These are bounded local fixtures; network functions were disabled for the PHP runs and no server or provider was used.
- All four dev.31 public assets were downloaded anonymously and independently rebuilt. Every rebuilt file is byte-for-byte identical to its public counterpart. Runtime, native scripts, manifest/version, Department pins, immutable tag and published download bytes remain unchanged.
- The existing exact-source PostgreSQL/dequeue evidence remains historical service evidence; it was not rerun or relabelled as a fresh server test. No WSL/test2, live server, installed game or provider was accessed for this clarification.

Reproduce package checks with `python -B tests/package_test.py`, `python -B tests/companion_package_test.py`, `python -B tests/release_preflight_test.py` and `python -B tests/release_assets_preflight_test.py`. Create an empty `dist` directory first. PHP contact/offscreen contact fixtures require `mbstring`; the ingress fixture requires its exact hash-pinned captured core inputs, as checked by the fixture. These results verify current source/package behavior, not actual Skyrim delivery or installed-model reactions.

Existing users can keep the published dev.31 ZIP. This documentation update introduces no new download or migration.
