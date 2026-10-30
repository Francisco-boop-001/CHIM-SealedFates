# NPC-independent manual correspondence delivery

Goal: retain the web and optional Department interfaces while allowing existing manual letters/items/gold to reach the game without nearby NPCs. The user authorizes investigation, implementation, isolated test2 verification and publication; live, installed game, production configuration and providers remain untouched.

## Approaches

Selected implementation choice: reuse CHIM's player-backed The Narrator command carrier and the unchanged correspondence bridge. Record independent native activity in the existing presence JSON, preserving existing managed snapshots. Keep the existing managed executor path as a compatibility fallback; no arbitrary new actor names.

A Department-only native transport would make the web depend on an optional interface. A new Papyrus probe would add a binary/protocol when the existing native heartbeat already supplies the supported source. PNG rendering alone cannot deliver a letter. Removing the web page does not repair the common backend gate. Reconsider the probe only if source/integration evidence disproves the heartbeat route.

## Authority and behavior

- Capture only the native `request` heartbeat in preprocessing before the core command response. It must work with zero nearby actors and autonomy/BGL/companion eligibility disabled. No browser call creates or refreshes activity.
- Store a separate activity member in `plugins.correspondence_presence.payload`: native type, opaque native ts, game ts, reconstructible request fingerprint, current scope, full restore generation, server receipt time, active/passive state and exact owned event anchor. No new table/migration, secrets or model calls.
- Native ts comes from a high-resolution nanosecond counter with unspecified epoch. Compare only with the native restore floor and previously accepted native counter; never convert it to Unix seconds. Reject equal/older samples without renewing TTL. Keep the existing 45-second server-received freshness window and reject future server times. This is recent accepted native traffic, not proof of human presence/foreground state.
- Snapshot and activity updates preserve each other. Accept an anchored activity checkpoint at most every 15 seconds while active/passive state is unchanged; earlier samples are skipped, not accepted. Retain the last accepted anchored counter as the replay fence, without per-heartbeat writes or eventlog growth. Equal/older accepted-counter replays never renew freshness; active/passive transitions are anchored immediately. This preserves server-received activity semantics, not a nonce challenge for never-accepted/delayed traffic. A passive header or available host interaction refusal cannot create active authority; a transition to inactive invalidates prior active proof while retaining the counter fence. Readers require active proof, exact anchor, current scope/full restore authority and ts above current native floor.
- A fresh validated activity proof may select exact The Narrator even when it is absent from the NPC catalog. Arbitrary resolver names remain invalid. All document/author/payer/recipient identities, transaction/restore fences, native command generation, operation receipts and same-request idempotence remain unchanged. Companion/autonomy ownership cannot be bypassed.
- Ordinary active game polling supplies activity; paused/offline/uninitialized or Interaction Off states defer safely. Explicit Resume/Dispatch targets the saved letter, never current draft contents. No new auto retry/refund behavior.

## Acceptance and limits

Actual isolated test2 must cover zero-NPC/manual 500-gold dispatch once, both interface entry points, replay/older/expired/corrupt proof, passive/Off, scope/restore/native-floor changes, concurrent/repeated attempts and uncertain receipts. Exercise the pinned real core dequeue/request response where available. Independently review the exact final tree and verify source-bound public bytes. Keep the existing web security and migration prerequisites.

Captured client source establishes heartbeat/Narrator/renderer/ExtCmd routing. Server tests do not prove installed Skyrim execution, physical inventory/note delivery, first-new-game Narrator initialization, or save/co-save behavior; state these limits in release notes.
