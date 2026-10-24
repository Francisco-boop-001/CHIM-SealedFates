# Idle companion correspondence implementation plan

Goal: let the existing companion observer and delivery bridge run during active solitary play, without a recent NPC dialogue snapshot.

Architecture: use CHIM's existing periodic native `request` event to poll. Address the existing global extension commands to the fixed, player-backed `The Narrator` transport agent. Companion/NPC identity and all state authority remain in the correlated payload, never in the carrier name. No Quest, ESP, DLL, provider, core or installed-game changes.

Alternatives: extending snapshot age would accept stale availability and still stop after expiry; nearby `bored` only stops when alone; a new native timer duplicates the existing request heartbeat. The existing request + Narrator route is the smallest supported source-level solution. The user authorized implementation if feasible; no further design approval is needed.

- [x] Add failing host/PG tests for request polling with absent and expired presence, exact Narrator-addressed Observe/Deliver commands, blocked manual sends, read-only reconciliation, no duplicate model/delivery after retries, opt-outs and restore.
- [x] Permit `request` only in the companion poll hook; keep the general offscreen observation whitelist unchanged. Poll through Narrator; use Narrator only for scheduler-owned companion dispatch/reconciliation. Preserve manual and other-plugin executor rules.
- [x] Exercise production request preprocessing and actual copied core response-return behavior in an isolated fixture. Verify that heartbeat polls progress game time without inventing a BGL source or making inline model calls.
- [x] Run new causal cases and affected regressions on the exact owned socket-only test2 cluster. Inspect native routing at the pinned source and distinguish this from VM proof.
- [x] Review independently, update candidate docs and build a fresh single-ZIP candidate without overwriting prior evidence. Synchronize canonical source, confirm owned cleanup and unchanged live VHD metadata.

Review focus: missing Narrator during initial setup fails closed; pause/recording/maintenance suppression delays polls; normalized/stale request clocks and restore fences remain strict; receipt retries never create new operations; no extra worker/model work per native polling cycle.

Limits: this removes the recent NPC snapshot dependency, not the need for CHIM to be active and normally initialized. Actual Skyrim command execution and physical delivery still need an in-game test.
