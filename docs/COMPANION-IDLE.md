# Companion correspondence during idle play — dev.23 PRE-ALPHA

The recent managed-NPC dialogue snapshot is no longer required for companion check-ins. The server uses CHIM's existing periodic native `request` heartbeat and addresses the observation and companion-owned delivery commands to `The Narrator`, the built-in agent backed by the player. No new native timer, ESP, Quest or DLL is added, and neither existing Papyrus bridge changes.

## Why the route is supported

Inspected CHIM source: `5ca1bc361d1e11a3c58067599b494b1101c2c77b`, with working-file identities retained in the task evidence. Inspected test2 core: `468584f7bfc34c2eaf76e6188efaf3294f4fd63c`.

| Step | Source evidence |
| --- | --- |
| Periodic request while active, even alone | Native `Plugin.cpp:2412`; no nearby-NPC condition |
| Plugin preprocessing sees request before the core response handler | Core `main.php:199-200`, then `processor/comm.php:402-413` |
| Return queued commands without a model call | Core `DataDequeue` in `lib/data_functions.php:877-914`, then actual actor/channel/payload echo in `comm.php:406` |
| Log response enters native queue | Native `HTTPManager.cpp:1424-1427`, `SPGResponse.cpp:32-58` |
| Narrator resolves to player-backed agent | Native save/load initialization `Plugin.cpp:6007-6023,7935-7952` |
| Extension command invokes the owned global Papyrus script | Native `Commands.cpp:2211-2224,3321-3337`; no NPC-presence or speech-enable condition on this branch |

The Narrator is a transport carrier, not the letter's author or recipient and not evidence of companion state. The nonce, restore epoch, playthrough, stable actor identities, calendar samples, episode continuity, eligibility and due-time rechecks remain unchanged. An idle heartbeat alone cannot establish waiting or generate a letter.

The companion hook alone recognizes `request`; it is not added to general Background Life source selection. Polling remains at most once per game-hour for four NPCs. Model decisions stay in the existing worker, with the existing cooldown and one-attempt-per-episode rules. No additional model runs on each heartbeat.

Only scheduler-owned companion dispatch and read-only reconciliation use the Narrator carrier. An ordinary letter cannot obtain this route by passing the scheduler flag; manual send/retry of owned companion letters remains blocked. Existing physical receipts and no-resend behavior are preserved.

## Return-queue repairs found during verification

The PostgreSQL queue writer previously stored `command|payload` in `action` and left `text` empty. Core prints `actor|action|text`; the inspected native decoder preserves the final field. That produced a trailing `|` on JSON and on renderer titles. The writer now stores the channel in `action` and payload in `text`, while preserving its existing internal call interface.

Core also stamps and filters `interaction_generation`. The previous direct SQL writer omitted that field, so a nonzero generation could mark a command sent and then discard it. On a core-aware host the writer now checks interaction permission and stamps the captured generation; disabled, stale or missing authority fails closed. Legacy hosts without the core helper retain their supported schema. Observation polling and companion worker ticks pause while interaction is Off.

## Verification

The owned socket-only PostgreSQL fixture on `DwemerAI4Skyrim3-test2` starts with no NPC presence row and advances controlled native game observations through 48 hours plus transit. It verifies one model decision, a player-addressed document, one durable physical operation and read-only reconciliation through the Narrator. Duplicate heartbeats, repeats and a deliberately expired NPC snapshot neither duplicate mail nor renew old presence authority. The ordinary manual-letter executor rule remains unchanged.

The fixture executes the hash-pinned actual `DataDequeue` function and actual core request-response branch against synthetic rows. It checks generation 7 and 8, stale-generation suppression, observer/delivery/reconcile JSON after native-style splitting, and the exact generated-letter title. The native game code itself is inspected, not executed by these PHP tests. Failed-before/passed-after receipts are retained under `artifacts/chim-sealed-fates-companion-idle-20261024`.

Existing full companion recovery/restore/concurrency tests pass against this candidate. The older physical gift/gold PostgreSQL suite also passes with a private test2 adapter that replaces its obsolete default database guard with the exact owned-cluster guard. Eighteen affected pure suites, 37 existing package checks, three companion package checks and twenty release-gate checks pass. The observer, delivery bridge and model prompts are unchanged, so this transport task does not repeat the earlier six model-quality cases or claim new model-quality evidence.

## Remaining proof boundary

The source-supported route and isolated server behavior do not prove the installed Skyrim DLL executes these commands. Actual unloaded-actor sampling, co-save restoration, renderer download, physical note delivery and first-ever new-game Narrator initialization require an in-game test. No game installation or live server change was made, and no dev.23 Department pairing or gameplay acceptance is claimed.

CHIM's heartbeat is postponed during pause, recording, application menus or maintenance suppression. Calendar time still determines eligibility; wall-clock waiting never counts. A long missed-observation interval resets continuity rather than inventing absence. CHIM's normal initialization is required; no acknowledgment means no invented success.

## Bughunt follow-up

BH-01, BH-02 and BH-03 are repaired in the dev.23 release candidate. Due-mail selection prioritizes fresh same-epoch samples; an unready letter stays pending while other eligible work can progress. Per-NPC BGL/cooldown deferrals skip that NPC, while global limits still apply. Attempt and completion writes require the exact claim request ID, so a displaced worker cannot consume replacement work. Future-dated samples cannot authorize delivery.

All three promoted reproductions failed against the previous candidate and pass after repair. Additional assertions cover retained pending mail, new decisions behind unready mail, global cooldown, future samples, no duplicate operation, late completion and valid claim recovery. The full companion recovery and actual-core idle suites, 18 pure suites and 60 package/release checks pass. Evidence is retained under `artifacts/chim-sealed-fates-companion-repair-20261024`; the repaired single ZIP is in `dist/companion-repaired-candidate-20261024`. Earlier candidate ZIPs remain unchanged. Publication adds no gameplay/provider proof, installation or Department pairing.
