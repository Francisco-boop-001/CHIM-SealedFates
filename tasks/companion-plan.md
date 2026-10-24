# Grounded companion check-ins — implementation plan, 2026-10-24

Goal: one optional NPC-to-player check-in after two observed in-game days of explicit waiting apart, using a new read-only plugin-owned Papyrus observer through CHIM's existing ExtCmd dispatch. No CHIM core replacement, native DLL, new ESP, installed-game edits, live access or publication.

Ruling: current server telemetry cannot establish waiting. Add an owned observer rather than interpreting party omissions or generated BGL text. The user's instruction to implement if possible authorizes this source/build work. Skyrim VM acceptance remains unperformed.

## Observations and state

- Use the global autonomy opt-in and NPC BGL + Letters opt-ins; require alive NPC and unambiguous stable identity.
- First version supports explicit waiting only. Waiting source is vanilla WaitingForPlayer AV OR CHIM WaitFaction plus active CHIM wait package, and IsPlayerTeammate must be true. Dismissed/non-following is not abandonment.
- Positive proximity (same parent cell, loaded actor, distance <=1024) cancels a waiting episode before any waiting test. Distinct known parent cells, or loaded same-cell distance >2048, establish sampled separation. Other cases are unknown.
- Native samples carry Utility.GetCurrentGameTime()*1440 rounded down. A sample gap >120 game-minutes or a rewind resets the episode. Do not claim continuous separation or exact time ordered to wait; first observation is a lower-evidence bound. The generated letter must make no numeric elapsed-time or abandonment assertion.
- Persist native episode in namespaced JContainers co-save state keyed by server playthrough/restore epoch + actor ID. First waiting sample uses its observation UUID as episode ID. Unknown, other, dead and nearby clear it.
- Native payload request: exactly version=1, observation_id UUID, playthrough_id string, epoch UUID, nonce lowercase hex64, actors list 1..4. Each actor exactly id (canonical positive decimal string), plugin, local_id, refid (signed int allowed by Papyrus, runtime uint normalized server-side).
- Native response: same version/observation_id/playthrough_id/epoch/nonce, actors list. Each actor exactly id, refid, state (waiting/nearby/other/unknown/dead), episode UUID or empty, first_game_minute int, game_minute int. No dialogue, names, inventories or provider data.
- New global script CHIMCorrespondenceCompanion, DispatchExternalCommand(executorName,command,parameter), accepts ExtCmdCHIMCorrespondenceCompanion_Observe only and emits correspondence_companion via AIAgentFunctions.logMessage. Reuse existing bridge's public UUID/hex/identity/lexeme validators where appropriate; keep physical bridge byte-identical.

## Server

Add companion module + additive migration and integrate native receipt ingress and scheduler. Reuse CorrespondencePgStore writer/restore barriers, actor identity locks, CorrespondenceService compose/dispatch/close, existing model factory and opt-ins. No providers/configuration changes.

- Poll at most once per game-hour, <=4 opted-in NPCs per batch with fair rotation, only via an existing fresh managed executor. Bound outstanding observation, bind its nonce/actor identities/clock/scope/restore token, reject replay/malformed/cross-scope/expired replies. Never make a model call to decide whether a raw state is trustworthy.
- Server validates game-minute progression against authoritative request gamets (GAMETS_TO_HOURS=0.0000024), receipt age, current observation correlation and epoch. Do not accept arbitrary supplied elapsed days.
- One decision per scope/hour and six-hour actor cooldown across correspondence schedulers, one attempted model decision per native episode including silence/failure/unknown. Persist attempted marker before calling model; abandoned attempted claims become inert. Unattempted work may recover safely.
- Model sees player recipient, personality/private affinity, and bounded factual waiting evidence only; it may return none or letter, with strict schema/recipient/body. No inferred promises, mutual romance, exact duration, continuous absence or abandonment. Validate outputs conservatively; invalid decisions are terminal for that episode.
- Compose one NPC-authored/player-addressed attachment-free document with idempotent request UUID; schedule existing twelve-hour transit. Before dispatch require fresh same-episode waiting sample and current opt-ins/identity/restore; nearby/unknown/opt-out cancels queued complaint. No automatic resend after operation exists, including unknown. Web manual send/retry must not bypass scheduler ownership.
- BGL retains its independent player-letter path; inspect its letter history to suppress a recent overlapping player letter where reliable, and document that no atomic cross-plugin duplicate guarantee exists without a shared ownership API.
- Diagnostics use fixed reasons and correlation IDs, game clock, stage and outcome; exclude ordinary raw letters/prompts/secrets.

## Tasks / ownership

- [x] Native task: new script + causal translated policy tests + compile new PEX with source/compiler hash receipt. Existing physical bridge unchanged.
- [x] Server task: companion policy/store/host + migration + integration in bootstrap/preprocessing/offscreen tick; focused pure + PG tests and ownership safeguards.
- [x] Coordinator: isolated test2 launcher, actual model evaluations, packaging/new script manifest and candidate docs, failure/concurrency/restore verification and final independent review. Skyrim VM acceptance remains a separate unperformed gate.

Required cases: wait 48h => one opportunity; <48h/unknown/no BGL/no Letters/no model => none; reunion and unknown cancel pending; repeated/out-of-order samples/replay/rewind/new epoch/new playthrough => no stale letter; two claims => one model attempt; timeout-after-attempt => no model retry; due dispatch => no new model call; unknown receipt => no resend; recent BGL letter => suppression. Real model: warm/irritable/reserved personality, unsupported numeric duration and romance, silence case, correct player recipient/written form. All runtime writes use fresh private system-ID-guarded socket-only PostgreSQL on test2. Actual VM/package polling/physical inventory delivery remain separate proof gates.

Ruling (usability): use 48 game-hours plus existing 12-hour transit instead of 72+12. Vanilla follower wait timeout around 72 hours is an externally corroborated compatibility risk; installed framework behavior is unverified. This avoids designing the first opportunity after an ordinary waiting window has ended. No follower timeout is changed.
